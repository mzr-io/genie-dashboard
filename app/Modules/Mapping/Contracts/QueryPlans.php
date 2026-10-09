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
     *
     * @throws InvalidPlan the plan is not one this engine runs (an unknown `v` included)
     * @throws GuardExceeded
     */
    public function run(BlockType $type, array $plan, mixed $document, PlanGuards $guards): PlanResult;
}
