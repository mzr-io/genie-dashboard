<?php

namespace App\Modules\Ingestion\Infrastructure;

/** The valid retry settings (Story 2.17), in whole seconds; `maxAttempts` counts the first call. */
final readonly class RetryPolicy
{
    public function __construct(public int $base, public int $cap, public int $maxAttempts) {}

    /** The ceiling of the full-jitter delay of the attempt that just failed: `min(cap, base * 2^(attempt-1))`. */
    public function ceiling(int $attempt): int
    {
        // 2^30 already exceeds any cap this reads (nine digits), so a large attempt cannot overflow.
        return min($this->cap, $this->base * (1 << min(30, max(0, $attempt - 1))));
    }
}
