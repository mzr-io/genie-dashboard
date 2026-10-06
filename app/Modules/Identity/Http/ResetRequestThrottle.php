<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ResetLimits;
use App\Modules\Identity\Contracts\PasswordResetMessage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `password-reset` rate limiter: one bucket per email and IP, with the limits of the `pending_input`
 * tunables (the starter kit's 5 per minute while they are unset). An attempt over the limit gets HTTP 429
 * with the `throttled` catalogue key, whether or not the account exists.
 */
final class ResetRequestThrottle
{
    public const NAME = 'password-reset';

    public function limit(Request $request): Limit
    {
        $email = $request->input('email');
        $key = Str::transliterate(Str::lower(is_string($email) ? $email : '').'|'.$request->ip());

        return (new Limit($key, ResetLimits::maxAttempts(), ResetLimits::decaySeconds()))
            ->response(function (Request $request, array $headers): Response {
                $key = PasswordResetMessage::Throttled->value;

                return response()->json(['message' => $key, 'errors' => ['email' => [$key]]], 429, $headers);
            });
    }
}
