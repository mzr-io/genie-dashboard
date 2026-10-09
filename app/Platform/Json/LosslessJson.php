<?php

namespace App\Platform\Json;

use InvalidArgumentException;

/**
 * The only JSON decoder for source data (Story 2.6; NFR exact numbers, AD-33). It is written by hand and never calls
 * PHP's own decoder, which would turn `1.10` into `1.1` and a long decimal into a float.
 *
 * It accepts the RFC 8259 grammar and nothing else: a leading BOM, comments, `NaN`, `Infinity`, a trailing comma, trailing
 * content, invalid UTF-8, a lone surrogate escape and a duplicate key within one object are all {@see JsonParseFailed}.
 * (A duplicate is refused rather than resolved: last-wins would silently change data.)
 *
 * Result: `null`, bool, string, {@see DecimalLiteral} for every number (the exact lexeme as received), a list for an array
 * and a {@see JsonObject} (key order kept) for an object. The parser is iterative, with its own stack, so a deeply nested
 * body cannot exhaust the PHP stack. When a depth limit is given, a pre-scan counts container depth outside strings before
 * any value is built and refuses a deeper body with {@see JsonDepthExceeded}. With no limit nothing is checked: no number is
 * invented (`dashflow.tunables.guards.depth_limit` is a `pending_input` setting, see {@see self::configuredDepthLimit()}).
 *
 * {@see self::canonical()} gives the lossless-canonical bytes: no whitespace, object keys in byte order of their UTF-8
 * form, strings with one fixed escaping (`"`, `\` and the C0 controls only: `\b \f \n \r \t` short, the rest lower-case
 * `\u00xx`; everything else raw UTF-8, `/` not escaped) and numbers as the lexemes received. Two bodies that differ only in
 * whitespace, key order or how a character is escaped give identical bytes, and `1.10` differs from `1.1`.
 */
final class LosslessJson
{
    /** What ends a plain run inside a string: the quote, the backslash and every C0 control. */
    private const STRING_STOP = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";

    private const NUMBER = '/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/';

    private const SIMPLE_ESCAPES = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t"];

    /**
     * The depth limit from `dashflow.tunables.guards.depth_limit`: null when unset.
     *
     * @throws InvalidLimitSetting when it is set but not a positive whole number
     */
    public static function configuredDepthLimit(): ?int
    {
        return LimitSetting::positive('depth_limit', function_exists('app') && app()->bound('config') ? config('dashflow.tunables.guards.depth_limit.value') : null);
    }

    /**
     * @throws JsonDepthExceeded when `$maxDepth` is set and the body nests deeper (nothing is parsed)
     * @throws JsonParseFailed when the body is not RFC 8259 JSON
     */
    public static function decode(string $json, ?int $maxDepth = null): mixed
    {
        return self::run($json, $maxDepth, true);
    }

    /**
     * Checks a body against exactly the rules of {@see self::decode()} (grammar, UTF-8, duplicate keys, depth) without
     * building the value: only the keys of the object being read are held, so memory stays near the size of the body.
     *
     * @throws JsonDepthExceeded
     * @throws JsonParseFailed
     */
    public static function validate(string $json, ?int $maxDepth = null): void
    {
        self::run($json, $maxDepth, false);
    }

