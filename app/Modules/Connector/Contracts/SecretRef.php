<?php

namespace App\Modules\Connector\Contracts;

/** A pointer to a stored secret: the worker opens it with the vault. It carries no value. */
final readonly class SecretRef
{
    public function __construct(
        public string $id,
        public string $slot,
        public string $purpose = SecretContext::PURPOSE_CRED,
    ) {}

    /** @return array{id: string, slot: string, purpose: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'slot' => $this->slot, 'purpose' => $this->purpose];
    }
}
