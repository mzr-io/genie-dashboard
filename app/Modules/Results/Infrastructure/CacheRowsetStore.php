<?php

namespace App\Modules\Results\Infrastructure;

use App\Modules\Results\Contracts\RowsetStore;
use App\Platform\Tenancy\TenantCache;
use Closure;

/** {@see RowsetStore} on the tenant cache. With no TTL configured nothing is stored: the same rule as the results. */
final class CacheRowsetStore implements RowsetStore
{
    /** @param  Closure(): (int|null)  $ttl */
    public function __construct(private readonly TenantCache $cache, private readonly Closure $ttl) {}

    public function put(string $workspaceId, string $ref, array $rowset): bool
    {
        $ttl = ($this->ttl)();

        return $ttl === null ? false : $this->cache->put($workspaceId, self::key($ref), $rowset, $ttl);
    }

    public function get(string $workspaceId, string $ref): ?array
    {
        $found = $this->cache->get($workspaceId, self::key($ref));

        if (! is_array($found) || ! is_array($found['columns'] ?? null) || ! is_array($found['values'] ?? null)) {
            return null;
        }

        /** @var array{columns: list<array{key: string, label: string}>, values: array<string, list<string|bool|null>>} $found */
        return $found;
    }

    private static function key(string $ref): string
    {
        return 'rows:'.$ref;
    }
}
