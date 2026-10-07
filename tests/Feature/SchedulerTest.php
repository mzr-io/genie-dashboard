<?php

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
