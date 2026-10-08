<?php

use App\Modules\Ingestion\Application\DispatchDueSyncsJob;
use App\Modules\Ingestion\Application\SweepRawHistoryJob;
use App\Platform\Outbox\RelayOutboxJob;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

it('registers every scheduled task with onOneServer', function () {
    $events = app(Schedule::class)->events();

    expect($events)->not->toBeEmpty();
    foreach ($events as $event) {
        expect($event->onOneServer)->toBeTrue($event->command ?? $event->description ?? 'task');
    }
});

it('runs a task once when two schedulers fire in the same minute', function () {
    $this->travelTo(now()->startOfMinute()->addSeconds(5));
    $runs = 0;
    // Counts the heartbeat task only: the outbox relay job is scheduled too and needs the database.
    Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $e) use (&$runs) {
        if (str_contains((string) $e->task->command, 'dashflow:heartbeat')) {
            $runs++;
        }
    });

    $schedule = app(Schedule::class);
    // The sync dispatcher repeats within the minute (Story 2.14) and needs the database: leave it out, the heartbeat is what is counted.
    (new ReflectionProperty($schedule, 'events'))->setValue($schedule, array_values(array_filter($schedule->events(), fn ($e) => ! $e->isRepeatable())));
    $firstScheduler = fn () => Artisan::call('schedule:run');
    $secondScheduler = function () use ($schedule) {
        // A second scheduler is another process: it has no in-process mutex memo,
        // only the shared cache lock.
        (new ReflectionProperty($schedule, 'mutexCache'))->setValue($schedule, []);
        Artisan::call('schedule:run');
    };

    $firstScheduler();
    $secondScheduler();

    expect($runs)->toBe(1);
});

it('schedules the outbox relay job on queue outbox with onOneServer', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => ($e->description ?? null) === RelayOutboxJob::class);

    expect($event)->not->toBeNull()->and($event->onOneServer)->toBeTrue();

    Queue::fake();
    $event->run(app());
    Queue::assertPushedOn('outbox', RelayOutboxJob::class);
    expect(new RelayOutboxJob)->toBeInstanceOf(ShouldBeUnique::class)->and((new RelayOutboxJob)->timeout)->toBe(55);
});

// Story 2.14: the dispatcher ticks every `dispatch_tick` on queue `maintenance` (worker-compute holds the `system` credentials), onOneServer.
it('schedules the sync dispatcher job every dispatch tick on queue maintenance with onOneServer', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => ($e->description ?? null) === DispatchDueSyncsJob::class);

    // The configured tick is 5 seconds unless the environment says otherwise: the nearest Laravel sub-minute step.
    expect($event)->not->toBeNull()->and($event->onOneServer)->toBeTrue()->and($event->isRepeatable())->toBeTrue()->and($event->repeatSeconds)->toBe(5);

    Queue::fake();
    $event->run(app());
    Queue::assertPushedOn('maintenance', DispatchDueSyncsJob::class);
    expect(new DispatchDueSyncsJob)->not->toBeInstanceOf(ShouldBeUnique::class);
});

// Story 2.16: the retention sweep runs every five minutes on queue `maintenance` (worker-compute holds the `maintenance` credentials), onOneServer.
it('schedules the raw history sweep every five minutes on queue maintenance with onOneServer', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => ($e->description ?? null) === SweepRawHistoryJob::class);

    expect($event)->not->toBeNull()->and($event->onOneServer)->toBeTrue()->and($event->expression)->toBe('*/5 * * * *');

    Queue::fake();
    $event->run(app());
    Queue::assertPushedOn('maintenance', SweepRawHistoryJob::class);
});
