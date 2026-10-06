<?php

namespace App\Modules\Identity\Application;

/**
 * The idle limit of a session, in seconds, by area. It comes from the `pending_input` tunables
 * `sessions.idle_admin` and `sessions.idle_user` (whole minutes, the unit of Laravel's `session.lifetime`).
 * While the tunable of an area is unset the starter kit's `session.lifetime` applies; that fallback is the
 * kit's own value and not a Dashflow choice. The limit never exceeds `session.lifetime`: the framework ends
 * the session there, and a longer idle limit would expire it with no warning.
 */
final class IdleLimit
{
    public static function seconds(?string $area): int
    {
        $tunable = $area === 'admin' ? 'idle_admin' : 'idle_user';
        $lifetime = max(1, (int) config('session.lifetime'));
        // Laravel drops the session at `session.lifetime`, so a longer idle limit could never warn.
        $minutes = min(SignInLimits::positive("dashflow.tunables.sessions.{$tunable}.value") ?? $lifetime, $lifetime);

        return $minutes * 60;
    }
}
