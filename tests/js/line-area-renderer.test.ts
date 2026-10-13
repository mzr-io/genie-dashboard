// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import Renderer from '@block-types/line-area/v1/Renderer.vue';
import type { LineAreaPayload } from '@block-types/line-area/v1/payload';
import { beforeEach, describe, expect, it } from 'vitest';
import { configureFormatting } from '../../resources/js/lib/format';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { rendererFor } from '../../resources/js/lib/blockRenderers';

// Story 5.8c: every state of the line-area renderer, the plot with its exact generated label, and literal text.
const payload = (over: Partial<LineAreaPayload> = {}): LineAreaPayload => ({
    state: 'normal',
    kind: 'line',
    title: 'Sales',
    subtitle: null,
    icon: null,
    footerAction: null,
    x: ['2026-01-01', '2026-02-01', '2026-03-01'],
    series: [
        {
            key: 'series_1',
            name: 'Revenue',
            values: ['10200.5', '21400', '40100.25'],
        },
    ],
    partial: false,
    highlight: null,
    target: null,
    format: {
        kind: 'currency',
        decimalPlaces: 0,
        thousandsSeparator: true,
        currencyCode: 'USD',
        currencyDisplay: 'symbol',
    },
    directLabels: true,
    emptyDisplay: null,
    staleSince: null,
    errorMessage: null,
    ...over,
});

const render = (p: LineAreaPayload, props: Record<string, unknown> = {}) =>
    mount(Renderer, {
        props: { payload: p, ...props },
        global: { plugins: [createCatalogue()] },
    });

beforeEach(() => configureFormatting({ locale: 'en', timeZone: 'UTC' }));

describe('line-area renderer', () => {
    it('is registered for the line-area type', () => {
        expect(rendererFor('line-area')).not.toBeNull();
    });

    it('draws the plot with the generated label, formatted in the locale', () => {
        const w = render(
            payload({ target: { value: '35000', label: 'Goal' } }),
        );
        const label = w.get('[data-test=chart-plot]').attributes('aria-label');

        expect(w.get('[data-test=chart-title]').text()).toBe('Sales');
        expect(label).toContain('Line chart, Revenue');
        expect(label).toContain('Lowest $10,201 in');
        expect(label).toContain('highest $40,100 in');
        expect(label).toContain('trend up');
        expect(label).toContain('Target $35,000, Goal');
    });

    it('draws no plot, and no zero line, in Loading, Unavailable, Empty and Error', async () => {
        const loading = render(payload({ state: 'loading' }));
        expect(loading.find('[data-test=chart-loading]').exists()).toBe(true);
        expect(loading.find('[data-test=chart-plot]').exists()).toBe(false);

        const bare = render(
            {
                state: 'unavailable',
                title: null,
                x: undefined,
                series: undefined,
            } as never,
            { audience: 'admin' },
        );
        expect(bare.get('[data-test=chart-unavailable]').text()).toContain(
            'Unavailable: Chart data missing',
        );
        expect(bare.find('[data-test=chart-plot]').exists()).toBe(false);

        const user = render(
            payload({ state: 'unavailable', x: [], series: [] }),
        );
        expect(user.get('[data-test=chart-unavailable]').text()).toContain(
            "can't be shown",
        );

        const empty = render(payload({ state: 'empty', x: [], series: [] }));
        expect(empty.get('[data-test=chart-empty]').text()).toBe(
            'No data for this period.',
        );

        const error = render(
            payload({ state: 'error', errorMessage: 'Source down' }),
        );
        expect(error.get('[data-test=chart-error]').text()).toContain(
            'Source down',
        );
        await error.get('[data-test=chart-retry]').trigger('click');
        expect(error.emitted('retry')).toHaveLength(1);
    });

    it('says Stale beside the plot and flags partial data', () => {
        const w = render(
            payload({
                state: 'stale',
                staleSince: '2026-03-01T10:42:00Z',
                partial: true,
                series: [
                    {
                        key: 'series_1',
                        name: 'Revenue',
                        values: ['1', null, '3'],
                    },
                ],
            }),
        );

        expect(w.get('[data-test=chart-stale]').text()).toContain(
            'Stale: last data',
        );
        expect(w.get('[data-test=chart-partial]').text()).toContain(
            'Partial data',
        );
        expect(w.find('[data-test=chart-plot]').exists()).toBe(true);
    });

    it('keeps every name, title and axis value literal text', () => {
        const hostile = '<img src=x onerror=alert(1)>';
        const w = render(
            payload({
                title: hostile,
                x: [hostile, 'b'],
                series: [
                    { key: 'series_1', name: hostile, values: ['1', '2'] },
                ],
                footerAction: hostile,
            }),
        );

        expect(w.find('img').exists()).toBe(false);
        expect(w.get('[data-test=chart-title]').text()).toBe(hostile);
        expect(w.get('[data-test=chart-footer]').text()).toBe(hostile);
        expect(
            w.get('[data-test=chart-plot]').attributes('aria-label'),
        ).toContain(hostile);
    });

    it('lists several series by marker and dash', () => {
        const w = render(
            payload({
                series: [
                    { key: 'series_1', name: 'A', values: ['1', '2', '3'] },
                    { key: 'series_2', name: 'B', values: ['2', '3', '4'] },
                ],
            }),
        );

        expect(
            w.findAll('[data-test=chart-legend] li').map((li) => li.text()),
        ).toEqual([
            '● A, solid line, circle markers',
            '■ B, dashed line, square markers',
        ]);
    });
});
