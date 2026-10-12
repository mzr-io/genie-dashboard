<?php

namespace App\Modules\Ingestion\Contracts;

/** One subscription of a sync target as compute reads it (Story 4.2): which Block version watches it, in which compute context and period. */
final readonly class ComputeSubscription
{
    public function __construct(
        public string $blockVersionId,
        public string $role,
        public string $computeContext,
        public ?string $periodStart,
        public ?string $periodEnd,
        /** The refresh interval copied at subscribe time (Story 2.19). */
        public int $refreshIntervalSeconds = 0,
        /** ISO 8601, UTC: while it is in the future the target is hot for this subscription. */
        public ?string $hotUntil = null,
    ) {}
}
