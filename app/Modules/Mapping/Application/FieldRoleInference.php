<?php

namespace App\Modules\Mapping\Application;

use App\Modules\Mapping\Contracts\FieldRole;
use App\Modules\Mapping\Contracts\FieldRoleReport;
use App\Modules\Mapping\Contracts\FieldRoles;
use App\Platform\Json\SamplePath;

/**
 * Infers each field's role and the record-path candidates from a sample's structure (Story 3.11a). Pure: no model, no float, no I/O, and
 * numbers are only ever looked at as the type name the shape carries. Keys are split into lowercase words (`created_at` and `createdAt` are
 * `created`, `at`); "matches" means a whole word, with an optional plural `s`.
 *
 * Roles, in order:
 *  1. A string whose example is an ISO-8601 date or datetime, or whose key has a word `date|time|timestamp|month|year|period` (or ends in
 *     `_at` / `_on`), is `time`.
 *  2. A boolean is `filter`.
 *  3. Inside the record array: a number is `measure`, except a key with the word `id|code|zip|postcode|phone|key|number`, which is
 *     `dimension`; a string with the word `name|title|label|description` is `label`, any other string `dimension`.
 *  4. Outside it: a number is `value`, a string `label`.
 * A path that holds only numbers is a number, only booleans a boolean; any other mix (or only null) is treated as a string.
 *
 * Confidence is `high` when the key's name points to the same role as the type does (a time word on a date, an id word on a number, a label
 * word on a string, a measure word such as `revenue` on a number), else `low`. An override (`config.field_roles`) wins, is `overridden` and `high`.
 *
 * Suggestion: a `filter` or `dimension` field that is numeric and whose key has a measure word (and no identifier word) `revenue|amount|total|price|cost|sales|spend|
 * count|quantity` carries `suggestion: {role: measure}`; it is never applied. An overridden field has none.
 *
 * Candidates: every array of objects. `suggested` is the one with most rows (ties: the shallower, then the first in document order);
 * `pagination_like` (the path has a key `links|meta|pagination|page|paging|next|prev|cursor`) is never suggested while another exists.
 * `needs_choice` is true with more than one candidate, or when one lies deeper than 2 levels (its array node's depth is above 2).
 * The chosen path is the given one, else the suggested one.
 */
final class FieldRoleInference implements FieldRoles
{
    public const ISO = '/\A\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?\z/D';

    private const TIME_WORDS = ['date', 'datetime', 'time', 'timestamp', 'month', 'year', 'period'];

    private const IDENTIFIER_WORDS = ['id', 'code', 'zip', 'postcode', 'phone', 'key', 'number'];

    private const LABEL_WORDS = ['name', 'title', 'label', 'description'];

    private const MEASURE_WORDS = ['revenue', 'amount', 'total', 'price', 'cost', 'sales', 'spend', 'count', 'quantity'];

    private const PAGINATION_WORDS = ['links', 'meta', 'pagination', 'page', 'paging', 'next', 'prev', 'cursor'];

    private const DEEP = 2;

