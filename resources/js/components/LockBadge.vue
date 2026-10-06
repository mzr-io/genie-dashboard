<script setup lang="ts">
import { Lock } from '@lucide/vue';
import { controlLabels as labels } from '@/locales/labels';

// "Locked", or the catalogue `locked` message passed in as `label`. The text is always present
// and the badge is announced as locked by the admin and not removable (UX-DR-46).
defineProps<{ label?: string }>();
</script>

<template>
    <span
        data-slot="lock-badge"
        class="type-caption inline-flex w-fit items-center gap-1 rounded-full bg-surface-muted px-2 font-semibold whitespace-nowrap text-text-secondary"
    >
        <Lock class="size-3" aria-hidden="true" />
        <span>{{ label ?? labels.locked }}</span>
        <span class="sr-only">, {{ labels.lockedAnnounce }}</span>
    </span>
</template>
