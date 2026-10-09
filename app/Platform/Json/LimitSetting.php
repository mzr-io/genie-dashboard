<?php

namespace App\Platform\Json;

/**
 * Reads a `pending_input` limit. Unset (null or empty) means not checked; a value that is set must be a positive whole number
 * (an int, or digits in a string), otherwise {@see InvalidLimitSetting}: a typo must never quietly remove a protection.
 */
final class LimitSetting
{
    /** @throws InvalidLimitSetting */
    public static function positive(string $name, mixed $value): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,17}\z/D', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new InvalidLimitSetting($name);
    }
}
