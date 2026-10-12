<?php

namespace App\Http\Controllers\Admin;

use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Blocks\Contracts\DraftNotFound;
use App\Modules\Blocks\Contracts\Drafts;
use App\Platform\EditLock\EditLock;

/** The soft lock on a Draft block (Story 3.6), behind `blocks.edit`. A Draft of another Workspace, or a discarded one, is a 404. */
final class DraftLockController extends ResourceLockController
{
    public function __construct(EditLock $lock, private readonly Drafts $drafts, MembershipLookup $memberships)
    {
        parent::__construct($lock, $memberships);
    }

    protected function lockType(): string
    {
        return Drafts::LOCK_TYPE;
    }

    protected function assertVisible(string $workspaceId, string $resource): void
    {
        try {
            $this->drafts->find($workspaceId, $resource);
        } catch (DraftNotFound) {
            abort(404);
        }
    }
}
