<?php

use App\Platform\Json\LosslessJson;
use App\Platform\Json\SampleShape;

// Story 3.8: what the structure view shows, derived from the lossless tree.
function ssShape(string $json): array
{
    return SampleShape::of(LosslessJson::decode($json));
}

function ssNode(array $shape, string $path): array
{
    return array_column($shape['nodes'], null, 'path')[$path];
}

it('finds the records at the shallowest array of objects and counts distinct scalars and arrays', function () {
    $shape = ssShape('{"data":{"monthly":[{"month":"Jan","revenue":1.0},{"month":"Feb","revenue":2}],"total":3},"meta":{"pages":[{"n":1}]}}');

    expect($shape['records'])->toBe(['path' => '$.data.monthly[*]', 'display' => 'data.monthly[]', 'count' => 2])
        ->and($shape['scalars'])->toBe(4)
        ->and($shape['arrays'])->toBe(2);
});

it('finds records in a root array and reports none when there is no array of objects', function () {
    expect(ssShape('[{"a":1},{"a":2},{"a":3}]')['records'])->toBe(['path' => '$[*]', 'display' => '[]', 'count' => 3])
        ->and(ssShape('{"a":[1,2,3],"b":{"c":1}}')['records'])->toBeNull()
        ->and(ssShape('{"a":[]}')['records'])->toBeNull()
        ->and(ssShape('42')['nodes'][0])->toMatchArray(['path' => '$', 'type' => 'integer', 'example' => '42']);
});

it('keeps every number as its lexeme and joins the types seen at one path', function () {
    $shape = ssShape('{"v":[12345678901234567890.10,1.0,7,null,1e3,-0.50],"w":true}');

    expect(ssNode($shape, '$.v[*]'))->toMatchArray(['type' => 'integer | decimal | null', 'example' => '12345678901234567890.10'])
        ->and(ssNode($shape, '$.w'))->toMatchArray(['type' => 'boolean', 'example' => 'true'])
        ->and(ssNode($shape, '$.v'))->toMatchArray(['type' => 'array', 'count' => 6]);
});

it('lists nodes in document order with parents and depths, for a browser that folds branches', function () {
    $shape = ssShape('{"a":{"b":[{"c":1}]},"d":"x"}');

    expect(array_column($shape['nodes'], 'path'))->toBe(['$', '$.a', '$.a.b', '$.a.b[*]', '$.a.b[*].c', '$.d'])
        ->and(array_column($shape['nodes'], 'parent'))->toBe([null, 0, 1, 2, 3, 0])
        ->and(array_column($shape['nodes'], 'depth'))->toBe([0, 1, 2, 3, 4, 1]);
});

it('builds a table for an array of objects: columns from every record, the first rows as received', function () {
    $shape = ssShape('{"r":[{"a":1.10,"b":"x"},{"a":2,"c":{"d":1}},{"a":3,"c":[1]}]}');
    $table = $shape['tables'][ssNode($shape, '$.r')['id']];

    expect(array_column($table['columns'], 'key'))->toBe(['a', 'b', 'c'])
        ->and($table['total'])->toBe(3)
        ->and($table['rows'])->toBe([['1.10', 'x', ''], ['2', '', '{…}'], ['3', '', '[…]']]);
});

it('caps the table rows but reports every record, and cuts a long example', function () {
    $records = implode(',', array_map(fn (int $i): string => '{"n":'.$i.'}', range(1, SampleShape::TABLE_ROWS + 25)));
    $shape = ssShape('{"r":['.$records.'],"s":"'.str_repeat('é', 500).'"}');
    $table = $shape['tables'][ssNode($shape, '$.r')['id']];

    expect($table['rows'])->toHaveCount(SampleShape::TABLE_ROWS)->and($table['total'])->toBe(SampleShape::TABLE_ROWS + 25)
        ->and(ssNode($shape, '$.r')['count'])->toBe(SampleShape::TABLE_ROWS + 25)
        ->and(mb_strlen(ssNode($shape, '$.s')['example']))->toBe(SampleShape::EXAMPLE_CHARS + 1);
});

it('walks a very deep sample without recursing', function () {
    $shape = ssShape(str_repeat('[', 5000).'1'.str_repeat(']', 5000));

    expect($shape['arrays'])->toBe(5000)->and($shape['nodes'])->toHaveCount(5001);
});

it('shares one node between the same path of every record', function () {
    $shape = ssShape('{"r":[{"a":{"b":1}},{"a":{"b":2,"c":3}}]}');

    expect(array_column($shape['nodes'], 'path'))->toBe(['$', '$.r', '$.r[*]', '$.r[*].a', '$.r[*].a.b', '$.r[*].a.c']);
});
