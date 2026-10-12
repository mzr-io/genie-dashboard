<?php

namespace App\Platform\Json;

use InvalidArgumentException;

/**
 * The stored form of a path into a sample (Story 3.8, AR-26): a strict subset of RFC 9535 made of member names and `[*]` only
 * (`$.data.monthly[*].revenue`). A name that is not a plain identifier is written as `['a b']` (single quotes, `\\` and `\'`
 * escaped). A path is a list of segments: a string is a member name and `null` is the wildcard. The dotted form for people
 * (`data.monthly[].revenue`) is for display only and is never parsed or stored.
 *
 * {@see self::select()} evaluates a stored path over a tree made by {@see LosslessJson}; a conformance test compares it with
 * `symfony/json-path`. A wildcard selects the elements of an array and the member values of an object, as RFC 9535 does.
 */
final class SamplePath
{
    private const IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_]*\z/D';

    /** @param  list<string|null>  $segments */
    public static function stored(array $segments): string
    {
        $path = '$';

        foreach ($segments as $segment) {
            $path .= $segment === null
                ? '[*]'
                : (preg_match(self::IDENTIFIER, $segment) === 1 ? '.'.$segment : "['".strtr($segment, ['\\' => '\\\\', "'" => "\\'"])."']");
        }

        return $path;
    }

    /** @param  list<string|null>  $segments */
    public static function display(array $segments): string
    {
        $text = '';

        foreach ($segments as $segment) {
            if ($segment === null) {
                $text .= '[]';
            } elseif (preg_match(self::IDENTIFIER, $segment) === 1) {
                $text .= ($text === '' ? '' : '.').$segment;
            } else {
                $text .= "['".strtr($segment, ['\\' => '\\\\', "'" => "\\'"])."']";
            }
        }

        return $text === '' ? '$' : $text;
    }

    /**
     * @return list<string|null>
     *
     * @throws InvalidArgumentException when the path is not in the subset
     */
    public static function parse(string $path): array
    {
        if ($path === '' || $path[0] !== '$') {
            throw new InvalidArgumentException('A stored path starts with $.');
        }

        $segments = [];
        $pos = 1;
        $len = strlen($path);

        while ($pos < $len) {
            if (substr($path, $pos, 3) === '[*]') {
                $segments[] = null;
                $pos += 3;
            } elseif ($path[$pos] === '.' && preg_match('/\G\.([A-Za-z_][A-Za-z0-9_]*)/', $path, $m, 0, $pos) === 1) {
                $segments[] = $m[1];
                $pos += strlen($m[0]);
            } elseif (substr($path, $pos, 2) === "['") {
                $name = '';
                $pos += 2;

                while (true) {
                    if ($pos >= $len) {
                        throw new InvalidArgumentException('A quoted member name is not closed.');
                    }

                    $char = $path[$pos];

                    if ($char === '\\' && $pos + 1 < $len && ($path[$pos + 1] === '\\' || $path[$pos + 1] === "'")) {
                        $name .= $path[$pos + 1];
                        $pos += 2;
                    } elseif ($char === "'") {
                        break;
                    } elseif ($char === '\\') {
                        throw new InvalidArgumentException('A quoted member name has an escape outside the subset.');
                    } else {
                        $name .= $char;
                        $pos++;
                    }
                }

                if (substr($path, $pos, 2) !== "']") {
                    throw new InvalidArgumentException('A quoted member name is not closed.');
                }

                $segments[] = $name;
                $pos += 2;
            } else {
                throw new InvalidArgumentException('Only member names and [*] are in the stored path subset.');
            }
        }

        return $segments;
    }

    /**
     * The nodes a stored path selects, in document order.
     *
     * @return list<mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function select(mixed $document, string $path): array
    {
        $nodes = [$document];

        foreach (self::parse($path) as $segment) {
            $next = [];

            foreach ($nodes as $node) {
                if ($segment === null) {
                    if (is_array($node)) {
                        array_push($next, ...$node);
                    } elseif ($node instanceof JsonObject) {
                        foreach ($node->entries() as [, $value]) {
                            $next[] = $value;
                        }
                    }
                } elseif ($node instanceof JsonObject && $node->has($segment)) {
                    $next[] = $node->get($segment);
                }
            }

            $nodes = $next;
        }

        return $nodes;
    }
}
