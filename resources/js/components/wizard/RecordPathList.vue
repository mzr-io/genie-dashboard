<script setup lang="ts">
import { useId } from 'vue';
import Tag from '@/components/Tag.vue';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import type { RecordCandidate } from '@/lib/blockMapping';
import { blockMapLabels as labels } from '@/locales/labels';

// "Rows from (record path)" (`record-path-list`, UX-DR-123): shown when the response has more than one array, or nesting deeper than 2 levels. A
// `radiogroup` of the candidate arrays, each with its path in mono, "12 rows · 5 columns", a "Suggested" badge on the one with most rows and
// "looks like pagination, not data" on a links or paging array. The badges are text, never colour alone.
const props = defineProps<{
    candidates: RecordCandidate[];
    value: string | null;
    disabled: boolean;
    reasonId?: string;
}>();

const emit = defineEmits<{ (e: 'change', path: string): void }>();

const groupId = useId();
const hintId = useId();

function onChange(next: unknown): void {
    if (typeof next === 'string' && next !== props.value) {
        emit('change', next);
    }
}
</script>

<template>
    <fieldset class="m-0 grid gap-2 border-0 p-0" data-test="record-path">
        <legend :id="groupId" class="type-title-sm text-text-primary">
            {{ labels.recordPath }}
        </legend>
        <p :id="hintId" class="type-caption text-text-secondary">
            {{ labels.recordPathHint }}
        </p>
        <RadioGroup
            :model-value="value ?? undefined"
            :disabled="disabled"
            :aria-labelledby="groupId"
            :aria-describedby="disabled && reasonId ? reasonId : hintId"
            class="grid gap-2"
            data-test="record-path-group"
            @update:model-value="onChange"
        >
            <label
                v-for="(candidate, index) in candidates"
                :key="candidate.path"
                :for="`${groupId}-${index}`"
                data-test="record-path-option"
                :data-path="candidate.path"
                class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-border-default bg-surface-card px-3 py-2 has-focus-visible:ring-2 has-focus-visible:ring-accent-ink-strong has-disabled:cursor-not-allowed has-data-[state=checked]:border-accent-ink-strong has-data-[state=checked]:bg-accent-soft"
            >
                <RadioGroupItem
                    :id="`${groupId}-${index}`"
                    :value="candidate.path"
                />
                <span
                    class="type-body-sm font-mono text-text-primary"
                    data-test="record-path-display"
                    >{{ candidate.display }}</span
                >
                <span class="type-caption text-text-muted">{{
                    labels.rowsColumns(candidate.rows, candidate.columns.length)
                }}</span>
                <span
                    v-if="candidate.suggested"
                    class="type-caption rounded-sm bg-success-soft px-2 text-success-text"
                    data-test="record-path-suggested"
                    >{{ labels.suggested }}</span
                >
                <Tag
                    v-if="candidate.pagination_like"
                    data-test="record-path-pagination"
                    >{{ labels.paginationLike }}</Tag
                >
            </label>
        </RadioGroup>
    </fieldset>
</template>
