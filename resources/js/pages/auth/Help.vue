<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import HelpContent from '@/components/HelpContent.vue';
import { helpLabels as labels } from '@/locales/labels';

// Help & support for a person who is not signed in (the sign-in page links here). A guest has no Workspace,
// so there are no links and the contact line is plain text.
defineOptions({ layout: { title: labels.title, description: '' } });

defineProps<{
    helpLinks: { label: string; url: string }[];
    contactHref: string | null;
}>();
</script>

<template>
    <Head :title="labels.title" />

    <HelpContent :help-links="helpLinks" :contact-href="contactHref" />
</template>
