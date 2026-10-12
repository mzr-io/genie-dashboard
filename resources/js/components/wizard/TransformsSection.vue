<script setup lang="ts">
import { SlidersHorizontal } from '@lucide/vue';
import { computed, nextTick, ref, useId } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { announce } from '@/lib/announce';
import {
    OPERATORS,
    canAdd,
    inStageOrder,
    shortName,
} from '@/lib/blockTransforms';
import type {
    FilterStep,
    Operator,
    RowCounts,
    SortStep,
    StepType,
    TopNStep,
    TransformFault,
    TransformStep,
} from '@/lib/blockTransforms';
import {
    blockMapLabels as mapLabels,
    blockTransformLabels as labels,
} from '@/locales/labels';

// "3 · Transforms" of Map Data (Story 5.1; UX-DR-131, 198, 205): Filter, Sort and Top-N steps over the rows. The steps always read and run in the
// fixed stage order (filter, sort, top-N): "+ Add step" puts a new one at its stage position and there is no reordering control. Each row says what
// it does in a sentence, counts the rows in and out, and shows what the sample cannot satisfy in place. The page owns the saving: a change is emitted
// whole, a text value when the field is left or Enter is pressed.
const props = defineProps<{
    steps: TransformStep[];
    // The row fields of the sample a step can use: stored path and the name shown.
    fields: { path: string; display: string }[];
    // What the server (and the client) say is wrong, by step position.
    faults: Record<number, { reason: string; field: string | null }>;
    counts: RowCounts | null;
    // Why nothing can be changed now (read-only, offline, published).
    blocked?: string;
}>();

const emit = defineEmits<{ change: [steps: TransformStep[]] }>();

const open = ref(false);
const adding = ref(false);
const root = ref<HTMLElement | null>(null);
const addButton = ref<InstanceType<typeof Button> | null>(null);
const uid = useId();
const disabled = computed(() => props.blocked !== undefined);
const noFields = computed(() => props.fields.length === 0);

const nameOf = (path: string): string => {
    const found = props.fields.find((f) => f.path === path);

    return found !== undefined ? shortName(found.display) : shortName(path);
};

function sentence(step: TransformStep): string {
    if (step.type === 'filter') {
        return labels.filterSentence(
            nameOf(step.field),
            labels.operators[step.operator],
            step.value,
        );
    }

    if (step.type === 'sort') {
        return labels.sortSentence(
            nameOf(step.field),
            labels.directions[step.direction],
        );
    }

    return labels.topNSentence(step.count);
}

function countOf(index: number): { from: number; to: number } | null {
    const c = props.counts;

    if (c === null || index >= c.steps.length) {
        return null;
    }

    return {
        from: index === 0 ? c.input : c.steps[index - 1],
        to: c.steps[index],
    };
}

function faultText(index: number): string | null {
    const fault = props.faults[index];

    if (fault === undefined) {
        return null;
    }

    const make = (labels.faults as Record<string, unknown>)[fault.reason];

    return typeof make === 'function'
        ? (make as (field: string) => string)(nameOf(fault.field ?? ''))
        : typeof make === 'string'
          ? make
          : labels.blockedContinue;
}

// A change always goes out in the fixed order, so what is shown is what runs.
function put(next: TransformStep[]): void {
    emit('change', inStageOrder(next));
}

function replace(index: number, step: TransformStep): void {
    put(props.steps.map((s, i) => (i === index ? step : s)));
}

function addStep(type: StepType): void {
    adding.value = false;

    const field = props.fields[0]?.path ?? '';
    const step: TransformStep =
        type === 'filter'
            ? { type, field, operator: 'is', value: '' }
            : type === 'sort'
              ? { type, field, direction: 'desc' }
              : { type, count: 5 };

    open.value = true;
    put([...props.steps, step]);
    announce(labels.stepAdded(sentence(step)), 'polite');
    void nextTick(() => {
        const rows = root.value?.querySelectorAll<HTMLElement>(
            `[data-test="transform-row"][data-type="${type}"]`,
        );
        rows?.[rows.length - 1]
            ?.querySelector<HTMLElement>('select, input')
            ?.focus();
    });
}

