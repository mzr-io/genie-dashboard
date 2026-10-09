<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\RunCounts;
use App\Modules\Connector\Contracts\SyncRunHistory;
use Illuminate\Support\Facades\DB;

/** {@see SyncRunHistory} over `sync_runs` (indexed by Workspace, Data Source and start time). */
final class QuerySyncRunHistory implements SyncRunHistory
{
    public function counts(string $workspaceId, string $dataSourceId, int $windowSeconds): RunCounts
    {
        $row = DB::selectOne(
            "select count(*) filter (where status = 'succeeded') as succeeded, count(*) filter (where status = 'failed') as failed from sync_runs "
            ."where workspace_id = ? and data_source_id = ? and kind = 'scheduled_fetch' and status in ('succeeded', 'failed') and started_at >= now() - make_interval(secs => ?)",
            [$workspaceId, strtolower($dataSourceId), $windowSeconds],
        );

        return new RunCounts((int) ($row->succeeded ?? 0), (int) ($row->failed ?? 0));
    }

    public function trailingFailures(string $workspaceId, string $dataSourceId): int
    {
        $row = DB::selectOne(
            "select count(*) as n from sync_runs where workspace_id = ? and data_source_id = ? and kind = 'scheduled_fetch' and status = 'failed' "
            ."and started_at > coalesce((select max(started_at) from sync_runs where workspace_id = ? and data_source_id = ? and kind = 'scheduled_fetch' and status = 'succeeded'), '-infinity'::timestamptz)",
            [$workspaceId, strtolower($dataSourceId), $workspaceId, strtolower($dataSourceId)],
        );

        return (int) ($row->n ?? 0);
    }

    public function hasFinalRun(string $workspaceId, string $dataSourceId): bool
    {
        return DB::selectOne(
            "select 1 as found from sync_runs where workspace_id = ? and data_source_id = ? and kind = 'scheduled_fetch' and status in ('succeeded', 'failed') limit 1",
            [$workspaceId, strtolower($dataSourceId)],
        ) !== null;
    }
}
