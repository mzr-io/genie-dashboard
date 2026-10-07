<script setup lang="ts">
import { controlLabels as labels } from '@/locales/labels';

// "* Required", once per form (UX-DR-23).
</script>

<template>
    <p data-slot="required-note" class="type-caption text-text-secondary">
        <span aria-hidden="true">*</span> {{ labels.required }}
    </p>
</template>
