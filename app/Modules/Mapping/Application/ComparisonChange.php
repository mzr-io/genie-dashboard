<?php

namespace App\Modules\Mapping\Application;

use App\Modules\Mapping\Contracts\DecimalValue;
use BcMath\Number;

/**
 * The change a comparison shows (Story 5.5), from the headline value and the mapped previous value, exact and at the platform's one decimal scale:
 * `pct_change` is the change as a percentage of the previous value (the grammar's PCT_CHANGE), `absolute_delta` and `pp_delta` the plain difference
 * (percentage points when both are ratios, which are percentage figures). A value that is missing or not a number, and a previous value of 0 for a
 * percentage, give no change: never 0 and never infinity.
 */
final class ComparisonChange
{
    public function __construct(private readonly DecimalMath $math) {}

    public function change(string $mode, mixed $current, mixed $previous): ?string
    {
        $current = self::plain($current);
        $previous = self::plain($previous);

        if ($current === null || $previous === null) {
            return null;
        }

        /** @var numeric-string $current */
        /** @var numeric-string $previous */
        /** @var numeric-string $difference */
        $difference = $this->math->sub($current, $previous);

        if ($mode !== 'pct_change') {
            return self::trimmed($difference);
        }

        if ((new Number($previous))->compare(new Number('0')) === 0) {
            return null;
        }

        /** @var numeric-string $scaled */
        $scaled = $this->math->mul($difference, '100');

        return self::trimmed($this->math->div($scaled, $previous));
    }

    private static function plain(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? DecimalValue::plain($value) : null;
    }

    /** `12.500000` is `12.5` and `-0.000000` is `0`. */
    private static function trimmed(string $decimal): string
    {
        if (str_contains($decimal, '.')) {
            $decimal = rtrim(rtrim($decimal, '0'), '.');
        }

        return $decimal === '' || $decimal === '-' || $decimal === '-0' ? '0' : $decimal;
    }
}
