<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Ingestion\Contracts\SyncGeneration;
use App\Modules\Ingestion\Contracts\SyncGenerations;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** {@see SyncGenerations}: reads `sync_generations` in the caller's Workspace; a group ID that is not a UUID has no generation. */
final class ReadSyncGenerations implements SyncGenerations
{
    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    public function latestComplete(string $workspaceId, string $syncGroupId): ?SyncGeneration
    {
        if (! Str::isUuid($syncGroupId)) {
            return null;
        }

        $row = $this->transactions->run($workspaceId, fn () => DB::selectOne(
            'select id, sync_group_id, primary_target_id, comparison_target_id, dispatch_seq, primary_payload_id, comparison_payload_id, primary_payload_seq, comparison_payload_seq '
            .'from sync_generations where workspace_id = ? and sync_group_id = ? and complete order by dispatch_seq desc limit 1',
            [$workspaceId, strtolower($syncGroupId)],
        ));

        return $row === null ? null : new SyncGeneration(
            strtolower((string) $row->id), strtolower((string) $row->sync_group_id), strtolower((string) $row->primary_target_id), strtolower((string) $row->comparison_target_id),
            (int) $row->dispatch_seq, strtolower((string) $row->primary_payload_id), strtolower((string) $row->comparison_payload_id),
            (int) $row->primary_payload_seq, (int) $row->comparison_payload_seq,
        );
    }

    public function comparisonUnavailable(string $workspaceId, string $syncGroupId): bool
    {
        if (! Str::isUuid($syncGroupId)) {
            return false;
        }

        $row = $this->transactions->run($workspaceId, fn () => DB::selectOne(
            'select comparison_ok from sync_generations where workspace_id = ? and sync_group_id = ? order by dispatch_seq desc limit 1',
            [$workspaceId, strtolower($syncGroupId)],
        ));

        return $row !== null && ! (bool) $row->comparison_ok;
    }
}
