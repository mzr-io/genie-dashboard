// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import Renderer from '@block-types/kpi-chart/v1/Renderer.vue';
import type { KpiChartPayload } from '@block-types/kpi-chart/v1/payload';
import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it } from 'vitest';
import { configureFormatting } from '../../resources/js/lib/format';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { rendererFor, tableFor } from '../../resources/js/lib/blockRenderers';

// Story 5.10 (FR-31): the normative revenue example through the Renderer, from the same golden payload the engine test asserts.
const golden = JSON.parse(
    readFileSync('tests/Fixtures/revenue-overview/golden-payload.json', 'utf8'),
) as KpiChartPayload;

const render = (payload: KpiChartPayload) =>
    mount(Renderer, {
        props: { payload },
        global: { plugins: [createCatalogue()] },
    });

beforeEach(() => configureFormatting({ locale: 'en', timeZone: 'UTC' }));

describe('kpi-chart renderer', () => {
    it('is registered, and offers its chart as a table', () => {
        expect(rendererFor('kpi-chart')).not.toBeNull();

        const table = tableFor('kpi-chart')?.table(
            golden as unknown as Record<string, unknown>,
            'en',
        );

        expect(table?.columns.map((c) => c.label)).toEqual([
            'month',
            'revenue',
        ]);
        expect(table?.rows).toEqual([
            ['Jan', '$10,200'],
            ['Feb', '$12,650'],
        ]);
    });

    it('shows the label, the headline, the comparison in green with its judgement, and the chart', () => {
        const w = render(golden);

        expect(w.get('[data-test=kpi-chart-title]').text()).toBe(
            'Revenue overview',
        );
        expect(w.get('[data-test=kpi-label]').text()).toBe('Total revenue');
        expect(w.get('[data-test=kpi-headline]').text()).toBe('$284,680');

        const delta = w.get('[data-test=kpi-delta]');

        expect(w.get('[data-test=kpi-delta-visible]').text()).toContain(
            '↗ 14.2% from last year',
        );
        expect(delta.classes()).toContain('text-success-text');
        expect(w.get('[data-test=kpi-delta-arrow]').text()).toBe('↗');
        expect(
            w.get('[data-test=kpi-delta-visible]').attributes('aria-hidden'),
        ).toBe('true');
        expect(w.get('[data-test=kpi-delta-spoken]').text()).toBe(
            'Up 14.2% from last year, favourable',
        );

        // Exactly one headline-styled element, and the chart beside it.
        expect(w.findAll('.type-kpi-headline')).toHaveLength(1);

        const label = w.get('[data-test=chart-plot]').attributes('aria-label');

        expect(label).toContain('Area chart, revenue, Jan to Feb');
        expect(label).toContain(
            'Lowest $10,200 in Jan, highest $12,650 in Feb',
        );
    });

    it('keeps the headline and the chart when the comparison is unavailable, and draws no zero', () => {
        const w = render({ ...golden, comparison: null });

        expect(w.get('[data-test=kpi-headline]').text()).toBe('$284,680');
        expect(w.find('[data-test=kpi-delta]').exists()).toBe(false);
        expect(w.find('[data-test=chart-plot]').exists()).toBe(true);
    });

    it('keeps the headline over an empty chart that says so', () => {
        const w = render({
            ...golden,
            chart: { ...golden.chart, state: 'empty', x: [], series: [] },
        });

        expect(w.get('[data-test=kpi-headline]').text()).toBe('$284,680');
        expect(w.get('[data-test=chart-empty]').text()).toBe(
            'No data for this period.',
        );
        expect(w.find('[data-test=chart-plot]').exists()).toBe(false);
    });

    it('reflows by container width with every part kept', () => {
        const w = render(golden);
        const layout = w.get('[data-test=kpi-chart-layout]');

        expect(w.classes()).toContain('@container');
        expect(layout.classes().join(' ')).toContain('@2xl:grid-cols');
        expect(w.find('[data-test=kpi-label]').exists()).toBe(true);
        expect(w.find('[data-test=kpi-delta]').exists()).toBe(true);
        expect(w.find('[data-test=chart-plot]').exists()).toBe(true);
    });
});
