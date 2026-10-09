<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Ingestion\Contracts\SourceHealth;
use App\Modules\Ingestion\Contracts\SourceHealths;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where each Data Source's health stands (Story 2.18), read in the caller's Workspace. The status is the health row's (`checking` when there is none).
 * "Last success" is the newest `last_success_at` of the source's current (unretired) sync targets; a source with no current target uses the time of its
 * last ok probe, so a passing Base URL never masks failing data.
 */
final class ReadSourceHealths implements SourceHealths
{
    private const STAMP = "'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'";

    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    public function forDataSources(string $workspaceId, array $dataSourceIds): array
    {
        $ids = array_values(array_unique(array_map('strtolower', array_filter($dataSourceIds, fn (string $id): bool => Str::isUuid($id)))));
        $healths = [];

        foreach ($ids as $id) {
            $healths[$id] = new SourceHealth;
        }

        if ($ids === []) {
            return $healths;
        }

        $rows = $this->transactions->run($workspaceId, fn (): array => DB::select(
            'select i.id as data_source_id, h.status, '
            ."to_char(h.last_probe_success_at at time zone 'UTC', ".self::STAMP.') as probe_success, '
            .'exists (select 1 from sync_targets t where t.workspace_id = ? and t.data_source_id = i.id and t.retired_at is null) as has_targets, '
            ."(select to_char(max(t.last_success_at) at time zone 'UTC', ".self::STAMP.') from sync_targets t where t.workspace_id = ? and t.data_source_id = i.id and t.retired_at is null) as target_success '
            .'from (values '.implode(', ', array_fill(0, count($ids), '(?::uuid)')).') as i(id) '
            .'left join data_source_health h on h.workspace_id = ? and h.data_source_id = i.id',
            [$workspaceId, $workspaceId, ...$ids, $workspaceId],
        ));

        foreach ($rows as $row) {
            $status = in_array($row->status, SourceHealth::STATUSES, true) ? (string) $row->status : SourceHealth::CHECKING;
            $last = filter_var($row->has_targets, FILTER_VALIDATE_BOOLEAN) ? $row->target_success : $row->probe_success;

            $healths[strtolower((string) $row->data_source_id)] = new SourceHealth($status, $last === null ? null : (string) $last);
        }

        return $healths;
    }
}
