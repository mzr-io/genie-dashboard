<?php

namespace App\Platform\Json;

/**
 * The structure of a decoded sample (Story 3.8): every distinct path with the types seen there and an example, a count of
 * scalars and arrays, where the records are, and a table view for arrays of objects. Everything is derived from a tree made
 * by {@see LosslessJson}, so a number is shown as the lexeme that was received (`1.0` keeps its zero, `12345678901234567890.10`
 * keeps every digit); no value goes through a float and PHP's own decoder is never called (an architecture test covers this
 * path, as it covers the decoder).
 *
 * The walk is iterative, so a deeply nested sample cannot exhaust the stack. The tree is returned flat, in pre-order, each
 * node with its parent and depth, so the browser can fold branches without a recursive document.
 *
 * Distinct paths: every array element shares one `[*]` node, so 3 records with the same keys are one node per key. A path
 * that holds more than one type (`integer | null`) is one node with the types joined. The scalar and array counts are of
 * these distinct nodes.
 */
final class SampleShape
{
    /** How many rows of an array a table view carries; the count of all is reported beside it. */
    public const TABLE_ROWS = 50;

    /** How many characters of a string an example or a table cell shows. */
    public const EXAMPLE_CHARS = 120;

    private const TYPE_ORDER = ['object', 'array', 'string', 'integer', 'decimal', 'boolean', 'null'];

    private const SCALARS = ['string', 'integer', 'decimal', 'boolean', 'null'];

    /**
     * @return array{
     *     nodes: list<array{id: int, parent: int|null, depth: int, key: string|null, path: string, display: string, type: string, example: string|null, count: int|null}>,
     *     tables: array<int, array{columns: list<array{key: string, display: string}>, rows: list<list<string>>, total: int}>,
     *     records: array{path: string, display: string, count: int}|null,
     *     scalars: int,
     *     arrays: int,
     *     objects: int
     * }
     */
    public static function of(mixed $document): array
    {
        $root = new SampleNode;
        $stack = [[$document, $root]];

        while ($stack !== []) {
            [$value, $node] = array_pop($stack);

            if ($value instanceof JsonObject) {
                $node->types['object'] = true;
                $pending = [];

                foreach ($value->entries() as [$key, $member]) {
                    $pending[] = [$member, $node->member($key)];
                }

                // Pushed in reverse, so the first member is walked first and an example comes from the first record.
                foreach (array_reverse($pending) as $item) {
                    $stack[] = $item;
                }
            } elseif (is_array($value)) {
                $node->types['array'] = true;
                $node->count = ($node->count ?? 0) + count($value);

                if ($value === []) {
                    continue;
                }

                $element = $node->element();

                foreach ($value as $item) {
                    $node->total++;

                    if (count($node->rows) < self::TABLE_ROWS) {
                        $node->rows[] = $item;
                    }
                }

                foreach (array_reverse($value) as $item) {
                    $stack[] = [$item, $element];
                }
            } else {
                $type = match (true) {
                    $value === null => 'null',
                    is_bool($value) => 'boolean',
                    $value instanceof DecimalLiteral => $value->isInteger() ? 'integer' : 'decimal',
                    default => 'string',
                };
                $node->types[$type] = true;

                if ($node->example === null && $value !== null) {
                    $node->example = self::text($value);
                }
            }
        }

        return self::flatten($root);
    }

    /**
     * @return array{
     *     nodes: list<array{id: int, parent: int|null, depth: int, key: string|null, path: string, display: string, type: string, example: string|null, count: int|null}>,
     *     tables: array<int, array{columns: list<array{key: string, display: string}>, rows: list<list<string>>, total: int}>,
     *     records: array{path: string, display: string, count: int}|null,
     *     scalars: int,
     *     arrays: int,
     *     objects: int
     * }
     */
    private static function flatten(SampleNode $root): array
    {
        $flat = [];
        $tables = [];
        $scalars = 0;
        $arrays = 0;
        $objects = 0;
        $best = null;
        $stack = [[$root, null, 0, []]];

        while ($stack !== []) {
            /** @var array{0: SampleNode, 1: int|null, 2: int, 3: list<string|null>} $item */
            $item = array_pop($stack);
            [$node, $parent, $depth, $above] = $item;
            $id = count($flat);
            $segments = $depth === 0 ? [] : [...$above, $node->segment];
            $types = array_values(array_filter(self::TYPE_ORDER, fn (string $type): bool => isset($node->types[$type])));

            $flat[] = [
                'id' => $id,
                'parent' => $parent,
                'depth' => $depth,
                'key' => $node->segment,
                'path' => SamplePath::stored($segments),
                'display' => SamplePath::display($segments),
                'type' => $types === [] ? 'null' : implode(' | ', $types),
                'example' => $node->example,
                'count' => $node->count,
            ];

            $arrays += in_array('array', $types, true) ? 1 : 0;
            $objects += in_array('object', $types, true) ? 1 : 0;
            $scalars += $types === [] || array_intersect($types, self::SCALARS) !== [] ? 1 : 0;

            if (isset($node->types['array'], $node->children['*']->types['object'])) {
                $tables[$id] = self::table($node, $segments);

                // The shallowest array of objects is the records; the first in document order wins a tie.
                if ($best === null || $depth < $best[0]) {
                    $best = [$depth, $segments, (int) $node->count];
                }
            }

            foreach (array_reverse(array_values($node->children)) as $child) {
                $stack[] = [$child, $id, $depth + 1, $segments];
            }
        }

        return [
            'nodes' => $flat,
            'tables' => $tables,
            'records' => $best === null ? null : [
                'path' => SamplePath::stored([...$best[1], null]),
                'display' => SamplePath::display([...$best[1], null]),
                'count' => $best[2],
            ],
            'scalars' => $scalars,
            'arrays' => $arrays,
            'objects' => $objects,
        ];
    }

    /**
     * @param  list<string|null>  $segments
     * @return array{columns: list<array{key: string, display: string}>, rows: list<list<string>>, total: int}
     */
    private static function table(SampleNode $array, array $segments): array
    {
        $columns = [];

        foreach ($array->children['*']->children as $child) {
            if ($child->segment !== null) {
                $columns[] = ['key' => $child->segment, 'display' => SamplePath::display([...$segments, null, $child->segment])];
            }
        }

        $rows = [];

        foreach ($array->rows as $row) {
            $cells = [];

            foreach ($columns as $column) {
                $cells[] = $row instanceof JsonObject && $row->has($column['key']) ? self::cell($row->get($column['key'])) : '';
            }

            $rows[] = $cells;
        }

        return ['columns' => $columns, 'rows' => $rows, 'total' => $array->total];
    }

    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value instanceof JsonObject => '{…}',
            is_array($value) => '[…]',
            default => self::text($value),
        };
    }

    private static function text(mixed $value): string
    {
        $text = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof DecimalLiteral => $value->lexeme,
            default => (string) $value,
        };

        return mb_strlen($text) > self::EXAMPLE_CHARS ? mb_substr($text, 0, self::EXAMPLE_CHARS).'…' : $text;
    }
}
