<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { placeholderLabels as labels } from '@/locales/labels';

// Placeholder until Story 1.18 replaces it with Help & support. Open to guests (the sign-in page links here).
defineOptions({ layout: { title: labels.help, description: '' } });
</script>

<template>
    <Head :title="labels.help" />
</template>
