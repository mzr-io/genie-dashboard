<?php

namespace App\Modules\Results\Contracts;

/** What a manual refresh of one Block did (Story 4.6): brought forward, held back by the window (with the seconds to wait), or impossible. */
final readonly class RefreshOutcome
{
    public const REQUESTED = 'requested';

    public const LIMITED = 'limited';

    public const UNAVAILABLE = 'unavailable';

    private function __construct(public string $status, public ?int $retryAfter = null) {}

    public static function requested(): self
    {
        return new self(self::REQUESTED);
    }

    public static function limited(int $retryAfter): self
    {
        return new self(self::LIMITED, max(1, $retryAfter));
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }
}
