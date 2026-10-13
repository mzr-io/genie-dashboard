<?php

use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;
use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Application\Expressions\ExpressionParser;
use App\Modules\Mapping\Application\Expressions\ExpressionResolver;
use App\Modules\Mapping\Application\Expressions\TextTemplate;
use App\Modules\Mapping\Application\QueryPlanEngine;
use App\Modules\Mapping\Contracts\Transforms;
use App\Modules\Mapping\Infrastructure\InProcessExecutor;
use App\Modules\Mapping\Infrastructure\PreviewSettings;
use App\Platform\Json\LosslessJson;
use Illuminate\Config\Repository;

// Story 5.10 (FR-31): the normative "Revenue overview" example, through the engine and the kpi-chart Shaper, against a golden payload.
function roEngine(): QueryPlanEngine
{
    $preview = [];

    foreach (['membership_limit' => '2', 'window_seconds' => '60', 'max_extracted_values' => '1000', 'max_evaluations' => '100', 'max_wall_ms' => '1000'] as $name => $value) {
        $preview[$name] = ['value' => $value];
    }

    $config = new Repository(['dashflow' => ['preview' => $preview, 'tunables' => ['numbers' => ['decimal_scale' => ['value' => '6']]]]]);
    $math = new DecimalMath($config);

    return new QueryPlanEngine(new InProcessExecutor(null, $math), new PackageShapers, new PreviewSettings($config), math: $math);
}

function roText(string $source): array
{
    return ['source' => $source, 'parts' => TextTemplate::resolve(TextTemplate::parse($source), [])];
}

/** The mapping of the story: static header and label, headline, PCT_CHANGE comparison, month axis and revenue series. */
function roPlan(): array
{
    $names = ['total' => '$.data.total', 'previous_total' => '$.data.previous_total'];
    $ast = ExpressionResolver::resolve(ExpressionParser::parse('PCT_CHANGE(total, previous_total)'), $names);
    [$steps] = Transforms::check([['type' => 'calc', 'name' => 'change', 'source' => 'PCT_CHANGE(total, previous_total)', 'level' => 'row', 'ast' => $ast]]);

    return Transforms::apply([
        'v' => 1,
        'extract' => ['fields' => [
            'title' => 'tpl:title',
            'subtitle' => 'tpl:subtitle',
            'kpi_label' => 'tpl:kpi_label',
            'headline_value' => '$.data.total',
            'comparison' => 'calc:change',
            'comparison_label' => 'tpl:comparison_label',
            'x' => '$.data.monthly[*].month',
            'series_1' => '$.data.monthly[*].revenue',
        ]],
        'shape' => ['title' => 'title', 'subtitle' => 'subtitle', 'kpi_label' => 'kpi_label', 'headline_value' => 'headline_value', 'comparison' => 'comparison', 'comparison_label' => 'comparison_label', 'x' => 'x', 'series_1' => 'series_1'],
        'templates' => [
            'title' => roText('Revenue overview'),
            'subtitle' => roText('Financial performance · Current year'),
            'kpi_label' => roText('Total revenue'),
            'comparison_label' => roText('from last year'),
        ],
    ], $steps);
}

function roRules(): array
{
    return [
        'slots' => [
            'headline_value' => ['format' => ['kind' => 'currency', 'currencyCode' => 'USD', 'decimalPlaces' => 0], 'emphasis' => ['role' => 'headline', 'size' => 'lg', 'weight' => 'bold']],
            'comparison' => ['format' => ['kind' => 'percent', 'decimalPlaces' => 1], 'direction' => 'higher_is_better'],
            'x' => ['format' => ['kind' => 'date', 'datePattern' => 'MMM']],
            'series_1' => ['format' => ['kind' => 'currency', 'currencyCode' => 'USD', 'decimalPlaces' => 0]],
        ],
        'chart_kind' => 'area',
    ];
}

function roRun(string $json): array
{
    $type = (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-chart', 1);
    $engine = roEngine();

    return $engine->run($type, roPlan(), LosslessJson::decode($json), $engine->guards(), roRules())->payload;
}

it('shapes the FR-31 fixture to the golden payload', function () {
    $payload = roRun((string) file_get_contents(__DIR__.'/../Fixtures/revenue-overview/document.json'));
    $golden = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/revenue-overview/golden-payload.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toEqual($golden);
});

it('has the headline, the comparison, the label and the chart of the story', function () {
    $payload = roRun((string) file_get_contents(__DIR__.'/../Fixtures/revenue-overview/document.json'));

    expect($payload['title'])->toBe('Revenue overview')
        ->and($payload['kpiLabel'])->toBe('Total revenue')
        ->and($payload['headline']['value'])->toBe('284680')
        ->and($payload['headline']['emphasis'])->toBe(['role' => 'headline', 'size' => 'lg', 'weight' => 'bold'])
        ->and($payload['comparison']['value'])->toBe('14.239852')
        ->and($payload['comparison']['direction'])->toBe('up')
        ->and($payload['comparison']['lowerIsBetter'])->toBeFalse()
        ->and($payload['comparisonLabel'])->toBe('from last year')
        ->and($payload['chart']['x'])->toBe(['2026-01', '2026-02'])
        ->and($payload['chart']['xPattern'])->toBe('MMM')
        ->and($payload['chart']['series'][0]['values'])->toBe(['10200', '12650'])
        ->and($payload['chart']['kind'])->toBe('area');
});

it('shows the comparison unavailable, not zero, and keeps the headline and the chart when the previous total is gone', function () {
    $payload = roRun('{"data":{"total":284680,"currency":"USD","monthly":[{"month":"2026-01","revenue":10200},{"month":"2026-02","revenue":12650}]}}');

    expect($payload['comparison'])->toBeNull()
        ->and($payload['headline']['value'])->toBe('284680')
        ->and($payload['state'])->toBe('normal')
        ->and($payload['chart']['state'])->toBe('normal')
        ->and($payload['chart']['series'][0]['values'])->toBe(['10200', '12650']);
});

it('keeps the headline and has an empty chart, with no point drawn, when the months are empty', function () {
    $payload = roRun('{"data":{"total":284680,"previous_total":249195,"currency":"USD","monthly":[]}}');

    expect($payload['headline']['value'])->toBe('284680')
        ->and($payload['chart']['state'])->toBe('empty')
        ->and($payload['chart']['x'])->toBe([])
        ->and($payload['chart']['series'][0]['values'])->toBe([]);
});
