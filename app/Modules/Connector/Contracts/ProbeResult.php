<?php

namespace App\Modules\Connector\Contracts;

/** What one health probe found (Story 2.18): reachable or not, the HTTP status when there was one and the run's error code (a catalogue code) when it failed. */
final readonly class ProbeResult
{
    public function __construct(
        public bool $ok,
        public ?int $httpStatus = null,
        public ?string $code = null,
    ) {}
}
