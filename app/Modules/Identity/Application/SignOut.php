<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Contracts\SignInMemberships;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ends a session on purpose: the session ID is rotated first, then the person is signed out and the session
 * invalidated, and `identity.signout.completed` is recorded in the Workspace the person was working in (a
 * log line when there is none). Recording never changes the outcome: the person is signed out either way.
 */
final class SignOut
{
    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly SignInMemberships $memberships,
        private readonly Audit $audit,
    ) {}

    public function handle(Request $request): void
    {
        $user = $request->user();
        $session = $request->hasSession() ? $request->session() : null;
        $workspaceId = $session?->get(WorkspaceTransaction::SESSION_KEY);
        $area = $session?->get('area');

        // Rotate before destroying, so the old ID is never reused for the invalidated session.
        $session?->regenerate();
        $this->guard->logout();

        if ($session !== null) {
            $session->invalidate();
            $session->regenerateToken();
        }

        $this->record($user instanceof User ? $user : null, is_string($workspaceId) ? $workspaceId : null, is_string($area) ? $area : null);
    }

    private function record(?User $user, ?string $workspaceId, ?string $area): void
    {
        try {
            $membershipId = $user === null || $workspaceId === null ? null : $this->membershipId($user->id, $workspaceId);

            if ($workspaceId === null || $membershipId === null) {
                // No Workspace exists to hold an audit row: the reason only.
                Log::warning(AuditAction::IdentitySignoutCompleted->value, ['reason' => 'no_workspace']);

                return;
            }

            $this->audit->recordSecurityEvent(
                AuditAction::IdentitySignoutCompleted,
                ['user_id' => $user->id, 'membership_id' => $membershipId, 'area' => $area],
                $workspaceId,
                subject: 'membership:'.$membershipId,
                actor: $membershipId,
            );
        } catch (Throwable $e) {
            try {
                // IDs and the exception class only, never data.
                Log::error('identity.signout.record_failed', ['workspace_id' => $workspaceId, 'exception' => $e::class]);
            } catch (Throwable) {
            }
        }
    }

    private function membershipId(int $userId, string $workspaceId): ?string
    {
        foreach ($this->memberships->forUser($userId) as $membership) {
            if ($membership->workspaceId === $workspaceId) {
                return $membership->membershipId;
            }
        }

        return null;
    }
}
