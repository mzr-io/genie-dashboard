<?php

use App\Support\Queue\TtlEnforcingStore;
use App\Support\Queue\TtlRequiredException;
use Illuminate\Support\Facades\Cache;

it('wraps the redis store in the application config without opening a connection', function () {
    $store = Cache::store('redis')->getStore();

    expect($store)->toBeInstanceOf(TtlEnforcingStore::class);
    expect(fn () => Cache::store('redis')->forever('k', 'v'))->toThrow(TtlRequiredException::class);
    expect(fn () => Cache::store('redis')->put('k', 'v'))->toThrow(TtlRequiredException::class);
});

it('gives the wrapped Redis store the queue connection for locks', function () {
    $wrapper = Cache::store('redis')->getStore();
    $inner = (new ReflectionProperty($wrapper, 'store'))->getValue($wrapper);

    expect((new ReflectionProperty($inner, 'lockConnection'))->getValue($inner))->toBe('queue')
        ->and((new ReflectionProperty($inner, 'connection'))->getValue($inner))->toBe('cache');
});
