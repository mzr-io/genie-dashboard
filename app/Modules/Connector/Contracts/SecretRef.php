<?php

namespace App\Modules\Connector\Contracts;

/**
 * A pointer to a stored secret: the worker opens it with the vault. It carries no value. A transient secret of a connection
 * test names the Operation that owns it (`operationId`): that Operation, not a Data Source, is the context its value was
 * sealed for.
 */
final readonly class SecretRef
{
    public function __construct(
        public string $id,
        public string $slot,
        public string $purpose = SecretContext::PURPOSE_CRED,
        public ?string $operationId = null,
        public int $secretVersion = 1,
    ) {}

    /** @return array{id: string, slot: string, purpose: string, secret_version: int, operation_id?: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'slot' => $this->slot, 'purpose' => $this->purpose, 'secret_version' => $this->secretVersion] + ($this->operationId === null ? [] : ['operation_id' => $this->operationId]);
    }
}
