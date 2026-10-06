<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Logo tile and marks follow the brand seam (--df-logo-tile, --df-logo-mark). -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 28 28"
        aria-hidden="true"
        focusable="false"
        :class="cn('size-7 shrink-0', className)"
        v-bind="$attrs"
    >
        <rect width="28" height="28" rx="8" fill="var(--df-logo-tile)" />
        <g fill="var(--df-logo-mark)">
            <rect x="6" y="6" width="7" height="7" rx="1.5" />
            <rect x="15" y="6" width="7" height="7" rx="1.5" />
            <rect x="6" y="15" width="7" height="7" rx="1.5" />
            <rect x="15" y="15" width="7" height="7" rx="1.5" />
        </g>
    </svg>
</template>
