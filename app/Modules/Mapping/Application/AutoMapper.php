<?php

namespace App\Modules\Mapping\Application;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeSlot;
use App\Modules\BlockTypes\Contracts\KpiCardSlots;
use App\Modules\Mapping\Application\Expressions\ExpressionParser;
use App\Modules\Mapping\Application\Expressions\ExpressionResolver;
use App\Modules\Mapping\Application\Expressions\TextTemplate;
use App\Modules\Mapping\Contracts\AutoMapping;
use App\Modules\Mapping\Contracts\AutoMapResult;
use App\Modules\Mapping\Contracts\ExecutionResult;
use App\Modules\Mapping\Contracts\FieldRole;
use App\Modules\Mapping\Contracts\FieldRoleReport;
use App\Modules\Mapping\Contracts\InvalidExpression;
use App\Modules\Mapping\Contracts\QueryPlan;
use App\Modules\Mapping\Contracts\SlotState;
use App\Modules\Mapping\Contracts\Templates;
use App\Modules\Mapping\Contracts\Transforms;
use App\Platform\Json\SamplePath;

/**
 * Auto-map (Story 3.12a): fills the Slots of a Block Type from {@see FieldRoleReport}'s fields, grades each with `high`, `medium` or `low`, and keeps
 * the Admin's decisions. Pure; the three words are the only confidence there is.
 *
 * Candidates are fields that select exactly one scalar: outside the record path (`in_records` false) and with no `[*]` in the path. A field inside
 * the records is ineligible (aggregation is Epic 5); if it is the only match, the Slot is `type_mismatch`.
 *
 * Headline value (`headline_value`): numeric `value`-role candidates. One: High. Several: those whose key is, or starts with, `previous`, `prior`,
 * `prev`, `last` or `baseline` are dropped and reported as baseline candidates (never mapped); exactly one left: Medium; otherwise Low, the first in
 * document order. Text Slots: a text candidate whose key has a word of the Slot's list is Medium (`title`: title|name, `subtitle`: subtitle|description,
 * `kpi_label`: label|metric|name, `status_badge`: status, `secondary_note`: note), the first in document order. Every other Slot (`comparison`, the
 * comparison label, and any Slot of another Block Type) has no rule: optional-empty, or required-missing. `icon` and `footer_action` are config-only and
 * are left out of the checklist.
 *
 * Decisions: `slot_map[slot] = {path, state: confirmed|overridden}` wins over the auto result and is never changed here. A decision whose path is
 * no longer a single scalar of the right type in the sample reads `type_mismatch`; a decision with a null path is an Admin-cleared Slot (empty).
 *
 * Groups: HEADER (title, subtitle), KPI (kpi_label, headline_value, comparison, comparison_label), META (everything else, the secondary note and the
 * status badge among them). Within a group the Low rows come first. Blockers: a required Slot with nothing mapped, an unconfirmed Low, a type mismatch.
 */
final class AutoMapper implements AutoMapping
{
    public const HEADER = 'HEADER';

    public const KPI = 'KPI';

    public const META = 'META';

    private const CONFIG_ONLY = [KpiCardSlots::SLOT_ICON, KpiCardSlots::SLOT_FOOTER_ACTION];

    private const GROUPS = [
        self::HEADER => [KpiCardSlots::SLOT_TITLE, KpiCardSlots::SLOT_SUBTITLE],
        self::KPI => [KpiCardSlots::SLOT_KPI_LABEL, KpiCardSlots::SLOT_HEADLINE_VALUE, KpiCardSlots::SLOT_COMPARISON, KpiCardSlots::SLOT_COMPARISON_LABEL],
    ];

    /** @var array<string, list<string>> */
    private const TEXT_WORDS = [
        KpiCardSlots::SLOT_TITLE => ['title', 'name'],
        KpiCardSlots::SLOT_SUBTITLE => ['subtitle', 'description'],
        KpiCardSlots::SLOT_KPI_LABEL => ['label', 'metric', 'name'],
        KpiCardSlots::SLOT_STATUS_BADGE => ['status'],
        KpiCardSlots::SLOT_SECONDARY_NOTE => ['note'],
    ];

    /** The types an Admin can have a field treated as (`config.field_types`). */
    private const TYPES = ['text', 'number', 'boolean', 'date'];

    private const BASELINE_WORDS = ['previous', 'prior', 'prev', 'last', 'baseline'];

