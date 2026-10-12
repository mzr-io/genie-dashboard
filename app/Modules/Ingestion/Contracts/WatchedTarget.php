<?php

namespace App\Modules\Ingestion\Contracts;

/** A target together with the primary subscription of one Block version and compute context (Story 4.3). */
final readonly class WatchedTarget
{
    public function __construct(public ComputeTarget $target, public ComputeSubscription $subscription) {}
}
