<?php

namespace App\Modules\Identity\Http;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Symfony\Component\HttpFoundation\Response;

/** After sign-out the person lands on the sign-in page. */
final class SignedOutResponse implements LogoutResponse
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->route('login');
    }
}
