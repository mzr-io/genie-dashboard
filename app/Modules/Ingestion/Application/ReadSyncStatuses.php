<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Ingestion\Contracts\SyncStatus;
use App\Modules\Ingestion\Contracts\SyncStatuses;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where each Endpoint's scheduled fetch stands (Story 2.14), read from its current (unretired) sync target in the caller's Workspace
 * transaction: the time of the last good response, or no successful call yet, or never scheduled because no refresh interval is set. An
 * Endpoint with no current target is waiting: its target is registered when the outbox delivers the save.
 */
final class ReadSyncStatuses implements SyncStatuses
{
    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    public function forEndpoints(string $workspaceId, array $endpointIds): array
    {
        return $this->transactions->run($workspaceId, fn (): array => $this->read($workspaceId, $endpointIds));
    }

    /**
     * @param  list<string>  $endpointIds
     * @return array<string, SyncStatus>
     */
    private function read(string $workspaceId, array $endpointIds): array
    {
        $ids = array_values(array_unique(array_map('strtolower', array_filter($endpointIds, fn (string $id): bool => Str::isUuid($id)))));
        $statuses = [];

        foreach ($ids as $id) {
            $statuses[$id] = new SyncStatus(SyncStatus::WAITING);
        }

        if ($ids === []) {
            return $statuses;
        }

        $rows = DB::select(
            "select endpoint_id, refresh_interval_seconds, next_due_at, to_char(last_success_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') as last_success, "
            ."to_char(last_checked_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') as last_checked, "
            ."to_char(payload_changed_at at time zone 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') as payload_changed "
            .'from sync_targets where workspace_id = ? and retired_at is null and endpoint_id in ('.implode(', ', array_fill(0, count($ids), '?')).')',
            [$workspaceId, ...$ids],
        );

        foreach ($rows as $row) {
            $id = strtolower((string) $row->endpoint_id);
            $checked = $row->last_checked === null ? null : (string) $row->last_checked;
            $changed = $row->payload_changed === null ? null : (string) $row->payload_changed;

            $statuses[$id] = match (true) {
                $row->last_success !== null => new SyncStatus(SyncStatus::SUCCEEDED, (string) $row->last_success, null, $checked, $changed),
                $row->refresh_interval_seconds === null || $row->next_due_at === null => new SyncStatus(SyncStatus::NOT_SCHEDULED, null, SyncStatus::NO_INTERVAL, $checked, $changed),
                default => new SyncStatus(SyncStatus::WAITING, null, null, $checked, $changed),
            };
        }

        return $statuses;
    }
}
