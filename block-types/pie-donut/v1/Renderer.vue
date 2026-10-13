<script setup lang="ts">
import { computed } from 'vue';
import { measure } from '@block-types/bar/v1/table';
import ChartBlockFrame from '@/charts/ChartBlockFrame.vue';
import type { ChartInput } from '@/charts/model';
import { resolveLocale } from '@/lib/format';
import type { PiePayload } from './payload';

// The pie-donut v1 renderer (Story 5.11): slices clockwise from 12 o'clock with a surface divider, patterns from the third slice, a legend listing
// name, share and value, the centre text of a donut, and the chart Block faces around the plot.
const props = withDefaults(
    defineProps<{
        payload: PiePayload;
        audience?: 'user' | 'admin';
        workspaceLocale?: string | null;
        bare?: boolean;
    }>(),
    { audience: 'user', workspaceLocale: null, bare: false },
);

defineEmits<{ (e: 'retry'): void }>();

const locale = computed(() => resolveLocale(props.workspaceLocale));

const input = computed<ChartInput | null>(() => {
    const names = props.payload.categories ?? [];
    const values = props.payload.values ?? [];

    if (names.length === 0) {
        return null;
    }

    const format = (value: string): string =>
        props.payload.format === undefined
            ? value
            : measure(value, props.payload.format, locale.value) || value;
    const centre = props.payload.centre;

    return {
        kind: 'pie',
        x: names,
        series: [
            {
                key: 'pie_value',
                name: props.payload.valueLabel ?? 'Value',
                values,
            },
        ],
        range: '',
        format,
        compact: format,
        donut: props.payload.kind === 'donut',
        centre:
            centre === null || centre === undefined
                ? null
                : {
                      label: centre.label ?? '',
                      value: centre.value === null ? '' : format(centre.value),
                  },
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
        fallback-title="Pie chart"
        field="Chart data"
        :audience="audience"
        :workspace-locale="workspaceLocale"
        :bare="bare"
        @retry="$emit('retry')"
    />
</template>
