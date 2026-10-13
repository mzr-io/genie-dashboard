<?php

use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;
use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Application\Expressions\TextTemplate;
use App\Modules\Mapping\Application\QueryPlanEngine;
use App\Modules\Mapping\Contracts\DecimalValue;
use App\Modules\Mapping\Infrastructure\InProcessExecutor;
use App\Modules\Mapping\Infrastructure\PreviewSettings;
use App\Platform\Json\LosslessJson;
use BlockTypes\ItemList\V1\Shaper as ItemList;
use BlockTypes\ProgressList\V1\Shaper as ProgressList;
use Illuminate\Config\Repository;

// Story 5.13: the list and the progress-list Shapers, the exact percent, and a template written once for every row.
function lpEngine(): QueryPlanEngine
{
    $preview = [];

    foreach (['membership_limit' => '2', 'window_seconds' => '60', 'max_extracted_values' => '1000', 'max_evaluations' => '1000', 'max_wall_ms' => '1000'] as $name => $value) {
        $preview[$name] = ['value' => $value];
    }

    $config = new Repository(['dashflow' => ['preview' => $preview, 'tunables' => ['numbers' => ['decimal_scale' => ['value' => '6']]]]]);

    return new QueryPlanEngine(new InProcessExecutor(null, new DecimalMath($config)), new PackageShapers, new PreviewSettings($config));
}

it('computes a percent in decimal, to two places, and has none for a total of 0 or text', function () {
    expect(DecimalValue::percent('12', '16'))->toBe('75')
        ->and(DecimalValue::percent('1', '3'))->toBe('33.33')
        ->and(DecimalValue::percent('2', '3'))->toBe('66.67')
        ->and(DecimalValue::percent('130', '100'))->toBe('130')
        ->and(DecimalValue::percent('1E2', '200'))->toBe('50')
        ->and(DecimalValue::percent('0', '8'))->toBe('0')
        ->and(DecimalValue::percent('5', '0'))->toBeNull()
        ->and(DecimalValue::percent('x', '5'))->toBeNull();
});

it('shapes a list with stable keys and an avatar that depends on the name alone', function () {
    $payload = (new ItemList)->shape([
        'title' => 'Top accounts',
        'item_title' => ['Acme', 'Beta', 'Acme'],
        'item_subtitle' => ['Retail · 12 days', null, 'Retail · 12 days'],
        'item_avatar' => ['Ada Lovelace', 'ada lovelace ', null],
        'item_value' => ['1200.50', '3', null],
        'item_meta' => ['up', null, null],
    ], ['columnRules' => ['item_value' => ['format' => ['kind' => 'currency', 'currencyCode' => 'eur']]]]);

    expect($payload['state'])->toBe('normal')
        ->and($payload['rows'][0]['avatar']['initials'])->toBe('AL')
        ->and($payload['rows'][0]['avatar']['tint'])->toBe($payload['rows'][1]['avatar']['tint'])
        ->and($payload['rows'][0]['avatar']['tint'])->toBeBetween(0, 4)
        ->and($payload['rows'][2]['avatar'])->toBeNull()
        ->and($payload['rows'][0]['subtitle'])->toBe('Retail · 12 days')
        ->and($payload['rows'][1]['value'])->toBe('3')
        ->and($payload['valueFormat']['currencyCode'])->toBe('EUR')
        ->and($payload['metaFormat'])->toBeNull();

    $keys = array_column($payload['rows'], 'key');

    expect(count(array_unique($keys)))->toBe(3)
        ->and($keys[0])->toStartWith('h:');

    $keyed = (new ItemList)->shape(['item_title' => ['a', 'b'], 'row_key' => ['id1', 'id1']]);

    expect(array_column($keyed['rows'], 'key'))->toBe(['k:id1', 'k:id1#2']);
});

it('keeps the same tint for a name in every list and marks a list with no title unavailable', function () {
    $one = (new ItemList)->shape(['item_title' => ['x'], 'item_avatar' => ['Grace Hopper']]);
    $two = (new ItemList)->shape(['item_title' => ['y', 'z'], 'item_avatar' => ['Someone Else', 'Grace Hopper']]);

    expect($one['rows'][0]['avatar']['tint'])->toBe($two['rows'][1]['avatar']['tint'])
        ->and((new ItemList)->shape([])['state'])->toBe('unavailable')
        ->and((new ItemList)->shape(['item_title' => []])['state'])->toBe('empty');
});

