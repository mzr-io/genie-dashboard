<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { placeholderLabels as labels } from '@/locales/labels';

// Placeholder until Story 1.16 (shell) and Story 1.19 (Admin gating) replace it.
defineOptions({
    layout: { breadcrumbs: [{ title: labels.adminOverview, href: '/admin' }] },
});
</script>

<template>
    <Head :title="labels.adminOverview" />

    <div class="p-4">
        <h1 class="type-headline-lg text-text-primary">
            {{ labels.adminOverview }}
        </h1>
    </div>
</template>
