<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import BandsEditor from '@/components/wizard/BandsEditor.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { ChecklistSlot } from '@/lib/blockMapping';
import {
    COMPARISON_MODES,
    DATE_PATTERNS,
    WINDOW_KINDS,
    WINDOW_MAX_AMOUNT,
    WINDOW_UNITS,
    DIRECTIONS,
    EMPHASIS_ROLES,
    EMPHASIS_SIZES,
    EMPHASIS_WEIGHTS,
    EMPTY_PATH,
    FORMAT_CHOICES,
    ROOT,
    SLOT_COMPARISON,
    SLOT_HEADLINE,
    choiceOf,
    clean,
    comparisonChange,
    exampleFor,
    setSlot,
    slotPath,
    withChoice,
} from '@/lib/blockPresentation';
import type {
    ComparisonMode,
    FormatChoice,
    PriorWindow,
    PresentationBand,
    PresentationRules,
    SlotPresentation,
} from '@/lib/blockPresentation';
import { resolveLocale } from '@/lib/format';
import { blockPresentationLabels as labels } from '@/locales/labels';

// The presentation editor of one slot row (Story 3.15b): Format, Emphasis and Direction in three columns (one under 640px), then the label text,
// the bands (on the headline) and the empty display (on every other slot). It edits a whole `PresentationRules` document and hands each change up;
// what is saved, judged and shown as an error is the step's. Text commits on change (blur or Enter), selects and the checkbox at once.
const props = defineProps<{
    item: ChecklistSlot;
    rules: PresentationRules;
    // Faults by `config.presentation.<path>`.
    errors: Record<string, string>;
    disabled?: boolean;
    // The headline the comparison is made from (Story 5.5): whether it is a ratio (percentage points compare two ratios) and its sample value.
    headline?: { ratio: boolean; example: string | null };
}>();

const emit = defineEmits<{ (e: 'change', rules: PresentationRules): void }>();

const id = useId();
const key = computed(() => props.item.slot);
const mine = computed<SlotPresentation>(
    () => props.rules.slots?.[key.value] ?? {},
);
const isHeadline = computed(() => key.value === SLOT_HEADLINE);
const isComparison = computed(() => key.value === SLOT_COMPARISON);
// The chart's own settings (Story 5.8c) sit on its X axis row.
const isAxis = computed(() => key.value === 'x');
// A bar's and a pie's own settings sit on their category row (Story 5.11).
const isBarAxis = computed(() => key.value === 'category');
const isPieAxis = computed(() => key.value === 'pie_category');
// A table's columns (Story 5.12): each one's alignment, width and sort; the page size, the highlight column and its bands sit on the first.
const isColumn = computed(() => /^col_\d+$/.test(key.value));
const isFirstColumn = computed(() => key.value === 'col_1');
// A progress list's colour rule sits on its progress row (Story 5.13).
const isProgress = computed(() => key.value === 'progress_value');
// A calendar's week strip sits on its date row, a text Block's mode and colour rule on its value row (Story 5.14).
const isAgendaDate = computed(() => key.value === 'event_date');
const isStatusText = computed(() => key.value === 'status_text');
const COLUMN_KEYS = Array.from({ length: 12 }, (_, n) => `col_${n + 1}`);
const numeric = computed(
    () =>
        props.item.value_type === 'number' ||
        // A table column or a list's value takes any value (Stories 5.12 and 5.13): the Admin picks how it is written.
        props.item.value_type === 'any' ||
        dated.value ||
        isHeadline.value ||
        isComparison.value,
);
const dated = computed(
    () =>
        !isHeadline.value &&
        !isComparison.value &&
        ['date', 'datetime'].includes(props.item.value_type ?? ''),
);
const choices = computed(() =>
    dated.value
        ? FORMAT_CHOICES.filter((c) => ['date', 'relative-time'].includes(c))
        : FORMAT_CHOICES,
);
const choice = computed(() =>
    dated.value && mine.value.format?.kind === undefined
        ? 'date'
        : choiceOf(mine.value.format),
);
const kind = computed(
    () => mine.value.format?.kind ?? (dated.value ? 'date' : 'number'),
);
const locale = computed(() => resolveLocale(null));
const lowerBetter = computed(() => mine.value.direction === 'lower_is_better');

const example = computed(() => {
    const shown = exampleFor(
        mine.value.format,
        props.item.example ?? null,
        locale.value,
    );

    if (shown === null) {
        return null;
    }

    const label = mine.value.label;

    return isHeadline.value
        ? `${label?.prefix ?? ''}${shown}${label?.suffix ?? ''}`
        : shown;
});

