<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

// A native `select` styled like `Input` (UX-DR-23). It stays native because the time zone list has several
// hundred entries, which a native control handles best on every device. The id and the aria attributes come
// from `FormField`'s slot (`v-bind="field"`).
defineOptions({ inheritAttrs: false });

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
const model = defineModel<string>();
</script>

<template>
    <select
        v-model="model"
        data-slot="native-select"
        v-bind="$attrs"
        :class="
            cn(
                'min-h-9 w-full min-w-0 rounded-md border border-border-control bg-surface-card px-3 py-1 text-sm text-text-primary transition-[color,box-shadow] disabled:cursor-not-allowed disabled:opacity-45',
                'focus:border-brand focus:shadow-[0_0_0_3px_var(--df-accent-soft)]',
                'aria-invalid:border-error',
                props.class,
            )
        "
    >
        <slot />
    </select>
</template>
