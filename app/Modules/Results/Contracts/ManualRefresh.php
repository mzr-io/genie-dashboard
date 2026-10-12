<?php

namespace App\Modules\Results\Contracts;

/**
 * A User's own Refresh of one Block (Story 4.6). It touches the Block's subscription (so the target is hot), then brings the target forward for the
 * dispatcher; it never calls a source itself. Limited to one request per member and target in the `sync.manual_refresh_window` (a limit that is unset
 * does not apply); a limited request does nothing at all. Runs in the caller's Workspace transaction.
 */
interface ManualRefresh
{
    public function refresh(ViewRequest $request): RefreshOutcome;
}
