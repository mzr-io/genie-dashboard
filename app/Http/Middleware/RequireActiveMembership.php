<?php

namespace App\Http\Middleware;

use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\StatusChange;
use App\Modules\Identity\Application\SessionClock;
use App\Platform\Contracts\ErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Every authenticated request re-checks that the person's membership in the session's Workspace is active (Story
 * 1.24). A deactivated member's session may outlive the revocation (a payload that cannot be decoded, a session
 * written meanwhile), so this is the guarantee: when the membership is not active (deactivated, any other status, or none in that Workspace) the Workspace
 * and area keys are dropped and the person is signed out of the session; the answer is 401 for JSON, API and
 * Inertia requests and a redirect to sign-in for page loads. Nothing is cached: the next request reads the row again. A session with no `workspace_id` is left alone. When the memberships cannot be read the answer is 503 and the session is kept.
 */
final class RequireActiveMembership
{
    public function __construct(
        private readonly MembershipLookup $memberships,
        private readonly RequestContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $request->hasSession() || $user === null) {
            return $next($request);
        }

        $session = $request->session();
        $workspaceId = $session->get(WorkspaceTransaction::SESSION_KEY);

        if ($workspaceId === null) {
            return $next($request);
        }

        try {
            // A malformed key names no membership: not active.
            $active = is_string($workspaceId) && Str::isUuid($workspaceId)
                && $this->isActive((int) $user->getAuthIdentifier(), strtolower($workspaceId));
        } catch (Throwable $e) {
            // A transient failure is not a verdict: the request fails with 503 and the session is kept.
            Log::error('access.membership.lookup_failed', ['exception' => $e::class]);

            return $this->unavailable($request);
        }

        if ($active) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $session->forget([WorkspaceTransaction::SESSION_KEY, 'area', SessionClock::KEY]);
        $session->regenerate(true);

        if ($this->wantsJson($request)) {
            return response()->json(['error' => [
                'code' => ErrorCode::Unauthenticated->value,
                'message' => Response::$statusTexts[401],
                'request_id' => $request->attributes->get('request_id') ?? $this->context->requestId(),
            ]], 401);
        }

        return redirect()->route('login');
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*') || $request->header('X-Inertia') !== null || $request->ajax();
    }

    private function unavailable(Request $request): Response
    {
        if ($this->wantsJson($request)) {
            return response()->json(['error' => [
                'code' => ErrorCode::ServerError->value,
                'message' => Response::$statusTexts[503],
                'request_id' => $request->attributes->get('request_id') ?? $this->context->requestId(),
            ]], 503);
        }

        return response(Response::$statusTexts[503], 503);
    }

    /**
     * True only for an `active` membership in the session's Workspace; a deactivated or otherwise non-active one, or
     * none at all, is a verdict of "no".
     *
     * @throws Throwable when the memberships cannot be read
     */
    private function isActive(int $userId, string $workspaceId): bool
    {
        foreach ($this->memberships->forUser($userId) as $membership) {
            if ($membership->workspaceId === $workspaceId) {
                return $membership->status === StatusChange::ACTIVE;
            }
        }

        return false;
    }
}
