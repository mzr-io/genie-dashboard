<?php

namespace App\Http\Controllers\Admin;

use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Connector\Contracts\DataSourceNotFound;
use App\Modules\Connector\Contracts\DataSources;
use App\Platform\EditLock\EditLock;

/** The soft lock on the Data source form (Story 2.8); the protocol lives in {@see ResourceLockController}. */
final class DataSourceLockController extends ResourceLockController
{
    public function __construct(EditLock $lock, private readonly DataSources $sources, MembershipLookup $memberships)
    {
        parent::__construct($lock, $memberships);
    }

    protected function lockType(): string
    {
        return DataSources::LOCK_TYPE;
    }

    protected function assertVisible(string $workspaceId, string $resource): void
    {
        try {
            $this->sources->find($workspaceId, $resource);
        } catch (DataSourceNotFound) {
            abort(404);
        }
    }
}
