<?php

use App\Modules\Blocks\Application\DraftConfig;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;
use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Application\QueryPlanEngine;
use App\Modules\Mapping\Infrastructure\InProcessExecutor;
use App\Modules\Mapping\Infrastructure\PreviewSettings;
use App\Platform\Json\LosslessJson;
use App\Platform\Period\PriorWindow;
use Illuminate\Config\Repository;

// Story 5.6: the prior window of a comparison, its rules, and the engine reading the prior response (never made up).
it('computes the three prior windows from the active period', function (string $start, string $end, string $kind, ?int $amount, ?string $unit, array $expected) {
    expect(PriorWindow::of($start, $end, $kind, $amount, $unit))->toBe($expected);
})->with([
    'previous period, a month' => ['2026-05-01', '2026-06-01', 'previous_period', null, null, ['start' => '2026-03-31', 'end' => '2026-05-01']],
    'previous period, seven days' => ['2026-05-10', '2026-05-17', 'previous_period', null, null, ['start' => '2026-05-03', 'end' => '2026-05-10']],
    'same period last year' => ['2026-05-01', '2026-06-01', 'same_period_last_year', null, null, ['start' => '2025-05-01', 'end' => '2025-06-01']],
    'last year keeps a leap day inside the shorter month' => ['2024-02-01', '2024-03-01', 'same_period_last_year', null, null, ['start' => '2023-02-01', 'end' => '2023-03-01']],
    'offset in weeks' => ['2026-05-01', '2026-06-01', 'custom_offset', 2, 'week', ['start' => '2026-04-17', 'end' => '2026-05-18']],
    'offset in months, clamped to a shorter month' => ['2026-03-31', '2026-04-30', 'custom_offset', 1, 'month', ['start' => '2026-02-28', 'end' => '2026-03-30']],
    'offset in quarters' => ['2026-04-01', '2026-07-01', 'custom_offset', 1, 'quarter', ['start' => '2026-01-01', 'end' => '2026-04-01']],
]);

it('gives no window for a bad date, an empty window, an unknown kind or an offset that is not a number and a unit', function (array $args) {
    expect(PriorWindow::of(...$args))->toBeNull();
})->with([
    'bad date' => [['2026-02-30', '2026-03-01', 'previous_period']],
    'empty window' => [['2026-05-01', '2026-05-01', 'previous_period']],
    'unknown kind' => [['2026-05-01', '2026-06-01', 'next_period']],
    'no amount' => [['2026-05-01', '2026-06-01', 'custom_offset', null, 'day']],
    'zero amount' => [['2026-05-01', '2026-06-01', 'custom_offset', 0, 'day']],
    'unknown unit' => [['2026-05-01', '2026-06-01', 'custom_offset', 3, 'fortnight']],
]);

function ppType()
{
    return (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);
}

it('keeps a prior-period source with its window, and refuses a source without one or a window without the source', function () {
    $ok = ['slots' => ['comparison' => ['source' => 'prior_period', 'window' => ['kind' => 'custom_offset', 'amount' => 3, 'unit' => 'month'], 'mode' => 'pct_change']]];

    expect(DraftConfig::normalisePresentation($ok, ppType()))->toEqual($ok);

    foreach ([
        ['slots' => ['comparison' => ['source' => 'prior_period']]],
        ['slots' => ['comparison' => ['window' => ['kind' => 'previous_period']]]],
        ['slots' => ['comparison' => ['source' => 'prior_period', 'window' => ['kind' => 'custom_offset', 'amount' => 0, 'unit' => 'day']]]],
        ['slots' => ['comparison' => ['source' => 'prior_period', 'window' => ['kind' => 'previous_period', 'amount' => 2]]]],
        ['slots' => ['headline_value' => ['source' => 'prior_period']]],
    ] as $bad) {
        expect(fn () => DraftConfig::normalisePresentation($bad, ppType()))->toThrow(InvalidDraft::class);
    }
});

function ppRun(?string $comparison, string $primary = '{"label":"Revenue","total":284680}'): array
{
    $preview = [];

    foreach (['membership_limit' => '2', 'window_seconds' => '60', 'max_extracted_values' => '1000', 'max_evaluations' => '100', 'max_wall_ms' => '1000'] as $name => $value) {
        $preview[$name] = ['value' => $value];
    }

    $config = new Repository(['dashflow' => ['preview' => $preview, 'tunables' => ['numbers' => ['decimal_scale' => ['value' => '6']]]]]);
    $math = new DecimalMath($config);
    $engine = new QueryPlanEngine(new InProcessExecutor(null, $math), new PackageShapers, new PreviewSettings($config), math: $math);
    $plan = ['v' => 1, 'extract' => ['fields' => ['label' => '$.label', 'total' => '$.total']], 'shape' => ['kpi_label' => 'label', 'headline_value' => 'total']];
    $rules = ['slots' => ['comparison' => ['source' => 'prior_period', 'window' => ['kind' => 'previous_period']]]];
    $result = $engine->run(ppType(), $plan, LosslessJson::decode($primary), $engine->guards(), $rules, null, $comparison === null ? null : LosslessJson::decode($comparison));

    return [$result->payload, $result->unavailable];
}

it('shows the change against the prior response and never makes one up without it', function () {
    [$payload, $unavailable] = ppRun('{"label":"Revenue","total":249195}');

    expect($payload['comparison']['value'])->toBe('14.239852')
        ->and($payload['comparison']['direction'])->toBe('up')
        ->and($unavailable)->not->toHaveKey('comparison');

    [$payload, $unavailable] = ppRun(null);

    expect($payload['comparison'])->toBeNull()
        ->and($payload['headline']['value'])->toBe('284680')
        ->and($unavailable['comparison'])->toBe('missing');

    [$payload] = ppRun('{"label":"Revenue"}');

    expect($payload['comparison'])->toBeNull()
        ->and($payload['headline']['available'])->toBeTrue();
});
