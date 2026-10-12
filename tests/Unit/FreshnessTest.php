<?php

use App\Modules\Results\Application\ComputeResults;
use App\Modules\Results\Contracts\Freshness;

// Story 4.3: the staleness rule, a pure function of last_success_at, the refresh interval and the database clock.
it('is stale only strictly after two refresh intervals without a successful check', function (int $age, bool $stale) {
    $last = new DateTimeImmutable('2026-10-12T10:00:00Z');
    $now = $last->modify("+{$age} seconds");

    expect(Freshness::isStale($last, 60, $now))->toBe($stale)
        ->and(Freshness::staleAt($last, 60)->format('c'))->toBe('2026-10-12T10:02:00+00:00');
})->with([
    'fresh' => [0, false],
    'one interval' => [60, false],
    'exactly two' => [120, false],
    'one second past' => [121, true],
    'long past' => [86400, true],
]);

it('judges by the older of several checks, and by none when one side has never succeeded', function () {
    $a = new DateTimeImmutable('2026-10-12T10:00:00Z');
    $b = new DateTimeImmutable('2026-10-12T10:05:00Z');

    expect(Freshness::olderOf([$b, $a])->format('c'))->toBe($a->format('c'))
        ->and(Freshness::olderOf([$a]))->toEqual($a)
        ->and(Freshness::olderOf([$a, null]))->toBeNull()
        ->and(Freshness::olderOf([]))->toBeNull();
});

it('reads data_as_of from a mapped slot value only when it is a date, as UTC', function (mixed $value, ?string $expected) {
    $method = new ReflectionMethod(ComputeResults::class, 'asOf');

    expect($method->invoke(null, $value))->toBe($expected);
})->with([
    'iso' => ['2026-10-01T08:30:00+06:00', '2026-10-01T02:30:00Z'],
    'date' => ['2026-10-01', '2026-10-01T00:00:00Z'],
    'text' => ['yesterday', null],
    'now' => ['now', null],
    'empty' => ['', null],
    'null' => [null, null],
    'number' => [12, null],
]);
