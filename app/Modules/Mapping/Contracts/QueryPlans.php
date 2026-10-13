<?php

namespace App\Modules\Mapping\Contracts;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Platform\Json\LosslessJson;

/** The Query Plan engine as other modules see it: one engine for the wizard's preview now, and validation and runtime later (AD-11). */
interface QueryPlans
{
    /** The limits of a run, from the `pending_input` settings. @throws SettingNotConfigured the first one that is unset */
    public function guards(): PlanGuards;

    /**
     * Runs the plan over a decoded sample and shapes the result with the Block Type's Shaper.
     *
     * @param  array<string, mixed>  $plan
     * @param  mixed  $document  the tree {@see LosslessJson} made
     * @param  array<string, mixed>  $presentation  the Draft's `config.presentation` (Story 3.15a), already validated when it was stored; none by default
     * @param  array{start: string, end: string}|null  $period  the Block's period as Workspace-local dates, both inclusive (Story 5.4); the `{period.*}` tokens of a Text Template read it
     * @param  mixed  $comparisonDocument  the same Endpoint's response for the prior window (Story 5.6), the tree {@see LosslessJson} made; null when there is none, which a prior-period comparison reads as unavailable. Never made up
     *
     * @throws InvalidPlan the plan is not one this engine runs (an unknown `v` included)
     * @throws GuardExceeded
     */
    public function run(BlockType $type, array $plan, mixed $document, PlanGuards $guards, array $presentation = [], ?array $period = null, mixed $comparisonDocument = null): PlanResult;
}
