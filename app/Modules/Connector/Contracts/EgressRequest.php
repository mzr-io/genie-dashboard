<?php

namespace App\Modules\Connector\Contracts;

/**
 * One outbound request. `credentials` (for example Authorization or an API-key header) are kept apart from `headers` so
 * the transport can drop them whenever the origin changes. Names and values never hold CR or LF.
 */
final readonly class EgressRequest
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $credentials
     */
    public function __construct(
        public string $url,
        public string $method = 'GET',
        public array $headers = [],
        public array $credentials = [],
        public ?string $body = null,
    ) {}
}
