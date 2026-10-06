<?php

namespace App\Support\Queue;

use Laravel\Horizon\RedisQueue;

/**
 * Horizon's Redis queue that signs every payload it creates.
 *
 * The payload hook (`Queue::createPayloadUsing`) runs before the command is
 * serialized, so the signature has to be added here, after serialization.
 */
final class SignedRedisQueue extends RedisQueue
{
    private ?JobSigner $signer = null;

    public function setSigner(JobSigner $signer): static
    {
        $this->signer = $signer;

        return $this;
    }

    /**
     * @param  string  $job
     * @param  string  $queue
     * @param  mixed  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function createPayloadArray($job, $queue, $data = '')
    {
        $payload = parent::createPayloadArray($job, $queue, $data);

        return $this->signer instanceof JobSigner ? $this->signer->sign($payload) : $payload;
    }

    /**
     * Horizon's retry rewrites `uuid`/`id` and adds `retry_of` (the original id)
     * before pushing. Re-sign only when the existing signature is valid for the
     * original id; anything else is pushed unchanged so the guard rejects it.
     *
     * @param  string  $payload
     * @param  string|null  $queue
     * @param  array<string, mixed>  $options
     */
    #[\Override]
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $decoded = json_decode((string) $payload, true);

        if ($this->signer instanceof JobSigner && is_array($decoded)
            && is_string($decoded['retry_of'] ?? null) && $decoded['retry_of'] !== ''
            && $this->signer->verify(['uuid' => $decoded['retry_of']] + $decoded)) {
            $payload = (string) json_encode($this->signer->sign($decoded), JSON_UNESCAPED_UNICODE);
        }

        return parent::pushRaw($payload, $queue, $options);
    }
}
