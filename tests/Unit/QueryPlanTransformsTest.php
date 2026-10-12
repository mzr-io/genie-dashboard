<?php

use App\Modules\Blocks\Application\DraftConfig;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;
use App\Modules\Mapping\Application\QueryPlanEngine;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\QueryPlan;
use App\Modules\Mapping\Contracts\Transforms;
use App\Modules\Mapping\Infrastructure\InProcessExecutor;
use App\Modules\Mapping\Infrastructure\PreviewSettings;
use App\Platform\Json\LosslessJson;
use Illuminate\Config\Repository;

// Story 5.1: the filter, sort and top-N stages of Query Plan v1: fixed stage order, stable sort, exact numbers, never silent truncation.
function qtType(): BlockType
{
    return (new LoadBlockTypes(dirname(__DIR__, 2).'/block-types'))->resolve('kpi-card', 1);
}

function qtEngine(array $settings = []): QueryPlanEngine
{
    $preview = [];

    foreach ($settings + ['membership_limit' => '2', 'window_seconds' => '60', 'max_extracted_values' => '1000', 'max_evaluations' => '100', 'max_wall_ms' => '1000'] as $name => $value) {
        $preview[$name] = ['value' => $value];
    }

    return new QueryPlanEngine(new InProcessExecutor, new PackageShapers, new PreviewSettings(new Repository(['dashflow' => ['preview' => $preview]])));
}

