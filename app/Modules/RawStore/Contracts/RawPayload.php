<?php

namespace App\Modules\RawStore\Contracts;

/** A stored response: the body's ID, the sha256 (hex) of its exact bytes and their number. The bytes themselves are read through {@see RawStore::get()}. */
final readonly class RawPayload
{
    public function __construct(
        public string $id,
        public string $contentHash,
        public int $sizeBytes,
    ) {}
}
