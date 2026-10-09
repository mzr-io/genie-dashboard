<?php

namespace App\Modules\Mapping\Contracts;

/** The per-membership preview limit (`DASHFLOW_PREVIEW_MEMBERSHIP_LIMIT` in `DASHFLOW_PREVIEW_WINDOW_SECONDS`). */
interface PreviewThrottle
{
    /**
     * Counts one preview of the membership.
     *
     * @throws SettingNotConfigured the limit or the window is unset (nothing is counted)
     * @throws PreviewThrottled over the limit
     */
    public function hit(string $workspaceId, string $membershipId): void;
}