/** @param  list<array<string, mixed>>  $rows */
function qtDocument(array $rows): mixed
{
    return LosslessJson::decode(json_encode(['data' => $rows], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
}

function qtPlan(array $steps, array $fields = ['rev' => '$.data[*].revenue', 'status' => '$.data[*].status'], array $shape = ['headline_value' => 'rev']): array
{
    [$ordered] = Transforms::check($steps);

    return Transforms::apply(['v' => 1, 'extract' => ['fields' => $fields], 'shape' => $shape], $ordered);
}

function qtRun(array $plan, mixed $document, ?PlanGuards $guards = null)
{
    $engine = qtEngine();

    return $engine->run(qtType(), $plan, $document, $guards ?? $engine->guards());
}

const QT_REVENUE = [10, 50, 30, 70, 20, 60, 40];

function qtRevenueRows(): array
{
    return array_map(fn (int $v, int $i): array => ['revenue' => $v, 'status' => $i % 2 === 0 ? 'Approved' : 'Pending'], QT_REVENUE, array_keys(QT_REVENUE));
}

it('sorts descending then keeps the top 5, the rows left being 70, 60, 50, 40, 30', function () {
    $plan = qtPlan([
        ['type' => 'top_n', 'count' => 5],
        ['type' => 'sort', 'field' => '$.data[*].revenue', 'direction' => 'desc'],
    ]);
    $result = qtRun($plan, qtDocument(qtRevenueRows()));

    expect($result->columns['rev'])->toBe(['70', '60', '50', '40', '30'])
        ->and($result->rows)->toBe(['input' => 7, 'steps' => [7, 5]]);
});

it('runs filter, sort and top-N in the fixed order whatever order the steps were added in', function () {
    $steps = [
        ['type' => 'top_n', 'count' => 2],
        ['type' => 'sort', 'field' => '$.data[*].revenue', 'direction' => 'desc'],
        ['type' => 'filter', 'field' => '$.data[*].status', 'operator' => 'is', 'value' => 'Approved'],
    ];
    [$ordered] = Transforms::check($steps);

    expect(array_column($ordered, 'type'))->toBe(['filter', 'sort', 'top_n']);

    $result = qtRun(qtPlan($steps), qtDocument(qtRevenueRows()));

    // Approved rows are 10, 30, 20, 40; by revenue descending 40, 30, 20, 10; the top 2 are 40 and 30.
    expect($result->columns['rev'])->toBe(['40', '30'])->and($result->rows)->toBe(['input' => 7, 'steps' => [4, 4, 2]]);
});

it('keeps source order for equal values, ascending and descending', function () {
    $rows = array_map(fn (array $r): array => $r, [
        ['revenue' => 5, 'status' => 'a'], ['revenue' => 5, 'status' => 'b'], ['revenue' => 9, 'status' => 'c'], ['revenue' => 5, 'status' => 'd'],
    ]);
    $fields = ['rev' => '$.data[*].revenue', 'status' => '$.data[*].status'];

    foreach (['asc' => ['a', 'b', 'd', 'c'], 'desc' => ['c', 'a', 'b', 'd']] as $direction => $expected) {
        $result = qtRun(qtPlan([['type' => 'sort', 'field' => '$.data[*].revenue', 'direction' => $direction]], $fields), qtDocument($rows));

        expect($result->columns['status'])->toBe($expected);
    }
});

it('compares numbers exactly, whatever their spelling', function () {
    $rows = [['revenue' => 1.10, 'status' => 'x'], ['revenue' => 1.1000000000000001, 'status' => 'y'], ['revenue' => 1e2, 'status' => 'z']];
    $document = LosslessJson::decode('{"data":[{"revenue":1.10,"status":"x"},{"revenue":1.1000000000000001,"status":"y"},{"revenue":1e2,"status":"z"}]}');
    $plan = qtPlan([['type' => 'filter', 'field' => '$.data[*].revenue', 'operator' => 'gt', 'value' => '1.1']]);
    $result = qtRun($plan, $document);

    expect($rows)->toHaveCount(3)->and($result->columns['status'])->toBe(['y', 'z']);
});

it('filters by contains and is not, and an empty result makes the slot unavailable, never 0', function () {
    $rows = qtRevenueRows();
    $contains = qtRun(qtPlan([['type' => 'filter', 'field' => '$.data[*].status', 'operator' => 'contains', 'value' => 'app']]), qtDocument($rows));
    $isNot = qtRun(qtPlan([['type' => 'filter', 'field' => '$.data[*].status', 'operator' => 'is_not', 'value' => 'Approved']]), qtDocument($rows));
    $none = qtRun(qtPlan([['type' => 'filter', 'field' => '$.data[*].status', 'operator' => 'is', 'value' => 'Rejected']]), qtDocument($rows));

    expect($contains->rows['steps'])->toBe([4])
        ->and($isNot->rows['steps'])->toBe([3])
        ->and($none->rows['steps'])->toBe([0])
        ->and($none->slots['headline_value'])->toBeNull()
        ->and($none->unavailable['headline_value'])->toBe('missing');
});

it('makes the dependent slots unavailable when a stage field is missing or does not suit the stage', function () {
    $document = qtDocument(qtRevenueRows());
    $missing = qtRun(qtPlan([['type' => 'filter', 'field' => '$.data[*].gone', 'operator' => 'is', 'value' => 'x']]), $document);
    $text = qtRun(qtPlan([['type' => 'filter', 'field' => '$.data[*].status', 'operator' => 'gt', 'value' => '3']]), $document);
    $badValue = qtRun(qtPlan([['type' => 'filter', 'field' => '$.data[*].revenue', 'operator' => 'gt', 'value' => 'abc']]), $document);
    $unsortable = qtRun(qtPlan([['type' => 'sort', 'field' => '$.data[*]', 'direction' => 'asc']]), $document);

    foreach ([$missing, $text, $badValue, $unsortable] as $result) {
        expect($result->slots['headline_value'])->toBeNull()->and($result->unavailable['headline_value'])->toBe('missing');
    }
});

it('judges the stages of a plan and refuses a malformed step, naming it', function () {
    $good = qtPlan([['type' => 'top_n', 'count' => 3]]);

    expect(QueryPlan::validate($good, qtType()))->toBe([]);

    $bad = $good;
    $bad['top_n'] = ['count' => 0];
    $bad['filter'] = [['field' => 'status', 'operator' => 'is', 'value' => 'x']];

    expect(array_keys(QueryPlan::validate($bad, qtType())))->toContain('config.query_plan.top_n.count');
    expect(fn () => qtEngine()->run(qtType(), $bad, qtDocument([]), qtEngine()->guards()))->toThrow(InvalidPlan::class);
});

it('judges the steps of the Draft whole and refuses what a script would need', function () {
    [, $errors] = Transforms::check([
        ['type' => 'filter', 'field' => '$.data[*].status', 'operator' => 'matches', 'value' => '.*'],
        ['type' => 'sort', 'field' => 'status', 'direction' => 'sideways'],
        ['type' => 'top_n', 'count' => 5000],
        ['type' => 'eval', 'code' => '1+1'],
    ]);

    expect(array_keys($errors))->toBe([
        'config.transforms.0.operator', 'config.transforms.1.field', 'config.transforms.1.direction', 'config.transforms.2.count', 'config.transforms.3',
    ]);

    [, $tooMany] = Transforms::check([['type' => 'top_n', 'count' => 1], ['type' => 'top_n', 'count' => 2]]);

    expect(array_keys($tooMany))->toBe(['config.transforms.1']);
});

it('keeps the transforms out of a Draft save', function () {
    expect(fn () => DraftConfig::normalise(['transforms' => []], qtType()))->toThrow(InvalidDraft::class);
});

it('aborts with a guard error when the rows are too many, and never truncates', function () {
    $rows = array_map(fn (int $i): array => ['revenue' => $i, 'status' => 'a'], range(1, 60));
    $engine = qtEngine(['max_extracted_values' => '100']);
    $plan = qtPlan([['type' => 'top_n', 'count' => 1]]);

    expect(fn () => $engine->run(qtType(), $plan, qtDocument($rows), $engine->guards()))->toThrow(GuardExceeded::class);
});
