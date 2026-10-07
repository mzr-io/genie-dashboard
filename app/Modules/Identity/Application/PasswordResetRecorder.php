<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Contracts\SignInMembership;
use App\Modules\Identity\Contracts\SignInMemberships;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records a completed password reset as `identity.password.reset`, and a password change from Profile &
 * settings as `identity.password.changed`: security events in the person's active Workspace. With no
 * Workspace to hold one, a structured log line with the reason only is written. They carry no email, token
 * or password, and a failure to record never undoes the change.
 */
final class PasswordResetRecorder
{
    public function __construct(
        private readonly Audit $audit,
        private readonly SignInMemberships $memberships,
    ) {}

    public function reset(User $user): void
    {
        $this->record($user, AuditAction::IdentityPasswordReset);
    }

    public function changed(User $user): void
    {
        $this->record($user, AuditAction::IdentityPasswordChanged);
    }

    private function record(User $user, AuditAction $action): void
    {
        try {
            $membership = SignInMembership::active($this->memberships->forUser($user->id));

            if ($membership === null) {
                Log::warning($action->value, ['reason' => 'no_membership']);

                return;
            }

            $this->audit->recordSecurityEvent(
                $action,
                ['user_id' => $user->id, 'membership_id' => $membership->membershipId],
                $membership->workspaceId,
                subject: 'membership:'.$membership->membershipId,
                actor: $membership->membershipId,
            );
        } catch (Throwable) {
            try {
                Log::error($action->value.'.record_failed', ['action' => $action->value]);
            } catch (Throwable) {
            }
        }
    }
}
