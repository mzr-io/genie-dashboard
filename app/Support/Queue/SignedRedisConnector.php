<?php

namespace App\Support\Queue;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Arr;
use Laravel\Horizon\Connectors\RedisConnector;

/** Replaces Horizon's `redis` queue connector so every Redis queue signs its payloads. */
final class SignedRedisConnector extends RedisConnector
{
    public function __construct(Redis $redis, private readonly JobSigner $signer)
    {
        parent::__construct($redis);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[\Override]
    public function connect(array $config)
    {
        return (new SignedRedisQueue(
            $this->redis,
            $config['queue'],
            Arr::get($config, 'connection', $this->connection),
            Arr::get($config, 'retry_after', 60),
            Arr::get($config, 'block_for', null),
            Arr::get($config, 'after_commit', null),
        ))->setSigner($this->signer);
    }
}
