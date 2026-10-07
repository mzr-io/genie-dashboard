<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteMemberRequest;
use App\Http\Resources\MemberResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\InvitationNotFound;
use App\Modules\Access\Contracts\InvitationOutcome;
use App\Modules\Access\Contracts\InvitationRefused;
use App\Modules\Access\Contracts\InvitationsNotConfigured;
use App\Modules\Access\Contracts\Inviter;
use App\Modules\Access\Contracts\MemberInvitations;
use App\Modules\Access\Contracts\MemberRow;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\PermissionNotHeld;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Invite, re-send and revoke (Story 1.21). The three routes sit behind the `admin` middleware (`users.manage`); the
 * Workspace is the session's, never a client-supplied ID. The response never carries a token or its hash.
 *
 * Every Admin invitation needs the inviter's current password in `confirm_password`, throttled per person and IP (6 wrong tries a minute, never reset by a right one). Permissions the inviter lacks are refused first, with 403, before the password
 * is looked at.
 */
final class InvitationController extends Controller
{
    private const CONFIRM_ATTEMPTS = 6;

    private const CONFIRM_DECAY_SECONDS = 60;

    public function __construct(
        private readonly MemberInvitations $invitations,
        private readonly MembershipLookup $memberships,
    ) {}

    public function store(InviteMemberRequest $request): JsonResponse
    {
        $inviter = $this->inviter($request);
        $permissions = $request->permissions();

        try {
            $this->invitations->assertGrantable($inviter, $request->role(), $permissions);
        } catch (PermissionNotHeld $e) {
            return $this->notHeld($request, $e);
        }

        // Every Admin invitation needs the password, even with no permission ticked.
        if ($request->role() === 'admin') {
            $refused = $this->confirmPassword($request, $inviter);

            if ($refused !== null) {
                return $refused;
            }
        }

        try {
            $outcome = $this->invitations->invite($inviter, $request->email(), $request->role(), $permissions);
        } catch (PermissionNotHeld $e) {
            return $this->notHeld($request, $e);
        } catch (InvitationRefused $e) {
            return $this->emailRefused($request, $e);
        } catch (InvitationsNotConfigured) {
            return $this->notConfigured($request);
        }

        return $this->row($outcome)->response()->setStatusCode(201)->header('Cache-Control', 'no-store, private');
    }

    public function resend(Request $request, string $invitation): JsonResponse
    {
        try {
            $outcome = $this->invitations->resend($this->inviter($request), $invitation);
        } catch (InvitationNotFound) {
            abort(404);
        } catch (PermissionNotHeld $e) {
            return $this->notHeld($request, $e, 'You cannot resend this invitation: it grants permissions you do not hold.');
        } catch (InvitationsNotConfigured) {
            return $this->notConfigured($request);
        }

        return $this->row($outcome)->response()->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request, string $invitation): Response
    {
        try {
            $this->invitations->revoke($this->inviter($request), $invitation);
        } catch (InvitationNotFound) {
            abort(404);
        }

        return response()->noContent();
    }

    private function row(InvitationOutcome $outcome): MemberResource
    {
        return new MemberResource(new MemberRow($outcome->invitationId, MemberRow::INVITATION, '', $outcome->email, $outcome->role, $outcome->status, [], null));
    }

    private function inviter(Request $request): Inviter
    {
        $user = $request->user();
        $workspaceId = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        // The admin middleware has already proven an active Admin membership in this session Workspace.
        abort_unless($user instanceof User && is_string($workspaceId), 404);

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return new Inviter($user->id, $membership->membershipId, $membership->workspaceId, $membership->workspaceName);
            }
        }

        abort(404);
    }

    /** Null when the password is right; otherwise the 422 field error, or 429 once the throttle is spent. */
    private function confirmPassword(InviteMemberRequest $request, Inviter $inviter): ?JsonResponse
    {
        $user = $request->user();
        $key = 'invite-confirm:'.$inviter->userId.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::CONFIRM_ATTEMPTS)) {
            return AdminApiError::json($request, PlatformErrorCode::TooManyRequests->value, 429, 'Too many attempts.', ['confirm_password' => ['Too many attempts.']])
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        $given = $request->confirmation();

        if ($given === '' || ! $user instanceof User || ! Hash::check($given, $user->getAuthPassword())) {
            RateLimiter::hit($key, self::CONFIRM_DECAY_SECONDS);

            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: ['confirm_password' => ['The password is incorrect.']]);
        }

        // Not cleared on success: a right guess never resets the count of wrong ones.
        return null;
    }

    private function notHeld(Request $request, PermissionNotHeld $e, ?string $message = null): JsonResponse
    {
        return AdminApiError::json($request, ErrorCode::PermissionNotHeld->value, 403, $message, extra: ['permissions' => implode(',', $e->permissions)]);
    }

    private function emailRefused(Request $request, InvitationRefused $e): JsonResponse
    {
        $pending = $e->reason === InvitationRefused::PENDING;

        return AdminApiError::json(
            $request,
            PlatformErrorCode::ValidationFailed->value,
            422,
            errors: ['email' => [$pending ? 'This email already has a pending invitation.' : 'This email already belongs to a member of this workspace.']],
            extra: ['reason' => $e->reason, 'invitation_id' => $e->invitationId],
        );
    }

    private function notConfigured(Request $request): JsonResponse
    {
        return AdminApiError::json($request, ErrorCode::InvitationsNotConfigured->value, 422, 'Invitations are not configured yet. Ask a platform operator to set the invitation lifetime.');
    }
}
