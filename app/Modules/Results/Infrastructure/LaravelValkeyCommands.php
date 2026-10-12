<?php

namespace App\Modules\Results\Infrastructure;

use Illuminate\Redis\Connections\Connection;

/** {@see ValkeyCommands} over a Laravel Redis connection (the `cache` store's, which the connection's prefix option applies to). */
final class LaravelValkeyCommands implements ValkeyCommands
{
    private ?Connection $connection = null;

    /** @param  \Closure(): Connection  $connect  opened on first use, so resolving a service that holds the store never opens a connection */
    public function __construct(private readonly \Closure $connect) {}

    private function connection(): Connection
    {
        return $this->connection ??= ($this->connect)();
    }

    public function eval(string $script, array $keys, array $args): mixed
    {
        // phpredis' own argument order (script, arguments, number of keys), which is what Laravel's `eval` passes on.
        return $this->connection()->command('eval', [$script, [...$keys, ...$args], count($keys)]);
    }

    public function hgetall(string $key): array
    {
        $all = $this->connection()->command('hgetall', [$key]);

        return is_array($all) ? array_map('strval', $all) : [];
    }
}
