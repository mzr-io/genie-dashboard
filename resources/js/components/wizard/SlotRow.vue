<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import ConfidenceMeter from '@/components/wizard/ConfidenceMeter.vue';
import FieldPicker from '@/components/wizard/FieldPicker.vue';
import TreatAsMenu from '@/components/wizard/TreatAsMenu.vue';
import { Button } from '@/components/ui/button';
import {
    compatibleFields,
    confidenceOf,
    needsReview,
} from '@/lib/blockMapping';
import type {
    ChecklistSlot,
    FieldType,
    OverrideNote,
    PickerField,
} from '@/lib/blockMapping';
import { blockMapLabels as labels } from '@/locales/labels';

// One row of the slot checklist (Story 3.12b): the slot, its state and confidence, the field that feeds it and, for a Medium or Low guess, the
// reason and a Confirm button. A required slot with nothing mapped carries the error rule and, when the server knows a candidate, says so.
// Confirm is a button only: nothing on the row itself reacts to Enter.
const props = defineProps<{
    item: ChecklistSlot;
    // Why nothing can be changed (read-only, offline, published); undefined when it can.
    blocked?: string;
    reasonId?: string;
    // A write is in flight: Confirm waits.
    busy?: boolean;
    // Lists the fields the slot could take (the picker and a Type-mismatch row's compatible fields).
    loadFields?: (slot: string) => Promise<PickerField[]>;
    // What the last override did to this slot, while it can still be undone.
    note?: OverrideNote | null;
    // The API change under this slot: the message, and the candidate it offers (never applied by itself).
    changeText?: string | null;
    match?: { path: string; display: string } | null;
    matchText?: string | null;
}>();

const emit = defineEmits<{
    (e: 'confirm', slot: string): void;
    (e: 'pick', slot: string, path: string | null): void;
    (e: 'undo', slot: string): void;
    (e: 'treat', slot: string, path: string, type: FieldType | null): void;
}>();

const picker = ref<InstanceType<typeof FieldPicker> | null>(null);
// A read-only view (read-only viewer, offline, published): nothing that changes the mapping is drawn.
const editable = computed(() => props.blocked === undefined);
const fits = ref<PickerField[]>([]);
// The mismatched field's own effective type, for the Treat as menu.
const fieldType = ref<string | null>(null);
let reading = 0;

const level = computed(() => confidenceOf(props.item));
// Only a guess carries a confidence badge; a decision reads "Confirmed by you" or "Overridden".
const mapped = computed(() =>
    ['auto_high', 'auto_medium', 'auto_low'].includes(props.item.state),
);
const stateName = computed(
    () => labels.stateNames[props.item.state] ?? props.item.state,
);
const levelWord = computed(() => labels.confidenceWords[level.value]);
const name = computed(() =>
    labels.slotRow(
        props.item.label,
        props.item.required,
        stateName.value,
        levelWord.value.toLowerCase(),
    ),
);
const showReason = computed(
    () =>
        ['auto_medium', 'auto_low', 'type_mismatch'].includes(
            props.item.state,
        ) && !!props.item.reason,
);
const flagged = computed(
    () =>
        props.item.state === 'required_missing' ||
        props.item.state === 'type_mismatch',
);
const suggestion = computed(() =>
    props.item.state === 'required_missing' ? props.item.suggestion : null,
);
const mismatch = computed(() => props.item.state === 'type_mismatch');
const list = (): Promise<PickerField[]> =>
    props.loadFields?.(props.item.slot) ?? Promise.resolve([]);

// A Type-mismatch row lists the fields that would fit (a read; it writes nothing). A late answer to an earlier read is dropped; a failed read
// leaves the area hidden.
watch(
    () => [mismatch.value, editable.value, props.item.path] as const,
    async ([isMismatch, canEdit, path]) => {
        const mine = (reading += 1);

        if (!isMismatch || !canEdit || props.loadFields === undefined) {
            fits.value = [];
            fieldType.value = null;

            return;
        }

        try {
            const all = await list();

            if (mine === reading) {
                fits.value = compatibleFields(all).slice(0, 5);
                fieldType.value =
                    all.find((f) => f.path === path)?.effective_type ?? null;
            }
        } catch {
            if (mine === reading) {
                fits.value = [];
                fieldType.value = null;
            }
        }
    },
    { immediate: true },
);

function pick(field: PickerField): void {
    emit('pick', props.item.slot, field.path);
}

defineExpose({ focusPicker: () => picker.value?.focus() });
</script>

