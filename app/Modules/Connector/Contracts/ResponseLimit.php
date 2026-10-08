<?php

namespace App\Modules\Connector\Contracts;

use App\Platform\Json\InvalidLimitSetting;
use App\Platform\Json\LimitSetting;

/**
 * The size a response may reach (Story 2.6): the smaller of the Data Source's `max_response_bytes` and the platform
 * `tunables.guards.max_bytes` ceiling, each used only when it is set (a set but malformed one raises, it is never read as unset). With neither set there is no cap: no number is
 * invented (both are `pending_input`), so an operator who wants a cap sets the ceiling.
 */
final class ResponseLimit
{
    /**
     * @param  mixed  $platform  the raw `dashflow.tunables.guards.max_bytes.value` (an int or digits in a string)
     *
     * @throws InvalidLimitSetting when a limit is set but is not a positive whole number (the fetch then fails closed)
     */
    public static function effective(?int $source, mixed $platform): ?int
    {
        $ceiling = LimitSetting::positive('max_bytes', $platform);

        if ($source !== null && $source <= 0) {
            throw new InvalidLimitSetting('max_response_bytes');
        }

        return match (true) {
            $source !== null && $ceiling !== null => min($source, $ceiling),
            default => $source ?? $ceiling,
        };
    }
}
