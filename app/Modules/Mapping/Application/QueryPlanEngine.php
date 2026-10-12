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
 * stages in their fixed order, and hands the slot values to the Block Type's Shaper, which stays pure. The Draft's presentation rules (Story 3.15a) reach the Shaper as
 * options, with the threshold band matched here. The per-slot reasons are the engine's.
 */
final class QueryPlanEngine implements QueryPlans
{
    public function __construct(
        private readonly QueryExecutor $executor,
        private readonly BlockTypeShapers $shapers,
        private readonly PreviewSettings $settings,
        private readonly PresentationOptions $presentation = new PresentationOptions,
    ) {}

    public function guards(): PlanGuards
    {
        return $this->settings->guards();
    }

    public function run(BlockType $type, array $plan, mixed $document, PlanGuards $guards, array $presentation = []): PlanResult
    {
        $errors = QueryPlan::validate($plan, $type);

        if ($errors !== []) {
            throw new InvalidPlan($errors);
        }

        $result = $this->executor->execute($plan, $document, $type, $guards);

        // The presentation rules (Story 3.15a) become the Shaper's options; the bands are judged here, on the exact headline value.
        $mapped = array_map('strval', array_keys(is_array($plan['shape'] ?? null) ? $plan['shape'] : []));
        $options = $this->presentation->options($presentation, $result->slots, $mapped);

        return new PlanResult($this->shapers->shape($type, $result->slots, $options), $result->unavailable, $result->slots, $result->rows, $result->columns);
    }
}
