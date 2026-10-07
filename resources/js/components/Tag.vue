<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

// Never interactive, always text (UX-DR-37).
type Props = {
    tone?: 'neutral' | 'info';
    class?: HTMLAttributes['class'];
};

const props = withDefaults(defineProps<Props>(), { tone: 'neutral' });
</script>

<template>
    <span
        data-slot="tag"
        :class="
            cn(
                'type-caption inline-flex w-fit items-center rounded-sm px-2 whitespace-nowrap',
                props.tone === 'info'
                    ? 'bg-info-soft text-info-strong'
                    : 'bg-surface-muted text-text-secondary',
                props.class,
            )
        "
    >
        <slot />
    </span>
</template>
