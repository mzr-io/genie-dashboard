// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import { format } from 'echarts/core';
import { describe, expect, it, vi } from 'vitest';
import ChartPlot from '../../resources/js/charts/ChartPlot.vue';
import {
    announce,
    chartLabel,
    chartOption,
    compareDecimal,
    extremes,
    legendEntries,
    position,
    reduceKey,
    tooltipText,
} from '../../resources/js/charts/model';
import type { ChartInput } from '../../resources/js/charts/model';
import { findViolations, lintTree } from '../../scripts/lint-renderers.mjs';

// Story 5.8b: the shared chart wrapper: safe text, six styles, the generated label, one tab stop with arrows, exact values.
const money = (value: string): string => `$${value}`;
const compact = (value: string): string => `$${value}k`;

function input(over: Partial<ChartInput> = {}): ChartInput {
    return {
        kind: 'line',
        x: ['Jan', 'Feb', 'Mar'],
        series: [
            { key: 'a', name: 'Revenue', values: ['10.2', '21.4', '40.1'] },
        ],
        range: 'Jan to Mar 2026',
        format: money,
        compact,
        ...over,
    };
}

const palette = {
    strokes: ['#111', '#222', '#333', '#444', '#555', '#666'],
    grid: '#eee',
    text: '#333',
    focus: '#00f',
    surface: '#fefefe',
};

describe('values', () => {
    it('compares decimal strings exactly, beyond double precision', () => {
        expect(compareDecimal('9007199254740993', '9007199254740992')).toBe(1);
        expect(compareDecimal('1E3', '1000')).toBe(0);
        expect(compareDecimal('-0.5', '0.25')).toBe(-1);
        expect(compareDecimal('n/a', '1')).toBeNull();
        expect(position(null)).toBeNull();
        expect(position('abc')).toBeNull();
        expect(position('12.5')).toBe(12.5);
    });

    it('finds the lowest, the highest and the trend, skipping gaps', () => {
        expect(extremes([null, '5', '2', null, '9'])).toEqual({
            low: 2,
            high: 4,
            trend: 'up',
        });
        expect(extremes(['3', null, '3'])?.trend).toBe('flat');
        expect(extremes([null, null])).toBeNull();
    });
});

describe('label and legend', () => {
    it('generates the label with the range, the extremes, the trend and the target', () => {
        const label = chartLabel(
            input({ target: { value: '35', label: 'Goal' } }),
        );

        expect(label).toBe(
            'Line chart, Revenue, Jan to Mar 2026. Lowest $10.2 in Jan, highest $40.1 in Mar, trend up. Target $35, Goal.',
        );
        expect(chartLabel(input({ kind: 'area' }))).toMatch(/^Area chart/);
    });

    it('names marker and dash for every series, at most six', () => {
        const series = Array.from({ length: 8 }, (_, i) => ({
            key: `s${i}`,
            name: `S${i}`,
            values: ['1'],
        }));
        const entries = legendEntries(series);

        expect(entries).toHaveLength(6);
        expect(entries[0].text).toBe('S0, solid line, circle markers');
        expect(entries[1].text).toBe('S1, dashed line, square markers');
        expect(new Set(entries.map((e) => e.text)).size).toBe(6);
    });
});

