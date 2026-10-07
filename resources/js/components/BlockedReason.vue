<script setup lang="ts">
// The inline reason for a blocked control, at full opacity beside it (UX-DR-22). Point the
// control's aria-describedby at `id`.
defineProps<{ id: string }>();
</script>

<template>
    <p
        :id="id"
        data-slot="blocked-reason"
        class="type-caption text-text-secondary"
    >
        <slot />
    </p>
</template>
