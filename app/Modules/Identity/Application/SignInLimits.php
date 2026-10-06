<?php

namespace App\Modules\Identity\Application;

/**
 * The sign-in throttle and Remember-me limits, from the `pending_input` tunables. Nothing is invented:
 * while the throttle settings are unset Fortify's shipped 5 attempts per minute applies, and while the
 * Remember-me maximum is unset Remember me adds nothing beyond the normal session lifetime.
 */
final class SignInLimits
{
    /** Fortify's own shipped throttle, the floor while the tunables are unset. */
    public const FORTIFY_MAX_ATTEMPTS = 5;

    public const FORTIFY_DECAY_SECONDS = 60;

    public static function maxAttempts(): int
    {
        return self::positive('dashflow.tunables.sessions.sign_in_max_attempts.value') ?? self::FORTIFY_MAX_ATTEMPTS;
    }

    public static function decaySeconds(): int
    {
        return self::positive('dashflow.tunables.sessions.sign_in_decay_seconds.value') ?? self::FORTIFY_DECAY_SECONDS;
    }

    /** Whole minutes a Remember-me cookie may last, or null while the maximum is unset. */
    public static function rememberMinutes(): ?int
    {
        return self::positive('dashflow.tunables.sessions.remember_me_duration.value');
    }

    private static function positive(string $key): ?int
    {
        $value = config($key);

        if (! is_int($value) && ! (is_string($value) && preg_match('/\A[0-9]{1,9}\z/', $value) === 1)) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }
}
