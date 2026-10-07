<?php

namespace App\Modules\Connector\Contracts;

/**
 * What {@see FetchTransport} brings back from the final hop. The body is for the caller that parses it (Story 2.6); a
 * connection test reads only `bytes` and drops it. It never appears in a dump.
 */
final readonly class FetchResponse
{
    /** @param  array<string, list<string>>  $headers  keyed by lower-case name */
    public function __construct(
        public int $status,
        public array $headers,
        #[\SensitiveParameter] public string $body,
        public int $bytes,
        public int $latencyMs,
    ) {}

    /** True for HTTP 2xx. */
    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['status' => $this->status, 'bytes' => $this->bytes, 'latencyMs' => $this->latencyMs];
    }
}
