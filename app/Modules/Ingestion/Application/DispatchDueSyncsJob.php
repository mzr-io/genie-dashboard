<?php

namespace App\Modules\Ingestion\Application;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fired by the `scheduler` every `dispatch_tick`, queued on `maintenance` so that it runs on `worker-compute`, the only service holding the
 * `system` database credentials (like the outbox relay). It spans Workspaces on purpose: the dispatcher sees due targets across all of them
 * and each fetch job it queues re-enters its own Workspace. Not unique: a second tick that overlaps a slow one cannot dispatch twice, because the dispatcher takes rows `FOR UPDATE SKIP LOCKED`.
 */
final class DispatchDueSyncsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(DispatchDueSyncs $dispatcher): void
    {
        $dispatcher->run();
    }
}
