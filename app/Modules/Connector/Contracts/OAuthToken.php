<?php

namespace App\Modules\Connector\Contracts;

/** A bearer token from a token endpoint. It hides itself from dumps; only the transport reads it. */
final readonly class OAuthToken
{
    public function __construct(
        #[\SensitiveParameter] public string $accessToken,
        /** Seconds the endpoint says the token lives; null when it gave none that is usable. */
        public ?int $expiresIn,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['expiresIn' => $this->expiresIn];
    }
}
