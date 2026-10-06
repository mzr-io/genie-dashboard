<?php

namespace App\Modules\Identity\Application;

use Illuminate\Support\Facades\Facade;

/**
 * The reset-link lifetime and the reset-request throttle, from the `pending_input` tunables. Nothing is
 * invented: while they are unset the starter kit's own behaviour applies (a 60 minute link in
 * `config/auth.php`, and 5 requests per minute, as for sign-in).
 */
final class ResetLimits
{
    /** The starter kit's shipped link lifetime (`config/auth.php`), minutes. */
    public const STARTER_KIT_LIFETIME_MINUTES = 60;

    public const STARTER_KIT_MAX_ATTEMPTS = 5;

    public const STARTER_KIT_DECAY_SECONDS = 60;

    /** Whole minutes a reset link stays valid. */
    public static function lifetimeMinutes(): int
    {
        return SignInLimits::positive('dashflow.tunables.sessions.reset_link_lifetime.value') ?? self::STARTER_KIT_LIFETIME_MINUTES;
    }

    public static function maxAttempts(): int
    {
        return SignInLimits::positive('dashflow.tunables.sessions.reset_request_max_attempts.value') ?? self::STARTER_KIT_MAX_ATTEMPTS;
    }

    public static function decaySeconds(): int
    {
        return SignInLimits::positive('dashflow.tunables.sessions.reset_request_decay_seconds.value') ?? self::STARTER_KIT_DECAY_SECONDS;
    }

    /** Points the password broker (and the email's "expires in" line) at the configured lifetime. */
    public static function applyLifetime(): void
    {
        $broker = config('fortify.passwords', 'users');

        $key = "auth.passwords.{$broker}.expire";

        if (SignInLimits::positive('dashflow.tunables.sessions.reset_link_lifetime.value') !== null && config($key) !== self::lifetimeMinutes()) {
            config([$key => self::lifetimeMinutes()]);

            // Brokers read the lifetime once, when built: drop the ones already resolved.
            app()->forgetInstance('auth.password');
            app()->forgetInstance('auth.password.broker');
            Facade::clearResolvedInstance('auth.password');
        }
    }
}
