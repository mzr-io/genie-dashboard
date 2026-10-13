<?php

use BlockTypes\ActivityFeed\V1\Shaper as Activity;
use BlockTypes\CalendarAgenda\V1\Shaper as Agenda;
use BlockTypes\TextStatus\V1\Shaper as TextStatus;

// Story 5.14: the activity-feed, calendar-agenda and text-status Shapers.
it('keeps a real timestamp as written, nulls an unreal one and says the feed is partial', function () {
    $payload = (new Activity)->shape([
        'actor' => ['Ada Lovelace', 'Grace', 'Alan'],
        'action' => ['merged', 'opened', 'closed'],
        'object' => ['PR 12', null, 'Issue 3'],
        'timestamp' => ['2026-05-22T09:30:00Z', 'yesterday-ish', '2026-02-31'],
        'avatar' => ['Ada Lovelace', null, null],
    ]);

    expect($payload['state'])->toBe('normal')
        ->and(array_column($payload['rows'], 'timestamp'))->toBe(['2026-05-22T09:30:00Z', null, null])
        ->and($payload['rows'][0]['avatar']['initials'])->toBe('AL')
        ->and($payload['rows'][0]['object'])->toBe('PR 12')
        ->and($payload['partial'])->toBeTrue()
        ->and(count(array_unique(array_column($payload['rows'], 'key'))))->toBe(3);

    $clean = (new Activity)->shape(['actor' => ['a'], 'action' => ['b'], 'timestamp' => ['2026-05-22']]);

    expect($clean['partial'])->toBeFalse()
        ->and($clean['rows'][0]['timestamp'])->toBe('2026-05-22')
        ->and((new Activity)->shape(['actor' => ['a']])['state'])->toBe('unavailable')
        ->and((new Activity)->shape(['actor' => [], 'action' => [], 'timestamp' => []])['state'])->toBe('empty');
});

it('orders the agenda by day and time, keeps a date a date and a time its wall clock', function () {
    $payload = (new Agenda)->shape([
        'event_date' => ['2026-05-23T00:00:00Z', '2026-05-22', '2026-05-22', 'someday'],
        'start_time' => ['08:00', '14:30', '9:30', '10:00'],
        'event_title' => ['Late', 'Review', 'Product sync', 'Lost'],
        'event_subtitle' => [null, null, 'Meeting room 2A', null],
        'duration' => [null, null, '45', 'x'],
        'category' => [null, 'Review', 'Meeting', null],
    ], ['weekStrip' => true]);

    expect(array_column($payload['rows'], 'title'))->toBe(['Product sync', 'Review', 'Late', 'Lost'])
        ->and(array_column($payload['rows'], 'date'))->toBe(['2026-05-22', '2026-05-22', '2026-05-23', null])
        ->and($payload['rows'][0]['time'])->toBe('09:30')
        ->and($payload['rows'][0]['duration'])->toBe('45')
        ->and($payload['rows'][0]['tint'])->toBeBetween(0, 5)
        ->and($payload['rows'][3]['duration'])->toBeNull()
        ->and($payload['weekStrip'])->toBeTrue()
        ->and($payload['partial'])->toBeTrue();
});

it('gives the same category the same rule colour and a missing slot makes the agenda unavailable', function () {
    $one = (new Agenda)->shape(['event_date' => ['2026-05-22'], 'start_time' => ['09:00'], 'event_title' => ['a'], 'category' => ['Meeting']]);
    $two = (new Agenda)->shape(['event_date' => ['2026-05-23'], 'start_time' => ['10:00'], 'event_title' => ['b'], 'category' => [' meeting ']]);

    expect($one['rows'][0]['tint'])->toBe($two['rows'][0]['tint'])
        ->and($one['weekStrip'])->toBeFalse()
        ->and((new Agenda)->shape(['event_title' => ['a']])['state'])->toBe('unavailable');
});

it('shows text as written, a status in its band, and neutral when it matched none', function () {
    $text = (new TextStatus)->shape(['status_text' => 'All good', 'title' => 'Health']);
    $band = ['text' => 'On track', 'color' => 'success', 'icon' => 'check'];
    $matched = (new TextStatus)->shape(['status_text' => 'On track'], ['textMode' => 'status', 'statusBand' => $band]);
    $unmatched = (new TextStatus)->shape(['status_text' => 'Weird'], ['textMode' => 'status']);
    $inText = (new TextStatus)->shape(['status_text' => 'On track'], ['statusBand' => $band]);

    expect($text['mode'])->toBe('text')
        ->and($text['text'])->toBe('All good')
        ->and($text['band'])->toBeNull()
        ->and($matched['mode'])->toBe('status')
        ->and($matched['band'])->toBe($band)
        ->and($unmatched['band'])->toBeNull()
        ->and($unmatched['text'])->toBe('Weird')
        ->and($inText['band'])->toBeNull()
        ->and((new TextStatus)->shape(['status_text' => null])['state'])->toBe('unavailable');
});
