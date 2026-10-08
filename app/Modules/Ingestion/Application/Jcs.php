<?php

namespace App\Modules\Ingestion\Application;

use InvalidArgumentException;

/**
 * JSON canonicalisation (RFC 8785) for the values a fetch key is made of: strings, integers, booleans, null, lists and objects (a PHP
 * array with string keys, or a `stdClass`: use `(object) $map` for a map that may be empty or have numeric names, which an array
 * would take for a list). No float is accepted, so no number is ever formatted: a float is a programming error here, not a rounding.
 * Object members are sorted by the UTF-16 code units of their names, strings are escaped as ECMAScript does (`"`, `\`, `\b \t \n \f \r` and
 * the other C0 controls as `\u00xx`; everything else, `/` and DEL included, literally in UTF-8) and there is no whitespace.
 */
final class Jcs
{
    /** @throws InvalidArgumentException for a float, an object of the wrong kind or text that is not UTF-8 */
    public static function encode(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => (string) $value,
            is_string($value) => self::string($value),
            $value instanceof \stdClass => self::object(get_object_vars($value)),
            is_array($value) => array_is_list($value) ? self::list($value) : self::object($value),
            default => throw new InvalidArgumentException('Only strings, integers, booleans, null, lists and objects can be canonicalised.'),
        };
    }

    /** @param  list<mixed>  $list */
    private static function list(array $list): string
    {
        return '['.implode(',', array_map(self::encode(...), $list)).']';
    }

    /** @param  array<mixed>  $object */
    private static function object(array $object): string
    {
        $members = [];

        foreach ($object as $key => $value) {
            $members[] = [self::utf16((string) $key), (string) $key, $value];
        }

        // UTF-16BE bytes compare as the code units do.
        usort($members, fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return '{'.implode(',', array_map(fn (array $m): string => self::string($m[1]).':'.self::encode($m[2]), $members)).'}';
    }

    private static function utf16(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Text to canonicalise must be valid UTF-8.');
        }

        return mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
    }

    private static function string(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Text to canonicalise must be valid UTF-8.');
        }

        $out = '';

        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $char = $text[$i];
            $byte = ord($char);

            $out .= match (true) {
                $char === '"' => '\\"',
                $char === '\\' => '\\\\',
                $byte === 0x08 => '\\b',
                $byte === 0x09 => '\\t',
                $byte === 0x0A => '\\n',
                $byte === 0x0C => '\\f',
                $byte === 0x0D => '\\r',
                $byte < 0x20 => sprintf('\\u%04x', $byte),
                default => $char,
            };
        }

        return '"'.$out.'"';
    }
}
