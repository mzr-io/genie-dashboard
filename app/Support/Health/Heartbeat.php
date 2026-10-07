<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\Cache;

/**
 * The scheduler's health signal: a timestamp written by a onOneServer() task.
 */
final class Heartbeat
{
    public const KEY = 'dashflow:scheduler:heartbeat';

    public const MAX_AGE_SECONDS = 150;

    public static function beat(): void
    {
        Cache::put(self::KEY, now()->getTimestamp(), 600);
    }

    public static function age(): ?int
    {
        $beat = Cache::get(self::KEY);

        return is_numeric($beat) ? max(0, now()->getTimestamp() - (int) $beat) : null;
    }
}
