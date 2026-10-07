<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\AccessChange;
use App\Modules\Access\Contracts\EditorNotAuthorized;
use App\Modules\Access\Contracts\LastUsersManageHolder;
use App\Modules\Access\Contracts\MemberActivation;
use App\Modules\Access\Contracts\MemberEditor;
use App\Modules\Access\Contracts\MembershipNotFound;
use App\Modules\Access\Contracts\RevisionConflict;
use App\Modules\Access\Contracts\SelfChangeForbidden;
use App\Modules\Access\Contracts\StatusChange;
use App\Modules\Identity\Contracts\SessionRevocation;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Deactivates and reactivates a member (Story 1.24) inside the Workspace's transaction. Only `status` and `revision`
 * change: role, permissions and groups stay, so reactivation restores the previous access. The locks and the
 * last-holder recount are the ones of Story 1.22 (`MembershipLocks`): the target and every active `users.manage`
 * holder are locked in one statement, and the holders are counted again after the locks.
 *
 * The order of refusals is: self change, the editor's own authority (an active Admin holding `users.manage` among the
 * locked rows), not found, then (a member already in the target status is a no-op whatever the revision) a stale
 * revision, and for a deactivation the last `users.manage` holder. Deactivation revokes the member's sessions for this
 * Workspace in the same transaction (also on a repeated deactivation) and audits how many it ended (`session_count`). Audit and outbox events (`access.membership.deactivated` and `.reactivated`)
 * carry IDs and enums only, never emails; a no-op writes neither. Any status other than `active` counts as deactivated.
 */
final class ChangeMemberStatus implements MemberActivation
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly MembershipLocks $locks,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
        private readonly SessionRevocation $sessions,
    ) {}

    public function deactivate(MemberEditor $editor, string $membershipId, int $revision): StatusChange
    {
        return $this->change($editor, $membershipId, $revision, StatusChange::DEACTIVATED);
    }

    public function reactivate(MemberEditor $editor, string $membershipId, int $revision): StatusChange
    {
        return $this->change($editor, $membershipId, $revision, StatusChange::ACTIVE);
    }

    private function change(MemberEditor $editor, string $membershipId, int $revision, string $to): StatusChange
    {
        if (! Str::isUuid($membershipId)) {
            throw new MembershipNotFound;
        }

        $membershipId = strtolower($membershipId);

        if ($membershipId === strtolower($editor->membershipId)) {
            throw new SelfChangeForbidden;
        }

        return $this->transactions->run($editor->workspaceId, function () use ($editor, $membershipId, $revision, $to): StatusChange {
            $locked = $this->locks->lock($editor->workspaceId, $membershipId);

            if (! in_array(strtolower($editor->membershipId), $locked, true)) {
                throw new EditorNotAuthorized;
            }

            if (! in_array($membershipId, $locked, true)) {
                throw new MembershipNotFound;
            }

            $target = $this->locks->read($membershipId);

            if ($target === null) {
                throw new MembershipNotFound;
            }

            $from = $target->status === StatusChange::ACTIVE ? StatusChange::ACTIVE : StatusChange::DEACTIVATED;

            // Already in the target status: nothing to write.
            if ($from === $to) {
                // A repeated deactivation still ends any session that outlived the first one.
                if ($to === StatusChange::DEACTIVATED) {
                    $this->revoke($membershipId, $editor->workspaceId);
                }

                return new StatusChange($membershipId, $from, $target->revision, false);
            }

            if ($target->revision !== $revision) {
                throw new RevisionConflict(new AccessChange($membershipId, $target->role, $target->permissions, $target->revision, false, $from));
            }

            if ($to === StatusChange::DEACTIVATED) {
                $this->assertNotLastHolder($editor->workspaceId, $membershipId, $target);
            }

            $updated = DB::update(
                'update workspace_memberships set status = ?, revision = revision + 1, updated_at = ? where id = ? and workspace_id = ?',
                [$to, now(), $membershipId, $editor->workspaceId],
            );

            // Exactly one row, or nothing is written (the transaction rolls back before any audit or outbox row).
            if ($updated !== 1) {
                throw new MembershipNotFound;
            }

            $revoked = $to === StatusChange::DEACTIVATED ? $this->revoke($membershipId, $editor->workspaceId) : null;

            $this->record($editor, $membershipId, $from, $to, $revoked);

            return new StatusChange($membershipId, $to, $target->revision + 1);
        });
    }

    /** Deletes the member's sessions for this Workspace; the number deleted. */
    private function revoke(string $membershipId, string $workspaceId): int
    {
        $row = DB::selectOne('select user_id from workspace_memberships where id = ?', [$membershipId]);

        return $row === null ? 0 : $this->sessions->revokeForWorkspace((int) $row->user_id, $workspaceId);
    }

    private function assertNotLastHolder(string $workspaceId, string $membershipId, AccessSnapshot $target): void
    {
        if ($this->locks->isActiveHolder($target->role, $target->status, $target->permissions) && $this->locks->otherHolders($workspaceId, $membershipId) === []) {
            throw new LastUsersManageHolder;
        }
    }

    private function record(MemberEditor $editor, string $membershipId, string $from, string $to, ?int $revoked): void
    {
        $action = $to === StatusChange::DEACTIVATED ? AuditAction::AccessMembershipDeactivated : AuditAction::AccessMembershipReactivated;
        $subject = 'membership:'.$membershipId;

        $this->audit->record(
            $action,
            ['membership_id' => $membershipId, 'status' => $to] + ($revoked === null ? [] : ['session_count' => $revoked]),
            ['membership_id' => $membershipId, 'status' => $from],
            subject: $subject,
            actor: $editor->membershipId,
        );
        $this->outbox->emit($action, $subject, [
            'membership_id' => $membershipId,
            'status' => $to,
            'previous_status' => $from,
        ], actor: $editor->membershipId);
    }
}
