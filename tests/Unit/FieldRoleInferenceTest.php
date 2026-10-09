<?php

use App\Modules\Mapping\Application\FieldRoleInference;
use App\Platform\Json\LosslessJson;
use App\Platform\Json\SampleShape;

// Story 3.11a: field roles and record-path candidates over a sample's structure.
function friInfer(string $json, ?string $path = null, array $overrides = []): array
{
    return (new FieldRoleInference)->infer(SampleShape::of(LosslessJson::decode($json)), $path, $overrides)->toArray();
}

function friField(array $report, string $path): array
{
    return array_column($report['fields'], null, 'path')[$path];
}

function friRows(int $n): string
{
    return implode(',', array_fill(0, $n, '{"id":7,"name":"A","revenue":12.50,"date":"2026-01-31"}'));
}

it('gives a single array of records one suggested candidate and roles by type and name', function () {
    $r = friInfer('{"data":['.friRows(3).']}');

    expect($r['candidates'])->toHaveCount(1)
        ->and($r['candidates'][0])->toMatchArray(['path' => '$.data[*]', 'rows' => 3, 'suggested' => true, 'pagination_like' => false, 'columns' => ['id', 'name', 'revenue', 'date']])
        ->and($r['needs_choice'])->toBeFalse()
        ->and($r['record_path'])->toBe('$.data[*]')
        ->and(friField($r, '$.data[*].id'))->toMatchArray(['role' => 'dimension', 'confidence' => 'high', 'overridden' => false])
        ->and(friField($r, '$.data[*].name'))->toMatchArray(['role' => 'label', 'confidence' => 'high'])
        ->and(friField($r, '$.data[*].revenue'))->toMatchArray(['role' => 'measure', 'confidence' => 'high', 'suggestion' => null])
        ->and(friField($r, '$.data[*].date'))->toMatchArray(['role' => 'time', 'confidence' => 'high']);
});

it('asks for a choice with several arrays and never suggests a pagination-like one', function () {
    $r = friInfer('{"data":['.friRows(40).'],"meta":{"links":[{"href":"a"},{"href":"b"},{"href":"c"}]}}');
    $by = array_column($r['candidates'], null, 'path');

    expect($r['needs_choice'])->toBeTrue()
        ->and($by['$.data[*]']['suggested'])->toBeTrue()
        ->and($by['$.meta.links[*]'])->toMatchArray(['suggested' => false, 'pagination_like' => true, 'rows' => 3]);

    // Even with more rows, a pagination-like array loses while another exists.
    $r = friInfer('{"links":[{"h":1},{"h":2},{"h":3}],"data":[{"a":1}]}');
    expect(array_column($r['candidates'], 'suggested', 'path'))->toBe(['$.links[*]' => false, '$.data[*]' => true]);
});

it('breaks a tie on rows by the shallower then the first, and honours a chosen path', function () {
    $r = friInfer('{"a":{"b":[{"x":1}]},"c":[{"y":1}],"d":[{"z":1}]}');

    expect(array_column($r['candidates'], 'suggested', 'path'))->toBe(['$.a.b[*]' => false, '$.c[*]' => true, '$.d[*]' => false]);

    $chosen = friInfer('{"a":{"b":[{"x":1}]},"c":[{"y":1}]}', '$.a.b[*]');
    expect($chosen['record_path'])->toBe('$.a.b[*]')
        ->and(friField($chosen, '$.a.b[*].x')['in_records'])->toBeTrue()
        ->and(friField($chosen, '$.c[*].y')['in_records'])->toBeFalse();
});

it('needs a choice when a candidate is nested deeper than 2 levels', function () {
    expect(friInfer('{"a":{"b":{"c":[{"x":1}]}}}')['needs_choice'])->toBeTrue()
        ->and(friInfer('{"a":{"b":[{"x":1}]}}')['needs_choice'])->toBeFalse();
});

it('suggests measure for a numeric dimension whose name says so, without applying it', function () {
    $r = friInfer('{"rows":[{"revenue_id":5,"region":"N","is_open":true,"total_code":9,"sales_rank":4}],"x":[{"a":1}]}', null, []);

    expect(friField($r, '$.rows[*].revenue_id'))->toMatchArray(['role' => 'dimension', 'suggestion' => null])
        ->and(friField($r, '$.rows[*].total_code'))->toMatchArray(['role' => 'dimension', 'suggestion' => null])
        ->and(friField($r, '$.rows[*].region'))->toMatchArray(['role' => 'dimension', 'confidence' => 'low', 'suggestion' => null])
        ->and(friField($r, '$.rows[*].is_open'))->toMatchArray(['role' => 'filter', 'suggestion' => null]);
});

it('lets an override win: overridden, high and without a suggestion', function () {
    $r = friInfer('{"rows":[{"revenue":1.5,"name":"x"}]}', null, ['$.rows[*].revenue' => 'dimension', '$.rows[*].name' => 'bogus']);

    expect(friField($r, '$.rows[*].revenue'))->toMatchArray(['role' => 'dimension', 'overridden' => true, 'confidence' => 'high', 'suggestion' => null])
        ->and(friField($r, '$.rows[*].name'))->toMatchArray(['role' => 'label', 'overridden' => false]);
});

it('treats scalars outside the records as value and label, and a scalar-only response has no candidates', function () {
    $r = friInfer('{"summary":{"total":284680,"currency":"GBP"}}');

    expect($r['candidates'])->toBe([])
        ->and($r['record_path'])->toBeNull()
        ->and($r['needs_choice'])->toBeFalse()
        ->and(friField($r, '$.summary.total'))->toMatchArray(['role' => 'value', 'confidence' => 'high'])
        ->and(friField($r, '$.summary.currency'))->toMatchArray(['role' => 'label', 'confidence' => 'low']);
});

it('recognises time by ISO example or key, and keeps numbers as lexemes', function () {
    $r = friInfer('{"rows":[{"created_at":"yesterday","when":"2026-01-31T10:00:00Z","updatedAt":"x","amount":12345678901234567890.10}]}');

    expect(friField($r, '$.rows[*].created_at')['role'])->toBe('time')
        ->and(friField($r, '$.rows[*].when'))->toMatchArray(['role' => 'time', 'confidence' => 'low'])
        ->and(friField($r, '$.rows[*].updatedAt')['role'])->toBe('time')
        ->and(friField($r, '$.rows[*].amount'))->toMatchArray(['role' => 'measure', 'example' => '12345678901234567890.10']);
});

it('is deterministic', function () {
    $json = '{"data":['.friRows(2).'],"meta":{"page":[{"n":1}]}}';

    expect(friInfer($json))->toBe(friInfer($json));
});

it('echoes a record path that is not in the sample and puts no field in the records', function () {
    $r = friInfer('{"data":[{"a":1}]}', '$.gone[*]');

    expect($r['record_path'])->toBe('$.gone[*]')
        ->and(array_unique(array_column($r['fields'], 'in_records')))->toBe([false]);
});

it('still suggests a pagination-like array when it is the only one', function () {
    $r = friInfer('{"links":[{"h":1},{"h":2}]}');

    expect($r['candidates'][0])->toMatchArray(['path' => '$.links[*]', 'pagination_like' => true, 'suggested' => true])
        ->and($r['record_path'])->toBe('$.links[*]');
});
