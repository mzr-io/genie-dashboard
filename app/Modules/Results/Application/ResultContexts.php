<?php

namespace App\Modules\Results\Application;

use App\Modules\Ingestion\Contracts\ComputeSubscription;
use App\Modules\Results\Contracts\ComputeContext;
use App\Platform\Tenancy\WorkspaceSettings;

/** The compute context of a subscription: the one its subscriber stored, or, when it stored none, the one its period and the Workspace give. */
final class ResultContexts
{
    public static function of(ComputeSubscription $subscription, int $shaperContractVersion, WorkspaceSettings $settings): string
    {
        if ($subscription->computeContext !== '') {
            return $subscription->computeContext;
        }

        $period = $subscription->periodStart !== null && $subscription->periodEnd !== null
            ? ['start' => $subscription->periodStart, 'end' => $subscription->periodEnd]
            : null;

        return ComputeContext::of($period, null, $settings->timezone(), $settings->revision(), $shaperContractVersion);
    }
}
