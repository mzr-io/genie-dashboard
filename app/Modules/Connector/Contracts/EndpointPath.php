<?php

namespace App\Modules\Connector\Contracts;

/**
 * An Endpoint's path, parsed once into a URL AST (Story 2.9; AR-44). The template is a string of `/`-separated segments,
 * each a literal or one whole `{name}` placeholder; the AST is the list of those segments, stored with the template, and
 * every later use renders from the AST, never by substituting into a string.
 *
 * A path resolves only against the Data Source's base URL, so it must start with a single `/` and may not be absolute
 * (`https://host/x`), protocol-relative (`//host/x`), carry `\`, `?`, `#`, a control character or a space, hold an empty
 * segment, a `.` or `..` segment (also after percent-decoding, repeatedly) or an encoded separator (`%2f`, `%5c`).
 * Placeholder names match `[A-Za-z][A-Za-z0-9_]{0,63}` and are unique. {@see self::render()} percent-encodes each value as
 * one segment and refuses a value that is empty, `.`, `..` or holds `/`, naming the parameter.
 */
final readonly class EndpointPath
{
    public const MAX_LENGTH = 1024;

    public const MAX_SEGMENTS = 32;

    private const DECODE_ROUNDS = 8;

    public const NAME = '/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D';

    /** A literal segment: unreserved characters, sub-delimiters, `:`, `@` and percent-escapes. */
    private const LITERAL = '/\A(?:[A-Za-z0-9\-._~!$&\'()*+,;=:@]|%[0-9A-Fa-f]{2})+\z/D';

    /**
     * @param  list<array{type: 'literal', value: string}|array{type: 'param', name: string}>  $ast
     */
    private function __construct(public string $template, public array $ast) {}

    /**
     * @throws InvalidEndpointPath
     */
    public static function parse(mixed $template): self
    {
        if (! is_string($template) || $template === '') {
            throw new InvalidEndpointPath('path-required', 'Enter the path, starting with /.');
        }

        if (strlen($template) > self::MAX_LENGTH) {
            throw new InvalidEndpointPath('path-too-long', 'The path can have at most '.self::MAX_LENGTH.' characters.');
        }

        if (preg_match('/\A[A-Za-z][A-Za-z0-9+.\-]*:/', $template) === 1) {
            throw new InvalidEndpointPath('path-absolute', 'Enter a path such as /api/v2/revenue, not a full address. It is added to the data source base URL.');
        }

        if (str_starts_with($template, '//')) {
            throw new InvalidEndpointPath('path-protocol-relative', 'Enter a path such as /api/v2/revenue, not a host. It is added to the data source base URL.');
        }

        if ($template[0] !== '/') {
            throw new InvalidEndpointPath('path-leading-slash', 'The path must start with /.');
        }

        if (preg_match('/[\x00-\x20\x7f-\xff]/', $template) === 1) {
            throw new InvalidEndpointPath('path-invalid-characters', 'The path cannot contain spaces, control characters or characters outside ASCII. Percent-encode them.');
        }

        foreach (['\\' => 'path-backslash', '?' => 'path-query', '#' => 'path-fragment'] as $char => $reason) {
            if (str_contains($template, $char)) {
                throw new InvalidEndpointPath($reason, match ($char) {
                    '\\' => 'The path cannot contain a backslash.',
                    '?' => 'Leave the query string out of the path. Add query values as parameters.',
                    default => 'Leave the fragment (#) out of the path.',
                });
            }
        }

        $parts = explode('/', substr($template, 1));

        if (count($parts) > self::MAX_SEGMENTS) {
            throw new InvalidEndpointPath('path-too-many-segments', 'The path can have at most '.self::MAX_SEGMENTS.' segments.');
        }

        $ast = [];
        $names = [];

        foreach ($parts as $part) {
            if ($part === '') {
                throw new InvalidEndpointPath('path-empty-segment', 'The path cannot have an empty segment (// or a trailing /).');
            }

            if (preg_match('/\A\{([^{}]*)\}\z/D', $part, $m) === 1) {
                if (preg_match(self::NAME, $m[1]) !== 1) {
                    throw new InvalidEndpointPath('path-placeholder-invalid', 'A placeholder name starts with a letter and uses letters, digits and _ only, up to 64 characters.');
                }

                if (isset($names[$m[1]])) {
                    throw new InvalidEndpointPath('path-placeholder-duplicate', "The placeholder {$m[1]} is used more than once.");
                }

                $names[$m[1]] = true;
                $ast[] = ['type' => 'param', 'name' => $m[1]];

                continue;
            }

            if (str_contains($part, '{') || str_contains($part, '}')) {
                throw new InvalidEndpointPath('path-placeholder-invalid', 'A placeholder is a whole segment, written {name}.');
            }

            if (preg_match(self::LITERAL, $part) !== 1) {
                throw new InvalidEndpointPath('path-invalid-characters', 'The path has a character that is not allowed in a segment, or a % that is not followed by two hex digits.');
            }

            $decoded = $part;

            // Decode until stable, so %252e%252e is no way round the dot-segment rule.
            for ($i = 0; $i < self::DECODE_ROUNDS; $i++) {
                $next = rawurldecode($decoded);

                if ($next === $decoded) {
                    break;
                }

                $decoded = $next;
            }

            // Still changing after the last round: too many layers of encoding to trust.
            $stable = rawurldecode($decoded) === $decoded;
            $bare = explode(';', $decoded, 2)[0];

            if (! $stable || $bare === '.' || $bare === '..') {
                throw new InvalidEndpointPath('path-dot-segment', 'The path cannot have a . or .. segment.');
            }

            if (preg_match('/[\/\\\\\x00-\x1f\x7f]/', $decoded) === 1 || preg_match('/%(?:2f|5c)/i', $part) === 1) {
                throw new InvalidEndpointPath('path-encoded-separator', 'The path cannot contain an encoded separator (%2f or %5c) or an encoded control character.');
            }

            $ast[] = ['type' => 'literal', 'value' => $part];
        }

        return new self($template, $ast);
    }

    /**
     * The AST of a stored path, trusted: it was parsed on save.
     *
     * @param  list<array{type: string, value?: string, name?: string}>  $ast
     */
    public static function fromStored(string $template, array $ast): self
    {
        /** @var list<array{type: 'literal', value: string}|array{type: 'param', name: string}> $ast */
        return new self($template, $ast);
    }

    /** @return list<string> the placeholder names, in path order */
    public function placeholders(): array
    {
        $names = [];

        foreach ($this->ast as $segment) {
            if ($segment['type'] === 'param') {
                $names[] = $segment['name'];
            }
        }

        return $names;
    }

    /**
     * Whether a value can be one path segment.
     */
    public static function valueAllowed(string $value): bool
    {
        return $value !== '' && $value !== '.' && $value !== '..' && ! str_contains($value, '/');
    }

    /**
     * The path with every placeholder replaced by its value, percent-encoded as one segment. Nothing is sent: no fetch uses
     * this yet.
     *
     * @param  list<array{type: string, value?: string, name?: string}>  $ast
     * @param  array<string, string>  $values  placeholder name => value
     *
     * @throws InvalidPathValue naming the parameter whose value is missing, empty, `.`, `..` or holds `/`
     */
    public static function render(array $ast, array $values): string
    {
        $out = '';

        foreach ($ast as $segment) {
            if ($segment['type'] === 'literal') {
                $out .= '/'.($segment['value'] ?? '');

                continue;
            }

            $name = $segment['name'] ?? '';
            $value = $values[$name] ?? null;

            if (! is_string($value) || ! self::valueAllowed($value)) {
                throw new InvalidPathValue($name);
            }

            $out .= '/'.rawurlencode($value);
        }

        return $out;
    }
}
