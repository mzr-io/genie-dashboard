<?php

namespace App\Modules\Connector\Contracts;

/**
 * Admission control per Data Source for scheduled calls (Story 2.17): the circuit breaker, the penalty after a throttled answer, a token
 * bucket and a concurrency cap. State is shared by every worker and changes only atomically. An implementation fails open: when its store
 * is unavailable, calls are admitted (and the failure is logged without a value); Postgres state is never touched.
 */
interface SourceGovernor
{
    public const OPENED = 'opened';

    public const CLOSED = 'closed';

    /** The breaker states {@see self::state()} reports. */
    public const STATE_CLOSED = 'closed';

    public const STATE_OPEN = 'open';

    public const STATE_HALF_OPEN = 'half_open';

    /** Asks to make one call. An admitted call takes a token and, when capped, a concurrency slot. */
    public function admit(string $workspaceId, string $dataSourceId, GovernorLimits $limits): Admission;

    /**
     * Records what the call told the breaker.
     *
     * @return string|null {@see self::OPENED} when this call opened (or reopened) the breaker, {@see self::CLOSED} when it closed it
     */
    public function record(string $workspaceId, string $dataSourceId, CallOutcome $outcome, GovernorLimits $limits, bool $probe = false): ?string;

    /**
     * Where the breaker stands (Story 2.18), read only: `closed`, `open` (inside the cool-down) or `half_open` (the cool-down is over and the next
     * call is the probe). It changes nothing. It is `closed` when the breaker is inert (pass the limits to say so) and when the store is unavailable.
     *
     * @return 'closed'|'open'|'half_open'
     */
    public function state(string $workspaceId, string $dataSourceId, ?GovernorLimits $limits = null): string;

    /** A throttled answer: no call until `$seconds` from now (0: no penalty, only the bucket), and the token bucket is emptied. */
    public function penalize(string $workspaceId, string $dataSourceId, int $seconds, GovernorLimits $limits): void;

    /** Gives back the concurrency slot an admitted call held. */
    public function release(string $workspaceId, string $dataSourceId): void;
}
