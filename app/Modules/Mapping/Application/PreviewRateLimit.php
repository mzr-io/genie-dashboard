<?php

namespace App\Modules\Mapping\Application;

use App\Modules\Mapping\Contracts\PreviewThrottle;
use App\Modules\Mapping\Contracts\PreviewThrottled;
use App\Modules\Mapping\Infrastructure\PreviewSettings;
use App\Platform\Tenancy\TenantKey;
use Illuminate\Cache\RateLimiter;

/** The per-membership preview limit, counted before the run with the rule of the Endpoint fetch limits: count first, then compare. */
final class PreviewRateLimit implements PreviewThrottle
{
    public function __construct(
        private readonly PreviewSettings $settings,
        private readonly RateLimiter $limiter,
    ) {}

    public function hit(string $workspaceId, string $membershipId): void
    {
        $max = $this->settings->membershipLimit();
        $window = $this->settings->windowSeconds();
        $key = TenantKey::cache($workspaceId, 'preview:membership:'.strtolower($membershipId));

        if ($this->limiter->hit($key, $window) > $max) {
            // At least a second: the window may roll over between the hit and the question, and the request is still refused.
            throw new PreviewThrottled(max(1, $this->limiter->availableIn($key)));
        }
    }
}
