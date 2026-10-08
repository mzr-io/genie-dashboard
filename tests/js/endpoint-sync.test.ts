// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { Endpoint } from '../../resources/js/lib/endpoints';
import { testDateFields } from '../../resources/js/lib/endpoints';
import { formatDateTime } from '../../resources/js/lib/format';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { endpointLabels as labels } from '../../resources/js/locales/labels';

// Story 2.14: the Endpoint row says when the last good response arrived ("Last success {time}"), that no call has succeeded yet, or that
// it is not scheduled and why; and the form takes a test date for each date-bound row, sends only those, and shows the server's refusal.
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            props: ['href'],
            setup:
                (props, { slots, attrs }) =>
                () =>
                    h('a', { ...attrs, href: props.href }, slots.default?.()),
        }),
        Head: { render: () => null },
        router: { visit: () => {}, on: () => () => {} },
        usePage: () => ({
            url: '/admin/data-sources/ds-1/endpoints',
            props: {
                shell: { can: { 'data_sources.manage': true }, items: [] },
            },
        }),
    };
});

const { default: DataSourceEndpoints } =
    await import('../../resources/js/pages/admin/DataSourceEndpoints.vue');

const DS = 'ds-1';

function endpoint(overrides: Partial<Endpoint> = {}): Endpoint {
    return {
        endpoint_id: 'ep-1',
        data_source_id: DS,
        method: 'GET',
        path: '/api/v2/finance/revenue',
        path_ast: [],
        params: [],
        headers: [],
        body_template: null,
        read_only_query: false,
        revision: 1,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-01T09:30:00Z',
        ...overrides,
    };
}

const list = (data: Endpoint[]) => ({
    data,
    meta: { total: data.length, matched: data.length },
});
const source = { data: { data_source_id: DS, name: 'Sales API' }, meta: {} };

type Reply = { ok: boolean; status: number; json: unknown };
type Call = { url: string; method: string; body: unknown };
const calls: Call[] = [];
let replies: Reply[] = [];
const ok = (json: unknown): Reply => ({ ok: true, status: 200, json });
const refused = (status: number, json: unknown = {}): Reply => ({
    ok: false,
    status,
    json,
});

let wrapper: VueWrapper | null = null;

