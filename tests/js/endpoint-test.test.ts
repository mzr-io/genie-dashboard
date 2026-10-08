// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { Endpoint } from '../../resources/js/lib/endpoints';
import { testFields } from '../../resources/js/lib/endpoints';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { endpointLabels as labels } from '../../resources/js/locales/labels';

// Story 2.10: Test endpoint (UX-DR-135, 25, 26, 70, 276, 279, 282): the action per Endpoint, the test-values panel, a polite
// "Testing…", the sample viewer (a focusable labelled region, the body exactly as received, Copy), the shared error card
// with focus on its title, a stale result with Retry, field errors by parameter name, the rate-limit reason and the
// `aria-disabled` Test action for an empty path parameter.
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
const OP = '018f0000-0000-7000-8000-0000000000aa';
const BODY = '{"total":12345678901234567890.12,"rate":1.10}';

function endpoint(overrides: Partial<Endpoint> = {}): Endpoint {
    return {
        endpoint_id: 'ep-1',
        data_source_id: DS,
        method: 'GET',
        path: '/customers/{id}/revenue',
        path_ast: [],
        params: [
            { name: 'id', binding: 'fixed', value: 'c-42', kind: 'path' },
            {
                name: 'from',
                binding: 'date_range_from',
                value: null,
                kind: 'query',
            },
            { name: 'limit', binding: 'fixed', value: '10', kind: 'query' },
        ],
        headers: [
            { name: 'X-Team', binding: 'fixed', value: 'finance' },
            { name: 'X-Day', binding: 'period_start', value: null },
        ],
        body_template: null,
        read_only_query: false,
        revision: 4,
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
let replies: Array<Reply | Promise<Reply>> = [];

const ok = (json: unknown): Reply => ({ ok: true, status: 200, json });
const refused = (status: number, json: unknown = {}): Reply => ({
    ok: false,
    status,
    json,
});
const started = ok({
    data: {
        operation_id: OP,
        status: 'queued',
        expires_at: '2026-10-09T10:00:00Z',
        endpoint_revision: 4,
    },
});
const operation = (status: string, result: Record<string, unknown> | null) =>
    ok({
        data: {
            id: OP,
            kind: 'sample_fetch',
            status,
            result,
            expires_at: '2026-10-09T10:00:00Z',
        },
    });
const success = {
    ok: true,
    status: 200,
    latency_ms: 184,
    code: null,
    reason: null,
    host: 'api.example.com',
    request_id: 'req-1',
    endpoint_revision: 4,
};
const sample = ok({
    data: {
        status: 200,
        latency_ms: 184,
        body: BODY,
        expires_at: '2026-10-09T10:00:00Z',
    },
});

let wrapper: VueWrapper | null = null;

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await flushPromises();
}

async function type(selector: string, value: string): Promise<void> {
    const input = $(selector) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

const field = (key: string) => `[data-test="test-value"][data-field="${key}"]`;

async function openTest(endpoints: Endpoint[] = [endpoint()]): Promise<void> {
    replies = [ok(list(endpoints)), ok(source)];
    wrapper = mount(DataSourceEndpoints as never, {
        attachTo: document.body,
        props: { dataSourceId: DS },
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
    await click($('[data-test="test-endpoint"]'));
}

const run = () => $('[data-test="run-test"]') as HTMLButtonElement;
const posts = () => calls.filter((call) => call.method === 'POST');

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

                const reply = await next;

                return {
                    ok: reply.ok,
                    status: reply.status,
                    headers: { get: () => null },
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
    vi.useRealTimers();
});

describe('testFields', () => {
    it('makes one field per parameter and per date-bound header, a fixed value as the default', () => {
        expect(
            testFields(endpoint()).map((f) => [
                f.key,
                f.type,
                f.initial,
                f.path,
            ]),
        ).toEqual([
            ['id', 'text', 'c-42', true],
            ['from', 'date', '', false],
            ['limit', 'text', '10', false],
            ['header:X-Day', 'date', '', false],
        ]);
    });
});

describe('Test endpoint action and panel', () => {
    it('offers Test endpoint on each row, named for the request', async () => {
        replies = [ok(list([endpoint()])), ok(source)];
        wrapper = mount(DataSourceEndpoints as never, {
            attachTo: document.body,
            props: { dataSourceId: DS },
            global: { plugins: [createCatalogue()] },
        });
        await flushPromises();
        const button = $('[data-test="test-endpoint"]');

        expect(button?.getAttribute('aria-label')).toBe(
            labels.testAction('GET', '/customers/{id}/revenue'),
        );
        expect(button?.getAttribute('data-variant')).toBe('secondary');
    });

    it('shows one labelled input per parameter, dates for bound ones, and moves focus to the title', async () => {
        await openTest();

        expect($('[data-test="test-title"]')?.textContent).toContain(
            labels.testTitle,
        );
        expect(document.activeElement).toBe($('[data-test="test-title"]'));
        expect($$('[data-test="test-value"]')).toHaveLength(4);
        expect(($(field('id')) as HTMLInputElement).value).toBe('c-42');
        expect($(field('from'))?.getAttribute('type')).toBe('date');
        expect($(field('header:X-Day'))?.getAttribute('type')).toBe('date');
        expect(
            document.querySelector(`label[for="${$(field('limit'))?.id}"]`)
                ?.textContent,
        ).toContain('limit');
    });

    it('is aria-disabled with the parameter named while a path value is empty, and sends nothing', async () => {
        await openTest();
        await type(field('id'), '');

        expect(run().getAttribute('aria-disabled')).toBe('true');
        expect($('[data-slot="blocked-reason"]')?.textContent).toBe(
            labels.testMissing('id'),
        );
        expect(run().getAttribute('aria-describedby')).toBe(
            $('[data-slot="blocked-reason"]')?.id,
        );

        await click(run());

        expect(posts()).toHaveLength(0);
    });
});

describe('Running the test', () => {
    it('sends the values, says Testing…, then announces the result and shows the body exactly as received', async () => {
        await openTest();
        await type(field('from'), '2026-01-01');
        await type(field('header:X-Day'), '2026-02-01');
        let release: (reply: Reply) => void = () => {};
        replies = [
            started,
            new Promise<Reply>((resolve) => {
                release = resolve;
            }),
        ];
        await click(run());

        expect(posts()[0].url).toBe(
            '/api/v1/admin/data-sources/ds-1/endpoints/ep-1/test',
        );
        expect(posts()[0].body).toEqual({
            values: {
                id: 'c-42',
                from: '2026-01-01',
                limit: '10',
                'header:X-Day': '2026-02-01',
            },
        });
        expect($('[data-test="testing"]')?.textContent).toBe(labels.testing);
        expect($('[data-test="test-status"]')?.getAttribute('aria-live')).toBe(
            'polite',
        );

        replies.push(sample);
        release(operation('succeeded', success));
        await flushPromises();

        expect($('[data-test="testing"]')).toBeNull();
        expect($('[data-test="test-ok"]')?.textContent).toBe('✓ 200 · 184 ms');
        expect($('[data-test="sample-meta"]')?.textContent).toBe(
            labels.sampleStatus(200, 184),
        );

        const region = $('[data-test="sample-region"]');
        expect(region?.getAttribute('role')).toBe('region');
        expect(region?.getAttribute('tabindex')).toBe('0');
        expect(region?.getAttribute('aria-label')).toBe(labels.sampleRegion);
        expect($('[data-test="sample-body"]')?.textContent).toBe(BODY);
        expect(calls.at(-1)?.url).toBe(
            `/api/v1/admin/data-sources/ds-1/endpoints/ep-1/samples/${OP}`,
        );
    });

    it('copies the sample', async () => {
        const writeText = vi.fn(async () => {});
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        await openTest();
        replies = [started, operation('succeeded', success), sample];
        await click(run());
        await click($('[data-test="sample-copy"]'));

        expect(writeText).toHaveBeenCalledWith(BODY);
        expect($('[data-test="sample-copy-status"]')?.textContent).toContain(
            labels.sampleCopied,
        );
    });

    it('shows the error card with its technical details and focus on its title when the call fails', async () => {
        await openTest();
        replies = [
            started,
            operation('failed', {
                ok: false,
                status: null,
                latency_ms: null,
                code: 'host-not-allowlisted',
                reason: 'host_not_allowlisted',
                host: 'api.example.com',
                request_id: 'req-9',
                endpoint_revision: 4,
            }),
        ];
        await click(run());

        expect($('[data-test="fetch-error-title"]')?.textContent?.trim()).toBe(
            labels.testFailedTitle,
        );
        expect(document.activeElement).toBe(
            $('[data-test="fetch-error-title"]'),
        );
        expect($('[data-test="fetch-error-message"]')?.textContent).toContain(
            'allowlist',
        );
        expect($('[data-test="copy-request-id"]')).not.toBeNull();
        expect($('[data-test="sample-viewer"]')).toBeNull();
    });

    it('runs the test again from the card with Retry', async () => {
        await openTest();
        replies = [
            started,
            operation('failed', {
                ok: false,
                code: 'fetch-failed',
                reason: 'http_500',
                status: 500,
                host: 'api.example.com',
                request_id: 'r',
            }),
        ];
        await click(run());
        replies = [started, operation('succeeded', success), sample];
        await click($('[data-test="fetch-retry"]'));

        expect(posts()).toHaveLength(2);
        expect($('[data-test="sample-viewer"]')).not.toBeNull();
    });

    it('marks a result for a revision that moved as not current, with a Retry, and shows no body', async () => {
        await openTest();
        replies = [
            started,
            operation('stale', {
                ok: false,
                code: null,
                reason: 'stale',
                endpoint_revision: 5,
            }),
        ];
        await click(run());

        expect($('[data-test="sample-stale"]')?.textContent).toContain(
            labels.testStale(5),
        );
        expect($('[data-test="sample-viewer"]')).toBeNull();
        expect($('[data-test="test-ok"]')).toBeNull();
        expect(
            calls.filter((call) => call.url.includes('/samples/')),
        ).toHaveLength(0);

        replies = [started, operation('succeeded', success), sample];
        await click($('[data-test="stale-retry"]'));

        expect($('[data-test="sample-viewer"]')).not.toBeNull();
    });

    it('says the sample is gone when it can no longer be read', async () => {
        await openTest();
        replies = [started, operation('succeeded', success), refused(404)];
        await click(run());

        expect($('[data-test="sample-expired"]')?.textContent).toBe(
            labels.sampleExpired,
        );
    });

    it('puts a 422 on the named parameter and focuses it', async () => {
        await openTest();
        replies = [
            refused(422, {
                error: { code: 'validation_failed', request_id: 'r' },
                errors: { 'values.limit': ['Enter a value for limit.'] },
                reasons: { 'values.limit': 'param-value-required' },
            }),
        ];
        await click(run());

        const input = $(field('limit')) as HTMLInputElement;
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect($('[data-slot="field-error"]')?.textContent).toContain(
            'Enter a value for limit.',
        );
        expect(document.activeElement).toBe(input);
    });

    it('blocks the action with the wait after a 429 and enqueues nothing more', async () => {
        await openTest();
        replies = [
            refused(429, {
                error: { code: 'too_many_requests', retry_after: 7 },
            }),
        ];
        await click(run());

        expect(run().getAttribute('aria-disabled')).toBe('true');
        expect($('[data-slot="blocked-reason"]')?.textContent).toBe(
            labels.testThrottled(7),
        );

        await click(run());

        expect(posts()).toHaveLength(1);
    });

    it('shows the first server message in an alert when a 422 names no input', async () => {
        await openTest();
        replies = [
            refused(422, {
                error: { code: 'platform.validation_failed' },
                errors: {
                    endpoint: ['Only a GET or a read-only POST can be tested.'],
                },
            }),
        ];
        await click(run());

        expect($('[data-test="form-error"]')?.textContent).toContain(
            'read-only POST',
        );
        expect($('[data-test="fetch-error-card"]')).toBeNull();
    });

    it('says why Retry did nothing while a 429 wait is on', async () => {
        await openTest();
        replies = [
            started,
            operation('failed', {
                ok: false,
                code: 'fetch-failed',
                reason: 'http_500',
                status: 500,
                host: 'h',
                request_id: 'r',
            }),
        ];
        await click(run());
        // A second test is refused with a wait; the card's Retry is then pressed.
        replies = [
            refused(429, {
                error: { code: 'too_many_requests', retry_after: 9 },
            }),
        ];
        await click($('[data-test="fetch-retry"]'));
        const before = posts().length;
        await click(
            $('[data-test="fetch-retry"]') ?? $('[data-test="stale-retry"]'),
        );

        expect(posts()).toHaveLength(before);
        expect($('[data-test="retry-blocked"]')?.textContent).toBe(
            labels.testThrottled(9),
        );
    });
});
