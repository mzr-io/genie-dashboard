<?php

use App\Modules\Blocks\Application\DraftConfig;
use App\Modules\Blocks\Contracts\InvalidDraft;
use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Infrastructure\PackageShapers;
use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Application\Expressions\ExpressionParser;
use App\Modules\Mapping\Application\Expressions\ExpressionResolver;
use App\Modules\Mapping\Application\Expressions\TextTemplate;
use App\Modules\Mapping\Application\QueryPlanEngine;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\QueryPlan;
use App\Modules\Mapping\Contracts\SettingNotConfigured;
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

function qtEngine(array $settings = [], string $scale = '6'): QueryPlanEngine
{
    $preview = [];

    foreach ($settings + ['membership_limit' => '2', 'window_seconds' => '60', 'max_extracted_values' => '1000', 'max_evaluations' => '100', 'max_wall_ms' => '1000'] as $name => $value) {
        $preview[$name] = ['value' => $value];
    }

    $config = new Repository(['dashflow' => ['preview' => $preview, 'tunables' => ['numbers' => ['decimal_scale' => ['value' => $scale]]]]]);

    return new QueryPlanEngine(new InProcessExecutor(null, new DecimalMath($config)), new PackageShapers, new PreviewSettings($config));
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

// Story 5.2: Group and Aggregate, in the fixed order filter, group, aggregate, sort, top-N.
function qgRows(): array
{
    $regions = ['EU', 'US', 'APAC', 'EU', 'US', 'EU', 'APAC', 'LATAM'];

    return array_map(fn (string $r, int $i): array => ['region' => $r, 'revenue' => ($i + 1) * 10, 'status' => $i % 4 === 0 ? 'Rejected' : 'Approved'], $regions, array_keys($regions));
}

function qgPlan(array $steps, array $shape = ['headline_value' => 'rev']): array
{
    return qtPlan($steps, ['rev' => '$.data[*].revenue', 'region' => '$.data[*].region'], $shape);
}

it('groups by a field, sums per group, sorts descending and keeps the top 2, in the fixed order', function () {
    $steps = [
        ['type' => 'top_n', 'count' => 2],
        ['type' => 'sort', 'field' => '$.data[*].revenue', 'direction' => 'desc'],
        ['type' => 'aggregate', 'function' => 'sum', 'field' => '$.data[*].revenue'],
        ['type' => 'group', 'fields' => ['$.data[*].region']],
    ];
    [$ordered] = Transforms::check($steps);

    expect(array_column($ordered, 'type'))->toBe(['group', 'aggregate', 'sort', 'top_n']);

    $result = qtRun(qgPlan($steps), qtDocument(qgRows()));

    // EU 10+40+60=110, US 20+50=70, APAC 30+70=100, LATAM 80; by revenue descending: EU 110, APAC 100, LATAM 80, US 70.
    expect($result->columns['region'])->toBe(['EU', 'APAC'])
        ->and($result->columns['rev'])->toBe(['110', '100'])
        ->and($result->rows)->toBe(['input' => 8, 'steps' => [4, 4, 4, 2]]);
});

it('offers exactly sum, count, avg, min and max and computes each per group', function () {
    expect(Transforms::FUNCTIONS)->toBe(['sum', 'count', 'avg', 'min', 'max']);

    $expect = ['sum' => ['110', '70', '100', '80'], 'count' => ['3', '2', '2', '1'], 'avg' => ['36.666666', '35', '50', '80'], 'min' => ['10', '20', '30', '80'], 'max' => ['60', '50', '70', '80']];

    foreach ($expect as $function => $values) {
        $result = qtRun(qgPlan([
            ['type' => 'group', 'fields' => ['$.data[*].region']],
            ['type' => 'aggregate', 'function' => $function, 'field' => '$.data[*].revenue'],
        ]), qtDocument(qgRows()));

        expect($result->columns['rev'])->toBe($values, $function)->and($result->columns['region'])->toBe(['EU', 'US', 'APAC', 'LATAM']);
    }
});

it('filters first and counts the rows of each stage', function () {
    $result = qtRun(qgPlan([
        ['type' => 'filter', 'field' => '$.data[*].status', 'operator' => 'is', 'value' => 'Approved'],
        ['type' => 'group', 'fields' => ['$.data[*].region']],
        ['type' => 'aggregate', 'function' => 'count', 'field' => '$.data[*].revenue'],
    ]), qtDocument(qgRows()));

    // Rejected are rows 1 and 5 (EU and US); the Approved 6 rows make 4 regions.
    expect($result->rows)->toBe(['input' => 8, 'steps' => [6, 4, 4]]);
});

it('sums digits no float can hold exactly and sends them as a decimal string', function () {
    $document = LosslessJson::decode('{"data":[{"region":"EU","revenue":12345678901234567.89},{"region":"EU","revenue":0.01},{"region":"EU","revenue":1e2}]}');
    $result = qtRun(qgPlan([
        ['type' => 'group', 'fields' => ['$.data[*].region']],
        ['type' => 'aggregate', 'function' => 'sum', 'field' => '$.data[*].revenue'],
    ]), $document);

    // 12345678901234567.89 + 0.01 + 100 = 12345678901234667.90, written without the zero the scale padded.
    expect($result->columns['rev'])->toBe(['12345678901234667.9'])->and($result->slots['headline_value'])->toBe('12345678901234667.9');
});

it('aggregates the whole table when nothing is grouped, and makes an empty table unavailable', function () {
    $whole = qtRun(qgPlan([['type' => 'aggregate', 'function' => 'sum', 'field' => '$.data[*].revenue']]), qtDocument(qgRows()));
    $none = qtRun(qgPlan([
        ['type' => 'filter', 'field' => '$.data[*].status', 'operator' => 'is', 'value' => 'Nothing'],
        ['type' => 'aggregate', 'function' => 'sum', 'field' => '$.data[*].revenue'],
    ]), qtDocument(qgRows()));

    expect($whole->slots['headline_value'])->toBe('360')
        ->and($whole->rows)->toBe(['input' => 8, 'steps' => [1]])
        ->and($none->slots['headline_value'])->toBeNull()->and($none->unavailable['headline_value'])->toBe('missing');
});

it('makes slots unavailable when a column cannot be summed, and never reads a missing value as 0', function () {
    $text = qtRun(qgPlan([
        ['type' => 'group', 'fields' => ['$.data[*].status']],
        ['type' => 'aggregate', 'function' => 'sum', 'field' => '$.data[*].region'],
    ], ['headline_value' => 'rev']), qtDocument(qgRows()));
    $gone = qtRun(qgPlan([['type' => 'aggregate', 'function' => 'sum', 'field' => '$.data[*].gone']]), qtDocument(qgRows()));
    $nulls = qtRun(qgPlan([['type' => 'aggregate', 'function' => 'sum', 'field' => '$.data[*].revenue']]), LosslessJson::decode('{"data":[{"region":"EU","revenue":null},{"region":"US","revenue":null}]}'));

    foreach ([$text, $gone, $nulls] as $result) {
        expect($result->slots['headline_value'])->toBeNull()->and($result->unavailable['headline_value'])->toBe('missing');
    }
});

it('judges group and aggregate steps whole and refuses a field both grouped and aggregated, or a sort on a dropped field', function () {
    [, $errors] = Transforms::check([
        ['type' => 'group', 'fields' => ['$.data[*].region', '$.data[*].region']],
        ['type' => 'aggregate', 'function' => 'median', 'field' => '$.data[*].revenue'],
    ]);

    expect(array_keys($errors))->toBe(['config.transforms.0.fields', 'config.transforms.1.function']);

    [, $both] = Transforms::check([
        ['type' => 'group', 'fields' => ['$.data[*].region']],
        ['type' => 'aggregate', 'function' => 'sum', 'field' => '$.data[*].region'],
        ['type' => 'sort', 'field' => '$.data[*].other', 'direction' => 'asc'],
    ]);

    expect(array_keys($both))->toBe(['config.transforms.1.field', 'config.transforms.2.field']);
    expect(QueryPlan::validate(qgPlan([['type' => 'group', 'fields' => ['$.data[*].region']]]), qtType()))->toBe([]);
});

it('aborts with a guard error when grouping makes more groups than the limit, and never truncates', function () {
    $rows = array_map(fn (int $i): array => ['region' => 'r'.$i, 'revenue' => $i, 'status' => 'a'], range(1, 20));
    $engine = qtEngine(['max_groups' => '5']);
    $plan = qgPlan([['type' => 'group', 'fields' => ['$.data[*].region']], ['type' => 'aggregate', 'function' => 'sum', 'field' => '$.data[*].revenue']]);

    expect(fn () => $engine->run(qtType(), $plan, qtDocument($rows), $engine->guards()))->toThrow(GuardExceeded::class);
});

it('refuses SUM and AVG while the decimal scale is not configured, naming it', function () {
    $engine = qtEngine([], '');
    $plan = qgPlan([['type' => 'aggregate', 'function' => 'sum', 'field' => '$.data[*].revenue']]);

    expect(fn () => $engine->run(qtType(), $plan, qtDocument(qgRows()), $engine->guards()))->toThrow(SettingNotConfigured::class);
});

// Story 5.3: calculated fields at the row and aggregate stages, and ratios that survive grouping.
function qcCalc(string $name, string $source, string $level, array $names): array
{
    $ast = ExpressionResolver::resolve(ExpressionParser::parse($source), $names);

    return ['type' => 'calc', 'name' => $name, 'source' => $source, 'level' => $level, 'ast' => $ast];
}

const QC_NAMES = ['revenue' => '$.data[*].revenue', 'cost' => '$.data[*].cost', 'region' => '$.data[*].region', 'done' => '$.data[*].done', 'total' => '$.data[*].total'];

function qcRows(): array
{
    return [
        ['region' => 'EU', 'revenue' => 200, 'cost' => 150, 'done' => 600, 'total' => 1000],
        ['region' => 'EU', 'revenue' => 0, 'cost' => 10, 'done' => 450, 'total' => 500],
        ['region' => 'US', 'revenue' => 400, 'cost' => 100, 'done' => 5, 'total' => 10],
    ];
}

function qcPlan(array $steps, array $shape = ['headline_value' => 'm']): array
{
    return qtPlan($steps, ['m' => 'calc:margin', 'region' => '$.data[*].region'], $shape);
}

it('evaluates a row-level calculated field per row, with a safe division by 0 as null', function () {
    $result = qtRun(qcPlan([qcCalc('margin', '(revenue - cost) / revenue', 'row', QC_NAMES)]), qtDocument(qcRows()));

    expect($result->columns['m'])->toBe(['0.25', null, '0.75'])->and($result->rows)->toBe(['input' => 3, 'steps' => [3]]);
});

it('runs row calc before the group and aggregate calc after the aggregate, each at its fixed position', function () {
    $steps = [
        qcCalc('share', 'total_margin / 2', 'aggregate', QC_NAMES + ['total_margin' => '$.data[*].revenue']),
        ['type' => 'aggregate', 'function' => 'sum', 'field' => '$.data[*].revenue'],
        ['type' => 'group', 'fields' => ['$.data[*].region']],
        qcCalc('margin', 'revenue - cost', 'row', QC_NAMES),
    ];
    [$ordered] = Transforms::check($steps);

    expect(array_map(fn (array $s): string => $s['type'].':'.($s['name'] ?? ''), $ordered))->toBe(['calc:margin', 'group:', 'aggregate:', 'calc:share']);

    $plan = Transforms::apply(['v' => 1, 'extract' => ['fields' => ['m' => 'calc:share', 'region' => '$.data[*].region']], 'shape' => []], $ordered);

    expect(array_keys($plan))->toEqualCanonicalizing(['v', 'extract', 'shape', 'row_calc', 'group', 'aggregate', 'aggregate_calc']);

    $result = qtRun($plan, qtDocument(qcRows()));

    // revenue per region: EU 200, US 400; share = revenue / 2.
    expect($result->columns['m'])->toBe(['100', '200'])->and($result->rows['steps'])->toBe([3, 2, 2, 2]);
});

it('aggregates a ratio from the sums, 70% and not the 75% of averaging 60% and 90%', function () {
    $rows = [['region' => 'EU', 'done' => 600, 'total' => 1000], ['region' => 'EU', 'done' => 450, 'total' => 500]];
    $ratio = ['type' => 'calc', 'name' => 'margin', 'level' => 'aggregate', 'ratio' => ['numerator' => '$.data[*].done', 'denominator' => '$.data[*].total']];
    $result = qtRun(qcPlan([['type' => 'group', 'fields' => ['$.data[*].region']], $ratio]), qtDocument($rows));

    expect($result->columns['m'])->toBe(['70'])->and($result->slots['headline_value'])->toBe('70');

    // The ratio of one group with a zero denominator has no value.
    $zero = qtRun(qcPlan([['type' => 'group', 'fields' => ['$.data[*].region']], $ratio]), qtDocument([['region' => 'EU', 'done' => 5, 'total' => 0]]));

    expect($zero->slots['headline_value'])->toBeNull()->and($zero->unavailable['headline_value'])->toBe('missing');
});

it('makes the slot unavailable when a calculated field cannot be made, never 0', function () {
    $text = qtRun(qcPlan([qcCalc('margin', 'region + 1', 'row', QC_NAMES)]), qtDocument(qcRows()));
    $gone = qtRun(qcPlan([qcCalc('margin', 'ghost + 1', 'row', QC_NAMES + ['ghost' => '$.data[*].ghost'])]), qtDocument(qcRows()));

    foreach ([$text, $gone] as $result) {
        expect($result->slots['headline_value'])->toBeNull()->and($result->unavailable['headline_value'])->toBe('missing');
    }
});

it('reads a calculated field over scalar fields as one value, like PCT_CHANGE(total, previous_total)', function () {
    $document = LosslessJson::decode('{"total":284680,"previous_total":249195}');
    $plan = qtPlan(
        [qcCalc('change', 'PCT_CHANGE(total, previous_total)', 'row', ['total' => '$.total', 'previous_total' => '$.previous_total'])],
        ['c' => 'calc:change'],
        ['headline_value' => 'c'],
    );
    $result = qtRun($plan, $document);

    expect($result->slots['headline_value'])->toBe('14.239852')->and($result->rows)->toBe(['input' => 1, 'steps' => [1]]);
});

it('judges calculated field steps whole: a name, a level, an expression or a ratio, and a ratio only at the aggregate level', function () {
    [, $errors] = Transforms::check([
        ['type' => 'calc', 'name' => '1bad', 'level' => 'sideways', 'source' => 'a + 1'],
        ['type' => 'calc', 'name' => 'r', 'level' => 'row', 'ratio' => ['numerator' => 'a', 'denominator' => 'b']],
        ['type' => 'calc', 'name' => 'e', 'level' => 'row', 'source' => ''],
        ['type' => 'calc', 'name' => 'r2', 'level' => 'aggregate', 'ratio' => ['numerator' => 'a']],
    ]);

    expect(array_keys($errors))->toBe(['config.transforms.0.name', 'config.transforms.0.level', 'config.transforms.1.ratio', 'config.transforms.2.source', 'config.transforms.3.ratio']);
});

// Story 5.4: Text Templates rendered to their wire form.
function qtTemplatePlan(string $source, array $names, string $slot = 'kpi_label'): array
{
    $parts = TextTemplate::resolve(TextTemplate::parse($source), $names);

    return [
        'v' => 1,
        'extract' => ['fields' => [$slot => 'tpl:'.$slot]],
        'shape' => [$slot => $slot],
        'templates' => [$slot => ['source' => $source, 'parts' => $parts]],
    ];
}

function qtTemplateRun(array $plan, string $json, ?array $period = null)
{
    $engine = qtEngine();

    return $engine->run(qtType(), $plan, LosslessJson::decode($json), $engine->guards(), [], $period);
}

it('renders field tokens, formats, the row count, the period and the viewer as the wire form', function () {
    $plan = qtTemplatePlan('{ROW_COUNT} requests · {category} · {revenue|currency} · {period.start|MMM d} – {period.end|MMM d, yyyy} · {user.name}', [
        'category' => '$.category', 'revenue' => '$.revenue',
    ]);
    $result = qtTemplateRun($plan, '{"category":"Ops","revenue":1234.50,"rows":[{"a":1},{"a":2}]}', ['start' => '2026-05-01', 'end' => '2026-05-31']);

    // Rows: the longest list any path selected; with none beyond single values it is one.
    expect($result->slots['kpi_label'])->toBe('{#1|integer} requests · Ops · {#1234.50|currency} · {@2026-05-01|MMM d} – {@2026-05-31|MMM d, yyyy} · {user.name}');
});

it('counts the rows that are left after the transforms', function () {
    $plan = qtTemplatePlan('{ROW_COUNT} approved', ['status' => '$.data[*].status']);
    $plan['filter'] = [['field' => '$.data[*].status', 'operator' => 'is', 'value' => 'Approved']];
    $result = qtTemplateRun($plan, json_encode(['data' => [['status' => 'Approved'], ['status' => 'Rejected'], ['status' => 'Approved']]]));

    expect($result->slots['kpi_label'])->toBe('{#2|integer} approved');
});

it('writes a null, missing or non-scalar value as the empty marker, never 0 or undefined', function () {
    $plan = qtTemplatePlan('A {gone} B {nothing} C {obj} D {period.start|MMM d}', ['gone' => '$.gone', 'nothing' => '$.nothing', 'obj' => '$.obj']);
    $result = qtTemplateRun($plan, '{"nothing":null,"obj":{"x":1}}');

    expect($result->slots['kpi_label'])->toBe('A {-} B {-} C {-} D {-}');
});

it('doubles every brace in data so a value can never become a token', function () {
    $plan = qtTemplatePlan('{note}', ['note' => '$.note']);
    $result = qtTemplateRun($plan, '{"note":"<img src=x onerror=alert(1)> {user.name} {#9|currency}"}');

    expect($result->slots['kpi_label'])->toBe('<img src=x onerror=alert(1)> {{user.name}} {{#9|currency}}');
});

it('reads one value of aggregated rows and formats a date field', function () {
    $plan = qtTemplatePlan('{done}/{total} tasks · {day|date}', ['done' => '$.data[*].done', 'total' => '$.data[*].total', 'day' => '$.day']);
    $plan['aggregate'] = [['function' => 'sum', 'field' => '$.data[*].done'], ['function' => 'sum', 'field' => '$.data[*].total']];
    $result = qtTemplateRun($plan, '{"day":"2026-05-03T10:00:00Z","data":[{"done":3,"total":5},{"done":4,"total":5}]}');

    expect($result->slots['kpi_label'])->toBe('7/10 tasks · {@2026-05-03|MMM d, yyyy}');
});
