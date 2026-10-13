<?php

use BlockTypes\Table\V1\Shaper as Table;

// Story 5.12: the table Shaper: pure, columns named by rule, else by field, a gap a null, row keys stable and unique, nothing formatted.
it('shapes the columns from the rules and the fields, and keeps a gap as null with the partial flag', function () {
    $payload = (new Table)->shape([
        'title' => 'Orders',
        'col_1' => ['Acme', 'Beta', 'Core'],
        'col_2' => ['10.50', null, '3'],
        'col_4' => ['2026-01-02', '2026-02-03', '2026-03-04'],
    ], [
        'fieldNames' => ['col_1' => 'customer', 'col_2' => 'amount'],
        'columnRules' => [
            'col_2' => ['sortable' => true, 'width' => 120, 'label' => ['text' => 'Amount (USD)'], 'format' => ['kind' => 'currency', 'currencyCode' => 'usd']],
            'col_4' => ['align' => 'center', 'format' => ['kind' => 'date']],
        ],
        'pageSize' => 25,
    ]);

    expect($payload['state'])->toBe('normal')
        ->and($payload['title'])->toBe('Orders')
        ->and(array_column($payload['columns'], 'key'))->toBe(['col_1', 'col_2', 'col_4'])
        ->and(array_column($payload['columns'], 'header'))->toBe(['customer', 'Amount (USD)', 'Column 4'])
        ->and(array_column($payload['columns'], 'align'))->toBe(['left', 'right', 'center'])
        ->and($payload['columns'][1]['width'])->toBe(120)
        ->and($payload['columns'][1]['sortable'])->toBeTrue()
        ->and($payload['columns'][0]['sortable'])->toBeFalse()
        ->and($payload['columns'][1]['format']['currencyCode'])->toBe('USD')
        ->and($payload['rows'][1]['cells'])->toBe(['Beta', null, '2026-02-03'])
        ->and($payload['partial'])->toBeTrue()
        ->and($payload['pageSize'])->toBe(25);
});

it('keys a row by the mapped key, else a hash of its cells, made unique and never by position', function () {
    $keyed = (new Table)->shape([
        'col_1' => ['a', 'b', 'c'],
        'row_key' => ['x1', 'x2', 'x1'],
    ]);

    expect(array_column($keyed['rows'], 'key'))->toBe(['k:x1', 'k:x2', 'k:x1#2']);

    $plain = (new Table)->shape(['col_1' => ['a', 'b', 'a'], 'col_2' => ['1', '2', '1']]);
    $reordered = (new Table)->shape(['col_1' => ['b', 'a', 'a'], 'col_2' => ['2', '1', '1']]);
    $keys = array_column($plain['rows'], 'key');

    expect($keys[0])->toStartWith('h:')
        ->and($keys[2])->toBe($keys[0].'#2')
        ->and(count(array_unique($keys)))->toBe(3)
        ->and($reordered['rows'][0]['key'])->toBe($keys[1]);
});

it('carries the band of each row and drops one that is not a valid band', function () {
    $payload = (new Table)->shape(['col_1' => ['a', 'b', 'c'], 'col_2' => ['1', '2', '3']], [
        'rowBands' => [
            ['text' => 'At risk', 'color' => 'warning', 'icon' => 'triangle-alert'],
            null,
            ['text' => 'Bad', 'color' => 'chartreuse', 'icon' => 'x'],
        ],
    ]);

    expect($payload['rows'][0]['highlight'])->toBe(['text' => 'At risk', 'color' => 'warning', 'icon' => 'triangle-alert'])
        ->and($payload['rows'][1]['highlight'])->toBeNull()
        ->and($payload['rows'][2]['highlight'])->toBeNull();
});

it('is unavailable without a column and empty without rows, and falls back to ten rows a page', function () {
    $table = new Table;

    expect($table->shape([])['state'])->toBe('unavailable')
        ->and($table->shape(['col_1' => []])['state'])->toBe('empty')
        ->and($table->shape(['col_1' => ['a']], ['pageSize' => 0])['pageSize'])->toBe(10)
        ->and($table->shape(['col_1' => ['a']], ['state' => 'error'])['state'])->toBe('error');
});
