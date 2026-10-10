<?php

namespace App\Modules\Blocks\Contracts;

use App\Modules\Mapping\Contracts\AutoMapResult;

/**
 * The auto-map checklist of a Draft (Story 3.12a) with the Draft's revision after the call; `queryPlan` is set when the call wrote one. An override
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
    ) {}
}