function remove(index: number): void {
    const gone = props.steps[index];
    const text = sentence(gone);

    put(props.steps.filter((_, i) => i !== index));
    announce(labels.stepRemoved(text), 'polite');

    // Focus goes to the step that took its place, else to "+ Add step".
    void nextTick(() => {
        const rows = root.value?.querySelectorAll<HTMLElement>(
            '[data-test="transform-row"]',
        );
        const next = rows?.[index];

        (
            next?.querySelector<HTMLElement>('select, input') ??
            (addButton.value?.$el as HTMLElement | undefined)
        )?.focus();
    });
}

function setOperator(index: number, step: FilterStep, value: string): void {
    replace(index, { ...step, operator: value as Operator });
}

// A row is drawn again from the saved step, so a value committed with Enter puts focus back where it was.
function keep(index: number, selector: string, event: Event): void {
    if (event.type !== 'keydown') {
        return;
    }

    void nextTick(() =>
        root.value
            ?.querySelectorAll<HTMLElement>('[data-test="transform-row"]')
            [index]?.querySelector<HTMLElement>(selector)
            ?.focus(),
    );
}

function setCount(index: number, step: TopNStep, event: Event): void {
    const raw = (event.target as HTMLInputElement).value.trim();
    const count = /^\d+$/.test(raw) ? Number(raw) : Number.NaN;

    if (count !== step.count) {
        replace(index, { ...step, count });
        keep(index, '[data-test="topn-count"]', event);
    }
}

function commitValue(index: number, step: FilterStep, event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    if (value !== step.value) {
        replace(index, { ...step, value });
        keep(index, '[data-test="filter-value"]', event);
    }
}

// What a screen reader hears of each control on focus (UX-DR-205).
const help = (index: number, part: string): string => `${uid}-${index}-${part}`;

// The first row with a fault takes focus: the Continue button asks for it.
function focusFirstFault(): boolean {
    const index = Object.keys(props.faults)
        .map(Number)
        .sort((a, b) => a - b)[0];

    if (index === undefined) {
        return false;
    }

    open.value = true;
    void nextTick(() => {
        root.value
            ?.querySelectorAll<HTMLElement>('[data-test="transform-row"]')
            [index]?.querySelector<HTMLElement>('select, input')
            ?.focus();
    });

    return true;
}

defineExpose({ focusFirstFault, open });

const typeOf = (step: TransformStep): StepType => step.type;
const asFilter = (s: TransformStep): FilterStep => s as FilterStep;
const asSort = (s: TransformStep): SortStep => s as SortStep;
const asTopN = (s: TransformStep): TopNStep => s as TopNStep;
</script>

