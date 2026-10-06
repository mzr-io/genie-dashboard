<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import ConnectionBanner from '@/components/ConnectionBanner.vue';
import SkipLink from '@/components/SkipLink.vue';
import ToastRegion from '@/components/ToastRegion.vue';
import { initAnnouncer } from '@/lib/announce';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// Live regions exist before the first message (UX-DR-276).
initAnnouncer();
</script>

<template>
    <AppShell variant="sidebar">
        <SkipLink />
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <ConnectionBanner />
            <slot />
        </AppContent>
        <ToastRegion />
    </AppShell>
</template>
