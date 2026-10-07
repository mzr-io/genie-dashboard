<?php

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\SignIn;
use App\Modules\Identity\Application\SignInRefused;
use App\Modules\Identity\Contracts\SignInArea;
use App\Modules\Identity\Contracts\SignInMessage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Fortify's login pipeline, replaced by one step: credentials, the chosen area, the rotated session.
 * A refusal is a validation error carrying a catalogue key (HTTP 422): `signin-failed` on the email field
 * for an unknown email, a wrong password or no membership alike, `signin-role-denied` on the role field.
 */
final class SignInPipe
{
    public function __construct(private readonly SignIn $signIn) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $email = $request->input(Fortify::username());
        $password = $request->input('password');
        $role = $request->input('role');

        // A missing role means the User card; any other value than user or admin is refused.
        if ($role !== null && $role !== '' && $role !== 'user' && $role !== 'admin') {
            throw ValidationException::withMessages([Fortify::username() => [SignInMessage::Failed->value]]);
        }

        try {
            $this->signIn->handle(
                $request,
                is_string($email) ? $email : '',
                is_string($password) ? $password : '',
                SignInArea::fromInput($role),
            );
        } catch (SignInRefused $refused) {
            throw ValidationException::withMessages([
                $refused->reply === SignInMessage::RoleDenied ? 'role' : Fortify::username() => [$refused->reply->value],
            ]);
        }

        // A successful sign-in does not count toward the throttle.
        SignInThrottle::clear($request);

        return $next($request);
    }
}
