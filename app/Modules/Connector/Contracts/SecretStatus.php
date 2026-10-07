<?php

namespace App\Modules\Connector\Contracts;

/**
 * What an API may say about one secret slot: whether it is set and when, never the value. `id` and `keyVersion` are for
 * the worker's `secret_ref` and are not part of any client response.
 */
final readonly class SecretStatus
{
    public function __construct(
        public string $slot,
        public bool $configured,
        public ?string $updatedAt = null,
        public ?string $id = null,
        public ?int $keyVersion = null,
    ) {}

    /** @return array{configured: bool, updated_at: string|null} */
    public function toArray(): array
    {
        return ['configured' => $this->configured, 'updated_at' => $this->updatedAt];
    }
}
