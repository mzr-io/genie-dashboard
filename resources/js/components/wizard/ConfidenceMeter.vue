<script setup lang="ts">
import { computed } from 'vue';

// A three-segment confidence meter (Story 3.12b). Decorative: the confidence word printed beside it carries the meaning (never colour alone).
const props = defineProps<{ level: 'high' | 'medium' | 'low' | 'none' }>();

const FILLED = { high: 3, medium: 2, low: 1, none: 0 } as const;
const TONE = {
    high: 'bg-success',
    medium: 'bg-brand',
    low: 'bg-warning-bar',
    none: 'bg-surface-muted',
} as const;

const filled = computed(() => FILLED[props.level]);
</script>

<template>
    <span
        class="inline-flex gap-0.5"
        aria-hidden="true"
        data-test="confidence-meter"
        :data-level="level"
    >
        <span
            v-for="n in 3"
            :key="n"
            class="h-1.5 w-3 rounded-full"
            :class="n <= filled ? TONE[level] : 'bg-surface-muted'"
        />
    </span>
</template>
