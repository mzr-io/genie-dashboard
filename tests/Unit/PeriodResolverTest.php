<?php

use App\Platform\Period\InvalidPeriodInput;
use App\Platform\Period\PeriodBehaviour;
use App\Platform\Period\PeriodPreset;
use App\Platform\Period\PeriodResolver;

// Story 4.1: FR-35 period resolution, AD-27.
function resolvePeriod(PeriodBehaviour $b, ?string $selection = null, ?string $dashboard = null, ?string $default = 'this_month', array $enabled = ['this_month', 'last_month', 'last_7_days'], string $tz = 'UTC', string $now = '2026-10-15T12:00:00Z', bool $comparison = false): ?array
{
    return PeriodResolver::resolve($b, $selection, $dashboard, $default, $enabled, $tz, new DateTimeImmutable($now), $comparison)?->toArray();
}

it('follows the dashboard Date Range, then the Block default', function () {
    $follow = PeriodBehaviour::FollowDashboard;

    expect(resolvePeriod($follow, dashboard: 'last_7_days'))->toBe(['token' => 'last_7_days', 'start' => '2026-10-09', 'end' => '2026-10-16'])
        ->and(resolvePeriod($follow))->toBe(['token' => 'this_month', 'start' => '2026-10-01', 'end' => '2026-11-01']);
});

it('lets the user choice win for an Own selector and starts at the default', function () {
    $own = PeriodBehaviour::OwnSelector;

    expect(resolvePeriod($own, selection: 'last_month', dashboard: 'last_7_days')['token'])->toBe('last_month')
        ->and(resolvePeriod($own, dashboard: 'last_7_days')['token'])->toBe('this_month');
});

it('ignores every input for a not date-filtered Block', function () {
    expect(resolvePeriod(PeriodBehaviour::NotDateFiltered, selection: 'garbage', dashboard: '2026-01-01'))->toBeNull();
});

it('rejects a preset the Block has not enabled and any absolute or unknown date with 422', function (string $bad) {
    try {
        resolvePeriod(PeriodBehaviour::OwnSelector, selection: $bad);
        $this->fail('accepted');
    } catch (InvalidPeriodInput $e) {
        expect($e->getStatusCode())->toBe(422);
    }
})->with(['not enabled' => 'current_year', 'absolute' => '2026-01-01', 'range' => '2026-01-01/2026-02-01', 'unknown' => 'forever', 'empty' => '']);

it('never applies the dashboard range to an Own selector or the selection to a Follow Block', function () {
    expect(resolvePeriod(PeriodBehaviour::OwnSelector, dashboard: 'garbage')['token'])->toBe('this_month')
        ->and(resolvePeriod(PeriodBehaviour::FollowDashboard, selection: 'garbage')['token'])->toBe('this_month');
});

it('returns no period when nothing is chosen and the Block has no default', function () {
    expect(resolvePeriod(PeriodBehaviour::FollowDashboard, default: null))->toBeNull();
});

it('resolves in the Workspace timezone near a month boundary, not the user timezone', function (string $tz, string $start, string $end) {
    // 2026-10-31T23:30Z is Nov 1 in Dhaka (+6), still Oct 31 in UTC and New York.
    expect(resolvePeriod(PeriodBehaviour::FollowDashboard, dashboard: 'this_month', enabled: ['this_month'], tz: $tz, now: '2026-10-31T23:30:00Z'))
        ->toBe(['token' => 'this_month', 'start' => $start, 'end' => $end]);
})->with([
    'dhaka' => ['Asia/Dhaka', '2026-11-01', '2026-12-01'],
    'utc' => ['UTC', '2026-10-01', '2026-11-01'],
    'new york' => ['America/New_York', '2026-10-01', '2026-11-01'],
]);

it('computes every preset window at boundaries', function (string $preset, string $now, string $start, string $end) {
    $r = PeriodResolver::resolve(PeriodBehaviour::FollowDashboard, null, $preset, null, [$preset], 'UTC', new DateTimeImmutable($now));

    expect([$r->start, $r->end])->toBe([$start, $end]);
})->with([
    ['today', '2026-12-31T23:59:59Z', '2026-12-31', '2027-01-01'],
    ['yesterday', '2026-01-01T00:00:00Z', '2025-12-31', '2026-01-01'],
    ['this_week', '2026-10-11T10:00:00Z', '2026-10-05', '2026-10-12'],
    ['this_week', '2026-10-12T00:00:00Z', '2026-10-12', '2026-10-19'],
    ['last_week', '2026-10-12T00:00:00Z', '2026-10-05', '2026-10-12'],
    ['last_month', '2026-01-15T00:00:00Z', '2025-12-01', '2026-01-01'],
    ['this_quarter', '2026-11-30T00:00:00Z', '2026-10-01', '2027-01-01'],
    ['last_quarter', '2026-01-02T00:00:00Z', '2025-10-01', '2026-01-01'],
    ['current_year', '2026-02-28T00:00:00Z', '2026-01-01', '2027-01-01'],
    ['last_year', '2026-06-01T00:00:00Z', '2025-01-01', '2026-01-01'],
    ['last_30_days', '2026-03-01T00:00:00Z', '2026-01-31', '2026-03-02'],
    ['last_90_days', '2026-10-15T00:00:00Z', '2026-07-18', '2026-10-16'],
]);

it('derives the comparison window', function () {
    $month = resolvePeriod(PeriodBehaviour::FollowDashboard, default: 'this_month', now: '2026-03-10T00:00:00Z', comparison: true);
    $rolling = resolvePeriod(PeriodBehaviour::FollowDashboard, default: 'last_7_days', enabled: ['last_7_days'], comparison: true);

    expect($month['comparison'])->toBe(['token' => 'this_month', 'start' => '2026-02-01', 'end' => '2026-03-01'])
        ->and($rolling['comparison'])->toBe(['token' => 'last_7_days', 'start' => '2026-10-02', 'end' => '2026-10-09']);
});

it('resolves a custom default from the Admin dates and never accepts it as a selection', function () {
    $r = PeriodResolver::resolve(PeriodBehaviour::FollowDashboard, null, null, 'custom', [], 'UTC', new DateTimeImmutable('2026-10-15T00:00:00Z'), true, ['from' => '2026-01-01', 'to' => '2026-01-31']);

    expect($r->toArray())->toBe(['token' => 'custom', 'start' => '2026-01-01', 'end' => '2026-02-01', 'comparison' => ['token' => 'custom', 'start' => '2025-12-01', 'end' => '2026-01-01']])
        ->and($r->lastDay())->toBe('2026-01-31')
        ->and(PeriodResolver::resolve(PeriodBehaviour::FollowDashboard, null, null, 'custom', [], 'UTC', new DateTimeImmutable('2026-10-15'), false, ['from' => '2026-01-01']))->toBeNull()
        ->and(fn () => PeriodResolver::resolve(PeriodBehaviour::OwnSelector, 'custom', null, 'this_month', [], 'UTC', new DateTimeImmutable('2026-10-15'), false, ['from' => '2026-01-01', 'to' => '2026-01-31']))->toThrow(InvalidPeriodInput::class);
});

it('falls back to UTC for an unknown timezone and lists every preset', function () {
    expect(resolvePeriod(PeriodBehaviour::FollowDashboard, tz: 'Mars/Olympus')['start'])->toBe('2026-10-01')
        ->and(PeriodPreset::values())->toContain('this_month', 'last_90_days')->toHaveCount(13);
});
