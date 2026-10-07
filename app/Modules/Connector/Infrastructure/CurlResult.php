<?php

namespace App\Modules\Connector\Infrastructure;

final readonly class CurlResult
{
    /** @param  array<string, list<string>>  $headers  keyed by lower-case name */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
