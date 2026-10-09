<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\Draft;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftExists;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPage;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\DraftRevisionConflict;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Starts, saves, discards and lists Drafts (Story 3.3) inside the Workspace's transaction.
 *
 * A Block has at most one Draft: creating one locks the Block's row, looks for a Draft and inserts (the partial unique index is the
 * backstop). A save locks the Draft's row and compares the caller's edit-lock epoch first (a stale one is 423, as on the Data Source form),
 * then its revision (a stale one is 409), and writes nothing in either case; success raises the revision and writes no audit event, since a
 * Draft is autosaved. The lenient Draft schema accepts a name, a Block Type, a category, the wizard step and a `config` whose present fields are
 * checked against the registry's limits ({@see DraftConfig}, Story 3.4); strict validation belongs to a later story.
 * Discarding sets `state = 'discarded'` and audits it in the same transaction: from then on the Draft is invisible and cannot be saved. The
 * `app` role holds no DELETE, so the rows are deleted afterwards by {@see PurgeDiscardedDraftsJob} on the `maintenance` worker, which this
 * enqueues after the commit (a lost enqueue is repaired by the scheduled run).
 */
final class ManageDrafts implements Drafts
{
    private const STAMP = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    private const COLUMNS = 'v.id, v.block_id, v.name, b.type_key, coalesce(c.name, b.category) AS category, b.category_id, v.version, v.revision, v.lock_epoch, coalesce(u.step, 1) AS step, b.owner_membership_id, '
        .'to_char(v.created_at, '.self::STAMP.') AS created, to_char(v.updated_at, '.self::STAMP.') AS updated';

    private const WITH_CONFIG = ', v.config::text AS config, u.state::text AS ui_state';

