import type { KpiCardPayload } from '../../kpi-card/v1/payload';
import type { LineAreaPayload } from '../../line-area/v1/payload';

// The kpi-chart v1 render payload (Story 5.10): the kpi-card payload as it is, and the chart beside it with its own state.
export type KpiChartPayload = KpiCardPayload & {
    chart: Pick<
        LineAreaPayload,
        | 'state'
        | 'kind'
        | 'x'
        | 'xLabel'
        | 'xPattern'
        | 'series'
        | 'partial'
        | 'highlight'
        | 'target'
        | 'format'
        | 'directLabels'
    >;
};
