<?php

namespace App\Modules\Mapping\Contracts;

use BcMath\Number;

/**
 * Exact comparison of decimal strings (never a float): a plain decimal or a lexeme with an exponent (`1E3`, `-2.5e-1`) compares by value, through `BcMath\Number`.
 * A value that is not a decimal, or whose exponent is too large to expand, has no value and compares as null.
 */
final class DecimalValue
{
    /** Beyond this an exponent is not expanded: it would allocate a huge string. */
    public const MAX_EXPONENT = 1000;

    private const PATTERN = '/\A([+-]?)(\d*)\.?(\d*)(?:[eE]([+-]?\d+))?\z/D';

    /** The decimal as a plain `-?digits(.digits)?` string, or null when it is not a decimal (or its exponent is too large). */
    public static function plain(string $value): ?string
    {
        $value = trim($value);

        if (strlen($value) > 400 || preg_match(self::PATTERN, $value, $m) !== 1) {
            return null;
        }

        [, $sign, $whole, $fraction] = $m;
        $exponent = (int) ($m[4] ?? 0);

        if ($whole.$fraction === '' || abs($exponent) > self::MAX_EXPONENT) {
            return null;
        }

        $digits = $whole.$fraction;
        $point = strlen($whole) + $exponent;

        if ($point <= 0) {
            $out = '0.'.str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $out = $digits.str_repeat('0', $point - strlen($digits));
        } else {
            $out = substr($digits, 0, $point).'.'.substr($digits, $point);
        }

        return ($sign === '-' ? '-' : '').$out;
    }

    /** -1, 0 or 1 as `$a <=> $b`, or null when either is not a decimal. */
    public static function compare(string $a, string $b): ?int
    {
        $left = self::plain($a);
        $right = self::plain($b);

        if ($left === null || $right === null) {
            return null;
        }

        /** @var numeric-string $left */
        /** @var numeric-string $right */
        return (new Number($left))->compare(new Number($right));
    }

    /**
     * `$value` as a percentage of `$total`, exact to two places (half away from zero) and without trailing zeros: "75" for 12 of 16, "33.33" for 1 of 3.
     * Null when either is not a decimal or the total is 0.
     */
    public static function percent(string $value, string $total): ?string
    {
        $part = self::plain($value);
        $whole = self::plain($total);

        if ($part === null || $whole === null) {
            return null;
        }

        /** @var numeric-string $part */
        /** @var numeric-string $whole */
        $divisor = new Number($whole);

        if ($divisor->compare(new Number('0')) === 0) {
            return null;
        }

        $out = (string) (new Number($part))->mul(new Number('100'))->div($divisor, 6)->round(2);

        return str_contains($out, '.') ? rtrim(rtrim($out, '0'), '.') : $out;
    }
}
