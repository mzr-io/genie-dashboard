<?php

namespace App\Modules\Blocks\Application;

use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\Drafts;
use App\Modules\Blocks\Contracts\ValidationReport;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeRegistry;
use App\Modules\BlockTypes\Contracts\KpiCardSlots;
use App\Modules\BlockTypes\Contracts\UnknownBlockType;
use App\Modules\Connector\Contracts\ConnectionTestCode;
use App\Modules\Connector\Contracts\EndpointValidations;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\QueryPlans;
use App\Modules\Mapping\Contracts\SettingNotConfigured;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\JsonDepthExceeded;
use App\Platform\Json\JsonParseFailed;
use App\Platform\Json\LosslessJson;
use App\Platform\Json\SamplePath;
use App\Platform\Operations\Operation;
use App\Platform\Operations\OperationHandler;
use App\Platform\Operations\OperationOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The `publish_validation` Operation (Story 3.18), run by `worker-connector`. It judges a Draft against the live API and stores one
 * ValidationReport bound to the Operation's `subject_revision`:
 *
 * 1. a Draft whose revision already moved is `stale`, nothing else is done;
 * 2. strict-schema errors (a required slot unmapped, an invalid presentation, a plan failing its type check) are `failed` with the list, before
 *    any request is made;
 * 3. the Endpoint is fetched through the Connector ({@see EndpointValidations}: the one fetcher), the live body is decoded losslessly and the
 *    Draft's own plan is run over it by the Query Plan engine; each mapped slot that finds no single value of its type is a mismatch (a
 *    path and a type, never a value) and the report is `failed`, else `passed` and audited as "validated";
 * 4. a fetch that fails is `unreachable` (Publish blocked), an Endpoint that moved on or a Draft whose revision moved while the run was in
 *    flight is `stale`.
 *
 * The body is only ever the Operation's encrypted, short-lived blob: it is dropped at once and nothing of it is stored, logged or audited.
 * A Draft that is gone or published ends the Operation without a report.
 */
final class RunDraftValidation implements OperationHandler
{
    public function __construct(
        private readonly Drafts $drafts,
        private readonly EndpointValidations $validations,
        private readonly BlockTypeRegistry $types,
        private readonly QueryPlans $plans,
        private readonly Audit $audit,
    ) {}

    public function handle(Operation $operation, array $input): OperationOutcome
    {
        $draftId = $operation->subjectId;
        $subject = $operation->subjectRevision;

        if ($draftId === null || $subject === null) {
            return $this->ended($operation, 'invalid_input');
        }

        try {
            $draft = $this->drafts->find($operation->workspaceId, $draftId);
            $block = DB::selectOne('select contract_version from blocks where workspace_id = ? and id = ?', [$operation->workspaceId, $draft->blockId]);
            $type = $block === null ? null : $this->types->resolve($draft->type, (int) $block->contract_version);
        } catch (DraftNotFound|UnknownBlockType) {
            // Gone, discarded or published (not a Draft), or a Block Type the registry no longer has: no report. Anything else fails the Operation visibly.
            return $this->ended($operation, 'draft_gone');
        }

        if ($type === null) {
            return $this->ended($operation, 'draft_gone');
        }

        // The Draft moved on before the worker got to the job: the report says so and nothing is fetched.
        if ($draft->revision !== $subject) {
            return $this->store($operation, $draft->blockId, ValidationReport::STALE, 'revision_moved', [], null, null);
        }

        $faults = DraftStrictCheck::check($draft->config, $type);

        if ($faults !== []) {
            return $this->store($operation, $draft->blockId, ValidationReport::FAILED, 'strict_schema', $faults, null, null);
        }

        $dataSourceId = is_string($input['data_source_id'] ?? null) ? $input['data_source_id'] : null;
        $endpointId = is_string($input['endpoint_id'] ?? null) ? $input['endpoint_id'] : null;
        $endpointRevision = is_int($input['endpoint_revision'] ?? null) ? $input['endpoint_revision'] : null;

        if ($dataSourceId === null || $endpointId === null || $endpointRevision === null) {
            return $this->store($operation, $draft->blockId, ValidationReport::FAILED, 'invalid_input', [], null, null);
        }

        $values = [];

        foreach (is_array($input['values'] ?? null) ? $input['values'] : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $values[$name] = $value;
            }
        }

        // The comparison request of a prior-period comparison (Story 5.6b): the same Endpoint with the prior window's values.
        $comparisonValues = null;