    private const FROM = 'FROM block_versions v JOIN blocks b ON b.id = v.block_id LEFT JOIN draft_ui_state u ON u.block_version_id = v.id LEFT JOIN block_categories c ON c.id = b.category_id';

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Audit $audit,
        private readonly BlockTypeRegistry $types,
        private readonly DraftSource $source,
    ) {}

    public function list(string $workspaceId, ?string $search): DraftPage
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $search): DraftPage {
            $pattern = $this->pattern($search);
            $match = $pattern === null ? 'true' : "(lower(v.name) LIKE lower(?) ESCAPE '!' OR lower(b.type_key) LIKE lower(?) ESCAPE '!' OR lower(coalesce(c.name, b.category, '')) LIKE lower(?) ESCAPE '!')";
            $bindings = $pattern === null ? [] : [$pattern, $pattern, $pattern];

            /** @var object{total: int|string, matched: int|string} $counts */
            $counts = DB::selectOne(
                "SELECT count(*) AS total, count(*) FILTER (WHERE {$match}) AS matched FROM block_versions v JOIN blocks b ON b.id = v.block_id LEFT JOIN block_categories c ON c.id = b.category_id WHERE v.workspace_id = ? AND v.state = 'draft'",
                [...$bindings, $workspaceId],
            );

            $found = DB::select(
                'SELECT '.self::COLUMNS.' '.self::FROM." WHERE v.workspace_id = ? AND v.state = 'draft' AND {$match} ORDER BY v.updated_at DESC, v.id DESC",
                [$workspaceId, ...$bindings],
            );

            return new DraftPage(array_values(array_map(fn (\stdClass $row): Draft => $this->draft($row), $found)), (int) $counts->total, (int) $counts->matched);
        });
    }

    public function create(DraftActor $actor, string $name, string $type, ?string $blockId = null): Draft
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $name, $type, $blockId): Draft {
            $name = $this->checkedName($name);
            $this->checkType($type);

            if ($blockId === null) {
                $blockId = (string) Str::uuid7();
                DB::insert(
                    'insert into blocks (id, workspace_id, type_key, contract_version, category_id, owner_membership_id, created_at, updated_at) values (?, ?, ?, 1, ?, ?, ?, ?)',
                    [$blockId, $actor->workspaceId, $type, GeneralCategory::ensure($actor->workspaceId), $actor->membershipId, now(), now()],
                );
            } else {
                $this->claimBlock($actor->workspaceId, $blockId, $type);
                GeneralCategory::ensure($actor->workspaceId);
            }

            $id = (string) Str::uuid7();

            try {
                // A savepoint: a unique violation must not abort the whole transaction before the existing Draft is read back.
                DB::transaction(function () use ($actor, $id, $blockId, $name): void {
                    DB::insert(
                        "insert into block_versions (id, workspace_id, block_id, state, version, name, revision, lock_epoch, created_by_membership_id, created_at, updated_at) values (?, ?, ?, 'draft', '1.0', ?, 1, 1, ?, ?, ?)",
                        [$id, $actor->workspaceId, $blockId, $name, $actor->membershipId, now(), now()],
                    );
                });
            } catch (QueryException $e) {
                throw $this->draftExists($actor->workspaceId, $blockId, $e);
            }

            DB::insert(
                'insert into draft_ui_state (id, workspace_id, block_version_id, step, state, updated_at) values (?, ?, ?, 1, ?::jsonb, ?)',
                [(string) Str::uuid7(), $actor->workspaceId, $id, '{}', now()],
            );

            $created = $this->fetch($actor->workspaceId, $id, false) ?? throw new DraftNotFound;

            $this->audit->record(
                AuditAction::BlocksDraftCreated,
                $this->auditState($created),
                subject: 'block_draft:'.$id,
                actor: $actor->membershipId,
            );

            return $created;
        });
    }

    public function find(string $workspaceId, string $draftId): Draft
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $draftId): Draft {
            GeneralCategory::ensure($workspaceId);

            return $this->fetch($workspaceId, $draftId, false, true) ?? throw new DraftNotFound;
        });
    }

    public function save(DraftActor $actor, string $draftId, string $name, ?string $type, int $revision, int $lockEpoch, ?array $config = null, ?int $step = null, ?string $categoryId = null, ?array $uiState = null, ?\Closure $holdsLock = null): Draft
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $draftId, $name, $type, $revision, $lockEpoch, $config, $step, $categoryId, $uiState, $holdsLock): Draft {
            // With the stored config, so a 409 or 423 body shows the real document.
            $before = $this->fetch($actor->workspaceId, $draftId, true, true) ?? throw $this->notADraft($actor->workspaceId, $draftId);

            // The edit lock first, as on the Data Source form: a take-over saves the holder's work and so raises the revision as well.
            if ($before->lockEpoch !== $lockEpoch || ($holdsLock !== null && $holdsLock() !== true)) {
                throw new DraftLockLost($before);
            }

            if ($before->revision !== $revision) {
                throw new DraftRevisionConflict($before);
            }

            $name = $this->checkedName($name);

            if ($type !== null) {
                $this->checkType($type);
            }

            // The config is judged against the type the Draft will have after this save.
            $normalised = $config === null ? null : $this->mergedConfig($actor->workspaceId, $before, $config, $type);

            // The Block's current category may have been archived since: keeping it is not a change.
            if ($categoryId !== null && strtolower($categoryId) !== $before->categoryId) {
                $this->checkCategory($actor->workspaceId, $categoryId);
            }

            if ($step !== null && ($step < 1 || $step > 5)) {
                throw new InvalidDraft(['step' => ['Choose a step from 1 to 5.']]);
            }

            DB::update(
                'update block_versions set name = ?, config = coalesce(?::jsonb, config), revision = revision + 1, updated_at = ? where workspace_id = ? and id = ?',
                [$name, $normalised === null ? null : DraftConfig::encode($normalised), now(), $actor->workspaceId, $before->id],
            );

            if (($type !== null && $type !== $before->type) || $categoryId !== null) {
                DB::update(
                    'update blocks set type_key = ?, category_id = coalesce(?::uuid, category_id), updated_at = ? where workspace_id = ? and id = ?',
                    [$type ?? $before->type, $categoryId === null ? null : strtolower($categoryId), now(), $actor->workspaceId, $before->blockId],
                );
            }

            if ($step !== null || $uiState !== null) {
                $this->saveUiState($actor->workspaceId, $before->id, $step, $uiState);
            }

            return $this->fetch($actor->workspaceId, $before->id, false, true) ?? throw new DraftNotFound;
        });
    }

    /**
     * The config this save leaves: Step 1's fields judged against the Block Type, Step 2's `source` (when the save carries one) checked and
     * pinned by {@see DraftSource}, and every other stored key kept. Both steps' errors come back together.
     *
     * @param  array<mixed>  $config
     * @return array<string, mixed>
     */
    private function mergedConfig(string $workspaceId, Draft $before, array $config, ?string $type): array
    {
        $errors = [];
        $step = [];

        try {
            $step = DraftConfig::normalise($config, $this->checkType($type ?? $before->type));
        } catch (InvalidDraft $e) {
            $errors = $e->errors;
        }

        if (array_key_exists('source', $config)) {
            try {
                $stored = is_array($before->config['source'] ?? null) ? $before->config['source'] : [];
                $step['source'] = $this->source->normalise($workspaceId, $config['source'], $stored);
            } catch (InvalidDraft $e) {
                $errors += $e->errors;
            }
        }

        if ($errors !== []) {
            throw new InvalidDraft($errors, 'blocks.invalid_config');
        }

        return DraftConfig::merge($before->config, $step);
    }

    /**
     * Where the wizard reopens the Draft: the step and the wizard's own state. The row exists from the start, but a Draft seeded without one
     * gets it here. A state replaces the stored one (the client sends it whole); a field left null is left as it is.
     *
     * @param  array<mixed>|null  $uiState
     */
    private function saveUiState(string $workspaceId, string $versionId, ?int $step, ?array $uiState): void
    {
        $state = $uiState === null ? null : DraftConfig::encodeUiState($uiState);

        $changed = DB::update(
            'update draft_ui_state set step = coalesce(?, step), state = coalesce(?::jsonb, state), updated_at = ? where workspace_id = ? and block_version_id = ?',
            [$step, $state, now(), $workspaceId, $versionId],
        );

        if ($changed === 0) {
            DB::insert(
                'insert into draft_ui_state (id, workspace_id, block_version_id, step, state, updated_at) values (?, ?, ?, ?, ?::jsonb, ?)',
                [(string) Str::uuid7(), $workspaceId, $versionId, $step ?? 1, $state ?? '{}', now()],
            );
        }
    }

    /** Why a save found no Draft: the version is past Draft (published), or it is gone or discarded (a 404). */
    private function notADraft(string $workspaceId, string $id): Throwable
    {
        $row = Str::isUuid($id) ? DB::selectOne('select state from block_versions where workspace_id = ? and id = ?', [$workspaceId, strtolower($id)]) : null;

        return $row !== null && $row->state !== 'discarded' ? new DraftPublished : new DraftNotFound;
    }

    /** A category of this Workspace that is not archived (row-level security hides every other Workspace's). */
    private function checkCategory(string $workspaceId, string $categoryId): void
    {
        $found = Str::isUuid($categoryId)
            ? DB::selectOne('select id from block_categories where workspace_id = ? and id = ? and not archived', [$workspaceId, strtolower($categoryId)])
            : null;

        if ($found === null) {
            throw new InvalidDraft(['category_id' => ['Choose a category that exists.']]);
        }
    }

    public function discard(DraftActor $actor, string $draftId, ?int $lockEpoch = null, ?\Closure $holdsLock = null): void
    {
        $this->transactions->run($actor->workspaceId, function () use ($actor, $draftId, $lockEpoch, $holdsLock): void {
            $draft = $this->fetch($actor->workspaceId, $draftId, true) ?? throw new DraftNotFound;

            // The same edit-lock claim as a save: a discard by a non-holder or with a stale epoch changes nothing.
            if (($lockEpoch !== null && $draft->lockEpoch !== $lockEpoch) || ($holdsLock !== null && $holdsLock() !== true)) {
                throw new DraftLockLost($draft);
            }

            DB::update(
                "update block_versions set state = 'discarded', discarded_at = ?, updated_at = ? where workspace_id = ? and id = ? and state = 'draft'",
                [now(), now(), $actor->workspaceId, $draft->id],
            );

            $this->audit->record(
                AuditAction::BlocksDraftDiscarded,
                subject: 'block_draft:'.$draft->id,
                actor: $actor->membershipId,
                before: $this->auditState($draft),
            );

            $this->enqueuePurge($actor->workspaceId);
        });
    }

    /** Locks the Block of a new Draft and refuses a Block that is not there, has another type or already has a Draft. */
    private function claimBlock(string $workspaceId, string $blockId, string $type): void
    {
        /** @var object{type_key: string}|null $block */
        $block = Str::isUuid($blockId)
            ? DB::selectOne('select type_key from blocks where workspace_id = ? and id = ? for update', [$workspaceId, strtolower($blockId)])
            : null;

        if ($block === null) {
            throw new InvalidDraft(['block_id' => ['This block does not exist.']]);
        }

        if ($block->type_key !== $type) {
            throw new InvalidDraft(['type' => ['A block keeps its type.']]);
        }

        $existing = DB::selectOne("select id from block_versions where workspace_id = ? and block_id = ? and state = 'draft'", [$workspaceId, strtolower($blockId)]);

        if ($existing !== null) {
            throw new DraftExists(strtolower((string) $existing->id));
        }
    }

    /** The Draft that won the race for the Block (the unique index fired), or the original failure when it is something else. */
    private function draftExists(string $workspaceId, string $blockId, QueryException $e): Throwable
    {
        if ($e->getCode() !== '23505') {
            return $e;
        }

        $existing = DB::selectOne("select id from block_versions where workspace_id = ? and block_id = ? and state = 'draft'", [$workspaceId, $blockId]);

        return $existing === null ? $e : new DraftExists(strtolower((string) $existing->id));
    }

    private function enqueuePurge(string $workspaceId): void
    {
        try {
            PurgeDiscardedDraftsJob::dispatch($workspaceId)->afterCommit();
        } catch (Throwable $e) {
            // The discard stands: the scheduled run deletes what this enqueue would have. Only the class is logged.
            Log::error('blocks.draft_purge.enqueue_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
        }
    }

    private function fetch(string $workspaceId, string $id, bool $lock, bool $withConfig = false): ?Draft
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT '.self::COLUMNS.($withConfig ? self::WITH_CONFIG : '').' '.self::FROM." WHERE v.workspace_id = ? AND v.id = ? AND v.state = 'draft'".($lock ? ' FOR UPDATE OF v' : ''),
            [$workspaceId, strtolower($id)],
        );

        return $row === null ? null : $this->draft($row);
    }

    private function draft(\stdClass $row): Draft
    {
        return new Draft(
            id: strtolower((string) $row->id),
            blockId: strtolower((string) $row->block_id),
            name: (string) $row->name,
            type: (string) $row->type_key,
            category: $row->category === null ? null : (string) $row->category,
            version: (string) $row->version,
            revision: (int) $row->revision,
            lockEpoch: (int) $row->lock_epoch,
            step: (int) $row->step,
            ownerMembershipId: strtolower((string) $row->owner_membership_id),
            createdAt: (string) $row->created,
            updatedAt: (string) $row->updated,
            categoryId: $row->category_id === null ? null : strtolower((string) $row->category_id),
            config: isset($row->config) ? DraftConfig::decode((string) $row->config) : [],
            uiState: isset($row->ui_state) ? DraftConfig::decodeUiState((string) $row->ui_state) : [],
        );
    }

    /** @return array<string, mixed> */
    private function auditState(Draft $draft): array
    {
        return [
            'block_id' => $draft->blockId,
            'draft_id' => $draft->id,
            'block_type' => $draft->type,
            'name' => $draft->name,
            'revision' => $draft->revision,
        ];
    }

    private function checkedName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidDraft(['name' => ['Enter a name.']]);
        }

        if (mb_strlen($name) > self::NAME_MAX || preg_match('/[\p{Cc}\p{Cf}]/u', $name) === 1) {
            throw new InvalidDraft(['name' => ['Use up to '.self::NAME_MAX.' characters, with no control characters.']]);
        }

        return $name;
    }

    private function checkType(string $type): BlockType
    {
        foreach ($this->types->all() as $known) {
            if ($known->key === $type) {
                return $known;
            }
        }

        throw new InvalidDraft(['type' => ['Choose a block type that exists.']]);
    }

    /** A `LIKE` pattern for the search term with its wildcards escaped; null when there is nothing to search. */
    private function pattern(?string $search): ?string
    {
        $search = trim(mb_scrub(str_replace("\0", '', (string) $search), 'UTF-8'));

        return $search === '' ? null : '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
    }
}