<template>
    <details
        ref="root"
        class="rounded-lg border border-border-default bg-surface-card p-4"
        data-test="transforms-section"
        :open="open"
        @toggle="(e: Event) => (open = (e.target as HTMLDetailsElement).open)"
    >
        <summary
            class="type-title-sm flex cursor-pointer items-center gap-3 text-text-primary"
        >
            <SlidersHorizontal class="size-4" aria-hidden="true" />
            {{ mapLabels.transformsTitle }}
            <span
                v-if="steps.length > 0"
                class="type-caption text-text-secondary"
                >({{ steps.length }})</span
            >
        </summary>
        <p class="type-caption mt-2 text-text-muted">
            {{ mapLabels.transformsCaption }}
        </p>

        <p
            v-if="counts !== null"
            class="type-caption mt-1 text-text-secondary tabular-nums"
            data-test="transform-input-rows"
        >
            {{ labels.rowsOfSample(counts.input) }}
        </p>

        <p
            v-if="steps.length === 0"
            class="type-body-sm mt-2 text-text-secondary"
            data-test="transforms-empty"
        >
            {{ mapLabels.transformsEmpty }}
        </p>

        <ol class="m-0 mt-3 grid list-none gap-2 p-0">
            <li
                v-for="(step, index) in steps"
                :key="`${index}:${JSON.stringify(step)}`"
                data-test="transform-row"
                :data-type="typeOf(step)"
                :data-fault="faults[index] !== undefined ? 'true' : undefined"
                class="grid gap-2 rounded-md border border-border-default p-3"
                :class="
                    faults[index] !== undefined
                        ? 'border-l-[3px] border-l-error bg-error-soft'
                        : 'bg-surface-card'
                "
            >
                <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                    <span
                        class="type-label-caps type-caption w-16 shrink-0 pt-2 font-semibold text-text-secondary"
                        data-test="stage-label"
                        >{{ labels.stage[typeOf(step)] }}</span
                    >

                    <div
                        class="flex min-w-0 flex-1 flex-wrap items-center gap-2"
                    >
                        <template v-if="typeOf(step) === 'filter'">
                            <NativeSelect
                                class="w-auto min-w-32"
                                :model-value="asFilter(step).field"
                                :aria-label="labels.fieldName.filter"
                                :aria-describedby="help(index, 'field')"
                                :aria-invalid="
                                    faults[index] !== undefined
                                        ? 'true'
                                        : undefined
                                "
                                :disabled="disabled"
                                data-test="filter-field"
                                @update:model-value="
                                    (v?: string) =>
                                        replace(index, {
                                            ...asFilter(step),
                                            field: v ?? '',
                                        })
                                "
                            >
                                <option
                                    v-if="
                                        !fields.some(
                                            (f) =>
                                                f.path === asFilter(step).field,
                                        )
                                    "
                                    :value="asFilter(step).field"
                                >
                                    {{ shortName(asFilter(step).field) }}
                                </option>
                                <option
                                    v-for="f in fields"
                                    :key="f.path"
                                    :value="f.path"
                                >
                                    {{ shortName(f.display) }}
                                </option>
                            </NativeSelect>
                            <NativeSelect
                                class="w-auto min-w-32"
                                :model-value="asFilter(step).operator"
                                :aria-label="labels.operatorName"
                                :aria-describedby="help(index, 'operator')"
                                :disabled="disabled"
                                data-test="filter-operator"
                                @update:model-value="
                                    (v?: string) =>
                                        setOperator(
                                            index,
                                            asFilter(step),
                                            v ?? 'is',
                                        )
                                "
                            >
                                <option
                                    v-for="op in OPERATORS"
                                    :key="op"
                                    :value="op"
                                >
                                    {{ labels.operators[op] }}
                                </option>
                            </NativeSelect>
                            <Input
                                class="w-auto min-w-32 flex-1"
                                type="text"
                                maxlength="200"
                                autocomplete="off"
                                :default-value="asFilter(step).value"
                                :aria-label="labels.valueName"
                                :aria-describedby="help(index, 'value')"
                                :aria-invalid="
                                    faults[index] !== undefined
                                        ? 'true'
                                        : undefined
                                "
                                :disabled="disabled"
                                data-test="filter-value"
                                @change="
                                    commitValue(index, asFilter(step), $event)
                                "
                                @keydown.enter="
                                    commitValue(index, asFilter(step), $event)
                                "
                            />
                        </template>

                        <template v-else-if="typeOf(step) === 'sort'">
                            <NativeSelect
                                class="w-auto min-w-32"
                                :model-value="asSort(step).field"
                                :aria-label="labels.fieldName.sort"
                                :aria-describedby="help(index, 'field')"
                                :aria-invalid="
                                    faults[index] !== undefined
                                        ? 'true'
                                        : undefined
                                "
                                :disabled="disabled"
                                data-test="sort-field"
                                @update:model-value="
                                    (v?: string) =>
                                        replace(index, {
                                            ...asSort(step),
                                            field: v ?? '',
                                        })
                                "
                            >
                                <option
                                    v-if="
                                        !fields.some(
                                            (f) =>
                                                f.path === asSort(step).field,
                                        )
                                    "
                                    :value="asSort(step).field"
                                >
                                    {{ shortName(asSort(step).field) }}
                                </option>
                                <option
                                    v-for="f in fields"
                                    :key="f.path"
                                    :value="f.path"
                                >
                                    {{ shortName(f.display) }}
                                </option>
                            </NativeSelect>
                            <NativeSelect
                                class="w-auto min-w-32"
                                :model-value="asSort(step).direction"
                                :aria-label="labels.directionName"
                                :aria-describedby="help(index, 'direction')"
                                :disabled="disabled"
                                data-test="sort-direction"
                                @update:model-value="
                                    (v?: string) =>
                                        replace(index, {
                                            ...asSort(step),
                                            direction:
                                                v === 'asc' ? 'asc' : 'desc',
                                        })
                                "
                            >
                                <option value="asc">
                                    {{ labels.directions.asc }}
                                </option>
                                <option value="desc">
                                    {{ labels.directions.desc }}
                                </option>
                            </NativeSelect>
                        </template>

                        <template v-else>
                            <Input
                                class="w-24"
                                type="text"
                                inputmode="numeric"
                                autocomplete="off"
                                :default-value="String(asTopN(step).count)"
                                :aria-label="labels.countName"
                                :aria-describedby="help(index, 'count')"
                                :aria-invalid="
                                    faults[index] !== undefined
                                        ? 'true'
                                        : undefined
                                "
                                :disabled="disabled"
                                data-test="topn-count"
                                @change="setCount(index, asTopN(step), $event)"
                                @keydown.enter="
                                    setCount(index, asTopN(step), $event)
                                "
                            />
                        </template>
                    </div>
                </div>

                <p
                    class="type-body-sm text-text-primary"
                    data-test="step-sentence"
                >
                    {{ sentence(step) }}
                </p>

                <span
                    v-for="part in [
                        'field',
                        'operator',
                        'value',
                        'direction',
                        'count',
                    ]"
                    :id="help(index, part)"
                    :key="part"
                    class="sr-only"
                    >{{
                        part === 'field'
                            ? labels.fieldHelp
                            : part === 'operator'
                              ? labels.operatorHelp
                              : part === 'value'
                                ? labels.valueHelp
                                : part === 'direction'
                                  ? labels.directionHelp
                                  : labels.countHelp
                    }}</span
                >

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p
                        v-if="countOf(index) !== null"
                        class="type-caption text-text-secondary tabular-nums"
                        data-test="step-counts"
                    >
                        {{
                            labels.counts(
                                countOf(index)!.from,
                                countOf(index)!.to,
                            )
                        }}
                    </p>
                    <span v-else />
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :aria-label="labels.removeName(sentence(step))"
                        :aria-disabled="disabled ? 'true' : undefined"
                        data-test="remove-step"
                        @click="!disabled && remove(index)"
                    >
                        {{ labels.remove }}
                    </Button>
                </div>

                <p
                    v-if="faultText(index) !== null"
                    class="type-caption text-error-text"
                    role="alert"
                    data-test="step-fault"
                >
                    {{ faultText(index) }}
                </p>
            </li>
        </ol>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <Button
                ref="addButton"
                type="button"
                variant="secondary"
                :aria-expanded="adding"
                :aria-disabled="disabled || noFields ? 'true' : undefined"
                data-test="add-step"
                @click="!disabled && !noFields && (adding = !adding)"
            >
                {{ labels.addStep }}
            </Button>
            <template v-if="adding">
                <Button
                    v-for="type in ['filter', 'sort', 'top_n'] as StepType[]"
                    :key="type"
                    type="button"
                    variant="secondary"
                    size="sm"
                    :aria-disabled="canAdd(steps, type) ? undefined : 'true'"
                    :data-test="`add-${type}`"
                    @click="canAdd(steps, type) && addStep(type)"
                >
                    {{
                        type === 'filter'
                            ? labels.addFilter
                            : type === 'sort'
                              ? labels.addSort
                              : labels.addTopN
                    }}
                </Button>
            </template>
            <p
                v-if="noFields && !disabled"
                class="type-caption text-text-muted"
                data-test="add-reason"
            >
                {{ labels.addDisabledNoField }}
            </p>
        </div>
    </details>
</template>
