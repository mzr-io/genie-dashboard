<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { SidebarProvider } from '@/components/ui/sidebar';
import { useAddBlocksPanel } from '@/composables/useAddBlocksPanel';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
};

withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});

// The full sidebar shows from 1280px; tablet widths use the 64px icon rail; below 640px the sidebar is an
// overlay sheet (UX-DR-81, DESIGN.md app shell by width).
const wide = useMediaQuery('(min-width: 1280px)');

// An open Add-blocks Panel pushes the dashboard and collapses the sidebar to the icon rail (UX-DR-152); closing it restores the sidebar.
const { open: panelOpen } = useAddBlocksPanel();
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
    <SidebarProvider v-else :open="wide && !panelOpen">
        <slot />
    </SidebarProvider>
</template>
