<?php

namespace App\Modules\Access\Contracts;

/** The Admin permissions a person holds in one Workspace (read for the shell's navigation; Story 1.19 enforces them). */
interface MembershipPermissions
{
    /**
     * The permissions of the person's active membership in the Workspace; none when the membership is absent or inactive.
     *
     * @return list<Permission>
     */
    public function forUser(int $userId, string $workspaceId): array;
}
