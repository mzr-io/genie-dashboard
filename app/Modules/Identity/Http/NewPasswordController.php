<?php

namespace App\Modules\Identity\Http;

use App\Models\User;
use App\Modules\Identity\Application\ResetLimits;
use App\Modules\Identity\Application\ResetLinks;
use App\Modules\Identity\Application\ResetPassword;
use App\Modules\Identity\Application\SignIn;
use App\Modules\Identity\Contracts\PasswordResetMessage;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\CompletePasswordReset;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Laravel\Fortify\Http\Controllers\NewPasswordController as FortifyNewPasswordController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's reset step, wrapped. An expired, used or tampered token, an unknown email and a token for
 * another email are one outcome: `reset-expired`, with no password changed. A password that fails the
 * rules gives field errors and leaves the token valid.
 *
 * The token check, the password write, the deletion of the other sessions and the deletion of the token
 * happen in one transaction that first locks the token row, so the token is consumed exactly when the
 * password changes and two concurrent uses cannot both succeed. Success lands on the sign-in page with
 * `password-changed`.
 */
final class NewPasswordController extends FortifyNewPasswordController
{
    public function __construct(StatefulGuard $guard)
    {
        parent::__construct($guard);

        $this->middleware(InvitationResponseHeaders::class);
    }

    public function store(Request $request): Responsable
    {
        $email = $request->input('email');
        $token = $request->input('token');
        $password = $request->input('password');
        $confirmation = $request->input('password_confirmation');

        if (! is_string($email) || ! is_string($token) || $token === '' || strlen($token) > ResetLinks::MAX_TOKEN || strlen($email) > SignIn::MAX_EMAIL) {
            return $this->expired($request);
        }

        // The stored address, whatever case the form sent it in.
        $user = ResetLinks::user($email);
        $changed = null;

        $status = DB::transaction(function () use ($user, $email, $token, $password, $confirmation, $request, &$changed) {
            if ($user !== null) {
                DB::table((string) config('auth.passwords.'.config('fortify.passwords').'.table', 'password_reset_tokens'))
                    ->where('email', $user->email)->lockForUpdate()->first();
            }

            return $this->broker()->reset(
                [
                    'email' => $user->email ?? $email,
                    'token' => $token,
                    'password' => is_string($password) ? $password : '',
                    'password_confirmation' => is_string($confirmation) ? $confirmation : '',
                ],
                function (User $found) use ($request, &$changed): void {
                    app(ResetsUserPasswords::class)->reset($found, $request->all());

                    app(CompletePasswordReset::class)($this->guard, $found);

                    $changed = $found;
                },
            );
        });

        if ($status !== Password::PASSWORD_RESET || $changed === null) {
            return $this->expired($request);
        }

        // After the commit: the session swap and the audit event never undo or block the reset.
        app(ResetPassword::class)->finish($changed);

        return $this->changed($request);
    }

    protected function broker(): PasswordBroker
    {
        ResetLimits::applyLifetime();

        return parent::broker();
    }

    private function changed(Request $request): Responsable
    {
        return new ResetReply(fn (): Response => $request->wantsJson()
            ? new JsonResponse(['message' => PasswordResetMessage::Changed->value], 200)
            : redirect()->route('login')->with('status', PasswordResetMessage::Changed->value));
    }

    private function expired(Request $request): Responsable
    {
        return new ResetReply(function () use ($request): Response {
            $key = PasswordResetMessage::Expired->value;

            if ($request->wantsJson()) {
                throw ValidationException::withMessages(['email' => [$key]]);
            }

            return back()->withErrors(['email' => $key]);
        });
    }
}
