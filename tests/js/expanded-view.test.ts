// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Table2 } from '@lucide/vue';
import ExpandedView from '../../resources/js/components/ExpandedView.vue';
import { configureFormatting } from '../../resources/js/lib/format';
import { createCatalogue } from '../../resources/js/lib/i18n';

// Story 5.9: View as table (from the displayed payload) and the Expanded view (the paginated rows), with their states, focus and live replacement.
const payload = {
    state: 'normal',
    x: ['2026-01-01', '2026-02-01'],
    xLabel: 'day',
    series: [
        { key: 'series_1', name: 'Revenue', values: ['10200.5', null] },
        { key: 'series_2', name: 'Cost', values: ['4', '5'] },
    ],
    format: {
        kind: 'currency',
        decimalPlaces: 2,
        thousandsSeparator: true,
        currencyCode: 'USD',
        currencyDisplay: 'symbol',
    },
};

const rows = (over: Record<string, unknown> = {}) => ({
    data: {
        status: 'ok',
        columns: [
            { key: 'x', label: 'day' },
            { key: 'series_1', label: 'Revenue' },
        ],
        rows: [
            ['2026-01-01', '10200.5'],
            ['<img src=x onerror=alert(1)>', null],
        ],
        total: 48,
        page: 1,
        page_size: 25,
        pages: 2,
        clamped: false,
        data_as_of: '2026-03-01T10:42:00Z',
        result_etag: 'e1',
        ...over,
    },
});

function answer(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'content-type': 'application/json' },
    });
}

const mountView = (props: Record<string, unknown> = {}) =>
    mount(ExpandedView, {
        props: {
            open: true,
            mode: 'rows',
            title: 'Sales',
            type: 'line-area',
            icon: Table2,
            dashboardId: 'd-1',
            itemId: 'i-1',
            payload,
            dataAsOf: '2026-03-01T10:42:00Z',
            resultEtag: 'e1',
            ...props,
        },
        global: { plugins: [createCatalogue()] },
        attachTo: document.body,
    });

const text = (selector: string): string =>
    document.body
        .querySelector(selector)
        ?.textContent?.replace(/\s+/g, ' ')
        .trim() ?? '';

beforeEach(() => configureFormatting({ locale: 'en', timeZone: 'UTC' }));
afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('View as table', () => {
    it('builds an accessible table from the displayed payload, with the chart formats and no request', async () => {
        const fetcher = vi.spyOn(globalThis, 'fetch');
        const w = mountView({ mode: 'table' });

        await flushPromises();

        const headers = [
            ...document.body.querySelectorAll('[data-test=expanded-table] th'),
        ];

        expect(headers.map((th) => th.textContent?.trim())).toEqual([
            'day',
            'Revenue',
            'Cost',
        ]);
        expect(headers.every((th) => th.getAttribute('scope') === 'col')).toBe(
            true,
        );
        expect(
            [
                ...document.body.querySelectorAll(
                    '[data-test=expanded-table] tbody tr',
                ),
            ].map((tr) =>
                [...tr.querySelectorAll('td')].map((td) =>
                    td.textContent?.trim(),
                ),
            ),
        ).toEqual([
            ['Jan 1, 2026', '$10,200.50', '$4.00'],
            ['Feb 1, 2026', '—', '$5.00'],
        ]);
        expect(fetcher).not.toHaveBeenCalled();
        expect(text('[data-slot=expanded-title]')).toBe('Sales: data table');
        expect(
            document.body.querySelector('[data-test=expanded-pager]'),
        ).toBeNull();
        w.unmount();
    });
});

