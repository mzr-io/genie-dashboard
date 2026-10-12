<?php

namespace App\Modules\Mapping\Contracts;

/** The three limits of one run (`pending_input`, no default): a run that passes one aborts and returns nothing. */
final readonly class PlanGuards
{
    public function __construct(
        public int $maxExtractedValues,
        public int $maxEvaluations,
        public int $maxWallMs,
    ) {}
}
