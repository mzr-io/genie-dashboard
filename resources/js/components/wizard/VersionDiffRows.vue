<script setup lang="ts">
import { computed } from 'vue';
import { DIFF_SECTIONS } from '@/lib/versionDiff';
import type { VersionDiff } from '@/lib/versionDiff';
import { versionDiffLabels as labels } from '@/locales/labels';

// "What changed" (Story 6.3; UX-DR-76): the four sections, each as before and after rows. The before text is muted and struck through, the after text is
// primary, and each carries "was" or "now" for a screen reader, so the change is never a colour alone. A section with no change says so.
const props = defineProps<{ diff: VersionDiff }>();

const sections = computed(() =>
    DIFF_SECTIONS.map((section) => ({
        key: section,
        title: labels.sections[section],
        changes: props.diff.sections[section] ?? [],
    })),
);
</script>

<template>
    <div class="grid gap-4" data-test="version-diff">
        <section
            v-for="section in sections"
            :key="section.key"
            class="grid gap-1.5"
            :aria-label="section.title"
            :data-test="`diff-${section.key}`"
        >
            <h4 class="type-label-caps text-text-muted">
                {{ section.title }}
            </h4>
            <p
                v-if="section.changes.length === 0"
                class="type-body-sm text-text-secondary"
                data-test="diff-none"
            >
                {{ labels.none }}
            </p>
            <ul v-else class="grid gap-1.5">
                <li
                    v-for="change in section.changes"
                    :key="change.path"
                    class="type-body-sm grid gap-0.5"
                    data-test="diff-row"
                >
                    <span class="font-medium text-text-primary">{{
                        change.path
                    }}</span>
                    <span class="flex flex-wrap items-baseline gap-x-2">
                        <span class="sr-only">{{ labels.was }}</span>
                        <del
                            class="break-all text-text-muted line-through"
                            data-test="diff-before"
                            >{{ change.before ?? labels.noValue }}</del
                        >
                        <span aria-hidden="true">→</span>
                        <span class="sr-only">{{ labels.now }}</span>
                        <ins
                            class="break-all text-text-primary no-underline"
                            data-test="diff-after"
                            >{{ change.after ?? labels.noValue }}</ins
                        >
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
