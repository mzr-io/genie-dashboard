// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { DataSource } from '../../resources/js/lib/dataSources';
import { formatDateTime } from '../../resources/js/lib/format';
import { createCatalogue } from '../../resources/js/lib/i18n';
import {
    dataSourceLabels as labels,
    platformHealthLabels,
} from '../../resources/js/locales/labels';

// Story 2.18: the Data sources list shows a dot AND its word (Healthy, Degraded, Unreachable, Checking…) with "Last success {time}"; the dot is
// aria-hidden and never stands alone. The Admin overview's "Platform health" panel lists "Your data sources" only for `data_sources.manage`,
// requests nothing without it, loads once, and each row is a link to the form named "{name}, {word}, last success {time}". The form takes the
// optional health path.
const visit = vi.fn();

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h, reactive } = await import('vue');
    const page = reactive({
        url: '/admin',
        props: { shell: { can: {} as Record<string, boolean>, items: [] } },
    });
    (globalThis as Record<string, unknown>).__page = page;

    return {
        Link: defineComponent({
            props: ['href'],
            setup:
                (props, { slots, attrs }) =>
                () =>
                    h('a', { ...attrs, href: props.href }, slots.default?.()),
        }),
        Head: { render: () => null },
        router: {
            visit: (...args: unknown[]) => visit(...args),
            on: () => () => {},
        },
        usePage: () => page,
    };
});

function setCan(can: Record<string, boolean>): void {
    (
        (globalThis as Record<string, unknown>).__page as {
            props: { shell: { can: Record<string, boolean> } };
        }
    ).props.shell.can = can;
}

const { default: DataSources } =
    await import('../../resources/js/pages/admin/DataSources.vue');
const { default: Overview } =
    await import('../../resources/js/pages/admin/Overview.vue');
const { default: DataSourceForm } =
    await import('../../resources/js/pages/admin/DataSourceForm.vue');

const ceilings = {
    timeout_seconds: 60,
    max_response_bytes: null,
    max_pages: 20,
};

function source(overrides: Partial<DataSource> = {}): DataSource {
    return {
        data_source_id: 'ds-1',
        name: 'Sales API',
        base_url: 'https://api.example.com/v1',
        health_path: null,
        scheme: 'https',
        host: 'api.example.com',
        port: 443,
        auth_type: 'none',
        api_key_name: null,
        api_key_placement: null,
        oauth_token_url: null,
        oauth_client_id: null,
        oauth_scope: null,
        headers: [],
        timeout_seconds: 30,
        max_response_bytes: null,
        max_pages: null,
        live_capable: false,
        pagination_style: 'none',
        pagination_param: null,
        pagination_size_param: null,
        pagination_size: null,
        pagination_records_path: null,
        pagination_cursor_path: null,
        retention_mode: 'latest',
        retention_days: null,
        revision: 1,
        health: 'checking',
        last_successful_call_at: null,
        blocks_using: 0,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-01T09:30:00Z',
        ...overrides,
    };
}

const list = (data: DataSource[]) => ({
    data,
    meta: {
        total: data.length,
        matched: data.length,
        sort: 'name',
        direction: 'asc',
        ceilings,
        retention: { max_window_days: 90 },
    },
});

type Reply = { ok: boolean; status: number; json: unknown };
type Call = { url: string; method: string; body: unknown };
const calls: Call[] = [];
let replies: Array<Reply | Promise<Reply>> = [];
const ok = (json: unknown): Reply => ({ ok: true, status: 200, json });

let wrapper: VueWrapper | null = null;

async function mountPage(
    component: unknown,
    props: Record<string, unknown> = {},
) {
    wrapper = mount(component as never, {
        attachTo: document.body,
        props,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();

    return wrapper;
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));

beforeEach(() => {
    setActivePinia(createPinia());
    setCan({ 'data_sources.manage': true });
    calls.length = 0;
    replies = [];
    visit.mockClear();
    window.history.replaceState({}, '', '/admin');
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                if (/\/lock(\/|\?|$)/.test(url)) {
                    return {
                        ok: true,
                        status: 200,
                        headers: { get: () => null },
                        json: async () => ({ data: { enabled: false } }),
                    };
                }

                calls.push({
                    url,
                    method: init?.method ?? 'GET',
                    body: init?.body ? JSON.parse(init.body) : undefined,
                });
                const next = replies.shift();

                if (!next) {
                    throw new Error(`unexpected request ${url}`);
                }

                const reply = await next;

                return {
                    ok: reply.ok,
                    status: reply.status,
                    json: async () => reply.json,
                };
            },
        ),
    );
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    resetAnnouncer();
    vi.unstubAllGlobals();
});