<template>
    <li
        :id="`slot-row-${item.slot}`"
        tabindex="-1"
        role="group"
        :aria-label="name"
        :data-slot-key="item.slot"
        :data-state="item.state"
        data-test="slot-row"
        class="flex flex-col gap-1.5 rounded-md border border-border-default bg-surface-card px-3 py-2 focus-visible:ring-2 focus-visible:ring-accent-ink-strong focus-visible:outline-none"
        :class="flagged ? 'border-l-4 border-l-error bg-error-soft' : ''"
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span class="type-body-md font-semibold text-text-primary">
                {{ item.label }}
                <span
                    v-if="item.required"
                    class="text-error-text"
                    aria-hidden="true"
                    data-test="required-mark"
                    >{{ labels.requiredMark }}</span
                >
            </span>
            <span
                v-if="mismatch"
                class="slot-pill-error type-caption rounded-sm border border-error-border bg-error-soft px-2 font-semibold text-error-text"
                data-test="slot-state"
                >{{ labels.typeMismatchPill }}</span
            >
            <span
                v-else
                class="type-caption rounded-sm bg-surface-muted px-2 text-text-secondary"
                data-test="slot-state"
                >{{ stateName }}</span
            >
            <span
                v-if="mapped"
                class="type-caption inline-flex items-center gap-1.5 text-text-secondary"
                data-test="slot-confidence"
            >
                <ConfidenceMeter :level="level" />
                {{ labels.confidenceBadge(levelWord) }}
            </span>
            <span
                v-if="item.state === 'confirmed'"
                class="type-caption font-semibold text-success-text"
                data-test="confirmed-by-you"
                >{{ labels.confirmedByYou }}</span
            >
        </div>

        <p class="type-body-sm text-text-secondary" data-test="slot-field">
            <template v-if="item.display">
                <span class="font-mono text-text-primary">{{
                    item.display
                }}</span>
                <span v-if="item.example" class="text-text-muted">
                    · {{ labels.exampleShort(item.example) }}</span
                >
            </template>
            <template v-else>{{ labels.noField }}</template>
        </p>

        <p
            v-if="showReason"
            class="type-caption text-text-secondary"
            data-test="slot-reason"
        >
            {{ item.reason }}
        </p>

        <p
            v-if="suggestion"
            class="type-body-sm font-semibold text-error-text"
            data-test="slot-suggestion"
        >
            {{ labels.useAs(suggestion.display, item.label) }}
        </p>

        <p
            v-if="changeText"
            class="type-body-sm text-error-text"
            data-test="slot-api-changed"
        >
            {{ changeText }}
        </p>

        <p
            v-if="matchText && match"
            class="type-body-sm flex flex-wrap items-center gap-2 text-text-secondary"
            data-test="slot-api-match"
        >
            <span>{{ matchText }}</span>
            <Button
                v-if="editable"
                type="button"
                variant="secondary"
                size="sm"
                :blocked="busy === true"
                :aria-busy="busy ? 'true' : undefined"
                :aria-label="labels.useMatchFor(match.display, item.label)"
                data-test="use-match"
                @click="emit('pick', item.slot, match.path)"
                >{{ labels.useField(match.display) }}</Button
            >
        </p>

        <p
            v-if="note"
            class="type-body-sm flex flex-wrap items-center gap-2 text-text-secondary"
            data-test="override-note"
        >
            <span>{{ note.text }}</span>
            <Button
                v-if="editable"
                type="button"
                variant="link"
                :aria-busy="busy ? 'true' : undefined"
                :aria-label="labels.undoFor(item.label)"
                data-test="undo-override"
                @click="emit('undo', item.slot)"
                >{{ labels.undo }}</Button
            >
        </p>

        <div
            v-if="mismatch && editable && fits.length > 0"
            class="flex flex-wrap items-center gap-2"
            role="group"
            :aria-label="labels.fitsHeading"
            data-test="fitting-fields"
        >
            <span class="type-caption text-text-secondary">{{
                labels.fitsHeading
            }}</span>
            <Button
                v-for="field in fits"
                :key="field.path ?? field.display"
                type="button"
                variant="secondary"
                size="sm"
                :blocked="busy === true"
                :aria-label="labels.useFieldFor(field.display, item.label)"
                data-test="fitting-field"
                @click="pick(field)"
                >{{ labels.useField(field.display) }}</Button
            >
        </div>

        <div v-if="editable" class="flex flex-wrap items-center gap-2">
            <FieldPicker
                v-if="loadFields"
                ref="picker"
                :slot-key="item.slot"
                :slot-label="item.label"
                :current="item.path"
                :load="list"
                :busy="busy"
                @pick="pick"
            />
            <TreatAsMenu
                v-if="mismatch && item.path && item.display"
                :field="item.display"
                :current="fieldType"
                @pick="(type) => emit('treat', item.slot, item.path!, type)"
            />
        </div>

        <div v-if="needsReview(item)">
            <Button
                type="button"
                variant="secondary"
                size="sm"
                :blocked="blocked !== undefined || busy === true"
                :blocked-reason="blocked"
                :aria-busy="busy ? 'true' : undefined"
                :aria-describedby="blocked ? reasonId : undefined"
                :aria-label="labels.confirmFor(item.label)"
                data-test="slot-confirm"
                @click="emit('confirm', item.slot)"
                >{{ labels.confirm }}</Button
            >
        </div>
    </li>
</template>
