<script setup lang="ts">
import { computed, useId } from 'vue';
import Tag from '@/components/Tag.vue';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import type { DatasetSuggestion, RecordCandidate } from '@/lib/blockMapping';
import { blockMapLabels as labels } from '@/locales/labels';

// "Rows from (record path)" (`record-path-list`, UX-DR-123): shown when the response has more than one array, or nesting deeper than 2 levels. A
// `radiogroup` of the candidate arrays, each with its path in mono, "12 rows · 5 columns", a "Suggested" badge on the one with most rows and
// "looks like pagination, not data" on a links or paging array. The badges are text, never colour alone.
const props = defineProps<{
    candidates: RecordCandidate[];
    value: string | null;
    disabled: boolean;
    reasonId?: string;
    // The Datasets catalog's suggestion (Story 3.11d): marked, never selected.
    suggestion?: Pick<
        DatasetSuggestion,
        'record_path' | 'record_path_display'
    > | null;
}>();

const emit = defineEmits<{ (e: 'change', path: string): void }>();

const groupId = useId();
const hintId = useId();

// A record path with or without its trailing `[*]` names the same array.
const bare = (path: string): string => path.replace(/\[\*\]$/, '');
// The server returns a suggestion only while the Draft has no chosen record path, so its presence is the signal.
const offered = computed(() => props.suggestion ?? null);
const remembered = (path: string): boolean =>
    offered.value !== null && bare(offered.value.record_path) === bare(path);
const noteNeeded = computed(
    () =>
        offered.value !== null &&
        !props.candidates.some((candidate) => remembered(candidate.path)),
);

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
        <p
            v-if="noteNeeded && offered"
            class="type-caption text-text-secondary"
            data-test="record-path-earlier-note"
        >
            {{ labels.datasetNotCandidate(offered.record_path_display) }}
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
                    v-if="remembered(candidate.path)"
                    tone="info"
                    data-test="record-path-earlier"
                    >{{ labels.datasetUsedEarlier }}</Tag
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
