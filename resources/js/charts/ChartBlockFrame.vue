<script setup lang="ts">
import { Clock, OctagonX, TriangleAlert } from '@lucide/vue';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import ChartPlot from '@/charts/ChartPlot.vue';
import type { ChartInput } from '@/charts/model';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDate, formatDateTime, resolveLocale } from '@/lib/format';
import { lineAreaLabels, shellLabels } from '@/locales/labels';

// The faces of a chart Block (Story 5.11), shared by the bar and the pie Block Types: Loading (skeleton bars), Empty (msg:block-empty), Stale
// (the stale message with the time), Unavailable (no zero line), Error with Retry, the partial-data badge, and the plot between. Every text is literal.
const props = withDefaults(
    defineProps<{
        state:
            | 'normal'
            | 'loading'
            | 'empty'
            | 'stale'
            | 'unavailable'
            | 'error';
        title: string | null;
        subtitle: string | null;
        footerAction: string | null;
        partial?: boolean;
        staleSince: string | null;
        errorMessage: string | null;
        emptyDisplay?: string | null;
        // What is drawn; null when there is nothing to draw.
        input: ChartInput | null;
        fallbackTitle: string;
        // The name an Admin is told is missing when the chart is unavailable.
        field: string;
        audience?: 'user' | 'admin';
        workspaceLocale?: string | null;
        bare?: boolean;
    }>(),
    {
        partial: false,
        emptyDisplay: null,
        audience: 'user',
        workspaceLocale: null,
        bare: false,
    },
);

defineEmits<{ (e: 'retry'): void }>();

const { t } = useI18n();
const titleId = useId();
const locale = computed(() => resolveLocale(props.workspaceLocale));
const heading = computed(() => props.title ?? props.fallbackTitle);
const plotted = computed(
    () =>
        (props.state === 'normal' || props.state === 'stale') &&
        props.input !== null &&
        props.input.x.length > 0 &&
        props.input.series.length > 0,
);

const staleMessage = computed(() => {
    const since = props.staleSince;

    if (since === null || formatDate(since, {}, locale.value) === '') {
        return lineAreaLabels.staleGeneric;
    }

    const time = formatDateTime(since, { timeStyle: 'short' }, locale.value);
    const day = formatDate(since, { dateStyle: 'medium' }, locale.value);
    const today = formatDate(new Date(), { dateStyle: 'medium' }, locale.value);

    return day === today
        ? t('stale.today', { time })
        : t('stale.earlier', { day, time });
});
</script>

<template>
    <article
        class="chart-block flex min-h-0 flex-col gap-2"
        :class="
            bare
                ? ''
                : 'rounded-md border border-border-default bg-surface-card p-4'
        "
        :data-state="state"
        data-test="chart-block"
    >
        <header v-if="!bare" class="grid gap-0.5">
            <h3
                :id="titleId"
                class="type-title-sm break-words text-text-primary"
                data-test="chart-title"
            >
                {{ heading }}
            </h3>
            <p
                v-if="subtitle"
                class="type-caption break-words text-text-secondary"
                data-test="chart-subtitle"
            >
                {{ subtitle }}
            </p>
        </header>

        <div class="grid min-h-0 gap-2" data-test="chart-body">
            <div
                v-if="state === 'loading'"
                class="flex h-40 items-end gap-2"
                role="status"
                data-test="chart-loading"
            >
                <Skeleton class="h-1/3 w-full" :caption="false" />
                <Skeleton class="h-2/3 w-full" :caption="false" />
                <Skeleton class="h-1/2 w-full" :caption="false" />
                <Skeleton class="h-5/6 w-full" />
            </div>

            <div
                v-else-if="state === 'error'"
                class="flex flex-wrap items-center gap-2 text-error-text"
                data-test="chart-error"
            >
                <OctagonX class="size-4 shrink-0" aria-hidden="true" />
                <p class="type-body-sm break-words" role="alert">
                    {{ errorMessage ?? lineAreaLabels.errorMessage }}
                </p>
                <button
                    type="button"
                    class="type-body-sm text-accent-ink underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    data-test="chart-retry"
                    @click="$emit('retry')"
                >
                    {{ shellLabels.retry }}
                </button>
            </div>

            <p
                v-else-if="state === 'empty'"
                class="type-body-sm py-4 text-center text-text-muted"
                data-test="chart-empty"
            >
                {{ emptyDisplay ?? t('block-empty') }}
            </p>

            <p
                v-else-if="state === 'unavailable' || !plotted"
                class="type-body-sm flex items-start gap-2 text-warning"
                data-test="chart-unavailable"
            >
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>{{
                    audience === 'admin'
                        ? t('unavailable-admin', { field })
                        : t('unavailable-user')
                }}</span>
            </p>

            <template v-else-if="input !== null">
                <ChartPlot :input="input" />
                <p
                    v-if="partial"
                    class="type-caption w-fit rounded-sm bg-surface-muted px-2 py-0.5 text-text-primary"
                    data-test="chart-partial"
                >
                    {{ lineAreaLabels.partial }}
                </p>
                <p
                    v-if="state === 'stale'"
                    class="type-caption flex items-center gap-1 text-warning"
                    data-test="chart-stale"
                >
                    <Clock class="size-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ staleMessage }}</span>
                </p>
            </template>

            <p
                v-if="footerAction"
                class="type-caption break-words text-text-secondary"
                data-test="chart-footer"
            >
                {{ footerAction }}
            </p>
        </div>
    </article>
</template>
