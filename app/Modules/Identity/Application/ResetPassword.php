<?php

namespace App\Modules\Identity\Application;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Fortify's reset action. Runs inside the broker's callback, so a failure here leaves the reset token
 * valid. It applies the `Password::defaults()` rules, changes the password, deletes every other session
 * of the user from the `sessions` table. `finish()` then regenerates the current session, if any
 * (destroying its old row), and records `identity.password.reset`. It never touches the email, token or password beyond hashing the password.
 */
final class ResetPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(
        private readonly Request $request,
        private readonly PasswordResetRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $session = $this->request->hasSession() ? $this->request->session() : null;
        $currentId = $session?->getId();

        // Runs inside the caller's transaction (a savepoint here), which also consumes the token.
        DB::transaction(function () use ($user, $input, $currentId): void {
            $user->forceFill(['password' => $input['password']])->save();

            DB::table('sessions')
                ->where('user_id', $user->id)
                ->when($currentId !== null, fn ($query) => $query->where('id', '!=', $currentId))
                ->delete();
        });
    }

    /** After the reset has committed: swap the current session (its old row is destroyed) and record the event. */
    public function finish(User $user): void
    {
        if ($this->request->hasSession()) {
            $this->request->session()->regenerate(true);
        }

        $this->recorder->reset($user);
    }
}