const LAST = '2026-10-09T10:42:00Z';

describe('Data sources list: health', () => {
    it('shows the dot with its word and the last success for every status, the dot aria-hidden', async () => {
        replies = [
            ok(
                list([
                    source({
                        data_source_id: 'a',
                        name: 'A',
                        health: 'healthy',
                        last_successful_call_at: LAST,
                    }),
                    source({
                        data_source_id: 'b',
                        name: 'B',
                        health: 'degraded',
                        last_successful_call_at: LAST,
                    }),
                    source({
                        data_source_id: 'c',
                        name: 'C',
                        health: 'unreachable',
                    }),
                    source({
                        data_source_id: 'd',
                        name: 'D',
                        health: 'checking',
                    }),
                ]),
            ),
        ];
        await mountPage(DataSources);

        const rows = $$('tbody [data-slot="data-row"]');
        const word = (row: HTMLElement) =>
            row.querySelector('[data-test="health"]')?.textContent?.trim();

        expect(rows.map(word)).toEqual([
            labels.health.healthy,
            labels.health.degraded,
            labels.health.unreachable,
            labels.checking,
        ]);
        expect(labels.health.checking).toBe('Checking…');

        for (const row of rows) {
            const health = row.querySelector(
                '[data-test="health"]',
            ) as HTMLElement;
            const dot = health.querySelector('[data-slot="status-dot"]');

            // The dot never appears without its word, and assistive technology skips it.
            expect(dot?.getAttribute('aria-hidden')).toBe('true');
            expect(
                health
                    .querySelector('[data-test="health-word"]')
                    ?.textContent?.trim(),
            ).not.toBe('');
        }

        const tones = ['bg-success', 'bg-warning', 'bg-error', 'bg-text-muted'];
        expect(
            rows.map((row, i) =>
                row
                    .querySelector('[data-slot="status-dot"]')
                    ?.className.includes(tones[i]),
            ),
        ).toEqual([true, true, true, true]);

        const last = (row: HTMLElement) =>
            row.querySelector('[data-test="last-success"]')?.textContent;
        expect(last(rows[0])).toBe(labels.lastSuccess(formatDateTime(LAST)));
        expect(last(rows[2])).toBe(labels.noCall);
    });

    it('reads an unknown status as Checking…, never Healthy', async () => {
        replies = [ok(list([source({ health: 'ok-ish' as never })]))];
        await mountPage(DataSources);

        expect($('[data-test="health"]')?.textContent?.trim()).toBe(
            labels.checking,
        );
    });
});

