<?php

namespace App\Modules\Mapping\Application\Expressions;

use App\Modules\Mapping\Contracts\InvalidExpression;

/**
 * A Text Template (Story 5.4, FR-26, AD-12): literal text mixed with tokens. `{field}` and `{field|format}` read a field of the response,
 * `{ROW_COUNT}` the rows that are left, `{period.start|MMM d}` and `{period.end|MMM d, yyyy}` the Block's period, `{user.name}` the signed-in person.
 * `{{` and `}}` are literal braces. The format names are a closed list; a token that is none of these is refused naming the token and its position.
 *
 * Part: `{t: lit, v}`, `{t: field, name, fmt, path?, p}`, `{t: row_count, fmt, p}`, `{t: period, end, fmt, p}` or `{t: user, key, p}`.
 */
final class TextTemplate
{
    /** The formats of a field token. */
    public const FIELD_FORMATS = ['text', 'number', 'integer', 'currency', 'percent', 'compact', 'date'];

    /** The date patterns of a period token (Unicode field letters, read with `Intl` in the viewer's locale). */
    public const DATE_PATTERNS = ['MMM d', 'MMM d, yyyy', 'MMMM yyyy', 'MMM yyyy', 'd MMM', 'EEE, MMM d', 'yyyy-MM-dd'];

    public const COUNT_FORMATS = ['number', 'integer'];

    public const MAX_LENGTH = 300;

    public const MAX_TOKENS = 12;

    /**
     * @return list<array<string, mixed>>
     *
     * @throws InvalidExpression
     */
    public static function parse(string $source): array
    {
        if (trim($source) === '') {
            throw new InvalidExpression('Enter the text of the template.', 1);
        }

        if (mb_strlen($source) > self::MAX_LENGTH) {
            throw new InvalidExpression('Use '.self::MAX_LENGTH.' characters or fewer.', self::MAX_LENGTH + 1);
        }

        if (preg_match('/[\p{Cc}\p{Cf}]/u', str_replace(["\r", "\n", "\t"], ' ', $source)) === 1) {
            throw new InvalidExpression('A control character is not allowed.', 1);
        }

        $parts = [];
        $literal = '';
        $tokens = 0;
        $length = mb_strlen($source);
        $i = 0;

        while ($i < $length) {
            $c = mb_substr($source, $i, 1);
            $next = $i + 1 < $length ? mb_substr($source, $i + 1, 1) : '';

            if ($c === '{' && $next === '{') {
                $literal .= '{';
                $i += 2;

                continue;
            }

            if ($c === '}' && $next === '}') {
                $literal .= '}';
                $i += 2;

                continue;
            }

            if ($c === '}') {
                throw new InvalidExpression('A single } has no { before it. Write }} for a literal brace.', $i + 1);
            }

            if ($c !== '{') {
                $literal .= $c;
                $i++;

                continue;
            }

            $close = mb_strpos($source, '}', $i);

            if ($close === false) {
                throw new InvalidExpression('This token is not closed. Add the closing }.', $i + 1);
            }

            if (++$tokens > self::MAX_TOKENS) {
                throw new InvalidExpression('Use '.self::MAX_TOKENS.' tokens or fewer.', $i + 1);
            }

            if ($literal !== '') {
                $parts[] = ['t' => 'lit', 'v' => $literal];
                $literal = '';
            }

            $parts[] = self::token(trim(mb_substr($source, $i + 1, $close - $i - 1)), $i + 1);
            $i = $close + 1;
        }

        if ($literal !== '') {
            $parts[] = ['t' => 'lit', 'v' => $literal];
        }

        return $parts;
    }

    /** @return array<string, mixed> */
    private static function token(string $body, int $pos): array
    {
        if ($body === '') {
            throw new InvalidExpression('This token is empty. Name a field, or remove the braces.', $pos);
        }

        $pipe = mb_strpos($body, '|');
        $name = trim($pipe === false ? $body : mb_substr($body, 0, $pipe));
        $format = $pipe === false ? null : trim(mb_substr($body, $pipe + 1));
        $shown = '{'.$body.'}';

        if ($name === '' || ($format !== null && $format === '')) {
            throw new InvalidExpression("{$shown} is missing its name or its format.", $pos);
        }

        if (str_contains((string) $format, '|')) {
            throw new InvalidExpression("{$shown} has more than one format.", $pos);
        }

        if ($name === 'ROW_COUNT') {
            if ($format !== null && ! in_array($format, self::COUNT_FORMATS, true)) {
                throw new InvalidExpression("{$shown}: ROW_COUNT takes the format number or integer.", $pos);
            }

            return ['t' => 'row_count', 'fmt' => $format ?? 'integer', 'p' => $pos];
        }

        if ($name === 'period.start' || $name === 'period.end') {
            if ($format !== null && ! in_array($format, self::DATE_PATTERNS, true)) {
                throw new InvalidExpression("{$shown}: the date format {$format} is not one of ".implode(', ', self::DATE_PATTERNS).'.', $pos);
            }

            return ['t' => 'period', 'end' => $name === 'period.end', 'fmt' => $format ?? 'MMM d, yyyy', 'p' => $pos];
        }

        if (str_starts_with($name, 'user.')) {
            $key = substr($name, 5);

            if (preg_match('/\A[a-z_]+\z/', $key) !== 1 || $format !== null) {
                throw new InvalidExpression("{$shown} is not a user token. Use {user.name} or {user.email}.", $pos);
            }

            return ['t' => 'user', 'key' => $key, 'p' => $pos];
        }

        if ($format !== null && ! in_array($format, self::FIELD_FORMATS, true)) {
            throw new InvalidExpression("{$shown}: the format {$format} is not one of ".implode(', ', self::FIELD_FORMATS).'.', $pos);
        }

        return ['t' => 'field', 'name' => $name, 'fmt' => $format, 'p' => $pos];
    }

    /**
     * The field names of a parsed template as stored paths.
     *
     * @param  list<array<string, mixed>>  $parts
     * @param  array<string, string>  $names  lower-case name => stored path; an ambiguous name maps to an empty string
     * @return list<array<string, mixed>>
     *
     * @throws InvalidExpression
     */
    public static function resolve(array $parts, array $names): array
    {
        return array_map(function (array $part) use ($names): array {
            if ($part['t'] !== 'field') {
                return $part;
            }

            $key = mb_strtolower((string) $part['name']);
            $format = $part['fmt'] === null ? '' : '|'.$part['fmt'];

            if (! array_key_exists($key, $names)) {
                throw new InvalidExpression("{{$part['name']}{$format}}: {$part['name']} isn’t a field of the response.", (int) $part['p']);
            }

            if ($names[$key] === '') {
                throw new InvalidExpression("{{$part['name']}{$format}}: {$part['name']} names more than one field. Use its full name.", (int) $part['p']);
            }

            return $part + ['path' => $names[$key]];
        }, $parts);
    }

    /**
     * The stored paths a resolved template reads.
     *
     * @param  list<array<string, mixed>>  $parts
     * @return list<string>
     */
    public static function paths(array $parts): array
    {
        $paths = [];

        foreach ($parts as $part) {
            if ($part['t'] === 'field' && isset($part['path'])) {
                $paths[] = (string) $part['path'];
            }
        }

        return $paths;
    }
}
