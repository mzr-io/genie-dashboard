<?php

namespace App\Modules\Mapping\Application;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeShapers;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\PlanResult;
use App\Modules\Mapping\Contracts\QueryExecutor;
use App\Modules\Mapping\Contracts\QueryPlan;
use App\Modules\Mapping\Contracts\QueryPlans;
use App\Modules\Mapping\Infrastructure\PreviewSettings;

/**
 * The one Query Plan engine (AD-11): it judges the plan (it runs plan `v` 1 forever and refuses any other), has the {@see QueryExecutor} run the
 * stages in their fixed order, and hands the slot values to the Block Type's Shaper, which stays pure. The per-slot reasons are the engine's.
 */
final class QueryPlanEngine implements QueryPlans
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly BlockTypeShapers $shapers,
        private readonly PreviewSettings $settings,
    ) {}

    public function guards(): PlanGuards
    {
        return $this->settings->guards();
    }

    public function run(BlockType $type, array $plan, mixed $document, PlanGuards $guards): PlanResult
    {
        $errors = QueryPlan::validate($plan, $type);

        if ($errors !== []) {
            throw new InvalidPlan($errors);
        }

        $result = $this->executor->execute($plan, $document, $type, $guards);

        return new PlanResult($this->shapers->shape($type, $result->slots), $result->unavailable, $result->slots);
    }
}
