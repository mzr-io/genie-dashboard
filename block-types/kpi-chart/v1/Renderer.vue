<script setup lang="ts">
import KpiCardRenderer from '@block-types/kpi-card/v1/Renderer.vue';
import LineAreaRenderer from '@block-types/line-area/v1/Renderer.vue';
import { computed, useId } from 'vue';
import { lineAreaLabels } from '@/locales/labels';
import type { KpiChartPayload } from './payload';

// The kpi-chart v1 renderer (Story 5.10): the card's own Renderer for the headline, its comparison, label, note and badge, and the chart's Renderer
// for the plot, side by side on a wide Block and stacked on a narrow one (a container query, so the preview's device widths and a resized Block
// reflow alike) with nothing hidden. The chart has its own empty and unavailable faces: the headline renders over an empty chart, and no zero is drawn.
const props = withDefaults(
    defineProps<{
        payload: KpiChartPayload;
        audience?: 'user' | 'admin';
        workspaceLocale?: string | null;
        bare?: boolean;
    }>(),
    { audience: 'user', workspaceLocale: null, bare: false },
);

defineEmits<{ (e: 'retry'): void }>();

const titleId = useId();
const heading = computed(
    () =>
        props.payload.title ??
        props.payload.kpiLabel ??
        lineAreaLabels.kpiChartFallbackTitle,
);

// The chart takes the Block's own state when the whole Block is loading or failed; otherwise its own.
const chart = computed(() => {
    const own = props.payload.chart;
    const state = props.payload.state;

    return {
        state:
            state === 'loading' || state === 'error'
                ? state
                : own?.state === 'normal' && state === 'stale'
                  ? 'stale'
                  : (own?.state ?? 'unavailable'),
        kind: own?.kind ?? 'line',
        title: null,
        subtitle: null,
        icon: null,
        footerAction: null,
        x: own?.x ?? [],
        xLabel: own?.xLabel ?? null,
        xPattern: own?.xPattern ?? null,
        series: own?.series ?? [],
        partial: own?.partial ?? false,
        highlight: own?.highlight ?? null,
        target: own?.target ?? null,
        format: own?.format,
        directLabels: own?.directLabels ?? true,
        emptyDisplay: null,
        staleSince: props.payload.staleSince,
        errorMessage: props.payload.errorMessage,
    };
});
</script>

<template>
    <article
        class="kpi-chart @container flex min-h-0 flex-col gap-2"
        :class="
            bare
                ? ''
                : 'rounded-md border border-border-default bg-surface-card p-4'
        "
        :data-state="payload.state"
        data-test="kpi-chart"
    >
        <header v-if="!bare" class="grid gap-0.5">
            <h3
                :id="titleId"
                class="type-title-sm break-words text-text-primary"
                data-test="kpi-chart-title"
            >
                {{ heading }}
            </h3>
            <p
                v-if="payload.subtitle"
                class="type-caption break-words text-text-secondary"
                data-test="kpi-chart-subtitle"
            >
                {{ payload.subtitle }}
            </p>
        </header>

        <div
            class="grid min-h-0 gap-4 @2xl:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]"
            data-test="kpi-chart-layout"
        >
            <KpiCardRenderer
                :payload="payload"
                :audience="audience"
                :workspace-locale="workspaceLocale"
                bare
                data-test="kpi-chart-kpi"
                @retry="$emit('retry')"
            />
            <LineAreaRenderer
                :payload="chart as never"
                :audience="audience"
                :workspace-locale="workspaceLocale"
                bare
                data-test="kpi-chart-chart"
                @retry="$emit('retry')"
            />
        </div>
    </article>
</template>
