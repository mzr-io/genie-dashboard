<?php

use BlockTypes\Bar\V1\Shaper as Bar;
use BlockTypes\PieDonut\V1\Shaper as Pie;

// Story 5.11: the bar and the pie-donut Shapers: pure, exact, a gap is a null, the rest folded exactly, nothing divided by zero.
it('shapes grouped bars from the category and value lists with each series named by its field', function () {
    $payload = (new Bar)->shape([
        'category' => ['North', 'South', 'West'],
        'values_1' => ['10.5', '20', null],
        'values_2' => ['1', '2', '3'],
        'title' => 'Sales',
    ], ['fieldNames' => ['values_1' => 'revenue', 'category' => 'region'], 'orientation' => 'horizontal', 'valueLabels' => true]);

    expect($payload['state'])->toBe('normal')
        ->and($payload['kind'])->toBe('grouped')
        ->and($payload['orientation'])->toBe('horizontal')
        ->and($payload['valueLabels'])->toBeTrue()
        ->and($payload['categories'])->toBe(['North', 'South', 'West'])
        ->and($payload['categoryLabel'])->toBe('region')
        ->and($payload['series'][0])->toBe(['key' => 'values_1', 'name' => 'revenue', 'values' => ['10.5', '20', null]])
        ->and($payload['series'][1]['name'])->toBe('Series 2')
        ->and($payload['partial'])->toBeTrue()
        ->and($payload['totals'])->toBe([]);
});

it('stacks with the exact total of each stack, and sorts by value with ties kept in data order', function () {
    $payload = (new Bar)->shape([
        'category' => ['A', 'B', 'C'],
        'values_1' => ['1.1', '5', '5'],
        'values_2' => ['2.2', '1', '0.5'],
    ], ['chartKind' => 'stacked', 'sort' => 'value']);

    expect($payload['kind'])->toBe('stacked')
        ->and($payload['categories'])->toBe(['B', 'C', 'A'])
        ->and($payload['totals'])->toBe(['6', '5.5', '3.3'])
        ->and($payload['series'][0]['values'])->toBe(['5', '5', '1.1']);
});

it('pivots long data by the series key and keeps negative and all-zero values with their categories', function () {
    $long = (new Bar)->shape([
        'category' => ['Q1', 'Q1', 'Q2', 'Q2'],
        'series_key' => ['EU', 'US', 'EU', 'US'],
        'values_1' => ['10', '20', '-5', '40'],
    ]);

    expect($long['categories'])->toBe(['Q1', 'Q2'])
        ->and(array_column($long['series'], 'name'))->toBe(['EU', 'US'])
        ->and($long['series'][0]['values'])->toBe(['10', '-5'])
        ->and($long['series'][1]['values'])->toBe(['20', '40']);

    $zero = (new Bar)->shape(['category' => ['A', 'B'], 'values_1' => ['0', '0']]);

    expect($zero['state'])->toBe('normal')
        ->and($zero['categories'])->toBe(['A', 'B'])
        ->and($zero['series'][0]['values'])->toBe(['0', '0']);
});

it('is unavailable without categories or values and empty with no value, never a zero', function () {
    $bar = new Bar;

    expect($bar->shape(['values_1' => ['1']])['state'])->toBe('unavailable')
        ->and($bar->shape(['category' => ['A']])['state'])->toBe('unavailable')
        ->and($bar->shape(['category' => ['A'], 'values_1' => [null]])['state'])->toBe('empty')
        ->and($bar->shape(['category' => [], 'values_1' => []])['state'])->toBe('empty');
});

it('orders the slices largest first, clockwise, with the data order on request', function () {
    $slots = ['pie_category' => ['A', 'B', 'C'], 'pie_value' => ['10', '30', '20']];
    $byValue = (new Pie)->shape($slots);
    $byData = (new Pie)->shape($slots, ['sort' => 'data']);

    expect($byValue['categories'])->toBe(['B', 'C', 'A'])
        ->and($byValue['values'])->toBe(['30', '20', '10'])
        ->and($byData['categories'])->toBe(['A', 'B', 'C'])
        ->and($byValue['kind'])->toBe('pie')
        ->and($byValue['centre'])->toBeNull();
});

it('folds the sixth slice and beyond into one Other that adds the rest exactly', function () {
    $names = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];
    $values = ['70', '60', '50', '40', '30', '20.25', '10.5'];
    $payload = (new Pie)->shape(['pie_category' => $names, 'pie_value' => $values]);

    expect($payload['categories'])->toBe(['A', 'B', 'C', 'D', 'E', 'Other'])
        ->and($payload['values'])->toBe(['70', '60', '50', '40', '30', '30.75'])
        ->and($payload['folded'])->toBeTrue();

    $six = (new Pie)->shape(['pie_category' => array_slice($names, 0, 6), 'pie_value' => array_slice($values, 0, 6)]);

    expect($six['categories'])->toHaveCount(6)->and($six['folded'])->toBeFalse();
});

it('puts a donut centre as the largest value or the exact total, with its label', function () {
    $slots = ['pie_category' => ['A', 'B'], 'pie_value' => ['10.5', '20.25'], 'centre_label' => 'Revenue'];

    expect((new Pie)->shape($slots, ['chartKind' => 'donut', 'centre' => 'total'])['centre'])->toBe(['label' => 'Revenue', 'value' => '30.75'])
        ->and((new Pie)->shape($slots, ['chartKind' => 'donut', 'centre' => 'value'])['centre']['value'])->toBe('20.25')
        ->and((new Pie)->shape($slots, ['chartKind' => 'donut', 'centre' => 'none'])['centre'])->toBeNull()
        ->and((new Pie)->shape($slots, ['chartKind' => 'pie', 'centre' => 'total'])['centre'])->toBeNull();
});

it('shows a pie with nothing positive as empty and keeps hostile names literal', function () {
    $empty = (new Pie)->shape(['pie_category' => ['A'], 'pie_value' => ['0']]);
    $hostile = (new Pie)->shape(['pie_category' => ['<img src=x onerror=alert(1)>'], 'pie_value' => ['5']]);

    expect($empty['state'])->toBe('empty')
        ->and($hostile['categories'])->toBe(['<img src=x onerror=alert(1)>']);
});
