<?php

namespace App\Modules\Ingestion\Application;

use App\Modules\Ingestion\Infrastructure\HealthSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The periodic probe tick (Story 2.18), fired by the `scheduler` every minute and queued on `maintenance`, so it runs on `worker-compute`, the only
 * holder of the `system` database role. When `health.probe_interval` is a valid positive whole number of seconds it takes a fixed batch of health rows
 * whose `next_probe_at` is due `FOR UPDATE SKIP LOCKED` (two ticks never take the same row), moves each `next_probe_at` forward by the interval and,
 * after the commit, queues one periodic {@see ProbeDataSourceJob} each. A source that has current sync targets is skipped by the job, since its scheduled
 * fetches already prove it. With the setting unset nothing happens: a probe on save only. The role sees only `id`, `workspace_id`, `data_source_id` and
 * `next_probe_at`, can change only the last, and never bypasses row-level security.
 */
final class DispatchDueProbesJob implements ShouldQueue
{
    use Queueable;

    public const CONNECTION = 'system';

    /** How many sources one tick may take: a backlog drains over the next ticks. */
    public const BATCH = 100;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(ConnectionResolverInterface $db, HealthSettings $settings): int
    {
        $interval = $settings->probeInterval();

        if ($interval === null) {
            return 0;
        }

        /** @var Connection $system */
        $system = $db->connection(self::CONNECTION);

        $due = $system->transaction(function () use ($system, $interval): array {
            $rows = $system->select(
                'select id, workspace_id, data_source_id from data_source_health where next_probe_at is not null and next_probe_at <= now() '
                .'order by next_probe_at, id limit '.self::BATCH.' for update skip locked',
            );
            $jobs = [];

            foreach ($rows as $row) {
                $moved = $system->selectOne(
                    'update data_source_health set next_probe_at = now() + make_interval(secs => ?) where id = ? returning id',
                    [$interval, $row->id],
                );

                if ($moved !== null) {
                    $jobs[] = [(string) $row->workspace_id, (string) $row->data_source_id];
                }
            }

            return $jobs;
        });

        $queued = 0;

        foreach ($due as [$workspaceId, $dataSourceId]) {
            try {
                ProbeDataSourceJob::dispatch($workspaceId, $dataSourceId, true);
                $queued++;
            } catch (Throwable $e) {
                // A probe that could not be queued is made again at the next due time.
                Log::error('ingestion.health.probe_enqueue_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
            }
        }

        return $queued;
    }
}
