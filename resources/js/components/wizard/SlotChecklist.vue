<script setup lang="ts">
import { computed, nextTick, ref, useId } from 'vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import SlotRow from '@/components/wizard/SlotRow.vue';
import { announce } from '@/lib/announce';
import { filterCounts, matchesFilter } from '@/lib/blockMapping';
import type { PresentationRules } from '@/lib/blockPresentation';
import type {
    Checklist,
    FieldType,
    OverrideNote,
    PickerField,
    SlotFilter,
} from '@/lib/blockMapping';
import { blockMapLabels as labels } from '@/locales/labels';

// "2 · Review block slots" (Story 3.12b): the slots in HEADER, KPI and META groups (the order is the page's: Low first, computed on load and on
// Re-run only), a filter "All · Needs review · Missing", the Re-run button and the baseline note. Confirming under Needs review keeps the row
// while focus stays in the list; leaving it, or changing the filter, lets it go.
const props = defineProps<{
    groups: Checklist['groups'];
    baseline: Checklist['baseline'];
    blocked?: string;
    reasonId?: string;
    // A write is in flight: Confirm and Re-run wait.
    busy?: boolean;
    // The field tree can be opened ("Choose from the field tree").
    treeAvailable?: boolean;
    loadFields?: (slot: string) => Promise<PickerField[]>;
    // The last override of each slot, while it can be undone.
    notes?: Record<string, OverrideNote>;
    // The API change under each slot (the message and the candidate it offers).
    changes?: Record<
        string,
        {
            text: string;
            match: { path: string; display: string } | null;
            matchText: string | null;
        }
    >;
    // The presentation rules and their faults (Story 3.15b).
    rules?: PresentationRules;
    presentationErrors?: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'confirm', slot: string): void;
    (e: 'rerun'): void;
    (e: 'pick', slot: string, path: string | null): void;
    (e: 'undo', slot: string): void;
    (e: 'treat', slot: string, path: string, type: FieldType | null): void;
    (e: 'tree', slot: string): void;
    (e: 'presentation', rules: PresentationRules): void;
}>();

// One row's format and display editor is open at a time.
const openRow = ref<string | null>(null);

function toggleRow(slot: string): void {
    openRow.value = openRow.value === slot ? null : slot;
}

const filter = ref<SlotFilter>('all');
const kept = ref<Set<string>>(new Set());
const root = ref<HTMLElement | null>(null);
const titleId = useId();

const all = computed(() => props.groups.flatMap((group) => group.slots));
const counts = computed(() => filterCounts(all.value));
const options = computed(() => [
    { value: 'all', label: labels.filterAll(counts.value.all) },
    { value: 'review', label: labels.filterReview(counts.value.review) },
    { value: 'missing', label: labels.filterMissing(counts.value.missing) },
]);
const shown = computed(() =>
    props.groups
        .map((group) => ({
            ...group,
            slots: group.slots.filter(
                (slot) =>
                    matchesFilter(slot, filter.value) ||
                    kept.value.has(slot.slot),
            ),
        }))
        .filter((group) => group.slots.length > 0),
);
const baselineText = computed(() =>
    props.baseline.map((field) => field.display).join(', '),
);

function setFilter(next: string): void {
    filter.value = next as SlotFilter;
    kept.value = new Set();
}

function showAll(): void {
    setFilter('all');
}

function rowOf(slot: string): HTMLElement | null {
    return (
        root.value?.querySelector<HTMLElement>(
            `[data-slot-key="${CSS.escape(slot)}"]`,
        ) ?? null
    );
}

// Focus a row (it shows even when a filter would hide it).
async function focusSlot(slot: string): Promise<void> {
    if (rowOf(slot) === null) {
        showAll();
        await nextTick();
    }

    rowOf(slot)?.focus();
}

// The page calls this once a Confirm has succeeded: under Needs review the row stays while focus is in the list.
function keep(slot: string): void {
    if (filter.value === 'review') {
        kept.value = new Set([...kept.value, slot]);
    }
}

// Puts focus back on a row's Confirm button (a refused confirm leaves the Admin where they were).
function focusConfirm(slot: string): void {
    rowOf(slot)
        ?.querySelector<HTMLElement>('[data-test=slot-confirm]')
        ?.focus();
}

function clearKept(): void {
    kept.value = new Set();
}

