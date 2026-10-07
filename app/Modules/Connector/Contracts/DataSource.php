<?php

namespace App\Modules\Connector\Contracts;

/** A registered Data Source: the API a Workspace reads. Credentials are never part of it (Story 2.4). */
final readonly class DataSource
{
    /** Where health, the last successful call and the Blocks using it stand until Stories 2.18, 2.14 and Epic 3 fill them. */
    public const HEALTH_PENDING = 'checking';

    /**
     * @param  list<array{name: string, value: string}>  $headers  the default headers, in the order entered
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
    ) {}
}
