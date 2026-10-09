<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Connector\Contracts\RunCounts;
use App\Modules\Connector\Contracts\SourceGovernor;
use App\Modules\Connector\Contracts\SyncRunHistory;
use App\Modules\Ingestion\Contracts\SourceHealth;
use App\Modules\Ingestion\Infrastructure\HealthSettings;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Support\Observability\MetricEmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Computes a Data Source's health status (Story 2.18) and records a change, in the caller's Workspace transaction.
 *
 * {@see self::decide()} is the decision and does no I/O. Rules, the first match wins:
 *  1. no probe result and no final run yet: `checking` (never Healthy);
 *  2. the breaker is `open` or `half_open`: `unreachable`;
 *  3. the trailing failed runs since the last succeeded one reach `threshold_unreachable`: `unreachable`;
 *  4. runs inside `health.window`: the whole percent of succeeded among succeeded and failed is at least `threshold_healthy`: `healthy`, at least
 *     `threshold_degraded`: `degraded`, else `unreachable` (an empty window skips the rule);
 *  5. a latest final run that failed (runs outrank a stale probe) is `degraded`; else the latest probe: ok is `healthy`, failed is `unreachable`.
 * Only final `scheduled_fetch` runs count (a 304 is a success). A rule whose settings are unset or malformed is skipped, so with none set the
 * probe and the breaker decide. Nothing matching is `checking` ({@see self::evaluate()} keeps a status the source already had).
 *
 * {@see self::evaluate()} reads the evidence under a lock on the health row (two evaluators take turns and both compute the same result), and
 * changes `status`, `status_since` and `status_seq` in one guarded UPDATE. A change emits the outbox event `ingestion.source_health.changed`
 * (`{data_source_id, from, to}`) and writes the same-named audit event (actor `system`) in the same transaction, except a change to `checking` and
 * `checking` to `healthy`. A Data Source with no health row is not evaluated (its row is made by the save).
 */
final class EvaluateSourceHealth
{
    public function __construct(
        private readonly SyncRunHistory $history,
        private readonly SourceGovernor $governor,
        private readonly HealthSettings $health,
        private readonly SyncSettings $sync,
        private readonly Outbox $outbox,
        private readonly Audit $audit,
        private readonly MetricEmitter $metrics,
    ) {}

    public static function decide(HealthEvidence $evidence, HealthRules $rules): string
    {
        if (! $evidence->probed && ! $evidence->hasFinalRun) {
            return SourceHealth::CHECKING;
        }

        if ($evidence->breaker !== SourceGovernor::STATE_CLOSED) {
            return SourceHealth::UNREACHABLE;
        }

        if ($rules->unreachableAfter !== null && $evidence->trailingFailures >= $rules->unreachableAfter) {
            return SourceHealth::UNREACHABLE;
        }

        $total = $evidence->succeeded + $evidence->failed;

        if ($rules->percentRuleActive() && $total > 0) {
            $percent = intdiv($evidence->succeeded * 100, $total);

            return match (true) {
                $percent >= (int) $rules->healthyPercent => SourceHealth::HEALTHY,
                $percent >= (int) $rules->degradedPercent => SourceHealth::DEGRADED,
                default => SourceHealth::UNREACHABLE,
            };
        }

        // Once runs exist they outrank a stale probe: with no percentage rule to judge them, a latest final run that failed is Degraded (no number invented).
        if ($evidence->hasFinalRun && $evidence->trailingFailures > 0) {
            return SourceHealth::DEGRADED;
        }

        if ($evidence->probed) {
            return $evidence->probeOk ? SourceHealth::HEALTHY : SourceHealth::UNREACHABLE;
        }

        return SourceHealth::CHECKING;
    }

    /**
     * Evaluates and records, never throwing: a failing evaluation must not undo the fetch or the probe that asked for it. It runs in its own
     * savepoint and logs the exception class only.
     *
     * @return string|null the new status when it changed
     */
    public function evaluate(string $workspaceId, string $dataSourceId): ?string
    {
        try {
            return DB::transaction(fn (): ?string => $this->apply($workspaceId, strtolower($dataSourceId)));
        } catch (Throwable $e) {
            Log::error('ingestion.health.evaluation_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);

            return null;
        }
    }

    private function apply(string $workspaceId, string $dataSourceId): ?string
    {
        // The row lock makes two evaluators of one source take turns: the second reads what the first committed.
        $row = DB::selectOne(
            'select id, status, last_probe_at, last_probe_ok from data_source_health where workspace_id = ? and data_source_id = ? for update',
            [$workspaceId, $dataSourceId],
        );

        if ($row === null) {
            return null;
        }

        $rules = $this->health->rules();
        $breaker = $this->governor->state($workspaceId, $dataSourceId, $this->sync->governorLimits());
        $counts = $rules->percentRuleActive() ? $this->history->counts($workspaceId, $dataSourceId, (int) $rules->windowSeconds) : new RunCounts;
        $trailing = $this->history->trailingFailures($workspaceId, $dataSourceId);

        $status = self::decide(new HealthEvidence(
            $row->last_probe_at !== null,
            filter_var($row->last_probe_ok, FILTER_VALIDATE_BOOLEAN),
            $this->history->hasFinalRun($workspaceId, $dataSourceId),
            $breaker,
            $trailing,
            $counts->succeeded,
            $counts->failed,
        ), $rules);

        $from = (string) $row->status;

        // `checking` is only where a Data Source starts: once it has had a status, evidence that decides nothing leaves that status as it is.
        if ($status === SourceHealth::CHECKING) {
            $status = $from;
        }

        $healthId = strtolower((string) $row->id);

        // The guard: only a real change moves the row, so a racing evaluator that reached the same result changes nothing and emits nothing.
        $moved = DB::selectOne(
            'update data_source_health set status = ?, status_since = clock_timestamp(), status_seq = status_seq + 1, updated_at = clock_timestamp() '
            .'where workspace_id = ? and id = ? and status is distinct from ? returning id',
            [$status, $workspaceId, $healthId, $status],
        );

        if ($moved === null) {
            return null;
        }

        if ($status !== SourceHealth::CHECKING && ! ($from === SourceHealth::CHECKING && $status === SourceHealth::HEALTHY)) {
            $data = ['data_source_id' => $dataSourceId, 'from' => $from, 'to' => $status];
            $subject = 'data_source_health:'.$healthId;

            $this->outbox->emit(AuditAction::IngestionSourceHealthChanged, $subject, $data, actor: 'system');
            $this->audit->record(AuditAction::IngestionSourceHealthChanged, $data, subject: $subject, actor: 'system');
            $this->metrics->increment('dashflow.ingestion.health_changed', ['workspace_id' => $workspaceId]);
        }

        return $status;
    }
}
