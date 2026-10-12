<?php

namespace App\Modules\Results\Application;

use App\Modules\Ingestion\Contracts\RefreshNow;
use App\Modules\Results\Contracts\ManualRefresh;
use App\Modules\Results\Contracts\RefreshOutcome;
use App\Modules\Results\Contracts\ViewedResults;
use App\Modules\Results\Contracts\ViewRequest;
use App\Platform\Tenancy\TenantCache;

/** {@see ManualRefresh}. The window is a per-(member, target) timestamp in the `cache` store with a TTL; the rate check comes before anything is written. */
final class RefreshBlock implements ManualRefresh
{
    public function __construct(
        private readonly ViewedResults $views,
        private readonly RefreshNow $refresh,
        private readonly TenantCache $cache,
    ) {}

    public function refresh(ViewRequest $request): RefreshOutcome
    {
        $workspaceId = strtolower($request->workspaceId);
        $viewed = $this->views->view($request);

        if ($viewed->syncTargetId === null) {
            return RefreshOutcome::unavailable();
        }

        $window = $this->refresh->windowSeconds();
        $key = 'manual-refresh:'.strtolower($request->membershipId).':'.$viewed->syncTargetId;

        if ($window !== null) {
            $until = $this->cache->get($workspaceId, $key);

            if (is_int($until) && $until > time()) {
                return RefreshOutcome::limited($until - time());
            }

            $this->cache->put($workspaceId, $key, time() + $window, $window);
        }

        $this->refresh->now($workspaceId, $viewed->syncTargetId);

        return RefreshOutcome::requested();
    }
}
