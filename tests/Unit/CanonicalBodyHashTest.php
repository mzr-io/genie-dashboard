<?php

use App\Modules\Ingestion\Application\CanonicalBodyHash;
use App\Modules\Ingestion\Application\Jcs;
use App\Platform\Json\LosslessJson;

// Story 2.15: the hash that tells whether a response changed. Whitespace and key order do not matter, a number's lexeme does, a list's
// order does, and a body that cannot be canonicalised has no hash. Golden vectors keep the layout from drifting.

it('hashes the canonical form: sorted keys, no whitespace, strings escaped one way, numbers as received', function (string $body, string $canonical) {
    expect(CanonicalBodyHash::of($body))->toBe(hash('sha256', $canonical));
})->with([
    'keys sorted' => ['{"b":1,"a":2}', '{"a":2,"b":1}'],
    'whitespace dropped' => [" {\n \"a\" : [ 1 , 2 ] ,\t\"b\" : { } } ", '{"a":[1,2],"b":{}}'],
    'lexemes kept' => ['{"n":[1.0,1,1.10,1e2,-0,12345678901234567890.12]}', '{"n":[1.0,1,1.10,1e2,-0,12345678901234567890.12]}'],
    'escapes normalised' => ['{"s":"é\/\u0007\n"}', '{"s":"é/\\u0007\\n"}'],
    'nested objects sorted' => ['{"z":{"b":null,"a":true},"a":[{"y":1,"x":2}]}', '{"a":[{"x":2,"y":1}],"z":{"a":true,"b":null}}'],
    'keys by UTF-16 code unit' => ["{\"\u{1F600}\":1,\"\u{FF5E}\":2}", "{\"\u{1F600}\":1,\"\u{FF5E}\":2}"],
    'a numeric key stays a key' => ['{"10":1,"2":2,"1":3}', '{"1":3,"10":1,"2":2}'],
    'a scalar' => ['  "x" ', '"x"'],
]);

it('tells a changed body from an unchanged one', function () {
    $hash = CanonicalBodyHash::of('{"a":1.0,"b":[1,2],"c":"x"}');

    expect(CanonicalBodyHash::of("{\"c\":\"x\", \"b\":[1, 2],\n\"a\":1.0}"))->toBe($hash)
        ->and(CanonicalBodyHash::of('{"a":1,"b":[1,2],"c":"x"}'))->not->toBe($hash)
        ->and(CanonicalBodyHash::of('{"a":1.0,"b":[2,1],"c":"x"}'))->not->toBe($hash)
        ->and(CanonicalBodyHash::of('{"a":1.0,"b":[1,2],"c":"y"}'))->not->toBe($hash)
        ->and(CanonicalBodyHash::of('{"a":1.0,"b":[1,2],"c":"x","d":null}'))->not->toBe($hash)
        ->and(CanonicalBodyHash::of('{"a":1.0,"b":[1,2],"c":"X"}'))->not->toBe($hash);
});

it('has no hash for a body that cannot be canonicalised', function (string $body) {
    expect(CanonicalBodyHash::of($body))->toBeNull();
})->with([
    'truncated' => ['{"a":'],
    'empty' => [''],
    'duplicate key' => ['{"a":1,"a":2}'],
    'not UTF-8' => ["{\"a\":\"\xff\"}"],
    'too deep with no limit set' => [str_repeat('[', 600).str_repeat(']', 600)],
]);

it('agrees with the lossless parser for what a number is, and never reads a number as a float', function () {
    $value = LosslessJson::decode('{"a":0.1,"b":1E400,"c":-1.0e-5}');

    expect(Jcs::encode($value))->toBe('{"a":0.1,"b":1E400,"c":-1.0e-5}')
        ->and(fn () => Jcs::encode(1.5))->toThrow(InvalidArgumentException::class);
});

it('orders members by the UTF-16 code units of their names, integer-like names included, and keeps "1" and "01" apart', function () {
    $value = LosslessJson::decode('{"9":1,"10":2,"b":3,"a":4,"01":5,"1":6}');

    expect(Jcs::encode($value))->toBe('{"01":5,"1":6,"10":2,"9":1,"a":4,"b":3}');
});
