<?php

namespace App\Modules\Mapping\Application;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeShapers;
use App\Modules\BlockTypes\Contracts\KpiCardSlots;
use App\Modules\Mapping\Contracts\ExecutionResult;
use App\Modules\Mapping\Contracts\GuardExceeded;
use App\Modules\Mapping\Contracts\InvalidPlan;
use App\Modules\Mapping\Contracts\PlanGuards;
use App\Modules\Mapping\Contracts\PlanResult;
use App\Modules\Mapping\Contracts\Presentation;
use App\Modules\Mapping\Contracts\QueryExecutor;
use App\Modules\Mapping\Contracts\QueryPlan;
use App\Modules\Mapping\Contracts\QueryPlans;
use App\Modules\Mapping\Contracts\SettingNotConfigured;
use App\Modules\Mapping\Infrastructure\PreviewSettings;
use App\Platform\Json\SamplePath;

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

    public function run(BlockType $type, array $plan, mixed $document, PlanGuards $guards, array $presentation = [], ?array $period = null, mixed $comparisonDocument = null): PlanResult
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
        $rules = is_array($presentation['slots'][KpiCardSlots::SLOT_COMPARISON] ?? null) ? $presentation['slots'][KpiCardSlots::SLOT_COMPARISON] : [];
        $prior = ($rules['source'] ?? null) === 'prior_period';
        $mode = $rules['mode'] ?? ($prior ? 'pct_change' : null);
        $compared = is_string($mode) && in_array($mode, Presentation::MODES, true) && ($prior || in_array(KpiCardSlots::SLOT_COMPARISON, $mapped, true));

        // A prior-period comparison (Story 5.6): the previous value is the headline of the same plan run on the response for the prior window. With no
        // such response (or one the guards stop) the comparison is unavailable: nothing is made up and the headline still renders.
        if ($prior) {
            $slots[KpiCardSlots::SLOT_COMPARISON] = null;
            $mapped[] = KpiCardSlots::SLOT_COMPARISON;

            if ($comparisonDocument !== null) {
                try {
                    $slots[KpiCardSlots::SLOT_COMPARISON] = $this->executor->execute($plan, $comparisonDocument, $type, $guards, $period)->slots[KpiCardSlots::SLOT_HEADLINE_VALUE] ?? null;
                } catch (GuardExceeded) {
                    $slots[KpiCardSlots::SLOT_COMPARISON] = null;
                }
            }
        }

        // The comparison slot's mapped field is the previous value (Story 5.5): its slot value becomes the change from the headline, or nothing.
        if ($compared) {
            if ($this->math === null) {
                throw new SettingNotConfigured('DASHFLOW_DECIMAL_SCALE');
            }

            $change = (new ComparisonChange($this->math))->change($mode, $slots[KpiCardSlots::SLOT_HEADLINE_VALUE] ?? null, $slots[KpiCardSlots::SLOT_COMPARISON] ?? null);
            $slots[KpiCardSlots::SLOT_COMPARISON] = $change;

            if ($change === null) {
                $unavailable[KpiCardSlots::SLOT_COMPARISON] ??= ExecutionResult::MISSING;
            } elseif ($prior) {
                // The executor reports a slot the plan does not shape as missing; the prior window gave it its value.
                unset($unavailable[KpiCardSlots::SLOT_COMPARISON]);
            }
        }

        $options = $this->presentation->options($presentation, $slots, $mapped);

        // The name of each mapped field (the last member of its stored path): a series without a label of its own is named by it.
        $fields = is_array($plan['extract']['fields'] ?? null) ? $plan['extract']['fields'] : [];

        foreach (is_array($plan['shape'] ?? null) ? $plan['shape'] : [] as $slot => $alias) {
            $name = is_string($fields[$alias] ?? null) ? self::fieldName($fields[$alias]) : null;

            if ($name !== null && str_starts_with((string) $slot, 'series_')) {
                $options['seriesFields'][(string) $slot] = $name;
            }
        }

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

    /** The last member name of a stored path, or null for one that ends in a wildcard or is not a path. */
    private static function fieldName(string $path): ?string
    {
        try {
            $segments = SamplePath::parse($path);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $last = end($segments);

        return is_string($last) && $last !== '' ? $last : null;
    }
}
