<?php

namespace App\Modules\Blocks\Contracts;

use App\Modules\Mapping\Contracts\AutoMapResult;

/** The auto-map checklist of a Draft (Story 3.12a) with the Draft's revision after the call; `queryPlan` is set when the call wrote one. */
final readonly class DraftChecklist
{
    /** @param  array<string, mixed>|null  $queryPlan */
    public function __construct(
        public AutoMapResult $result,
        public int $revision,
        public ?array $queryPlan = null,
    ) {}
}
