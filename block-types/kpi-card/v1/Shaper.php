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

    /**
     * @param  array<string, mixed>  $slots  slot key => value (measures as decimal strings or integers)
     * @param  array<string, mixed>  $options  state, headline/comparison format descriptors, lowerIsBetter, staleSince, errorMessage
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $state = in_array($options['state'] ?? null, self::STATES, true) ? $options['state'] : 'normal';
        $headlineValue = $this->decimal($slots['headline_value'] ?? null);
        $headline = $headlineValue === null
            ? ['available' => false, 'field' => self::HEADLINE_FIELD]
            : ['available' => true, 'value' => $headlineValue, 'format' => $this->descriptor($this->array($options['headlineFormat'] ?? null))];

        if ($state === 'normal' && $headlineValue === null) {
            $state = 'unavailable';
        }

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
            'statusBadge' => $this->text($slots['status_badge'] ?? null),
            'staleSince' => $this->text($options['staleSince'] ?? null),
            'errorMessage' => $this->text($options['errorMessage'] ?? null),
        ];
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
