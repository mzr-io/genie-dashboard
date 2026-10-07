<?php

namespace App\Modules\Connector\Contracts;

/** The validated fields an Admin sets on a Data Source (see {@see DataSources}). Built only by the validator. */
final readonly class DataSourceInput
{
    public const AUTH_TYPES = ['none', 'api_key', 'bearer', 'basic', 'oauth2_client_credentials'];

    /** @param  list<array{name: string, value: string}>  $headers */
    public function __construct(
        public string $name,
        public DataSourceUrl $url,
        public array $headers,
        public ?int $timeoutSeconds,
        public ?int $maxResponseBytes,
        public ?int $maxPages,
        public bool $liveCapable,
        public string $authType = 'none',
    ) {}
}
