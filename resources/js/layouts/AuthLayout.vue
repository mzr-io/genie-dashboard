<script setup lang="ts">
import SkipLink from '@/components/SkipLink.vue';
import ToastRegion from '@/components/ToastRegion.vue';
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';
import { initAnnouncer } from '@/lib/announce';

const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();

initAnnouncer();
</script>

<template>
    <SkipLink />
    <AuthLayout :title="title" :description="description">
        <slot />
    </AuthLayout>
    <ToastRegion />
</template>
