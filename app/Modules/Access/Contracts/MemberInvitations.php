<?php

namespace App\Modules\Access\Contracts;

/**
 * An Admin invites people to the Workspace, re-sends an invitation (which replaces its token) and revokes one
 * (Story 1.21). Each change audits and emits `access.membership.changed` in the caller's Workspace transaction; the
 * email goes out only after that transaction commits.
 */
interface MemberInvitations
{
    /**
     * Refuses permissions the inviter does not hold (read now, never cached).
     *
     * @param  string  $role  `user` or `admin`
     * @param  list<string>  $permissions
     *
     * @throws PermissionNotHeld
     */
    public function assertGrantable(Inviter $inviter, string $role, array $permissions): void;

    /**
     * @param  string  $role  `user` or `admin`
     * @param  list<string>  $permissions  catalogue values; none for role `user`
     *
     * @throws PermissionNotHeld
     * @throws InvitationRefused
     * @throws InvitationsNotConfigured
     */
    public function invite(Inviter $inviter, string $email, string $role, array $permissions): InvitationOutcome;

    /**
     * Replaces the pending invitation's token (the old link stops working) and sends the email again.
     *
     * @throws InvitationNotFound
     * @throws PermissionNotHeld when the invitation grants permissions the person re-sending does not hold
     * @throws InvitationsNotConfigured
     */
    public function resend(Inviter $inviter, string $invitationId): InvitationOutcome;

    /** @throws InvitationNotFound */
    public function revoke(Inviter $inviter, string $invitationId): void;
}