// The comparison source (Story 5.5): only the mapped previous value exists until prior-period requests (sync groups) do.
const priorPicked = computed(() => mine.value.source === 'prior_period');
const priorWindow = computed<PriorWindow>(() => mine.value.window ?? {});
type PriorWindowKind = NonNullable<PriorWindow['kind']>;
const mode = computed<ComparisonMode | ''>(() => mine.value.mode ?? '');
const ratio = computed(() => props.headline?.ratio === true);
const modeExample = computed(() => {
    if (mode.value === '') {
        return null;
    }

    const change = comparisonChange(
        mode.value,
        props.headline?.example,
        props.item.example,
    );

    if (change === null) {
        return null;
    }

    const places = mode.value === 'absolute_delta' ? null : 1;
    const descriptor =
        mine.value.format ??
        (mode.value === 'pct_change'
            ? { kind: 'percent' as const, decimalPlaces: places }
            : mode.value === 'pp_delta'
              ? {
                    kind: 'number' as const,
                    decimalPlaces: places,
                    unitsSuffix: 'pp',
                }
              : undefined);
    const shown = exampleFor(
        descriptor,
        change.replace(/^-/, ''),
        locale.value,
    );

    if (shown === null) {
        return null;
    }

    const flat = !/[1-9]/.test(change);
    const arrow = flat ? '' : change.startsWith('-') ? '↘ ' : '↗ ';
    const sign =
        mode.value !== 'pct_change' && change.startsWith('-') ? '-' : '';

    return `${arrow}${flat ? '' : sign}${shown}`;
});

function setChartKind(next: string): void {
    emit(
        'change',
        clean({
            ...props.rules,
            chart_kind: next === 'area' ? 'area' : undefined,
        }),
    );
}

// One chart setting of the Block: written whole, a default clears it.
function setRule(
    rule: 'chart_kind' | 'orientation' | 'sort' | 'centre' | 'centre_label',
    value: string,
    byDefault: string,
): void {
    emit(
        'change',
        clean({
            ...props.rules,
            [rule]: value === byDefault || value === '' ? undefined : value,
        }),
    );
}

// A column's own setting (Story 5.12): written on the slot, a default clears it.
function setColumn(
    patch: Partial<Pick<SlotPresentation, 'align' | 'width' | 'sortable'>>,
): void {
    emit('change', clean(setSlot(props.rules, key.value, patch)));
}

function setWidth(raw: string): void {
    const text = raw.trim();

    setColumn({
        width:
            text === ''
                ? undefined
                : /^\d+$/.test(text)
                  ? Number(text)
                  : (text as never),
    });
}

function setPageSize(raw: string): void {
    const text = raw.trim();

    emit(
        'change',
        clean({
            ...props.rules,
            page_size:
                text === ''
                    ? undefined
                    : /^\d+$/.test(text)
                      ? Number(text)
                      : (text as never),
        }),
    );
}

function setHighlight(next: string): void {
    emit(
        'change',
        clean({
            ...props.rules,
            highlight_column: next === '' ? undefined : next,
        }),
    );
}

function setWeekStrip(next: boolean): void {
    emit(
        'change',
        clean({ ...props.rules, week_strip: next ? true : undefined }),
    );
}

function setTextMode(next: string): void {
    emit(
        'change',
        clean({
            ...props.rules,
            text_mode: next === 'status' ? 'status' : undefined,
        }),
    );
}

function setValueLabels(next: boolean): void {
    emit(
        'change',
        clean({ ...props.rules, value_labels: next ? true : undefined }),
    );
}

function setDirectLabels(next: boolean): void {
    emit(
        'change',
        clean({ ...props.rules, direct_labels: next ? undefined : false }),
    );
}

function setSource(next: string): void {
    patch(
        next === 'prior'
            ? {
                  source: 'prior_period',
                  window: mine.value.window ?? { kind: 'previous_period' },
              }
            : { source: undefined, window: undefined },
    );
}

function setWindowKind(next: string): void {
    patch({
        window:
            next === 'custom_offset'
                ? {
                      kind: 'custom_offset',
                      amount: priorWindow.value.amount ?? 1,
                      unit: priorWindow.value.unit ?? 'month',
                  }
                : { kind: next as PriorWindowKind },
    });
}

function setWindowAmount(raw: string): void {
    const value = raw.trim();

    patch({
        window: {
            ...priorWindow.value,
            amount: /^\d+$/.test(value) ? Number(value) : value,
        },
    });
}

function setMode(next: string): void {
    patch({ mode: next === '' ? undefined : (next as ComparisonMode) });
}

const err = (part: string): string | undefined =>
    props.errors[slotPath(key.value, part)];
const text = (event: Event): string => (event.target as HTMLInputElement).value;

function patch(change: Partial<SlotPresentation>): void {
    emit('change', setSlot(props.rules, key.value, change));
}

function patchFormat(change: Record<string, unknown>): void {
    patch({ format: { ...mine.value.format, ...change } });
}

function setChoice(next: string): void {
    patch({
        format: withChoice(mine.value.format, next as FormatChoice),
    });
}

function setPlaces(raw: string): void {
    const value = raw.trim();

    patchFormat({
        decimalPlaces:
            value === ''
                ? undefined
                : /^-?\d+$/.test(value)
                  ? Number(value)
                  : value,
    });
}

function patchEmphasis(part: 'role' | 'size' | 'weight', value: string): void {
    patch({
        emphasis: {
            ...mine.value.emphasis,
            [part]: value === '' ? undefined : value,
        },
    });
}

function patchLabel(part: 'text' | 'prefix' | 'suffix', value: string): void {
    patch({ label: { ...mine.value.label, [part]: value } });
}

function setBands(bands: PresentationBand[]): void {
    emit('change', clean({ ...props.rules, bands }));
}

