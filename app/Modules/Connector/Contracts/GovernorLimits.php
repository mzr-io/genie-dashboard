<?php

namespace App\Modules\Connector\Contracts;

/**
 * The numbers the {@see SourceGovernor} applies (Story 2.17). Every one is a `pending_input` setting with no default: null means that
 * guard is off. The breaker needs both `failureCount` and `coolDownSeconds`.
 */
final readonly class GovernorLimits
{
    public function __construct(
        public ?int $failureCount = null,
        public ?int $coolDownSeconds = null,
        /** Whole calls per minute: the token bucket's capacity. */
        public ?int $ratePerMinute = null,
        /** The most in-flight calls per Data Source. */
        public ?int $concurrency = null,
        /** The lease of the in-flight counter (the platform timeout ceiling), so a killed worker cannot hold a slot forever. */
        public ?int $leaseSeconds = null,
        /** `retry.cap`: a throttled answer sets a penalty only when it is valid, so a penalty can only exist when it is. */
        public ?int $penaltyCapSeconds = null,
    ) {}

    /** Whether any guard is configured: with none, the governor has nothing to ask and nothing to record. */
    public function active(): bool
    {
        return $this->breakerActive() || $this->ratePerMinute !== null || $this->concurrency !== null || $this->penaltyCapSeconds !== null;
    }

    public function breakerActive(): bool
    {
        return $this->failureCount !== null && $this->coolDownSeconds !== null;
    }
}
