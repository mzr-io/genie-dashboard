<?php

namespace App\Platform\Tenancy;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Workspace-scoped cache. Keys are built by TenantKey and every value is stored with its
 * `workspace_id`; a hit whose stored `workspace_id` differs is discarded and reported as a miss.
 * A TTL is always required (the Valkey cache store rejects writes without one).
 */
final class TenantCache
{
    public function __construct(private readonly Repository $store) {}

    public function get(string $workspaceId, string $key, mixed $default = null): mixed
    {
        $workspaceId = TenantKey::workspace($workspaceId);
        $storeKey = TenantKey::cache($workspaceId, $key);
        $entry = $this->store->get($storeKey);

        if ($entry === null) {
            return $default;
        }

        if (! is_array($entry) || ($entry['workspace_id'] ?? null) !== $workspaceId || ! array_key_exists('value', $entry)) {
            $this->store->forget($storeKey);

            return $default;
        }

        return $entry['value'];
    }

    public function put(string $workspaceId, string $key, mixed $value, DateTimeInterface|DateInterval|int $ttl): bool
    {
        $workspaceId = TenantKey::workspace($workspaceId);

        return $this->store->put(
            TenantKey::cache($workspaceId, $key),
            ['workspace_id' => $workspaceId, 'value' => $value],
            $ttl,
        );
    }

    public function forget(string $workspaceId, string $key): bool
    {
        return $this->store->forget(TenantKey::cache($workspaceId, $key));
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    public function remember(string $workspaceId, string $key, DateTimeInterface|DateInterval|int $ttl, Closure $callback): mixed
    {
        $missing = new \stdClass;
        $value = $this->get($workspaceId, $key, $missing);

        if ($value !== $missing) {
            return $value;
        }

        $value = $callback();
        $this->put($workspaceId, $key, $value, $ttl);

        return $value;
    }

    public function lock(string $workspaceId, string $name, int $seconds): Lock
    {
        /** @var LockProvider $provider */
        $provider = $this->store->getStore();

        return $provider->lock(TenantKey::lock($workspaceId, $name), $seconds);
    }
}
