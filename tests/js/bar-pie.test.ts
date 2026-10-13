// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { format } from 'echarts/core';
import BarRenderer from '@block-types/bar/v1/Renderer.vue';
import type { BarPayload } from '@block-types/bar/v1/payload';
import PieRenderer from '@block-types/pie-donut/v1/Renderer.vue';
import type { PiePayload } from '@block-types/pie-donut/v1/payload';
import { beforeEach, describe, expect, it } from 'vitest';
import {
    announce,
    chartLabel,
    chartOption,
    legendFor,
    share,
    sliceTotal,
    tooltipText,
} from '../../resources/js/charts/model';
import type { ChartInput } from '../../resources/js/charts/model';
import { configureFormatting } from '../../resources/js/lib/format';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { tableFor } from '../../resources/js/lib/blockRenderers';

// Story 5.11: bars and pies on the shared chart wrapper: fill patterns, stack totals, shares by exact arithmetic, the faces and the table.
const money = (value: string): string => `$${value}`;
const palette = {
    strokes: ['#111', '#222', '#333', '#444', '#555', '#666'],
    fills: ['#aaa', '#bbb', '#ccc', '#ddd', '#eee', '#fff0'],
    grid: '#eee',
    text: '#333',
    focus: '#00f',
    surface: '#fefefe',
};

function bars(over: Partial<ChartInput> = {}): ChartInput {
    return {
        kind: 'bar',
        x: ['North', 'South'],
        series: [
            { key: 'values_1', name: 'Revenue', values: ['10', '-5'] },
            { key: 'values_2', name: 'Cost', values: ['4', '5'] },
            { key: 'values_3', name: 'Tax', values: ['1', '2'] },
        ],
        range: 'North to South',
        format: money,
        compact: money,
        ...over,
    };
}

function pie(over: Partial<ChartInput> = {}): ChartInput {
    return {
        kind: 'pie',
        x: ['A', 'B', 'C'],
        series: [
            { key: 'pie_value', name: 'Revenue', values: ['50', '30', '20'] },
        ],
        range: '',
        format: money,
        compact: money,
        ...over,
    };
}

describe('exact shares', () => {
    it('writes a share with one place from exact decimals', () => {
        expect(share('1', '3')).toBe('33.3');
        expect(share('2', '3')).toBe('66.7');
        expect(share('50', '100')).toBe('50.0');
        expect(share('0', '100')).toBe('0.0');
        expect(share('5', '0')).toBeNull();
        expect(share(null, '10')).toBeNull();
        expect(share('9007199254740993', '18014398509481986')).toBe('50.0');
    });

    it('totals only the positive slices, exactly', () => {
        expect(sliceTotal(['10.5', '-3', null, '0.25', 'n/a'])).toBe('10.75');
        expect(sliceTotal([])).toBe('0');
    });
});