    private static function run(string $json, ?int $maxDepth, bool $build): mixed
    {
        if ($maxDepth !== null) {
            self::assertDepth($json, $maxDepth);
        }

        if (! mb_check_encoding($json, 'UTF-8')) {
            throw new JsonParseFailed('the body is not valid UTF-8');
        }

        $len = strlen($json);
        $pos = 0;
        /** @var list<JsonFrame> the containers being built, innermost last */
        $stack = [];
        $value = null;
        $mode = 'value';

        while (true) {
            $pos += strspn($json, " \t\n\r", $pos);

            if ($mode === 'end') {
                if ($pos !== $len) {
                    throw new JsonParseFailed('unexpected content after the value', $pos);
                }

                return $value;
            }

            if ($pos >= $len) {
                throw new JsonParseFailed('the body ends early', $pos);
            }

            $c = $json[$pos];

            if ($mode === 'key') {
                if ($c !== '"') {
                    throw new JsonParseFailed('an object key must be a string', $pos);
                }

                $key = self::readString($json, $pos, $len);
                $pos += strspn($json, " \t\n\r", $pos);

                if ($pos >= $len || $json[$pos] !== ':') {
                    throw new JsonParseFailed('a colon is expected after a key', $pos);
                }

                $pos++;
                $frame = $stack[count($stack) - 1];

                if (array_key_exists($key, $frame->members)) {
                    throw new JsonParseFailed('a key is repeated within one object', $pos);
                }

                $frame->key = $key;
                $mode = 'value';

                continue;
            }

            if ($mode === 'after') {
                $isObject = $stack[count($stack) - 1]->isObject;

                if ($c === ',') {
                    $pos++;
                    $mode = $isObject ? 'key' : 'value';

                    continue;
                }

                if ($c !== ($isObject ? '}' : ']')) {
                    throw new JsonParseFailed('a comma or the end of the container is expected', $pos);
                }

                $pos++;
                $frame = array_pop($stack);
                $value = ! $build ? null : ($frame->isObject ? new JsonObject($frame->members) : $frame->members);
                $mode = self::deliver($stack, $value, $build);

                continue;
            }

            // mode === 'value'
            switch (true) {
                case $c === '{':
                    $pos++;
                    $pos += strspn($json, " \t\n\r", $pos);

                    if ($pos < $len && $json[$pos] === '}') {
                        $pos++;
                        $value = $build ? new JsonObject : null;
                        $mode = self::deliver($stack, $value, $build);
                    } else {
                        $stack[] = new JsonFrame(true);
                        $mode = 'key';
                    }

                    break;
                case $c === '[':
                    $pos++;
                    $pos += strspn($json, " \t\n\r", $pos);

                    if ($pos < $len && $json[$pos] === ']') {
                        $pos++;
                        $value = $build ? [] : null;
                        $mode = self::deliver($stack, $value, $build);
                    } else {
                        $stack[] = new JsonFrame(false);
                        $mode = 'value';
                    }

                    break;
                case $c === '"':
                    $value = self::readString($json, $pos, $len);
                    $mode = self::deliver($stack, $value, $build);

                    break;
                case $c === '-' || ($c >= '0' && $c <= '9'):
                    if (preg_match(self::NUMBER, $json, $m, 0, $pos) !== 1) {
                        throw new JsonParseFailed('a number is malformed', $pos);
                    }

                    $pos += strlen($m[0]);
                    $value = $build ? new DecimalLiteral($m[0]) : null;
                    $mode = self::deliver($stack, $value, $build);

                    break;
                case $c === 't' && substr_compare($json, 'true', $pos, 4) === 0:
                    $pos += 4;
                    $value = true;
                    $mode = self::deliver($stack, $value, $build);

                    break;
                case $c === 'f' && substr_compare($json, 'false', $pos, 5) === 0:
                    $pos += 5;
                    $value = false;
                    $mode = self::deliver($stack, $value, $build);

                    break;
                case $c === 'n' && substr_compare($json, 'null', $pos, 4) === 0:
                    $pos += 4;
                    $value = null;
                    $mode = self::deliver($stack, $value, $build);

                    break;
                default:
                    throw new JsonParseFailed('a value is expected', $pos);
            }
        }
    }

    /** The lossless-canonical bytes of a body. */
    public static function canonical(string $json, ?int $maxDepth = null): string
    {
        return self::canonicalOf(self::decode($json, $maxDepth));
    }

    /** The SHA-256 (lower-case hex) of {@see self::canonical()}. */
    public static function hash(string $json, ?int $maxDepth = null): string
    {
        return hash('sha256', self::canonical($json, $maxDepth));
    }

    /**
     * The lossless-canonical bytes of an already decoded value. Iterative, like the parser.
     *
     * @throws InvalidArgumentException when the value holds something the decoder never produces (a float, an object)
     */
    public static function canonicalOf(mixed $value): string
    {
        return self::emit($value, true);
    }

    /**
     * Writes an already decoded value back as JSON text (Story 2.11) with the same escaping as the canonical form, every number
     * as the lexeme received and the members of an object in the order they hold (so a merged document keeps the order of the
     * first page). {@see self::decode()} of the result gives back an equal value. Iterative, like the parser.
     *
     * @throws InvalidArgumentException when the value holds something the decoder never produces (a float, an object)
     */
    public static function encode(mixed $value): string
    {
        return self::emit($value, false);
    }