describe('keyboard', () => {
    it('steps points, switches series, goes to the ends and leaves on Escape', () => {
        let r = reduceKey(null, 'ArrowRight', 2, 5);
        expect(r.state).toEqual({ series: 0, point: 0 });

        r = reduceKey(r.state, 'ArrowRight', 2, 5);
        r = reduceKey(r.state, 'ArrowRight', 2, 5);
        expect(r.state).toEqual({ series: 0, point: 2 });

        r = reduceKey(r.state, 'ArrowDown', 2, 5);
        expect(r.state).toEqual({ series: 1, point: 2 });
        expect(reduceKey(r.state, 'ArrowDown', 2, 5).state?.series).toBe(1);

        expect(reduceKey(r.state, 'Home', 2, 5).state?.point).toBe(0);
        expect(reduceKey(r.state, 'End', 2, 5).state?.point).toBe(4);
        expect(reduceKey(r.state, 'ArrowUp', 2, 5).state?.series).toBe(0);

        const out = reduceKey(r.state, 'Escape', 2, 5);
        expect(out).toEqual({ state: null, left: true, handled: true });
        expect(reduceKey(null, 'Escape', 2, 5).handled).toBe(false);
        expect(reduceKey(r.state, 'a', 2, 5).handled).toBe(false);
    });

    it('announces the focused point with its exact formatted value', () => {
        expect(announce(input(), { series: 0, point: 1 })).toBe(
            'Revenue, Feb, $21.4',
        );
        expect(
            announce(
                input({
                    series: [{ key: 'a', name: 'R', values: ['1', null] }],
                }),
                { series: 0, point: 1 },
            ),
        ).toBe('R, Feb, no value');
        expect(announce(input(), null)).toBe('');
    });
});

describe('text safety', () => {
    it('encodes every piece of the tooltip: data is text, never markup', () => {
        const hostile = input({
            x: ['<img src=x onerror=alert(1)>'],
            series: [
                {
                    key: 'a',
                    name: '<script>alert(1)</script>',
                    values: ['1'],
                },
            ],
            format: (v) => `"${v}"<b>`,
        });
        const html = tooltipText(hostile, 0, format.encodeHTML);

        expect(html).not.toContain('<img');
        expect(html).not.toContain('<script');
        expect(html).toContain('&lt;script&gt;');
        expect(html).toContain('&lt;b&gt;');
    });

    it('lints the shared chart code with the Renderer rules, and fails a formatter string', () => {
        expect(lintTree(process.cwd())).toEqual([]);
        expect(
            findViolations("tooltip: { formatter: '{b}: {c}' }").length,
        ).toBe(1);
        expect(findViolations('<div v-html="x" />').length).toBe(1);
    });
});

describe('option', () => {
    it('draws one series as a 2px monotone line with no markers at rest, horizontal gridlines only and compact ticks', () => {
        const option = chartOption(input(), palette, format.encodeHTML) as any;
        const line = option.series[0];

        expect(line.lineStyle.width).toBe(2);
        expect(line.smooth).toBe('monotone');
        expect(line.symbol).toBe('none');
        expect(line.areaStyle).toBeUndefined();
        expect(option.xAxis.splitLine.show).toBe(false);
        expect(option.yAxis.splitLine.show).toBe(true);
        expect(option.yAxis.axisLabel.formatter(40)).toBe('$40k');
        expect(line.data.map((d: any) => d.value)).toEqual([10.2, 21.4, 40.1]);
    });

    it('adds the gradient under series 1 for an area only', () => {
        const option = chartOption(
            input({
                kind: 'area',
                series: [
                    { key: 'a', name: 'A', values: ['1', '2'] },
                    { key: 'b', name: 'B', values: ['2', '3'] },
                ],
                x: ['a', 'b'],
            }),
            palette,
            format.encodeHTML,
        ) as any;

        expect(option.series[0].areaStyle).toBeDefined();
        expect(option.series[1].areaStyle).toBeUndefined();
    });

    it('gives each of several series its own dash and marker, a marker at every point up to 24 and at the end beyond, and a direct label', () => {
        const series = [
            { key: 'a', name: 'A', values: ['1', '2'] },
            { key: 'b', name: 'B', values: ['2', '3'] },
        ];
        const few = chartOption(
            input({ series, x: ['x', 'y'] }),
            palette,
            format.encodeHTML,
        ) as any;

        expect(few.series[0].symbol).toBe('circle');
        expect(few.series[1].symbol).toBe('rect');
        expect(few.series[1].lineStyle.type).toEqual([8, 4]);
        expect(few.series[0].endLabel.formatter()).toBe('A');

        const long = Array.from({ length: 30 }, (_, i) => String(i));
        const many = chartOption(
            input({
                series: [
                    { key: 'a', name: 'A', values: long },
                    { key: 'b', name: 'B', values: long },
                ],
                x: long,
            }),
            palette,
            format.encodeHTML,
        ) as any;

        expect(many.series[0].symbol).toBe('none');
        expect(many.series[0].data[29].symbol).toBe('circle');
        expect(many.series[0].data[0].symbol).toBeUndefined();
    });

    it('draws the target line with its label and the highlighted point at 10px with a 2px ring', () => {
        const option = chartOption(
            input({ target: { value: '35', label: 'Goal' }, highlight: 1 }),
            palette,
            format.encodeHTML,
        ) as any;

        expect(option.series[0].markLine.data[0].yAxis).toBe(35);
        expect(option.series[0].markLine.label.formatter()).toBe('Goal');
        expect(option.series[0].data[1]).toMatchObject({
            symbol: 'circle',
            symbolSize: 10,
            itemStyle: { borderWidth: 2 },
        });
    });

    it('keeps a gap as a gap, never 0', () => {
        const option = chartOption(
            input({
                series: [{ key: 'a', name: 'A', values: ['1', null, '3'] }],
            }),
            palette,
            format.encodeHTML,
        ) as any;

        expect(option.series[0].data[1].value).toBeNull();
        expect(option.series[0].connectNulls).toBe(false);
    });
});

