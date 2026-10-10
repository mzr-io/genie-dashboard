<script setup lang="ts">
import { computed } from 'vue';
import ConfidenceMeter from '@/components/wizard/ConfidenceMeter.vue';
import { Button } from '@/components/ui/button';
import { confidenceOf, needsReview } from '@/lib/blockMapping';
import type { ChecklistSlot } from '@/lib/blockMapping';
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
}>();

const emit = defineEmits<{ (e: 'confirm', slot: string): void }>();

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