    private static function emit(mixed $value, bool $sortKeys): string
    {
        $out = '';
        // Work items: a value to write, or a literal string to append.
        $work = [[0, $value]];

        while ($work !== []) {
            [$kind, $item] = array_pop($work);

            if ($kind === 1) {
                $out .= $item;

                continue;
            }

            if ($item === null) {
                $out .= 'null';
            } elseif ($item === true) {
                $out .= 'true';
            } elseif ($item === false) {
                $out .= 'false';
            } elseif (is_string($item)) {
                $out .= '"'.self::escape($item).'"';
            } elseif ($item instanceof DecimalLiteral) {
                $out .= $item->lexeme;
            } elseif ($item instanceof JsonObject) {
                $entries = $item->entries();

                if ($sortKeys) {
                    usort($entries, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
                }

                $out .= '{';
                $work[] = [1, '}'];

                for ($i = count($entries) - 1; $i >= 0; $i--) {
                    $work[] = [0, $entries[$i][1]];
                    $work[] = [1, '"'.self::escape($entries[$i][0]).'":'];

                    if ($i > 0) {
                        $work[] = [1, ','];
                    }
                }
            } elseif (is_array($item) && array_is_list($item)) {
                $out .= '[';
                $work[] = [1, ']'];

                for ($i = count($item) - 1; $i >= 0; $i--) {
                    $work[] = [0, $item[$i]];

                    if ($i > 0) {
                        $work[] = [1, ','];
                    }
                }
            } else {
                throw new InvalidArgumentException('That value is not something the lossless decoder produces.');
            }
        }

        return $out;
    }

    /**
     * Attaches a finished value to the container being built, or makes it the result.
     *
     * @param  list<JsonFrame>  $stack
     */
    private static function deliver(array $stack, mixed $value, bool $build): string
    {
        $top = array_key_last($stack);

        if ($top === null) {
            return 'end';
        }

        $frame = $stack[$top];

        if ($frame->isObject) {
            $frame->members[(string) $frame->key] = $build ? $value : true;
            $frame->key = null;
        } else {
            if ($build) {
                $frame->members[] = $value;
            }
        }

        return 'after';
    }

    /** Reads the string that starts at `$pos` (on its opening quote) and leaves `$pos` after its closing quote. */
    private static function readString(string $json, int &$pos, int $len): string
    {
        $pos++;
        $out = '';

        while (true) {
            $run = strcspn($json, self::STRING_STOP, $pos);

            if ($run > 0) {
                $out .= substr($json, $pos, $run);
                $pos += $run;
            }

            if ($pos >= $len) {
                throw new JsonParseFailed('a string is not closed', $pos);
            }

            $c = $json[$pos];

            if ($c === '"') {
                $pos++;

                return $out;
            }

            if ($c !== '\\') {
                throw new JsonParseFailed('a control character is not allowed in a string', $pos);
            }

            $next = $json[$pos + 1] ?? '';

            if (isset(self::SIMPLE_ESCAPES[$next])) {
                $out .= self::SIMPLE_ESCAPES[$next];
                $pos += 2;

                continue;
            }

            if ($next !== 'u') {
                throw new JsonParseFailed('an escape is not valid', $pos);
            }

            $code = self::hex4($json, $pos + 2);

            if ($code === null) {
                throw new JsonParseFailed('a \\u escape needs four hex digits', $pos);
            }

            $pos += 6;

            if ($code >= 0xD800 && $code <= 0xDBFF) {
                $low = ($json[$pos] ?? '') === '\\' && ($json[$pos + 1] ?? '') === 'u' ? self::hex4($json, $pos + 2) : null;

                if ($low === null || $low < 0xDC00 || $low > 0xDFFF) {
                    throw new JsonParseFailed('a high surrogate is not followed by a low surrogate', $pos);
                }

                $pos += 6;
                $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
            } elseif ($code >= 0xDC00 && $code <= 0xDFFF) {
                throw new JsonParseFailed('a low surrogate has no high surrogate', $pos);
            }

            $out .= self::utf8($code);
        }
    }

    private static function hex4(string $json, int $at): ?int
    {
        $hex = substr($json, $at, 4);

        return strlen($hex) === 4 && ctype_xdigit($hex) ? (int) hexdec($hex) : null;
    }

    private static function utf8(int $code): string
    {
        return match (true) {
            $code < 0x80 => chr($code & 0x7F),
            $code < 0x800 => chr((0xC0 | ($code >> 6)) & 0xFF).chr(0x80 | ($code & 0x3F)),
            $code < 0x10000 => chr((0xE0 | ($code >> 12)) & 0xFF).chr(0x80 | (($code >> 6) & 0x3F)).chr(0x80 | ($code & 0x3F)),
            default => chr((0xF0 | ($code >> 18)) & 0xFF).chr(0x80 | (($code >> 12) & 0x3F)).chr(0x80 | (($code >> 6) & 0x3F)).chr(0x80 | ($code & 0x3F)),
        };
    }

    /** The one fixed escaping of the canonical form. */
    private static function escape(string $s): string
    {
        static $table = null;

        if ($table === null) {
            $table = ['"' => '\\"', '\\' => '\\\\', "\x08" => '\\b', "\x0c" => '\\f', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t'];

            for ($i = 0; $i < 0x20; $i++) {
                $table[chr($i)] ??= sprintf('\\u%04x', $i);
            }
        }

        return strtr($s, $table);
    }

    /** Counts container depth outside strings, before any value is built. */
    private static function assertDepth(string $json, int $limit): void
    {
        $len = strlen($json);
        $i = 0;
        $depth = 0;

        while ($i < $len) {
            $i += strcspn($json, '"{}[]', $i);

            if ($i >= $len) {
                return;
            }

            $c = $json[$i];

            if ($c === '"') {
                $i++;

                while (true) {
                    $i += strcspn($json, '"\\', $i);

                    if ($i >= $len) {
                        return;
                    }

                    if ($json[$i] === '"') {
                        $i++;

                        break;
                    }

                    $i += 2;
                }

                continue;
            }

            if ($c === '{' || $c === '[') {
                if (++$depth > $limit) {
                    throw new JsonDepthExceeded($limit);
                }
            } elseif ($depth > 0) {
                $depth--;
            }

            $i++;
        }
    }
}