describe('ChartPlot', () => {
    function chartDouble() {
        const calls: Record<string, unknown>[] = [];
        const handle = {
            setOption: vi.fn(),
            resize: vi.fn(),
            dispose: vi.fn(),
            dispatchAction: (action: Record<string, unknown>) =>
                calls.push(action),
        };

        return { handle, calls };
    }

    it('is one image tab stop with the generated label, and the legend is a list', () => {
        const { handle } = chartDouble();
        const w = mount(ChartPlot, {
            props: {
                input: input({
                    series: [
                        { key: 'a', name: 'A', values: ['1', '2', '3'] },
                        { key: 'b', name: 'B', values: ['2', '3', '4'] },
                    ],
                }),
                chart: () => handle,
            },
        });
        const plot = w.get('[data-test=chart-plot]');

        expect(plot.attributes('role')).toBe('img');
        expect(plot.attributes('tabindex')).toBe('0');
        expect(plot.attributes('aria-label')).toContain('Line chart, A, B');
        expect(w.get('[data-test=chart-host]').attributes('aria-hidden')).toBe(
            'true',
        );
        expect(
            w.findAll('[data-test=chart-legend] li').map((li) => li.text()),
        ).toEqual([
            '● A, solid line, circle markers',
            '■ B, dashed line, square markers',
        ]);
        expect(handle.setOption).toHaveBeenCalledTimes(1);
    });

    it('shows the tip and announces politely as the arrows move, and hides on Escape with focus kept', async () => {
        const { handle, calls } = chartDouble();
        const w = mount(ChartPlot, {
            props: { input: input(), chart: () => handle },
            attachTo: document.body,
        });
        const plot = w.get('[data-test=chart-plot]');

        await plot.trigger('keydown', { key: 'ArrowRight' });
        await plot.trigger('keydown', { key: 'ArrowRight' });

        expect(calls.filter((c) => c.type === 'showTip').at(-1)).toMatchObject({
            seriesIndex: 0,
            dataIndex: 1,
        });
        expect(w.get('[data-test=chart-announce]').text()).toBe(
            'Revenue, Feb, $21.4',
        );
        expect(
            w.get('[data-test=chart-announce]').attributes('aria-live'),
        ).toBe('polite');

        await plot.trigger('keydown', { key: 'Escape' });

        expect(calls.at(-1)).toEqual({ type: 'downplay' });
        w.unmount();
        expect(handle.dispose).toHaveBeenCalled();
    });
});

describe('ChartPlot with ECharts', () => {
    it('draws the SVG plot with the real library', async () => {
        const w = mount(ChartPlot, {
            props: { input: input() },
            attachTo: document.body,
        });

        await new Promise((resolve) => setTimeout(resolve, 20));

        expect(
            w.get('[data-test=chart-host]').element.querySelector('svg'),
        ).not.toBeNull();
        w.unmount();
    });
});