    public function infer(array $shape, ?string $recordPath, array $overrides): FieldRoleReport
    {
        /** @var list<array<string, mixed>> $nodes */
        $nodes = is_array($shape['nodes'] ?? null) ? $shape['nodes'] : [];
        $children = [];

        foreach ($nodes as $node) {
            if ($node['parent'] !== null) {
                $children[$node['parent']][] = $node;
            }
        }

        [$candidates, $deep] = $this->candidates($nodes, $children);
        $suggested = null;

        foreach ($candidates as $i => $candidate) {
            if ($candidate['suggested']) {
                $suggested = $candidate['path'];
            }
        }

        $chosen = $recordPath ?? $suggested;
        $prefix = $chosen === null ? null : $this->segments($chosen);
        $fields = [];

        foreach ($nodes as $node) {
            $types = array_map('trim', explode('|', (string) $node['type']));

            if (in_array('object', $types, true) || in_array('array', $types, true)) {
                continue;
            }

            $fields[] = $this->field($node, $types, $prefix, $overrides);
        }

        return new FieldRoleReport(
            $candidates,
            count($candidates) > 1 || $deep,
            $chosen,
            $chosen === null ? null : SamplePath::display($prefix ?? []),
            $fields,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<int, list<array<string, mixed>>>  $children
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function candidates(array $nodes, array $children): array
    {
        $found = [];
        $deep = false;

        foreach ($nodes as $order => $node) {
            if (! in_array('array', explode(' | ', (string) $node['type']), true)) {
                continue;
            }

            $element = null;

            foreach ($children[$node['id']] ?? [] as $child) {
                if ($child['key'] === null && in_array('object', explode(' | ', (string) $child['type']), true)) {
                    $element = $child;
                }
            }

            if ($element === null) {
                continue;
            }

            $columns = [];

            foreach ($children[$element['id']] ?? [] as $child) {
                if ($child['key'] !== null) {
                    $columns[] = $child['key'];
                }
            }

            $segments = $this->segments((string) $element['path']);
            $deep = $deep || $node['depth'] > self::DEEP;
            $found[] = [
                'path' => (string) $element['path'],
                'display' => (string) $element['display'],
                'rows' => (int) $node['count'],
                'columns' => $columns,
                'depth' => (int) $node['depth'],
                'order' => $order,
                'suggested' => false,
                'pagination_like' => $this->words(array_filter($segments, 'is_string'), self::PAGINATION_WORDS),
            ];
        }

        $pool = array_filter($found, fn (array $c): bool => ! $c['pagination_like']) ?: $found;
        $best = null;

        foreach ($pool as $i => $candidate) {
            if ($best === null
                || $candidate['rows'] > $found[$best]['rows']
                || ($candidate['rows'] === $found[$best]['rows'] && $candidate['depth'] < $found[$best]['depth'])) {
                $best = $i;
            }
        }

        if ($best !== null) {
            $found[$best]['suggested'] = true;
        }

        foreach ($found as &$candidate) {
            unset($candidate['order']);
        }

        return [$found, $deep];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $types
     * @param  list<string|null>|null  $prefix
     * @param  array<string, string>  $overrides
     * @return array<string, mixed>
     */
    private function field(array $node, array $types, ?array $prefix, array $overrides): array
    {
        $path = (string) $node['path'];
        $segments = $this->segments($path);
        $inRecords = $prefix !== null && count($segments) > count($prefix) && array_slice($segments, 0, count($prefix)) === $prefix;
        $key = $node['key'] === null ? '' : (string) $node['key'];
        $example = $node['example'] === null ? null : (string) $node['example'];
        $present = array_values(array_diff($types, ['null']));
        $numeric = $present !== [] && array_diff($present, ['integer', 'decimal']) === [];
        $boolean = $present === ['boolean'];
        $string = ! $numeric && ! $boolean;

        $timeName = $this->timeName($key);
        $idName = $this->words([$key], self::IDENTIFIER_WORDS);
        $labelName = $this->words([$key], self::LABEL_WORDS);
        $measureName = $this->words([$key], self::MEASURE_WORDS);

        if ($string && (($example !== null && preg_match(self::ISO, $example) === 1) || $timeName)) {
            $role = FieldRole::Time;
            $agrees = $timeName && $example !== null && preg_match(self::ISO, $example) === 1;
        } elseif ($boolean) {
            $role = FieldRole::Filter;
            $agrees = false;
        } elseif ($inRecords) {
            if ($numeric) {
                $role = $idName ? FieldRole::Dimension : FieldRole::Measure;
                $agrees = $idName || $measureName;
            } else {
                $role = $labelName ? FieldRole::Label : FieldRole::Dimension;
                $agrees = $labelName;
            }
        } elseif ($numeric) {
            $role = FieldRole::Value;
            $agrees = $measureName;
        } else {
            $role = FieldRole::Label;
            $agrees = $labelName;
        }

        $override = isset($overrides[$path]) ? FieldRole::tryFrom($overrides[$path]) : null;
        $suggestion = $override === null && $numeric && ($role === FieldRole::Filter || $role === FieldRole::Dimension) && $measureName && ! $idName
            ? ['role' => FieldRole::Measure->value]
            : null;

        return [
            'path' => $path,
            'display' => (string) $node['display'],
            'type' => (string) $node['type'],
            'example' => $example,
            'role' => ($override ?? $role)->value,
            'confidence' => $override !== null || $agrees ? 'high' : 'low',
            'overridden' => $override !== null,
            'in_records' => $inRecords,
            'suggestion' => $suggestion,
        ];
    }

    private function timeName(string $key): bool
    {
        $words = $this->split($key);

        return $this->words([$key], self::TIME_WORDS) || (count($words) > 1 && in_array(end($words), ['at', 'on'], true));
    }

    /**
     * @param  iterable<string>  $keys
     * @param  list<string>  $wanted
     */
    private function words(iterable $keys, array $wanted): bool
    {
        foreach ($keys as $key) {
            foreach ($this->split($key) as $word) {
                if (in_array($word, $wanted, true) || (str_ends_with($word, 's') && in_array(substr($word, 0, -1), $wanted, true))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private function split(string $key): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $key) ?? $key;
        $words = preg_split('/[^A-Za-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    /** @return list<string|null> */
    private function segments(string $path): array
    {
        try {
            return SamplePath::parse($path);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }
}
