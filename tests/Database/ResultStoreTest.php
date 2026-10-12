<?php

use App\Modules\Results\Contracts\BlockResult;
use App\Modules\Results\Contracts\ResultSettingNotConfigured;
use App\Modules\Results\Contracts\ResultState;
use App\Modules\Results\Infrastructure\ValkeyResultStore;
use Tests\Database\Support\RespClient;

// Story 4.2 against a real Valkey: the Lua compare-and-set on payload_seq, the TTL on every key, the Workspace guard, concurrent writers and a flush.
const RS_WS = '0197a000-0000-7000-8000-0000000000aa';
const RS_OTHER = '0197a000-0000-7000-8000-0000000000bb';
const RS_KEY = RS_WS.':cache:res:v:fk1:abc:cc1:x';

beforeEach(function () {
    $this->valkey = new RespClient;
    $this->valkey->command('FLUSHALL');
    $this->store = new ValkeyResultStore($this->valkey, fn () => 600);
});

function rsResult(int $seq, string $value = '12345678901234567890.10', array $slots = ['headline_value' => ['state' => 'ok']]): BlockResult
{
    return new BlockResult(1, 'g-'.$seq, $seq, '2026-10-12T10:00:00Z', '2026-10-12T10:00:00Z', ResultState::Ok, false, false, $slots, ['headline' => ['value' => $value], 'n' => 3], BlockResult::etag(RS_KEY, 'g-'.$seq), '2026-10-12T10:00:01Z');
}

it('stores a result with a TTL and reads it back with exact decimals', function () {
    expect($this->store->put(RS_WS, RS_KEY, rsResult(5)))->toBeTrue();

    $ttl = $this->valkey->command('TTL', RS_KEY);
    $read = $this->store->get(RS_WS, RS_KEY);

    expect($ttl)->toBeGreaterThan(0)->toBeLessThanOrEqual(600)
        ->and($read)->toEqual(rsResult(5))
        ->and($read->renderPayload['headline']['value'])->toBe('12345678901234567890.10')
        ->and($this->store->payloadSeq(RS_WS, RS_KEY))->toBe(5);
});

it('keeps the higher payload_seq when the later-started job writes last', function () {
    expect($this->store->put(RS_WS, RS_KEY, rsResult(9, 'new')))->toBeTrue()
        ->and($this->store->put(RS_WS, RS_KEY, rsResult(8, 'old')))->toBeFalse()
        ->and($this->store->get(RS_WS, RS_KEY)->renderPayload['headline']['value'])->toBe('new')
        ->and($this->store->payloadSeq(RS_WS, RS_KEY))->toBe(9);
});

it('accepts a duplicate delivery of the same sequence and a newer one', function () {
    expect($this->store->put(RS_WS, RS_KEY, rsResult(4)))->toBeTrue()
        ->and($this->store->put(RS_WS, RS_KEY, rsResult(4)))->toBeTrue()
        ->and($this->store->put(RS_WS, RS_KEY, rsResult(10, 'newer')))->toBeTrue()
        ->and($this->store->get(RS_WS, RS_KEY)->payloadSeq)->toBe(10);
});

it('ends with the highest sequence whatever order a burst of writers arrives in', function () {
    $order = [3, 11, 7, 1, 11, 9, 2, 10, 5];

    foreach ($order as $seq) {
        $this->store->put(RS_WS, RS_KEY, rsResult($seq, 'v'.$seq));
    }

    expect($this->store->payloadSeq(RS_WS, RS_KEY))->toBe(11)
        ->and($this->store->get(RS_WS, RS_KEY)->renderPayload['headline']['value'])->toBe('v11');
});

it('has no result after the cache is flushed, and accepts a lower sequence again', function () {
    $this->store->put(RS_WS, RS_KEY, rsResult(9));
    $this->valkey->command('FLUSHALL');

    expect($this->store->get(RS_WS, RS_KEY))->toBeNull()
        ->and($this->store->payloadSeq(RS_WS, RS_KEY))->toBeNull()
        ->and($this->store->put(RS_WS, RS_KEY, rsResult(2)))->toBeTrue();
});

it('treats another Workspace\'s entry, or a body it cannot read, as a miss', function () {
    $this->store->put(RS_WS, RS_KEY, rsResult(5));

    expect($this->store->get(RS_OTHER, RS_KEY))->toBeNull()
        ->and($this->store->payloadSeq(RS_OTHER, RS_KEY))->toBeNull();

    $this->valkey->command('HSET', RS_KEY, 'body', '{"v":9}');

    expect($this->store->get(RS_WS, RS_KEY))->toBeNull();
});

it('refuses to store while no TTL is configured, and leaves nothing behind', function () {
    $store = new ValkeyResultStore($this->valkey, fn () => null);

    expect(fn () => $store->put(RS_WS, RS_KEY, rsResult(1)))->toThrow(ResultSettingNotConfigured::class)
        ->and($this->valkey->command('EXISTS', RS_KEY))->toBe(0);
});
