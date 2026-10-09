<?php

use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\JsonObject;
use App\Platform\Json\LosslessJson;
use App\Platform\Json\SamplePath;
use Symfony\Component\JsonPath\JsonCrawler;

// Story 3.8: stored paths are the RFC 9535 subset (member names and `[*]` only). A conformance suite compares our evaluator with
// `symfony/json-path` (tests may call json_decode; the app may not).

const SP_DOCUMENT = <<<'JSON'
{
  "data": {
    "monthly": [
      {"month": "Jan", "revenue": 12345678901234567890.10, "rate": 1.0, "tags": ["a", "b"]},
      {"month": "Feb", "revenue": 2.50, "rate": null},
      {"month": "Mar", "revenue": 3}
    ],
    "currency": "USD"
  },
  "odd key": 1,
  "it's": 2,
  "a.b": 3,
  "": 4,
  "é": 5,
  "nested": [[1, 2], [3], []],
  "empty": [],
  "obj": {"x": {"y": [10, 20]}, "z": true},
  "flag": false,
  "nothing": null
}
JSON;

function spNative(mixed $value): mixed
{
    if ($value instanceof DecimalLiteral) {
        return json_decode($value->lexeme);
    }

    if ($value instanceof JsonObject) {
        $map = [];

        foreach ($value->entries() as [$key, $member]) {
            $map[$key] = spNative($member);
        }

        return $map;
    }

    return is_array($value) ? array_map(spNative(...), $value) : $value;
}

it('selects the same nodes as symfony/json-path for every stored path of the subset', function (string $path) {
    $ours = array_map(spNative(...), SamplePath::select(LosslessJson::decode(SP_DOCUMENT), $path));
    $theirs = (new JsonCrawler(SP_DOCUMENT))->find($path);

    expect($ours)->toEqual($theirs);
})->with([
    'root' => ['$'],
    'member' => ['$.data'],
    'nested member' => ['$.data.currency'],
    'records' => ['$.data.monthly[*]'],
    'a field of every record' => ['$.data.monthly[*].revenue'],
    'a field missing from some records' => ['$.data.monthly[*].rate'],
    'a field missing from every record' => ['$.data.monthly[*].nope'],
    'an array inside records' => ['$.data.monthly[*].tags[*]'],
    'a missing member' => ['$.missing'],
    'a missing nested member' => ['$.data.missing.deeper'],
    'a member with a space' => ["\$['odd key']"],
    'a member with a quote' => ["\$['it\\'s']"],
    'a member with a dot' => ["\$['a.b']"],
    'an empty name' => ["\$['']"],
    'a non-ASCII name' => ["\$['é']"],
    'wildcard over an array of arrays' => ['$.nested[*][*]'],
    'wildcard over an empty array' => ['$.empty[*]'],
    'wildcard over an object' => ['$.obj[*]'],
    'wildcard over an object, then a member' => ['$.obj[*].y'],
    'deep arrays' => ['$.obj.x.y[*]'],
    'wildcard over a scalar' => ['$.flag[*]'],
    'a member of a scalar' => ['$.flag.x'],
    'a member of null' => ['$.nothing.x'],
    'wildcard over the root object' => ['$[*]'],
]);

it('writes a stored path and a display path from segments, and reads the stored one back', function (array $segments, string $stored, string $display) {
    expect(SamplePath::stored($segments))->toBe($stored)
        ->and(SamplePath::display($segments))->toBe($display)
        ->and(SamplePath::parse($stored))->toBe($segments);
})->with([
    'root' => [[], '$', '$'],
    'members' => [['data', 'monthly'], '$.data.monthly', 'data.monthly'],
    'a field of every record' => [['data', 'monthly', null, 'revenue'], '$.data.monthly[*].revenue', 'data.monthly[].revenue'],
    'records' => [['data', 'monthly', null], '$.data.monthly[*]', 'data.monthly[]'],
    'a root array' => [[null, 'x'], '$[*].x', '[].x'],
    'a quoted name' => [['odd key', null], "\$['odd key'][*]", "['odd key'][]"],
    'a quote and a backslash' => [["it's\\"], "\$['it\\'s\\\\']", "['it\\'s\\\\']"],
    'a name that starts with a digit' => [['1a'], "\$['1a']", "['1a']"],
]);

it('refuses a path outside the subset', function (string $path) {
    expect(fn () => SamplePath::parse($path))->toThrow(InvalidArgumentException::class);
})->with(['', 'data', '$..a', '$.a[0]', '$.a[1:2]', '$.a[?(@.b)]', "\$['a'", "\$['a\\n']", '$.a.', '$.1a', '$[*', '$ .a']);
