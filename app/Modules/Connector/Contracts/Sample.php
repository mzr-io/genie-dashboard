<?php

namespace App\Modules\Connector\Contracts;

/** A Sample Response as its requester reads it: the status and latency of the call and the body exactly as received. */
final readonly class Sample
{
    public function __construct(
        public int $status,
        public int $latencyMs,
        #[\SensitiveParameter] public string $body,
        public string $expiresAt,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'latencyMs' => $this->latencyMs, 'expiresAt' => $this->expiresAt];
    }
}
