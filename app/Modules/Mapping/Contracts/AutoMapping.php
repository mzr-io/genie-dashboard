<?php

namespace App\Modules\Mapping\Contracts;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeSlot;

/** Fills a Block Type's Slots from the inferred field roles with a confidence (Story 3.12a). Pure: no I/O, no model, no float. */
interface AutoMapping
{
    /**
     * @param  array<string, mixed>  $slotMap  `config.slot_map`: slot key => `{path, state}` with state `confirmed` or `overridden`; entries that are not that are ignored
     */
    public function map(FieldRoleReport $report, BlockType $type, array $slotMap): AutoMapResult;

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