    public function map(FieldRoleReport $report, BlockType $type, array $slotMap, array $fieldTypes = [], array $transforms = [], array $templates = []): AutoMapResult
    {
        $fields = $this->enrich($report, $fieldTypes);
        // Calculated fields are fields a slot may be pointed at by hand, never picked by the auto-mapper.
        $own = $fields;
        $fields = [...$fields, ...$this->calcFields($transforms, $fields), ...$this->templateFields($templates)];

        $byPath = array_column($fields, null, 'path');
        $rows = [];
        $decidedPath = null;
        // A field fills one slot: the Admin's decisions own theirs, and an auto match is not offered to a later slot.
        $used = [];
        $apiChanged = [];

        foreach ($type->slots as $slot) {
            $decision = in_array($slot->key, self::CONFIG_ONLY, true) ? null : $this->decision($slotMap[$slot->key] ?? null);

            // A slot with a Text Template is read from it: the template is the Admin's decision.
            if (isset($templates[$slot->key])) {
                $decision = ['path' => Templates::PREFIX.$slot->key, 'state' => 'overridden'];
            }

            if ($decision !== null && $decision['path'] !== null) {
                $used[$decision['path']] = true;

                if ($slot->key === KpiCardSlots::SLOT_HEADLINE_VALUE) {
                    $decidedPath = $decision['path'];
                }
            }
        }

        foreach ($type->slots as $slot) {
            if (in_array($slot->key, self::CONFIG_ONLY, true)) {
                continue;
            }

            $decision = $this->decision($slotMap[$slot->key] ?? null);

            if (isset($templates[$slot->key])) {
                $decision = ['path' => Templates::PREFIX.$slot->key, 'state' => 'overridden'];
            }

            $row = $decision !== null ? $this->decided($slot, $decision, $byPath, $fields, $apiChanged) : $this->auto($slot, $own, $used);
            $rows[] = $row;

            if ($decision === null && $row['path'] !== null) {
                $used[(string) $row['path']] = true;
            }
        }

        $baseline = $this->baseline($type, $own, $decidedPath);

        $groups = [];

        foreach ([self::HEADER, self::KPI, self::META] as $name) {
            $slots = array_values(array_filter($rows, fn (array $row): bool => $row['group'] === $name));

            if ($slots === []) {
                continue;
            }

            usort($slots, fn (array $a, array $b): int => ($b['state'] === SlotState::AutoLow->value) <=> ($a['state'] === SlotState::AutoLow->value));
            $groups[] = ['group' => $name, 'slots' => $slots];
        }

        return new AutoMapResult($groups, $this->counts($rows), $this->blockers($rows, $apiChanged), $baseline, self::withTemplates(Transforms::apply($this->plan($rows), $transforms), $templates), $apiChanged, $this->transformFaults($transforms, $fields), $this->templateFaults($templates, $own));
    }

