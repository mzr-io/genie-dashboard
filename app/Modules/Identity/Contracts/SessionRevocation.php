<?php

namespace App\Modules\Identity\Contracts;

/**
 * Ends a person's open sessions for one Workspace (Story 1.24). Identity owns the `sessions` table; Access calls this
 * port when it deactivates a membership. Sessions of the person's other Workspaces are never touched.
 */
interface SessionRevocation
{
    /**
     * Deletes the user's sessions whose stored active Workspace is `$workspaceId`. A session whose payload cannot be
     * decoded safely is left alone (the per-request membership check covers it).
     *
     * @return int the number of sessions deleted
     */
    public function revokeForWorkspace(int $userId, string $workspaceId): int;
}