describe('bar option', () => {
    it('fills the series, edges them 1px, rounds the top corners and gaps them 30% and 2px', () => {
        const option = chartOption(bars(), palette, format.encodeHTML) as any;
        const first = option.series[0];

        expect(first.type).toBe('bar');
        expect(first.barCategoryGap).toBe('30%');
        expect(first.barGap).toBe(2);
        expect(first.itemStyle).toMatchObject({
            color: '#aaa',
            borderColor: '#111',
            borderWidth: 1,
            borderRadius: [2, 2, 0, 0],
        });
        expect(first.data).toEqual([10, -5]);
    });

    it('patterns series 3 to 6 so colour is never the only signal', () => {
        const six = bars({
            series: Array.from({ length: 6 }, (_, i) => ({
                key: `values_${i + 1}`,
                name: `S${i + 1}`,
                values: ['1', '2'],
            })),
        });
        const option = chartOption(six, palette, format.encodeHTML) as any;

        expect(option.series[0].itemStyle.decal).toBeUndefined();
        expect(option.series[1].itemStyle.decal).toBeUndefined();

        for (let i = 2; i < 6; i++) {
            expect(option.series[i].itemStyle.decal).toBeDefined();
        }

        expect(legendFor(six).map((e) => e.text)).toEqual([
            'S1, solid fill',
            'S2, solid fill',
            'S3, diagonal stripes fill',
            'S4, dots fill',
            'S5, cross-hatch fill',
            'S6, vertical lines fill',
        ]);
    });

    it('stacks with a 1px surface divider and the total above each stack, and goes across when asked', () => {
        const option = chartOption(
            bars({ stacked: true, totals: ['15', '2'], horizontal: true }),
            palette,
            format.encodeHTML,
        ) as any;

        expect(option.series[0].stack).toBe('total');
        expect(option.series[0].itemStyle.borderColor).toBe('#fefefe');
        expect(option.series[0].itemStyle.borderRadius).toEqual([0, 2, 2, 0]);

        const totals = option.series[option.series.length - 1];

        expect(totals.data).toEqual([0, 0]);
        expect(totals.label.formatter({ dataIndex: 0 })).toBe('$15');
        expect(option.xAxis.type).toBe('value');
        expect(option.yAxis.type).toBe('category');
    });

    it('writes each value on its bar from the exact decimal and keeps negative bars below the baseline', () => {
        const option = chartOption(
            bars({ valueLabels: true }),
            palette,
            format.encodeHTML,
        ) as any;

        expect(option.series[0].label.formatter({ dataIndex: 1 })).toBe('$-5');
        expect(option.series[0].data[1]).toBe(-5);
    });

    it('keeps the categories and the legend of an all-zero chart', () => {
        const zero = bars({
            series: [{ key: 'values_1', name: 'Revenue', values: ['0', '0'] }],
        });
        const option = chartOption(zero, palette, format.encodeHTML) as any;

        expect(option.xAxis.data).toEqual(['North', 'South']);
        expect(option.series[0].data).toEqual([0, 0]);
        expect(legendFor(zero)).toHaveLength(1);
    });

    it('names the categories and the largest value, announces the step and escapes the tooltip', () => {
        expect(chartLabel(bars())).toBe(
            'Bar chart, Revenue, Cost, Tax, North to South. Largest $10 in North.',
        );
        expect(chartLabel(bars({ stacked: true }))).toMatch(
            /^Stacked bar chart/,
        );
        expect(announce(bars(), { series: 0, point: 0 })).toBe(
            'Revenue, North, $10',
        );

        const hostile = bars({
            x: ['<img src=x onerror=alert(1)>'],
            series: [{ key: 'values_1', name: '<b>', values: ['1'] }],
        });

        expect(tooltipText(hostile, 0, format.encodeHTML)).not.toContain(
            '<img',
        );
    });
});

describe('pie option', () => {
    it("draws slices clockwise from 12 o'clock with a 2px surface divider and a ring 28% thick", () => {
        const option = chartOption(
            pie({ donut: true, centre: { label: 'Revenue', value: '$100' } }),
            palette,
            format.encodeHTML,
        ) as any;
        const series = option.series[0];

        expect(series.type).toBe('pie');
        expect(series.startAngle).toBe(90);
        expect(series.clockwise).toBe(true);
        expect(series.sort).toBeNull();
        expect(series.radius).toEqual(['65%', '90%']);
        expect(series.data[0].itemStyle).toMatchObject({
            borderColor: '#fefefe',
            borderWidth: 2,
        });
        expect(option.title).toMatchObject({
            text: '$100',
            subtext: 'Revenue',
        });
        expect(
            (chartOption(pie(), palette, format.encodeHTML) as any).series[0]
                .radius,
        ).toEqual(['0%', '90%']);
    });

    it('lists name, share and value, patterns from the third slice, and labels the largest slices', () => {
        expect(legendFor(pie()).map((e) => e.text)).toEqual([
            'A, 50.0%, $50',
            'B, 30.0%, $30',
            'C, 20.0%, $20, diagonal stripes fill',
        ]);
        expect(chartLabel(pie())).toBe(
            'Pie chart, Revenue, 3 categories. Largest: A 50.0% ($50), B 30.0% ($30), C 20.0% ($20).',
        );
        expect(announce(pie(), { series: 0, point: 1 })).toBe('B, 30.0%, $30');
    });

    it('draws a zero or negative slice as nothing, never a negative size', () => {
        const option = chartOption(
            pie({
                series: [
                    { key: 'pie_value', name: 'R', values: ['5', '-2', null] },
                ],
            }),
            palette,
            format.encodeHTML,
        ) as any;

        expect(option.series[0].data.map((d: any) => d.value)).toEqual([
            5, 0, 0,
        ]);
    });
});

