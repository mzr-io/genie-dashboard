<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { shellLabels } from '@/locales/labels';
</script>

<template>
    <AppLogoIcon />
    <div
        class="ml-1 grid flex-1 text-left group-data-[collapsible=icon]:hidden"
    >
        <span class="type-title-md truncate font-bold text-text-primary">{{
            shellLabels.product
        }}</span>
    </div>
</template>
