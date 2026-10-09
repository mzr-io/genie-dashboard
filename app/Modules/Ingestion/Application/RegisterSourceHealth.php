<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSources;
use App\Modules\Ingestion\Infrastructure\HealthSettings;
use App\Platform\Outbox\OutboxConsumer;
use App\Platform\Outbox\OutboxEnvelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps the health row of each Data Source (Story 2.18). Connector cannot call Ingestion, so the row is a read model fed by the outbox events
 * `connector.data_source.created` and `connector.data_source.updated` (IDs and the revision only). Each event makes the row exist (status `checking`
 * on create, the status kept on an update) and queues a probe after the transaction commits, so the first result follows the relay's delivery. A
 * Data Source saved before this story has no row until its next save (no backfill).
 */
final class RegisterSourceHealth implements OutboxConsumer
{
    public const NAME = 'ingestion.register_source_health';

    private const TYPES = ['connector.data_source.created', 'connector.data_source.updated'];

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(OutboxEnvelope $event): bool
    {
        return in_array($event->type, self::TYPES, true);
    }

    public function handle(OutboxEnvelope $event): void
    {
        $dataSourceId = $event->data['data_source_id'] ?? null;

        if (! is_string($dataSourceId) || ! Str::isUuid($dataSourceId)) {
            return;
        }

        $dataSourceId = strtolower($dataSourceId);

        try {
            app(DataSources::class)->find($event->workspaceId, $dataSourceId);
        } catch (DataSourceNotFound) {
            return;
        }

        DB::insert(
            "insert into data_source_health (id, workspace_id, data_source_id, status, status_since, status_seq, created_at, updated_at) values (?, ?, ?, 'checking', now(), 0, now(), now()) on conflict (workspace_id, data_source_id) do nothing",
            [(string) Str::uuid7(), $event->workspaceId, $dataSourceId],
        );

        // A row that has no next periodic probe yet gets one, so the tick reaches it even when its first probe never completes.
        $interval = app(HealthSettings::class)->probeInterval();

        if ($interval !== null) {
            DB::update('update data_source_health set next_probe_at = now() + make_interval(secs => ?::integer) where workspace_id = ? and data_source_id = ? and next_probe_at is null', [$interval, $event->workspaceId, $dataSourceId]);
        }

        ProbeDataSourceJob::dispatch($event->workspaceId, $dataSourceId)->afterCommit();
    }
}
