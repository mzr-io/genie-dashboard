// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import { format } from 'echarts/core';
import ActivityRenderer from '@block-types/activity-feed/v1/Renderer.vue';
import BarRenderer from '@block-types/bar/v1/Renderer.vue';
import AgendaRenderer from '@block-types/calendar-agenda/v1/Renderer.vue';
import KpiRenderer from '@block-types/kpi-card/v1/Renderer.vue';
import KpiChartRenderer from '@block-types/kpi-chart/v1/Renderer.vue';
import LineRenderer from '@block-types/line-area/v1/Renderer.vue';
import ListRenderer from '@block-types/list/v1/Renderer.vue';
import PieRenderer from '@block-types/pie-donut/v1/Renderer.vue';
import ProgressRenderer from '@block-types/progress-list/v1/Renderer.vue';
import TableRenderer from '@block-types/table/v1/Renderer.vue';
import TextRenderer from '@block-types/text-status/v1/Renderer.vue';
import { Table2 } from '@lucide/vue';
import type { Component } from 'vue';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import * as generated from '../../resources/js/types/block-types';
import { chartOption, tooltipText } from '../../resources/js/charts/model';
import type { ChartInput } from '../../resources/js/charts/model';
import ExpandedView from '../../resources/js/components/ExpandedView.vue';
import { tableFor } from '../../resources/js/lib/blockRenderers';
import { configureFormatting } from '../../resources/js/lib/format';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { resolveFooterLink } from '../../resources/js/lib/footerAction';
import { expandKpiPayload } from '../../resources/js/lib/textTemplate';
import fixtures from '../Fixtures/xss-payloads.json';

// Story 5.16: hostile text in every slot of every Block Type renders as inert text in the Block, in "View as table" and in the Expanded view.
const RENDERERS: Record<string, Component> = {
    'activity-feed': ActivityRenderer,
    bar: BarRenderer,
    'calendar-agenda': AgendaRenderer,
    'kpi-card': KpiRenderer,
    'kpi-chart': KpiChartRenderer,
    'line-area': LineRenderer,
    list: ListRenderer,
    'pie-donut': PieRenderer,
    'progress-list': ProgressRenderer,
    table: TableRenderer,
    'text-status': TextRenderer,
};
const global = { plugins: [createCatalogue()] };
const entries = fixtures as unknown as Record<
    string,
    { slots: string[]; payload: Record<string, unknown> }
>;
const HOSTILE = /<img|<script|onerror/i;

declare global {
    interface Window {
        __xss?: number;
    }
}

beforeEach(() => {
    delete window.__xss;
    configureFormatting({ locale: 'en', timeZone: 'UTC' });
});
afterEach(() => {
    document.body.innerHTML = '';
});

// No element in the DOM came from payload text.
function inert(root: ParentNode): void {
    expect(window.__xss).toBeUndefined();
    expect(root.querySelector('img, script, iframe, object, embed')).toBeNull();
    expect(root.querySelector('[onerror], [onload], [onclick]')).toBeNull();
}

describe('the fixtures', () => {
    it('cover every slot of every generated Block Type, naming the missing one', () => {
        const types = generated.ALL_BLOCK_TYPES;

        expect(types.map((t) => t.key).sort()).toEqual(
            Object.keys(RENDERERS).sort(),
        );

        for (const type of types) {
            const missing = type.slots
                .map((s) => s.key)
                .filter((key) => !entries[type.key]?.slots.includes(key));

            expect(
                missing,
                `${type.key} has no XSS fixture for: ${missing.join(', ')}`,
            ).toEqual([]);
        }
    });
});

describe('every Block Type', () => {
    for (const [key, renderer] of Object.entries(RENDERERS)) {
        it(`${key} draws the hostile payload as text`, async () => {
            const payload = entries[key].payload;
            const wrapper = mount(renderer, {
                props: { payload },
                global,
                attachTo: document.body,
            });

            await flushPromises();
            inert(document.body);

            // The text is there, literally: a Block that drew nothing would pass the check above for the wrong reason.
            if (!['bar', 'kpi-chart', 'line-area', 'pie-donut'].includes(key)) {
                expect(document.body.textContent).toMatch(HOSTILE);
            }

            wrapper.unmount();
        });

        it(`${key} keeps its template expansion inert`, () => {
            const made = expandKpiPayload(
                entries[key].payload as never,
                { name: '<img src=x onerror=window.__xss=1>' },
                'en',
            );

            // Expanding never turns text into markup: it only substitutes text.
            expect(JSON.stringify(made)).not.toMatch(/<b>|<div/);
            expect(window.__xss).toBeUndefined();
        });
    }
});