        if (is_array($input['comparison_values'] ?? null)) {
            $comparisonValues = [];

            foreach ($input['comparison_values'] as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $comparisonValues[$name] = $value;
                }
            }
        }

        try {
            return $this->validate($operation, $draft->blockId, $draft->config, $type, $dataSourceId, $endpointId, $endpointRevision, $values, ($input['user_bound'] ?? false) === true, $comparisonValues);
        } finally {
            $this->validations->discard($operation);
        }
    }

    /** Whatever the outcome, the Operation's blob is dropped (idempotent). */
    public function cleanup(Operation $operation): void
    {
        $this->validations->discard($operation);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $values
     * @param  array<string, string>|null  $comparisonValues  the prior window's values of a prior-period comparison
     */
    private function validate(Operation $operation, string $blockId, array $config, BlockType $type, string $dataSourceId, string $endpointId, int $endpointRevision, array $values, bool $userBound, ?array $comparisonValues = null): OperationOutcome
    {
        try {
            $guards = $this->plans->guards();
        } catch (SettingNotConfigured) {
            return $this->store($operation, $blockId, ValidationReport::FAILED, 'setting_not_configured', [], $endpointId, $endpointRevision);
        }

        $run = $this->validations->run($operation, $dataSourceId, $endpointId, $endpointRevision, $values, $userBound);
        $outcome = $run->outcome;

        if ($outcome->stale) {
            return $this->store($operation, $blockId, ValidationReport::STALE, 'endpoint_changed', [], $endpointId, $endpointRevision);
        }

        // An Endpoint bound to a member that could be fetched without the member's attribute could be published unbound: it fails.
        if ($run->unboundRefused === false) {
            return $this->store($operation, $blockId, ValidationReport::FAILED, 'unbound_publishable', [], $endpointId, $endpointRevision);
        }

        if (! $outcome->succeeded) {
            $code = is_string($outcome->summary['code'] ?? null) ? $outcome->summary['code'] : 'fetch-failed';

            // The member's own values could not be produced for the live check: the shape is not proved, and it is not the API's fault.
            if ($code === ConnectionTestCode::ContextMissing->value) {
                return $this->store($operation, $blockId, ValidationReport::FAILED, 'context_missing', [], $endpointId, $endpointRevision);
            }

            return $this->store($operation, $blockId, ValidationReport::UNREACHABLE, $code, [], $endpointId, $endpointRevision);
        }

        $body = $this->validations->take($operation, $endpointId, $endpointRevision);

        if ($body === null) {
            return $this->store($operation, $blockId, ValidationReport::UNREACHABLE, 'blob_unavailable', [], $endpointId, $endpointRevision);
        }

        try {
            $document = LosslessJson::decode($body, LosslessJson::configuredDepthLimit());
        } catch (JsonDepthExceeded|JsonParseFailed|InvalidLimitSetting) {
            return $this->store($operation, $blockId, ValidationReport::UNREACHABLE, 'not-json', [], $endpointId, $endpointRevision);
        } finally {
            unset($body);
        }

        $plan = is_array($config['query_plan'] ?? null) ? $config['query_plan'] : [];

        try {
            $result = $this->plans->run($type, $plan, $document, $guards);
        } catch (GuardExceeded) {
            return $this->store($operation, $blockId, ValidationReport::FAILED, 'guard_exceeded', [], $endpointId, $endpointRevision);
        } catch (InvalidPlan) {
            return $this->store($operation, $blockId, ValidationReport::FAILED, 'strict_schema', [['path' => 'config.query_plan', 'slot' => null, 'type' => 'invalid_plan', 'expected' => null]], $endpointId, $endpointRevision);
        } catch (\Throwable $e) {
            Log::error('blocks.publish_validation.engine_error', ['workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'exception' => $e::class]);

            return $this->store($operation, $blockId, ValidationReport::FAILED, 'engine_error', [], $endpointId, $endpointRevision);
        }

        $expected = [];

        foreach ($type->slots as $slot) {
            $expected[$slot->key] = $slot->valueType;
        }

        $fields = is_array($plan['extract']['fields'] ?? null) ? $plan['extract']['fields'] : [];
        $mismatches = [];

        // The records the Draft reads must be in the live response: an absent record path or no records at all is a mismatch, whatever the slots say.
        $recordPath = is_string($config['record_path'] ?? null) ? $config['record_path'] : null;

        if ($recordPath !== null) {
            try {
                $records = SamplePath::select($document, $recordPath);
            } catch (\Throwable) {
                $records = [];
            }

            if ($records === []) {
                $mismatches[] = ['path' => mb_substr($recordPath, 0, 200), 'slot' => null, 'type' => 'missing', 'expected' => null];
            }
        }

        foreach (is_array($plan['shape'] ?? null) ? $plan['shape'] : [] as $slot => $alias) {
            $reason = $result->unavailable[(string) $slot] ?? null;

            if ($reason !== null) {
                $path = $fields[$alias] ?? null;
                $mismatches[] = ['path' => is_string($path) ? mb_substr($path, 0, 200) : null, 'slot' => (string) $slot, 'type' => $reason, 'expected' => $expected[(string) $slot] ?? null];
            }
        }

        // The comparison request is checked with the first, once the primary is sound: it must be reachable and the headline must resolve in it.
        if ($mismatches === [] && $comparisonValues !== null) {
            $checked = $this->comparison($operation, $blockId, $type, $plan, $guards, $dataSourceId, $endpointId, $endpointRevision, $comparisonValues, $userBound, $mismatches);

            if ($checked !== null) {
                return $checked;
            }
        }

        return $this->store($operation, $blockId, $mismatches === [] ? ValidationReport::PASSED : ValidationReport::FAILED, $mismatches === [] ? null : 'shape_mismatch', $mismatches, $endpointId, $endpointRevision);
    }

    /**
     * Fetches the prior window and runs the Draft's plan over it. Returns the outcome when the report is decided here (the request failed), or null
     * with any mismatch of the headline added to `$mismatches`.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, string>  $values
     * @param  list<array{path: string|null, slot: string|null, type: string, expected: string|null}>  $mismatches
     */
    private function comparison(Operation $operation, string $blockId, BlockType $type, array $plan, PlanGuards $guards, string $dataSourceId, string $endpointId, int $endpointRevision, array $values, bool $userBound, array &$mismatches): ?OperationOutcome
    {
        $run = $this->validations->run($operation, $dataSourceId, $endpointId, $endpointRevision, $values, $userBound);
        $outcome = $run->outcome;

        if ($outcome->stale) {
            return $this->store($operation, $blockId, ValidationReport::STALE, 'endpoint_changed', [], $endpointId, $endpointRevision);
        }

        if (! $outcome->succeeded) {
            $code = is_string($outcome->summary['code'] ?? null) ? $outcome->summary['code'] : 'fetch-failed';

            return $code === ConnectionTestCode::ContextMissing->value
                ? $this->store($operation, $blockId, ValidationReport::FAILED, 'context_missing', [], $endpointId, $endpointRevision)
                : $this->store($operation, $blockId, ValidationReport::UNREACHABLE, 'comparison_'.$code, [], $endpointId, $endpointRevision);
        }

        $body = $this->validations->take($operation, $endpointId, $endpointRevision);

        if ($body === null) {
            return $this->store($operation, $blockId, ValidationReport::UNREACHABLE, 'comparison_blob_unavailable', [], $endpointId, $endpointRevision);
        }

        try {
            $document = LosslessJson::decode($body, LosslessJson::configuredDepthLimit());
        } catch (JsonDepthExceeded|JsonParseFailed|InvalidLimitSetting) {
            return $this->store($operation, $blockId, ValidationReport::UNREACHABLE, 'comparison_not-json', [], $endpointId, $endpointRevision);
        } finally {
            unset($body);
        }

        try {
            $result = $this->plans->run($type, $plan, $document, $guards);
        } catch (GuardExceeded) {
            return $this->store($operation, $blockId, ValidationReport::FAILED, 'guard_exceeded', [], $endpointId, $endpointRevision);
        } catch (InvalidPlan) {
            return $this->store($operation, $blockId, ValidationReport::FAILED, 'strict_schema', [['path' => 'config.query_plan', 'slot' => null, 'type' => 'invalid_plan', 'expected' => null]], $endpointId, $endpointRevision);
        }

        $reason = $result->unavailable[KpiCardSlots::SLOT_HEADLINE_VALUE] ?? null;

        if ($reason !== null) {
            $fields = is_array($plan['extract']['fields'] ?? null) ? $plan['extract']['fields'] : [];
            $alias = $plan['shape'][KpiCardSlots::SLOT_HEADLINE_VALUE] ?? null;
            $path = is_string($alias) ? ($fields[$alias] ?? null) : null;
            $mismatches[] = ['path' => is_string($path) ? mb_substr($path, 0, 200) : null, 'slot' => KpiCardSlots::SLOT_COMPARISON, 'type' => $reason, 'expected' => 'number'];
        }

        return null;
    }

    /**
     * Writes the report. The Draft's revision is read again under a lock first: a result for a revision that is no longer the Draft's is `stale`,
     * whatever the run found, and only a report that is still current can be `passed`.
     *
     * @param  list<array{path: string|null, slot: string|null, type: string, expected: string|null}>  $mismatches
     */
    private function store(Operation $operation, string $blockId, string $status, ?string $reason, array $mismatches, ?string $endpointId, ?int $endpointRevision): OperationOutcome
    {
        $draftId = (string) $operation->subjectId;
        $reason = $reason === null ? null : mb_substr($reason, 0, 64);

        // A run that is repeated (a retried job) returns the report its Operation already stored.
        $existing = $this->existing($operation);

        if ($existing !== null) {
            return $this->fromRow($operation, $existing);
        }

        // The runner's own transaction is a savepoint here: the row lock, the insert and the audit event stand or fall together.
        return DB::transaction(function () use ($operation, $blockId, $status, $reason, $mismatches, $endpointId, $endpointRevision, $draftId): OperationOutcome {
            $row = DB::selectOne('select revision, state from block_versions where workspace_id = ? and id = ? for update', [$operation->workspaceId, $draftId]);

            if ($row === null || $row->state !== 'draft') {
                return $this->ended($operation, 'draft_gone');
            }

            if ((int) $row->revision !== $operation->subjectRevision && $status !== ValidationReport::STALE) {
                $status = ValidationReport::STALE;
                $reason = 'revision_moved';
                $mismatches = [];
            }

            $id = (string) Str::uuid7();
            $inserted = DB::affectingStatement(
                'insert into validation_reports (id, workspace_id, block_version_id, operation_id, subject_revision, status, reason, endpoint_id, endpoint_revision, mismatches, created_by_membership_id, created_at) '
                .'values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, now()) on conflict (operation_id) do nothing',
                [
                    $id, $operation->workspaceId, $draftId, $operation->id, $operation->subjectRevision, $status, $reason,
                    $endpointId === null ? null : strtolower($endpointId), $endpointRevision, json_encode($mismatches, JSON_THROW_ON_ERROR), $operation->requesterMembershipId,
                ],
            );

            if ($inserted === 0) {
                // Another run of this Operation stored it first: its report stands.
                $existing = $this->existing($operation);

                return $existing === null ? $this->ended($operation, 'store_failed') : $this->fromRow($operation, $existing);
            }

            if ($status === ValidationReport::PASSED) {
                $this->audit->record(
                    AuditAction::BlocksDraftValidated,
                    ['draft_id' => $draftId, 'block_id' => $blockId, 'revision' => (int) $operation->subjectRevision, 'report_id' => $id],
                    subject: 'block_draft:'.$draftId,
                    actor: $operation->requesterMembershipId,
                );
            }

            return $this->outcomeOf($operation, $status, $reason, $id, count($mismatches));
        });
    }

    private function existing(Operation $operation): ?\stdClass
    {
        /** @var \stdClass|null $row */
        $row = DB::selectOne(
            'select id, status, reason, mismatches::text as mismatches from validation_reports where workspace_id = ? and operation_id = ?',
            [$operation->workspaceId, $operation->id],
        );

        return $row;
    }

    private function fromRow(Operation $operation, \stdClass $row): OperationOutcome
    {
        try {
            $list = LosslessJson::decode((string) $row->mismatches);
        } catch (JsonDepthExceeded|JsonParseFailed) {
            $list = [];
        }

        return $this->outcomeOf($operation, (string) $row->status, $row->reason === null ? null : (string) $row->reason, (string) $row->id, is_array($list) ? count($list) : 0);
    }

    private function outcomeOf(Operation $operation, string $status, ?string $reason, string $id, int $mismatches): OperationOutcome
    {
        $summary = ['ok' => $status === ValidationReport::PASSED, 'status' => $status, 'reason' => $reason, 'report_id' => $id, 'mismatches' => $mismatches, 'draft_revision' => $operation->subjectRevision];

        return $status === ValidationReport::STALE ? OperationOutcome::stale($summary) : new OperationOutcome($status === ValidationReport::PASSED, $summary);
    }

    private function ended(Operation $operation, string $reason): OperationOutcome
    {
        Log::warning('blocks.publish_validation.not_stored', ['workspace_id' => $operation->workspaceId, 'operation_id' => $operation->id, 'reason' => $reason]);

        return new OperationOutcome(false, ['ok' => false, 'status' => null, 'reason' => $reason, 'report_id' => null, 'mismatches' => 0, 'draft_revision' => $operation->subjectRevision]);
    }
}
