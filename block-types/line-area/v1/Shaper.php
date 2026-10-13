<?php

namespace BlockTypes\LineArea\V1;

/**
 * The line-area v1 Shaper (Story 5.8c): a pure function from already-extracted slot values to the render payload (`payload.ts`). It does no I/O, reads
 * no clock and never decodes JSON (an architecture test enforces it). Measures stay decimal strings, never floats, and nothing is formatted for a
 * locale: a format descriptor tells the browser how. A row without a value is a null (a gap), never 0.
 */
final class Shaper
{
    private const STATES = ['normal', 'loading', 'empty', 'stale', 'unavailable', 'error'];

    private const KINDS = ['number', 'percent', 'currency', 'compact'];

    private const DECIMAL = '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\z/';

    private const SERIES = 6;

    /** How the axis writes a date: `MMM` (Jan), `MMM yyyy`, `MMM d`, or the `Intl` styles. */
    private const PATTERNS = ['MMM', 'MMM yyyy', 'MMM d', 'short', 'medium', 'long', 'full'];

    /**
     * @param  array<string, mixed>  $slots  slot key => value (`x` and `series_n` are lists, one entry per row)
     * @param  array<string, mixed>  $options  state, `headlineFormat` (the series format descriptor), `directLabels`, `fieldNames` (slot => the mapped field's name), staleSince, errorMessage, emptyDisplay
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $state = in_array($options['state'] ?? null, self::STATES, true) ? $options['state'] : 'normal';
        $x = $this->texts($slots['x'] ?? null);
        $fields = is_array($options['fieldNames'] ?? null) ? $options['fieldNames'] : [];
        $series = [];
        $partial = false;
        $any = false;

        for ($n = 1; $n <= self::SERIES; $n++) {
            $values = $this->measures($slots["series_{$n}"] ?? null);

            if ($values === null) {
                continue;
            }

            $any = $any || array_filter($values, fn ($v): bool => $v !== null) !== [];
            $partial = $partial || in_array(null, $values, true);
            $name = $this->text($slots["series_label_{$n}"] ?? null) ?? $this->text($fields["series_{$n}"] ?? null) ?? "Series {$n}";
            $series[] = ['key' => "series_{$n}", 'name' => $name, 'values' => $values];
        }

        // The axis and at least one series are the chart; without them there is nothing to draw, and it says so rather than drawing a zero line.
        if ($state === 'normal' && ($x === null || $series === [])) {
            $state = 'unavailable';
        } elseif ($state === 'normal' && (! $any || $x === [])) {
            $state = 'empty';
        }

        $highlight = $this->text($slots['highlight_x'] ?? null);
        $at = $highlight === null || $x === null ? false : array_search($highlight, $x, true);
        $target = $this->decimal($slots['target_value'] ?? null);
        $emptyDisplay = $this->text($options['emptyDisplay'] ?? null);

        return [
            'state' => $state,
            'kind' => ($options['chartKind'] ?? null) === 'area' ? 'area' : 'line',
            'title' => $this->text($slots['title'] ?? null),
            'subtitle' => $this->text($slots['subtitle'] ?? null),
            'icon' => $this->text($slots['icon'] ?? null),
            'footerAction' => $this->text($slots['footer_action'] ?? null),
            'x' => $x ?? [],
            'xLabel' => $this->text($fields['x'] ?? null),
            'xPattern' => in_array($options['xPattern'] ?? null, self::PATTERNS, true) ? $options['xPattern'] : null,
            'series' => $series,
            'partial' => $partial,
            'highlight' => $at === false ? null : $at,
            'target' => $target === null ? null : ['value' => $target, 'label' => $this->text($slots['target_label'] ?? null)],
            'format' => $this->descriptor($this->array($options['headlineFormat'] ?? null)),
            'directLabels' => ($options['directLabels'] ?? true) !== false,
            'emptyDisplay' => $emptyDisplay,
            'staleSince' => $this->text($options['staleSince'] ?? null),
            'errorMessage' => $this->text($options['errorMessage'] ?? null),
        ];
    }

    /** @return list<string>|null the axis values as text, null when the slot has none */
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

    /** @return list<string|null>|null decimal strings and nulls, null when the slot has no list */
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

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
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
