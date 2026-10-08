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
        /** The Data Source's own timeout in seconds: it can only shorten the platform's, never lengthen it. */
        public ?int $timeoutSeconds = null,
        /**
         * The Data Source's own `max_response_bytes`. The transport reads no more than the smaller of this and the platform
         * ceiling (each used only when set; neither set means no cap) and counts the decompressed stream.
         */
        public ?int $maxBytes = null,
    ) {}
}