async function mountPage() {
    wrapper = mount(DataSourceEndpoints as never, {
        attachTo: document.body,
        props: { dataSourceId: DS },
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await flushPromises();
}

async function pick(selector: string, value: string): Promise<void> {
    const select = $(selector) as HTMLSelectElement;
    select.value = value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    select.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function type(selector: string, value: string): Promise<void> {
    const input = $(selector) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

beforeEach(() => {
    setActivePinia(createPinia());
    calls.length = 0;
    replies = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                calls.push({
                    url,
                    method: init?.method ?? 'GET',
                    body: init?.body ? JSON.parse(init.body) : undefined,
                });
                const next = replies.shift();

                if (!next) {
                    throw new Error(`unexpected request ${url}`);
                }

                return {
                    ok: next.ok,
                    status: next.status,
                    json: async () => next.json,
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

describe('the scheduled-fetch status of an Endpoint row', () => {
    it('shows "Last success {time}", "No successful call yet" and "Not scheduled" with the reason', async () => {
        const sync = (state: string, extra: Record<string, unknown> = {}) => ({
            state,
            last_success_at: null,
            reason: null,
            missing_test_values: [],
            ...extra,
        });
        replies = [
            ok(
                list([
                    endpoint({
                        endpoint_id: 'a',
                        path: '/a',
                        sync: sync('succeeded', {
                            last_success_at: '2026-10-08T12:30:00Z',
                        }) as Endpoint['sync'],
                    }),
                    endpoint({
                        endpoint_id: 'b',
                        path: '/b',
                        sync: sync('waiting') as Endpoint['sync'],
                    }),
                    endpoint({
                        endpoint_id: 'c',
                        path: '/c',
                        sync: sync('not_scheduled', {
                            reason: 'test_values',
                            missing_test_values: ['from', 'header:X-Day'],
                        }) as Endpoint['sync'],
                    }),
                    endpoint({
                        endpoint_id: 'd',
                        path: '/d',
                        sync: sync('not_scheduled', {
                            reason: 'user_context',
                        }) as Endpoint['sync'],
                    }),
                    endpoint({
                        endpoint_id: 'e',
                        path: '/e',
                        sync: sync('not_scheduled', {
                            reason: 'no_interval',
                        }) as Endpoint['sync'],
                    }),
                    // An older server that sends no status at all is still "no successful call yet", never a crash.
                    endpoint({ endpoint_id: 'f', path: '/f' }),
                ]),
            ),
            ok(source),
        ];
        await mountPage();

        const text = (row: number, test: string) =>
            $$('tbody [data-slot="data-row"]')
                [row].querySelector(`[data-test="${test}"]`)
                ?.textContent?.trim();

        expect(text(0, 'sync-status')).toBe(
            labels.lastSuccess(formatDateTime('2026-10-08T12:30:00Z')),
        );
        expect(text(0, 'sync-reason')).toBeUndefined();
        expect(text(1, 'sync-status')).toBe(labels.noSuccessYet);
        expect(text(2, 'sync-status')).toBe(labels.notScheduled);
        expect(text(2, 'sync-reason')).toBe(
            labels.notScheduledReasons.test_values(['from', 'X-Day']),
        );
        expect(text(3, 'sync-status')).toBe(labels.notScheduled);
        expect(text(3, 'sync-reason')).toBe(
            labels.notScheduledReasons.user_context,
        );
        expect(text(4, 'sync-reason')).toBe(
            labels.notScheduledReasons.no_interval,
        );
        expect(text(5, 'sync-status')).toBe(labels.noSuccessYet);
        expect(
            $$('tbody [data-slot="data-row"]')[0]
                .querySelector('[data-test="sync-status"]')
                ?.getAttribute('data-state'),
        ).toBe('succeeded');
    });
});

describe('the test dates of the form', () => {
    it('lists the date-bound rows only, parameters then headers', () => {
        expect(
            testDateFields(
                [
                    { name: 'region', binding: 'fixed', value: 'emea' },
                    { name: 'from', binding: 'date_range_from', value: null },
                    { name: 'me', binding: 'user_id', value: null },
                    { name: '', binding: 'period_end', value: null },
                ],
                [
                    { name: 'X-Day', binding: 'period_start', value: null },
                    { name: 'X-Team', binding: 'fixed', value: 'finance' },
                ],
            ),
        ).toEqual([
            { key: 'from', name: 'from', header: false },
            { key: 'header:X-Day', name: 'X-Day', header: true },
        ]);
    });

    it('asks for a date per date-bound row, sends only the ones filled in, and shows the server refusal on the field', async () => {
        replies = [ok(list([])), ok(source)];
        await mountPage();
        await click($('[data-test="add-endpoint"]'));
        // The form's own request for the attribute options.
        replies.push(
            ok({ data: { bindings: [], attributes: [], members: [] } }),
        );

        expect($$('[data-test="test-date"]')).toHaveLength(0);

        await type('[data-test="path"]', '/revenue');
        document
            .querySelector<HTMLButtonElement>('[data-test="params-add"]')
            ?.click();
        await flushPromises();
        await type('[data-test="params-name"]', 'from');
        await pick('[data-test="params-binding"]', 'date_range_from');

        expect($$('[data-test="test-date"]')).toHaveLength(1);
        expect($('[data-test="test-dates"] legend')?.textContent).toContain(
            labels.testValuesTitle,
        );
        expect(
            $('[data-test="test-dates"] label')?.textContent?.trim(),
        ).toContain(labels.testValueLabel('from'));
        expect(($('[data-test="test-date"]') as HTMLInputElement).type).toBe(
            'date',
        );

        // Saved without a date: nothing is sent for it (the Endpoint is saved and simply not scheduled yet).
        replies = [
            ok({ data: endpoint({ endpoint_id: 'new', path: '/revenue' }) }),
            ok(list([endpoint({ endpoint_id: 'new', path: '/revenue' })])),
        ];
        await click($('[data-test="save"]'));
        expect(
            (
                calls.find((call) => call.method === 'POST')?.body as Record<
                    string,
                    unknown
                >
            )?.test_values,
        ).toBeUndefined();
    });

    it('sends the typed date by name, and focuses the field the server refuses', async () => {
        replies = [ok(list([])), ok(source)];
        await mountPage();
        await click($('[data-test="add-endpoint"]'));

        await type('[data-test="path"]', '/revenue');
        document
            .querySelector<HTMLButtonElement>('[data-test="params-add"]')
            ?.click();
        await flushPromises();
        await type('[data-test="params-name"]', 'from');
        await pick('[data-test="params-binding"]', 'date_range_from');
        await type('[data-test="test-date"]', '2026-10-01');

        replies = [
            refused(422, {
                errors: { 'test_values.from': ['Enter a date.'] },
                reasons: { 'test_values.from': 'param-date-invalid' },
            }),
        ];
        await click($('[data-test="save"]'));

        const sent = calls.find((call) => call.method === 'POST')
            ?.body as Record<string, unknown>;
        expect(sent.test_values).toEqual({ from: '2026-10-01' });
        expect($('[data-slot="field-error"]')?.textContent).toBeTruthy();
        expect(document.activeElement).toBe($('[data-test="test-date"]'));
        expect($('[data-test="test-date"]')?.getAttribute('aria-invalid')).toBe(
            'true',
        );
    });

    it('drops the date of a row that is no longer date-bound', async () => {
        replies = [
            ok(
                list([
                    endpoint({
                        params: [
                            {
                                name: 'from',
                                binding: 'date_range_from',
                                value: null,
                                kind: 'query',
                            },
                        ],
                        test_values: { from: '2026-10-01' },
                    }),
                ]),
            ),
            ok(source),
        ];
        await mountPage();
        await click($('[data-test="edit-endpoint"]'));

        expect(($('[data-test="test-date"]') as HTMLInputElement).value).toBe(
            '2026-10-01',
        );

        await pick('[data-test="params-binding"]', 'fixed');
        expect($$('[data-test="test-date"]')).toHaveLength(0);

        await type('[data-test="params-value"]', 'x');
        replies = [
            ok({ data: endpoint({ revision: 2 }) }),
            ok(list([endpoint({ revision: 2 })])),
        ];
        await click($('[data-test="save"]'));

        const sent = calls.find((call) => call.method === 'PUT')
            ?.body as Record<string, unknown>;
        expect(sent.test_values).toBeUndefined();
    });
});
