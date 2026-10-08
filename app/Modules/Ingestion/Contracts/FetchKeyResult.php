<?php

namespace App\Modules\Ingestion\Contracts;

/** A fetch key, or the reason there is none. Never a value of the user. */
final readonly class FetchKeyResult
{
    /** The reason when a bound attribute is missing or invalid, or the digest key is unusable (the platform's `access.context_missing`). */
    public const CONTEXT_MISSING = 'access.context_missing';

    private function __construct(
        public ?string $key,
        public ?string $reason,
    ) {}

    public static function key(string $key): self
    {
        return new self($key, null);
    }

    public static function missing(): self
    {
        return new self(null, self::CONTEXT_MISSING);
    }

    public function ok(): bool
    {
        return $this->key !== null;
    }
}
