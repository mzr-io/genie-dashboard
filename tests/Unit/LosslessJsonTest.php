<?php

use App\Platform\Json\DecimalLiteral;
use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\JsonDepthExceeded;
use App\Platform\Json\JsonObject;
use App\Platform\Json\JsonParseFailed;
use App\Platform\Json\LosslessJson;
use Illuminate\Config\Repository;

// Story 2.6: the lossless decoder. `json_decode` is the oracle for valid structures only (tests may call it; the app may not).

const LJ_DIR = __DIR__.'/../Fixtures/json';

/** The shape json_decode gives, from a lossless value, so the two can be compared. */
function ljNative(mixed $value): mixed
{
    if ($value instanceof DecimalLiteral) {
        return json_decode($value->lexeme);
    }

    if ($value instanceof JsonObject) {
        $object = new stdClass;

        foreach ($value->entries() as [$key, $member]) {
            $object->{$key} = ljNative($member);
        }

        return $object;
    }

    return is_array($value) ? array_map(ljNative(...), $value) : $value;
}

/** @return array<string, array{0: string}> */
function ljFiles(string $kind): array
{
    $files = [];

    foreach (glob(LJ_DIR."/{$kind}/*.json") as $file) {
        $files[basename($file)] = [$file];
    }

    return $files;
}

it('accepts every valid fixture and agrees with json_decode on the structure', function (string $file) {
    $body = (string) file_get_contents($file);

    expect(ljNative(LosslessJson::decode($body)))->toEqual(json_decode($body));
})->with(ljFiles('accept'));

