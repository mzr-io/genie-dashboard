<script setup lang="ts">
import { computed, useId } from 'vue';
import BandsEditor from '@/components/wizard/BandsEditor.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { ChecklistSlot } from '@/lib/blockMapping';
import {
    DATE_PATTERNS,
    DIRECTIONS,
    EMPHASIS_ROLES,
    EMPHASIS_SIZES,
    EMPHASIS_WEIGHTS,
    EMPTY_PATH,
    FORMAT_CHOICES,
    SLOT_COMPARISON,
    SLOT_HEADLINE,
    choiceOf,
    clean,
    exampleFor,
    setSlot,
    slotPath,
    withChoice,
} from '@/lib/blockPresentation';
import type {
    FormatChoice,
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
}>();

const emit = defineEmits<{ (e: 'change', rules: PresentationRules): void }>();

const id = useId();
const key = computed(() => props.item.slot);
const mine = computed<SlotPresentation>(
    () => props.rules.slots?.[key.value] ?? {},
);
const isHeadline = computed(() => key.value === SLOT_HEADLINE);
const isComparison = computed(() => key.value === SLOT_COMPARISON);
const numeric = computed(
    () =>
        props.item.value_type === 'number' ||
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
            v-if="isHeadline"
            :bands="rules.bands ?? []"
            :errors="errors"
            :disabled="disabled"
            @update="setBands"
            @add="addBand"
        />
    </fieldset>
</template>
