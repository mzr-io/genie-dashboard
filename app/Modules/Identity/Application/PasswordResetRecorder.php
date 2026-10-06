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
 * Records a completed password reset as `identity.password.reset`, a security event in the person's
 * active Workspace. With no Workspace to hold it, a structured log line with the reason only is written.
 * It carries no email, token or password, and a failure to record never undoes the reset.
 */
final class PasswordResetRecorder
{
    public function __construct(
        private readonly Audit $audit,
        private readonly SignInMemberships $memberships,
    ) {}

    public function reset(User $user): void
    {
        try {
            $membership = SignInMembership::active($this->memberships->forUser($user->id));

            if ($membership === null) {
                Log::warning(AuditAction::IdentityPasswordReset->value, ['reason' => 'no_membership']);

                return;
            }

            $this->audit->recordSecurityEvent(
                AuditAction::IdentityPasswordReset,
                ['user_id' => $user->id, 'membership_id' => $membership->membershipId],
                $membership->workspaceId,
                subject: 'membership:'.$membership->membershipId,
                actor: $membership->membershipId,
            );
        } catch (Throwable) {
            try {
                Log::error('identity.password.reset.record_failed', ['action' => AuditAction::IdentityPasswordReset->value]);
            } catch (Throwable) {
            }
        }
    }
}
