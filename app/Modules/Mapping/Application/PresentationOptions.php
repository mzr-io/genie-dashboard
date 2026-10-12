<?php

namespace App\Modules\Mapping\Application;

use App\Modules\BlockTypes\Contracts\KpiCardSlots;
use App\Modules\Mapping\Contracts\DecimalValue;

/**
 * The Shaper's options from a Draft's presentation rules (Story 3.15a), and the one place the threshold bands are evaluated: exact decimal comparison on the
 * headline value (`BcMath\Number` through {@see DecimalValue}, never a float; `1E2` and `100` are the same value), first match top to bottom. A headline that
 * is null or not a number matches no band. The empty display is for a mapped slot with no value, never the headline (which is Unavailable).
 */
final class PresentationOptions
{
    /**
     * @param  array<string, mixed>  $presentation  `config.presentation`, already validated when it was stored
     * @param  array<string, string|bool|null>  $values  slot key => value the executor read
     * @param  list<string>  $mapped  the slots the plan shapes
     * @return array<string, mixed> the Shaper's options
     */
    public function options(array $presentation, array $values, array $mapped): array
    {
        if ($presentation === []) {
            return [];
        }

        $slots = is_array($presentation['slots'] ?? null) ? $presentation['slots'] : [];
        $headline = is_array($slots[KpiCardSlots::SLOT_HEADLINE_VALUE] ?? null) ? $slots[KpiCardSlots::SLOT_HEADLINE_VALUE] : [];
        $comparison = is_array($slots[KpiCardSlots::SLOT_COMPARISON] ?? null) ? $slots[KpiCardSlots::SLOT_COMPARISON] : [];
        $label = is_array($headline['label'] ?? null) ? $headline['label'] : [];
        $options = [];

        if (is_array($headline['format'] ?? null)) {
            $options['headlineFormat'] = $headline['format'];
        }

        if (is_array($comparison['format'] ?? null)) {
            $options['comparisonFormat'] = $comparison['format'];
        }

        if (($comparison['direction'] ?? null) === 'lower_is_better') {
            $options['lowerIsBetter'] = true;
        }

        if (is_array($headline['emphasis'] ?? null)) {
            $options['headlineEmphasis'] = $headline['emphasis'];
        }

        foreach (['prefix', 'suffix'] as $key) {
            if (is_string($label[$key] ?? null)) {
                $options[$key] = $label[$key];
            }
        }

        $band = $this->band($presentation['bands'] ?? null, $values[KpiCardSlots::SLOT_HEADLINE_VALUE] ?? null);

        if ($band !== null) {
            $options['statusBand'] = ['text' => $band['label'], 'color' => $band['color'], 'icon' => $band['icon']];
        }

        if (is_string($presentation['empty_display'] ?? null) && $presentation['empty_display'] !== '') {
            $options['emptyDisplay'] = $presentation['empty_display'];
            $options['emptySlots'] = array_values(array_filter(
                $mapped,
                fn (string $slot): bool => $slot !== KpiCardSlots::SLOT_HEADLINE_VALUE && ($values[$slot] ?? null) === null,
            ));
        }

        return $options;
    }

    /**
     * The first band the value falls in, or null.
     *
     * @return array<string, mixed>|null
     */
    public function band(mixed $bands, mixed $value): ?array
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_array($bands) || ! is_string($value) || DecimalValue::plain($value) === null) {
            return null;
        }

        foreach ($bands as $band) {
            if (is_array($band) && $this->matches($band, $value)) {
                return $band;
            }
        }

        return null;
    }

    /** @param  array<mixed>  $band */
    private function matches(array $band, string $value): bool
    {
        $limit = is_string($band['value'] ?? null) ? $band['value'] : null;

        if ($limit === null) {
            return false;
        }

        return match ($band['op'] ?? null) {
            '>=' => (DecimalValue::compare($value, $limit) ?? -1) >= 0,
            '<=' => (DecimalValue::compare($value, $limit) ?? 1) <= 0,
            'between' => is_string($band['to'] ?? null)
                && (DecimalValue::compare($value, $limit) ?? -1) >= 0
                && (DecimalValue::compare($value, $band['to']) ?? 1) <= 0,
            default => false,
        };
    }
}
