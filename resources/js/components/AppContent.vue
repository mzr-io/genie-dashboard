<script setup lang="ts">
import { computed } from 'vue';
import { SidebarInset } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
    class?: string;
    // The page name: it labels the `main` landmark (UX-DR-270).
    label?: string;
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});
const className = computed(() => props.class);
</script>

<template>
    <!-- The sidebar layout puts the banner and `main` side by side in the column; `main` is the page itself. -->
    <SidebarInset v-if="props.variant === 'sidebar'" :class="className">
        <slot name="banner" />
        <main
            id="main-content"
            tabindex="-1"
            :aria-label="label"
            class="flex min-w-0 flex-1 flex-col outline-none"
        >
            <slot />
        </main>
    </SidebarInset>
    <main
        v-else
        id="main-content"
        tabindex="-1"
        :aria-label="label"
        class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl"
        :class="className"
    >
        <slot />
    </main>
</template>
