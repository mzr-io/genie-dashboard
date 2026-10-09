<?php

namespace BlockTypes\KpiCard\V1;

/**
 * The kpi-card v1 Shaper: a pure function from already-extracted slot values to the shaped slots. It does no I/O, reads no clock and never
 * decodes JSON (an architecture test enforces it). The real shaping arrives with the Query Plan engine (Story 3.2 onwards).
 */
final class Shaper
{
    /**
     * @param  array<string, mixed>  $slots  slot key => value
     * @return array<string, mixed>
     */
    public function shape(array $slots): array
    {
        return $slots;
    }
}
