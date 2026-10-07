<?php

namespace App\Support\Observability;

/** Emits a counter increment. Names come from MetricName; labels are scrubbed before they leave the process. */
interface MetricEmitter
{
    /** @param  array<string, mixed>  $labels */
    public function increment(string $name, array $labels = [], int $by = 1): void;
}
