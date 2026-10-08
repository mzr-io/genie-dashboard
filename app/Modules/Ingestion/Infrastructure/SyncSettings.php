<?php

namespace App\Modules\Ingestion\Infrastructure;

use Illuminate\Contracts\Config\Repository;

/**
 * The two pending-input tunables the scheduled fetch reads (AR-57): `sync.refresh_intervals` and `sync.dispatch_tick`. No number is invented:
 * an unset or malformed interval list means no target is ever scheduled, and an unset or malformed tick falls back to the proposed 5 seconds
 * the configuration already shows.
 */
final class SyncSettings
{
    /** The proposed tick (seconds), used when the setting is unset or malformed. */
    public const DEFAULT_TICK = 5;

    /** The sub-minute steps the Laravel scheduler offers, in seconds. */
    public const STEPS = [1, 2, 5, 10, 15, 20, 30];

    public function __construct(private readonly Repository $config) {}

    /**
     * The smallest value of `refresh_intervals` (whole seconds, comma-separated), or null when it is unset or any entry is not a positive whole number.
     */
    public function refreshInterval(): ?int
    {
        return self::smallest($this->config->get('dashflow.tunables.sync.refresh_intervals.value'));
    }

    public function dispatchTick(): int
    {
        return self::tick($this->config->get('dashflow.tunables.sync.dispatch_tick.value'));
    }

    public static function smallest(mixed $setting): ?int
    {
        if (! is_string($setting) && ! is_int($setting)) {
            return null;
        }

        $entries = array_map('trim', explode(',', (string) $setting));
        $smallest = null;

        foreach ($entries as $entry) {
            if (preg_match('/\A[1-9][0-9]{0,8}\z/D', $entry) !== 1) {
                return null;
            }

            $smallest = $smallest === null ? (int) $entry : min($smallest, (int) $entry);
        }

        return $smallest;
    }

    /** The nearest sub-minute step to the configured tick; 60 seconds or more is one minute (returned as 60). */
    public static function tick(mixed $setting): int
    {
        $seconds = is_int($setting) || (is_string($setting) && preg_match('/\A[1-9][0-9]{0,5}\z/D', trim($setting)) === 1)
            ? (int) $setting
            : self::DEFAULT_TICK;

        if ($seconds >= 45) {
            return 60;
        }

        $nearest = self::STEPS[0];

        foreach (self::STEPS as $step) {
            if (abs($step - $seconds) < abs($nearest - $seconds)) {
                $nearest = $step;
            }
        }

        return $nearest;
    }
}
