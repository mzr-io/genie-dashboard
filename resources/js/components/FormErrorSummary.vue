<script setup lang="ts">
import { ref, useId } from 'vue';
import { controlLabels as labels } from '@/locales/labels';

// Two or more errors: a summary of links, focused on submit; each link focuses its field
// (UX-DR-274). It is not a live region, so focusing it is what announces it.
export type SummaryItem = { id: string; label: string; message: string };

defineProps<{ items: SummaryItem[] }>();

const root = ref<HTMLElement | null>(null);
const titleId = useId();

function focusField(id: string): void {
    document.getElementById(id)?.focus();
}

defineExpose({ focus: () => root.value?.focus() });
</script>

<template>
    <section
        ref="root"
        tabindex="-1"
        data-slot="form-error-summary"
        :aria-labelledby="titleId"
        class="rounded-md border border-error-border bg-error-soft p-3"
    >
        <h2 :id="titleId" class="type-title-sm text-error-text">
            {{ labels.errorSummaryTitle }}
        </h2>
        <ul class="mt-2 list-disc ps-5">
            <li v-for="item in items" :key="item.id" class="type-body-sm">
                <a
                    :href="`#${item.id}`"
                    class="font-semibold text-error-text underline underline-offset-2"
                    @click.prevent="focusField(item.id)"
                    >{{ item.label }}</a
                >
                <span class="text-error-text">: {{ item.message }}</span>
            </li>
        </ul>
    </section>
</template>
