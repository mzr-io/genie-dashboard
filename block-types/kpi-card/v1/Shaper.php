<?php

namespace BlockTypes\KpiCard\V1;

/**
 * The kpi-card v1 Shaper: a pure function from already-extracted slot values to the render payload (`payload.ts`). It does no I/O, reads no
 * clock and never decodes JSON (an architecture test enforces it). Measures stay decimal strings, never floats, and nothing is formatted for a
 * locale: a format descriptor tells the browser how.
 */
final class Shaper
{
    private const STATES = ['normal', 'loading', 'empty', 'stale', 'unavailable', 'error'];

    private const KINDS = ['number', 'percent', 'currency', 'compact', 'date', 'relative-time', 'duration'];

    private const DECIMAL = '/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?\z/';

    private const HEADLINE_FIELD = 'Headline value';

    private const ROLES = ['headline', 'primary', 'secondary', 'badge'];

    private const SIZES = ['sm', 'md', 'lg'];

    private const WEIGHTS = ['regular', 'medium', 'bold'];

    /** A band's colour: the accent colour is never a status. */
    private const COLORS = ['success', 'warning', 'error', 'info', 'neutral'];

    private const ICON = '/^[a-z0-9]+(-[a-z0-9]+)*\z/';

    /**
     * @param  array<string, mixed>  $slots  slot key => value (measures as decimal strings or integers)
     * @param  array<string, mixed>  $options  state, headline/comparison format descriptors, lowerIsBetter, staleSince, errorMessage, and the presentation rules (Story 3.15a): headlineEmphasis, prefix, suffix, emptyDisplay, emptySlots (the mapped slots with no value) and statusBand ({text, color, icon}, the band the engine matched)
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $state = in_array($options['state'] ?? null, self::STATES, true) ? $options['state'] : 'normal';
        $headlineValue = $this->decimal($slots['headline_value'] ?? null);
        $headline = $headlineValue === null
            ? ['available' => false, 'field' => self::HEADLINE_FIELD]
            : ['available' => true, 'value' => $headlineValue, 'format' => $this->descriptor($this->array($options['headlineFormat'] ?? null))];

        $emphasis = $this->emphasis($options['headlineEmphasis'] ?? null);

        if ($headlineValue !== null && $emphasis !== null) {
            $headline['emphasis'] = $emphasis;
        }

        if ($state === 'normal' && $headlineValue === null) {
            $state = 'unavailable';
        }

        $emptyDisplay = $this->text($options['emptyDisplay'] ?? null);
        $comparisonValue = $this->decimal($slots['comparison'] ?? null);
        $comparison = $comparisonValue === null ? null : [
            'value' => $comparisonValue,
            'format' => $this->descriptor($this->array($options['comparisonFormat'] ?? null)),
            'direction' => $this->direction($comparisonValue),
            'lowerIsBetter' => ($options['lowerIsBetter'] ?? false) === true,
        ];

        return [
            'state' => $state,
            'title' => $this->text($slots['title'] ?? null),
            'subtitle' => $this->text($slots['subtitle'] ?? null),
            'icon' => $this->text($slots['icon'] ?? null),
            'footerAction' => $this->text($slots['footer_action'] ?? null),
            'kpiLabel' => $this->text($slots['kpi_label'] ?? null),
            'headline' => $headline,
            'comparison' => $comparison,
            'comparisonLabel' => $this->text($slots['comparison_label'] ?? null),
            'secondaryNote' => $this->text($slots['secondary_note'] ?? null),
            'statusBadge' => $this->statusBadge($options['statusBand'] ?? null) ?? $this->text($slots['status_badge'] ?? null),
            'prefix' => $this->text($options['prefix'] ?? null),
            'suffix' => $this->text($options['suffix'] ?? null),
            'emptyDisplay' => $emptyDisplay,
            'emptySlots' => $emptyDisplay === null ? [] : $this->emptySlots($options['emptySlots'] ?? null),
            'staleSince' => $this->text($options['staleSince'] ?? null),
            'errorMessage' => $this->text($options['errorMessage'] ?? null),
        ];
    }

    /**
     * The band the engine matched, as a badge with its icon and label (colour is never the only signal); a malformed band is no band.
     *
     * @return array{text: string, color: string, icon: string}|null
     */
    private function statusBadge(mixed $band): ?array
    {
        if (! is_array($band)) {
            return null;
        }

        $text = $this->text($band['text'] ?? null);
        $color = $band['color'] ?? null;
        $icon = $band['icon'] ?? null;

        if ($text === null || ! in_array($color, self::COLORS, true) || ! is_string($icon) || preg_match(self::ICON, $icon) !== 1) {
            return null;
        }

        return ['text' => $text, 'color' => $color, 'icon' => $icon];
    }

    /** @return array{role: string, size?: string, weight?: string}|null a size or weight left unset is omitted: the card keeps its own default */
    private function emphasis(mixed $emphasis): ?array
    {
        if (! is_array($emphasis)) {
            return null;
        }

        $role = $emphasis['role'] ?? null;
        $size = $emphasis['size'] ?? null;
        $weight = $emphasis['weight'] ?? null;
        $out = ['role' => in_array($role, self::ROLES, true) ? $role : 'headline'];

        if (in_array($size, self::SIZES, true)) {
            $out['size'] = $size;
        }

        if (in_array($weight, self::WEIGHTS, true)) {
            $out['weight'] = $weight;
        }

        return $out;
    }

    /** @return list<string> */
    private function emptySlots(mixed $slots): array
    {
        $out = [];

        foreach (is_array($slots) ? $slots : [] as $slot) {
            if (is_string($slot) && $slot !== '' && ! in_array($slot, $out, true)) {
                $out[] = $slot;
            }
        }

        return $out;
    }

    /** A decimal string as given (integers become their digits); anything else, floats included, is not a measure. */
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

    private function direction(string $decimal): string
    {
        $mantissa = substr($decimal, 0, strcspn($decimal, 'eE'));

        if (preg_match('/[1-9]/', $mantissa) !== 1) {
            return 'flat';
        }

        return str_starts_with($mantissa, '-') ? 'down' : 'up';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function descriptor(array $input): array
    {
        $places = $input['decimalPlaces'] ?? null;
        $code = $input['currencyCode'] ?? null;
        $suffix = $input['unitsSuffix'] ?? null;

        return [
            'kind' => in_array($input['kind'] ?? null, self::KINDS, true) ? $input['kind'] : 'number',
            'decimalPlaces' => is_int($places) && $places >= 0 && $places <= 20 ? $places : null,
            'thousandsSeparator' => ($input['thousandsSeparator'] ?? true) !== false,
            'currencyCode' => is_string($code) && preg_match('/^[A-Za-z]{3}\z/', $code) === 1 ? strtoupper($code) : null,
            'currencyDisplay' => ($input['currencyDisplay'] ?? null) === 'code' ? 'code' : 'symbol',
            'datePattern' => in_array($input['datePattern'] ?? null, ['short', 'medium', 'long', 'full'], true) ? $input['datePattern'] : 'medium',
            'unitsSuffix' => is_string($suffix) && $suffix !== '' ? $suffix : null,
        ];
    }
}
