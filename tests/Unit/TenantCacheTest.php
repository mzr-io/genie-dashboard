<?php

use App\Platform\Tenancy\TenantCache;
use App\Platform\Tenancy\TenantKey;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->store = new Repository(new ArrayStore);
    $this->cache = new TenantCache($this->store);
    $this->a = (string) Str::uuid7();
    $this->b = (string) Str::uuid7();
});

it('stores under a Workspace-prefixed key and reads it back', function () {
    $this->cache->put($this->a, 'k', ['x' => 1], 60);

    expect($this->cache->get($this->a, 'k'))->toBe(['x' => 1])
        ->and($this->store->has(TenantKey::cache($this->a, 'k')))->toBeTrue();
});

it('misses when an entry stored for A is read under B', function () {
    $this->cache->put($this->a, 'k', 'secret', 60);

    expect($this->cache->get($this->b, 'k'))->toBeNull()
        ->and($this->cache->get($this->b, 'k', 'fallback'))->toBe('fallback');
});

it('discards a hit whose stored workspace_id differs', function () {
    $key = TenantKey::cache($this->a, 'k');
    $this->store->put($key, ['workspace_id' => $this->b, 'value' => 'B\'s data'], 60);

    expect($this->cache->get($this->a, 'k'))->toBeNull()
        ->and($this->store->has($key))->toBeFalse();
});

it('discards an entry without a workspace envelope', function () {
    $key = TenantKey::cache($this->a, 'k');
    $this->store->put($key, 'bare value', 60);

    expect($this->cache->get($this->a, 'k'))->toBeNull()
        ->and($this->store->has($key))->toBeFalse();
});

it('remembers, forgets and locks per Workspace', function () {
    $calls = 0;
    $compute = function () use (&$calls) {
        return ++$calls;
    };

    expect($this->cache->remember($this->a, 'n', 60, $compute))->toBe(1)
        ->and($this->cache->remember($this->a, 'n', 60, $compute))->toBe(1)
        ->and($this->cache->remember($this->b, 'n', 60, $compute))->toBe(2);

    $this->cache->forget($this->a, 'n');
    expect($this->cache->get($this->a, 'n'))->toBeNull();

    $lockA = $this->cache->lock($this->a, 'job', 10);
    $lockB = $this->cache->lock($this->b, 'job', 10);
    expect($lockA->get())->toBeTrue()
        ->and($lockB->get())->toBeTrue()
        ->and($this->cache->lock($this->a, 'job', 10)->get())->toBeFalse();
});

it('keeps a falsy and a null value as hits', function () {
    $this->cache->put($this->a, 'zero', 0, 60);
    $this->cache->put($this->a, 'nothing', null, 60);

    expect($this->cache->get($this->a, 'zero', 'miss'))->toBe(0)
        ->and($this->cache->get($this->a, 'nothing', 'miss'))->toBeNull();
});
