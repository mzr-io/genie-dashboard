<?php

use App\Platform\Outbox\EventData;

it('accepts integers, booleans, null, UUIDs and short slugs', function () {
    $uuid = '018F0000-0000-7000-8000-000000000001';

    expect(EventData::data(['a' => 1, 'b' => true, 'c' => null, 'd' => $uuid, 'e' => 'admin']))
        ->toBe(['a' => 1, 'b' => true, 'c' => null, 'd' => strtolower($uuid), 'e' => 'admin']);
});

it('rejects free text, floats, lists, objects and bad keys', function (array $data) {
    expect(fn () => EventData::data($data))->toThrow(InvalidArgumentException::class);
})->with([
    'text' => [['a' => 'hello world']],
    'float' => [['a' => 0.5]],
    'nested' => [['a' => ['b' => 1]]],
    'object' => [['a' => new stdClass]],
    'bad key' => [['Bad Key' => 1]],
    'list' => [[1, 2]],
]);

it('validates subjects and actors', function () {
    expect(EventData::subject('membership:42'))->toBe('membership:42');
    expect(fn () => EventData::subject('membership'))->toThrow(InvalidArgumentException::class);
    expect(fn () => EventData::subject('membership:a@b.c'))->toThrow(InvalidArgumentException::class);
    expect(EventData::actor(null))->toBeNull();
    expect(fn () => EventData::actor('Alice Smith'))->toThrow(InvalidArgumentException::class);
});

it('fits subjects and request IDs into their varchar(64) columns', function () {
    $uuid = '018f0000-0000-7000-8000-000000000001';
    $longest = str_repeat('a', 27).':'.$uuid;

    expect(strlen($longest))->toBe(64)->and(EventData::subject($longest))->toBe($longest);
    expect(fn () => EventData::subject(str_repeat('a', 28).':'.$uuid))->toThrow(InvalidArgumentException::class);

    expect(strlen((string) EventData::requestId(str_repeat('r', 200))))->toBe(64)
        ->and(EventData::requestId('abc'))->toBe('abc')
        ->and(EventData::requestId(null))->toBeNull();
});
