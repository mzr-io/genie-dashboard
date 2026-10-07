<script setup lang="ts">
// The page header (UX-DR-86): the title in headline-md, the subtitle in caption text-muted and, on the
// right, the page's primary action. The title is the page's `h1`.
defineProps<{
    title: string;
    subtitle?: string;
}>();
</script>

<template>
    <div
        data-slot="page-header"
        class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3"
    >
        <div class="min-w-0">
            <h1 class="type-headline-md text-text-primary">{{ title }}</h1>
            <p
                v-if="subtitle"
                class="type-caption mt-1 text-text-muted"
                data-slot="page-subtitle"
            >
                {{ subtitle }}
            </p>
        </div>
        <div v-if="$slots.default" class="flex shrink-0 items-center gap-2">
            <slot />
        </div>
    </div>
</template>
