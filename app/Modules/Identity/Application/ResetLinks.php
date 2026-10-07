<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;

/** Finding the person a reset is for, and whether a reset link can still be used. Every way it cannot is one answer. */
final class ResetLinks
{
    /** Longest token accepted before any lookup. */
    public const MAX_TOKEN = 512;

    /** The account for an email, matched case-insensitively like sign-in (the `lower(email)` index). */
    public static function user(string $email): ?User
    {
        if ($email === '' || strlen($email) > SignIn::MAX_EMAIL) {
            return null;
        }

        return User::query()->whereRaw('lower(email) = lower(?)', [$email])->first();
    }

    public function usable(string $email, string $token): bool
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN) {
            return false;
        }

        $user = self::user($email);

        if ($user === null) {
            return false;
        }

        ResetLimits::applyLifetime();

        $broker = Password::broker(config('fortify.passwords'));

        return $broker instanceof PasswordBroker && $broker->tokenExists($user, $token);
    }
}
