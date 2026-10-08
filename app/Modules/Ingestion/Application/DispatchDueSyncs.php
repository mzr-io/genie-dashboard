<?php

namespace App\Modules\Ingestion\Application;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The dispatcher (Story 2.14), run by {@see DispatchDueSyncsJob} on `worker-compute`, the only holder of the `system` database role. In
 * one transaction on the `system` connection it takes a fixed batch of due, unretired targets `FOR UPDATE SKIP LOCKED` (two dispatchers never
 * take the same row), raises each target's `dispatch_seq` by one and moves `next_due_at` forward by its refresh interval; after the commit it
 * queues one {@see FetchJob(workspace_id, sync_group_id, dispatch_seq)} per target on `fetch-scheduled`. Nothing is queued for a rolled-back
 * dispatch, and a job that is lost is simply dispatched again at the next `next_due_at`. The role sees only the dispatch columns and can
 * change only those two; it never bypasses row-level security.
 */
final class DispatchDueSyncs
{
    public const CONNECTION = 'system';

    /** How many targets one tick may dispatch: a backlog drains over the next ticks. */
    public const BATCH = 200;

    public function __construct(private readonly ConnectionResolverInterface $db) {}

    /** @return int how many fetch jobs were queued */
    public function run(): int
    {
        /** @var Connection $system */
        $system = $this->db->connection(self::CONNECTION);

        $due = $system->transaction(function () use ($system): array {
            $rows = $system->select(
                'select id, workspace_id, sync_group_id, refresh_interval_seconds from sync_targets '
                .'where retired_at is null and next_due_at is not null and next_due_at <= now() '
                .'order by next_due_at, id limit '.self::BATCH.' for update skip locked',
            );

            $jobs = [];

            foreach ($rows as $row) {
                $moved = $system->selectOne(
                    'update sync_targets set dispatch_seq = dispatch_seq + 1, next_due_at = now() + make_interval(secs => refresh_interval_seconds) where id = ? returning dispatch_seq',
                    [$row->id],
                );

                if ($moved !== null) {
                    $jobs[] = [(string) $row->workspace_id, (string) $row->sync_group_id, (int) $moved->dispatch_seq];
                }
            }

            return $jobs;
        });

        $queued = 0;

        foreach ($due as [$workspaceId, $syncGroupId, $dispatchSeq]) {
            try {
                FetchJob::dispatch($workspaceId, $syncGroupId, $dispatchSeq);
                $queued++;
            } catch (Throwable $e) {
                // A job that could not be queued is a lost job: the target is dispatched again at its next due time.
                Log::error('ingestion.dispatch.enqueue_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
            }
        }

        return $queued;
    }
}
