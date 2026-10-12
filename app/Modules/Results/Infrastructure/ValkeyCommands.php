<?php

namespace App\Modules\Results\Infrastructure;

/** The few Valkey commands the result store needs, so the store runs against the real connection and against a test client alike. */
interface ValkeyCommands
{
    /**
     * @param  list<string>  $keys
     * @param  list<string>  $args
     */
    public function eval(string $script, array $keys, array $args): mixed;

    /** @return array<string, string> field => value, empty when the key is missing */
    public function hgetall(string $key): array;
}
