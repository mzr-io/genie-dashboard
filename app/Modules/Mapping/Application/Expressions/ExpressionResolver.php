<?php

namespace App\Modules\Mapping\Application\Expressions;

use App\Modules\Mapping\Contracts\InvalidExpression;

/**
 * Turns the field names of a parsed expression into stored paths (Story 5.3): a name is a field's short name, its displayed name, or the name of an
 * earlier calculated field. The tree keeps both, so the Admin reads names and the engine reads paths.
 */
final class ExpressionResolver
{
    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, string>  $names  lower-case name => stored path (`calc:<name>` for a calculated field); an ambiguous name is mapped to an empty string
     * @return array<string, mixed>
     *
     * @throws InvalidExpression a name that is not a field, or is the name of several
     */
    public static function resolve(array $node, array $names): array
    {
        $type = $node['t'];

        if ($type === 'field') {
            $key = mb_strtolower((string) $node['name']);

            if (! array_key_exists($key, $names)) {
                throw new InvalidExpression("{$node['name']} isn’t a field of the response.", (int) $node['p']);
            }

            if ($names[$key] === '') {
                throw new InvalidExpression("{$node['name']} names more than one field. Use its full name.", (int) $node['p']);
            }

            return $node + ['path' => $names[$key]];
        }

        if ($type === 'neg' || $type === 'not') {
            return ['x' => self::resolve($node['x'], $names)] + $node;
        }

        if ($type === 'bin') {
            return ['l' => self::resolve($node['l'], $names), 'r' => self::resolve($node['r'], $names)] + $node;
        }

        if ($type === 'call') {
            return ['args' => array_map(fn (array $arg): array => self::resolve($arg, $names), $node['args'])] + $node;
        }

        return $node;
    }

    /**
     * The stored paths a resolved tree reads.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    public static function paths(array $node): array
    {
        return match ($node['t']) {
            'field' => isset($node['path']) ? [(string) $node['path']] : [],
            'neg', 'not' => self::paths($node['x']),
            'bin' => [...self::paths($node['l']), ...self::paths($node['r'])],
            'call' => array_merge([], ...array_map(fn (array $a): array => self::paths($a), $node['args'])),
            default => [],
        };
    }
}
