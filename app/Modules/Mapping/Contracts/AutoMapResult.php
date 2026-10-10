<?php

namespace App\Modules\Mapping\Contracts;

/**
 * What {@see AutoMapping::map()} found: one entry per mappable Slot (grouped for the checklist), the counts, the blockers, the numeric fields kept
 * apart as baseline candidates, and the Query Plan v1 built from every mapped Slot.
 *
 * Slot entry: `{slot, label, group, required, value_type, state, confidence, reason, suggestion, path, display}`; `confidence` is `high`, `medium`, `low`
 * or null, `suggestion` is null or `{path, display}`. Blocker: `{slot, reason}` with reason `required_missing`, `unconfirmed_low` or `type_mismatch`.
 */
final readonly class AutoMapResult
{
    /**
     * @param  list<array{group: string, slots: list<array<string, mixed>>}>  $groups
     * @param  array<string, int>  $counts
     * @param  list<array{slot: string, reason: string}>  $blockers
     * @param  list<array{path: string, display: string}>  $baseline
     * @param  array<string, mixed>  $plan
     */
    public function __construct(
        public array $groups,
        public array $counts,
        public array $blockers,
        public array $baseline,
        public array $plan,
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
        ];
    }
}
