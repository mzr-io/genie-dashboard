<?php

namespace App\Modules\Access\Application;

use App\Modules\Access\Contracts\InvitationNotFound;
use App\Modules\Access\Contracts\InvitationOutcome;
use App\Modules\Access\Contracts\InvitationRefused;
use App\Modules\Access\Contracts\InvitationsNotConfigured;
use App\Modules\Access\Contracts\Inviter;
use App\Modules\Access\Contracts\MemberInvitations;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\Permission;
use App\Modules\Access\Contracts\PermissionNotHeld;
use App\Modules\Identity\Contracts\InvitationCourier;
use App\Modules\Identity\Contracts\InvitationIssuer;
use App\Modules\Identity\Contracts\InvitationLifetimeUnset;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Outbox\Outbox;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Invite, re-send and revoke (Story 1.21). Everything runs in the Workspace's transaction (the request's own): the
 * invitation rows change only through the `access_*_invitation` SECURITY DEFINER functions, which are bound to the
 * transaction's Workspace; the audit event and the `access.membership.changed` outbox event are written in the same
 * transaction; the email is only queued and goes out after the commit.
 *
 * The invitee's email is stored (lower-cased) in `invitations`, shown to Admins in the list and written to the
 * audit log as a keyed hash. It never reaches the outbox, a log line or a response other than the Admin's own.
 */
