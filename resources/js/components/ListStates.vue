<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { shellLabels } from '@/locales/labels';

// The generic states of every list-bearing page (UX-DR-263): a cold load shows a toolbar and five skeleton
// rows; a failed load shows one full-width row with Retry; an empty list shows msg:list-empty.
withDefaults(
    defineProps<{
        state: 'loading' | 'error' | 'empty';
        // What the list holds, lower case and plural ("dashboards").
        items: string;
        // The action that starts the list, as a phrase ("Create a dashboard").
        action: string;
        // Whether the loading state draws its own toolbar skeleton (off when the page keeps its real toolbar).
        toolbar?: boolean;
    }>(),
    { toolbar: true },
);

defineEmits<{ retry: [] }>();

const { t } = useI18n();
const SKELETON_ROWS = 5;
</script>

<template>
    <section
        data-slot="list-states"
        :data-state="state"
        class="rounded-lg border border-border-default bg-surface-card"
    >
        <div
            v-if="state === 'loading'"
            role="status"
            aria-busy="true"
            class="flex flex-col gap-3 p-4"
        >
            <span class="sr-only">{{ shellLabels.loadingItems(items) }}</span>
            <Skeleton
                v-if="toolbar"
                class="h-9 w-full max-w-xs"
                :caption="false"
            />
            <Skeleton
                v-for="row in SKELETON_ROWS"
                :key="row"
                class="h-12 w-full"
                data-slot="skeleton-row"
                :caption="row === 1"
            />
        </div>

        <div
            v-else-if="state === 'error'"
            role="alert"
            data-slot="load-failure"
            class="flex flex-wrap items-center justify-between gap-3 border-l-[3px] border-error bg-error-soft p-4"
        >
            <p class="type-body-sm text-error-text">
                {{ shellLabels.loadFailed(items) }}
            </p>
            <Button
                type="button"
                variant="secondary"
                size="sm"
                data-test="retry"
                @click="$emit('retry')"
            >
                {{ shellLabels.retry }}
            </Button>
        </div>

        <div v-else class="flex flex-col items-center gap-4 p-6">
            <p
                data-slot="list-empty"
                class="type-body-md text-center text-text-secondary"
            >
                {{ t('list-empty', { items, action }) }}
            </p>
            <!-- The page's primary action, where it has one. -->
            <slot />
        </div>
    </section>
</template>