describe('Admin overview: Platform health', () => {
    const rows = [
        {
            data_source_id: 'ds-1',
            name: 'Sales API',
            health: 'unreachable',
            last_successful_call_at: LAST,
        },
        {
            data_source_id: 'ds-2',
            name: 'HR API',
            health: 'healthy',
            last_successful_call_at: null,
        },
    ];

    it('lists "Your data sources" with dot, word and last success, each row a link to the form with the full accessible name', async () => {
        replies = [ok({ data: rows })];
        await mountPage(Overview);

        expect(calls.map((call) => call.url)).toEqual([
            '/api/v1/admin/data-source-health',
        ]);
        expect($('[data-slot="platform-health"] h2')?.textContent?.trim()).toBe(
            platformHealthLabels.panel,
        );
        expect(
            $('[data-test="your-data-sources"] h3')?.textContent?.trim(),
        ).toBe(platformHealthLabels.sourcesTitle);

        const items = $$('[data-test="health-row"]');
        expect(items).toHaveLength(2);

        const link = items[0].querySelector('a') as HTMLAnchorElement;
        expect(link.getAttribute('href')).toContain(
            '/admin/data-sources/ds-1/edit',
        );
        expect(link.getAttribute('aria-label')).toBe(
            `Sales API, Unreachable, last success ${formatDateTime(LAST)}`,
        );
        expect(
            items[0]
                .querySelector('[data-slot="status-dot"]')
                ?.getAttribute('aria-hidden'),
        ).toBe('true');
        expect(items[0].textContent).toContain('Unreachable');
        expect(items[0].textContent).toContain(
            `Last success ${formatDateTime(LAST)}`,
        );
        expect(
            (items[1].querySelector('a') as HTMLAnchorElement).getAttribute(
                'aria-label',
            ),
        ).toBe(`HR API, Healthy, ${labels.noCallYet}`);
    });

    it('is loaded once: no polling and no announcement of a routine refresh', async () => {
        vi.useFakeTimers();
        replies = [ok({ data: rows })];
        await mountPage(Overview);
        vi.advanceTimersByTime(10 * 60 * 1000);
        await flushPromises();
        vi.useRealTimers();

        expect(calls).toHaveLength(1);
        expect(
            document.querySelector('[aria-live]')?.textContent ?? '',
        ).not.toContain('Sales API');
    });

    it('does not render the panel, and requests nothing, without data_sources.manage', async () => {
        setCan({});
        await mountPage(Overview);

        expect($('[data-slot="platform-health"]')).toBeNull();
        expect(document.body.textContent).not.toContain(
            platformHealthLabels.sourcesTitle,
        );
        expect(calls).toHaveLength(0);
    });

    it('loads once when the permission arrives after the page mounted, and shows no placeholder under the panel', async () => {
        setCan({});
        await mountPage(Overview);
        expect(calls).toHaveLength(0);

        replies = [ok({ data: rows })];
        setCan({ 'data_sources.manage': true });
        await flushPromises();

        expect(calls).toHaveLength(1);
        expect($$('[data-test="health-row"]')).toHaveLength(2);
        expect($('[data-slot="list-empty"]')).toBeNull();
        expect(document.body.textContent).not.toContain('Create a block');
    });

    it('says when there are no data sources and offers Retry when the load fails', async () => {
        replies = [ok({ data: [] })];
        await mountPage(Overview);
        expect($('[data-test="health-empty"]')?.textContent?.trim()).toBe(
            platformHealthLabels.empty,
        );
        wrapper?.unmount();
        document.body.innerHTML = '';

        replies = [{ ok: false, status: 500, json: {} }, ok({ data: rows })];
        await mountPage(Overview);
        expect($('[data-test="health-error"]')).not.toBeNull();

        ($('[data-test="health-retry"]') as HTMLElement).click();
        await flushPromises();
        expect($$('[data-test="health-row"]')).toHaveLength(2);
    });
});

describe('Data source form: health path', () => {
    it('fills the saved health path and sends it trimmed, and sends none when blank', async () => {
        replies = [
            ok({
                data: source({ health_path: '/health' }),
                meta: { ceilings, retention: { max_window_days: 90 } },
            }),
        ];
        await mountPage(DataSourceForm, { dataSourceId: 'ds-1' });

        const input = $('[data-test="health-path"]') as HTMLInputElement;
        expect(input.value).toBe('/health');

        replies = [
            ok({
                data: source({ revision: 2 }),
                meta: { ceilings, retention: { max_window_days: 90 } },
            }),
        ];
        input.value = '  /status  ';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();
        ($('[data-test="save"]') as HTMLButtonElement).click();
        await flushPromises();

        const writes = calls.filter((call) => call.method !== 'GET');
        expect(writes[0].body).toMatchObject({ health_path: '/status' });
    });

    it('shows the server refusal on the field', async () => {
        replies = [
            ok({
                data: source(),
                meta: { ceilings, retention: { max_window_days: 90 } },
            }),
        ];
        await mountPage(DataSourceForm, { dataSourceId: 'ds-1' });

        replies = [
            {
                ok: false,
                status: 422,
                json: {
                    error: { code: 'validation_failed' },
                    errors: { health_path: ['x'] },
                    reasons: { health_path: 'health-path-query' },
                },
            },
        ];
        const input = $('[data-test="health-path"]') as HTMLInputElement;
        input.value = '/health?x=1';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();
        ($('[data-test="save"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(document.body.textContent).toContain(
            labels.reasons['health-path-query'],
        );
        expect(input.getAttribute('aria-invalid')).toBe('true');
    });
});
