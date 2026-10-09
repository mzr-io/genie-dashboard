<?php

namespace App\Modules\Connector\Contracts;

/** The answer of the final hop. `headers` are keyed by lower-case name, each with every value in order. */
final readonly class EgressResponse
{
    /** @param  array<string, list<string>>  $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $url,
        public int $redirects = 0,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
