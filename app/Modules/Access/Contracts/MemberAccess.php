<?php

namespace App\Modules\Access\Contracts;

use Closure;

/**
 * An Admin changes another member's role and permissions (Story 1.22), in the caller's Workspace transaction: the
 * membership row and every active holder of `users.manage` are locked, the audit and outbox events are written in the
 * same transaction, and nothing is cached (the next request reads the new state).
 */
interface MemberAccess
{
    /**
     * @param  int  $revision  the revision the editor saw; a different one is refused
     * @param  string|null  $role  `user` or `admin`; null keeps the current role
     * @param  list<string>|null  $permissions  the complete desired set (catalogue values); null keeps it
     * @param  Closure(): bool  $confirmPassword  checks the editor's password; called only when the change needs it (it may throw ConfirmationThrottled)
     *
     * @throws EditorNotAuthorized
     * @throws MembershipNotFound
     * @throws MembershipInactive
     * @throws SelfChangeForbidden
     * @throws RevisionConflict
     * @throws PermissionsOnUserRole
     * @throws PermissionNotHeld
     * @throws LastUsersManageHolder
     * @throws PasswordNotConfirmed
     * @throws ConfirmationThrottled
     */
    public function update(MemberEditor $editor, string $membershipId, int $revision, ?string $role, ?array $permissions, Closure $confirmPassword): AccessChange;
}
