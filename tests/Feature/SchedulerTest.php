<?php

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

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
    Event::listen(ScheduledTaskFinished::class, function () use (&$runs) {
        $runs++;
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
