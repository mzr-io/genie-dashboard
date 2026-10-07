<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\SessionClock;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a successful sign-in lands: the page the person was on when an intended URL exists (set when their
 * session idled out), else the Overview of the area stored in the session. After an idle expiry the
 * `session_expired` flash is passed on to the page, which shows the toast.
 */
final class SignInResponse implements LoginResponse
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $session = $request->hasSession() ? $request->session() : null;
        $area = $session?->get('area');

        if ($session?->pull(SessionClock::EXPIRED_KEY)) {
            Inertia::flash('session_expired', true);
        }

        $default = route($area === 'admin' ? 'admin.overview' : 'overview');

        $intended = $session?->get('url.intended');

        // The remembered page is used only when it belongs to the area just opened.
        if (IntendedUrl::accept($request, $intended) !== null && IntendedUrl::belongsToArea($intended, is_string($area) ? $area : null)) {
            return redirect()->intended($default);
        }

        $session?->forget('url.intended');

        return redirect($default);
    }
}
