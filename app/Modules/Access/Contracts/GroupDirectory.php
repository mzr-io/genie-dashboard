<?php

namespace App\Modules\Access\Contracts;

/** The Workspace's groups and their members (Story 1.23). Another Workspace's groups are invisible (row-level security). */
interface GroupDirectory
{
    public function list(string $workspaceId, GroupQuery $query): GroupPage;

    /** A group of the Workspace by ID; null when it does not exist there. */
    public function find(string $workspaceId, string $groupId): ?GroupRow;
}
