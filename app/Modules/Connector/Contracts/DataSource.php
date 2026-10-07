<?php

namespace App\Modules\Connector\Contracts;

/** A registered Data Source: the API a Workspace reads. A credential is never part of it, only the status of each secret slot (Story 2.4). */
final readonly class DataSource
{
    /** Where health, the last successful call and the Blocks using it stand until Stories 2.18, 2.14 and Epic 3 fill them. */
    public const HEALTH_PENDING = 'checking';

    /**
     * @param  list<array{name: string, value: string, secret?: true}>  $headers  the default headers, in the order entered; a secret one has an empty value and `secret: true`
     * @param  string  $createdAt  ISO 8601, UTC
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $baseUrl,
        public string $scheme,
        public string $host,
        public int $port,
        public string $authType,
        public array $headers,
        public ?int $timeoutSeconds,
        public ?int $maxResponseBytes,
        public ?int $maxPages,
        public bool $liveCapable,
        public int $revision,
        public string $createdAt,
        public string $updatedAt,
        public ?string $apiKeyName = null,
        public ?string $apiKeyPlacement = null,
        /** @var array<string, SecretStatus> slot => status, only the slots that hold a value; empty in a list */
        public array $secrets = [],
    ) {}
}
