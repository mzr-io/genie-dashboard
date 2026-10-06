<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

// Decorative: the word or number beside it is the value (UX-DR-279), so it is aria-hidden.
type Props = {
    tone?: 'current' | 'success' | 'warning' | 'error' | 'info' | 'neutral';
    class?: HTMLAttributes['class'];
};

const props = withDefaults(defineProps<Props>(), { tone: 'current' });

const tones = {
    current: 'bg-current',
    success: 'bg-success',
    warning: 'bg-warning',
    error: 'bg-error',
    info: 'bg-info',
    neutral: 'bg-text-muted',
} as const;
</script>

<template>
    <span
        aria-hidden="true"
        data-slot="status-dot"
        :class="
            cn(
                'inline-block size-1.5 shrink-0 rounded-full',
                tones[props.tone],
                props.class,
            )
        "
    />
</template>
