<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\Draft;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftChecklist;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftMapping;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\DraftRevisionConflict;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftSlots;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\Blocks\Contracts\InvalidSampleShape;
use App\Modules\Blocks\Contracts\PreviewRefused;
use App\Modules\Blocks\Contracts\SlotChanged;
use App\Modules\Blocks\Contracts\SlotNotFound;
use App\Modules\Blocks\Contracts\SlotRefused;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\BlockTypes\Contracts\KpiCardSlots;
use App\Modules\Datasets\Contracts\DatasetSuggestions;
use App\Modules\Mapping\Contracts\AutoMapping;
use App\Modules\Mapping\Contracts\AutoMapResult;
use App\Modules\Mapping\Contracts\FieldRole;
use App\Modules\Mapping\Contracts\FieldRoleReport;
use App\Modules\Mapping\Contracts\FieldRoles;
use App\Modules\Mapping\Contracts\SlotState;
use App\Platform\Json\SamplePath;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Auto-map and the slot checklist (Story 3.12a), and the one writer of the mapping keys (Story 3.13c). The checklist is computed from the Draft's
 * stored sample structure, `config.record_path`, `config.field_roles`, `config.field_types` and `config.slot_map`; nothing but the Admin's decisions and
 * the plan every write recomputes (`query_plan`, stamped with `mapped_sample_revision`) is stored. The writes take the Draft's row lock, the edit-lock
 * claim as a sample write does and the Draft's revision, and change only `config`. Nothing of the sample is in a refusal.
 */
