<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\BlockPublishing;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftValidations;
use App\Modules\Blocks\Contracts\InvalidSampleShape;
use App\Modules\Blocks\Contracts\PublishedVersion;
use App\Modules\Blocks\Contracts\PublishPending;
use App\Modules\Blocks\Contracts\PublishRefused;
use App\Modules\Blocks\Contracts\SampleUnavailable;
use App\Modules\Blocks\Contracts\ValidationReport;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\EndpointNotFound;
use App\Modules\Connector\Contracts\Endpoints;
use App\Modules\Mapping\Contracts\FieldRoles;
use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Publish v1.0 (Story 3.19, {@see BlockPublishing}). Transaction one locks the Draft's row and, in this order, refuses a Draft that is no longer one
 * (404 or 409 `blocks.draft_published`: a double click or a second tab loses here, with no second version and no second audit event), a lost edit lock
 * (423), and a report that is not a `passed` one bound to this revision and this Endpoint revision (409 `blocks.publish_not_ready`). It then writes
 * `state = 'prepared'` where `state = 'draft' and revision = report.revision`, replaces `config` with the frozen document ({@see FrozenConfig}: the
 * validated config, never a different one), pins the Endpoint revision and emits `blocks.version.prepared`. From that commit a save of the Draft is a
 * 409 `blocks.draft_published`, and the database trigger refuses any change to the version but activation.
 *
 * Transaction two is {@see ActivateVersion}. If it fails the version stays `prepared`; the maintenance job activates it once `precompute_timeout` has
 * passed (Results precompute is Epic 4: nothing waits for it here).
 */
