<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Contracts\InvitedMembershipGranter;
use App\Modules\Identity\Contracts\InviterGone;
use App\Modules\Identity\Contracts\MembershipNotGrantable;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Accepts an invitation: finds it by the SHA-256 hash of the token, checks it (compared in constant time),
 * then in one Workspace transaction creates or reuses the user, grants the invited role and permissions (capped at what the inviter holds now),
 * audits `identity.invitation.accepted` and marks the invitation used.
 *
 * Every refusal (unknown, tampered, used, revoked, expired, wrong email) throws the same InvitationRejected and
 * records `identity.invitation.rejected` as a security event: reason and invitation ID, never the token.
 * An existing user keeps their name and password.
 */
final class AcceptInvitation
{
    /** Stands in for a stored hash on an unknown token, so every path does the same comparisons. */
    private const DUMMY_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private readonly WorkspaceTransaction $transactions,
        private readonly InvitedMembershipGranter $memberships,
        private readonly Audit $audit,
    ) {}

    /**
     * @return string the ID of the new membership
     *
     * @throws InvitationRejected
     */
    public function handle(string $token, string $email, string $name, string $password): string
    {
        try {
            $invitation = $this->check($token, $email);

            return $this->transactions->run($invitation->workspaceId, fn (): string => $this->accept($invitation->id, $email, $name, $password));
        } catch (InvitationRejected $rejected) {
            $this->recordRejection($rejected);

            throw $rejected;
        }
    }

    /**
     * Whether the link still opens the form (no email given yet). Records the rejection like `handle`.
     *
     * @throws InvitationRejected
     */
    public function assertOpen(string $token): void
    {
        try {
            $this->check($token, null);
        } catch (InvitationRejected $rejected) {
            $this->recordRejection($rejected);

            throw $rejected;
        }
    }

    private function check(string $token, ?string $email): InvitationRecord
    {
        $hash = InvitationToken::wellFormed($token) ? InvitationToken::hash($token) : InvitationToken::hash('malformed');
        $invitation = InvitationToken::wellFormed($token)
            ? InvitationRecord::fromRow(DB::table('invitations')->where('token_hash', $hash)->first())
            : null;

        // Constant-time comparison, also on the unknown path (against a dummy), so timing does not tell them apart.
        $matches = hash_equals($invitation->tokenHash ?? self::DUMMY_HASH, $hash);

        if ($invitation === null || ! $matches) {
            throw new InvitationRejected(RejectionReason::Unknown);
        }

        $this->assertUsable($invitation, $email);

        return $invitation;
    }

    private function assertUsable(InvitationRecord $invitation, ?string $email): void
    {
        $reject = fn (RejectionReason $reason): InvitationRejected => new InvitationRejected($reason, $invitation->id, $invitation->workspaceId);

        if ($invitation->usedAt !== null) {
            throw $reject(RejectionReason::Used);
        }

        // A revoked link answers like an expired one.
        if ($invitation->revokedAt !== null) {
            throw $reject(RejectionReason::Revoked);
        }

        if (now()->greaterThanOrEqualTo($invitation->expiresAt)) {
            throw $reject(RejectionReason::Expired);
        }

        // An invitation is for `user` or `admin`; any other value is refused rather than upgraded.
        if (! in_array($invitation->role, ['user', 'admin'], true)) {
            throw $reject(RejectionReason::UnsupportedRole);
        }

        if ($email !== null && ! hash_equals(strtolower($invitation->email), strtolower($email))) {
            throw $reject(RejectionReason::EmailMismatch);
        }

    }

    private function accept(string $invitationId, string $email, string $name, string $password): string
    {
        // Lock the row so two concurrent uses cannot both pass: the second sees `used_at` set.
        $invitation = InvitationRecord::fromRow(DB::table('invitations')->where('id', $invitationId)->lockForUpdate()->first());

        if ($invitation === null) {
            throw new InvitationRejected(RejectionReason::Unknown);
        }

        $this->assertUsable($invitation, $email);

        $user = $this->user($email, $name, $password);

        try {
            $grant = $this->memberships->grant($user->id, $invitation->workspaceId, $invitation->role, $invitation->permissions, $invitation->createdBy);
        } catch (MembershipNotGrantable) {
            throw new InvitationRejected(RejectionReason::MembershipInactive, $invitation->id, $invitation->workspaceId);
        } catch (InviterGone) {
            throw new InvitationRejected(RejectionReason::InviterGone, $invitation->id, $invitation->workspaceId);
        }

        $this->audit->record(
            AuditAction::IdentityInvitationAccepted,
            ['invitation_id' => $invitation->id, 'user_id' => $user->id, 'membership_id' => $grant->membershipId, 'membership' => $grant->outcome, 'email' => strtolower($email)],
            subject: 'invitation:'.$invitation->id,
            actor: $grant->membershipId,
        );

        $membershipId = $grant->membershipId;

        DB::table('invitations')->where('id', $invitation->id)->update(['used_at' => now(), 'updated_at' => now()]);

        return $membershipId;
    }

    /** The user for the email, created if new. A concurrent creation of the same email is reused, not a 500. */
    private function user(string $email, string $name, string $password): User
    {
        $find = fn (): ?User => User::query()->whereRaw('lower(email) = ?', [strtolower($email)])->first();

        $user = $find();

        if ($user instanceof User) {
            return $user;
        }

        $user = new User(['name' => $name, 'email' => strtolower($email), 'password' => $password]);
        $user->forceFill(['email_verified_at' => now()]);

        try {
            // A savepoint: a unique violation must not abort the surrounding transaction.
            DB::transaction(fn () => $user->save());
        } catch (UniqueConstraintViolationException) {
            $concurrent = $find();

            return $concurrent instanceof User ? $concurrent : throw new InvitationRejected(RejectionReason::Unknown);
        }

        return $user;
    }

    /** A security event when the Workspace is known; a scrubbed log line (reason only) when it is not. */
    private function recordRejection(InvitationRejected $rejected): void
    {
        try {
            if ($rejected->workspaceId !== null) {
                $this->audit->recordSecurityEvent(
                    AuditAction::IdentityInvitationRejected,
                    ['reason' => $rejected->reason->value, 'invitation_id' => $rejected->invitationId],
                    $rejected->workspaceId,
                    subject: $rejected->invitationId === null ? null : 'invitation:'.$rejected->invitationId,
                );

                return;
            }

            // No Workspace exists to hold an audit row for an unknown token; log the event without the token.
            Log::warning('identity.invitation.rejected', ['reason' => $rejected->reason->value]);
        } catch (Throwable) {
            // Recording must never turn a refusal into an error that tells a visitor something.
        }
    }
}
