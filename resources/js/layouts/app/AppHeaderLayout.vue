<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
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

initAnnouncer();
</script>

<template>
    <AppShell variant="header">
        <SkipLink />
        <AppHeader :breadcrumbs="breadcrumbs" />
        <AppContent variant="header">
            <ConnectionBanner />
            <slot />
        </AppContent>
        <ToastRegion />
    </AppShell>
</template>
