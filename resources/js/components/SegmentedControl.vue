<script setup lang="ts">
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui';

// radiogroup with arrow keys and one tab stop (UX-DR-30). The active segment is marked by
// fill, border and weight (see .cue-segmented), never by colour alone.
export type SegmentOption = { value: string; label: string };

defineProps<{
    modelValue?: string;
    options: SegmentOption[];
    label: string;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();
</script>

<template>
    <RadioGroupRoot
        :model-value="modelValue"
        orientation="horizontal"
        :aria-label="label"
        data-slot="segmented-control"
        class="cue-segmented inline-flex gap-0.5 p-0.5"
        @update:model-value="emit('update:modelValue', String($event))"
    >
        <RadioGroupItem
            v-for="option in options"
            :key="option.value"
            :value="option.value"
            data-slot="segmented-item"
            class="type-caption min-h-(--df-target-chrome) rounded-md border border-transparent px-3 text-text-secondary aria-checked:border-border-control aria-checked:bg-surface-card aria-checked:font-semibold aria-checked:text-text-primary"
        >
            {{ option.label }}
        </RadioGroupItem>
    </RadioGroupRoot>
</template>
