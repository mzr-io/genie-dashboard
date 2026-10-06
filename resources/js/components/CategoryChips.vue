<script setup lang="ts">
import { RadioGroup } from '@/components/ui/radio-group';

// The single-select group for CategoryChip: one tab stop, arrows move between chips, and the
// group always has an accessible name.
defineProps<{ modelValue?: string; label: string }>();

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();
</script>

<template>
    <RadioGroup
        :model-value="modelValue"
        :aria-label="label"
        orientation="horizontal"
        class="flex gap-2 overflow-x-auto"
        @update:model-value="emit('update:modelValue', String($event))"
    >
        <slot />
    </RadioGroup>
</template>