final class InviteMembers implements MemberInvitations
{
    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly MembershipPermissions $permissions,
        private readonly InvitationIssuer $lifetime,
        private readonly InvitationCourier $courier,
        private readonly Audit $audit,
        private readonly Outbox $outbox,
    ) {}

    public function assertGrantable(Inviter $inviter, string $role, array $permissions): void
    {
        $this->assertRole($role, $permissions);

        if ($permissions === []) {
            return;
        }

        $held = array_map(fn (Permission $permission): string => $permission->value, $this->permissions->forUser($inviter->userId, $inviter->workspaceId));
        $missing = array_values(array_diff($permissions, $held));

        if ($missing !== []) {
            throw new PermissionNotHeld($missing);
        }
    }

    public function invite(Inviter $inviter, string $email, string $role, array $permissions): InvitationOutcome
    {
        $permissions = $this->normalise($role, $permissions);
        $email = strtolower(trim($email));
        $hours = $this->hours();

        $this->assertGrantable($inviter, $role, $permissions);

        return $this->transactions->run($inviter->workspaceId, function () use ($inviter, $email, $role, $permissions, $hours): InvitationOutcome {
            if ($this->isMember($inviter->workspaceId, $email)) {
                throw new InvitationRefused(InvitationRefused::MEMBER);
            }

            $secret = $this->courier->newSecret();
            $expiresAt = now()->addHours($hours);
            $id = (string) Str::uuid7();

            /** @var object{invitation_id: string, was_created: bool|int|string} $row */
            $row = DB::selectOne('select * from access_create_invitation(?, ?, ?, ?, ?::jsonb, ?, ?)', [
                $id, $email, $secret->hash, $role, json_encode($permissions, JSON_THROW_ON_ERROR), $inviter->membershipId, $expiresAt->toIso8601String(),
            ]);

            if (! filter_var($row->was_created, FILTER_VALIDATE_BOOLEAN)) {
                throw new InvitationRefused(InvitationRefused::PENDING, $row->invitation_id);
            }

            $this->record($inviter, $id, $email, $role, count($permissions), InvitationAction::Created);
            $this->courier->deliverAfterCommit($id, $email, $secret, $inviter->workspaceName, $role, $expiresAt);

            return new InvitationOutcome($id, $email, $role);
        });
    }

    public function resend(Inviter $inviter, string $invitationId): InvitationOutcome
    {
        $hours = $this->hours();

        if (! Str::isUuid($invitationId)) {
            throw new InvitationNotFound;
        }

        return $this->transactions->run($inviter->workspaceId, function () use ($inviter, $invitationId, $hours): InvitationOutcome {
            $secret = $this->courier->newSecret();
            $expiresAt = now()->addHours($hours);

            /** @var object{invitation_id: string, invitee_email: string, invitee_role: string, granted_permissions: string, previous_created_by: string|null}|null $row */
            $row = DB::selectOne('select * from access_replace_invitation(?, ?, ?, ?)', [
                strtolower($invitationId), $secret->hash, $expiresAt->toIso8601String(), $inviter->membershipId,
            ]);

            if ($row === null) {
                throw new InvitationNotFound;
            }

            // The invitation now carries this person's name as its inviter, so it may not grant more than they hold
            // (a refusal rolls the replacement back).
            $permissions = $this->granted($row->granted_permissions);
            $this->assertGrantable($inviter, $row->invitee_role, $permissions);

            $this->record($inviter, $row->invitation_id, $row->invitee_email, $row->invitee_role, count($permissions), InvitationAction::Resent, $row->previous_created_by);
            $this->courier->deliverAfterCommit($row->invitation_id, $row->invitee_email, $secret, $inviter->workspaceName, $row->invitee_role, $expiresAt);

            return new InvitationOutcome($row->invitation_id, $row->invitee_email, $row->invitee_role);
        });
    }

    public function revoke(Inviter $inviter, string $invitationId): void
    {
        if (! Str::isUuid($invitationId)) {
            throw new InvitationNotFound;
        }

        $this->transactions->run($inviter->workspaceId, function () use ($inviter, $invitationId): void {
            /** @var object{invitation_id: string, invitee_role: string}|null $row */
            $row = DB::selectOne('select * from access_revoke_invitation(?)', [strtolower($invitationId)]);

            if ($row === null) {
                throw new InvitationNotFound;
            }

            $subject = 'invitation:'.$row->invitation_id;

            $this->audit->record(
                AuditAction::AccessMembershipChanged,
                ['invitation_id' => $row->invitation_id, 'role' => $row->invitee_role, 'status' => 'revoked'],
                ['invitation_id' => $row->invitation_id, 'role' => $row->invitee_role, 'status' => 'invited'],
                subject: $subject,
                actor: $inviter->membershipId,
            );

            $this->outbox->emit(AuditAction::AccessMembershipChanged, $subject, [
                'invitation_id' => $row->invitation_id,
                'role' => $row->invitee_role,
                'status' => 'revoked',
                'change' => 'revoked',
            ], actor: $inviter->membershipId);
        });
    }

    private function hours(): int
    {
        try {
            return $this->lifetime->lifetimeHours();
        } catch (InvitationLifetimeUnset) {
            throw new InvitationsNotConfigured;
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function normalise(string $role, array $permissions): array
    {
        $this->assertRole($role, $permissions);

        return array_values(array_unique($permissions));
    }

    /** @param  list<string>  $permissions */
    private function assertRole(string $role, array $permissions): void
    {
        if (! in_array($role, ['user', 'admin'], true)) {
            throw new InvalidArgumentException('An invitation is for the role user or admin.');
        }

        if ($role === 'user' && $permissions !== []) {
            throw new InvalidArgumentException('A user invitation carries no permissions.');
        }

        if (array_diff($permissions, Permission::values()) !== []) {
            throw new InvalidArgumentException('Invitation permissions come from the closed catalogue.');
        }
    }

    /** @return list<string> */
    private function granted(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }

    /** A membership of ANY status counts (suspended and removed people are still members of the Workspace). */
    private function isMember(string $workspaceId, string $email): bool
    {
        return DB::selectOne(
            'select 1 as found from access_workspace_members m where m.workspace_id = ? and lower(m.email) = ? limit 1',
            [$workspaceId, $email],
        ) !== null;
    }

    /** @param  string|null  $previousInviter  the membership that was the inviter of record before a re-send */
    private function record(Inviter $inviter, string $invitationId, string $email, string $role, int $permissionCount, InvitationAction $action, ?string $previousInviter = null): void
    {
        $subject = 'invitation:'.$invitationId;

        $this->audit->record(
            AuditAction::AccessMembershipInvited,
            ['invitation_id' => $invitationId, 'role' => $role, 'status' => 'invited', 'email' => $email, 'permission_count' => $permissionCount, 'reason' => $action->value, 'inviter_id' => $inviter->membershipId],
            $previousInviter === null ? [] : ['invitation_id' => $invitationId, 'inviter_id' => Str::isUuid($previousInviter) ? $previousInviter : null],
            subject: $subject,
            actor: $inviter->membershipId,
        );

        $this->outbox->emit(AuditAction::AccessMembershipChanged, $subject, [
            'invitation_id' => $invitationId,
            'role' => $role,
            'status' => 'invited',
            'change' => $action === InvitationAction::Created ? 'invited' : $action->value,
        ], actor: $inviter->membershipId);
    }
}
