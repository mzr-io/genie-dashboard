<?php

namespace App\Http\Middleware;

use App\Http\Navigation\ShellNavigation;
use App\Models\User;
use App\Modules\Access\Contracts\ErrorCode;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\Permission;
use App\Modules\Access\Contracts\UserMembership;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditAction;
use App\Platform\Tenancy\TenantKey;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\RequestContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Route middleware `admin` (optionally `admin:{permission}`): the server-side gate of every Admin page and
 * `/api/admin` endpoint (Story 1.19). A request passes only when the session area is `admin` AND the person's
 * membership in the session's Workspace is an active `admin` membership in an active Workspace AND, when a
 * permission applies, the membership holds it. Nothing is cached beyond the request: the membership and the
 * permissions are read again on every request (a revoked permission, a demotion or a deactivation denies the
 * next one). The area in the session is never trusted alone.
 *
 * The permission is the key given to the middleware, else the one `ShellNavigation::ADMIN_ITEMS` maps to the
 * route's name, so the navigation and this gate share one mapping.
 *
 * A denial is HTTP 403: the `Forbidden` Inertia page (no page content, no props of the page asked for) or, for
 * the API, the error envelope with `access.not_authorized`. It is recorded as `access.admin.denied` on the
 * security connection, at most once per person, route and minute (a log line with the reason when there is no
 * Workspace). The group's CSRF check has already run: a state-changing request without a valid token is a 419
 * before this class is reached.
 */
final class RequireAdminAccess
{
    /** At most one denial row per person and route in this many seconds. */
    public const DEDUPE_SECONDS = 60;

    public function __construct(
        private readonly MembershipLookup $memberships,
        private readonly MembershipPermissions $permissions,
        private readonly Audit $audit,
        private readonly RequestContext $context,
    ) {}

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $route = $request->route()?->getName() ?? 'unnamed';
        $required = null;

        try {
            $required = $this->required($permission, $route);
        } catch (InvalidArgumentException) {
            // Fails closed: an admin route with no mapping (unnamed, misnamed, mistyped key) is never open.
            Log::error('access.admin.unmapped_route', ['route' => $route, 'user_id' => $user->id, 'request_id' => $this->requestId($request)]);

            return $this->denied($request);
        }

        [$reason, $membership] = $this->decide($request, $user, $required);

        if ($reason === null) {
            return $next($request);
        }

        $this->record($request, $user, $membership, $route, $required, $reason);

        return $this->denied($request);
    }

    private function requestId(Request $request): ?string
    {
        $id = $request->attributes->get('request_id') ?? $this->context->requestId();

        return is_string($id) ? $id : null;
    }

    /** @throws InvalidArgumentException when the route has no permission mapping */
    private function required(?string $key, string $route): ?Permission
    {
        if ($key === null) {
            if (! ShellNavigation::hasRoute($route)) {
                throw new InvalidArgumentException("Route [{$route}] uses the admin middleware without a key or an ADMIN_ITEMS entry.");
            }

            return ShellNavigation::permissionForRoute($route);
        }

        return Permission::tryFrom($key)
            ?? throw new InvalidArgumentException("Unknown permission key [{$key}] on the admin middleware.");
    }

    /**
     * @return array{0: string|null, 1: UserMembership|null} the denial reason (null: allowed) and the membership in the session's Workspace
     */
    private function decide(Request $request, User $user, ?Permission $required): array
    {
        $session = $request->hasSession() ? $request->session() : null;
        $workspaceId = $session?->get(WorkspaceTransaction::SESSION_KEY);
        $workspaceId = is_string($workspaceId) && Str::isUuid($workspaceId) ? strtolower($workspaceId) : null;

        if ($workspaceId === null) {
            return ['no_workspace', null];
        }

        try {
            $membership = null;

            foreach ($this->memberships->forUser($user->id) as $candidate) {
                if ($candidate->workspaceId === $workspaceId) {
                    $membership = $candidate;
                    break;
                }
            }

            if ($session?->get('area') !== 'admin') {
                return ['area', $membership];
            }

            if ($membership === null || $membership->status !== 'active' || $membership->workspaceStatus !== 'active' || $membership->role !== 'admin') {
                return ['membership', $membership];
            }

            if ($required !== null && ! in_array($required, $this->permissions->forUser($user->id, $workspaceId), true)) {
                return ['permission', $membership];
            }
        } catch (Throwable $e) {
            // Fails closed: an unreadable membership or permission store is a denial.
            Log::error('access.admin.lookup_failed', ['exception' => $e::class]);

            return ['lookup_failed', null];
        }

        return [null, $membership];
    }

    private function record(Request $request, User $user, ?UserMembership $membership, string $route, ?Permission $required, string $reason): void
    {
        $key = null;

        try {
            if ($membership === null) {
                // No Workspace exists to hold an audit row: the reason only.
                Log::warning(AuditAction::AccessAdminDenied->value, ['reason' => $reason, 'route' => $route, 'user_id' => $user->id, 'request_id' => $this->requestId($request)]);

                return;
            }

            $key = TenantKey::cache($membership->workspaceId, 'admin-denied:'.$user->id.':'.$route);

            try {
                $claimed = Cache::add($key, 1, self::DEDUPE_SECONDS);
            } catch (Throwable) {
                // A failing cache store is "not a duplicate": the audit is still attempted.
                $claimed = true;
                $key = null;
            }

            if (! $claimed) {
                return;
            }

            $area = $request->hasSession() && $request->session()->get('area') === 'admin' ? 'admin' : 'user';

            $id = $this->audit->recordSecurityEvent(
                AuditAction::AccessAdminDenied,
                [
                    'user_id' => $user->id,
                    'membership_id' => $membership->membershipId,
                    'area' => $area,
                    'route' => $route,
                    'permission' => $required?->value,
                    'reason' => $reason,
                ],
                $membership->workspaceId,
                subject: 'membership:'.$membership->membershipId,
                actor: $membership->membershipId,
            );

            // The audit swallows a failed write and returns null: do not silence the next attempt.
            if ($id === null && $key !== null) {
                Cache::forget($key);
            }
        } catch (Throwable $e) {
            try {
                // A failed write must not silence the next attempt for the dedupe window.
                if (isset($key)) {
                    Cache::forget($key);
                }

                Log::error('access.admin.denied_record_failed', ['exception' => $e::class]);
            } catch (Throwable) {
            }
        }
    }

    private function denied(Request $request): Response
    {
        if ($request->is('api/*') || ($request->expectsJson() && $request->header('X-Inertia') === null)) {
            return response()->json(['error' => [
                'code' => ErrorCode::NotAuthorized->value,
                'message' => Response::$statusTexts[403],
                'request_id' => $request->attributes->get('request_id') ?? $this->context->requestId(),
            ]], 403);
        }

        return Inertia::render('Forbidden')->toResponse($request)->setStatusCode(403);
    }
}
