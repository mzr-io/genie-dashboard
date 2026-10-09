<?php

namespace App\Modules\Ingestion\Application;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fired by the `scheduler` every minute on `maintenance` (so it runs on `worker-compute`, which holds the `maintenance` credentials it lists
 * Workspaces with, Story 2.20). It enters each Workspace itself, in its own transaction.
 */
final class ReemitPayloadEventsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(ReemitPayloadEvents $reemit): void
    {
        $reemit->run();
    }
}
