<script setup lang="ts">
defineProps<{
    message?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p class="text-sm text-error-text">
            {{ message }}
        </p>
    </div>
</template>
