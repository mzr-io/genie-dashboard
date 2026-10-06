<script setup lang="ts">
import { RadioGroupItem } from 'reka-ui';

// One chip of a single-select group: put chips inside CategoryChips so the group
// has one tab stop and arrow keys move between them (UX-DR-31). The selected chip gains a
// check, a heavier border and weight (see .cue-chip), never colour alone. Focus scrolls the
// chip into view.
defineProps<{ value: string }>();

function reveal(event: FocusEvent): void {
    (event.target as HTMLElement).scrollIntoView?.({
        block: 'nearest',
        inline: 'nearest',
    });
}
</script>

<template>
    <RadioGroupItem
        :value="value"
        data-slot="chip"
        class="cue-chip type-caption inline-flex min-h-(--df-target-chrome) shrink-0 items-center rounded-full border border-border-default bg-surface-card px-3 whitespace-nowrap text-text-secondary aria-checked:border-accent-ink-strong aria-checked:bg-accent-soft aria-checked:font-bold aria-checked:text-accent-ink-strong"
        @focus="reveal"
    >
        <slot />
    </RadioGroupItem>
</template>
