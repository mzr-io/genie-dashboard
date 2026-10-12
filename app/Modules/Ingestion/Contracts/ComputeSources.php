<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * The compute-facing read of sync targets and their subscriptions (Story 4.2; AD-25). Read-only, in the caller's Workspace transaction. Only a
 * live target (not retired) that holds a payload is returned.
 */
interface ComputeSources
{
    public function target(string $workspaceId, string $syncTargetId): ?ComputeTarget;

    /**
     * The live target a Block version watches in one compute context, with its subscription; null while there is none or the target holds no payload.
     * An empty `$computeContext` is a subscription stored without one.
     */
    public function watching(string $workspaceId, string $blockVersionId, string $computeContext): ?WatchedTarget;

    /** @return list<ComputeSubscription> the target's primary subscriptions, one per Block version and compute context */
    public function subscriptions(string $workspaceId, string $syncTargetId): array;

    /**
     * The live targets of the Workspace that hold a payload and have a primary subscription, by ID, after `$afterId`.
     *
     * @return list<ComputeTarget>
     */
    public function watched(string $workspaceId, int $limit, ?string $afterId = null): array;
}