function addBand(): void {
    setBands([
        ...(props.rules.bands ?? []),
        { op: '>=', value: '', color: 'success', icon: '', label: '' },
    ]);
}

function setEmpty(value: string): void {
    emit('change', clean({ ...props.rules, empty_display: value }));
}

const field = 'grid gap-1 [&>label]:type-caption [&>label]:text-text-secondary';
</script>

<template>
    <fieldset
        :disabled="disabled"
        class="m-0 grid min-w-0 gap-4 border-0 p-0"
        :aria-label="labels.heading(item.label)"
        data-test="presentation-editor"
        :data-slot-key="item.slot"
    >
        <p v-if="disabled" class="type-caption text-text-muted">
            {{ labels.readOnly }}
        </p>

        <div class="grid gap-4 sm:grid-cols-3" data-test="presentation-columns">
            <!-- Format -->
            <section
                class="grid min-w-0 content-start gap-2"
                :aria-label="labels.formatTitle"
            >
                <h4 class="type-label-caps text-text-muted">
                    {{ labels.formatTitle }}
                </h4>

                <p
                    v-if="!numeric"
                    class="type-caption text-text-muted"
                    data-test="no-format"
                >
                    {{ labels.noFormat }}
                </p>

                <template v-else>
                    <div :class="field">
                        <label :for="`${id}-kind`">{{ labels.kind }}</label>
                        <NativeSelect
                            :id="`${id}-kind`"
                            :model-value="choice"
                            data-test="format-kind"
                            @update:model-value="
                                (v) => setChoice(v ?? 'number')
                            "
                        >
                            <option
                                v-for="option in choices"
                                :key="option"
                                :value="option"
                            >
                                {{ labels.kinds[option] }}
                            </option>
                        </NativeSelect>
                    </div>

                    <div
                        v-if="
                            !['relative-time', 'duration', 'date'].includes(
                                kind,
                            )
                        "
                        :class="field"
                    >
                        <label :for="`${id}-places`">{{ labels.places }}</label>
                        <Input
                            :id="`${id}-places`"
                            :model-value="mine.format?.decimalPlaces ?? ''"
                            inputmode="numeric"
                            autocomplete="off"
                            :aria-invalid="
                                err('format.decimalPlaces') ? 'true' : undefined
                            "
                            :aria-describedby="`${id}-places-hint`"
                            data-test="format-places"
                            @change="(e: Event) => setPlaces(text(e))"
                        />
                        <p
                            :id="`${id}-places-hint`"
                            class="type-caption"
                            :class="
                                err('format.decimalPlaces')
                                    ? 'text-error-text'
                                    : 'text-text-muted'
                            "
                            data-test="places-hint"
                        >
                            {{
                                err('format.decimalPlaces') ?? labels.placesHint
                            }}
                        </p>
                    </div>

                    <div
                        v-if="
                            !['relative-time', 'duration', 'date'].includes(
                                kind,
                            )
                        "
                        class="flex items-center gap-2"
                    >
                        <Checkbox
                            :id="`${id}-thousands`"
                            :model-value="
                                mine.format?.thousandsSeparator !== false
                            "
                            data-test="format-thousands"
                            @update:model-value="
                                (v) =>
                                    patchFormat({
                                        thousandsSeparator:
                                            v === true ? undefined : false,
                                    })
                            "
                        />
                        <label
                            :for="`${id}-thousands`"
                            class="type-body-sm text-text-primary"
                            >{{ labels.thousands }}</label
                        >
                    </div>

                    <template v-if="kind === 'currency'">
                        <div :class="field">
                            <label :for="`${id}-code`">{{
                                labels.currencyCode
                            }}</label>
                            <Input
                                :id="`${id}-code`"
                                :model-value="mine.format?.currencyCode ?? ''"
                                maxlength="12"
                                autocomplete="off"
                                :aria-invalid="
                                    err('format.currencyCode')
                                        ? 'true'
                                        : undefined
                                "
                                :aria-describedby="`${id}-code-hint`"
                                data-test="format-currency"
                                @change="
                                    (e: Event) =>
                                        patchFormat({
                                            currencyCode: text(e)
                                                .trim()
                                                .toUpperCase(),
                                        })
                                "
                            />
                            <p
                                :id="`${id}-code-hint`"
                                class="type-caption"
                                :class="
                                    err('format.currencyCode')
                                        ? 'text-error-text'
                                        : 'text-text-muted'
                                "
                            >
                                {{
                                    err('format.currencyCode') ??
                                    labels.currencyCodeHint
                                }}
                            </p>
                        </div>
                        <div :class="field">
                            <label :for="`${id}-display`">{{
                                labels.currencyDisplay
                            }}</label>
                            <NativeSelect
                                :id="`${id}-display`"
                                :model-value="
                                    mine.format?.currencyDisplay ?? 'symbol'
                                "
                                data-test="format-currency-display"
                                @update:model-value="
                                    (v) =>
                                        patchFormat({
                                            currencyDisplay:
                                                v === 'code'
                                                    ? 'code'
                                                    : undefined,
                                        })
                                "
                            >
                                <option value="symbol">
                                    {{ labels.currencyDisplays.symbol }}
                                </option>
                                <option value="code">
                                    {{ labels.currencyDisplays.code }}
                                </option>
                            </NativeSelect>
                        </div>
                    </template>

                    <div v-if="kind === 'date'" :class="field">
                        <label :for="`${id}-pattern`">{{
                            labels.datePattern
                        }}</label>
                        <NativeSelect
                            :id="`${id}-pattern`"
                            :model-value="mine.format?.datePattern ?? 'medium'"
                            data-test="format-date"
                            @update:model-value="
                                (v) =>
                                    patchFormat({
                                        datePattern:
                                            v === 'medium' ? undefined : v,
                                    })
                            "
                        >
                            <option
                                v-for="pattern in DATE_PATTERNS"
                                :key="pattern"
                                :value="pattern"
                            >
                                {{ labels.datePatterns[pattern] }}
                            </option>
                        </NativeSelect>
                    </div>

                    <div :class="field">
                        <label :for="`${id}-units`">{{ labels.units }}</label>
                        <Input
                            :id="`${id}-units`"
                            :model-value="mine.format?.unitsSuffix ?? ''"
                            autocomplete="off"
                            :aria-invalid="
                                err('format.unitsSuffix') ? 'true' : undefined
                            "
                            :aria-describedby="`${id}-units-hint`"
                            data-test="format-units"
                            @change="
                                (e: Event) =>
                                    patchFormat({ unitsSuffix: text(e) })
                            "
                        />
                        <p
                            :id="`${id}-units-hint`"
                            class="type-caption"
                            :class="
                                err('format.unitsSuffix')
                                    ? 'text-error-text'
                                    : 'text-text-muted'
                            "
                        >
                            {{ err('format.unitsSuffix') ?? labels.unitsHint }}
                        </p>
                    </div>

                    <p
                        class="type-body-sm rounded-sm bg-surface-muted px-2 py-1 text-text-primary tabular-nums"
                        role="status"
                        data-test="format-example"
                    >
                        {{
                            example === null
                                ? labels.exampleNone
                                : labels.exampleValue(example)
                        }}
                    </p>
                    <p
                        v-if="err('format.kind')"
                        class="type-caption text-error-text"
                    >
                        {{ err('format.kind') }}
                    </p>
                    <p
                        v-if="err('format')"
                        class="type-caption text-error-text"
                    >
                        {{ err('format') }}
                    </p>
                </template>
            </section>

            <!-- Emphasis -->
            <section
                class="grid min-w-0 content-start gap-2"
                :aria-label="labels.emphasisTitle"
            >
                <h4 class="type-label-caps text-text-muted">
                    {{ labels.emphasisTitle }}
                </h4>
                <div :class="field">
                    <label :for="`${id}-role`">{{ labels.role }}</label>
                    <NativeSelect
                        :id="`${id}-role`"
                        :model-value="mine.emphasis?.role ?? ''"
                        :aria-invalid="err('emphasis') ? 'true' : undefined"
                        data-test="emphasis-role"
                        @update:model-value="
                            (v) => patchEmphasis('role', v ?? '')
                        "
                    >
                        <option value="">{{ labels.notSet }}</option>
                        <option
                            v-for="role in EMPHASIS_ROLES"
                            :key="role"
                            :value="role"
                        >
                            {{ labels.roles[role] }}
                        </option>
                    </NativeSelect>
                </div>
                <div :class="field">
                    <label :for="`${id}-size`">{{ labels.size }}</label>
                    <NativeSelect
                        :id="`${id}-size`"
                        :model-value="mine.emphasis?.size ?? ''"
                        data-test="emphasis-size"
                        @update:model-value="
                            (v) => patchEmphasis('size', v ?? '')
                        "
                    >
                        <option value="">{{ labels.notSet }}</option>
                        <option
                            v-for="size in EMPHASIS_SIZES"
                            :key="size"
                            :value="size"
                        >
                            {{ labels.sizes[size] }}
                        </option>
                    </NativeSelect>
                </div>
                <div :class="field">
                    <label :for="`${id}-weight`">{{ labels.weight }}</label>
                    <NativeSelect
                        :id="`${id}-weight`"
                        :model-value="mine.emphasis?.weight ?? ''"
                        data-test="emphasis-weight"
                        @update:model-value="
                            (v) => patchEmphasis('weight', v ?? '')
                        "
                    >
                        <option value="">{{ labels.notSet }}</option>
                        <option
                            v-for="weight in EMPHASIS_WEIGHTS"
                            :key="weight"
                            :value="weight"
                        >
                            {{ labels.weights[weight] }}
                        </option>
                    </NativeSelect>
                </div>
                <p
                    v-if="err('emphasis') || err('emphasis.role')"
                    class="type-caption text-error-text"
                    data-test="emphasis-error"
                >
                    {{ err('emphasis') ?? err('emphasis.role') }}
                </p>
            </section>

            <!-- Direction -->
            <section
                class="grid min-w-0 content-start gap-2"
                :aria-label="labels.directionTitle"
            >
                <h4 class="type-label-caps text-text-muted">
                    {{ labels.directionTitle }}
                </h4>
                <template v-if="isComparison">
                    <div :class="field">
                        <label :for="`${id}-direction`">{{
                            labels.direction
                        }}</label>
                        <NativeSelect
                            :id="`${id}-direction`"
                            :model-value="mine.direction ?? 'higher_is_better'"
                            data-test="direction"
                            @update:model-value="
                                (v) =>
                                    patch({
                                        direction:
                                            v === 'lower_is_better'
                                                ? 'lower_is_better'
                                                : undefined,
                                    })
                            "
                        >
                            <option
                                v-for="direction in DIRECTIONS"
                                :key="direction"
                                :value="direction"
                            >
                                {{ labels.directions[direction] }}
                            </option>
                        </NativeSelect>
                    </div>
                    <p
                        class="type-caption text-text-secondary"
                        data-test="direction-note"
                    >
                        {{
                            lowerBetter
                                ? labels.directionNoteLower
                                : labels.directionNote
                        }}
                    </p>
                    <p
                        v-if="err('direction')"
                        class="type-caption text-error-text"
                    >
                        {{ err('direction') }}
                    </p>
                </template>
                <p
                    v-else
                    class="type-caption text-text-muted"
                    data-test="direction-na"
                >
                    {{ labels.directionNotHere }}
                </p>
            </section>
        </div>

        <!-- Comparison source and mode (Story 5.5) -->
        <section
            v-if="isComparison"
            class="grid min-w-0 content-start gap-2"
            :aria-label="labels.comparisonTitle"
            data-test="comparison-source"
        >
            <h4 class="type-label-caps text-text-muted">
                {{ labels.comparisonTitle }}
            </h4>
            <div class="grid gap-3 sm:grid-cols-2">
                <div :class="field">
                    <span :id="`${id}-source`">{{ labels.sourceLabel }}</span>
                    <SegmentedControl
                        :model-value="priorPicked ? 'prior' : 'mapped'"
                        :options="[
                            { value: 'mapped', label: labels.sourceMapped },
                            { value: 'prior', label: labels.sourcePrior },
                        ]"
                        :label="labels.sourceLabel"
                        data-test="comparison-source-control"
                        @update:model-value="setSource"
                    />
                </div>
                <div :class="field">
                    <label :for="`${id}-mode`">{{ labels.modeLabel }}</label>
                    <NativeSelect
                        :id="`${id}-mode`"
                        :model-value="mode"
                        :disabled="disabled"
                        :aria-invalid="err('mode') ? 'true' : undefined"
                        data-test="comparison-mode"
                        @update:model-value="(v) => setMode(String(v))"
                    >
                        <option value="">
                            {{
                                priorPicked
                                    ? labels.modePrior
                                    : labels.modeValue
                            }}
                        </option>
                        <option
                            v-for="option in COMPARISON_MODES"
                            :key="option"
                            :value="option"
                            :disabled="option === 'pp_delta' && !ratio"
                        >
                            {{ labels.modes[option] }}
                        </option>
                    </NativeSelect>
                </div>
            </div>
            <div
                v-if="priorPicked"
                class="grid gap-3 sm:grid-cols-3"
                data-test="comparison-window"
            >
                <div :class="field">
                    <label :for="`${id}-window`">{{
                        labels.windowLabel
                    }}</label>
                    <NativeSelect
                        :id="`${id}-window`"
                        :model-value="priorWindow.kind ?? ''"
                        :disabled="disabled"
                        :aria-invalid="err('window') ? 'true' : undefined"
                        data-test="window-kind"
                        @update:model-value="(v) => setWindowKind(String(v))"
                    >
                        <option
                            v-for="kind in WINDOW_KINDS"
                            :key="kind"
                            :value="kind"
                        >
                            {{ labels.windowKinds[kind] }}
                        </option>
                    </NativeSelect>
                </div>
                <template v-if="priorWindow.kind === 'custom_offset'">
                    <div :class="field">
                        <label :for="`${id}-window-amount`">{{
                            labels.windowAmount
                        }}</label>
                        <Input
                            :id="`${id}-window-amount`"
                            :model-value="String(priorWindow.amount ?? '')"
                            inputmode="numeric"
                            autocomplete="off"
                            :disabled="disabled"
                            :max="WINDOW_MAX_AMOUNT"
                            :aria-invalid="
                                err('window.amount') ? 'true' : undefined
                            "
                            data-test="window-amount"
                            @change="(e: Event) => setWindowAmount(text(e))"
                        />
                    </div>
                    <div :class="field">
                        <label :for="`${id}-window-unit`">{{
                            labels.windowUnit
                        }}</label>
                        <NativeSelect
                            :id="`${id}-window-unit`"
                            :model-value="priorWindow.unit ?? ''"
                            :disabled="disabled"
                            data-test="window-unit"
                            @update:model-value="
                                (v) =>
                                    patch({
                                        window: {
                                            ...priorWindow,
                                            unit: String(v) as never,
                                        },
                                    })
                            "
                        >
                            <option
                                v-for="unit in WINDOW_UNITS"
                                :key="unit"
                                :value="unit"
                            >
                                {{ labels.windowUnits[unit] }}
                            </option>
                        </NativeSelect>
                    </div>
                </template>
            </div>
            <p
                v-if="priorPicked"
                class="type-caption text-text-secondary"
                data-test="comparison-prior-note"
            >
                {{ labels.windowNote }}
            </p>
            <p
                v-if="
                    err('window') || err('window.amount') || err('window.unit')
                "
                class="type-caption text-error-text"
                data-test="window-error"
            >
                {{
                    err('window') ?? err('window.amount') ?? err('window.unit')
                }}
            </p>
            <p
                v-if="!ratio"
                class="type-caption text-text-muted"
                data-test="comparison-pp-reason"
            >
                {{ labels.modePpReason }}
            </p>
            <p v-if="!priorPicked" class="type-caption text-text-secondary">
                {{ labels.modeNote }}
            </p>
            <p
                v-if="mode !== ''"
                class="type-caption text-text-secondary"
                data-test="comparison-example"
            >
                {{
                    modeExample === null
                        ? labels.modeNoExample
                        : labels.modeExample(modeExample)
                }}
            </p>
            <p v-if="err('mode')" class="type-caption text-error-text">
                {{ err('mode') }}
            </p>
        </section>

        <!-- Chart settings (Story 5.8c) -->
        <section
            v-if="isAxis"
            class="grid min-w-0 content-start gap-2"
            :aria-label="labels.chartTitle"
            data-test="chart-settings"
        >
            <h4 class="type-label-caps text-text-muted">
                {{ labels.chartTitle }}
            </h4>
            <div class="grid gap-3 sm:grid-cols-2">
                <div :class="field">
                    <label :for="`${id}-kind`">{{ labels.chartKind }}</label>
                    <NativeSelect
                        :id="`${id}-kind`"
                        :model-value="rules.chart_kind ?? 'line'"
                        :disabled="disabled"
                        data-test="chart-kind"
                        @update:model-value="(v) => setChartKind(String(v))"
                    >
                        <option value="line">{{ labels.chartLine }}</option>
                        <option value="area">{{ labels.chartArea }}</option>
                    </NativeSelect>
                </div>
                <label class="type-body-sm flex items-center gap-2">
                    <Checkbox
                        :model-value="rules.direct_labels !== false"
                        :disabled="disabled"
                        data-test="chart-direct-labels"
                        @update:model-value="(v) => setDirectLabels(v === true)"
                    />
                    {{ labels.chartDirectLabels }}
                </label>
            </div>
        </section>

        <!-- Bar settings (Story 5.11) -->
        <section
            v-if="isBarAxis"
            class="grid min-w-0 content-start gap-2"
            :aria-label="labels.chartTitle"
            data-test="bar-settings"
        >
            <h4 class="type-label-caps text-text-muted">
                {{ labels.chartTitle }}
            </h4>
            <div class="grid gap-3 sm:grid-cols-3">
                <div :class="field">
                    <label :for="`${id}-layout`">{{ labels.barLayout }}</label>
                    <NativeSelect
                        :id="`${id}-layout`"
                        :model-value="rules.chart_kind ?? 'grouped'"
                        :disabled="disabled"
                        data-test="bar-layout"
                        @update:model-value="
                            (v) => setRule('chart_kind', String(v), 'grouped')
                        "
                    >
                        <option value="grouped">{{ labels.barGrouped }}</option>
                        <option value="stacked">{{ labels.barStacked }}</option>
                    </NativeSelect>
                </div>
                <div :class="field">
                    <label :for="`${id}-orientation`">{{
                        labels.barOrientation
                    }}</label>
                    <NativeSelect
                        :id="`${id}-orientation`"
                        :model-value="rules.orientation ?? 'vertical'"
                        :disabled="disabled"
                        data-test="bar-orientation"
                        @update:model-value="
                            (v) => setRule('orientation', String(v), 'vertical')
                        "
                    >
                        <option value="vertical">
                            {{ labels.barVertical }}
                        </option>
                        <option value="horizontal">
                            {{ labels.barHorizontal }}
                        </option>
                    </NativeSelect>
                </div>
                <div :class="field">
                    <label :for="`${id}-sort`">{{ labels.barSort }}</label>
                    <NativeSelect
                        :id="`${id}-sort`"
                        :model-value="rules.sort ?? 'data'"
                        :disabled="disabled"
                        data-test="bar-sort"
                        @update:model-value="
                            (v) => setRule('sort', String(v), 'data')
                        "
                    >
                        <option value="data">{{ labels.sortData }}</option>
                        <option value="value">{{ labels.sortValue }}</option>
                    </NativeSelect>
                </div>
            </div>
            <label class="type-body-sm flex items-center gap-2">
                <Checkbox
                    :model-value="rules.value_labels === true"
                    :disabled="disabled"
                    data-test="bar-value-labels"
                    @update:model-value="(v) => setValueLabels(v === true)"
                />
                {{ labels.barValueLabels }}
            </label>
        </section>

        <!-- Pie settings (Story 5.11) -->
        <section
            v-if="isPieAxis"
            class="grid min-w-0 content-start gap-2"
            :aria-label="labels.chartTitle"
            data-test="pie-settings"
        >
            <h4 class="type-label-caps text-text-muted">
                {{ labels.chartTitle }}
            </h4>
            <div class="grid gap-3 sm:grid-cols-3">
                <div :class="field">
                    <label :for="`${id}-shape`">{{ labels.pieShape }}</label>
                    <NativeSelect
                        :id="`${id}-shape`"
                        :model-value="rules.chart_kind ?? 'pie'"
                        :disabled="disabled"
                        data-test="pie-shape"
                        @update:model-value="
                            (v) => setRule('chart_kind', String(v), 'pie')
                        "
                    >
                        <option value="pie">{{ labels.piePie }}</option>
                        <option value="donut">{{ labels.pieDonut }}</option>
                    </NativeSelect>
                </div>
                <div :class="field">
                    <label :for="`${id}-centre`">{{ labels.pieCentre }}</label>
                    <NativeSelect
                        :id="`${id}-centre`"
                        :model-value="rules.centre ?? 'none'"
                        :disabled="disabled || rules.chart_kind !== 'donut'"
                        data-test="pie-centre"
                        @update:model-value="
                            (v) => setRule('centre', String(v), 'none')
                        "
                    >
                        <option value="none">{{ labels.centreNone }}</option>
                        <option value="value">{{ labels.centreValue }}</option>
                        <option value="total">{{ labels.centreTotal }}</option>
                    </NativeSelect>
                </div>
                <div :class="field">
                    <label :for="`${id}-centre-label`">{{
                        labels.pieCentreLabel
                    }}</label>
                    <Input
                        :id="`${id}-centre-label`"
                        :model-value="rules.centre_label ?? ''"
                        autocomplete="off"
                        :disabled="disabled || rules.chart_kind !== 'donut'"
                        data-test="pie-centre-label"
                        @change="
                            (e: Event) =>
                                setRule('centre_label', text(e).trim(), '')
                        "
                    />
                </div>
            </div>
        </section>

        <!-- A calendar's week strip (Story 5.14) -->
        <section
            v-if="isAgendaDate"
            class="grid min-w-0 content-start gap-2"
            :aria-label="labels.agendaTitle"
            data-test="agenda-settings"
        >
            <h4 class="type-label-caps text-text-muted">
                {{ labels.agendaTitle }}
            </h4>
            <label class="type-body-sm flex items-center gap-2">
                <Checkbox
                    :model-value="rules.week_strip === true"
                    :disabled="disabled"
                    data-test="agenda-week-strip"
                    @update:model-value="(v) => setWeekStrip(v === true)"
                />
                {{ labels.agendaWeekStrip }}
            </label>
        </section>

        <!-- A text Block's mode (Story 5.14) -->
        <section
            v-if="isStatusText"
            class="grid min-w-0 content-start gap-2"
            :aria-label="labels.textModeTitle"
            data-test="text-mode-settings"
        >
            <div :class="field">
                <label :for="`${id}-text-mode`">{{
                    labels.textModeTitle
                }}</label>
                <NativeSelect
                    :id="`${id}-text-mode`"
                    :model-value="rules.text_mode ?? 'text'"
                    :disabled="disabled"
                    data-test="text-mode"
                    @update:model-value="(v) => setTextMode(String(v))"
                >
                    <option value="text">{{ labels.textModeText }}</option>
                    <option value="status">{{ labels.textModeStatus }}</option>
                </NativeSelect>
            </div>
        </section>

        <!-- A table column (Story 5.12) -->
        <section
            v-if="isColumn"
            class="grid min-w-0 content-start gap-2"
            :aria-label="labels.columnTitle"
            data-test="column-settings"
        >
            <h4 class="type-label-caps text-text-muted">
                {{ labels.columnTitle }}
            </h4>
            <div class="grid gap-3 sm:grid-cols-3">
                <div :class="field">
                    <label :for="`${id}-align`">{{ labels.columnAlign }}</label>
                    <NativeSelect
                        :id="`${id}-align`"
                        :model-value="mine.align ?? ''"
                        :disabled="disabled"
                        data-test="column-align"
                        @update:model-value="
                            (v) =>
                                setColumn({
                                    align:
                                        v === ''
                                            ? undefined
                                            : (String(v) as never),
                                })
                        "
                    >
                        <option value="">{{ labels.alignAuto }}</option>
                        <option value="left">{{ labels.alignLeft }}</option>
                        <option value="right">{{ labels.alignRight }}</option>
                        <option value="center">{{ labels.alignCenter }}</option>
                    </NativeSelect>
                </div>
                <div :class="field">
                    <label :for="`${id}-width`">{{ labels.columnWidth }}</label>
                    <Input
                        :id="`${id}-width`"
                        :model-value="mine.width ?? ''"
                        inputmode="numeric"
                        autocomplete="off"
                        :disabled="disabled"
                        data-test="column-width"
                        @change="(e: Event) => setWidth(text(e))"
                    />
                    <p
                        v-if="errors[slotPath(key, 'width')]"
                        class="type-caption text-text-error"
                        data-test="column-width-error"
                    >
                        {{ errors[slotPath(key, 'width')] }}
                    </p>
                </div>
                <label class="type-body-sm flex items-center gap-2 self-end">
                    <Checkbox
                        :model-value="mine.sortable === true"
                        :disabled="disabled"
                        data-test="column-sortable"
                        @update:model-value="
                            (v) =>
                                setColumn({
                                    sortable: v === true ? true : undefined,
                                })
                        "
                    />
                    {{ labels.columnSortable }}
                </label>
            </div>
        </section>

        <!-- The table's own settings (Story 5.12) -->
        <section
            v-if="isFirstColumn"
            class="grid min-w-0 content-start gap-2"
            :aria-label="labels.tableTitle"
            data-test="table-settings"
        >
            <h4 class="type-label-caps text-text-muted">
                {{ labels.tableTitle }}
            </h4>
            <div class="grid gap-3 sm:grid-cols-2">
                <div :class="field">
                    <label :for="`${id}-page-size`">{{
                        labels.tablePageSize
                    }}</label>
                    <Input
                        :id="`${id}-page-size`"
                        :model-value="rules.page_size ?? ''"
                        inputmode="numeric"
                        autocomplete="off"
                        :disabled="disabled"
                        data-test="table-page-size"
                        @change="(e: Event) => setPageSize(text(e))"
                    />
                    <p
                        v-if="errors[`${ROOT}.page_size`]"
                        class="type-caption text-text-error"
                        data-test="table-page-size-error"
                    >
                        {{ errors[`${ROOT}.page_size`] }}
                    </p>
                </div>
                <div :class="field">
                    <label :for="`${id}-highlight`">{{
                        labels.tableHighlight
                    }}</label>
                    <NativeSelect
                        :id="`${id}-highlight`"
                        :model-value="rules.highlight_column ?? ''"
                        :disabled="disabled"
                        data-test="table-highlight-column"
                        @update:model-value="(v) => setHighlight(String(v))"
                    >
                        <option value="">{{ labels.tableNoHighlight }}</option>
                        <option
                            v-for="(column, at) in COLUMN_KEYS"
                            :key="column"
                            :value="column"
                        >
                            {{ labels.tableColumn }} {{ at + 1 }}
                        </option>
                    </NativeSelect>
                </div>
            </div>
        </section>

        <!-- Labels -->
        <div class="grid gap-3 sm:grid-cols-3">
            <div :class="field">
                <label :for="`${id}-label`">{{ labels.labelText }}</label>
                <Input
                    :id="`${id}-label`"
                    :model-value="mine.label?.text ?? ''"
                    autocomplete="off"
                    :aria-invalid="err('label.text') ? 'true' : undefined"
                    data-test="label-text"
                    @change="(e: Event) => patchLabel('text', text(e))"
                />
                <p
                    v-if="err('label.text')"
                    class="type-caption text-error-text"
                >
                    {{ err('label.text') }}
                </p>
            </div>
            <template v-if="isHeadline">
                <div :class="field">
                    <label :for="`${id}-prefix`">{{ labels.prefix }}</label>
                    <Input
                        :id="`${id}-prefix`"
                        :model-value="mine.label?.prefix ?? ''"
                        autocomplete="off"
                        :aria-invalid="err('label.prefix') ? 'true' : undefined"
                        data-test="label-prefix"
                        @change="(e: Event) => patchLabel('prefix', text(e))"
                    />
                    <p
                        v-if="err('label.prefix')"
                        class="type-caption text-error-text"
                    >
                        {{ err('label.prefix') }}
                    </p>
                </div>
                <div :class="field">
                    <label :for="`${id}-suffix`">{{ labels.suffix }}</label>
                    <Input
                        :id="`${id}-suffix`"
                        :model-value="mine.label?.suffix ?? ''"
                        autocomplete="off"
                        :aria-invalid="err('label.suffix') ? 'true' : undefined"
                        data-test="label-suffix"
                        @change="(e: Event) => patchLabel('suffix', text(e))"
                    />
                    <p
                        v-if="err('label.suffix')"
                        class="type-caption text-error-text"
                    >
                        {{ err('label.suffix') }}
                    </p>
                </div>
            </template>
        </div>

        <!-- Empty display (every slot but the headline) -->
        <div v-if="!isHeadline" :class="field">
            <label :for="`${id}-empty`">{{ labels.emptyTitle }}</label>
            <Input
                :id="`${id}-empty`"
                :model-value="rules.empty_display ?? ''"
                autocomplete="off"
                :aria-invalid="errors[EMPTY_PATH] ? 'true' : undefined"
                :aria-describedby="`${id}-empty-hint`"
                data-test="empty-display"
                @change="(e: Event) => setEmpty(text(e))"
            />
            <p
                :id="`${id}-empty-hint`"
                class="type-caption"
                :class="
                    errors[EMPTY_PATH] ? 'text-error-text' : 'text-text-muted'
                "
            >
                {{ errors[EMPTY_PATH] ?? labels.emptyHint }}
            </p>
        </div>
        <p
            v-else
            class="type-caption text-text-muted"
            data-test="empty-headline-note"
        >
            {{ labels.emptyForHeadline }}
        </p>

        <!-- Bands (on the headline value) -->
        <BandsEditor
            v-if="isHeadline || isFirstColumn || isProgress || isStatusText"
            :bands="rules.bands ?? []"
            :errors="errors"
            :disabled="disabled"
            @update="setBands"
            @add="addBand"
        />
    </fieldset>
</template>
