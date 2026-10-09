<script setup lang="ts">
// Minimal kpi-card v1 renderer stub. Text is only ever interpolated (never raw HTML); the states and formatting come in Story 3.2.
defineProps<{ label?: string; value?: string }>();
</script>

<template>
    <div class="kpi-card">
        <p>{{ label }}</p>
        <p>{{ value }}</p>
    </div>
</template>
