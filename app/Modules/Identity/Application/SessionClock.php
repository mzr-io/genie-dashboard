<?php

namespace App\Modules\Identity\Application;

use Illuminate\Contracts\Session\Session;

/**
 * The idle clock of a session. Laravel rewrites `last_activity` on every request, background polling
 * included, so the idle limit has its own session key, `last_user_activity`, written only for
 * user-initiated requests (never one with `X-Background: 1`, never the status endpoint).
 */
final class SessionClock
{
    public const KEY = 'last_user_activity';

    /** Marker left for the sign-in response when the previous session ended by idling out. */
    public const EXPIRED_KEY = 'session_expired';

    public static function touch(Session $session): void
    {
        $session->put(self::KEY, now()->getTimestamp());
    }

    public static function limit(Session $session): int
    {
        $area = $session->get('area');

        return IdleLimit::seconds(is_string($area) ? $area : null);
    }

    /** Seconds left before the idle limit; a session that never recorded activity has its whole limit. */
    public static function remaining(Session $session): int
    {
        $limit = self::limit($session);
        $last = $session->get(self::KEY);

        if (! is_int($last)) {
            return $limit;
        }

        return max(0, $limit - max(0, now()->getTimestamp() - $last));
    }
}
