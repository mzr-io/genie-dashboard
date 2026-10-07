<?php

namespace App\Modules\Connector\Contracts;

/** The validated fields an Admin sets on a Data Source (see {@see DataSources}). Built only by the validator. */
final readonly class DataSourceInput
{
    public const AUTH_TYPES = ['none', 'api_key', 'bearer', 'basic', 'oauth2_client_credentials'];

    /** The types accepted until Story 2.7 adds `oauth2_client_credentials`. */
    public const ACCEPTED_AUTH_TYPES = ['none', 'api_key', 'bearer', 'basic'];

    /**
     * @param  list<array{name: string, value: string, secret?: true}>  $headers  a secret header keeps only its name and the flag
     * @param  array<string, string>  $secretValues  slot => the new value, only for the slots being set or replaced; never stored in the clear
     */
    public function __construct(
        public string $name,
        public DataSourceUrl $url,
        public array $headers,
        public ?int $timeoutSeconds,
        public ?int $maxResponseBytes,
        public ?int $maxPages,
        public bool $liveCapable,
        public string $authType = 'none',
        public ?string $apiKeyName = null,
        public ?string $apiKeyPlacement = null,
        #[\SensitiveParameter] public array $secretValues = [],
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'authType' => $this->authType, 'secretSlots' => array_keys($this->secretValues)];
    }
}
