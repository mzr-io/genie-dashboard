<?php

namespace App\Modules\Blocks\Contracts;

use App\Modules\Mapping\Contracts\AutoMapResult;

/**
 * The auto-map checklist of a Draft (Story 3.12a) as a mapping write leaves it, with the Draft's revision after the call, the plan the Draft now stores
 * (null when nothing is mapped: a write removes the plan then; Story 3.13c) and the revision of the sample the plan was built from. An override
 * (Story 3.13a) also carries `roleChange` (`{path, from, to}`, when it changed a field's role) and `undo`, the payload `DraftSlots::undo()` takes.
 */
final readonly class DraftChecklist
{
    /**
     * @param  array<string, mixed>|null  $queryPlan
     * @param  array{path: string, from: string, to: string}|null  $roleChange
     * @param  array<string, mixed>|null  $undo
     */
    public function __construct(
        public AutoMapResult $result,
        public int $revision,
        public ?array $queryPlan = null,
        public ?array $roleChange = null,
        public ?array $undo = null,
        public ?int $sampleRevision = null,
    ) {}
}
