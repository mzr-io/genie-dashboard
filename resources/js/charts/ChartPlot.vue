<script setup lang="ts">
import { computed, ref, toRef } from 'vue';
import { legendFor } from '@/charts/model';
import type { ChartInput } from '@/charts/model';
import { useChart } from '@/charts/useChart';
import type { ChartInit } from '@/charts/useChart';

// A line or area plot (Story 5.8b). The plot is one `role="img"` tab stop with a generated label; the legend is a list that names marker and dash; the
// focused point is announced politely from a live region outside the image. Block Type Renderers draw their states around it.
const props = defineProps<{
    input: ChartInput;
    // For tests: a chart factory in place of ECharts.
    chart?: ChartInit;
}>();

const host = ref<HTMLElement | null>(null);
const { label, announcement, onKeydown, onBlur } = useChart(
    host,
    toRef(props, 'input'),
    props.chart,
);
const legend = computed(() => legendFor(props.input));
</script>

<template>
    <div class="grid min-w-0 gap-2" data-test="chart">
        <div
            role="img"
            tabindex="0"
            class="min-h-40 w-full rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring"
            :aria-label="label"
            data-test="chart-plot"
            @keydown="onKeydown"
            @blur="onBlur"
        >
            <div
                ref="host"
                class="h-full min-h-40 w-full"
                aria-hidden="true"
                data-test="chart-host"
            ></div>
        </div>
        <ul
            v-if="legend.length > 1 || input.kind === 'pie'"
            class="m-0 flex list-none flex-wrap gap-x-4 gap-y-1 p-0"
            data-test="chart-legend"
        >
            <li
                v-for="entry in legend"
                :key="entry.key"
                class="type-caption text-text-secondary"
            >
                <span aria-hidden="true">{{ entry.glyph }}</span>
                {{ entry.text }}
            </li>
        </ul>
        <p class="sr-only" aria-live="polite" data-test="chart-announce">
            {{ announcement }}
        </p>
    </div>
</template>
