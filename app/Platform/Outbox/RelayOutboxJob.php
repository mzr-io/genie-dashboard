<?php

namespace App\Platform\Outbox;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled every minute on queue `outbox` (worker-compute, the only service holding the `system`
 * credentials). It spans Workspaces on purpose: each event is delivered inside its own Workspace context.
 * Unique, so runs never overlap; it stops within its timeout and leaves the rest to the next run.
 */
final class RelayOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const BATCH = 100;

    public const MAX_BATCHES = 10;

    public int $timeout = 55;

    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onQueue('outbox');
    }

    public function handle(OutboxRelay $relay): void
    {
        for ($i = 0; $i < self::MAX_BATCHES; $i++) {
            if ($relay->relay(self::BATCH) < self::BATCH) {
                return;
            }
        }
    }
}
