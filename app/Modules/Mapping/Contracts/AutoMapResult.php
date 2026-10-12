<?php

namespace App\Modules\Mapping\Contracts;

/**
 * What {@see AutoMapping::map()} found: one entry per mappable Slot (grouped for the checklist), the counts, the blockers, the numeric fields kept
 * apart as baseline candidates, and the Query Plan v1 built from every mapped Slot.
 *
 * Slot entry: `{slot, label, group, required, value_type, state, confidence, reason, suggestion, path, display, example}`; `example` is the field's bounded example (Story 3.13c), null when there is none; `confidence` is `high`, `medium`, `low`
 * or null, `suggestion` is null or `{path, display}`. Blocker: `{slot, reason}` with reason `required_missing`, `unconfirmed_low`, `type_mismatch` or `api_changed` (an optional slot whose field left the sample).
 */
final readonly class AutoMapResult
{
    /**
     * @param  list<array{group: string, slots: list<array<string, mixed>>}>  $groups
     * @param  array<string, int>  $counts
     * @param  list<array{slot: string, reason: string}>  $blockers
     * @param  list<array{path: string, display: string}>  $baseline
     * @param  array<string, mixed>  $plan
     * @param  list<array{index: int, type: string, field: string|null, reason: string}>  $transformFaults  a transform step the sample cannot satisfy (Story 5.1): `reason` is `field_missing`, `not_numeric` or `unsortable`; each one blocks Continue
     * @param  list<array<string, mixed>>  $apiChanged  `{slot, path, display, state, match}`: a decided slot whose path left the sample; `state` is `required_missing` or `optional_empty`, `match` null or `{path, display}`, only reported
     */
    public function __construct(
        public array $groups,
        public array $counts,
        public array $blockers,
        public array $baseline,
        public array $plan,
        public array $apiChanged = [],
        public array $transformFaults = [],
    ) {}

    /** @return array<string, mixed>|null the checklist entry of a Slot */
    public function slot(string $key): ?array
    {
        foreach ($this->groups as $group) {
            foreach ($group['slots'] as $slot) {
                if ($slot['slot'] === $key) {
                    return $slot;
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> the checklist as the API shows it (the plan is a separate matter) */
    public function toArray(): array
    {
        return [
            'groups' => $this->groups,
            'counts' => $this->counts,
            'blockers' => $this->blockers,
            'baseline' => $this->baseline,
            'api_changed' => $this->apiChanged,
            'transform_faults' => $this->transformFaults,
        ];
    }
}
