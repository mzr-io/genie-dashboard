<?php

namespace App\Modules\Access\Application;

/**
 * The shape of a user attribute value by the key's value type (Story 2.12). Surrounding ASCII spaces are trimmed first;
 * a value that is then empty or longer than the limit is always refused. A type is a closed set (no per-key pattern),
 * so no rule can be unbounded or hostile.
 *
 *  text        1 to 256 characters of visible Unicode (valid UTF-8, no control, format, separator-only or unassigned characters)
 *  identifier  1 to 128 characters of letters, digits, `.`, `_` and `-`
 *  integer     an optional minus sign and 1 to 18 digits, with no leading zero except `0` itself
 */
final class AttributeValueRules
{
    public const TEXT_MAX = 256;

    public const IDENTIFIER_MAX = 128;

    /**
     * @return string the value to store (trimmed)
     *
     * @throws \InvalidArgumentException with the reason as its message: `empty`, `too_long` or `invalid`
     */
    public static function normalise(string $valueType, mixed $raw): string
    {
        // The framework turns an empty string into null before it gets here.
        if ($raw === null) {
            throw new \InvalidArgumentException('empty');
        }

        if (! is_string($raw)) {
            throw new \InvalidArgumentException('invalid');
        }

        $value = trim($raw, ' ');

        if ($value === '') {
            throw new \InvalidArgumentException('empty');
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException('invalid');
        }

        $max = match ($valueType) {
            'text' => self::TEXT_MAX,
            'identifier' => self::IDENTIFIER_MAX,
            'integer' => 19,
            default => throw new \InvalidArgumentException('invalid'),
        };

        if (mb_strlen($value, 'UTF-8') > $max) {
            throw new \InvalidArgumentException('too_long');
        }

        $ok = match ($valueType) {
            'text' => preg_match('/\A[^\p{C}\p{Zl}\p{Zp}]+\z/u', $value) === 1 && preg_match('/[^\p{Z}]/u', $value) === 1,
            'identifier' => preg_match('/\A[A-Za-z0-9._-]+\z/D', $value) === 1,
            default => preg_match('/\A(?:0|-?[1-9][0-9]{0,17})\z/D', $value) === 1,
        };

        return $ok ? $value : throw new \InvalidArgumentException('invalid');
    }
}
