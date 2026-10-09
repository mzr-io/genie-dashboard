<?php

namespace App\Modules\Connector\Application;

use App\Modules\Connector\Contracts\DataSourceActor;
use App\Modules\Connector\Contracts\SampleFetchThrottled;
use App\Modules\Connector\Infrastructure\SampleFetchSettings;
use App\Platform\Tenancy\TenantKey;
use Illuminate\Cache\RateLimiter;

/**
 * The rate limits of an interactive Endpoint fetch (Story 2.10), per membership and per Workspace, from the `pending_input`
 * settings (unset means not limited). A Fetch as user (Story 2.13) shares these counters with Test endpoint: both start a
 * `fetch-interactive` request to a source, so both spend the same budget.
 */
final class SampleFetchRateLimit
{
    public function __construct(
        private readonly SampleFetchSettings $limits,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * Counts this request against every limit first (an atomic increment), then compares: concurrent requests cannot all pass a
     * check that none of them has counted yet. A request over a limit is refused with the wait. Same rule as Test connection.
     *
     * @throws SampleFetchThrottled
     */
    public function hit(DataSourceActor $actor): void
    {
        $window = $this->limits->window();
        $retry = 0;

        foreach ([
            [TenantKey::cache($actor->workspaceId, 'sample-fetch:membership:'.strtolower($actor->membershipId)), $this->limits->membershipLimit()],
            [TenantKey::cache($actor->workspaceId, 'sample-fetch:workspace'), $this->limits->workspaceLimit()],
        ] as [$key, $max]) {
            if ($window === null || $max === null) {
                continue;
            }

            if ($this->limiter->hit($key, $window) > $max) {
                // At least a second: the window may roll over between the hit and the question, and the request is still refused.
                $retry = max($retry, 1, $this->limiter->availableIn($key));
            }
        }

        if ($retry > 0) {
            throw new SampleFetchThrottled($retry);
        }
    }
}
