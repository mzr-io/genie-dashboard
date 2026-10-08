<?php

namespace App\Modules\Connector\Contracts;

/** The validated fields an Admin sets on a Data Source (see {@see DataSources}). Built only by the validator. */
final readonly class DataSourceInput
{
    public const AUTH_TYPES = ['none', 'api_key', 'bearer', 'basic', 'oauth2_client_credentials'];

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
        /** OAuth2 client credentials only (Story 2.7): where the token is requested, the plain client ID and the optional scope. */
        public ?DataSourceUrl $oauthTokenUrl = null,
        public ?string $oauthClientId = null,
        public ?string $oauthScope = null,
        /** How the API pages its answers (Story 2.11). */
        public Pagination $pagination = new Pagination,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'authType' => $this->authType, 'secretSlots' => array_keys($this->secretValues)];
    }
}