it('rejects every invalid fixture with a parse error that quotes none of the body', function (string $file) {
    $body = (string) file_get_contents($file);

    try {
        LosslessJson::decode($body);
        $failure = null;
    } catch (JsonParseFailed $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(JsonParseFailed::class);

    if (strlen($body) > 3) {
        expect($failure->getMessage())->not->toContain($body);
    }
})->with(ljFiles('reject'));

it('rejects what json_decode also rejects (the oracle agrees on invalid input, apart from the cases it is lenient about)', function (string $file) {
    $body = (string) file_get_contents($file);
    // PHP's decoder accepts a duplicate key (last wins); this decoder refuses it on purpose.
    $lenient = str_contains($file, 'dup_key');

    expect(json_decode($body) === null && json_last_error() !== JSON_ERROR_NONE)->toBe(! $lenient);
})->with(ljFiles('reject'));

it('keeps every number as a DecimalLiteral with its exact lexeme, never a float', function () {
    $cases = json_decode((string) file_get_contents(LJ_DIR.'/numbers.json'), true);

    foreach ($cases['lexemes'] as $lexeme) {
        $value = LosslessJson::decode("[{$lexeme}]")[0];

        expect($value)->toBeInstanceOf(DecimalLiteral::class)
            ->and($value->lexeme)->toBe($lexeme)
            ->and((string) $value)->toBe($lexeme);
    }

    foreach ($cases['integers'] as $lexeme) {
        expect((new DecimalLiteral($lexeme))->isInteger())->toBeTrue();
    }

    foreach ($cases['not_integers'] as $lexeme) {
        expect((new DecimalLiteral($lexeme))->isInteger())->toBeFalse();
    }
});

it('decodes 12345678901234567890.12 and 1.10 without losing a digit, and tells 1.10 from 1.1', function () {
    [$big, $trailing, $short] = LosslessJson::decode('[12345678901234567890.12, 1.10, 1.1]');

    expect((string) $big)->toBe('12345678901234567890.12')
        ->and((string) $trailing)->toBe('1.10')
        ->and($trailing->equals($short))->toBeFalse()
        ->and($trailing->equals(new DecimalLiteral('1.10')))->toBeTrue();
});

it('refuses a DecimalLiteral that is not a JSON number', function (string $lexeme) {
    expect(fn () => new DecimalLiteral($lexeme))->toThrow(InvalidArgumentException::class);
})->with(['01', '+1', '1.', '.5', 'NaN', '', '1e', ' 1']);

it('keeps object key order and tells an object from an array', function () {
    $object = LosslessJson::decode('{"b":1,"a":2,"1":3}');

    expect($object)->toBeInstanceOf(JsonObject::class)
        ->and($object->keys())->toBe(['b', 'a', '1'])
        ->and($object->has('a'))->toBeTrue()
        ->and(count($object))->toBe(3)
        ->and(LosslessJson::decode('{}'))->toBeInstanceOf(JsonObject::class)
        ->and(LosslessJson::decode('[]'))->toBe([]);
});

it('decodes escapes and surrogate pairs to UTF-8', function () {
    expect(LosslessJson::decode('"\u00e9\ud83d\ude00\n\/"'))->toBe("é😀\n/");
});

it('rejects a body nested deeper than the limit before parsing, and ignores brackets inside strings', function () {
    $deep = str_repeat('[', 6).str_repeat(']', 6);

    expect(fn () => LosslessJson::decode($deep, 5))->toThrow(JsonDepthExceeded::class)
        ->and(LosslessJson::decode($deep, 6))->toBeArray()
        ->and(LosslessJson::decode('["[[[[[[[[\\"[["]', 1))->toBe(['[[[[[[[["[[']);
});

it('raises the depth error even when the body would not parse, so nothing is built (the pre-scan comes first)', function () {
    expect(fn () => LosslessJson::decode(str_repeat('[', 10).'garbage', 3))->toThrow(JsonDepthExceeded::class);
});

it('checks no depth when the setting is unset, and reads it when it is set', function () {
    ljDepth(null);
    expect(LosslessJson::configuredDepthLimit())->toBeNull();

    ljDepth('12');
    expect(LosslessJson::configuredDepthLimit())->toBe(12);

    ljDepth('');
    expect(LosslessJson::configuredDepthLimit())->toBeNull();
});

it('parses a very deeply nested body without recursion', function () {
    $n = 50_000;
    $value = LosslessJson::decode(str_repeat('[', $n).str_repeat(']', $n));

    expect($value)->toBeArray()
        ->and(strlen(LosslessJson::canonicalOf($value)))->toBe(2 * $n);
});

it('matches the golden vectors: input to canonical bytes to hash', function (array $vector) {
    expect(LosslessJson::canonical($vector['input']))->toBe($vector['canonical'])
        ->and(LosslessJson::hash($vector['input']))->toBe($vector['sha256'])
        ->and(hash('sha256', $vector['canonical']))->toBe($vector['sha256']);
})->with(array_map(fn (array $v): array => [$v], json_decode((string) file_get_contents(LJ_DIR.'/golden.json'), true)));

it('gives identical bytes and hash for bodies that differ only in whitespace, key order or escaping, and different ones for 1.10 and 1.1', function () {
    $a = '{"name":"é","n":[1.10,2],"o":{"y":1,"x":2}}';
    $b = "{ \"o\" : {\"x\":2, \"y\":1},\n \"n\":[ 1.10 , 2 ], \"name\":\"\\u00e9\" }";

    expect(LosslessJson::canonical($a))->toBe(LosslessJson::canonical($b))
        ->and(LosslessJson::hash($a))->toBe(LosslessJson::hash($b))
        ->and(LosslessJson::canonical('[1.10]'))->not->toBe(LosslessJson::canonical('[1.1]'))
        ->and(LosslessJson::hash('[1.10]'))->not->toBe(LosslessJson::hash('[1.1]'));
});

it('refuses to canonicalise a value the decoder never produces', function () {
    expect(fn () => LosslessJson::canonicalOf(1.5))->toThrow(InvalidArgumentException::class);
});

/** Binds a config repository holding the depth limit setting (the Unit suite boots no application), or removes it. */
function ljDepth(mixed $value): void
{
    app()->instance('config', new Repository(['dashflow' => ['tunables' => ['guards' => ['depth_limit' => ['value' => $value]]]]]));
}

afterEach(fn () => app()->forgetInstance('config'));

it('fails closed on a depth limit that is set but malformed, instead of reading it as unset', function (mixed $value) {
    ljDepth($value);

    expect(fn () => LosslessJson::configuredDepthLimit())->toThrow(InvalidLimitSetting::class, 'depth_limit');
})->with(['abc', '10MB', '0', '-3', 1.5, 0, -1, '1.5', true]);

it('validate() accepts and rejects exactly what decode() does, and applies the same depth rule', function (string $file) {
    $body = (string) file_get_contents($file);
    $decoded = null;
    $validated = null;

    try {
        LosslessJson::decode($body);
    } catch (JsonParseFailed $e) {
        $decoded = $e->getMessage();
    }

    try {
        LosslessJson::validate($body);
    } catch (JsonParseFailed $e) {
        $validated = $e->getMessage();
    }

    expect($validated)->toBe($decoded);
})->with(array_merge(ljFiles('accept'), ljFiles('reject')));

it('validate() enforces the depth limit before parsing', function () {
    expect(fn () => LosslessJson::validate(str_repeat('[', 6).str_repeat(']', 6), 5))->toThrow(JsonDepthExceeded::class);
    LosslessJson::validate(str_repeat('[', 6).str_repeat(']', 6), 6);
});