describe('View as table and the Expanded view', () => {
    for (const key of Object.keys(RENDERERS)) {
        if (tableFor(key) === null) {
            continue;
        }

        it(`${key} builds its table from the hostile payload as text`, async () => {
            const wrapper = mount(ExpandedView, {
                props: {
                    open: true,
                    mode: 'table',
                    title: '<script>window.__xss=1</script>',
                    type: key,
                    icon: Table2,
                    dashboardId: 'd-1',
                    itemId: 'i-1',
                    payload: entries[key].payload,
                    dataAsOf: null,
                    resultEtag: null,
                },
                global,
                attachTo: document.body,
            });

            await flushPromises();
            inert(document.body);
            expect(document.body.textContent).toMatch(HOSTILE);
            wrapper.unmount();
        });
    }

    it('writes the rows of the paginated endpoint as text', async () => {
        const rows = [
            [
                '"><img src=x onerror=window.__xss=1>',
                '<script>window.__xss=1</script>',
            ],
        ];
        const original = globalThis.fetch;

        globalThis.fetch = (async () =>
            new Response(
                JSON.stringify({
                    data: {
                        status: 'ok',
                        columns: [
                            { key: 'x', label: '<img src=x onerror=1>' },
                            { key: 'series_1', label: '<script>1</script>' },
                        ],
                        rows,
                        total: 1,
                        page: 1,
                        page_size: 25,
                        pages: 1,
                        clamped: false,
                        data_as_of: '2026-03-01T10:42:00Z',
                        result_etag: 'e1',
                    },
                }),
                { headers: { 'content-type': 'application/json' } },
            )) as typeof fetch;

        const wrapper = mount(ExpandedView, {
            props: {
                open: true,
                mode: 'rows',
                title: 'Sales',
                type: 'line-area',
                icon: Table2,
                dashboardId: 'd-1',
                itemId: 'i-1',
                payload: entries['line-area'].payload,
                dataAsOf: null,
                resultEtag: 'e1',
            },
            global,
            attachTo: document.body,
        });

        await flushPromises();
        inert(document.body);
        globalThis.fetch = original;
        wrapper.unmount();
    });
});

describe('chart text', () => {
    const input: ChartInput = {
        kind: 'bar',
        x: ['"><img src=x onerror=window.__xss=1>'],
        series: [
            {
                key: 'values_1',
                name: '<script>window.__xss=1</script>',
                values: ['1'],
            },
        ],
        range: '<img src=x>',
        format: (value: string) => value,
        compact: (value: string) => value,
    };

    it('encodes every piece of a tooltip with the library encoder', () => {
        const html = tooltipText(input, 0, format.encodeHTML);

        expect(html).not.toMatch(/<img|<script/);
        expect(html).toContain('&lt;script&gt;');
    });

    it('gives the option no formatter string', () => {
        const option = chartOption(
            input,
            {
                strokes: ['#111'],
                fills: ['#222'],
                grid: '#eee',
                text: '#333',
                focus: '#00f',
                surface: '#fff',
            },
            format.encodeHTML,
        ) as unknown as Record<string, unknown>;
        const walk = (node: unknown): void => {
            if (Array.isArray(node)) {
                node.forEach(walk);
            } else if (node !== null && typeof node === 'object') {
                for (const [name, value] of Object.entries(node)) {
                    if (/formatter/i.test(name)) {
                        expect(typeof value).not.toBe('string');
                    }

                    walk(value);
                }
            }
        };

        walk(option);
    });
});

describe('very long and bidirectional text', () => {
    it('is drawn as text, whole, with no element made from it', () => {
        const huge = `${'A'.repeat(1_000_000)}`;
        const bidi = '‮evil‬ \u0000\u0007 <img src=x onerror=window.__xss=1>';
        const payload = {
            ...entries['list'].payload,
            rows: [
                {
                    key: 'k:1',
                    title: huge,
                    subtitle: bidi,
                    avatar: null,
                    icon: null,
                    value: null,
                    meta: null,
                    status: null,
                },
            ],
        };
        const wrapper = mount(ListRenderer, { props: { payload }, global });

        expect(wrapper.get('[data-test=list-title]').text().length).toBe(
            1_000_000,
        );
        expect(wrapper.get('[data-test=list-subtitle]').text()).toContain(
            '<img src=x',
        );
        expect(wrapper.find('img').exists()).toBe(false);
        expect(window.__xss).toBeUndefined();
        wrapper.unmount();
    });
});

describe('URL templates', () => {
    it('percent-encodes a hostile token and keeps the scheme and host fixed', () => {
        const link = resolveFooterLink(
            'https://erp.example.com/a/{user.name}',
            {
                name: 'javascript:alert(1)"><img src=x onerror=1>',
            },
        );

        expect(link?.href.startsWith('https://erp.example.com/a/')).toBe(true);
        expect(link?.href).not.toMatch(/[<>"]/);
        expect(link?.href).toContain('javascript%3Aalert');
    });
});
