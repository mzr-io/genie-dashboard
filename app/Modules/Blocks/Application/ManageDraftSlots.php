<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\Draft;
use App\Modules\Blocks\Contracts\DraftActor;
use App\Modules\Blocks\Contracts\DraftChecklist;
use App\Modules\Blocks\Contracts\DraftLockLost;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\DraftPublished;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\DraftSamples;
use App\Modules\Blocks\Contracts\DraftSlots;
use App\Modules\Blocks\Contracts\PreviewRefused;
use App\Modules\Blocks\Contracts\SlotNotFound;
use App\Modules\Blocks\Contracts\SlotRefused;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\Mapping\Contracts\AutoMapping;
use App\Modules\Mapping\Contracts\AutoMapResult;
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
        [$type, $report] = $this->inputs($actor->workspaceId, $draft);

        return new DraftChecklist($this->mapper->map($report, $type, self::slotMap($draft->config)), $draft->revision);
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
        return $this->write($actor, $draftId, $lockEpoch, $holdsLock, function (array $config, AutoMapResult $now, FieldRoleReport $report, BlockType $type) use ($slot, $path): array {
            $definition = null;

            foreach ($type->slots as $candidate) {
                if ($candidate->key === $slot) {
                    $definition = $candidate;
                }
            }

            if ($definition === null || $now->slot($slot) === null) {
                throw new SlotNotFound($slot);
            }

            if ($path !== null) {
                $field = array_column($report->fields, null, 'path')[$path] ?? null;

                if ($field === null) {
                    throw new SlotRefused($slot, 'That field is not in the sample.');
                }

                if ($field['in_records'] === true || str_contains($path, '[*]')) {
                    throw new SlotRefused($slot, 'That field has one value per row; this slot needs a single value.');
                }

                $types = array_values(array_diff(array_map('trim', explode('|', (string) $field['type'])), ['null']));
                $numeric = $types !== [] && array_diff($types, ['integer', 'decimal']) === [];
                $boolean = $types === ['boolean'];
                $fits = match ($definition->valueType) {
                    'number' => $numeric,
                    'boolean' => $boolean,
                    default => ! $numeric && ! $boolean,
                };

                if (! $fits) {
                    throw new SlotRefused($slot, 'That field\'s type does not fit this slot ('.$definition->valueType.').');
                }
            }

            $config['slot_map'] = [...self::slotMap($config), $slot => ['path' => $path, 'state' => SlotState::Overridden->value]];

            return $config;
        });
    }

    /**
     * One writer: the Draft's row lock, the edit-lock claim (423 and nothing written when lost), the sample and Block Type inputs, `$change` (which
     * may refuse), then `config` and the revision.
     *
     * @param  \Closure(array<string, mixed>, AutoMapResult, FieldRoleReport, BlockType): array<string, mixed>  $change
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

            [$type, $report] = $this->inputs($actor->workspaceId, $draft);
            $config = $change($draft->config, $this->mapper->map($report, $type, self::slotMap($draft->config)), $report, $type);

            // A write that changes nothing (a confirm of a decision, a re-run with the same plan) leaves the config and the revision alone.
            if ($config == $draft->config) {
                return new DraftChecklist($this->mapper->map($report, $type, self::slotMap($config)), $draft->revision, $returnPlan && is_array($config['query_plan'] ?? null) ? $config['query_plan'] : null);
            }

            DB::update(
                'update block_versions set config = ?::jsonb, revision = revision + 1, updated_at = ? where workspace_id = ? and id = ?',
                [DraftConfig::encode($config), now(), $actor->workspaceId, $draft->id],
            );

            // The checklist as it now reads: a decision just stored shows as one.
            $after = $this->mapper->map($report, $type, self::slotMap($config));

            return new DraftChecklist($after, $draft->revision + 1, $returnPlan && $after->plan['shape'] !== [] ? $after->plan : null);
        });
    }

    /**
     * @return array{0: BlockType, 1: FieldRoleReport}
     *
     * @throws PreviewRefused
     */
    private function inputs(string $workspaceId, Draft $draft): array
    {
        $sample = $this->samples->find($workspaceId, $draft->id);

        if ($sample === null || ! $sample->check->valid || $sample->check->shape === null) {
            throw new PreviewRefused(PreviewRefused::NO_SAMPLE);
        }

        $path = $draft->config['record_path'] ?? null;
        $overrides = is_array($draft->config['field_roles'] ?? null) ? array_filter($draft->config['field_roles'], is_string(...)) : [];
        $type = $this->types->resolve($draft->type, $this->contractVersion($workspaceId, $draft->blockId));

        return [$type, $this->roles->infer($sample->check->shape, is_string($path) ? $path : null, $overrides)];
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
