<?php

namespace App\Modules\Dashboards\Contracts;

/**
 * A member's own dashboards (Story 4.4). Every call runs in the caller's Workspace transaction and is scoped to one member: another member's
 * dashboard and another Workspace's are the same {@see DashboardNotFound}.
 */
interface Dashboards
{
    /** The member's Overview, created on first use (empty, `revision` 0). Idempotent under concurrent first opens. */
    public function overview(string $workspaceId, string $membershipId): DashboardRef;

    /** @throws DashboardNotFound */
    public function find(string $workspaceId, string $membershipId, string $dashboardId): DashboardView;
}
