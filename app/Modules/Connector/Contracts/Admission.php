<?php

namespace App\Modules\Connector\Contracts;

/**
 * The {@see SourceGovernor}'s answer to "may this call go out now?" (Story 2.17). Admitted, or denied with a reason and how long to
 * wait. A `probe` is the one half-open call of an open breaker; a `slot` is a concurrency slot the caller must release.
 */
final readonly class Admission
{
    public const CIRCUIT_OPEN = 'circuit-open';

    /** The penalty after a throttled answer, or an empty token bucket. */
    public const RATE_LIMITED = 'rate-limited';

    /** The Data Source's concurrency cap is reached. */
    public const CONCURRENCY = 'concurrency';

    private function __construct(
        public bool $admitted,
        public ?string $reason = null,
        public int $waitSeconds = 0,
        public bool $probe = false,
        public bool $slot = false,
    ) {}

    public static function admitted(bool $probe = false, bool $slot = false): self
    {
        return new self(true, null, 0, $probe, $slot);
    }

    public static function denied(string $reason, int $waitSeconds): self
    {
        return new self(false, $reason, max(0, $waitSeconds));
    }
}
