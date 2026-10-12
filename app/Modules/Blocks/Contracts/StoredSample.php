<?php

namespace App\Modules\Blocks\Contracts;

/**
 * A Draft's stored sample (Story 3.8, 3.9), opened for the Admin who is editing it: the text exactly as pasted or fetched and what the server
 * derived from it. `sampleRevision` goes up each time the sample is replaced; a fetched sample also carries the key of the request it was taken
 * with and the status and time of that fetch (all null for a paste). `pastedAt` is the time it was stored, whatever its origin.
 */
final readonly class StoredSample
{
    public function __construct(
        public string $text,
        public string $origin,
        public int $sizeBytes,
        public string $pastedAt,
        public string $expiresAt,
        public bool $containsPersonalData,
        public SampleCheck $check,
        public int $sampleRevision = 1,
        public ?string $requestKey = null,
        public ?int $fetchStatus = null,
        public ?int $fetchLatencyMs = null,
    ) {}
}
