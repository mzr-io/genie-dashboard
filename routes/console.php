<?php

use App\Modules\Ingestion\Application\DispatchDueProbesJob;
use App\Modules\Ingestion\Application\DispatchDueSyncsJob;
use App\Modules\Ingestion\Application\SweepRawHistoryJob;
use App\Modules\Ingestion\Infrastructure\SyncSettings;
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

// The sync dispatcher (Story 2.14) ticks every `dispatch_tick` (the nearest Laravel sub-minute step; unset uses 5 s) on queue `maintenance`,
// so it runs on worker-compute, the only service holding the `system` database credentials. It only selects what is due.
$dispatch = Schedule::job(new DispatchDueSyncsJob, 'maintenance');
match (SyncSettings::tick(config('dashflow.tunables.sync.dispatch_tick.value'))) {
    1 => $dispatch->everySecond(),
    2 => $dispatch->everyTwoSeconds(),
    5 => $dispatch->everyFiveSeconds(),
    10 => $dispatch->everyTenSeconds(),
    15 => $dispatch->everyFifteenSeconds(),
    20 => $dispatch->everyTwentySeconds(),
    30 => $dispatch->everyThirtySeconds(),
    default => $dispatch->everyMinute(),
};
$dispatch->onOneServer();

// The periodic health probe tick (Story 2.18) runs on queue `maintenance`, so it runs on worker-compute, the only service holding the `system`
// database credentials. It does nothing unless `health.probe_interval` is a valid number of seconds.
Schedule::job(new DispatchDueProbesJob, 'maintenance')->everyMinute()->onOneServer();

// The retention sweep (Story 2.16) runs on queue `maintenance`, so it runs on worker-compute, the only service holding the `maintenance` database credentials.
Schedule::job(new SweepRawHistoryJob, 'maintenance')->everyFiveMinutes()->onOneServer();

// Schedule mutexes are locks: with Valkey they live on the noeviction `queue` store, not the LRU cache.
Schedule::useCache(config('cache.schedule_store'));
