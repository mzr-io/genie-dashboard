<?php

namespace App\Modules\Results\Infrastructure;

use Illuminate\Redis\Connections\Connection;

/** {@see ValkeyCommands} over a Laravel Redis connection (the `cache` store's, which the connection's prefix option applies to). */
final class LaravelValkeyCommands implements ValkeyCommands
{
    public function __construct(private readonly Connection $connection) {}

    public function eval(string $script, array $keys, array $args): mixed
    {
        // phpredis' own argument order (script, arguments, number of keys), which is what Laravel's `eval` passes on.
        return $this->connection->command('eval', [$script, [...$keys, ...$args], count($keys)]);
    }

    public function hgetall(string $key): array
    {
        $all = $this->connection->command('hgetall', [$key]);

        return is_array($all) ? array_map('strval', $all) : [];
    }
}
