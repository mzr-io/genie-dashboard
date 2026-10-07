<?php

use App\Platform\Outbox\RelayOutboxJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every scheduled task uses onOneServer() so several schedulers run each task once.
Schedule::command('dashflow:heartbeat')->everyMinute()->onOneServer();
// Connection tests (Story 2.5): transient secrets that outlived their Operation, and the monthly sync_runs partitions.
Schedule::command('dashflow:secrets:purge-expired')->everyFiveMinutes()->onOneServer();
Schedule::command('dashflow:partitions:ensure')->daily()->onOneServer();
// The outbox relay runs on queue `outbox` (worker-compute holds the `system` database role).
Schedule::job(new RelayOutboxJob, 'outbox')->everyMinute()->onOneServer();

// Schedule mutexes are locks: with Valkey they live on the noeviction `queue` store, not the LRU cache.
Schedule::useCache(config('cache.schedule_store'));
