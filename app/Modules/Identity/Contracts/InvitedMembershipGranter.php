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
     * Gives the user the invited role and permissions in the Workspace. No membership: one is created. An active
     * membership keeps its role unless an Admin invitation promotes a User, and only gains permissions. A membership
     * that is not active is never revived.
     *
     * The permissions are capped at what the inviter holds now: `$invitedBy` is the inviting membership's ID, which
     * must still be an active Admin membership of the Workspace. Only an `operator:` value (the operator's first-Admin
     * invitation) is exempt and uncapped; anything else is refused.
     *
     * @param  string  $role  `user` or `admin`
     * @param  list<string>  $permissions  Admin permissions invited; ignored for role `user`
     * @param  string|null  $invitedBy  `invitations.created_by`
     *
     * @throws MembershipNotGrantable
     * @throws InviterGone
     */
    public function grant(int $userId, string $workspaceId, string $role, array $permissions, ?string $invitedBy): MembershipGrant;
}
