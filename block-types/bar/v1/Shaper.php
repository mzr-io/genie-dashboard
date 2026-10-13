<?php

namespace BlockTypes\Bar\V1;

use BcMath\Number;

/**
 * The bar v1 Shaper (Story 5.11): a pure function from the category list and the value lists to the render payload (`payload.ts`). A series key (long
 * data: one row per category and series) is pivoted into one series per key. Measures stay decimal strings; the sort by value and the stack total are
 * exact. A gap is a null, an all-zero chart still has its categories, and nothing is divided.
 */
final class Shaper
{
    private const STATES = ['normal', 'loading', 'empty', 'stale', 'unavailable', 'error'];

    private const KINDS = ['number', 'percent', 'currency', 'compact'];

    private const DECIMAL = '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\z/';

    private const SERIES = 6;

    /**
     * @param  array<string, mixed>  $slots  `category`, `values_1` to `values_6` and `series_key` are lists, one entry per row
     * @param  array<string, mixed>  $options  state, `headlineFormat` (the values' format), `chartKind` (`grouped` or `stacked`), `orientation`, `valueLabels`, `sort` (`data` or `value`), `fieldNames`, staleSince, errorMessage
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $state = in_array($options['state'] ?? null, self::STATES, true) ? $options['state'] : 'normal';
        $categories = $this->texts($slots['category'] ?? null);
        $fields = is_array($options['fieldNames'] ?? null) ? $options['fieldNames'] : [];
        $keys = $this->texts($slots['series_key'] ?? null);
        $lists = [];

        for ($n = 1; $n <= self::SERIES; $n++) {
            $values = $this->measures($slots["values_{$n}"] ?? null);

            if ($values !== null) {
                $lists[] = ['key' => "values_{$n}", 'name' => $this->text($fields["values_{$n}"] ?? null) ?? "Series {$n}", 'values' => $values];
            }
        }

        // Long data: the first values list is split by the series key, the categories made distinct in order of appearance.
        if ($categories !== null && $keys !== null && $lists !== []) {
            [$categories, $lists] = $this->pivot($categories, $keys, $lists[0]['values']);
        }

        $series = array_slice($lists, 0, self::SERIES);
        $partial = false;
        $any = false;

        foreach ($series as $entry) {
            $partial = $partial || in_array(null, $entry['values'], true);
            $any = $any || array_filter($entry['values'], fn ($v): bool => $v !== null) !== [];
        }

        if ($state === 'normal' && ($categories === null || $series === [])) {
            $state = 'unavailable';
        } elseif ($state === 'normal' && (! $any || $categories === [])) {
            $state = 'empty';
        }

        $categories ??= [];
        $stacked = ($options['chartKind'] ?? null) === 'stacked';

        if (($options['sort'] ?? null) === 'value' && $series !== []) {
            [$categories, $series] = $this->sorted($categories, $series, $stacked);
        }

        $totals = [];

        if ($stacked) {
            foreach (array_keys($categories) as $at) {
                $totals[] = $this->total(array_map(fn (array $s): ?string => $s['values'][$at] ?? null, $series));
            }
        }

        return [
            'state' => $state,
            'kind' => $stacked ? 'stacked' : 'grouped',
            'orientation' => ($options['orientation'] ?? null) === 'horizontal' ? 'horizontal' : 'vertical',
            'valueLabels' => ($options['valueLabels'] ?? false) === true,
            'title' => $this->text($slots['title'] ?? null),
            'subtitle' => $this->text($slots['subtitle'] ?? null),
            'icon' => $this->text($slots['icon'] ?? null),
            'footerAction' => $this->text($slots['footer_action'] ?? null),
            'categories' => $categories,
            'categoryLabel' => $this->text($fields['category'] ?? null),
            'series' => $series,
            'totals' => $totals,
            'partial' => $partial,
            'format' => $this->descriptor(is_array($options['headlineFormat'] ?? null) ? $options['headlineFormat'] : []),
            'emptyDisplay' => $this->text($options['emptyDisplay'] ?? null),
            'staleSince' => $this->text($options['staleSince'] ?? null),
            'errorMessage' => $this->text($options['errorMessage'] ?? null),
        ];
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $keys
     * @param  list<string|null>  $values
     * @return array{0: list<string>, 1: list<array{key: string, name: string, values: list<string|null>}>}
     */
    private function pivot(array $categories, array $keys, array $values): array
    {
        $order = [];
        $names = [];
        $cells = [];

        foreach ($categories as $at => $category) {
            $name = $keys[$at] ?? '';
            $order[$category] = true;
            $names[$name] = true;
            $cells[$name][$category] = $values[$at] ?? null;
        }

        $list = array_map('strval', array_keys($order));
        $series = [];

        foreach (array_slice(array_map('strval', array_keys($names)), 0, self::SERIES) as $n => $name) {
            $series[] = [
                'key' => 'values_'.($n + 1),
                'name' => $name === '' ? 'Series '.($n + 1) : $name,
                'values' => array_map(fn (string $category): ?string => $cells[$name][$category] ?? null, $list),
            ];
        }

        return [$list, $series];
    }

