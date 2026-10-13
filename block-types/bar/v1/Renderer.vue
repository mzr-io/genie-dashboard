<script setup lang="ts">
import { computed } from 'vue';
import ChartBlockFrame from '@/charts/ChartBlockFrame.vue';
import type { ChartInput } from '@/charts/model';
import { formatNumber, resolveLocale } from '@/lib/format';
import { measure } from './table';
import type { BarPayload } from './payload';

// The bar v1 renderer (Story 5.11): grouped or stacked, across or down, with each series told apart by fill pattern from the third on, the stack total
// above a stack, and the chart Block faces around the plot. Values are formatted in the browser from the decimal strings.
const props = withDefaults(
    defineProps<{
        payload: BarPayload;
        audience?: 'user' | 'admin';
        workspaceLocale?: string | null;
        bare?: boolean;
    }>(),
    { audience: 'user', workspaceLocale: null, bare: false },
);

defineEmits<{ (e: 'retry'): void }>();

const locale = computed(() => resolveLocale(props.workspaceLocale));
const categories = computed(() => props.payload.categories ?? []);
const series = computed(() => props.payload.series ?? []);

const input = computed<ChartInput | null>(() => {
    if (series.value.length === 0 || categories.value.length === 0) {
        return null;
    }

    const format = props.payload.format;

    return {
        kind: 'bar',
        x: categories.value,
        series: series.value,
        range:
            categories.value.length === 1
                ? categories.value[0]
                : `${categories.value[0]} to ${categories.value[categories.value.length - 1]}`,
        format: (value) =>
            format === undefined
                ? value
                : measure(value, format, locale.value) || value,
        compact: (value) =>
            formatNumber(
                value,
                { notation: 'compact', maximumFractionDigits: 1 },
                locale.value,
            ),
        stacked: props.payload.kind === 'stacked',
        horizontal: props.payload.orientation === 'horizontal',
        valueLabels: props.payload.valueLabels === true,
        totals: props.payload.totals,
    };
});
</script>

<template>
    <ChartBlockFrame
        :state="payload.state"
        :title="payload.title"
        :subtitle="payload.subtitle"
        :footer-action="payload.footerAction"
        :partial="payload.partial === true"
        :stale-since="payload.staleSince"
        :error-message="payload.errorMessage"
        :empty-display="payload.emptyDisplay"
        :input="input"
        fallback-title="Bar chart"
        field="Chart data"
        :audience="audience"
        :workspace-locale="workspaceLocale"
        :bare="bare"
        @retry="$emit('retry')"
    />
</template>
