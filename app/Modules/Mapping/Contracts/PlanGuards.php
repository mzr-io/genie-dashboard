<?php

namespace App\Modules\Mapping\Contracts;

/**
 * The limits of one run (`pending_input`, no default): a run that passes one aborts and returns nothing. `maxGroups` (Story 5.2) is the most groups a
 * Group step may make; while its own setting is not set it is the extracted-values limit, which no run can have more groups than.
 */
final readonly class PlanGuards
{
    public int $maxGroups;

    public function __construct(
        public int $maxExtractedValues,
        public int $maxEvaluations,
        public int $maxWallMs,
        ?int $maxGroups = null,
    ) {
        $this->maxGroups = $maxGroups ?? $maxExtractedValues;
    }
}
