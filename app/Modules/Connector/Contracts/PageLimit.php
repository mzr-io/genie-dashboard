<?php

namespace App\Modules\Connector\Contracts;

use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\LimitSetting;

/**
 * How many pages a run may fetch (Story 2.11): the smaller of the Data Source's `max_pages` and the platform
 * `tunables.guards.max_pages` ceiling, each used only when it is set (a set but malformed one raises, it is never read as
 * unset). With neither set there is no cap: no number is invented (both are `pending_input`), so capping a runaway API is an
 * operator duty.
 */
final class PageLimit
{
    /**
     * @param  mixed  $platform  the raw `dashflow.tunables.guards.max_pages.value`
     *
     * @throws InvalidLimitSetting when a limit is set but is not a positive whole number (the fetch then fails closed)
     */
    public static function effective(?int $source, mixed $platform): ?int
    {
        $ceiling = LimitSetting::positive('max_pages', $platform);

        if ($source !== null && $source <= 0) {
            throw new InvalidLimitSetting('max_pages');
        }

        return match (true) {
            $source !== null && $ceiling !== null => min($source, $ceiling),
            default => $source ?? $ceiling,
        };
    }
}
