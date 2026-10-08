<?php

namespace App\Modules\Ingestion\Application;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fired by the `scheduler` every five minutes, queued on `maintenance` so that it runs on `worker-compute`, the only service holding the
 * `maintenance` database credentials (Story 2.16). It spans Workspaces on purpose and enters each one itself, in its own transaction.
 */
final class SweepRawHistoryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 280;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(SweepRawHistory $sweep): void
    {
        $sweep->run();
    }
}
