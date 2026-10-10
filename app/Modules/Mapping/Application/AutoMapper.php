<?php

namespace App\Modules\Mapping\Application;

use App\Modules\BlockTypes\Contracts\BlockType;
use App\Modules\BlockTypes\Contracts\BlockTypeSlot;
use App\Modules\BlockTypes\Contracts\KpiCardSlots;
use App\Modules\Mapping\Contracts\AutoMapping;
use App\Modules\Mapping\Contracts\AutoMapResult;
use App\Modules\Mapping\Contracts\ExecutionResult;
use App\Modules\Mapping\Contracts\FieldRole;
use App\Modules\Mapping\Contracts\FieldRoleReport;
use App\Modules\Mapping\Contracts\QueryPlan;
use App\Modules\Mapping\Contracts\SlotState;
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

    public function map(FieldRoleReport $report, BlockType $type, array $slotMap, array $fieldTypes = []): AutoMapResult
    {
        $fields = $this->enrich($report, $fieldTypes);

        $byPath = array_column($fields, null, 'path');
        $rows = [];
        $decidedPath = null;
        // A field fills one slot: the Admin's decisions own theirs, and an auto match is not offered to a later slot.
        $used = [];
        $apiChanged = [];

        foreach ($type->slots as $slot) {
            $decision = in_array($slot->key, self::CONFIG_ONLY, true) ? null : $this->decision($slotMap[$slot->key] ?? null);

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
            $row = $decision !== null ? $this->decided($slot, $decision, $byPath, $fields, $apiChanged) : $this->auto($slot, $fields, $used);
            $rows[] = $row;

            if ($decision === null && $row['path'] !== null) {
                $used[(string) $row['path']] = true;
            }
        }

        $baseline = $this->baseline($type, $fields, $decidedPath);

        $groups = [];

        foreach ([self::HEADER, self::KPI, self::META] as $name) {
            $slots = array_values(array_filter($rows, fn (array $row): bool => $row['group'] === $name));

            if ($slots === []) {
                continue;
            }

            usort($slots, fn (array $a, array $b): int => ($b['state'] === SlotState::AutoLow->value) <=> ($a['state'] === SlotState::AutoLow->value));
            $groups[] = ['group' => $name, 'slots' => $slots];
        }

        return new AutoMapResult($groups, $this->counts($rows), $this->blockers($rows, $apiChanged), $baseline, $this->plan($rows), $apiChanged);
    }

    public function fieldsFor(FieldRoleReport $report, BlockType $type, string $slot, array $fieldTypes = []): ?array
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

        foreach ($this->enrich($report, $fieldTypes) as $field) {
            $compatible = $field['single'] === true && $this->fits($definition->valueType, $field);
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
            array_splice($entries, $firstIncompatible, 0, [['kind' => 'static_text', 'path' => null, 'display' => 'Static text', 'compatible' => true]]);
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
            if ($field['single'] === true && $this->fits($slot->valueType, $field)) {
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

        if ($field['single'] !== true || ! $this->fits($slot->valueType, $field)) {
            $compatible = array_values(array_filter($byPath, fn (array $f): bool => $f['single'] === true && $this->fits($slot->valueType, $f)));

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
        $single = array_values(array_filter($fields, fn (array $f): bool => $f['single'] === true));
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
        if ($field['single'] !== true) {
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
