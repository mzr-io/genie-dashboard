<?php

namespace BlockTypes\PieDonut\V1;

use BcMath\Number;

/**
 * The pie-donut v1 Shaper (Story 5.11): a pure function from the category and value lists to the render payload. Slices that are not positive are not
 * drawn but stay in the legend. More than six slices are folded: the five largest and one "Other" that adds the rest exactly (a ratio is folded by the
 * Top-N step with "group the rest as Other", which makes it from the sums; a plain sum is made here). Largest first, clockwise from 12 o'clock, by
 * value unless the data order is asked for.
 */
final class Shaper
{
    private const STATES = ['normal', 'loading', 'empty', 'stale', 'unavailable', 'error'];

    private const KINDS = ['number', 'percent', 'currency', 'compact'];

    private const DECIMAL = '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\z/';

    private const SLICES = 6;

    /**
     * @param  array<string, mixed>  $slots  `pie_category` and `pie_value` are lists, one entry per row
     * @param  array<string, mixed>  $options  state, `headlineFormat`, `chartKind` (`pie` or `donut`), `sort` (`value` by default or `data`), `centre` (`value`, `total` or `none`), `centreLabel`, staleSince, errorMessage
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $state = in_array($options['state'] ?? null, self::STATES, true) ? $options['state'] : 'normal';
        $names = $this->texts($slots['pie_category'] ?? null);
        $values = $this->measures($slots['pie_value'] ?? null);
        $fields = is_array($options['fieldNames'] ?? null) ? $options['fieldNames'] : [];
        $slices = [];

        foreach ($names ?? [] as $at => $name) {
            $slices[] = ['name' => $name === '' ? '—' : $name, 'value' => $values[$at] ?? null, 'order' => $at];
        }

        $byValue = ($options['sort'] ?? 'value') !== 'data';
        $folded = false;

        if (count($slices) > self::SLICES) {
            // The five largest stay; the rest is one slice, added exactly.
            $ranked = $slices;
            usort($ranked, fn (array $a, array $b): int => $this->order($b['value'], $a['value']) ?: $a['order'] <=> $b['order']);
            $keep = array_slice($ranked, 0, self::SLICES - 1);
            $rest = array_slice($ranked, self::SLICES - 1);
            $sum = null;

            foreach ($rest as $slice) {
                if ($slice['value'] !== null) {
                    $sum = $sum === null ? $this->number($slice['value']) : $sum->add($this->number($slice['value']));
                }
            }

            $slices = $keep;

            if (! $byValue) {
                usort($slices, fn (array $a, array $b): int => $a['order'] <=> $b['order']);
            }

            $slices[] = ['name' => 'Other', 'value' => $sum === null ? null : $this->trim((string) $sum), 'order' => PHP_INT_MAX];
            $folded = true;
        } elseif ($byValue) {
            usort($slices, fn (array $a, array $b): int => $this->order($b['value'], $a['value']) ?: $a['order'] <=> $b['order']);
        }

        $any = array_filter($slices, fn (array $slice): bool => $this->isPositive($slice)) !== [];

        if ($state === 'normal' && ($names === null || $values === null)) {
            $state = 'unavailable';
        } elseif ($state === 'normal' && ! $any) {
            $state = 'empty';
        }

        $total = null;
        $largest = null;

        foreach ($slices as $slice) {
            if ($this->isPositive($slice)) {
                $number = $this->number((string) $slice['value']);
                $total = $total === null ? $number : $total->add($number);
                $largest ??= $slice['value'];
            }
        }

        $centre = in_array($options['centre'] ?? null, ['value', 'total', 'none'], true) ? $options['centre'] : 'none';
        $donut = ($options['chartKind'] ?? null) === 'donut';
        $label = $this->text($slots['centre_label'] ?? null) ?? $this->text($options['centreLabel'] ?? null);

        return [
            'state' => $state,
            'kind' => $donut ? 'donut' : 'pie',
            'title' => $this->text($slots['title'] ?? null),
            'subtitle' => $this->text($slots['subtitle'] ?? null),
            'icon' => $this->text($slots['icon'] ?? null),
            'footerAction' => $this->text($slots['footer_action'] ?? null),
            'categories' => array_column($slices, 'name'),
            'categoryLabel' => $this->text($fields['pie_category'] ?? null),
            'values' => array_column($slices, 'value'),
            'valueLabel' => $this->text($fields['pie_value'] ?? null),
            'folded' => $folded,
            'centre' => $donut && $centre !== 'none'
                ? ['label' => $label, 'value' => $centre === 'total' ? ($total === null ? null : $this->trim((string) $total)) : $largest]
                : null,
            'partial' => in_array(null, array_column($slices, 'value'), true),
            'format' => $this->descriptor(is_array($options['headlineFormat'] ?? null) ? $options['headlineFormat'] : []),
            'emptyDisplay' => $this->text($options['emptyDisplay'] ?? null),
            'staleSince' => $this->text($options['staleSince'] ?? null),
            'errorMessage' => $this->text($options['errorMessage'] ?? null),
        ];
    }

    /** @param  array{name: string, value: string|null, order: int}  $slice */
    private function isPositive(array $slice): bool
    {
        return $slice['value'] !== null && $this->number($slice['value'])->compare(new Number('0')) > 0;
    }

    /** -1, 0 or 1 by exact comparison; a missing value is below any number. */
    private function order(?string $a, ?string $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 0 : 1) <=> ($b === null ? 0 : 1);
        }

        return $this->number($a)->compare($this->number($b));
    }

    /** A measure as an exact number (its exponent expanded). */
    private function number(string $value): Number
    {
        /** @var numeric-string $plain */
        $plain = $this->plain($value);

        return new Number($plain);
    }

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
