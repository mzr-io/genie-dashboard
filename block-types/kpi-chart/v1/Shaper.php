<?php

namespace BlockTypes\KpiChart\V1;

use BlockTypes\KpiCard\V1\Shaper as KpiCard;
use BlockTypes\LineArea\V1\Shaper as LineArea;

/**
 * The kpi-chart v1 Shaper (Story 5.10): a pure function that gives a headline, its comparison and a chart over the same response. It composes the pinned
 * kpi-card v1 and line-area v1 Shapers (the two contracts it is made of), so its card and its chart are shaped exactly as theirs are. The KPI part is
 * the payload's top level; the chart is `chart`, with its own state (a headline can render over an empty chart, and a chart never draws a zero).
 */
final class Shaper
{
    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $options  the kpi-card options and the line-area ones, `seriesFormat` being the chart's measure format
     * @return array<string, mixed>
     */
    public function shape(array $slots, array $options = []): array
    {
        $payload = (new KpiCard)->shape($slots, $options);

        // The chart is shaped from its own slots and format; the card's state, title and text are not its.
        $series = $options['seriesFormat'] ?? $options['headlineFormat'] ?? null;
        $chart = (new LineArea)->shape($slots, [
            'headlineFormat' => $series,
            'chartKind' => $options['chartKind'] ?? null,
            'directLabels' => $options['directLabels'] ?? true,
            'fieldNames' => $options['fieldNames'] ?? [],
            'xPattern' => $options['xPattern'] ?? null,
            'state' => in_array($options['state'] ?? null, ['loading', 'error'], true) ? $options['state'] : 'normal',
        ]);

        $payload['chart'] = array_intersect_key($chart, array_flip(['state', 'kind', 'x', 'xLabel', 'xPattern', 'series', 'partial', 'highlight', 'target', 'format', 'directLabels']));

        return $payload;
    }
}
