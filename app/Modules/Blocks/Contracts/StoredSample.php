<?php

namespace App\Modules\Blocks\Contracts;

/** A Draft's stored sample (Story 3.8), opened for the Admin who is editing it: the text exactly as pasted and what the server derived from it. */
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
    ) {}
}
