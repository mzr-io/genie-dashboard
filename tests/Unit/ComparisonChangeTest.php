<?php

use App\Modules\Blocks\Application\DraftConfig;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;
use App\Modules\Mapping\Application\ComparisonChange;
use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Application\QueryPlanEngine;
use App\Modules\Mapping\Infrastructure\InProcessExecutor;
use App\Modules\Mapping\Infrastructure\PreviewSettings;
use App\Platform\Json\LosslessJson;
use Illuminate\Config\Repository;

// Story 5.5: a comparison from a mapped previous value: the change in % , an absolute delta or percentage points, exact, never 0 or infinity.
function ccConfig(): Repository
{
    $preview = [];

    foreach (['membership_limit' => '2', 'window_seconds' => '60', 'max_extracted_values' => '1000', 'max_evaluations' => '100', 'max_wall_ms' => '1000'] as $name => $value) {
        $preview[$name] = ['value' => $value];
    }

    return new Repository(['dashflow' => ['preview' => $preview, 'tunables' => ['numbers' => ['decimal_scale' => ['value' => '6']]]]]);
}

function ccRun(string $mode, string $json, array $presentation = []): array
{
    $config = ccConfig();
    $math = new DecimalMath($config);
    $engine = new QueryPlanEngine(new InProcessExecutor(null, $math), new PackageShapers, new PreviewSettings($config), math: $math);
    $type = (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);
    $plan = ['v' => 1, 'extract' => ['fields' => ['label' => '$.label', 'total' => '$.total', 'before' => '$.previous']], 'shape' => ['kpi_label' => 'label', 'headline_value' => 'total', 'comparison' => 'before']];
    $rules = ['slots' => ['comparison' => ['mode' => $mode]]] + $presentation;
    $result = $engine->run($type, $plan, LosslessJson::decode($json), $engine->guards(), $rules);

    return [$result->payload, $result->unavailable];
}

it('calculates the change exactly in each mode', function (string $mode, string $json, string $expected) {
    $change = (new ComparisonChange(new DecimalMath(ccConfig())))->change($mode, json_decode($json, true)['c'], json_decode($json, true)['p']);

    expect($change)->toBe($expected);
})->with([
    'percent' => ['pct_change', '{"c":"284680","p":"249195"}', '14.239852'],
    'percent down' => ['pct_change', '{"c":"90","p":"100"}', '-10'],
    'absolute' => ['absolute_delta', '{"c":"90","p":"100"}', '-10'],
    'points' => ['pp_delta', '{"c":"72","p":"70"}', '2'],
    'equal' => ['absolute_delta', '{"c":"5.50","p":"5.5"}', '0'],
]);

it('gives no change for a missing, non-numeric or zero previous value', function (mixed $current, mixed $previous, string $mode) {
    expect((new ComparisonChange(new DecimalMath(ccConfig())))->change($mode, $current, $previous))->toBeNull();
})->with([
    'missing' => ['10', null, 'pct_change'],
    'text' => ['10', 'n/a', 'absolute_delta'],
    'zero previous' => ['10', '0', 'pct_change'],
]);

it('shapes the comparison from the headline and the previous value', function () {
    [$payload] = ccRun('pct_change', '{"label":"Revenue","total":284680,"previous":249195}');

    expect($payload['comparison']['value'])->toBe('14.239852')
        ->and($payload['comparison']['format']['kind'])->toBe('percent')
        ->and($payload['comparison'])->not->toHaveKey('signed');

    [$payload] = ccRun('absolute_delta', '{"label":"Revenue","total":90,"previous":100}');

    expect($payload['comparison']['value'])->toBe('-10')
        ->and($payload['comparison']['direction'])->toBe('down')
        ->and($payload['comparison']['signed'])->toBeTrue();

    [$payload] = ccRun('pp_delta', '{"label":"Rate","total":72,"previous":70}');

    expect($payload['comparison']['format']['unitsSuffix'])->toBe('pp')
        ->and($payload['comparison']['format']['decimalPlaces'])->toBe(1);
});

it('keeps the headline when the previous value is missing or zero', function () {
    [$payload, $unavailable] = ccRun('pct_change', '{"label":"Revenue","total":10,"previous":0}');

    expect($payload['comparison'])->toBeNull()
        ->and($payload['headline']['available'])->toBeTrue()
        ->and($unavailable)->toHaveKey('comparison');

    [$payload, $unavailable] = ccRun('pct_change', '{"label":"Revenue","total":10}');

    expect($payload['comparison'])->toBeNull()
        ->and($payload['headline']['value'])->toBe('10')
        ->and($unavailable['comparison'])->toBe('missing');
});

it('accepts a comparison mode on the comparison slot only', function () {
    $type = (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);

    expect(DraftConfig::normalisePresentation(['slots' => ['comparison' => ['mode' => 'pp_delta']]], $type))->toBe(['slots' => ['comparison' => ['mode' => 'pp_delta']]]);

    foreach ([['slots' => ['comparison' => ['mode' => 'ratio']]], ['slots' => ['headline_value' => ['mode' => 'pct_change']]]] as $bad) {
        expect(fn () => DraftConfig::normalisePresentation($bad, $type))->toThrow(InvalidDraft::class);
    }
});
