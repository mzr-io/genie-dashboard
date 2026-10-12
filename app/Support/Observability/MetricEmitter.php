<?php

namespace App\Support\Observability;

/** Emits a counter increment or a histogram value. Names come from MetricName; labels are scrubbed before they leave the process. */
interface MetricEmitter
{
    /** @param  array<string, mixed>  $labels */
    public function increment(string $name, array $labels = [], int $by = 1): void;

    /**
     * Records one value in a histogram (a duration in milliseconds, say).
     *
     * @param  array<string, mixed>  $labels
     */
    public function record(string $name, float|int $value, array $labels = []): void;
}
