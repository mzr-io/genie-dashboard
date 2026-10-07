<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\SessionClock;
use App\Platform\Contracts\ErrorCode;
use App\Platform\Tenancy\WorkspaceTransaction;
use App\Support\Observability\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle timeout per area. A signed-in session whose last user-initiated request is older than the limit is
 * signed out: JSON and Inertia requests get 401, page loads a redirect to sign-in. The URL the person was
 * on is stored as the intended URL and `session_expired` is kept for the sign-in response to turn into a
 * toast. A request with `X-Background: 1` and the status endpoint never write the idle clock, so polling
 * cannot keep a session alive; every other request does.
 */
final class IdleTimeout
{
    public const BACKGROUND_HEADER = 'X-Background';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');

        if (! $request->hasSession() || ! $guard->check()) {
            return $next($request);
        }

        $session = $request->session();

        if (SessionClock::remaining($session) <= 0) {
            return $this->expire($request);
        }

        if (! $this->isBackground($request)) {
            SessionClock::touch($session);
        }

        return $next($request);
    }

    private function isBackground(Request $request): bool
    {
        return $request->header(self::BACKGROUND_HEADER) === '1' || $request->routeIs('api.session.status');
    }

    private function expire(Request $request): Response
    {
        $session = $request->session();
        $intended = $this->intendedUrl($request);

        Auth::guard('web')->logout();
        $session->forget([WorkspaceTransaction::SESSION_KEY, 'area', SessionClock::KEY]);
        $session->regenerate(true);
        $session->put('url.intended', $intended);
        $session->put(SessionClock::EXPIRED_KEY, true);

        if ($this->wantsJson($request)) {
            return response()->json(['error' => [
                'code' => ErrorCode::Unauthenticated->value,
                'message' => Response::$statusTexts[401],
                'request_id' => $request->attributes->get('request_id') ?? app(RequestContext::class)->requestId(),
            ]], 401);
        }

        return redirect()->route('login');
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*') || $request->header('X-Inertia') !== null || $request->ajax();
    }

    /**
     * The page the person was on: this URL for a page visit (an HTML or Inertia GET that is neither a
     * prefetch nor a background call), else the page that made the call. Only same-origin application pages
     * qualify; anything else falls back to the Overview.
     */
    private function intendedUrl(Request $request): string
    {
        $isPage = $request->isMethod('GET')
            && $request->header(self::BACKGROUND_HEADER) !== '1'
            && ! in_array($request->header('Purpose'), ['prefetch'], true)
            && ! $request->headers->has('X-Inertia-Prefetch')
            && ($request->header('X-Inertia') !== null || ! ($request->expectsJson() || $request->ajax()));

        $candidate = $isPage ? $request->fullUrl() : $request->headers->get('referer');

        return IntendedUrl::accept($request, $candidate) ?? route('overview');
    }
}
