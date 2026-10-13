<?php

namespace App\Modules\Mapping\Application;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeShapers;
use App\Modules\BlockTypes\Contracts\KpiCardSlots;
use App\Modules\Mapping\Contracts\ExecutionResult;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\PlanResult;
use App\Modules\Mapping\Contracts\Presentation;
use App\Modules\Mapping\Contracts\QueryExecutor;
use App\Modules\Mapping\Contracts\QueryPlan;
use App\Modules\Mapping\Contracts\QueryPlans;
use App\Modules\Mapping\Contracts\SettingNotConfigured;
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
        private readonly ?DecimalMath $math = null,
    ) {}

    public function guards(): PlanGuards
    {
        return $this->settings->guards();
    }

    public function run(BlockType $type, array $plan, mixed $document, PlanGuards $guards, array $presentation = [], ?array $period = null): PlanResult
    {
        $errors = QueryPlan::validate($plan, $type);

        if ($errors !== []) {
            throw new InvalidPlan($errors);
        }

        $result = $this->executor->execute($plan, $document, $type, $guards, $period);

        // The presentation rules (Story 3.15a) become the Shaper's options; the bands are judged here, on the exact headline value.
        $mapped = array_map('strval', array_keys(is_array($plan['shape'] ?? null) ? $plan['shape'] : []));
        $slots = $result->slots;
        $unavailable = $result->unavailable;
        $mode = $presentation['slots'][KpiCardSlots::SLOT_COMPARISON]['mode'] ?? null;
        $compared = is_string($mode) && in_array($mode, Presentation::MODES, true) && in_array(KpiCardSlots::SLOT_COMPARISON, $mapped, true);

        // The comparison slot's mapped field is the previous value (Story 5.5): its slot value becomes the change from the headline, or nothing.
        if ($compared) {
            if ($this->math === null) {
                throw new SettingNotConfigured('DASHFLOW_DECIMAL_SCALE');
            }

            $change = (new ComparisonChange($this->math))->change($mode, $slots[KpiCardSlots::SLOT_HEADLINE_VALUE] ?? null, $slots[KpiCardSlots::SLOT_COMPARISON] ?? null);
            $slots[KpiCardSlots::SLOT_COMPARISON] = $change;

            if ($change === null) {
                $unavailable[KpiCardSlots::SLOT_COMPARISON] ??= ExecutionResult::MISSING;
            }
        }

        $options = $this->presentation->options($presentation, $slots, $mapped);

        if ($compared) {
            $slotRules = $presentation['slots'][KpiCardSlots::SLOT_COMPARISON] ?? [];
            $headlineFormat = $presentation['slots'][KpiCardSlots::SLOT_HEADLINE_VALUE]['format'] ?? null;

            if (! is_array($slotRules['format'] ?? null)) {
                $options['comparisonFormat'] = match ($mode) {
                    'pct_change' => ['kind' => 'percent', 'decimalPlaces' => 1],
                    'pp_delta' => ['kind' => 'number', 'decimalPlaces' => 1, 'unitsSuffix' => 'pp'],
                    default => is_array($headlineFormat) ? $headlineFormat : [],
                };
            }

            // The sign of an absolute change or a point change is part of its text; a percentage leans on the arrow.
            $options['comparisonSigned'] = $mode !== 'pct_change';
        }

        return new PlanResult($this->shapers->shape($type, $slots, $options), $unavailable, $slots, $result->rows, $result->columns);
    }
}
