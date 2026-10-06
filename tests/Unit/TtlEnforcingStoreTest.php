<?php

use App\Support\Queue\TtlEnforcingStore;
use App\Support\Queue\TtlRequiredException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function ttlRepository(): array
{
    $inner = new ArrayStore;

    return [new Repository(new TtlEnforcingStore($inner)), $inner];
}

it('rejects a write without a TTL and stores nothing', function (string $write) {
    [$cache, $inner] = ttlRepository();

    $attempt = fn () => match ($write) {
        'forever' => $cache->forever('k', 'v'),
        'put with null TTL' => $cache->put('k', 'v'),
        'rememberForever' => $cache->rememberForever('k', fn () => 'v'),
        'add with zero TTL' => $cache->getStore()->add('k', 'v', 0),
        'putMany with zero TTL' => $cache->getStore()->putMany(['k' => 'v'], 0),
        'increment of a missing key' => $cache->increment('k'),
        'touch with zero TTL' => $cache->getStore()->touch('k', 0),
    };

    expect($attempt)->toThrow(TtlRequiredException::class);
    expect($inner->get('k'))->toBeNull();
})->with([
    'forever', 'put with null TTL', 'rememberForever', 'add with zero TTL',
    'putMany with zero TTL', 'increment of a missing key', 'touch with zero TTL',
]);

it('accepts writes that carry a TTL', function () {
    [$cache] = ttlRepository();

    $cache->put('a', 1, 60);
    expect($cache->get('a'))->toBe(1);
    expect($cache->add('b', 2, 60))->toBeTrue();
    expect($cache->add('b', 3, 60))->toBeFalse();
    expect($cache->remember('c', 60, fn () => 'x'))->toBe('x');
    expect($cache->increment('a'))->toBe(2);
    expect($cache->getStore()->lock('l', 5)->get())->toBeTrue();
});