    /**
     * The categories largest first (the stack's total, else the first series), a tie keeping the order the data came in.
     *
     * @param  list<string>  $categories
     * @param  list<array{key: string, name: string, values: list<string|null>}>  $series
     * @return array{0: list<string>, 1: list<array{key: string, name: string, values: list<string|null>}>}
     */
    private function sorted(array $categories, array $series, bool $stacked): array
    {
        $order = array_keys($categories);

        usort($order, function (int $a, int $b) use ($series, $stacked): int {
            $x = $this->keyOf($a, $series, $stacked);
            $y = $this->keyOf($b, $series, $stacked);

            if ($x === null || $y === null) {
                return ($x === null) <=> ($y === null) ?: $a <=> $b;
            }

            return $this->number($y)->compare($this->number($x)) ?: $a <=> $b;
        });

        return [
            array_map(fn (int $at): string => $categories[$at], $order),
            array_map(fn (array $s): array => ['values' => array_map(fn (int $at): ?string => $s['values'][$at] ?? null, $order)] + $s, $series),
        ];
    }

    /**
     * The value a category is ranked by: its stack's total, else the first series.
     *
     * @param  list<array{key: string, name: string, values: list<string|null>}>  $series
     */
    private function keyOf(int $at, array $series, bool $stacked): ?string
    {
        return $stacked
            ? $this->total(array_map(fn (array $s): ?string => $s['values'][$at] ?? null, $series))
            : ($series[0]['values'][$at] ?? null);
    }

    /** @param  list<string|null>  $values */
    private function total(array $values): ?string
    {
        $sum = null;

        foreach ($values as $value) {
            if ($value !== null) {
                $sum = $sum === null ? $this->number($value) : $sum->add($this->number($value));
            }
        }

        return $sum === null ? null : $this->trim((string) $sum);
    }

    /** A measure as an exact number (its exponent expanded). */
    private function number(string $value): Number
    {
        /** @var numeric-string $plain */
        $plain = $this->plain($value);

        return new Number($plain);
    }

    /** A decimal with an exponent as a plain decimal, for the exact arithmetic. */
    private function plain(string $value): string
    {
        if (preg_match('/^([+-]?)(\d*)\.?(\d*)[eE]([+-]?\d+)$/', trim($value), $m) !== 1) {
            return trim($value);
        }

        $digits = $m[2].$m[3];
        $point = strlen($m[2]) + (int) $m[4];

        if ($point <= 0) {
            return $m[1].'0.'.str_repeat('0', min(-$point, 400)).$digits;
        }

        return $m[1].($point >= strlen($digits) ? $digits.str_repeat('0', min($point - strlen($digits), 400)) : substr($digits, 0, $point).'.'.substr($digits, $point));
    }

    private function trim(string $decimal): string
    {
        if (str_contains($decimal, '.')) {
            $decimal = rtrim(rtrim($decimal, '0'), '.');
        }

        return $decimal === '' || $decimal === '-' || $decimal === '-0' ? '0' : $decimal;
    }

    /** @return list<string>|null */
    private function texts(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        $out = [];

        foreach (array_values($values) as $value) {
            $out[] = is_int($value) ? (string) $value : (is_string($value) ? $value : '');
        }

        return $out;
    }

    /** @return list<string|null>|null */
    private function measures(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        $out = [];

        foreach (array_values($values) as $value) {
            $out[] = $this->decimal($value);
        }

        return $out;
    }

    private function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match(self::DECIMAL, $value) === 1 ? $value : null;
    }

    private function text(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function descriptor(array $input): array
    {
        $places = $input['decimalPlaces'] ?? null;
        $code = $input['currencyCode'] ?? null;

        return [
            'kind' => in_array($input['kind'] ?? null, self::KINDS, true) ? $input['kind'] : 'number',
            'decimalPlaces' => is_int($places) && $places >= 0 && $places <= 20 ? $places : null,
            'thousandsSeparator' => ($input['thousandsSeparator'] ?? true) !== false,
            'currencyCode' => is_string($code) && preg_match('/^[A-Za-z]{3}\z/', $code) === 1 ? strtoupper($code) : null,
            'currencyDisplay' => ($input['currencyDisplay'] ?? null) === 'code' ? 'code' : 'symbol',
        ];
    }
}
