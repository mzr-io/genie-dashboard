<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\SignOut;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

/** Fortify's `logout` route, answered by the audited sign-out (Story 1.16). Bound over Fortify's controller. */
final class SignOutController extends AuthenticatedSessionController
{
    public function __construct(StatefulGuard $guard, private readonly SignOut $signOut)
    {
        parent::__construct($guard);
    }

    public function destroy(Request $request): LogoutResponse
    {
        $this->signOut->handle($request);

        return app(LogoutResponse::class);
    }
}
