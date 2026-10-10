<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\Draft;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftChecklist;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\DraftRevisionConflict;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\DraftSlots;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\Blocks\Contracts\PreviewRefused;
use App\Modules\Blocks\Contracts\SlotNotFound;
use App\Modules\Blocks\Contracts\SlotRefused;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\BlockTypes\Contracts\KpiCardSlots;
use App\Modules\Mapping\Contracts\AutoMapping;
use App\Modules\Mapping\Contracts\AutoMapResult;
use App\Modules\Mapping\Contracts\FieldRole;
use App\Modules\Mapping\Contracts\FieldRoleReport;
use App\Modules\Mapping\Contracts\FieldRoles;
use App\Modules\Mapping\Contracts\SlotState;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Auto-map and the slot checklist (Story 3.12a). The checklist is computed from the Draft's stored sample structure, `config.record_path`,
 * `config.field_roles` and `config.slot_map`; nothing but the Admin's decisions (`slot_map`) and the plan a re-run writes is stored. The writes
 * take the Draft's row lock and the edit-lock claim as a sample write does, and change only `config`. Nothing of the sample is in a refusal.
 */
final class ManageDraftSlots implements DraftSlots
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly Drafts $drafts,
        private readonly DraftSamples $samples,
        private readonly BlockTypeRegistry $types,
        private readonly FieldRoles $roles,
        private readonly AutoMapping $mapper,
    ) {}

    public function checklist(DraftActor $actor, string $draftId): DraftChecklist
    {
        $draft = $this->drafts->find($actor->workspaceId, $draftId);
        [$type, $shape] = $this->inputs($actor->workspaceId, $draft);

        return new DraftChecklist($this->map($type, $this->report($shape, $draft->config), $draft->config), $draft->revision);
    }

    public function fields(DraftActor $actor, string $draftId, string $slot): array
    {
        $draft = $this->drafts->find($actor->workspaceId, $draftId);
        [$type, $shape] = $this->inputs($actor->workspaceId, $draft);

        return $this->mapper->fieldsFor($this->report($shape, $draft->config), $type, $slot, self::fieldTypes($draft->config)) ?? throw new SlotNotFound($slot);
    }

    public function rerun(DraftActor $actor, string $draftId, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist
    {
        return $this->write($actor, $draftId, $lockEpoch, $holdsLock, function (array $config, AutoMapResult $now): array {
            // With nothing mapped there is no plan to write, and a plan already stored is not replaced by an empty one.
            if ($now->plan['shape'] !== []) {
                $config['query_plan'] = $now->plan;
            }

            return $config;
        }, true);
    }

    public function confirm(DraftActor $actor, string $draftId, string $slot, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist
    {
        return $this->write($actor, $draftId, $lockEpoch, $holdsLock, function (array $config, AutoMapResult $now) use ($slot): array {
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

    public function override(DraftActor $actor, string $draftId, string $slot, ?string $path, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist
    {
        $roleChange = null;
        $undo = null;

        $checklist = $this->write($actor, $draftId, $lockEpoch, $holdsLock, function (array $config, AutoMapResult $now, FieldRoleReport $report, BlockType $type) use ($slot, $path, &$roleChange, &$undo): array {
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

        return new DraftChecklist($checklist->result, $checklist->revision, null, $roleChange, $undo);
    }

    public function undo(DraftActor $actor, string $draftId, array $undo, int $lockEpoch, ?\Closure $holdsLock = null): DraftChecklist
    {
        return $this->write($actor, $draftId, $lockEpoch, $holdsLock, function (array $config, AutoMapResult $now, FieldRoleReport $report, BlockType $type, array $shape) use ($undo, $actor, $draftId): array {
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
                throw new DraftRevisionConflict($this->drafts->find($actor->workspaceId, $draftId));
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

            // The restored pieces are judged like any save of them: stored paths, known roles, known states.
            try {
                $checked = DraftConfig::normalise(['slot_map' => $map, 'field_roles' => $roles], $type);
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

            // Fill what is not decided from the restored roles, and write the plan from it.
            $after = $this->map($type, $this->report($shape, $config), $config);

            if ($after->plan['shape'] !== []) {
                $config['query_plan'] = $after->plan;
            }

            return $config;
        }, true);
    }

    /**
     * One writer: the Draft's row lock, the edit-lock claim (423 and nothing written when lost), the sample and Block Type inputs, `$change` (which
     * may refuse), then `config` and the revision.
     *
     * @param  \Closure(array<string, mixed>, AutoMapResult, FieldRoleReport, BlockType, array<string, mixed>): array<string, mixed>  $change  the last argument is the sample's shape
     */
    private function write(DraftActor $actor, string $draftId, int $lockEpoch, ?\Closure $holdsLock, \Closure $change, bool $returnPlan = false): DraftChecklist
    {
        return $this->transactions->run($actor->workspaceId, function () use ($actor, $draftId, $lockEpoch, $holdsLock, $change, $returnPlan): DraftChecklist {
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

            [$type, $shape] = $this->inputs($actor->workspaceId, $draft);
            $report = $this->report($shape, $draft->config);
            $config = $change($draft->config, $this->map($type, $report, $draft->config), $report, $type, $shape);

            // A write that changes nothing (a confirm of a decision, a re-run with the same plan) leaves the config and the revision alone.
            if ($config == $draft->config) {
                return new DraftChecklist($this->map($type, $report, $config), $draft->revision, $returnPlan && is_array($config['query_plan'] ?? null) ? $config['query_plan'] : null);
            }

            DB::update(
                'update block_versions set config = ?::jsonb, revision = revision + 1, updated_at = ? where workspace_id = ? and id = ?',
                [DraftConfig::encode($config), now(), $actor->workspaceId, $draft->id],
            );

            // The checklist as it now reads: a decision just stored shows as one.
            $after = $this->map($type, $this->report($shape, $config), $config);

            return new DraftChecklist($after, $draft->revision + 1, $returnPlan && $after->plan['shape'] !== [] ? $after->plan : null);
        });
    }

    /**
     * @return array{0: BlockType, 1: array<string, mixed>} the Block Type and the sample's structure
     *
     * @throws PreviewRefused
     */
    private function inputs(string $workspaceId, Draft $draft): array
    {
        $sample = $this->samples->find($workspaceId, $draft->id);

        if ($sample === null || ! $sample->check->valid || $sample->check->shape === null) {
            throw new PreviewRefused(PreviewRefused::NO_SAMPLE);
        }

        return [$this->types->resolve($draft->type, $this->contractVersion($workspaceId, $draft->blockId)), $sample->check->shape];
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
