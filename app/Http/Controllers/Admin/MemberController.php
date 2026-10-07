<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListMembersRequest;
use App\Http\Requests\Admin\MemberStatusRequest;
use App\Http\Requests\Admin\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Http\Responses\AdminApiError;
use App\Models\User;
use App\Modules\Access\Contracts\ConfirmationThrottled;
use App\Modules\Access\Contracts\EditorNotAuthorized;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\InvalidMemberCursor;
use App\Modules\Access\Contracts\LastUsersManageHolder;
use App\Modules\Access\Contracts\MemberAccess;
use App\Modules\Access\Contracts\MemberActivation;
use App\Modules\Access\Contracts\MemberDirectory;
use App\Modules\Access\Contracts\MemberEditor;
use App\Modules\Access\Contracts\MemberRow;
use App\Modules\Access\Contracts\MembershipInactive;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipNotFound;
use App\Modules\Access\Contracts\PasswordNotConfirmed;
use App\Modules\Access\Contracts\PermissionNotHeld;
use App\Modules\Access\Contracts\PermissionsOnUserRole;
use App\Modules\Access\Contracts\RevisionConflict;
use App\Modules\Access\Contracts\SelfChangeForbidden;
use App\Modules\Access\Contracts\StatusChange;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Contracts\ErrorCode as PlatformErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Workspace's members and pending invitations (Story 1.20). Both routes sit behind the `admin` middleware
 * (`users.manage`); the Workspace is the session's, never a client-supplied ID, and another Workspace's
 * membership is invisible (404) under row-level security.
 */
final class MemberController extends Controller
{
    /** Member data is personal and per-Workspace: never stored by a cache. */
    private const NO_STORE = 'no-store, private';

    private const CONFIRM_ATTEMPTS = 6;

    private const CONFIRM_DECAY_SECONDS = 60;

    public function __construct(
        private readonly MemberDirectory $members,
        private readonly MemberAccess $access,
        private readonly MemberActivation $activation,
        private readonly MembershipLookup $memberships,
        private readonly Audit $audit,
    ) {}

    public function index(ListMembersRequest $request): JsonResponse
    {
        $query = $request->memberQuery();

        try {
            $page = $this->members->page($this->workspaceId($request), $query);
        } catch (InvalidMemberCursor) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is not valid.']]);
        }

