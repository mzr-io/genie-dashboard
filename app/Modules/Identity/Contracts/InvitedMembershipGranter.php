<?php

namespace App\Modules\Identity\Contracts;

/**
 * The port through which accepting an invitation creates the membership. Identity sits below Access
 * and cannot call it, so Identity declares the port and Access implements it; the container binds them.
 * It runs inside the caller's Workspace transaction.
 */
interface InvitedMembershipGranter
{
    /**
     * Gives the user the Admin role with every Admin permission in the Workspace. No membership: one is created.
     * An active membership is raised to Admin and given all permissions. A membership that is not active is
     * never revived.
     *
     * @throws MembershipNotGrantable
     */
    public function grantAdmin(int $userId, string $workspaceId): MembershipGrant;
}
