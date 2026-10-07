<script setup lang="ts">
import { useUserPreferences } from '@/composables/useUserPreferences';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [], pageName = undefined } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
    pageName?: string;
}>();

// The saved locale, time zone and Keyboard shortcuts switch apply to every signed-in page.
useUserPreferences();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :page-name="pageName">
        <slot />
    </AppLayout>
</template>