    /**
     * @param  array<string, mixed>  $fieldTypes
     * @param  list<array<string, mixed>>  $transforms
     */
    public function fieldsFor(FieldRoleReport $report, BlockType $type, string $slot, array $fieldTypes = [], array $transforms = []): ?array
    {
        $definition = null;

        foreach ($type->slots as $candidate) {
            if ($candidate->key === $slot && ! in_array($slot, self::CONFIG_ONLY, true)) {
                $definition = $candidate;
            }
        }

        if ($definition === null) {
            return null;
        }

        $entries = [];

        $enriched = $this->enrich($report, $fieldTypes);

        foreach ([...$enriched, ...$this->calcFields($transforms, $enriched)] as $field) {
            $compatible = $this->takes($definition, $field);
            $entry = [
                'kind' => 'field',
                'path' => (string) $field['path'],
                'display' => (string) $field['display'],
                'type' => (string) $field['type'],
                'effective_type' => (string) $field['effective'],
                'role' => (string) $field['role'],
                'example' => $field['example'],
                'compatible' => $compatible,
            ];

            if (! $compatible) {
                $entry['reason'] = $this->mismatch($definition, $field, false);
            }

            $entries[] = $entry;
        }

        // Compatible first, then the response's document order (the sort is stable).
        usort($entries, fn (array $a, array $b): int => $b['compatible'] <=> $a['compatible']);

        if ($definition->valueType === 'text') {
            $firstIncompatible = count(array_filter($entries, fn (array $e): bool => $e['compatible']));
            array_splice($entries, $firstIncompatible, 0, [
                ['kind' => 'static_text', 'path' => null, 'display' => 'Static text', 'compatible' => true],
                ['kind' => 'template', 'path' => null, 'display' => 'Text Template', 'example' => '{ROW_COUNT} requests need your attention', 'compatible' => true],
            ]);
        }

        // A number slot may be filled by an expression (Story 5.3): the entry sits beside the fields, with an example of what to write.
        if ($definition->valueType === 'number') {
            $entries[] = ['kind' => 'calculated', 'path' => null, 'display' => 'Calculated Field', 'example' => 'PCT_CHANGE(total, previous_total)', 'compatible' => true];
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $fieldTypes
     * @return list<array<string, mixed>>
     */
    private function enrich(FieldRoleReport $report, array $fieldTypes): array
    {
        $fields = [];

        foreach ($report->fields as $field) {
            $forced = $fieldTypes[$field['path']] ?? null;
            $forced = is_string($forced) && in_array($forced, self::TYPES, true) ? $forced : null;
            $inferred = $this->inferredType($field);
            $fields[] = $field + [
                'key' => $this->key((string) $field['path']),
                'single' => $this->isSingle($field),
                'forced' => $forced !== null,
                'inferred' => $inferred,
                'effective' => $forced ?? $inferred,
            ];
        }

        return $fields;
    }

    public function carryOver(array $slotMap, array $newSlots, array $oldSlots): array
    {
        $new = [];
        $old = [];

        foreach ($newSlots as $slot) {
            $new[$slot->key] = $slot->valueType;
        }

        foreach ($oldSlots as $slot) {
            $old[$slot->key] = $slot->valueType;
        }

        $kept = [];
        $unmapped = [];

        foreach ($slotMap as $key => $entry) {
            $key = (string) $key;

            if (isset($new[$key], $old[$key]) && $new[$key] === $old[$key]) {
                $kept[$key] = $entry;

                continue;
            }

            $unmapped[] = [
                'slot' => $key,
                'path' => is_array($entry) && is_string($entry['path'] ?? null) ? $entry['path'] : null,
                'state' => is_array($entry) && is_string($entry['state'] ?? null) ? $entry['state'] : SlotState::Overridden->value,
            ];
        }

        return ['kept' => $kept, 'unmapped' => $unmapped];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, true>  $used  paths already given to a slot
     * @return array<string, mixed>
     */
    private function auto(BlockTypeSlot $slot, array $fields, array $used): array
    {
        if ($slot->valueType === 'number' && $slot->key === KpiCardSlots::SLOT_HEADLINE_VALUE) {
            return $this->headline($slot, $fields);
        }

        if ($slot->cardinality === 'rows') {
            return $this->rows($slot, $fields, $used);
        }

        $words = self::TEXT_WORDS[$slot->key] ?? null;

        if ($words === null || $slot->valueType !== 'text') {
            return $this->nothing($slot, $fields);
        }

        $matches = array_values(array_filter($fields, fn (array $f): bool => $this->fits('text', $f) && $this->hasWord((string) $f['key'], $words)));
        $single = array_values(array_filter($matches, fn (array $f): bool => $f['single'] === true));
        $eligible = array_values(array_filter($single, fn (array $f): bool => ! isset($used[(string) $f['path']])));

        if ($eligible !== []) {
            return $this->row($slot, SlotState::AutoMedium, 'medium', "The key \"{$eligible[0]['key']}\" matches this slot.", $eligible[0]);
        }

        if ($matches !== [] && $single === []) {
            return $this->row($slot, SlotState::TypeMismatch, null, 'Only found inside the rows; one value is needed here.');
        }

        return $this->nothing($slot, $fields);
    }

    /**
     * A `rows` slot (a chart's axis or series, Story 5.8a): the first field of the rows that fits the type and has one of the slot's roles and that no
     * slot has taken, in document order.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, true>  $used
     * @return array<string, mixed>
     */
    private function rows(BlockTypeSlot $slot, array $fields, array $used): array
    {
        $eligible = array_values(array_filter(
            $fields,
            fn (array $f): bool => $this->takes($slot, $f) && in_array((string) $f['role'], $slot->fieldRoles, true) && ! isset($used[(string) $f['path']]),
        ));

        if ($eligible === []) {
            return $this->nothing($slot, $fields);
        }

        return $this->row($slot, SlotState::AutoMedium, 'medium', "The rows' \"{$eligible[0]['key']}\" fits this slot.", $eligible[0]);
    }

    /**
     * Whether the field can fill the slot: a field of one value for a single slot, a field of the rows for a `rows` slot, in both the type fits.
     *
     * @param  array<string, mixed>  $field
     */
    private function takes(BlockTypeSlot $slot, array $field): bool
    {
        return (($field['single'] === true) !== ($slot->cardinality === 'rows')) && $this->fits($slot->valueType, $field);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function headline(BlockTypeSlot $slot, array $fields): array
    {
        $numeric = array_values(array_filter($fields, fn (array $f): bool => $this->fits('number', $f)));
        $eligible = array_values(array_filter($numeric, fn (array $f): bool => $f['single'] === true && $f['role'] === FieldRole::Value->value));
        $count = count($eligible);

        if ($count === 0) {
            if (array_filter($numeric, fn (array $f): bool => $f['single'] === false) !== []) {
                return $this->row($slot, SlotState::TypeMismatch, null, 'Numbers are only found inside the rows; one value is needed here.');
            }

            return $this->nothing($slot, $fields);
        }

        if ($count === 1) {
            return $this->row($slot, SlotState::AutoHigh, 'high', 'The only numeric value in the response.', $eligible[0]);
        }

        $kept = array_values(array_filter($eligible, fn (array $f): bool => ! $this->isBaselineName((string) $f['key'])));

        if ($kept === []) {
            return $this->row($slot, SlotState::AutoLow, 'low', "{$count} values, all named like earlier values; picked the first in the response.", $eligible[0]);
        }

        if (count($kept) === 1) {
            return $this->row($slot, SlotState::AutoMedium, 'medium', "{$count} values; picked the one not named previous_*.", $kept[0]);
        }

        $names = implode(', ', array_map(fn (array $f): string => (string) $f['key'], $kept));

        return $this->row($slot, SlotState::AutoLow, 'low', count($kept)." values could be the headline ({$names}); picked the first in the response.", $kept[0]);
    }

    /**
     * Numeric values named like earlier ones, reported apart and never mapped: only when several values compete and one is not so named. Found whatever
     * the headline's state, so a confirmed or overridden headline does not hide them; the Admin's own headline path is left out.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array{path: string, display: string}>
     */
    private function baseline(BlockType $type, array $fields, ?string $decidedPath): array
    {
        $has = false;

        foreach ($type->slots as $slot) {
            $has = $has || ($slot->valueType === 'number' && $slot->key === KpiCardSlots::SLOT_HEADLINE_VALUE);
        }

        $eligible = array_values(array_filter($fields, fn (array $f): bool => $this->fits('number', $f) && $f['single'] === true && $f['role'] === FieldRole::Value->value));
        $named = array_values(array_filter($eligible, fn (array $f): bool => $this->isBaselineName((string) $f['key'])));

        if (! $has || count($eligible) < 2 || count($named) === count($eligible)) {
            return [];
        }

        $out = [];

        foreach ($named as $field) {
            if ($field['path'] !== $decidedPath) {
                $out[] = ['path' => (string) $field['path'], 'display' => (string) $field['display']];
            }
        }

        return $out;
    }

    /**
     * Nothing matched: optional-empty, or required-missing with the nearest candidate (a single value of the right type that the rules passed over).
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function nothing(BlockTypeSlot $slot, array $fields): array
    {
        if (! $slot->required) {
            return $this->row($slot, SlotState::OptionalEmpty, null, 'No matching field.');
        }

        $suggestion = null;

        foreach ($fields as $field) {
            if ($this->takes($slot, $field)) {
                $suggestion = ['path' => (string) $field['path'], 'display' => (string) $field['display']];

                break;
            }
        }

        return $this->row($slot, SlotState::RequiredMissing, null, 'No matching field was found for this required slot.', null, $suggestion);
    }

    /**
     * @param  array{path: string|null, state: string}  $decision
     * @param  array<string, array<string, mixed>>  $byPath
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array<string, mixed>>  $apiChanged  gains an entry when the decided path is no longer in the sample
     * @return array<string, mixed>
     */
    private function decided(BlockTypeSlot $slot, array $decision, array $byPath, array $fields, array &$apiChanged): array
    {
        $path = $decision['path'];

        if ($path === null) {
            return $this->row($slot, $slot->required ? SlotState::RequiredMissing : SlotState::OptionalEmpty, null, 'Cleared by an admin.');
        }

        $field = $byPath[$path] ?? null;
        $state = SlotState::from($decision['state']);

        // The API changed under the mapping: the slot is empty (never silently remapped); a match is only reported.
        if ($field === null) {
            $match = $this->match($path, $slot, $fields);
            $row = $this->row($slot, $slot->required ? SlotState::RequiredMissing : SlotState::OptionalEmpty, null, 'The mapped field is no longer in the sample.', ['path' => $path, 'display' => $this->display($path)], $match);
            $apiChanged[] = ['slot' => $slot->key, 'path' => $path, 'display' => $this->display($path), 'state' => $row['state'], 'match' => $match];

            return $row;
        }

        if (! $this->takes($slot, $field)) {
            $compatible = array_values(array_filter($byPath, fn (array $f): bool => $this->takes($slot, $f)));

            return $this->row($slot, SlotState::TypeMismatch, null, $this->mismatch($slot, $field, true), $field, $compatible === [] ? null : ['path' => (string) $compatible[0]['path'], 'display' => (string) $compatible[0]['display']]);
        }

        return $this->row($slot, $state, 'high', $state === SlotState::Confirmed ? 'Confirmed by an admin.' : 'Chosen by an admin.', $field);
    }

    /**
     * A field elsewhere that may be the one the mapping lost: the same last key and a type that fits, else the same last key, else a key that
     * contains the lost key's words (or the reverse, `total` and `grand_total`) with a type that fits. Only reported, never applied.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array{path: string, display: string}|null
     */
    private function match(string $path, BlockTypeSlot $slot, array $fields): ?array
    {
        $key = strtolower($this->key($path));
        $words = $this->split($this->key($path));
        $single = array_values(array_filter($fields, fn (array $f): bool => ($f['single'] === true) !== ($slot->cardinality === 'rows')));
        $same = array_values(array_filter($single, fn (array $f): bool => $key !== '' && strtolower((string) $f['key']) === $key));
        $fit = fn (array $f): bool => $this->fits($slot->valueType, $f);
        $found = array_values(array_filter($same, $fit))[0] ?? $same[0] ?? null;

        if ($found === null && $words !== []) {
            foreach (array_filter($single, $fit) as $f) {
                $other = $this->split((string) $f['key']);

                if ($other !== [] && (array_diff($words, $other) === [] || array_diff($other, $words) === [])) {
                    $found = $f;

                    break;
                }
            }
        }

        return $found === null ? null : ['path' => (string) $found['path'], 'display' => (string) $found['display']];
    }

    /**
     * Why a field cannot fill a slot. `$quote` adds the example to the sentence (the checklist only). The picker returns a bounded `example` per field by design; the refusals (and the picker's reasons) carry no sample text.
     *
     * @param  array<string, mixed>  $field
     */
    private function mismatch(BlockTypeSlot $slot, array $field, bool $quote): string
    {
        if ($slot->cardinality === 'rows') {
            if ($field['single'] === true) {
                return 'This slot takes the value of every row; a single value does not fill it.';
            }
        } elseif ($field['single'] !== true) {
            return 'Needs aggregation (a later release).';
        }

        $effective = (string) $field['effective'];

        if (! $quote && $slot->key === KpiCardSlots::SLOT_HEADLINE_VALUE && $slot->valueType === 'number' && $effective === 'text' && $field['forced'] !== true) {
            return "Text field can't be a numeric headline. Use it as the currency format below.";
        }

        $example = $field['example'] ?? null;
        $subject = $quote && $effective === 'text' && is_string($example) ? "{$field['display']} is text (\"{$example}\")" : "{$field['display']} is {$effective}";
        $needs = match ($slot->valueType) {
            'number' => 'a number',
            'boolean' => 'a true or false value',
            default => 'text',
        };

        return "{$subject}. {$slot->label} needs {$needs}.";
    }

    /**
     * @param  array<string, mixed>|null  $field
     * @param  array{path: string, display: string}|null  $suggestion
     * @return array<string, mixed>
     */
    private function row(BlockTypeSlot $slot, SlotState $state, ?string $confidence, string $reason, ?array $field = null, ?array $suggestion = null): array
    {
        return [
            'slot' => $slot->key,
            'label' => $slot->label,
            'group' => $this->group($slot->key),
            'required' => $slot->required,
            'value_type' => $slot->valueType,
            'state' => $state->value,
            'confidence' => $confidence,
            'reason' => $reason,
            'suggestion' => $suggestion,
            'path' => $field === null ? null : (string) $field['path'],
            'display' => $field === null ? null : (string) ($field['display'] ?? $this->display((string) $field['path'])),
            'example' => is_string($field['example'] ?? null) ? $field['example'] : null,
        ];
    }

    private function group(string $slot): string
    {
        foreach (self::GROUPS as $name => $keys) {
            if (in_array($slot, $keys, true)) {
                return $name;
            }
        }

        return self::META;
    }

    /** @return array{path: string|null, state: string}|null */
    private function decision(mixed $entry): ?array
    {
        if (! is_array($entry) || ! array_key_exists('path', $entry) || ($entry['path'] !== null && ! is_string($entry['path']))) {
            return null;
        }

        $state = $entry['state'] ?? null;

        return in_array($state, [SlotState::Confirmed->value, SlotState::Overridden->value], true) ? ['path' => $entry['path'], 'state' => $state] : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function counts(array $rows): array
    {
        $states = array_column($rows, 'state');
        $of = fn (SlotState ...$wanted): int => count(array_filter($states, fn (string $s): bool => in_array(SlotState::from($s), $wanted, true)));

        return [
            'auto_mapped' => $of(SlotState::AutoHigh, SlotState::AutoMedium, SlotState::AutoLow),
            'total' => count($rows),
            'confirmed' => $of(SlotState::AutoHigh, SlotState::Confirmed, SlotState::Overridden),
            'needs_review' => $of(SlotState::AutoMedium, SlotState::AutoLow),
            'required_missing' => $of(SlotState::RequiredMissing),
            'type_mismatch' => $of(SlotState::TypeMismatch),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $apiChanged
     * @return list<array{slot: string, reason: string}>
     */
    private function blockers(array $rows, array $apiChanged): array
    {
        $out = [];

        foreach ($rows as $row) {
            $reason = match ($row['state']) {
                SlotState::RequiredMissing->value => 'required_missing',
                SlotState::AutoLow->value => 'unconfirmed_low',
                SlotState::TypeMismatch->value => 'type_mismatch',
                default => null,
            };

            if ($reason !== null) {
                $out[] = ['slot' => (string) $row['slot'], 'reason' => $reason];
            }
        }

        // An optional slot whose field went away is empty, not missing, but the Admin still has to look at it.
        foreach ($apiChanged as $change) {
            if ($change['state'] === SlotState::OptionalEmpty->value) {
                $out[] = ['slot' => (string) $change['slot'], 'reason' => 'api_changed'];
            }
        }

        return $out;
    }

    public function resolveTemplates(FieldRoleReport $report, BlockType $type, array $sources): array
    {
        $names = $this->names($report);
        $textSlots = [];

        foreach ($type->slots as $slot) {
            if ($slot->valueType === 'text' && ! in_array($slot->key, self::CONFIG_ONLY, true)) {
                $textSlots[$slot->key] = true;
            }
        }

        $out = [];
        $errors = [];

        foreach ($sources as $slot => $source) {
            $slot = (string) $slot;
            $at = "config.templates.{$slot}";

            if (! isset($textSlots[$slot])) {
                $errors[$at] = ['Only a text slot takes a Text Template.'];

                continue;
            }

            if (! is_string($source)) {
                $errors["{$at}.source"] = ['Enter the text of the template.'];

                continue;
            }

            try {
                $parts = TextTemplate::resolve(TextTemplate::parse($source), $names);
            } catch (InvalidExpression $e) {
                $errors["{$at}.source"] = [rtrim($e->getMessage(), '.').' (position '.$e->position.').'];

                continue;
            }

            $out[$slot] = ['source' => $source, 'parts' => $parts];
        }

        return [$out, $errors];
    }

    /**
     * The lower-case names a field can be written by (its last name and its displayed name) to its stored path; a name two fields share is ambiguous
     * and maps to an empty string.
     *
     * @return array<string, string>
     */
    private function names(FieldRoleReport $report): array
    {
        $names = [];

        foreach ($report->fields as $field) {
            $path = (string) $field['path'];

            foreach ([$this->key($path), $this->display($path)] as $name) {
                $key = mb_strtolower($name);

                if ($key === '') {
                    continue;
                }

                $names[$key] = isset($names[$key]) && $names[$key] !== $path ? '' : $path;
            }
        }

        return $names;
    }

    /**
     * Text Templates as fields a slot is read from: text, single, never picked by the auto-mapper.
     *
     * @param  array<string, array<string, mixed>>  $templates
     * @return list<array<string, mixed>>
     */
    private function templateFields(array $templates): array
    {
        $out = [];

        foreach (array_keys($templates) as $slot) {
            $out[] = [
                'path' => Templates::PREFIX.$slot,
                'display' => 'Text Template',
                'type' => 'string',
                'example' => null,
                'role' => 'label',
                'confidence' => 'high',
                'overridden' => false,
                'in_records' => false,
                'suggestion' => null,
                'key' => (string) $slot,
                'single' => true,
                'forced' => false,
                'inferred' => 'text',
                'effective' => 'text',
            ];
        }

        return $out;
    }

    /**
     * The templates that read a field the sample does not have.
     *
     * @param  array<string, array<string, mixed>>  $templates
     * @param  list<array<string, mixed>>  $fields  the sample's enriched fields
     * @return list<array{slot: string, field: string, reason: string}>
     */
    private function templateFaults(array $templates, array $fields): array
    {
        $known = array_flip(array_map(fn (array $f): string => (string) $f['path'], $fields));
        $out = [];

        foreach ($templates as $slot => $template) {
            /** @var list<array<string, mixed>> $parts */
            $parts = array_values((array) ($template['parts'] ?? []));

            foreach (TextTemplate::paths($parts) as $path) {
                if (! isset($known[$path])) {
                    $out[] = ['slot' => (string) $slot, 'field' => $path, 'reason' => 'field_missing'];

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, array<string, mixed>>  $templates
     * @return array<string, mixed>
     */
    private static function withTemplates(array $plan, array $templates): array
    {
        if ($templates === []) {
            return $plan;
        }

        $plan[Templates::KEY] = $templates;

        return $plan;
    }

    public function resolveTransforms(FieldRoleReport $report, array $steps): array
    {
        /** @var array<string, string> $names */
        $names = [];

        foreach ($report->fields as $field) {
            $path = (string) $field['path'];

            foreach ([$this->key($path), $this->display($path)] as $name) {
                $key = mb_strtolower($name);

                if ($key === '') {
                    continue;
                }

                $names[$key] = isset($names[$key]) && $names[$key] !== $path ? '' : $path;
            }
        }

        $paths = array_flip(array_map(fn (array $f): string => (string) $f['path'], $report->fields));
        $errors = [];
        $out = [];
        $seen = [];

        foreach ($steps as $index => $step) {
            if ($step['type'] !== Transforms::CALC) {
                $out[] = $step;

                continue;
            }

            $at = "config.transforms.{$index}";
            $name = (string) $step['name'];

            if (isset($seen[mb_strtolower($name)])) {
                $errors["{$at}.name"] = ['Name each calculated field once.'];
            }

            $seen[mb_strtolower($name)] = true;

            if (isset($step['ratio'])) {
                foreach (['numerator', 'denominator'] as $part) {
                    $given = (string) $step['ratio'][$part];
                    $path = isset($paths[$given]) ? $given : ($names[mb_strtolower($given)] ?? null);

                    if ($path === null || $path === '') {
                        $errors["{$at}.ratio"] = ["{$given} isn’t a field of the response."];
                    } else {
                        $step['ratio'][$part] = $path;
                    }
                }
            } else {
                try {
                    $step['ast'] = ExpressionResolver::resolve(ExpressionParser::parse((string) $step['source']), $names);
                } catch (InvalidExpression $e) {
                    $errors["{$at}.source"] = [rtrim($e->getMessage(), '.').' (position '.$e->position.').'];
                }
            }

            $names[mb_strtolower($name)] = Transforms::CALC_PREFIX.$name;
            $out[] = $step;
        }

        return [$out, $errors];
    }

    /**
     * The calculated fields as fields a slot can be pointed at: read by `calc:<name>`, numbers, and single when they come out as one value (aggregated
     * with no group, or made only of single fields).
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  list<array<string, mixed>>  $fields  the enriched fields of the sample
     * @return list<array<string, mixed>>
     */
    private function calcFields(array $steps, array $fields): array
    {
        $grouped = false;

        foreach ($steps as $step) {
            $grouped = $grouped || $step['type'] === Transforms::GROUP;
        }

        $single = [];

        foreach ($fields as $field) {
            $single[(string) $field['path']] = $field['single'] === true;
        }

        $out = [];

        foreach ($steps as $step) {
            if ($step['type'] !== Transforms::CALC) {
                continue;
            }

            $reads = isset($step['ratio'])
                ? [(string) $step['ratio']['numerator'], (string) $step['ratio']['denominator']]
                : (is_array($step['ast'] ?? null) ? ExpressionResolver::paths($step['ast']) : []);
            $path = Transforms::CALC_PREFIX.$step['name'];
            $isSingle = $step['level'] === 'aggregate' && ! $grouped;

            if (! $isSingle && $reads !== []) {
                $isSingle = true;

                foreach ($reads as $read) {
                    $isSingle = $isSingle && ($single[$read] ?? false);
                }
            }

            $single[$path] = $isSingle;
            $out[] = [
                'path' => $path,
                'display' => $step['name'].' (calculated)',
                'type' => 'decimal',
                'example' => null,
                'role' => 'measure',
                'confidence' => 'high',
                'overridden' => false,
                'in_records' => false,
                'suggestion' => null,
                'key' => (string) $step['name'],
                'single' => $isSingle,
                'forced' => false,
                'inferred' => 'number',
                'effective' => 'number',
            ];
        }

        return $out;
    }

    /**
     * The transform steps the sample cannot satisfy (Story 5.1): a field that is not in the response, a numeric comparison on a field that is not a number
     * (or with a value that is not one) and a Sort on a field that cannot be ordered (a true or false field).
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  list<array<string, mixed>>  $fields  the enriched fields of the sample
     * @return list<array{index: int, type: string, field: string|null, reason: string, function: string|null, field_type: string|null}>
     */
    private function transformFaults(array $steps, array $fields): array
    {
        $byPath = array_column($fields, null, 'path');
        $out = [];

        foreach ($steps as $index => $step) {
            $type = (string) $step['type'];

            if ($type === Transforms::TOP_N) {
                continue;
            }

            $fault = null;

            if ($type === Transforms::CALC) {
                $reads = isset($step['ratio'])
                    ? [(string) $step['ratio']['numerator'], (string) $step['ratio']['denominator']]
                    : (is_array($step['ast'] ?? null) ? ExpressionResolver::paths($step['ast']) : []);

                foreach ($reads as $path) {
                    if (str_starts_with($path, Transforms::CALC_PREFIX)) {
                        continue;
                    }

                    $read = $byPath[$path] ?? null;

                    if ($read === null) {
                        $fault = ['field' => $path, 'reason' => 'field_missing'];

                        break;
                    }

                    if (isset($step['ratio']) && $read['effective'] !== 'number') {
                        $fault = ['field' => $path, 'reason' => 'not_numeric'];

                        break;
                    }
                }
            } elseif ($type === Transforms::GROUP) {
                foreach ($step['fields'] as $path) {
                    if (! isset($byPath[(string) $path])) {
                        $fault = ['field' => (string) $path, 'reason' => 'field_missing'];

                        break;
                    }
                }
            } else {
                $path = (string) $step['field'];
                $field = $byPath[$path] ?? null;
                $effective = $field === null ? null : (string) $field['effective'];

                if ($field === null) {
                    $fault = ['field' => $path, 'reason' => 'field_missing'];
                } elseif ($type === Transforms::FILTER && in_array($step['operator'], Transforms::NUMERIC_OPERATORS, true)) {
                    $numericValue = preg_match(ExecutionResult::DECIMAL, trim((string) $step['value'])) === 1;

                    $fault = $effective !== 'number' || ! $numericValue ? ['field' => $path, 'reason' => 'not_numeric'] : null;
                } elseif ($type === Transforms::SORT && $effective === 'boolean') {
                    $fault = ['field' => $path, 'reason' => 'unsortable'];
                } elseif ($type === Transforms::AGGREGATE) {
                    $needsNumber = in_array($step['function'], Transforms::NUMERIC_FUNCTIONS, true);

                    if (($needsNumber && $effective !== 'number') || ($step['function'] !== 'count' && $effective === 'boolean')) {
                        $fault = ['field' => $path, 'reason' => 'not_numeric', 'function' => (string) $step['function'], 'field_type' => $effective];
                    }
                }
            }

            if ($fault !== null) {
                $out[] = $fault + ['index' => $index, 'type' => $type, 'function' => null, 'field_type' => null];
            }
        }

        return $out;
    }

    /**
     * Query Plan v1 from every mapped Slot: one extract alias per Slot (the slot key) and the shape that fills the Slot from it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function plan(array $rows): array
    {
        $fields = [];
        $shape = [];

        foreach ($rows as $row) {
            if ($row['path'] !== null && SlotState::from($row['state'])->isMapped()) {
                $fields[$row['slot']] = $row['path'];
                $shape[$row['slot']] = $row['slot'];
            }
        }

        return ['v' => QueryPlan::VERSION, 'extract' => ['fields' => $fields], 'shape' => $shape];
    }

    /**
     * Whether the field's effective type (the Admin's "Treat as", else the inferred one) fits the slot. A decimal-shaped string treated as a number
     * fits a number slot; any other forced mismatch does not.
     *
     * @param  array<string, mixed>  $field
     */
    private function fits(string $valueType, array $field): bool
    {
        $effective = (string) ($field['effective'] ?? $this->inferredType($field));

        return match ($valueType) {
            'number' => $effective === 'number' && ($this->present($field) !== [] && array_diff($this->present($field), ['integer', 'decimal']) === [] || $this->decimalShaped($field)),
            'boolean' => $effective === 'boolean' && $this->present($field) === ['boolean'],
            default => $effective === 'text' || $effective === 'date',
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private function present(array $field): array
    {
        return array_values(array_diff(array_map('trim', explode('|', (string) $field['type'])), ['null']));
    }

    /** @param  array<string, mixed>  $field */
    private function inferredType(array $field): string
    {
        $present = $this->present($field);

        if ($present !== [] && array_diff($present, ['integer', 'decimal']) === []) {
            return 'number';
        }

        if ($present === ['boolean']) {
            return 'boolean';
        }

        $example = $field['example'] ?? null;

        return is_string($example) && preg_match(FieldRoleInference::ISO, $example) === 1 ? 'date' : 'text';
    }

    /** @param  array<string, mixed>  $field */
    private function decimalShaped(array $field): bool
    {
        $example = $field['example'] ?? null;

        return $this->present($field) === ['string'] && is_string($example) && preg_match(ExecutionResult::DECIMAL, trim($example)) === 1;
    }

    /** @param  array<string, mixed>  $field */
    private function isSingle(array $field): bool
    {
        return $field['in_records'] !== true && ! in_array(null, $this->segments((string) $field['path']), true);
    }

    private function isBaselineName(string $key): bool
    {
        $words = $this->split($key);

        return $words !== [] && in_array($words[0], self::BASELINE_WORDS, true);
    }

    /** @param  list<string>  $wanted */
    private function hasWord(string $key, array $wanted): bool
    {
        foreach ($this->split($key) as $word) {
            if (in_array($word, $wanted, true) || (str_ends_with($word, 's') && in_array(substr($word, 0, -1), $wanted, true))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function split(string $key): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $key) ?? $key;
        $words = preg_split('/[^A-Za-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    /** The last member name of a path. */
    private function key(string $path): string
    {
        $names = array_values(array_filter($this->segments($path), 'is_string'));

        return $names === [] ? '' : $names[count($names) - 1];
    }

    private function display(string $path): string
    {
        return SamplePath::display($this->segments($path));
    }

    /** @return list<string|null> */
    private function segments(string $path): array
    {
        try {
            return SamplePath::parse($path);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }
}