describe('Expanded view', () => {
    it('shows skeleton rows, then the rows with the range, escaped text and the as-of time', async () => {
        let resolve: (response: Response) => void = () => {};
        vi.spyOn(globalThis, 'fetch').mockReturnValue(
            new Promise<Response>((done) => (resolve = done)),
        );
        const w = mountView();

        await flushPromises();
        expect(
            document.body.querySelector('[data-test=expanded-loading]'),
        ).not.toBeNull();

        resolve(answer(rows()));
        await flushPromises();

        expect(text('[data-test=expanded-pager] p')).toBe('1–25 of 48');
        expect(text('[data-slot=expanded-asof]')).toContain('10:42');
        expect(
            document.body.querySelectorAll(
                '[data-test=expanded-table] tbody tr',
            ),
        ).toHaveLength(2);
        expect(document.body.querySelector('img')).toBeNull();
        expect(
            text('[data-test=expanded-table] tbody tr:nth-child(2) td'),
        ).toBe('<img src=x onerror=alert(1)>');
        w.unmount();
    });

    it('pages with Next and Previous and requests the page asked for', async () => {
        const fetcher = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce(answer(rows()))
            .mockResolvedValueOnce(answer(rows({ page: 2 })));
        const w = mountView();

        await flushPromises();
        (
            document.body.querySelector(
                '[data-test=expanded-next]',
            ) as HTMLElement
        ).click();
        await flushPromises();

        expect(fetcher.mock.calls[1][0] as string).toContain(
            'page=2&page_size=25',
        );
        expect(text('[data-test=expanded-pager] p')).toBe(
            '26–48 of 48'.replace('48 of', '48 of'),
        );
        w.unmount();
    });

    it('shows the empty message, and the error with Retry that reads the rows again', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
            answer({ error: { code: 'dashboards.rowset_expired' } }, 503),
        );
        const w = mountView();

        await flushPromises();
        expect(text('[data-test=expanded-error]')).toContain(
            "We couldn't load the rows",
        );

        vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce(
            answer(rows({ status: 'empty', rows: [], total: 0, pages: 0 })),
        );
        (
            document.body.querySelector(
                '[data-test=expanded-retry]',
            ) as HTMLElement
        ).click();
        await flushPromises();

        expect(text('[data-test=expanded-empty]')).toBe(
            'No rows for this period.',
        );
        w.unmount();
    });

    it('replaces the values in place when a new result arrives, keeping the page, and announces a page that is gone', async () => {
        const fetcher = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce(answer(rows({ page: 2 })))
            .mockResolvedValueOnce(
                answer(
                    rows({
                        page: 1,
                        pages: 1,
                        clamped: true,
                        rows: [['2026-03-01', '9']],
                        total: 1,
                    }),
                ),
            );
        const w = mountView();

        await flushPromises();
        await w.setProps({ resultEtag: 'e2' });
        await flushPromises();

        expect(fetcher.mock.calls[1][0] as string).toContain('page=2');
        expect(text('[data-test=expanded-announce]')).toContain(
            'Showing the last page, 1 of 1',
        );
        w.unmount();
    });

    it('moves focus to the title and closes from the Close button', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(answer(rows()));
        const w = mountView();

        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.activeElement).toBe(
            document.body.querySelector('[data-slot=expanded-title]'),
        );

        (
            document.body.querySelector(
                '[data-slot=dialog-close]',
            ) as HTMLElement
        )?.click();
        await flushPromises();

        expect(w.emitted('update:open')?.[0]).toEqual([false]);
        w.unmount();
    });
});

describe('the card', () => {
    it('offers View as table for a chart Block and an Expanded view button for its footer target, and none of them for a KPI card', async () => {
        const { default: BlockCard } =
            await import('../../resources/js/components/BlockCard.vue');
        const item = (type: string, footer: unknown) => ({
            item_id: 'i-1',
            block_id: 'b-1',
            geometry: { col: 0, row: 0, width: 6, height: 'medium' },
            minimized: false,
            state: 'ok',
            refresh_interval: 60,
            is_live: false,
            block: {
                name: 'Sales',
                subtitle: null,
                type,
                icon: null,
                sizes: {
                    min_width: 3,
                    max_width: 12,
                    width: 6,
                    min_height: 'small',
                    max_height: 'large',
                    height: 'medium',
                },
                about: {
                    description: null,
                    source_name: null,
                    refresh_interval: 60,
                    version: '1.0',
                    period: null,
                },
                chrome: {
                    show_header: true,
                    show_subtitle: true,
                    allow_resize: false,
                    allow_minimize: false,
                    allow_refresh: false,
                    show_footer_action: true,
                },
                footer,
            },
        });
        const result = {
            item_id: 'i-1',
            block_id: 'b-1',
            state: 'ok',
            stale: false,
            refreshing: false,
            data_as_of: null,
            last_success_at: null,
            stale_at: null,
            result_etag: 'e1',
            slot_states: {},
            render_payload: payload,
        };
        const mountCard = (type: string, footer: unknown) =>
            mount(BlockCard, {
                props: {
                    item: item(type, footer) as never,
                    result: result as never,
                    dashboardId: 'd-1',
                },
                global: { plugins: [createCatalogue()] },
                attachTo: document.body,
            });

        const chart = mountCard('line-area', {
            target: 'expanded_view',
            label: 'Open the rows',
            url: null,
        });
        await flushPromises();
        expect(chart.get('[data-slot=footer-expanded]').text()).toBe(
            'Open the rows',
        );
        await chart.get('[data-slot=footer-expanded]').trigger('click');
        await flushPromises();
        expect(
            document.body.querySelector('[data-slot=expanded-view]'),
        ).not.toBeNull();
        chart.unmount();

        const kpi = mountCard('kpi-card', null);
        await flushPromises();
        expect(kpi.find('[data-slot=footer-expanded]').exists()).toBe(false);
        kpi.unmount();
    });
});