// Rows kept under a filter go when focus is no longer in the list. A removed Confirm button also fires focusout, so the check waits a tick for
// focus to land on the row.
function onFocusOut(): void {
    setTimeout(() => {
        if (!root.value?.contains(document.activeElement)) {
            kept.value = new Set();
        }
    }, 0);
}

// A hotspot on the preview asks for this slot: the row shows (a filter would hide it), scrolls into view and opens its picker.
const rows = new Map<string, InstanceType<typeof SlotRow>>();

function setRow(slot: string, row: unknown): void {
    if (row) {
        rows.set(slot, row as InstanceType<typeof SlotRow>);
    } else {
        rows.delete(slot);
    }
}

async function openSlot(
    slot: string,
    from: HTMLElement | null = null,
): Promise<void> {
    if (rowOf(slot) === null) {
        showAll();
        await nextTick();
    }

    const calm =
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    rowOf(slot)?.scrollIntoView?.({
        block: 'center',
        behavior: calm ? 'auto' : 'smooth',
    });

    if (!rows.get(slot)?.openPicker(from)) {
        rowOf(slot)?.focus();
        announce(labels.cannotChange, 'polite');
    }
}

// The skip link's target: the first row (or the filter when no row shows).
function focusFirst(): void {
    const first = root.value?.querySelector<HTMLElement>('[data-slot-key]');

    (
        first ?? root.value?.querySelector<HTMLElement>('button, [tabindex]')
    )?.focus();
}

defineExpose({
    openRow,
    focusSlot,
    showAll,
    keep,
    clearKept,
    focusConfirm,
    openSlot,
    focusFirst,
});
</script>

<template>
    <div
        id="slot-checklist"
        tabindex="-1"
        ref="root"
        class="flex flex-col gap-4"
        data-test="slot-checklist"
        @focusout="onFocusOut"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <SegmentedControl
                :model-value="filter"
                :options="options"
                :label="labels.filterLabel"
                data-test="slot-filter"
                @update:model-value="setFilter"
            />
            <Button
                type="button"
                variant="secondary"
                size="sm"
                :blocked="blocked !== undefined || busy === true"
                :blocked-reason="blocked"
                :aria-busy="busy ? 'true' : undefined"
                :aria-describedby="blocked ? reasonId : undefined"
                data-test="rerun"
                @click="emit('rerun')"
                >{{ labels.rerun }}</Button
            >
        </div>

        <section
            v-for="group in shown"
            :key="group.group"
            :aria-labelledby="`${titleId}-${group.group}`"
            class="flex flex-col gap-2"
            data-test="slot-group"
            :data-group="group.group"
        >
            <h3
                :id="`${titleId}-${group.group}`"
                class="type-label-caps text-text-muted"
            >
                {{ labels.groupNames[group.group] ?? group.group }}
            </h3>
            <ul class="m-0 flex list-none flex-col gap-2 p-0">
                <SlotRow
                    v-for="slot in group.slots"
                    :key="slot.slot"
                    :ref="(row) => setRow(slot.slot, row)"
                    :item="slot"
                    :blocked="blocked"
                    :reason-id="reasonId"
                    :busy="busy"
                    :tree-available="treeAvailable"
                    :load-fields="loadFields"
                    :note="notes?.[slot.slot] ?? null"
                    :change-text="changes?.[slot.slot]?.text ?? null"
                    :match="changes?.[slot.slot]?.match ?? null"
                    :match-text="changes?.[slot.slot]?.matchText ?? null"
                    @confirm="emit('confirm', $event)"
                    @pick="(key, path) => emit('pick', key, path)"
                    @undo="emit('undo', $event)"
                    @treat="(key, path, type) => emit('treat', key, path, type)"
                    :rules="rules"
                    :presentation-errors="presentationErrors"
                    :open="openRow === slot.slot"
                    @tree="emit('tree', $event)"
                    @toggle="toggleRow"
                    @presentation="emit('presentation', $event)"
                />
            </ul>
        </section>

        <p
            v-if="shown.length === 0"
            class="type-body-sm text-text-secondary"
            data-test="slot-filter-empty"
        >
            {{ labels.filterEmpty }}
        </p>

        <p
            v-if="baseline.length > 0"
            class="type-caption text-text-muted"
            data-test="baseline-note"
        >
            {{ labels.baselineNote(baselineText) }}
        </p>
    </div>
</template>
