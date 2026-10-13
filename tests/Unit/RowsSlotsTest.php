<?php

use App\Modules\BlockTypes\Application\LoadBlockTypes;
use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\InvalidBlockTypePackage;
use App\Modules\Mapping\Application\AutoMapper;
use App\Modules\Mapping\Application\DecimalMath;
use App\Modules\Mapping\Application\FieldRoleInference;
use App\Modules\Mapping\Contracts\ExecutionResult;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\Transforms;
use App\Modules\Mapping\Infrastructure\InProcessExecutor;
use App\Platform\Json\LosslessJson;
use App\Platform\Json\SampleShape;
use Illuminate\Config\Repository;

// Story 5.8a: a `rows` slot is the value of every row, and `repeat` declares a slot that may be filled up to N times (a chart's series).
function rsRoot(?Closure $change = null): string
{
    $root = sys_get_temp_dir().'/rows-slots-'.bin2hex(random_bytes(4));
    $dir = "{$root}/chart-test/v1";
    mkdir($dir, 0777, true);

    foreach (['Shaper.php', 'Renderer.vue'] as $file) {
        copy(dirname(__DIR__, 2).'/block-types/kpi-card/v1/'.$file, "{$dir}/{$file}");
    }

    $schema = [
        'key' => 'chart-test', 'contractVersion' => 1, 'title' => 'Chart test',
        'slots' => [
            ['key' => 'title', 'label' => 'Title', 'required' => false, 'valueType' => 'text', 'cardinality' => 'single', 'fieldRoles' => ['label']],
            ['key' => 'x', 'label' => 'X axis', 'required' => true, 'valueType' => 'datetime', 'cardinality' => 'rows', 'fieldRoles' => ['time', 'dimension']],
            ['key' => 'series', 'label' => 'Series', 'required' => true, 'valueType' => 'number', 'cardinality' => 'rows', 'repeat' => 3, 'fieldRoles' => ['value', 'measure']],
        ],
        'size' => ['defaultWidth' => 6, 'minWidth' => 3, 'maxWidth' => 12, 'defaultHeight' => 'medium', 'minHeight' => 'small', 'maxHeight' => 'large'],
        'defaultAggregations' => [],
    ];
    file_put_contents("{$dir}/schema.json", json_encode($change ? $change($schema) : $schema));

    return $root;
}

function rsType(): BlockType
{
    return (new LoadBlockTypes(rsRoot()))->resolve('chart-test', 1);
}

it('expands a repeated slot into numbered slots, the first as required as the declaration', function () {
    $slots = rsType()->toArray()['slots'];

    expect(array_column($slots, 'key'))->toBe(['title', 'x', 'series_1', 'series_2', 'series_3'])
        ->and(array_column($slots, 'label'))->toBe(['Title', 'X axis', 'Series 1', 'Series 2', 'Series 3'])
        ->and(array_column($slots, 'required'))->toBe([false, true, true, false, false])
        ->and($slots[2]['cardinality'])->toBe('rows');
});

it('refuses a repeat that is not a whole number from 2 to 6', function (mixed $repeat) {
    $root = rsRoot(function (array $schema) use ($repeat): array {
        $schema['slots'][2]['repeat'] = $repeat;

        return $schema;
    });

    expect(fn () => (new LoadBlockTypes($root))->resolve('chart-test', 1))->toThrow(InvalidBlockTypePackage::class);
})->with([1, 7, '3', 2.5]);

function rsRun(array $plan, string $json): ExecutionResult
{
    return (new InProcessExecutor)->execute($plan, LosslessJson::decode($json), rsType(), new PlanGuards(1000, 1000, 1000));
}

it('reads the value of every row for a rows slot, a row without one is null, never 0', function () {
    $plan = ['v' => 1, 'extract' => ['fields' => ['d' => '$.rows[*].day', 'a' => '$.rows[*].revenue', 'b' => '$.rows[*].cost']], 'shape' => ['x' => 'd', 'series_1' => 'a', 'series_2' => 'b']];
    $result = rsRun($plan, '{"rows":[{"day":"2026-01-01","revenue":10.5,"cost":4},{"day":"2026-01-02","revenue":12,"cost":null},{"day":"2026-01-03","revenue":"n/a","cost":6}]}');

    expect($result->slots['x'])->toBe(['2026-01-01', '2026-01-02', '2026-01-03'])
        ->and($result->slots['series_1'])->toBe(['10.5', '12', null])
        ->and($result->slots['series_2'])->toBe(['4', null, '6'])
        ->and($result->slots['series_3'])->toBeNull()
        ->and($result->unavailable)->toMatchArray(['series_3' => 'missing'])
        ->and($result->unavailable)->not->toHaveKey('series_1');
});

