<?php

namespace App\Modules\Access\Contracts;

/**
 * An Admin deactivates or reactivates another member (Story 1.24), in the caller's Workspace transaction. Only the
 * membership status changes: role, permissions and groups are kept and come back on reactivation. Deactivation also
 * revokes the member's sessions for this Workspace; the audit and outbox events are written in the same transaction.
 */
interface MemberActivation
{
    /**
     * @param  int  $revision  the revision the editor saw; a different one is refused (an already deactivated member is a no-op whatever it is)
     *
     * @throws EditorNotAuthorized
     * @throws MembershipNotFound
     * @throws SelfChangeForbidden
     * @throws RevisionConflict
     * @throws LastUsersManageHolder
     */
    public function deactivate(MemberEditor $editor, string $membershipId, int $revision): StatusChange;

    /**
     * @throws EditorNotAuthorized
     * @throws MembershipNotFound
     * @throws SelfChangeForbidden
     * @throws RevisionConflict
     */
    public function reactivate(MemberEditor $editor, string $membershipId, int $revision): StatusChange;
}
