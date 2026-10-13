import { LineChart } from 'echarts/charts';
import {
    GridComponent,
    MarkLineComponent,
    MarkPointComponent,
    TooltipComponent,
} from 'echarts/components';
import { format, init, use } from 'echarts/core';
import { LegacyGridContainLabel } from 'echarts/features';
import { SVGRenderer } from 'echarts/renderers';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { announce, chartLabel, chartOption, reduceKey } from '@/charts/model';
import type { ChartInput, ChartPalette, FocusState } from '@/charts/model';

// The shared chart wrapper (Story 5.8b): ECharts behind one composable so every chart Block Type gets the same safe text (the library's `encodeHTML`
// on every piece of the tooltip, and no formatter string anywhere), the same styles, the same label and the same keyboard model. The Renderer gives
// it decimal strings and formatted text through `ChartInput`; the plot is drawn by the SVG renderer into an element it does not own.
use([
    LineChart,
    GridComponent,
    TooltipComponent,
    MarkLineComponent,
    MarkPointComponent,
    SVGRenderer,
    LegacyGridContainLabel,
]);

export type ChartHandle = {
    setOption: (option: Record<string, unknown>, notMerge?: boolean) => void;
    resize: () => void;
    dispose: () => void;
    dispatchAction: (action: Record<string, unknown>) => void;
};

export type ChartInit = (element: HTMLElement) => ChartHandle;

function palette(): ChartPalette {
    const style = getComputedStyle(document.documentElement);
    // An unset token falls back to the text colour, never to a colour of its own.
    const read = (name: string): string =>
        style.getPropertyValue(`--df-${name}`).trim() || 'currentColor';

    return {
        strokes: [
            read('chart-1-stroke'),
            read('chart-2'),
            read('chart-3'),
            read('chart-4'),
            read('chart-5'),
            read('chart-6'),
        ],
        grid: read('chart-grid'),
        text: read('text-secondary'),
        focus: read('focus-ring'),
        surface: read('surface-card'),
    };
}

export function useChart(
    host: Ref<HTMLElement | null>,
    input: Ref<ChartInput>,
    makeChart: ChartInit = (element) =>
        init(element, undefined, { renderer: 'svg' }) as unknown as ChartHandle,
) {
    let chart: ChartHandle | null = null;
    let observer: ResizeObserver | null = null;
    const focus = ref<FocusState>(null);
    const label = computed(() => chartLabel(input.value));
    const announcement = ref('');

    function draw(): void {
        chart?.setOption(
            chartOption(input.value, palette(), format.encodeHTML),
            true,
        );
        show();
    }

    // The focused point: ECharts shows the tooltip (placed above it) and highlights it; nothing is shown when the plot is left.
    function show(): void {
        if (chart === null) {
            return;
        }

        if (focus.value === null) {
            chart.dispatchAction({ type: 'hideTip' });
            chart.dispatchAction({ type: 'downplay' });

            return;
        }

        const action = {
            seriesIndex: focus.value.series,
            dataIndex: focus.value.point,
        };

        chart.dispatchAction({ type: 'highlight', ...action });
        chart.dispatchAction({ type: 'showTip', ...action });
    }

    function onKeydown(event: KeyboardEvent): void {
        const result = reduceKey(
            focus.value,
            event.key,
            input.value.series.length,
            input.value.x.length,
        );

        if (!result.handled) {
            return;
        }

        event.preventDefault();
        focus.value = result.state;
        announcement.value = announce(input.value, result.state);
        show();
    }

    function onBlur(): void {
        focus.value = null;
        announcement.value = '';
        show();
    }

    onMounted(() => {
        if (host.value === null) {
            return;
        }

        chart = makeChart(host.value);
        draw();

        if (typeof ResizeObserver !== 'undefined') {
            observer = new ResizeObserver(() => chart?.resize());
            observer.observe(host.value);
        }
    });

    watch(input, () => {
        // A shorter input can leave the focused point past its end.
        if (
            focus.value !== null &&
            (focus.value.point >= input.value.x.length ||
                focus.value.series >= input.value.series.length)
        ) {
            focus.value = null;
        }

        draw();
    });

    onBeforeUnmount(() => {
        observer?.disconnect();
        chart?.dispose();
        chart = null;
    });

    return { label, announcement, focus, onKeydown, onBlur };
}