it('is unavailable when no row has a value of the type or the field is missing, and empty when the rows are there and none', function () {
    $plan = ['v' => 1, 'extract' => ['fields' => ['a' => '$.rows[*].revenue']], 'shape' => ['series_1' => 'a']];

    expect(rsRun($plan, '{"rows":[{"revenue":"n/a"},{"revenue":null}]}')->unavailable['series_1'])->toBe('wrong_type')
        ->and(rsRun($plan, '{}')->unavailable['series_1'])->toBe('missing');

    // Rows that are there and empty are an empty list, which is not a missing field.
    $empty = rsRun($plan, '{"rows":[]}');

    expect($empty->slots['series_1'])->toBe([])->and($empty->unavailable)->not->toHaveKey('series_1');
});

it('maps the axis and the series from the rows, one field to one slot, and offers only the rows to a rows slot', function () {
    $json = '{"rows":[{"day":"2026-01-01","revenue":10,"cost":4,"label":"a"},{"day":"2026-01-02","revenue":12,"cost":5,"label":"b"}],"total":22}';
    $report = (new FieldRoleInference)->infer(SampleShape::of(LosslessJson::decode($json)), null, []);
    $mapper = new AutoMapper;
    $result = $mapper->map($report, rsType(), []);

    expect($result->slot('x')['path'])->toBe('$.rows[*].day')
        ->and($result->slot('series_1')['path'])->toBe('$.rows[*].revenue')
        ->and($result->slot('series_2')['path'])->toBe('$.rows[*].cost')
        ->and($result->slot('series_3')['state'])->toBe('optional_empty')
        ->and($result->plan['shape'])->toBe(['x' => 'x', 'series_1' => 'series_1', 'series_2' => 'series_2']);

    $fields = $mapper->fieldsFor($report, rsType(), 'series_1');
    $compatible = array_column(array_filter($fields, fn (array $f): bool => $f['kind'] === 'field' && $f['compatible']), 'path');

    expect($compatible)->toContain('$.rows[*].revenue')->not->toContain('$.total');

    // A single value does not fill a rows slot, and says so.
    $decided = $mapper->map($report, rsType(), ['series_1' => ['path' => '$.total', 'state' => 'overridden']]);

    expect($decided->slot('series_1')['state'])->toBe('type_mismatch')
        ->and($decided->slot('series_1')['reason'])->toContain('every row');
});

// Story 5.9: the rows behind a rows slot are kept before the top-N stage, in the order the stages left them.
it('keeps the rows before top-N, sorted, while the slot holds only the cut', function () {
    [$steps] = Transforms::check([
        ['type' => 'sort', 'field' => '$.rows[*].revenue', 'direction' => 'desc'],
        ['type' => 'top_n', 'count' => 2],
    ]);
    $plan = Transforms::apply(
        ['v' => 1, 'extract' => ['fields' => ['d' => '$.rows[*].day', 'a' => '$.rows[*].revenue']], 'shape' => ['x' => 'd', 'series_1' => 'a']],
        $steps,
    );
    $executor = new InProcessExecutor(null, new DecimalMath(new Repository(['dashflow' => ['tunables' => ['numbers' => ['decimal_scale' => ['value' => '6']]]]])));
    $result = $executor->execute(
        $plan,
        LosslessJson::decode('{"rows":[{"day":"a","revenue":5},{"day":"b","revenue":9},{"day":"c","revenue":1},{"day":"d","revenue":7}]}'),
        rsType(),
        new PlanGuards(1000, 1000, 1000),
    );

    expect($result->slots['x'])->toBe(['b', 'd'])
        ->and($result->slots['series_1'])->toBe(['9', '7'])
        ->and($result->rowset['x'])->toBe(['b', 'd', 'a', 'c'])
        ->and($result->rowset['series_1'])->toBe(['9', '7', '5', '1']);
});
