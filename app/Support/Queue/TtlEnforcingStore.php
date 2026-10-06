<?php

namespace App\Support\Queue;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;

/**
 * Wraps a cache store so that no key can be written without a TTL.
 *
 * The cache instance evicts with LRU; a key without a TTL would only ever leave
 * by eviction. `forever`, a non-positive TTL, and `increment`/`decrement` on a
 * key that does not exist (which would create it without a TTL) all throw
 * before anything reaches the store.
 */
final class TtlEnforcingStore implements LockProvider, Store
{
    public function __construct(private readonly Store $store) {}

    public function get($key)
    {
        return $this->store->get($key);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function many(array $keys)
    {
        return $this->store->many($keys);
    }

    public function put($key, $value, $seconds)
    {
        $this->requireTtl((string) $key, $seconds);

        return $this->store->put($key, $value, $seconds);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values, $seconds)
    {
        $this->requireTtl((string) array_key_first($values), $seconds);

        return $this->store->putMany($values, $seconds);
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @param  int  $seconds
     * @return bool
     */
    public function add($key, $value, $seconds)
    {
        $this->requireTtl((string) $key, $seconds);

        if (method_exists($this->store, 'add')) {
            return $this->store->add($key, $value, $seconds);
        }

        if ($this->store->get($key) !== null) {
            return false;
        }

        return $this->store->put($key, $value, $seconds);
    }

    public function increment($key, $value = 1)
    {
        $this->requireExisting((string) $key);

        return $this->store->increment($key, $value);
    }

    public function decrement($key, $value = 1)
    {
        $this->requireExisting((string) $key);

        return $this->store->decrement($key, $value);
    }

    public function forever($key, $value)
    {
        throw TtlRequiredException::forKey((string) $key);
    }

    public function touch($key, $seconds)
    {
        $this->requireTtl((string) $key, $seconds);

        return $this->store->touch($key, $seconds);
    }

    public function forget($key)
    {
        return $this->store->forget($key);
    }

    public function flush()
    {
        return $this->store->flush();
    }

    public function getPrefix()
    {
        return $this->store->getPrefix();
    }

    public function lock($name, $seconds = 0, $owner = null)
    {
        return $this->locks()->lock($name, $seconds, $owner);
    }

    public function restoreLock($name, $owner)
    {
        return $this->locks()->restoreLock($name, $owner);
    }

    private function locks(): LockProvider
    {
        return $this->store instanceof LockProvider
            ? $this->store
            : throw new \BadMethodCallException('The wrapped cache store does not support locks.');
    }

    private function requireTtl(string $key, mixed $seconds): void
    {
        if (! is_numeric($seconds) || $seconds <= 0) {
            throw TtlRequiredException::forKey($key);
        }
    }

    private function requireExisting(string $key): void
    {
        if ($this->store->get($key) === null) {
            throw TtlRequiredException::forKey($key);
        }
    }
}
