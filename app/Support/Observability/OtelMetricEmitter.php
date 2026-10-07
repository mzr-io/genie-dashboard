<?php

namespace App\Support\Observability;

use OpenTelemetry\API\Globals;
use Throwable;

/** Counters through the global OpenTelemetry meter provider (a no-op when none is configured). Never throws. */
final class OtelMetricEmitter implements MetricEmitter
{
    public function increment(string $name, array $labels = [], int $by = 1): void
    {
        try {
            /** @var array<non-empty-string, bool|float|int|string> $attributes */
            $attributes = MetricName::labels($labels);

            Globals::meterProvider()
                ->getMeter('dashflow')
                ->createCounter(MetricName::assert($name))
                ->add($by, $attributes);
        } catch (Throwable) {
            // A metric must never break the work it observes.
        }
    }
}
