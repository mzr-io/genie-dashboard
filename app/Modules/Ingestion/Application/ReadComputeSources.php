<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Ingestion\Contracts\ComputeSources;
use App\Modules\Ingestion\Contracts\ComputeSubscription;
use App\Modules\Ingestion\Contracts\ComputeTarget;
use App\Modules\Ingestion\Contracts\WatchedTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** {@see ComputeSources}: reads `sync_targets` and `sync_subscriptions` under the caller's Workspace transaction. */
final class ReadComputeSources implements ComputeSources
{
    private const COLUMNS = 't.id, t.fetch_key, t.endpoint_id, t.current_payload_id, t.payload_seq, t.last_success_at, t.payload_changed_at';

    public function target(string $workspaceId, string $syncTargetId): ?ComputeTarget
    {
        if (! Str::isUuid($syncTargetId)) {
            return null;
        }

        /** @var \stdClass|null $row */
        $row = DB::selectOne(
            'select '.self::COLUMNS.' from sync_targets t where t.workspace_id = ? and t.id = ? and t.retired_at is null and t.current_payload_id is not null',
            [$workspaceId, strtolower($syncTargetId)],
        );

        return $row === null ? null : $this->targetOf($row);
    }

    public function watching(string $workspaceId, string $blockVersionId, string $computeContext): ?WatchedTarget
    {
        if (! Str::isUuid($blockVersionId)) {
            return null;
        }

        /** @var \stdClass|null $row */
        $row = DB::selectOne(
            'select '.self::COLUMNS.', s.block_version_id, s.role, s.compute_context, s.period_start, s.period_end, s.refresh_interval_seconds, s.hot_until '
            .'from sync_subscriptions s join sync_targets t on t.workspace_id = s.workspace_id and t.id = s.sync_target_id '
            ."where s.workspace_id = ? and s.block_version_id = ? and s.role = 'primary' and s.compute_context = ? and t.retired_at is null and t.current_payload_id is not null "
            .'order by s.last_access_at desc limit 1',
            [$workspaceId, strtolower($blockVersionId), $computeContext],
        );

        return $row === null ? null : new WatchedTarget($this->targetOf($row), $this->subscriptionOf($row));
    }

    public function subscriptions(string $workspaceId, string $syncTargetId): array
    {
        if (! Str::isUuid($syncTargetId)) {
            return [];
        }

        $rows = DB::select(
            "select block_version_id, role, compute_context, period_start, period_end, refresh_interval_seconds, hot_until from sync_subscriptions where workspace_id = ? and sync_target_id = ? and role = 'primary' order by block_version_id, compute_context",
            [$workspaceId, strtolower($syncTargetId)],
        );

        return array_values(array_map($this->subscriptionOf(...), $rows));
    }

    private function subscriptionOf(\stdClass $r): ComputeSubscription
    {
        return new ComputeSubscription(
            strtolower((string) $r->block_version_id), (string) $r->role, (string) $r->compute_context,
            $r->period_start === null ? null : substr((string) $r->period_start, 0, 10), $r->period_end === null ? null : substr((string) $r->period_end, 0, 10),
            (int) $r->refresh_interval_seconds, $r->hot_until === null ? null : CarbonImmutable::parse((string) $r->hot_until)->utc()->format('Y-m-d\TH:i:s\Z'),
        );
    }

    public function watched(string $workspaceId, int $limit, ?string $afterId = null): array
    {
        $rows = DB::select(
            'select '.self::COLUMNS.' from sync_targets t where t.workspace_id = ? and t.retired_at is null and t.current_payload_id is not null '
            ."and exists (select 1 from sync_subscriptions s where s.workspace_id = t.workspace_id and s.sync_target_id = t.id and s.role = 'primary') "
            .'and (?::uuid is null or t.id > ?::uuid) order by t.id limit '.max(1, $limit),
            [$workspaceId, $afterId, $afterId],
        );

        return array_values(array_map($this->targetOf(...), $rows));
    }

    private function targetOf(\stdClass $row): ComputeTarget
    {
        $iso = fn (mixed $value): ?string => $value === null ? null : CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d\TH:i:s\Z');

        return new ComputeTarget(
            strtolower((string) $row->id), (string) $row->fetch_key, strtolower((string) $row->endpoint_id), strtolower((string) $row->current_payload_id),
            (int) $row->payload_seq, $iso($row->last_success_at), $iso($row->payload_changed_at),
        );
    }
}
