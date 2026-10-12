<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Access\Contracts\RegisterAccessSubject;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\PublishedVersion;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The second publish transaction (Story 3.19, `ActivateVersion`): a `prepared` version becomes `published`. In one Workspace transaction it sets the
 * state, switches the Block's `current_version_id`, registers the Block as an Access subject for All users ({@see RegisterAccessSubject}), ends the Draft's
 * samples (the `maintenance` purge deletes the rows: `app` holds no DELETE), writes the audit event and emits `blocks.version.published`. Called by the
 * publish request straight after the freeze and by the maintenance job for a version left `prepared`.
 *
 * Idempotent: a version that is already `published` is returned as it is and nothing is audited or emitted twice; the row is locked, so two callers
 * (the request and the job) cannot both activate. A version in any other state is refused.
 */
final class ActivateVersion
{
    private const STAMP = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
        private readonly RegisterAccessSubject $subjects,
    ) {}

    /**
     * @param  string|null  $actorMembershipId  who published (null: the maintenance job activated it)
     *
     * @throws DraftNotFound the version does not exist, or is not prepared or published
     */
    public function activate(string $workspaceId, string $versionId, ?string $actorMembershipId = null): PublishedVersion
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $versionId, $actorMembershipId): PublishedVersion {
            if (! Str::isUuid($versionId)) {
                throw new DraftNotFound;
            }

            /** @var \stdClass|null $row */
            $row = DB::selectOne(
                'select v.id, v.block_id, v.state, v.name, v.version, v.revision, v.endpoint_id, v.endpoint_revision, v.prepared_by_membership_id, v.prepared_at, b.type_key, coalesce(c.name, b.category) as category, '
                .'to_char(v.published_at at time zone \'UTC\', '.self::STAMP.') as published '
                .'from block_versions v join blocks b on b.id = v.block_id left join block_categories c on c.id = b.category_id '
                .'where v.workspace_id = ? and v.id = ? for update of v',
                [$workspaceId, strtolower($versionId)],
            );

            if ($row === null || ! in_array($row->state, ['prepared', 'published'], true)) {
                throw new DraftNotFound;
            }

            if ($row->state === 'published') {
                return $this->published($row, (string) $row->published);
            }

            // Who published: the caller, or, for the maintenance job, the member who froze the version.
            $actorMembershipId ??= $row->prepared_by_membership_id === null ? null : strtolower((string) $row->prepared_by_membership_id);
            $now = now();

            DB::update(
                "update block_versions set state = 'published', published_at = ?, published_by_membership_id = ?, updated_at = ? where workspace_id = ? and id = ? and state = 'prepared'",
                [$now, $actorMembershipId, $now, $workspaceId, $row->id],
            );

            // Only forward: an older version activated late (the job) never takes the Block back from a newer one.
            DB::update(
                'update blocks set current_version_id = ?, updated_at = ? where workspace_id = ? and id = ? and (current_version_id is null or exists '
                .'(select 1 from block_versions c where c.id = blocks.current_version_id and c.prepared_at < ?))',
                [$row->id, $now, $workspaceId, $row->block_id, $row->prepared_at],
            );

            $this->subjects->register($workspaceId, RegisterAccessSubject::BLOCK, (string) $row->block_id);

            // The Draft's sample is unreadable from this moment; the purge deletes the row.
            ManageDraftSamples::erase($workspaceId, (string) $row->id);

            [$major, $minor] = array_map('intval', array_pad(explode('.', (string) $row->version, 2), 2, '0'));

            $this->audit->record(
                AuditAction::BlocksVersionPublished,
                [
                    'block_id' => strtolower((string) $row->block_id),
                    'version_id' => strtolower((string) $row->id),
                    'block_type' => (string) $row->type_key,
                    'name' => (string) $row->name,
                    'revision' => (int) $row->revision,
                    'version_major' => $major,
                    'version_minor' => $minor,
                    'endpoint_id' => $row->endpoint_id === null ? null : strtolower((string) $row->endpoint_id),
                    'endpoint_revision' => $row->endpoint_revision === null ? null : (int) $row->endpoint_revision,
                ],
                subject: 'block_version:'.strtolower((string) $row->id),
                actor: $actorMembershipId,
            );

            $this->outbox->emit(
                AuditAction::BlocksVersionPublished,
                'block_version:'.strtolower((string) $row->id),
                [
                    'block_id' => strtolower((string) $row->block_id),
                    'version_id' => strtolower((string) $row->id),
                    'version_major' => $major,
                    'version_minor' => $minor,
                ],
                actor: $actorMembershipId,
            );

            $this->enqueuePurge($workspaceId);

            $fresh = DB::selectOne("select to_char(published_at at time zone 'UTC', ".self::STAMP.') as published from block_versions where workspace_id = ? and id = ?', [$workspaceId, $row->id]);

            return $this->published($row, (string) ($fresh->published ?? ''));
        });
    }

    private function published(\stdClass $row, string $publishedAt): PublishedVersion
    {
        return new PublishedVersion(
            strtolower((string) $row->id),
            strtolower((string) $row->block_id),
            (string) $row->name,
            (string) $row->type_key,
            $row->category === null ? null : (string) $row->category,
            (string) $row->version,
            $publishedAt,
        );
    }

    private function enqueuePurge(string $workspaceId): void
    {
        try {
            PurgeDiscardedDraftsJob::dispatch($workspaceId)->afterCommit();
        } catch (Throwable $e) {
            // The activation stands: the scheduled run deletes what this enqueue would have. Only the class is logged.
            Log::error('blocks.draft_purge.enqueue_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
        }
    }
}
