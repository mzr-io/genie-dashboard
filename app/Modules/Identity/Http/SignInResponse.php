<?php

namespace App\Modules\Identity\Http;

use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

/** Where a successful sign-in lands: the Overview of the area stored in the session. */
final class SignInResponse implements LoginResponse
{
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $area = $request->hasSession() ? $request->session()->get('area') : null;

        return redirect()->route($area === 'admin' ? 'admin.overview' : 'overview');
    }
}