final class PublishDraft implements BlockPublishing
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Outbox $outbox,
        private readonly Drafts $drafts,
        private readonly DraftValidations $validations,
        private readonly BlockTypeRegistry $types,
        private readonly SampleShapes $shapes,
        private readonly FieldRoles $roles,
        private readonly ActivateVersion $activate,
        private readonly Endpoints $endpoints,
    ) {}

    public function publish(DraftActor $actor, string $draftId, int $lockEpoch, ?\Closure $holdsLock = null): PublishedVersion
    {
        try {
            $versionId = $this->transactions->run($actor->workspaceId, fn (): string => $this->freeze($actor, $draftId, $lockEpoch, $holdsLock));
        } catch (DraftPublished $e) {
            // A retry after a failed second transaction: the version is frozen (`prepared`) and valid, so it is activated now, not refused.
            if (! $this->isPrepared($actor->workspaceId, $draftId)) {
                throw $e;
            }

            $versionId = strtolower($draftId);
        }

        try {
            return $this->activate->activate($actor->workspaceId, $versionId, $actor->membershipId);
        } catch (Throwable $e) {
            // The version stays prepared and the maintenance job (or a retry) activates it. Only the class is logged.
            Log::error('blocks.publish.activation_failed', ['workspace_id' => $actor->workspaceId, 'version_id' => $versionId, 'exception' => $e::class]);

            throw new PublishPending($e);
        }
    }

    private function isPrepared(string $workspaceId, string $id): bool
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $id): bool {
            $row = Str::isUuid($id) ? DB::selectOne('select state from block_versions where workspace_id = ? and id = ?', [$workspaceId, strtolower($id)]) : null;

            return $row !== null && $row->state === 'prepared';
        });
    }

    private function freeze(DraftActor $actor, string $draftId, int $lockEpoch, ?\Closure $holdsLock): string
    {
        $row = Str::isUuid($draftId)
            ? DB::selectOne("select id, revision, lock_epoch, block_id from block_versions where workspace_id = ? and id = ? and state = 'draft' for update", [$actor->workspaceId, strtolower($draftId)])
            : null;

        if ($row === null) {
            throw $this->notADraft($actor->workspaceId, $draftId);
        }

        $id = strtolower((string) $row->id);
        $draft = $this->drafts->find($actor->workspaceId, $id);

        if ((int) $row->lock_epoch !== $lockEpoch || ($holdsLock !== null && $holdsLock() !== true)) {
            throw new DraftLockLost($draft);
        }

        $report = $this->validations->latest($actor->workspaceId, $id);

        if ($report === null) {
            throw new PublishRefused(PublishRefused::MISSING);
        }

        if (! $report->allowsPublish() || $report->endpointId === null || $report->endpointRevision === null) {
            throw new PublishRefused(match ($report->status) {
                ValidationReport::FAILED => PublishRefused::FAILED,
                ValidationReport::UNREACHABLE => PublishRefused::UNREACHABLE,
                default => PublishRefused::STALE,
            });
        }

        /** @var object{type_key: string, contract_version: int|string}|null $block */
        $block = DB::selectOne('select type_key, contract_version from blocks where workspace_id = ? and id = ?', [$actor->workspaceId, $row->block_id]);

        if ($block === null) {
            throw new DraftNotFound;
        }

        $contractVersion = (int) $block->contract_version;

        try {
            $type = $this->types->resolve((string) $block->type_key, $contractVersion);
        } catch (UnknownBlockType $e) {
            // The pinned package is gone: nothing can be frozen against it. Nothing was written.
            throw new PublishRefused(PublishRefused::BLOCK_TYPE_UNAVAILABLE, $e);
        }

        // The Endpoint's revision is read again with a shared lock held to the commit: a save of the Endpoint cannot slip in between the check and the pin.
        $source = is_array($draft->config['source'] ?? null) ? $draft->config['source'] : [];

        try {
            $live = $this->endpoints->lockRevision($actor->workspaceId, (string) ($source['data_source_id'] ?? ''), $report->endpointId);
        } catch (DataSourceNotFound|EndpointNotFound) {
            throw new PublishRefused(PublishRefused::STALE);
        }

        if ($live !== $report->endpointRevision) {
            throw new PublishRefused(PublishRefused::STALE);
        }

        $fieldInfo = $this->fieldInfo($actor->workspaceId, $id, $draft->config);

        if (! FrozenConfig::resolved($draft->config, $fieldInfo)) {
            throw new PublishRefused(PublishRefused::FIELDS_UNRESOLVED);
        }

        $frozen = FrozenConfig::build($draft->config, $type, $contractVersion, $report->endpointId, $report->endpointRevision, $fieldInfo);

        $now = now();

        try {
            $changed = DB::update(
                "update block_versions set state = 'prepared', config = ?::jsonb, endpoint_id = ?, endpoint_revision = ?, prepared_at = ?, prepared_by_membership_id = ?, updated_at = ? "
                ."where workspace_id = ? and id = ? and state = 'draft' and revision = ?",
                [DraftConfig::encode($frozen), $report->endpointId, $report->endpointRevision, $now, $actor->membershipId, $now, $actor->workspaceId, $id, $report->subjectRevision],
            );
        } catch (QueryException $e) {
            // Another version of this Block already holds the label (the unique index): this Draft cannot become it.
            throw $e->getCode() === '23505' ? new DraftPublished : $e;
        }

        if ($changed !== 1) {
            throw new PublishRefused(PublishRefused::STALE);
        }

        [$major, $minor] = array_map('intval', array_pad(explode('.', $draft->version, 2), 2, '0'));

        $this->outbox->emit(
            AuditAction::BlocksVersionPrepared,
            'block_version:'.$id,
            [
                'block_id' => $draft->blockId,
                'version_id' => $id,
                'block_type' => $draft->type,
                'revision' => $draft->revision,
                'version_major' => $major,
                'version_minor' => $minor,
                'endpoint_id' => $report->endpointId,
                'endpoint_revision' => $report->endpointRevision,
            ],
            actor: $actor->membershipId,
        );

        return $id;
    }

    /**
     * The effective role and type of each stored path of the sample, when the Draft still has one; otherwise nothing is known beyond the Admin's own
     * overrides (which {@see FrozenConfig} applies first).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, array{role: string|null, type: string|null}>
     */
    private function fieldInfo(string $workspaceId, string $draftId, array $config): array
    {
        try {
            $found = $this->shapes->of($workspaceId, $draftId);

            if ($found === null) {
                return [];
            }

            $path = $config['record_path'] ?? null;
            $overrides = is_array($config['field_roles'] ?? null) ? array_filter($config['field_roles'], is_string(...)) : [];
            $out = [];

            foreach ($this->roles->infer($found[0], is_string($path) ? $path : null, $overrides)->fields as $field) {
                if (is_string($field['path'] ?? null)) {
                    $out[$field['path']] = [
                        'role' => is_string($field['role'] ?? null) ? $field['role'] : null,
                        'type' => is_string($field['type'] ?? null) ? $field['type'] : null,
                    ];
                }
            }

            return $out;
        } catch (DraftNotFound|InvalidSampleShape|SampleUnavailable) {
            return [];
        }
    }

    private function notADraft(string $workspaceId, string $id): Throwable
    {
        $row = Str::isUuid($id) ? DB::selectOne('select state from block_versions where workspace_id = ? and id = ?', [$workspaceId, strtolower($id)]) : null;

        return $row !== null && $row->state !== 'discarded' ? new DraftPublished : new DraftNotFound;
    }
}
