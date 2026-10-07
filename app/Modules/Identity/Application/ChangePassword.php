<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Changes the signed-in person's password from Profile & settings. The request has already checked the
 * current password and the new one against `Password::defaults()`. The new password and a fresh remember
 * token are saved and every other session of the person and every API token are deleted in one transaction; then the current
 * session is regenerated (its old row destroyed), so the person stays signed in, and
 * `identity.password.changed` is recorded as a security event (a log line when there is no Workspace).
 * No email is sent (deferred in Story 1.14).
 */
final class ChangePassword
{
    public function __construct(private readonly PasswordResetRecorder $recorder) {}

    public function handle(Request $request, User $user, string $password): void
    {
        $session = $request->hasSession() ? $request->session() : null;
        $currentId = $session?->getId();

        DB::transaction(function () use ($user, $password, $currentId): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')
                ->where('user_id', $user->id)
                ->when($currentId !== null, fn ($query) => $query->where('id', '!=', $currentId))
                ->delete();

            // API tokens are sessions too: a new password ends them all.
            $user->tokens()->delete();
        });

        // The change has committed: nothing below may turn it into a failure.
        try {
            $session?->regenerate(true);
        } catch (Throwable $e) {
            Log::error('identity.password.change.session_failed', ['exception' => $e::class]);
        }

        try {
            $this->recorder->changed($user);
        } catch (Throwable $e) {
            Log::error('identity.password.change.record_failed', ['exception' => $e::class]);
        }
    }
}
