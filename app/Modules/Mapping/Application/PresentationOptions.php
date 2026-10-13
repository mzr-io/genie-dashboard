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
     * @param  array<string, string|bool|list<string|bool|null>|null>  $values  slot key => value the executor read
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
        } else {
            // A chart's series share the first series' format (Stories 5.8c and 5.11): the first of its value slots that has one.
            foreach (['series_1', 'values_1', 'pie_value'] as $first) {
                if (is_array($slots[$first]['format'] ?? null)) {
                    $options['headlineFormat'] = $slots[$first]['format'];

                    break;
                }
            }
        }

        // A Block with a headline and a chart (Story 5.10) keeps the two formats apart; the axis writes its dates by the X slot's pattern.
        if (is_array($slots['series_1']['format'] ?? null)) {
            $options['seriesFormat'] = $slots['series_1']['format'];
        }

        if (is_string($slots['x']['format']['datePattern'] ?? null)) {
            $options['xPattern'] = $slots['x']['format']['datePattern'];
        }

        if (in_array($presentation['chart_kind'] ?? null, ['line', 'area'], true)) {
            $options['chartKind'] = $presentation['chart_kind'];
        }

        if (is_bool($presentation['direct_labels'] ?? null)) {
            $options['directLabels'] = $presentation['direct_labels'];
        }

        // Bar and pie settings (Story 5.11); the Shaper of the type reads what it knows.
        foreach (['orientation' => 'orientation', 'sort' => 'sort', 'centre' => 'centre', 'centre_label' => 'centreLabel'] as $key => $option) {
            if (is_string($presentation[$key] ?? null)) {
                $options[$option] = $presentation[$key];
            }
        }

        if (is_bool($presentation['value_labels'] ?? null)) {
            $options['valueLabels'] = $presentation['value_labels'];
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

        $band = $this->firstBand($presentation['bands'] ?? null, $values[KpiCardSlots::SLOT_HEADLINE_VALUE] ?? null, $values[KpiCardSlots::SLOT_STATUS_BADGE] ?? null);

        if ($band !== null) {
            $options['statusBand'] = ['text' => $band['label'], 'color' => $band['color'], 'icon' => $band['icon']];
        }

        // A table (Story 5.12): the rules of each column, the page size and, per row, the band the highlight column's value falls in.
        $columns = [];

        foreach ($slots as $key => $rules) {
            if (str_starts_with((string) $key, 'col_') && is_array($rules)) {
                $columns[(string) $key] = array_intersect_key($rules, array_flip(['format', 'label', 'align', 'width', 'sortable']));
            }
        }

        if ($columns !== []) {
            $options['columnRules'] = $columns;
        }

        if (is_int($presentation['page_size'] ?? null)) {
            $options['pageSize'] = $presentation['page_size'];
        }

        $highlight = $presentation['highlight_column'] ?? null;

        if (is_string($highlight) && is_array($values[$highlight] ?? null) && is_array($presentation['bands'] ?? null)) {
            $options['rowBands'] = array_map(function (mixed $value) use ($presentation): ?array {
                $band = $this->firstBand($presentation['bands'], $value, $value);

                return $band === null ? null : ['text' => $band['label'], 'color' => $band['color'], 'icon' => $band['icon']];
            }, $values[$highlight]);
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
     * The first band that matches, top to bottom (Story 5.7): a numeric band is judged on the headline value, an `equals` band on the text of the status
     * badge slot (ignoring case and surrounding space). A subject with no value matches none of its bands, so the value renders neutral, never an error.
     *
     * @return array<string, mixed>|null
     */
    public function firstBand(mixed $bands, mixed $headline, mixed $statusText): ?array
    {
        if (! is_array($bands)) {
            return null;
        }

        $headline = is_int($headline) ? (string) $headline : $headline;
        $text = is_string($statusText) ? mb_strtolower(trim($statusText)) : null;

        foreach ($bands as $band) {
            if (! is_array($band)) {
                continue;
            }

            if (($band['op'] ?? null) === 'equals') {
                if ($text !== null && $text !== '' && is_string($band['value'] ?? null) && mb_strtolower(trim($band['value'])) === $text) {
                    return $band;
                }

                continue;
            }

            if (is_string($headline) && DecimalValue::plain($headline) !== null && $this->matches($band, $headline)) {
                return $band;
            }
        }

        return null;
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