final class ManageDraftSlots implements DraftSlots
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Drafts $drafts,
        private readonly SampleShapes $shapes,
        private readonly BlockTypeRegistry $types,
        private readonly FieldRoles $roles,
        private readonly AutoMapping $mapper,
        private readonly DatasetSuggestions $datasets,
    ) {}

    public function read(DraftActor $actor, string $draftId): DraftMapping
    {
        $draft = $this->drafts->find($actor->workspaceId, $draftId);
        $state = DraftMapping::SAMPLE_EXPIRED;

        try {
            $found = $this->shapes->of($actor->workspaceId, $draft->id);
        } catch (InvalidSampleShape) {
            $found = null;
            $state = DraftMapping::SAMPLE_INVALID;
        }

        $plan = self::storedPlan($draft->config);
        $suggestion = $this->suggestion($actor->workspaceId, $draft);
        $sampleRevision = $found === null ? null : $found[1];
        $stale = self::planStale($draft->config, $sampleRevision);

        // No usable sample (none, it ended, or it no longer passes the limits): the stored decisions and plan are still the Admin's, and nothing is computed from it.
        if ($found === null) {
            return new DraftMapping(null, null, self::slotMap($draft->config), $plan, $draft->revision, null, $stale, $state, $suggestion);
        }

        $type = $this->types->resolve($draft->type, $this->contractVersion($actor->workspaceId, $draft->blockId));
        $report = $this->report($found[0], $draft->config);

        return new DraftMapping($this->map($type, $report, $draft->config), $report, self::slotMap($draft->config), $plan, $draft->revision, $sampleRevision, $stale, DraftMapping::SAMPLE_OK, $suggestion);
    }

    /**
     * The Dataset catalog's suggestion for a Draft that has not chosen a record path (Story 3.11c); never applied. Null when the Draft has chosen a path or has no Endpoint.
     *
     * @return array{record_path: string, record_path_display: string, fields: list<array{path: string, type: string, role: string|null}>, updated_at: string}|null
     */
    private function suggestion(string $workspaceId, Draft $draft): ?array
    {
        $source = is_array($draft->config['source'] ?? null) ? $draft->config['source'] : [];
        $endpointId = $source['endpoint_id'] ?? null;

        if (is_string($draft->config['record_path'] ?? null) || ! is_string($endpointId) || $endpointId === '') {
            return null;
        }

        $found = $this->datasets->suggestFor($workspaceId, $endpointId);

        if ($found === null) {
            return null;
        }

        try {
            $display = SamplePath::display(SamplePath::parse($found->recordPath));
        } catch (\InvalidArgumentException) {
            return null;
        }

        return ['record_path' => $found->recordPath, 'record_path_display' => $display, 'fields' => $found->fields, 'updated_at' => $found->updatedAt];
    }

    public function fields(DraftActor $actor, string $draftId, string $slot): array
    {
        $draft = $this->drafts->find($actor->workspaceId, $draftId);
        [$type, $shape] = $this->inputs($actor->workspaceId, $draft);

        return $this->mapper->fieldsFor($this->report($shape, $draft->config), $type, $slot, self::fieldTypes($draft->config)) ?? throw new SlotNotFound($slot);
    }

    public function rerun(DraftActor $actor, string $draftId, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist
    {
        // Every write recomputes the plan; a re-run is the write that changes nothing else.
        return $this->write($actor, $draftId, $revision, $lockEpoch, $holdsLock, fn (array $config): array => $config);
    }

    public function confirm(DraftActor $actor, string $draftId, string $slot, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist
    {
        return $this->write($actor, $draftId, $revision, $lockEpoch, $holdsLock, function (array $config, AutoMapResult $now) use ($slot): array {
            $row = $now->slot($slot) ?? throw new SlotNotFound($slot);
            $state = SlotState::from($row['state']);

            if ($row['path'] === null || ! $state->isMapped()) {
                throw new SlotRefused($slot, 'This slot has no mapped field to confirm.');
            }

            // A confirmed or overridden slot stays as it is.
            if (! $state->isDecision()) {
                $config['slot_map'] = [...self::slotMap($config), $slot => ['path' => $row['path'], 'state' => SlotState::Confirmed->value]];
            }

            return $config;
        });
    }

    public function override(DraftActor $actor, string $draftId, string $slot, ?string $path, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist
    {
        $roleChange = null;
        $undo = null;

        $checklist = $this->write($actor, $draftId, $revision, $lockEpoch, $holdsLock, function (array $config, AutoMapResult $now, FieldRoleReport $report, BlockType $type) use ($slot, $path, &$roleChange, &$undo): array {
            $definition = null;

            foreach ($type->slots as $candidate) {
                if ($candidate->key === $slot) {
                    $definition = $candidate;
                }
            }

            if ($definition === null || $now->slot($slot) === null) {
                throw new SlotNotFound($slot);
            }

            $roles = is_array($config['field_roles'] ?? null) ? $config['field_roles'] : [];
            $previous = self::slotMap($config)[$slot] ?? null;
            $undo = ['slot' => $slot, 'slot_entry' => is_array($previous) ? ['path' => $previous['path'] ?? null, 'state' => $previous['state'] ?? null] : null, 'role' => null, 'applied' => ['path' => $path, 'state' => SlotState::Overridden->value]];

            if ($path !== null) {
                $entries = $this->mapper->fieldsFor($report, $type, $slot, self::fieldTypes($config)) ?? throw new SlotNotFound($slot);
                $entry = null;

                foreach ($entries as $candidate) {
                    if (($candidate['path'] ?? null) === $path) {
                        $entry = $candidate;
                    }
                }

                if ($entry === null) {
                    throw new SlotRefused($slot, 'That field is not in the sample.');
                }

                if ($entry['compatible'] !== true) {
                    $compatible = array_values(array_map(
                        fn (array $e): array => ['path' => (string) $e['path'], 'display' => (string) $e['display']],
                        array_filter($entries, fn (array $e): bool => $e['kind'] === 'field' && $e['compatible'] === true),
                    ));

                    throw new SlotRefused($slot, (string) $entry['reason'], $compatible);
                }

                // A field picked for a number slot is a measure or a value; a field of any other role becomes a measure, and the old role is kept for an undo.
                if ($definition->valueType === 'number' && ! in_array($entry['role'], [FieldRole::Value->value, FieldRole::Measure->value], true)) {
                    $undo['role'] = ['path' => $path, 'previous' => $roles[$path] ?? null];
                    $roleChange = ['path' => $path, 'from' => (string) $entry['role'], 'to' => FieldRole::Measure->value];
                    $config['field_roles'] = [...$roles, $path => FieldRole::Measure->value];
                }
            }

            $config['slot_map'] = [...self::slotMap($config), $slot => ['path' => $path, 'state' => SlotState::Overridden->value]];

            return $config;
        });

        return new DraftChecklist($checklist->result, $checklist->revision, $checklist->queryPlan, $roleChange, $undo, $checklist->sampleRevision);
    }

    public function undo(DraftActor $actor, string $draftId, array $undo, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist
    {
        return $this->write($actor, $draftId, $revision, $lockEpoch, $holdsLock, function (array $config, AutoMapResult $now, FieldRoleReport $report, BlockType $type) use ($undo, $actor, $draftId): array {
            $slot = is_string($undo['slot'] ?? null) ? $undo['slot'] : '';
            $mappable = array_filter($type->slots, fn ($candidate): bool => $candidate->key === $slot && ! in_array($slot, [KpiCardSlots::SLOT_ICON, KpiCardSlots::SLOT_FOOTER_ACTION], true));

            if ($mappable === []) {
                throw new SlotRefused($slot, 'This slot cannot be mapped.');
            }

            $entry = $undo['slot_entry'] ?? null;
            $role = $undo['role'] ?? null;
            $applied = is_array($undo['applied'] ?? null) ? $undo['applied'] : null;
            $map = self::slotMap($config);

            // Only the override that wrote the slot's current entry can be undone: a later decision is never overwritten.
            $current = $map[$slot] ?? null;

            if ($applied === null || ! is_array($current) || ($current['path'] ?? null) !== ($applied['path'] ?? null) || ($current['state'] ?? null) !== ($applied['state'] ?? null)) {
                throw new SlotChanged($this->drafts->find($actor->workspaceId, $draftId));
            }

            // A restored path must still be one the slot accepts, as for an override.
            if (is_array($entry) && is_string($entry['path'] ?? null)) {
                $fits = array_filter($this->mapper->fieldsFor($report, $type, $slot, self::fieldTypes($config)) ?? [], fn (array $e): bool => ($e['path'] ?? null) === $entry['path'] && $e['compatible'] === true);

                if ($fits === []) {
                    throw new SlotRefused($slot, 'The field to restore is no longer one this slot accepts.');
                }
            }
            $roles = is_array($config['field_roles'] ?? null) ? $config['field_roles'] : [];

            if ($entry === null) {
                unset($map[$slot]);
            } else {
                $map[$slot] = $entry;
            }

            if ($role !== null) {
                if (! is_array($role)) {
                    throw new InvalidDraft(['undo.role' => ['The role to restore is malformed.']], 'blocks.invalid_config');
                }

                $path = (string) ($role['path'] ?? '');

                // The role override to put back belongs to the field the override picked or replaced, no other.
                if ($path === '' || ! in_array($path, array_filter([is_array($entry) ? ($entry['path'] ?? null) : null, $applied['path'] ?? null], 'is_string'), true)) {
                    throw new InvalidDraft(['undo.role.path' => ['The role to restore does not belong to this slot.']], 'blocks.invalid_config');
                }

                if (($role['previous'] ?? null) === null) {
                    unset($roles[$path]);
                } else {
                    $roles[$path] = $role['previous'];
                }
            }

            // The restored pieces are judged like any write of them: stored paths, known roles, known states.
            try {
                $checked = DraftConfig::normaliseMapping(['slot_map' => $map, 'field_roles' => $roles], $type);
            } catch (InvalidDraft $e) {
                throw new InvalidDraft(array_combine(array_map(fn (string $key): string => (string) preg_replace('/\A(config\.)/', 'undo.', $key), array_keys($e->errors)), array_values($e->errors)), $e->errorCode);
            }

            $config['slot_map'] = $checked['slot_map'];
            $config['field_roles'] = $checked['field_roles'];

            // An emptied map is no map, as before the override.
            foreach (['slot_map', 'field_roles'] as $key) {
                if ($config[$key] === []) {
                    unset($config[$key]);
                }
            }

            // The unconfirmed slots are filled from the restored roles, and the plan is written from them, by the write itself.
            return $config;
        });
    }

    public function setRecordPath(DraftActor $actor, string $draftId, ?string $recordPath, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist
    {
        return $this->write($actor, $draftId, $revision, $lockEpoch, $holdsLock, function (array $config, AutoMapResult $now, FieldRoleReport $report) use ($recordPath): array {
            if ($recordPath === null) {
                unset($config['record_path']);

                return $config;
            }

            if (! DraftConfig::isRecordPath($recordPath)) {
                throw new InvalidDraft(['record_path' => ['Choose an array of records, such as $.data[*].']], 'blocks.invalid_config');
            }

            if (! in_array($recordPath, array_column($report->candidates, 'path'), true)) {
                throw new InvalidDraft(['record_path' => ['That array of records is not in the sample.']], 'blocks.invalid_config');
            }

            $config['record_path'] = $recordPath;

            return $config;
        }, true);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<mixed>
     */
    private static function catalogKeys(array $config): array
    {
        return [$config['record_path'] ?? null, $config['field_roles'] ?? null, $config['field_types'] ?? null];
    }

    /**
     * Remembers the chosen record path and its typed fields as a suggestion for new Drafts of the Endpoint, in the write's transaction (Story 3.11c). The
     * Draft's config stays authoritative; a Draft with no Endpoint remembers nothing. Only paths, inferred types and the effective roles are passed on.
     *
     * @param  array<string, mixed>  $config  the config as the write leaves it
     * @param  array<string, mixed>  $shape
     */
    private function remember(string $workspaceId, array $config, array $shape): void
    {
        $source = is_array($config['source'] ?? null) ? $config['source'] : [];
        $endpointId = $source['endpoint_id'] ?? null;
        $path = $config['record_path'] ?? null;

        if (! is_string($endpointId) || $endpointId === '' || ! is_string($path)) {
            return;
        }

        $fields = [];

        foreach ($this->report($shape, $config)->fields as $field) {
            if (($field['in_records'] ?? false) === true) {
                $fields[] = ['path' => (string) $field['path'], 'type' => (string) $field['type'], 'role' => is_string($field['role'] ?? null) ? $field['role'] : null];
            }
        }

        $this->datasets->remember($workspaceId, $endpointId, $path, $fields);
    }

    public function setFieldRole(DraftActor $actor, string $draftId, string $path, ?string $role, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist
    {
        return $this->write($actor, $draftId, $revision, $lockEpoch, $holdsLock, function (array $config, AutoMapResult $now, FieldRoleReport $report) use ($path, $role): array {
            $roles = is_array($config['field_roles'] ?? null) ? $config['field_roles'] : [];

            if ($role === null) {
                // Clearing is always allowed, a stale override included.
                unset($roles[$path]);
            } else {
                $this->checkField($path, $report);

                if (FieldRole::tryFrom($role) === null) {
                    throw new InvalidDraft(['role' => ['Choose one of: '.implode(', ', array_column(FieldRole::cases(), 'value')).'.']], 'blocks.invalid_config');
                }

                $roles[$path] = $role;
            }

            return self::withKey($config, 'field_roles', $roles);
        });
    }

    public function setFieldType(DraftActor $actor, string $draftId, string $path, ?string $type, int $revision, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist
    {
        return $this->write($actor, $draftId, $revision, $lockEpoch, $holdsLock, function (array $config, AutoMapResult $now, FieldRoleReport $report) use ($path, $type): array {
            $types = self::fieldTypes($config);

            if ($type === null) {
                unset($types[$path]);
            } else {
                $this->checkField($path, $report);

                if (! in_array($type, DraftConfig::FIELD_TYPES, true)) {
                    throw new InvalidDraft(['type' => ['Choose one of: '.implode(', ', DraftConfig::FIELD_TYPES).'.']], 'blocks.invalid_config');
                }

                $types[$path] = $type;
            }

            return self::withKey($config, 'field_types', $types);
        });
    }

    /** A path to set a role or a type for: a stored path of a field of the sample. */
    private function checkField(string $path, FieldRoleReport $report): void
    {
        if (! DraftConfig::isCanonicalPath($path)) {
            throw new InvalidDraft(['path' => ['Use a stored path such as $.data[*].revenue.']], 'blocks.invalid_config');
        }

        if (! in_array($path, array_column($report->fields, 'path'), true)) {
            throw new InvalidDraft(['path' => ['That field is not in the sample.']], 'blocks.invalid_config');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $value
     * @return array<string, mixed> the config with the map set, or without the key when the map is empty
     */
    private static function withKey(array $config, string $key, array $value): array
    {
        if ($value === []) {
            unset($config[$key]);
        } else {
            $config[$key] = $value;
        }

        return $config;
    }

    /**
     * One writer: the Draft's row lock, the edit-lock claim (423 and nothing written when lost), the Draft's revision (409 and nothing written when
     * stale), the sample and Block Type inputs, `$change` (which may refuse), then the plan recomputed from the resulting mapping and stamped with the
     * sample revision (an empty mapping removes the plan and the stamp), then `config` and the revision.
     *
     * @param  \Closure(array<string, mixed>, AutoMapResult, FieldRoleReport, BlockType, array<string, mixed>): array<string, mixed>  $change  the last argument is the sample's shape
     * @param  bool  $remember  refresh the catalog even when the mapping keys are unchanged (a record-path pick)
     */
    private function write(DraftActor $actor, string $draftId, int $revision, int $lockEpoch, ?\Closure $holdsLock, \Closure $change, bool $remember = false): DraftChecklist
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $draftId, $revision, $lockEpoch, $holdsLock, $change, $remember): DraftChecklist {
            $row = Str::isUuid($draftId)
                ? DB::selectOne("select id, lock_epoch from block_versions where workspace_id = ? and id = ? and state = 'draft' for update", [$actor->workspaceId, strtolower($draftId)])
                : null;

            if ($row === null) {
                throw $this->notADraft($actor->workspaceId, $draftId);
            }

            $draft = $this->drafts->find($actor->workspaceId, (string) $row->id);

            if ((int) $row->lock_epoch !== $lockEpoch || ($holdsLock !== null && $holdsLock() !== true)) {
                throw new DraftLockLost($draft);
            }

            if ($draft->revision !== $revision) {
                throw new DraftRevisionConflict($draft);
            }

            [$type, $shape, $sampleRevision] = $this->inputs($actor->workspaceId, $draft);
            $report = $this->report($shape, $draft->config);
            $config = $change($draft->config, $this->map($type, $report, $draft->config), $report, $type, $shape);

            // The plan follows the mapping the write leaves, whatever the write was.
            $after = $this->map($type, $this->report($shape, $config), $config);

            if ($after->plan['shape'] !== []) {
                $config['query_plan'] = $after->plan;
                $config['mapped_sample_revision'] = $sampleRevision;
            } else {
                unset($config['query_plan'], $config['mapped_sample_revision']);
            }

            // The catalog follows the chosen path: a record-path pick always, and any write that changed the path, the roles or the types under it.
            if (is_string($config['record_path'] ?? null) && ($remember || self::catalogKeys($config) !== self::catalogKeys($draft->config))) {
                $this->remember($actor->workspaceId, $config, $shape);
            }

            // A write that changes nothing (a confirm of a decision, a re-run with the same plan) leaves the config and the revision alone.
            if (self::canonical($config) === self::canonical($draft->config)) {
                return new DraftChecklist($after, $draft->revision, self::storedPlan($config), null, null, $sampleRevision);
            }

            DB::update(
                'update block_versions set config = ?::jsonb, revision = revision + 1, updated_at = ? where workspace_id = ? and id = ?',
                [DraftConfig::encode($config), now(), $actor->workspaceId, $draft->id],
            );

            // The checklist as it now reads: a decision just stored shows as one.
            return new DraftChecklist($after, $draft->revision + 1, self::storedPlan($config), null, null, $sampleRevision);
        });
    }

    /**
     * @return array{0: BlockType, 1: array<string, mixed>, 2: int} the Block Type, the sample's structure and its revision
     *
     * @throws PreviewRefused
     */
    private function inputs(string $workspaceId, Draft $draft): array
    {
        $found = $this->shapes->of($workspaceId, $draft->id);

        if ($found === null) {
            throw new PreviewRefused(PreviewRefused::NO_SAMPLE);
        }

        return [$this->types->resolve($draft->type, $this->contractVersion($workspaceId, $draft->blockId)), $found[0], $found[1]];
    }

    /**
     * The config as one canonical string for a strict comparison: keys sorted at every level (the stored order is not the written one), types kept, so
     * `1` and `true`, or `"1"` and `1`, differ.
     *
     * @param  array<string, mixed>  $config
     */
    private static function canonical(array $config): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }

            ksort($value);

            return array_map($sort, $value);
        };

        return DraftConfig::encode($sort($config));
    }

    /**
     * A stored plan is stale unless it was stamped with the sample revision that is live now: an unstamped (hand-saved) plan and an absent sample count as stale.
     *
     * @param  array<string, mixed>  $config
     */
    public static function planStale(array $config, ?int $sampleRevision): bool
    {
        $stamp = is_int($config['mapped_sample_revision'] ?? null) ? $config['mapped_sample_revision'] : null;

        return self::storedPlan($config) !== null && ($stamp === null || $sampleRevision === null || $stamp !== $sampleRevision);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null the stored plan, or null when there is none
     */
    private static function storedPlan(array $config): ?array
    {
        return is_array($config['query_plan'] ?? null) && $config['query_plan'] !== [] ? $config['query_plan'] : null;
    }

    /**
     * The field roles of the sample under a config's record path and role overrides.
     *
     * @param  array<string, mixed>  $shape
     * @param  array<string, mixed>  $config
     */
    private function report(array $shape, array $config): FieldRoleReport
    {
        $path = $config['record_path'] ?? null;
        $overrides = is_array($config['field_roles'] ?? null) ? array_filter($config['field_roles'], is_string(...)) : [];

        return $this->roles->infer($shape, is_string($path) ? $path : null, $overrides);
    }

    /** @param  array<string, mixed>  $config */
    private function map(BlockType $type, FieldRoleReport $report, array $config): AutoMapResult
    {
        return $this->mapper->map($report, $type, self::slotMap($config), self::fieldTypes($config));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function fieldTypes(array $config): array
    {
        return is_array($config['field_types'] ?? null) ? $config['field_types'] : [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function slotMap(array $config): array
    {
        return is_array($config['slot_map'] ?? null) ? $config['slot_map'] : [];
    }

    /** The contract version the Block pinned when it was started. */
    private function contractVersion(string $workspaceId, string $blockId): int
    {
        return $this->transactions->run($workspaceId, function () use ($workspaceId, $blockId): int {
            $row = DB::selectOne('select contract_version from blocks where workspace_id = ? and id = ?', [$workspaceId, $blockId]);

            return $row === null ? throw new DraftNotFound : (int) $row->contract_version;
        });
    }

    private function notADraft(string $workspaceId, string $id): \Throwable
    {
        $row = Str::isUuid($id) ? DB::selectOne('select state from block_versions where workspace_id = ? and id = ?', [$workspaceId, strtolower($id)]) : null;

        return $row !== null && $row->state !== 'discarded' ? new DraftPublished : new DraftNotFound;
    }
}
