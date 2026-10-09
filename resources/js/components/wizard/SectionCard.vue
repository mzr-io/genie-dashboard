<script setup lang="ts">
import { useId } from 'vue';
import type { Component } from 'vue';

// A form section card (Story 3.4): a 1 px border, radius lg, 16 px padding, a 28 px accent-soft icon tile, a title and a caption.
defineProps<{ title: string; caption?: string; icon: Component }>();

const titleId = useId();
</script>

<template>
    <section
        :aria-labelledby="titleId"
        data-slot="section-card"
        class="rounded-lg border border-border-default bg-surface-card p-4"
    >
        <header class="mb-4 flex items-start gap-3">
            <span
                aria-hidden="true"
                data-slot="section-icon"
                class="inline-flex size-7 shrink-0 items-center justify-center rounded-md bg-accent-soft text-accent-ink-strong"
                ><component :is="icon" class="size-4"
            /></span>
            <div class="min-w-0">
                <h2 :id="titleId" class="type-title-sm text-text-primary">
                    {{ title }}
                </h2>
                <p v-if="caption" class="type-caption text-text-muted">
                    {{ caption }}
                </p>
            </div>
        </header>
        <div class="grid gap-4">
            <slot />
        </div>
    </section>
</template>
