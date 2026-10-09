<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\SourceGovernor;
use App\Modules\Connector\Contracts\SourceProbe;
use App\Modules\Ingestion\Infrastructure\HealthSettings;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs one probe of a Data Source and keeps its result (Story 2.18), inside the Workspace the {@see ProbeDataSourceJob} re-entered. An open or
 * half-open breaker is honoured: no call is made and the previous result stays. A periodic probe of a source that has current sync targets makes no
 * call either. Otherwise Connector's {@see SourceProbe} makes the one call (it writes the `health_probe` run); this stores the latest result
 * (`last_probe_at`, `last_probe_ok` and, when ok, `last_probe_success_at`), plans the next periodic probe when `health.probe_interval` is valid,
 * and evaluates the status.
 */
final class RecordProbeResult
{
    public function __construct(
        private readonly SourceProbe $probe,
        private readonly SourceGovernor $governor,
        private readonly SyncSettings $sync,
        private readonly HealthSettings $health,
        private readonly EvaluateSourceHealth $evaluator,
    ) {}

    public function run(string $workspaceId, string $dataSourceId, bool $periodic = false): void
    {
        $dataSourceId = strtolower($dataSourceId);

        // Fetches already prove a source that has current targets. With the demand rule on (Story 2.19) only a hot target is fetched, so only a
        // hot one that has a schedule counts (a user-scoped target is never fetched on a schedule): a source nobody watches is kept in view by this probe alone.
        $hot = $this->sync->hotWindowSeconds() !== null ? ' and exists (select 1 from sync_subscriptions s where s.sync_target_id = sync_targets.id and s.hot_until > now()) and next_due_at is not null' : '';

        if ($periodic && DB::selectOne('select 1 as found from sync_targets where workspace_id = ? and data_source_id = ? and retired_at is null'.$hot.' limit 1', [$workspaceId, $dataSourceId]) !== null) {
            return;
        }

        if ($this->governor->state($workspaceId, $dataSourceId, $this->sync->governorLimits()) !== SourceGovernor::STATE_CLOSED) {
            // No call: the previous result stays, but the periodic tick must still come back for this source.
            $interval = $this->health->probeInterval();

            if ($interval !== null) {
                DB::update('update data_source_health set next_probe_at = clock_timestamp() + make_interval(secs => ?::integer) where workspace_id = ? and data_source_id = ?', [$interval, $workspaceId, $dataSourceId]);
            }

            return;
        }

        try {
            $result = $this->probe->probe($workspaceId, $dataSourceId);
        } catch (DataSourceNotFound) {
            return;
        }

        // The row normally exists (the save made it); a probe that outran the consumer's commit makes it itself.
        DB::insert(
            "insert into data_source_health (id, workspace_id, data_source_id, status, status_since, status_seq, created_at, updated_at) values (?, ?, ?, 'checking', now(), 0, now(), now()) on conflict (workspace_id, data_source_id) do nothing",
            [(string) Str::uuid7(), $workspaceId, $dataSourceId],
        );

        $interval = $this->health->probeInterval();

        DB::update(
            'update data_source_health set last_probe_at = clock_timestamp(), last_probe_ok = ?::boolean, '
            .'last_probe_success_at = case when ?::boolean then clock_timestamp() else last_probe_success_at end, '
            .'next_probe_at = case when ?::integer is null then null else clock_timestamp() + make_interval(secs => ?::integer) end, updated_at = clock_timestamp() '
            .'where workspace_id = ? and data_source_id = ?',
            [$result->ok ? 'true' : 'false', $result->ok ? 'true' : 'false', $interval, $interval, $workspaceId, $dataSourceId],
        );

        $this->evaluator->evaluate($workspaceId, $dataSourceId);
    }
}
