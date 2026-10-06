<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Contracts\SignInArea;
use App\Modules\Identity\Contracts\SignInMembership;
use App\Modules\Identity\Contracts\SignInMemberships;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records every sign-in outcome. With a Workspace (the person's active one) it is a security event in
 * that Workspace's audit log; without one (unknown email, no membership) it is a structured log line with
 * the reason only. Neither carries the password, the raw email or a token. Recording never changes the
 * outcome: a failure to record is logged and swallowed.
 */
final class SignInRecorder
{
    public function __construct(
        private readonly Audit $audit,
        private readonly SignInMemberships $memberships,
    ) {}

    public function succeeded(User $user, SignInMembership $membership, SignInArea $area): void
    {
        $this->event(AuditAction::IdentitySigninSucceeded, 'succeeded', $membership->workspaceId, [
            'user_id' => $user->id,
            'membership_id' => $membership->membershipId,
            'area' => $area->value,
        ], $membership);
    }

    public function areaDenied(User $user, SignInMembership $membership, SignInArea $area): void
    {
        $this->event(AuditAction::IdentityAreaDenied, 'area_denied', $membership->workspaceId, [
            'user_id' => $user->id,
            'membership_id' => $membership->membershipId,
            'area' => $area->value,
        ], $membership);
    }

    /** @param  string  $reason  `bad_password`, `unknown_user` or `no_membership` */
    public function failed(?User $user, string $reason, ?SignInMembership $membership = null): void
    {
        $this->event(AuditAction::IdentitySigninFailed, 'failed', $membership?->workspaceId, [
            'user_id' => $user?->id,
            'reason' => $reason,
        ], $membership, $reason);
    }

    /** An attempt refused by the throttle. The email only finds the person's Workspace; it is never stored. */
    public function throttled(string $email): void
    {
        try {
            $user = User::query()->whereRaw('lower(email) = ?', [strtolower($email)])->first();
            $membership = $user === null ? null : SignInMembership::active($this->memberships->forUser($user->id));
        } catch (Throwable) {
            $user = $membership = null;
        }

        $this->event(AuditAction::IdentitySigninThrottled, 'throttled', $membership?->workspaceId, [
            'user_id' => $user?->id,
            'reason' => 'throttled',
        ], $membership, 'throttled');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function event(AuditAction $action, string $outcome, ?string $workspaceId, array $values, ?SignInMembership $membership, ?string $reason = null): void
    {
        try {
            if ($workspaceId !== null) {
                $this->audit->recordSecurityEvent(
                    $action,
                    $values,
                    $workspaceId,
                    subject: $membership === null ? null : 'membership:'.$membership->membershipId,
                    actor: $outcome === 'succeeded' && $membership !== null ? $membership->membershipId : null,
                );

                return;
            }

            // No Workspace exists to hold an audit row: the reason only, never the email.
            Log::warning($action->value, ['reason' => $reason ?? $outcome]);
        } catch (Throwable) {
            // A sign-in outcome must never turn into an error that tells a visitor something.
            try {
                Log::error('identity.signin.record_failed', ['action' => $action->value]);
            } catch (Throwable) {
            }
        }
    }
}