it('shapes progress rows with the percent, a bar held to 100 and a dash where there is none', function () {
    $payload = (new ProgressList)->shape([
        'item_title' => ['Website redesign', 'Over', 'Empty total', 'No value'],
        'progress_value' => ['12', '130', '3', null],
        'progress_total' => ['16', '100', '0', '10'],
        'item_caption' => ['12/16 tasks', null, null, null],
    ]);

    expect(array_column($payload['rows'], 'percent'))->toBe(['75', '130', null, null])
        ->and(array_column($payload['rows'], 'fill'))->toBe(['75', '100', null, null])
        ->and($payload['rows'][0]['caption'])->toBe('12/16 tasks')
        ->and($payload['partial'])->toBeTrue();

    $plain = (new ProgressList)->shape(['item_title' => ['a', 'b'], 'progress_value' => ['40', '-5']]);

    expect(array_column($plain['rows'], 'percent'))->toBe(['40', '-5'])
        ->and(array_column($plain['rows'], 'fill'))->toBe(['40', '0'])
        ->and($plain['partial'])->toBeFalse();
});

it('writes a caption template for every row and puts each percent in its band', function () {
    $type = (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('progress-list', 1);
    $source = '{done}/{total} tasks';
    $parts = TextTemplate::resolve(TextTemplate::parse($source), ['done' => '$.data[*].done', 'total' => '$.data[*].total']);
    $plan = [
        'v' => 1,
        'extract' => ['fields' => [
            'name' => '$.data[*].name',
            'done' => '$.data[*].done',
            'total' => '$.data[*].total',
            'item_caption' => 'tpl:item_caption',
        ]],
        'shape' => ['item_title' => 'name', 'progress_value' => 'done', 'progress_total' => 'total', 'item_caption' => 'item_caption'],
        'templates' => ['item_caption' => ['source' => $source, 'parts' => $parts]],
    ];
    $json = json_encode(['data' => [
        ['name' => 'Website redesign', 'done' => 12, 'total' => 16],
        ['name' => 'Billing', 'done' => 1, 'total' => 4],
    ]]);
    $presentation = ['bands' => [
        ['op' => '<=', 'value' => '30', 'color' => 'error', 'icon' => 'triangle-alert', 'label' => 'At risk'],
    ]];

    $result = lpEngine()->run($type, $plan, LosslessJson::decode($json), lpEngine()->guards(), $presentation);

    expect($result->slots['item_caption'])->toBe(['12/16 tasks', '1/4 tasks'])
        ->and(array_column($result->payload['rows'], 'caption'))->toBe(['12/16 tasks', '1/4 tasks'])
        ->and(array_column($result->payload['rows'], 'percent'))->toBe(['75', '25'])
        ->and($result->payload['rows'][0]['band'])->toBeNull()
        ->and($result->payload['rows'][1]['band'])->toBe(['text' => 'At risk', 'color' => 'error', 'icon' => 'triangle-alert']);
});

it('sums the numerators and denominators of a group before dividing', function () {
    $type = (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('progress-list', 1);
    $plan = [
        'v' => 1,
        'extract' => ['fields' => ['team' => '$.data[*].team', 'done' => '$.data[*].done', 'total' => '$.data[*].total']],
        'shape' => ['item_title' => 'team', 'progress_value' => 'done', 'progress_total' => 'total'],
        'group' => ['fields' => ['$.data[*].team']],
        'aggregate' => [
            ['field' => '$.data[*].done', 'function' => 'sum'],
            ['field' => '$.data[*].total', 'function' => 'sum'],
        ],
    ];
    $json = json_encode(['data' => [
        ['team' => 'A', 'done' => 1, 'total' => 2],
        ['team' => 'A', 'done' => 3, 'total' => 14],
        ['team' => 'B', 'done' => 5, 'total' => 5],
    ]]);

    $result = lpEngine()->run($type, $plan, LosslessJson::decode($json), lpEngine()->guards());

    expect(array_column($result->payload['rows'], 'percent'))->toBe(['25', '100']);
});