        return MemberResource::collection($page->rows)->additional(['meta' => [
            'per_page' => $query->pageSize,
            'next_cursor' => $page->nextCursor,
            'total' => $page->total,
            'matched' => $page->matched,
            'sort' => $query->sort->value,
            'direction' => $query->descending ? 'desc' : 'asc',
        ]])->response()->header('Cache-Control', self::NO_STORE);
    }

    public function show(Request $request, string $membership): JsonResponse
    {
        $row = $this->members->find($this->workspaceId($request), $membership);

        abort_if($row === null, 404);

        return (new MemberResource($row))->response()->header('Cache-Control', self::NO_STORE);
    }

    /**
     * Change a member's role and permissions (Story 1.22). Everything is refused with nothing changed (the request's
     * transaction rolls back on any 4xx): a stale revision (409 with the current state), the last `users.manage`
     * holder (409), one's own row (403), permissions the editor lacks (403), an editor who lost their authority
     * meanwhile (403), an inactive member (422) and a wrong password (422, throttled). Refused attempts that look like
     * probing (self edit, a permission not held, the last holder, a wrong password, lost authority) are recorded as
     * `access.admin.denied` security events on their own connection, so they stay although the change rolls back.
     */
    public function update(UpdateMemberRequest $request, string $membership): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $editor = $this->editor($request, $workspaceId);
        $wrongPassword = false;

        try {
            $change = $this->access->update(
                $editor,
                $membership,
                $request->revision(),
                $request->role(),
                $request->permissions(),
                function () use ($request, $editor, &$wrongPassword): bool {
                    $ok = $this->confirmPassword($request, $editor);
                    $wrongPassword = ! $ok && $request->confirmation() !== '';

                    return $ok;
                },
            );
        } catch (MembershipNotFound) {
            abort(404);
        } catch (EditorNotAuthorized) {
            $this->recordRefusal($request, $editor, $membership, 'api.admin.members.update', 'not_authorized');

            return AdminApiError::json($request, ErrorCode::NotAuthorized->value, 403);
        } catch (SelfChangeForbidden) {
            $this->recordRefusal($request, $editor, $membership, 'api.admin.members.update', 'self_change');

            return AdminApiError::json($request, ErrorCode::SelfChangeForbidden->value, 403, 'Nobody edits their own role or permissions.');
        } catch (MembershipInactive) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, 'This member is deactivated.', extra: ['reason' => 'membership_inactive']);
        } catch (RevisionConflict $e) {
            return AdminApiError::json($request, ErrorCode::RevisionConflict->value, 409, 'This member was changed by someone else.', extra: [
                'current' => ['role' => $e->current->role, 'permissions' => $e->current->permissions, 'revision' => $e->current->revision],
            ]);
        } catch (PermissionsOnUserRole) {
            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: ['permissions' => ['A User holds no permissions.']]);
        } catch (PermissionNotHeld $e) {
            $this->recordRefusal($request, $editor, $membership, 'api.admin.members.update', 'permission_not_held');

            return AdminApiError::json($request, ErrorCode::PermissionNotHeld->value, 403, extra: ['permissions' => implode(',', $e->permissions)]);
        } catch (LastUsersManageHolder) {
            $this->recordRefusal($request, $editor, $membership, 'api.admin.members.update', 'last_users_manage_holder');

            return AdminApiError::json($request, ErrorCode::LastUsersManageHolder->value, 409, 'This member is the last person who can manage users, so their role and that permission cannot be changed.');
        } catch (PasswordNotConfirmed) {
            if ($wrongPassword) {
                $this->recordRefusal($request, $editor, $membership, 'api.admin.members.update', 'wrong_password');
            }

            return AdminApiError::json($request, PlatformErrorCode::ValidationFailed->value, 422, errors: ['confirm_password' => ['The password is incorrect.']]);
        } catch (ConfirmationThrottled $e) {
            return AdminApiError::json($request, PlatformErrorCode::TooManyRequests->value, 429, 'Too many attempts.', ['confirm_password' => ['Too many attempts.']])
                ->header('Retry-After', (string) $e->retryAfter);
        }

        // Built from the change result: the editor needs the new role, permissions and revision (the page keeps the rest).
        return response()->json(['data' => [
            'kind' => MemberRow::MEMBER,
            'membership_id' => $change->membershipId,
            'role' => $change->role,
            'permissions' => $change->permissions,
            'revision' => $change->revision,
        ]], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** Deactivate a member (Story 1.24): their sessions for this Workspace end and their next request fails. */
    public function deactivate(MemberStatusRequest $request, string $membership): JsonResponse
    {
        return $this->changeStatus($request, $membership, 'api.admin.members.deactivate', fn (MemberEditor $editor): StatusChange => $this->activation->deactivate($editor, $membership, $request->revision()));
    }

    /** Reactivate a member (Story 1.24): their previous role, permissions and groups come back. */
    public function reactivate(MemberStatusRequest $request, string $membership): JsonResponse
    {
        return $this->changeStatus($request, $membership, 'api.admin.members.reactivate', fn (MemberEditor $editor): StatusChange => $this->activation->reactivate($editor, $membership, $request->revision()));
    }

    /**
     * Refusals leave nothing changed (the request's transaction rolls back on any 4xx): one's own row (403), a lost
     * authority (403), the last `users.manage` holder (409), a stale revision (409 with the current state) and another
     * Workspace's member (404). Probing refusals are recorded as `access.admin.denied` on their own connection.
     *
     * @param  Closure(MemberEditor): StatusChange  $run
     */
    private function changeStatus(MemberStatusRequest $request, string $membership, string $route, Closure $run): JsonResponse
    {
        $editor = $this->editor($request, $this->workspaceId($request));

        try {
            $change = $run($editor);
        } catch (MembershipNotFound) {
            abort(404);
        } catch (EditorNotAuthorized) {
            $this->recordRefusal($request, $editor, $membership, $route, 'not_authorized');

            return AdminApiError::json($request, ErrorCode::NotAuthorized->value, 403);
        } catch (SelfChangeForbidden) {
            $this->recordRefusal($request, $editor, $membership, $route, 'self_change');

            return AdminApiError::json($request, ErrorCode::SelfChangeForbidden->value, 403, 'Nobody changes their own access.');
        } catch (RevisionConflict $e) {
            return AdminApiError::json($request, ErrorCode::RevisionConflict->value, 409, 'This member was changed by someone else.', extra: [
                'current' => ['role' => $e->current->role, 'permissions' => $e->current->permissions, 'revision' => $e->current->revision, 'status' => $e->current->status],
            ]);
        } catch (LastUsersManageHolder) {
            $this->recordRefusal($request, $editor, $membership, $route, 'last_users_manage_holder');

            return AdminApiError::json($request, ErrorCode::LastUsersManageHolder->value, 409, 'This member is the last person who can manage users, so they cannot be deactivated.');
        }

        return response()->json(['data' => [
            'kind' => MemberRow::MEMBER,
            'membership_id' => $change->membershipId,
            'status' => $change->status,
            'revision' => $change->revision,
        ]], 200, ['Cache-Control' => self::NO_STORE]);
    }

    /** A refused attempt as a security event on its own connection (it outlives the rolled-back request); the target ID only, never an email. */
    private function recordRefusal(Request $request, MemberEditor $editor, string $target, string $route, string $reason): void
    {
        try {
            $values = ['route' => $route, 'reason' => $reason, 'user_id' => $editor->userId, 'membership_id' => $editor->membershipId, 'area' => 'admin'];
            $subject = Str::isUuid($target) ? 'membership:'.strtolower($target) : null;

            $this->audit->recordSecurityEvent(AuditAction::AccessAdminDenied, $values, $editor->workspaceId, subject: $subject, actor: $editor->membershipId);
        } catch (Throwable $e) {
            Log::error('access.admin.denied_record_failed', ['exception' => $e::class]);
        }
    }

    /** The editor's own membership in the session's Workspace; the `admin` middleware has proven it is an active Admin one. */
    private function editor(Request $request, string $workspaceId): MemberEditor
    {
        $user = $request->user();

        abort_unless($user instanceof User, 404);

        foreach ($this->memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return new MemberEditor($user->id, $membership->membershipId, $membership->workspaceId);
            }
        }

        abort(404);
    }

    /** True when the password is right. Wrong guesses are throttled per person and IP; a right guess never clears them. */
    private function confirmPassword(UpdateMemberRequest $request, MemberEditor $editor): bool
    {
        $user = $request->user();
        $key = 'member-confirm:'.$editor->userId.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::CONFIRM_ATTEMPTS)) {
            throw new ConfirmationThrottled(RateLimiter::availableIn($key));
        }

        $given = $request->confirmation();

        // A missing password is a field error, not a guess: it does not count against the throttle.
        if ($given === '') {
            return false;
        }

        if (! $user instanceof User || ! Hash::check($given, $user->getAuthPassword())) {
            RateLimiter::hit($key, self::CONFIRM_DECAY_SECONDS);

            return false;
        }

        return true;
    }

    private function workspaceId(Request $request): string
    {
        // The admin middleware has already proven an active Admin membership in this session Workspace.
        $id = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        return is_string($id) ? $id : abort(404);
    }
}
