<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import HelpContent from '@/components/HelpContent.vue';
import PageHeader from '@/components/PageHeader.vue';
import { helpLabels as labels } from '@/locales/labels';

// Help & support inside the shell (Story 1.18). The links are read-only here; the Admin edits them in
// System settings (Epic 8).
defineProps<{
    helpLinks: { label: string; url: string }[];
    contactHref: string | null;
}>();
</script>

<template>
    <Head :title="labels.title" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader :title="labels.title" />
        <HelpContent :help-links="helpLinks" :contact-href="contactHref" />
    </div>
</template>
