<script setup lang="ts">
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import ConnectionBanner from '@/components/ConnectionBanner.vue';
import SessionExpiryDialog from '@/components/SessionExpiryDialog.vue';
import SkipLink from '@/components/SkipLink.vue';
import ToastRegion from '@/components/ToastRegion.vue';
import { useShell } from '@/composables/useShell';
import { initAnnouncer } from '@/lib/announce';
import { shellLabels } from '@/locales/labels';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
    // The page name labelling `main`; defaults to the last breadcrumb, else the navigation item's title.
    pageName?: string;
};

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
    pageName: undefined,
});

// Live regions exist before the first message (UX-DR-276).
initAnnouncer();

const { current } = useShell();

// A page that sets no breadcrumbs gets the one of its navigation item.
const trail = computed<BreadcrumbItem[]>(() => {
    if (props.breadcrumbs.length > 0) {
        return props.breadcrumbs;
    }

    return current.value
        ? [{ title: current.value.title, href: current.value.href }]
        : [];
});

const label = computed(
    () =>
        props.pageName ??
        trail.value[trail.value.length - 1]?.title ??
        shellLabels.pageContent,
);
</script>

<template>
    <AppShell variant="sidebar">
        <SkipLink />
        <AppSidebar />
        <AppContent variant="sidebar" :label="label" class="overflow-x-clip">
            <template #banner>
                <AppSidebarHeader :breadcrumbs="trail" />
            </template>
            <ConnectionBanner />
            <slot />
        </AppContent>
        <ToastRegion />
        <SessionExpiryDialog />
    </AppShell>
</template>
