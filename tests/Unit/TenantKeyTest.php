<?php

use App\Modules\Access\Infrastructure\WorkspaceMembership;
use App\Platform\Tenancy\TenantKey;
use App\Platform\Tenancy\Workspace;
use Illuminate\Support\Str;

it('prefixes every cache key, lock, queue name, object key and channel name with the Workspace ID', function () {
    $ws = (string) Str::uuid7();

    foreach ([
        TenantKey::cache($ws, 'dashboard:1'),
        TenantKey::lock($ws, 'sync:target'),
        TenantKey::queue($ws, 'compute'),
        TenantKey::object($ws, 'raw/abc'),
        TenantKey::channel($ws, 'dashboards.1'),
    ] as $key) {
        expect($key)->toStartWith($ws);
    }
});

it('keeps two Workspaces\' keys apart and normalises the ID', function () {
    $a = (string) Str::uuid7();
    $b = (string) Str::uuid7();

    expect(TenantKey::cache($a, 'k'))->not->toBe(TenantKey::cache($b, 'k'))
        ->and(TenantKey::cache(strtoupper($a), 'k'))->toBe(TenantKey::cache($a, 'k'));
});

it('refuses a malformed Workspace ID or an empty key part', function () {
    expect(fn () => TenantKey::cache('not-a-uuid', 'k'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TenantKey::cache('', 'k'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TenantKey::queue((string) Str::uuid7(), ''))->toThrow(InvalidArgumentException::class);
});

it('generates UUIDv7 keys for models using HasUuids', function () {
    $id = (new Workspace)->newUniqueId();

    expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and((new WorkspaceMembership)->newUniqueId())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7/');
});

it('refuses key parts that climb out of the Workspace prefix', function () {
    $ws = (string) Str::uuid7();
    $other = (string) Str::uuid7();

    foreach (["../{$other}/x", '..', 'a/../b', 'a\\..\\b', "/{$other}/x", '\\x', ':x', '.x', "a\0b", "a\nb", "a\x7Fb"] as $bad) {
        expect(fn () => TenantKey::object($ws, $bad))->toThrow(InvalidArgumentException::class)
            ->and(fn () => TenantKey::channel($ws, $bad))->toThrow(InvalidArgumentException::class)
            ->and(fn () => TenantKey::cache($ws, $bad))->toThrow(InvalidArgumentException::class)
            ->and(fn () => TenantKey::lock($ws, $bad))->toThrow(InvalidArgumentException::class)
            ->and(fn () => TenantKey::queue($ws, $bad))->toThrow(InvalidArgumentException::class);
    }

    expect(TenantKey::object($ws, 'raw/a..b/c.json'))->toBe("{$ws}/raw/a..b/c.json")
        ->and(TenantKey::channel($ws, 'dashboards.1'))->toBe("{$ws}.dashboards.1");
});