describe('Renderers', () => {
    beforeEach(() => configureFormatting({ locale: 'en', timeZone: 'UTC' }));

    const barPayload = (over: Partial<BarPayload> = {}): BarPayload => ({
        state: 'normal',
        kind: 'grouped',
        orientation: 'vertical',
        valueLabels: false,
        title: 'Sales by region',
        subtitle: null,
        icon: null,
        footerAction: null,
        categories: ['North', 'South'],
        categoryLabel: 'region',
        series: [
            { key: 'values_1', name: 'revenue', values: ['1200.5', '800'] },
        ],
        totals: [],
        partial: false,
        format: {
            kind: 'currency',
            decimalPlaces: 0,
            thousandsSeparator: true,
            currencyCode: 'USD',
            currencyDisplay: 'symbol',
        },
        emptyDisplay: null,
        staleSince: null,
        errorMessage: null,
        ...over,
    });

    it('draws a bar chart with its label, and its table has the category and the series', () => {
        const w = mount(BarRenderer, {
            props: { payload: barPayload() },
            global: { plugins: [createCatalogue()] },
        });

        expect(w.get('[data-test=chart-title]').text()).toBe('Sales by region');
        expect(
            w.get('[data-test=chart-plot]').attributes('aria-label'),
        ).toContain(
            'Bar chart, revenue, North to South. Largest $1,201 in North.',
        );

        const table = tableFor('bar')?.table(
            barPayload() as unknown as Record<string, unknown>,
            'en',
        );

        expect(table?.columns.map((c) => c.label)).toEqual([
            'region',
            'revenue',
        ]);
        expect(table?.rows).toEqual([
            ['North', '$1,201'],
            ['South', '$800'],
        ]);
    });

    it('shows the empty and unavailable faces with no plot', () => {
        const empty = mount(BarRenderer, {
            props: {
                payload: barPayload({
                    state: 'empty',
                    categories: [],
                    series: [],
                }),
            },
            global: { plugins: [createCatalogue()] },
        });
        const gone = mount(BarRenderer, {
            props: {
                payload: barPayload({
                    state: 'unavailable',
                    categories: [],
                    series: [],
                }),
                audience: 'admin',
            },
            global: { plugins: [createCatalogue()] },
        });

        expect(empty.get('[data-test=chart-empty]').text()).toBe(
            'No data for this period.',
        );
        expect(gone.get('[data-test=chart-unavailable]').text()).toContain(
            'Unavailable: Chart data missing',
        );
        expect(empty.find('[data-test=chart-plot]').exists()).toBe(false);
    });

    it('draws a donut with its centre and lists the slices', () => {
        const payload: PiePayload = {
            state: 'normal',
            kind: 'donut',
            title: 'Share',
            subtitle: null,
            icon: null,
            footerAction: null,
            categories: ['A', 'B'],
            categoryLabel: 'region',
            values: ['60', '40'],
            valueLabel: 'revenue',
            folded: false,
            centre: { label: 'Revenue', value: '100' },
            partial: false,
            format: {
                kind: 'currency',
                decimalPlaces: 0,
                thousandsSeparator: true,
                currencyCode: 'USD',
                currencyDisplay: 'symbol',
            },
            emptyDisplay: null,
            staleSince: null,
            errorMessage: null,
        };
        const w = mount(PieRenderer, {
            props: { payload },
            global: { plugins: [createCatalogue()] },
        });

        expect(
            w.get('[data-test=chart-plot]').attributes('aria-label'),
        ).toContain(
            'Donut chart, revenue, 2 categories. Largest: A 60.0% ($60), B 40.0% ($40).',
        );
        expect(
            w.findAll('[data-test=chart-legend] li').map((li) => li.text()),
        ).toEqual(['● A, 60.0%, $60', '● B, 40.0%, $40']);
        expect(
            tableFor('pie-donut')?.table(
                payload as unknown as Record<string, unknown>,
                'en',
            ).rows,
        ).toEqual([
            ['A', '$60'],
            ['B', '$40'],
        ]);
    });
});
