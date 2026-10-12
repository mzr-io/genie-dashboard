<?php

namespace App\Modules\Results\Application;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Fired by the `scheduler` every minute on `maintenance` (so it runs on `worker-compute`, which holds the credentials it lists Workspaces with). */
final class SweepStaleResultsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(SweepStaleResults $sweep): void
    {
        $sweep->run();
    }
}
