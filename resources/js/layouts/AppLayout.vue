<script setup lang="ts">
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [], pageName = undefined } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
    pageName?: string;
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs" :page-name="pageName">
        <slot />
    </AppLayout>
</template>
