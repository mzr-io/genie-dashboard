<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\SignInLimits;
use App\Modules\Identity\Application\SignInRecorder;
use App\Modules\Identity\Contracts\SignInMessage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `login` rate limiter Fortify's route uses: one bucket per email and IP, with the limits of the
 * `pending_input` tunables (Fortify's 5 per minute while they are unset). An attempt over the limit gets
 * HTTP 429 with the `throttled` catalogue key and is recorded as `identity.signin.throttled`.
 */
final class SignInThrottle
{
    public function __construct(private readonly SignInRecorder $recorder) {}

    /** The bucket key: email and IP, as Fortify's own limiter keyed it. */
    public static function key(Request $request): string
    {
        return Str::transliterate(Str::lower(self::email($request)).'|'.$request->ip());
    }

    /** Empties the request's bucket (after a successful sign-in). The route middleware stores it under md5('login'.key). */
    public static function clear(Request $request): void
    {
        RateLimiter::clear(md5('login'.self::key($request)));
    }

    private static function email(Request $request): string
    {
        $email = $request->input(Fortify::username());

        return is_string($email) ? $email : '';
    }

    public function limit(Request $request): Limit
    {
        $email = self::email($request);
        $decay = SignInLimits::decaySeconds();

        return (new Limit(self::key($request), SignInLimits::maxAttempts(), $decay))
            ->response(function (Request $request, array $headers) use ($email, $decay): Response {
                // One audit row per bucket per decay window, however long the flood lasts.
                if (Cache::add('signin-throttled:'.md5(self::key($request)), 1, $decay)) {
                    $this->recorder->throttled($email);
                }

                $key = SignInMessage::Throttled->value;

                return response()->json(['message' => $key, 'errors' => [Fortify::username() => [$key]]], 429, $headers);
            });
    }
}
