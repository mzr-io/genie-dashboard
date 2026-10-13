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
     * @param  array<string, array<string, mixed>>  $templates  `config.templates` (Story 5.4): slot key => `{source, parts}`; a slot with one is read from it, and the plan carries it
     */
    public function map(FieldRoleReport $report, BlockType $type, array $slotMap, array $fieldTypes = [], array $transforms = [], array $templates = []): AutoMapResult;

    /**
     * Text Templates judged against the fields of the sample (Story 5.4): each source is parsed by the one template grammar and its field names are
     * resolved to stored paths. Only a text slot of the Block Type takes one.
     *
     * @param  array<string, mixed>  $sources  slot key => source text
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, list<string>>} slot key => `{source, parts}`, and the faults keyed `config.templates.{slot}.{part}`
     */
    public function resolveTemplates(FieldRoleReport $report, BlockType $type, array $sources): array;

    /**
     * The calculated fields of a Draft's transform steps judged against the fields of the sample (Story 5.3): each expression is parsed by the one grammar
     * (AD-12), its field names are resolved to stored paths and the tree is kept beside the source text. A ratio's numerator and denominator become paths.
     *
     * @param  list<array<string, mixed>>  $steps  as {@see Transforms::check()} returned them (stage order)
     * @return array{0: list<array<string, mixed>>, 1: array<string, list<string>>} the steps with `ast` and the faults keyed `config.transforms.{n}.{part}`
     */
    public function resolveTransforms(FieldRoleReport $report, array $steps): array;

    /**
     * The field picker of one Slot (Story 3.13a): every field of the sample with its effective type and role, `compatible` and, when false, a `reason`.
     * Compatible fields first, then document order; a text Slot also gets a `static_text` entry. Null for a Slot the Block Type does not have or that is
     * config-only.
     *
     * @param  array<string, mixed>  $fieldTypes
     * @param  list<array<string, mixed>>  $transforms  the Draft's steps: its calculated fields are offered, and a number Slot gets a `calculated` entry (Story 5.3)
     * @return list<array<string, mixed>>|null
     */
    public function fieldsFor(FieldRoleReport $report, BlockType $type, string $slot, array $fieldTypes = [], array $transforms = []): ?array;

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
