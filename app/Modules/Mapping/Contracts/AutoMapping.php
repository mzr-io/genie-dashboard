<?php

namespace App\Modules\Mapping\Contracts;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeSlot;

/** Fills a Block Type's Slots from the inferred field roles with a confidence (Story 3.12a). Pure: no I/O, no model, no float. */
interface AutoMapping
{
    /**
     * @param  array<string, mixed>  $slotMap  `config.slot_map`: slot key => `{path, state}` with state `confirmed` or `overridden`; entries that are not that are ignored
     * @param  array<string, mixed>  $fieldTypes  `config.field_types` (Story 3.13a): stored path => `text|number|boolean|date`, "Treat as"; the effective type is this, else the inferred one
     * @param  list<array<string, mixed>>  $transforms  `config.transforms` as {@see Transforms::check()} returned them (Story 5.1): carried into the plan, and each judged against the fields of the sample
     */
    public function map(FieldRoleReport $report, BlockType $type, array $slotMap, array $fieldTypes = [], array $transforms = []): AutoMapResult;

    /**
     * The field picker of one Slot (Story 3.13a): every field of the sample with its effective type and role, `compatible` and, when false, a `reason`.
     * Compatible fields first, then document order; a text Slot also gets a `static_text` entry. Null for a Slot the Block Type does not have or that is
     * config-only.
     *
     * @param  array<string, mixed>  $fieldTypes
     * @return list<array<string, mixed>>|null
     */
    public function fieldsFor(FieldRoleReport $report, BlockType $type, string $slot, array $fieldTypes = []): ?array;

    /**
     * What survives a change of Block Type: the entries whose slot key exists in both slot sets with the same value type are kept; every other entry is
     * returned in `unmapped` (`{slot, path, state}`), so nothing is dropped silently.
     *
     * @param  array<string, mixed>  $slotMap
     * @param  list<BlockTypeSlot>  $newSlots  the new type's Slots
     * @param  list<BlockTypeSlot>  $oldSlots  the Slots the map was made for (their value types decide)
     * @return array{kept: array<string, mixed>, unmapped: list<array{slot: string, path: string|null, state: string}>}
     */
    public function carryOver(array $slotMap, array $newSlots, array $oldSlots): array;
}
