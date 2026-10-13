<?php

use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;
use App\Modules\Mapping\Application\AutoMapper;
use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Application\FieldRoleInference;
use App\Modules\Mapping\Application\QueryPlanEngine;
use App\Modules\Mapping\Infrastructure\InProcessExecutor;
use App\Modules\Mapping\Infrastructure\PreviewSettings;
use App\Platform\Json\LosslessJson;
use App\Platform\Json\SampleShape;
use BlockTypes\LineArea\V1\Shaper;
use Illuminate\Config\Repository;

// Story 5.8c: the line-area v1 Shaper and the package through the engine: a gap is a null, names fall back from the label to the field to "Series n".
function laType()
{
    return (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('line-area', 1);
}

it('shapes the axis, the series with their names, the gaps, the highlight and the target', function () {
    $payload = (new Shaper)->shape([
        'x' => ['2026-01-01', '2026-02-01', '2026-03-01'],
        'series_1' => ['10.5', null, '12'],
        'series_2' => ['4', '5', '6'],
        'series_label_1' => 'Revenue',
        'highlight_x' => '2026-02-01',
        'target_value' => '9000000000000000001.5',
        'target_label' => 'Goal',
        'title' => 'Sales',
    ], ['fieldNames' => ['series_2' => 'cost', 'x' => 'day'], 'chartKind' => 'area', 'directLabels' => false]);

    expect($payload['state'])->toBe('normal')
        ->and($payload['kind'])->toBe('area')
        ->and($payload['x'])->toBe(['2026-01-01', '2026-02-01', '2026-03-01'])
        ->and($payload['series'])->toBe([
            ['key' => 'series_1', 'name' => 'Revenue', 'values' => ['10.5', null, '12']],
            ['key' => 'series_2', 'name' => 'cost', 'values' => ['4', '5', '6']],
        ])
        ->and($payload['xLabel'])->toBe('day')
        ->and($payload['partial'])->toBeTrue()
        ->and($payload['highlight'])->toBe(1)
        ->and($payload['target'])->toBe(['value' => '9000000000000000001.5', 'label' => 'Goal'])
        ->and($payload['directLabels'])->toBeFalse()
        ->and($payload['title'])->toBe('Sales');

    $plain = (new Shaper)->shape(['x' => ['a'], 'series_1' => ['1']]);

    expect($plain['series'][0]['name'])->toBe('Series 1')
        ->and($plain['kind'])->toBe('line')
        ->and($plain['directLabels'])->toBeTrue()
        ->and($plain['partial'])->toBeFalse()
        ->and($plain['highlight'])->toBeNull()
        ->and($plain['target'])->toBeNull();
});

it('is unavailable without the axis or a series, empty with no value in any row, and never draws a zero', function () {
    $shaper = new Shaper;

    expect($shaper->shape(['series_1' => ['1']])['state'])->toBe('unavailable')
        ->and($shaper->shape(['x' => ['a']])['state'])->toBe('unavailable')
        ->and($shaper->shape(['x' => ['a', 'b'], 'series_1' => [null, null]])['state'])->toBe('empty')
        ->and($shaper->shape(['x' => ['a'], 'series_1' => ['n/a']])['series'][0]['values'])->toBe([null]);
});

it('keeps data text literal and carries a requested state through', function () {
    $payload = (new Shaper)->shape(['x' => ['<b>x</b>'], 'series_1' => ['1'], 'title' => '<img src=x onerror=alert(1)>'], ['state' => 'stale', 'staleSince' => '2026-10-13T10:00:00Z']);

    expect($payload['state'])->toBe('stale')
        ->and($payload['title'])->toBe('<img src=x onerror=alert(1)>')
        ->and($payload['x'])->toBe(['<b>x</b>'])
        ->and($payload['staleSince'])->toBe('2026-10-13T10:00:00Z');
});

it('maps the rows of a sample and renders the package through the engine, named by the field', function () {
    $json = '{"rows":[{"day":"2026-01-01","revenue":10,"cost":4},{"day":"2026-01-02","revenue":12.25,"cost":5},{"day":"2026-01-03","revenue":14,"cost":6}]}';
    $report = (new FieldRoleInference)->infer(SampleShape::of(LosslessJson::decode($json)), null, []);
    $mapped = (new AutoMapper)->map($report, laType(), []);

    $preview = [];

    foreach (['membership_limit' => '2', 'window_seconds' => '60', 'max_extracted_values' => '1000', 'max_evaluations' => '100', 'max_wall_ms' => '1000'] as $name => $value) {
        $preview[$name] = ['value' => $value];
    }

    $config = new Repository(['dashflow' => ['preview' => $preview, 'tunables' => ['numbers' => ['decimal_scale' => ['value' => '6']]]]]);
    $engine = new QueryPlanEngine(new InProcessExecutor(null, new DecimalMath($config)), new PackageShapers, new PreviewSettings($config));
    $rules = ['chart_kind' => 'area', 'direct_labels' => false, 'slots' => ['series_1' => ['format' => ['kind' => 'currency', 'currencyCode' => 'USD', 'decimalPlaces' => 2]]]];
    $result = $engine->run(laType(), $mapped->plan, LosslessJson::decode($json), $engine->guards(), $rules);

    expect($result->payload['state'])->toBe('normal')
        ->and($result->payload['kind'])->toBe('area')
        ->and($result->payload['x'])->toBe(['2026-01-01', '2026-01-02', '2026-01-03'])
        ->and(array_column($result->payload['series'], 'name'))->toBe(['revenue', 'cost'])
        ->and($result->payload['series'][0]['values'])->toBe(['10', '12.25', '14'])
        ->and($result->payload['format'])->toMatchArray(['kind' => 'currency', 'currencyCode' => 'USD', 'decimalPlaces' => 2])
        ->and($result->payload['directLabels'])->toBeFalse();
});
