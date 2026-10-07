<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Contracts\SignInArea;
use App\Modules\Identity\Contracts\SignInMembership;
use App\Modules\Identity\Contracts\SignInMemberships;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Switches the active Workspace. The ID from the client is never trusted: the person's memberships are
 * re-read through the Access lookup and the target must be a usable membership (active membership in an
 * active Workspace). Anything else is refused, recorded as `access.workspace.forbidden` in the current
 * Workspace (a log line when there is none) and changes nothing.
 *
 * A valid switch stamps `last_active_at` for the chosen membership and records `identity.workspace.switched`
 * in the new Workspace's audit log in one transaction of that Workspace, then rotates the session ID and
 * sets `workspace_id` and `area`. The area is kept when the person holds the same role there (the Admin
 * area needs an Admin membership); otherwise it becomes User.
 */
final class SwitchWorkspace
{
    public function __construct(
        private readonly SignInMemberships $memberships,
        private readonly WorkspaceTransaction $transactions,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws WorkspaceSwitchRefused
     */
    public function handle(Request $request, mixed $target): WorkspaceSwitched
    {
        $user = $request->user();
        $session = $request->hasSession() ? $request->session() : null;

        if (! $user instanceof User || $session === null) {
            throw new WorkspaceSwitchRefused;
        }

        $current = $session->get(WorkspaceTransaction::SESSION_KEY);
        $current = is_string($current) && Str::isUuid($current) ? strtolower($current) : null;
        $area = $session->get('area') === SignInArea::Admin->value ? SignInArea::Admin : SignInArea::User;

        try {
            $memberships = $this->memberships->forUser($user->id);
        } catch (Throwable $e) {
            // A failed lookup is a refusal, never a 500 and never data.
            Log::error('identity.workspace.lookup_failed', ['exception' => $e::class]);

            throw new WorkspaceSwitchRefused;
        }

        $chosen = null;

        if (is_string($target) && Str::isUuid($target)) {
            $target = strtolower($target);

            foreach ($memberships as $membership) {
                if ($membership->workspaceId === $target && $membership->usable) {
                    $chosen = $membership;
                    break;
                }
            }
        }

        if (! $chosen instanceof SignInMembership) {
            $this->forbidden($user, $current, $area, $memberships);

            throw new WorkspaceSwitchRefused;
        }

        $kept = $area === SignInArea::Admin && $chosen->isAdmin() ? SignInArea::Admin : SignInArea::User;

        // Already there: nothing rotates, nothing is stamped or audited; the person lands on the area Overview.
        if ($chosen->workspaceId === $current) {
            return new WorkspaceSwitched($chosen->workspaceId, $chosen->workspaceName, $kept, false);
        }

        $this->transactions->run($chosen->workspaceId, function () use ($chosen, $user, $kept): void {
            // The read above is not atomic with this write: look again inside the target Workspace's
            // transaction and abort unless the membership and the Workspace are still usable.
            $usable = false;

            foreach ($this->memberships->forUser($user->id) as $membership) {
                if ($membership->membershipId === $chosen->membershipId && $membership->workspaceId === $chosen->workspaceId && $membership->usable) {
                    $usable = true;
                    break;
                }
            }

            if (! $usable || ! $this->memberships->markActive($chosen->workspaceId, $chosen->membershipId)) {
                throw new WorkspaceSwitchRefused;
            }

            $this->audit->record(
                AuditAction::IdentityWorkspaceSwitched,
                [
                    'user_id' => $user->id,
                    'membership_id' => $chosen->membershipId,
                    'to_workspace_id' => $chosen->workspaceId,
                    'area' => $kept->value,
                ],
                subject: 'membership:'.$chosen->membershipId,
                actor: $chosen->membershipId,
            );
        });

        // Rotate, then store: the old session ID is never reused for the new Workspace.
        $session->regenerate(true);
        $session->put(WorkspaceTransaction::SESSION_KEY, $chosen->workspaceId);
        $session->put('area', $kept->value);
        SessionClock::touch($session);

        return new WorkspaceSwitched($chosen->workspaceId, $chosen->workspaceName, $kept, $area === SignInArea::Admin && $kept === SignInArea::User);
    }

    /**
     * The refusal is a security event in the Workspace the person is working in; with none there is no
     * audit log to hold it, so a log line with the reason only is written. Never the submitted ID.
     *
     * @param  list<SignInMembership>  $memberships
     */
    private function forbidden(User $user, ?string $current, SignInArea $area, array $memberships): void
    {
        try {
            // At most one security event per person per minute, so a forged-ID loop cannot flood the log.
            if (! Cache::add('workspace-forbidden:'.$user->id, 1, 60)) {
                return;
            }

            $membershipId = null;

            foreach ($memberships as $membership) {
                if ($membership->workspaceId === $current && $membership->usable) {
                    $membershipId = $membership->membershipId;
                    break;
                }
            }

            if ($current === null || $membershipId === null) {
                Log::warning(AuditAction::AccessWorkspaceForbidden->value, ['reason' => 'no_workspace']);

                return;
            }

            $this->audit->recordSecurityEvent(
                AuditAction::AccessWorkspaceForbidden,
                ['user_id' => $user->id, 'membership_id' => $membershipId, 'area' => $area->value, 'reason' => 'unusable_workspace'],
                $current,
                subject: 'membership:'.$membershipId,
                actor: $membershipId,
            );
        } catch (Throwable $e) {
            try {
                Log::error('identity.workspace.forbidden_record_failed', ['workspace_id' => $current, 'exception' => $e::class]);
            } catch (Throwable) {
            }
        }
    }
}
