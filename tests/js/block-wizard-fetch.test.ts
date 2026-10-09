// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { setConnectionForTest } from '../../resources/js/composables/useConnection';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    belongsToEarlierParameters,
    failureOf,
    fetchedFacts,
} from '../../resources/js/lib/blockSample';
import type { StoredSample } from '../../resources/js/lib/blockSample';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';

// Story 3.9: Fetch from API in wizard step 2. The button starts a `draft_sample_fetch` Operation (nothing is sent from the browser to the API),
// the Operation is polled with the existing status endpoint, and the stored sample is read back. `fetch-ok` and the JSON tree show on success; the
// shared fetch-error-card shows for each failure with Retry, "Paste sample JSON instead", Technical details and Copy request ID, focus on its title;
// a 429 puts its wait on the button; offline disables Fetch with `offline-editing`; expired and stale results offer Retry; a sample taken with
// earlier parameters says so.
const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/admin/blocks/create',
    props: {},
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({
        props: ['href'],
        setup:
            (props, { slots, attrs }) =>
            () =>
                h('a', { ...attrs, href: props.href }, slots.default?.()),
    }),
    router: { visit: vi.fn(), on: () => () => {} },
}));

const { default: BlockWizard } =
    await import('../../resources/js/pages/admin/BlockWizard.vue');

const SOURCE = 'a0000000-0000-7000-8000-000000000001';
const ENDPOINT = 'e0000000-0000-7000-8000-000000000001';
const OPERATION = 'f0000000-0000-7000-8000-000000000001';
const BODY =
    '{"data":{"monthly":[{"month":"Jan","revenue":12345678901234567890.10,"rate":1.0},{"month":"Feb","revenue":2.50},{"month":"Mar","revenue":3}]}}';

function draft(overrides: Partial<WizardDraft> = {}): WizardDraft {
    return {
        draft_id: 'd-1',
        block_id: 'b-1',
        name: 'Revenue today',
        type: 'kpi-card',
        category: 'General',
        category_id: 'c-general',
        version: '1.0',
        state: 'draft',
        revision: 1,
        lock_epoch: 1,
        step: 2,
        owner_membership_id: 'm-1',
        owner: null,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-02T10:00:00Z',
        config: {
            schema_version: 1,
            description: 'Daily revenue',
            source: { data_source_id: SOURCE, endpoint_id: ENDPOINT },
        },
        ...overrides,
    } as WizardDraft;
}

const NODES = [
    {
        id: 0,
        parent: null,
        depth: 0,
        key: null,
        path: '$',
        display: '$',
        type: 'object',
        example: null,
        count: null,
    },
    {
        id: 1,
        parent: 0,
        depth: 1,
        key: 'data',
        path: '$.data',
        display: 'data',
        type: 'object',
        example: null,
        count: null,
    },
];

function stored(overrides: Partial<StoredSample> = {}): StoredSample {
    return {
        origin: 'fetched',
        text: BODY,
        size_bytes: BODY.length,
        pasted_at: '2026-10-09T09:14:00Z',
        expires_at: '2026-10-09T10:14:00Z',
        contains_personal_data: true,
        sample_revision: 1,
        request_key: null,
        fetch_status: 200,
        fetch_latency_ms: 312,
        valid: true,
        reason: null,
        line: null,
        column: null,
        problem: null,
        limit: null,
        setting: null,
        shape: {
            records: {
                path: '$.data.monthly[*]',
                display: 'data.monthly[]',
                count: 3,
            },
            scalars: 4,
            arrays: 1,
            objects: 2,
            nodes: NODES,
            tables: {},
        },
        ...overrides,
    } as StoredSample;
}

type Reply = { status: number; json: unknown };
type Call = { url: string; method: string; body: Record<string, any> | null };
const calls: Call[] = [];
let lockReply: Record<string, unknown> = { enabled: false };
let startReply: () => Reply;
let operationReply: () => Reply;
let sampleReply: (body: Record<string, any> | null) => Reply;
let lastStart: Record<string, any> | null = null;
let wrapper: VueWrapper | null = null;
const reply = (r: Reply) => ({
    ok: r.status < 400,
    status: r.status,
    json: async () => r.json,
});
const started = (): Reply => ({
    status: 202,
    json: {
        data: {
            operation_id: OPERATION,
            status: 'queued',
            expires_at: '2026-10-09T09:20:00Z',
            draft_revision: 1,
        },
    },
});
const operation = (
    status: string,
    result: Record<string, unknown> | null,
): Reply => ({
    status: 200,
    json: {
        data: {
            id: OPERATION,
            kind: 'draft_sample_fetch',
            status,
            result,
            expires_at: '2026-10-09T09:20:00Z',
        },
    },
});
const success = (extra: Record<string, unknown> = {}): Reply =>
    operation('succeeded', {
        ok: true,
        status: 200,
        latency_ms: 312,
        code: null,
        reason: null,
        host: 'api.example.com',
        request_id: 'req-1',
        draft_revision: 1,
        sample_revision: 1,
        records: 3,
        records_path: 'data.monthly[]',
        ...extra,
    });
const failure = (
    code: string,
    reason: string,
    extra: Record<string, unknown> = {},
): Reply =>
    operation('failed', {
        ok: false,
        status: null,
        latency_ms: null,
        code,
        reason,
        host: 'api.example.com',
        request_id: 'req-9',
        ...extra,
    });

const fetchCalls = () => calls.filter((c) => c.url.endsWith('/sample/fetch'));

async function mountPage(
    config: Record<string, unknown> | null = null,
    search = '?draft=d-1&step=2',
) {
    window.history.replaceState({}, '', `/admin/blocks/create${search}`);
    current = draft(config === null ? {} : ({ config } as never));
    wrapper = mount(BlockWizard as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
}

let current: WizardDraft = draft();
const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const text = (selector: string) =>
    $(selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
const blocked = (selector: string) =>
    $(selector)?.getAttribute('aria-disabled') === 'true';
const polite = () =>
    document.querySelector('[data-announcer=polite]')?.textContent ?? '';

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await flushPromises();
}

async function acknowledge(): Promise<void> {
    await click($('[data-test=fetch-personal-data]'));
}

async function fetchNow(): Promise<void> {
    await acknowledge();
    await click($('[data-test=fetch]'));
    // The Operation is polled at once: let the awaited chain settle.
    await flushPromises();
    await flushPromises();
}

beforeEach(() => {
    setActivePinia(createPinia());
    page.props = {
        shell: {
            area: 'admin',
            items: [],
            can: { 'blocks.edit': true, 'blocks.publish': true },
        },
    };
    setConnectionForTest(true);
    calls.length = 0;
    lastStart = null;
    sessionStorage.clear();
    lockReply = { enabled: false };
    startReply = started;
    operationReply = () => success();
    sampleReply = () => ({ status: 200, json: { data: null } });
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                if (/\/lock(\/|$)/.test(url)) {
                    return reply({
                        status: 200,
                        json: { data: lockReply },
                    });
                }

                const method = init?.method ?? 'GET';
                const body = init?.body ? JSON.parse(init.body) : null;

                calls.push({ url, method, body });

                if (url === '/api/v1/admin/blocks/categories') {
                    return reply({
                        status: 200,
                        json: {
                            data: [
                                {
                                    id: 'c-general',
                                    name: 'General',
                                    archived: false,
                                },
                            ],
                        },
                    });
                }

                if (url === '/api/v1/admin/blocks/data-sources') {
                    return reply({
                        status: 200,
                        json: {
                            data: [
                                {
                                    data_source_id: SOURCE,
                                    name: 'Finance API',
                                    health: 'healthy',
                                    live_capable: false,
                                    revision: 3,
                                },
                            ],
                        },
                    });
                }

                if (url === '/api/v1/admin/blocks/refresh-intervals') {
                    return reply({
                        status: 200,
                        json: { data: { intervals: [60] } },
                    });
                }

                if (/\/blocks\/data-sources\/[^/]+\/endpoints$/.test(url)) {
                    return reply({
                        status: 200,
                        json: {
                            data: [
                                {
                                    endpoint_id: ENDPOINT,
                                    data_source_id: SOURCE,
                                    method: 'GET',
                                    path: '/revenue',
                                    params: [
                                        {
                                            name: 'region',
                                            binding: 'fixed',
                                            value: 'EU',
                                            kind: 'query',
                                        },
                                    ],
                                    read_only_query: false,
                                    requires_user_context: false,
                                    revision: 2,
                                    revision_id:
                                        'r0000000-0000-7000-8000-000000000001',
                                },
                            ],
                        },
                    });
                }

                if (url.endsWith('/sample/fetch')) {
                    lastStart = body;

                    return reply(startReply());
                }

                if (url.startsWith('/api/v1/operations/')) {
                    return reply(operationReply());
                }

                if (url.endsWith('/sample')) {
                    return reply(sampleReply(lastStart));
                }

                if (method === 'PUT') {
                    return reply({
                        status: 200,
                        json: {
                            data: draft({
                                revision: 2,
                                step: body.step,
                                config: body.config,
                            }),
                        },
                    });
                }

                return reply({ status: 200, json: { data: current } });
            },
        ),
    );
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    resetAnnouncer();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Fetch from API: starting a fetch', () => {
    it('is the default, aria-disabled until the personal-data box is ticked, with the reason inline, and sends nothing while blocked', async () => {
        await mountPage();

        expect(
            $('[data-test=sample-mode] [aria-checked=true]')?.textContent,
        ).toContain('Fetch from API');
        expect(blocked('[data-test=fetch]')).toBe(true);
        expect(text('[data-slot=blocked-reason]')).toContain(
            'Confirm the personal data box first.',
        );

        await click($('[data-test=fetch]'));
        expect(fetchCalls()).toHaveLength(0);

        await acknowledge();
        expect(blocked('[data-test=fetch]')).toBe(false);
    });

    it('stays aria-disabled until a Data Source and an Endpoint are chosen, with the form reason first', async () => {
        await mountPage({ schema_version: 1, description: 'x' });

        expect(blocked('[data-test=fetch]')).toBe(true);
        expect(text('[data-slot=blocked-reason]')).toContain(
            'Choose a data source first.',
        );
    });

    it('POSTs the Data Source, the Endpoint, the parameters, the request key, the acknowledgement and the lock claim, then polls the Operation', async () => {
        sampleReply = (start) => ({
            status: 200,
            json: { data: stored({ request_key: start?.request_key }) },
        });
        await mountPage();
        await fetchNow();

        expect(fetchCalls()).toHaveLength(1);
        expect(fetchCalls()[0].url).toBe(
            '/api/v1/admin/blocks/drafts/d-1/sample/fetch',
        );
        expect(fetchCalls()[0].method).toBe('POST');
        expect(fetchCalls()[0].body).toMatchObject({
            data_source_id: SOURCE,
            endpoint_id: ENDPOINT,
            bindings: {},
            range: null,
            period: null,
            contains_personal_data: true,
            lock_epoch: 1,
        });
        expect(typeof fetchCalls()[0].body?.request_key).toBe('string');
        expect(
            calls.some((c) => c.url === `/api/v1/operations/${OPERATION}`),
        ).toBe(true);
    });

    it("sends the block's own value for a Fixed parameter", async () => {
        await mountPage({
            schema_version: 1,
            source: {
                data_source_id: SOURCE,
                endpoint_id: ENDPOINT,
                bindings: { region: 'US' },
            },
        });
        await fetchNow();

        expect(fetchCalls()[0].body?.bindings).toEqual({ region: 'US' });
    });

    it('shows a polite Fetching status while the Operation runs', async () => {
        let polls = 0;

        operationReply = () => {
            polls += 1;

            return polls === 1 ? operation('running', null) : success();
        };
        vi.useFakeTimers({ shouldAdvanceTime: true });
        await mountPage();
        await acknowledge();
        await click($('[data-test=fetch]'));
        await flushPromises();

        expect(text('[data-test=fetch-running]')).toBe('Fetching the sample…');
        expect($('[data-test=fetch-running]')?.getAttribute('role')).toBe(
            'status',
        );
        expect(blocked('[data-test=fetch]')).toBe(true);
    });
});

describe('Fetch from API: success', () => {
    it('shows msg:fetch-ok with the status, time, count and path, announces it politely and shows the JSON tree', async () => {
        sampleReply = (start) => ({
            status: 200,
            json: { data: stored({ request_key: start?.request_key }) },
        });
        await mountPage();
        await fetchNow();

        expect(text('[data-test=fetch-ok]')).toBe(
            '✓ 200 · 312 ms · 3 records found at data.monthly[]',
        );
        expect(en['fetch-ok']).toBe(
            '✓ {status} · {ms} ms · {count} records found at {path}',
        );
        expect(polite()).toContain('✓ 200 · 312 ms · 3 records found');
        expect(text('[data-test=sample-summary]')).toMatch(
            /^Fetched \d\d:\d\d · \d+ bytes$/,
        );
        expect(text('[data-test=sample-badge]')).toBe(en['sample-wizard']);
        expect($('[data-test=fetch-error-card]')).toBeNull();

        await click($('[data-test=view-json]'));
        expect($('[data-test=sample-structure]')).not.toBeNull();
        expect($$('[data-test=tree-node]').length).toBeGreaterThan(0);
    });

    it('says there is no list of records when the sample has none', async () => {
        sampleReply = (start) => ({
            status: 200,
            json: {
                data: stored({
                    request_key: start?.request_key,
                    shape: { ...stored().shape!, records: null },
                }),
            },
        });
        await mountPage();
        await fetchNow();

        expect(text('[data-test=fetch-ok]')).toBe(
            '✓ 200 · 312 ms · no list of records found',
        );
    });

    it('clears the out-of-date notice when the sample was taken with the current parameters, and keeps it (with the earlier-parameters notice) when not', async () => {
        const marked = {
            schema_version: 1,
            source: {
                data_source_id: SOURCE,
                endpoint_id: ENDPOINT,
                sample_out_of_date: true,
            },
        };

        sampleReply = (start) => ({
            status: 200,
            json: { data: stored({ request_key: start?.request_key }) },
        });
        await mountPage(marked);
        expect($('[data-test=out-of-date]')).not.toBeNull();
        await fetchNow();
        expect($('[data-test=out-of-date]')).toBeNull();
        expect($('[data-test=earlier-parameters]')).toBeNull();

        wrapper?.unmount();
        document.body.innerHTML = '';
        sampleReply = () => ({
            status: 200,
            json: { data: stored({ request_key: 'earlier-parameters' }) },
        });
        await mountPage(marked);
        await fetchNow();

        expect($('[data-test=out-of-date]')).not.toBeNull();
        expect(text('[data-test=earlier-parameters]')).toContain(
            'belongs to earlier parameters',
        );
    });

    it('replaces the sample on a re-fetch: the second result is the one shown', async () => {
        let n = 0;

        sampleReply = (start) => {
            // The read when the page opens is not a fetch: only a started one counts.
            n += start === null ? 0 : 1;

            return {
                status: 200,
                json: {
                    data: stored({
                        request_key: start?.request_key,
                        sample_revision: Math.max(n, 1),
                        fetch_latency_ms: n <= 1 ? 312 : 120,
                    }),
                },
            };
        };
        await mountPage();
        await fetchNow();
        expect(text('[data-test=fetch-ok]')).toContain('312 ms');

        await click($('[data-test=fetch]'));
        await flushPromises();
        expect(fetchCalls()).toHaveLength(2);
        expect(text('[data-test=fetch-ok]')).toContain('120 ms');
    });
});

describe('Fetch from API: failures', () => {
    it.each([
        [
            'host-not-allowlisted',
            'host_not_allowlisted',
            "isn't on your workspace allowlist",
        ],
        ['blocked-address', 'blocked_address', "can't call loopback"],
        [
            'response-too-large',
            'connector.limit_exceeded',
            'Response too large',
        ],
        [
            'not-json',
            'connector.not_json:content_type',
            'returned HTML, not JSON',
        ],
        ['fetch-failed', 'http_500', "couldn't reach Finance API"],
    ])(
        '%s shows the error card with its message, Retry, Paste sample JSON instead, Technical details and Copy request ID, and focuses the title',
        async (code, reason, message) => {
            operationReply = () =>
                failure(code, reason, {
                    status: code === 'fetch-failed' ? 500 : 200,
                    size_bytes: 9000,
                    limit_bytes: 5000,
                });
            await mountPage();
            await fetchNow();

            expect($('[data-test=fetch-error-card]')).not.toBeNull();
            expect(text('[data-test=fetch-error-message]')).toContain(message);
            expect(text('[data-test=fetch-error-title]')).toContain(
                'Fetch failed',
            );
            expect($('[data-test=fetch-retry]')).not.toBeNull();
            expect(text('[data-test=paste-instead]')).toBe(
                'Paste sample JSON instead',
            );
            expect(text('[data-test=copy-request-id]')).not.toBe('');
            expect(document.body.textContent).toContain('Technical details');
            expect(document.activeElement).toBe(
                $('[data-test=fetch-error-title]'),
            );
            // Nothing is shown as a sample and the success line is absent.
            expect($('[data-test=fetch-ok]')).toBeNull();
            expect($('[data-test=sample-in-use]')).toBeNull();
        },
    );

    it('says the response was too large with both sizes and never shows a truncated sample', async () => {
        operationReply = () =>
            failure('response-too-large', 'connector.limit_exceeded', {
                size_bytes: 4097,
                limit_bytes: 4096,
            });
        await mountPage();
        await fetchNow();

        expect(text('[data-test=fetch-error-message]')).toContain(
            'Nothing was shown',
        );
        expect($('[data-test=sample-in-use]')).toBeNull();
    });

    it('Retry starts another fetch', async () => {
        operationReply = () => failure('fetch-failed', 'http_500');
        await mountPage();
        await fetchNow();
        expect(fetchCalls()).toHaveLength(1);

        operationReply = () => success();
        sampleReply = (start) => ({
            status: 200,
            json: { data: stored({ request_key: start?.request_key }) },
        });
        await click($('[data-test=fetch-retry]'));
        await flushPromises();

        expect(fetchCalls()).toHaveLength(2);
        expect($('[data-test=fetch-error-card]')).toBeNull();
        expect(text('[data-test=fetch-ok]')).toContain('✓ 200');
    });

    it('Paste sample JSON instead switches to the paste panel and focuses the text area', async () => {
        operationReply = () => failure('not-json', 'x');
        await mountPage();
        await fetchNow();
        await click($('[data-test=paste-instead]'));

        expect($('[data-test=json-textarea]')).not.toBeNull();
        expect($('[data-test=fetch]')).toBeNull();
        expect(document.activeElement).toBe($('[data-test=json-textarea]'));
    });

    it('an Operation with no summary is a generic fetch-failed card', async () => {
        operationReply = () => operation('failed', null);
        await mountPage();
        await fetchNow();

        expect(text('[data-test=fetch-error-message]')).toContain(
            "couldn't reach",
        );
    });
});

describe('Fetch from API: expired, stale and refused', () => {
    it('shows an expired Operation with Retry and nothing stored', async () => {
        operationReply = () => operation('expired', null);
        await mountPage();
        await fetchNow();

        expect(text('[data-test=fetch-expired]')).toContain(
            'took too long or expired',
        );
        expect($('[data-test=sample-in-use]')).toBeNull();

        operationReply = () => success();
        sampleReply = (start) => ({
            status: 200,
            json: { data: stored({ request_key: start?.request_key }) },
        });
        await click($('[data-test=fetch-expired-retry]'));
        await flushPromises();
        expect(fetchCalls()).toHaveLength(2);
        expect($('[data-test=fetch-expired]')).toBeNull();
    });

    it('treats a sample that is already gone when read back as expired', async () => {
        sampleReply = () => ({ status: 200, json: { data: null } });
        await mountPage();
        await fetchNow();

        expect($('[data-test=fetch-expired]')).not.toBeNull();
    });

    it('shows a stale Operation (the edit lock was taken over) with Retry', async () => {
        operationReply = () =>
            operation('stale', { ok: false, reason: 'lock_lost' });
        await mountPage();
        await fetchNow();

        expect(text('[data-test=fetch-stale]')).toContain('not current');
        expect($('[data-test=fetch-stale-retry]')).not.toBeNull();
    });

    it.each([
        [
            {
                status: 422,
                json: {
                    error: { code: 'blocks.sample_invalid' },
                    reason: 'setting_not_configured',
                    setting: 'DASHFLOW_SAMPLE_FETCH_WINDOW_SECONDS',
                },
            },
            'DASHFLOW_SAMPLE_FETCH_WINDOW_SECONDS is not set',
        ],
        [
            {
                status: 422,
                json: {
                    error: { code: 'blocks.sample_invalid' },
                    reason: 'ttl_not_configured',
                },
            },
            'sample lifetime is not set',
        ],
        [
            {
                status: 422,
                json: {
                    error: { code: 'blocks.sample_invalid' },
                    reason: 'limit_not_configured',
                    check: { setting: 'max_bytes' },
                },
            },
            'size limit is not set',
        ],
        [
            {
                status: 422,
                json: {
                    error: { code: 'blocks.sample_invalid' },
                    reason: 'endpoint_not_found',
                },
            },
            'no longer exists',
        ],
        [
            {
                status: 422,
                json: {
                    error: { code: 'platform.validation_failed' },
                    errors: {
                        'values.region': [
                            'The value of region cannot contain control characters.',
                        ],
                    },
                },
            },
            'The value of region cannot contain control characters.',
        ],
        [
            {
                status: 423,
                json: { error: { code: 'platform.edit_lock_lost' } },
            },
            'no longer hold the edit lock',
        ],
        [
            { status: 500, json: { error: { code: 'x' } } },
            "couldn't start the fetch",
        ],
    ])(
        'says in words why the server would not start the fetch (%#) and starts nothing',
        async (answer, message) => {
            startReply = () => answer as Reply;
            await mountPage();
            await fetchNow();

            expect(text('[data-test=fetch-refusal]')).toContain(message);
            expect(
                calls.some((c) => c.url.startsWith('/api/v1/operations/')),
            ).toBe(false);
            expect($('[data-test=fetch-error-card]')).toBeNull();
            expect(blocked('[data-test=fetch]')).toBe(false);
        },
    );
});

describe('Fetch from API: rate limit and offline', () => {
    it('puts the 429 wait on the button, blocks it with the reason, and frees it when the wait ends', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        startReply = () => ({
            status: 429,
            json: {
                error: { code: 'platform.too_many_requests', retry_after: 3 },
                reason: 'sample-fetch-throttled',
            },
        });
        await mountPage();
        await fetchNow();

        expect(text('[data-test=fetch]')).toBe('Wait 3 s');
        expect(blocked('[data-test=fetch]')).toBe(true);
        expect(text('[data-slot=blocked-reason]')).toContain(
            'Try again in 3 seconds',
        );

        await vi.advanceTimersByTimeAsync(3100);
        await flushPromises();

        expect(text('[data-test=fetch]')).toBe('Fetch sample');
        expect(blocked('[data-test=fetch]')).toBe(false);
    });

    it('is disabled while offline with msg:offline-editing as its reason, and sends nothing', async () => {
        await mountPage();
        await acknowledge();
        setConnectionForTest(false);
        await flushPromises();

        expect(blocked('[data-test=fetch]')).toBe(true);
        expect(text('[data-slot=blocked-reason]')).toBe(en['offline-editing']);

        await click($('[data-test=fetch]'));
        expect(fetchCalls()).toHaveLength(0);
    });

    it('needs the edit lock: a read-only viewer cannot fetch', async () => {
        lockReply = {
            enabled: true,
            status: 'held',
            holder: { name: 'Ada', since: '2026-10-09T09:00:00Z' },
        };
        await mountPage();
        await acknowledge();
        await click($('[data-test=fetch]'));
        expect(fetchCalls()).toHaveLength(0);
    });
});

describe('Fetch from API: a fetched sample on reopen', () => {
    it('restores it: Fetch mode, the Fetched time, fetch-ok from the stored status and records, and no earlier-parameters notice for the same request', async () => {
        sampleReply = () => ({
            status: 200,
            json: { data: stored({ request_key: null }) },
        });
        await mountPage();

        expect(
            $('[data-test=sample-mode] [aria-checked=true]')?.textContent,
        ).toContain('Fetch from API');
        expect(text('[data-test=fetch-ok]')).toBe(
            '✓ 200 · 312 ms · 3 records found at data.monthly[]',
        );
        expect(text('[data-test=sample-summary]')).toMatch(/^Fetched /);
        expect($('[data-test=earlier-parameters]')).toBeNull();
    });

    it('says a restored sample belongs to earlier parameters when its request key differs', async () => {
        sampleReply = () => ({
            status: 200,
            json: { data: stored({ request_key: 'something-else' }) },
        });
        await mountPage();

        expect(text('[data-test=earlier-parameters]')).toContain(
            'belongs to earlier parameters',
        );
    });
});

describe('blockSample helpers (Story 3.9)', () => {
    it('failureOf maps an unknown code to a generic failure and keeps the detail', () => {
        expect(
            failureOf(
                {
                    code: null,
                    status: 502,
                    request_id: 'r',
                    host: 'h',
                    reason: 'x',
                },
                'failed',
            ),
        ).toMatchObject({
            code: 'fetch-failed',
            status: 502,
            requestId: 'r',
            host: 'h',
            reason: 'x',
        });
        expect(failureOf(null, 'expired')).toMatchObject({
            code: 'fetch-failed',
            reason: 'expired',
            status: null,
        });
        expect(
            failureOf({ code: 'not-json', reason: 'p' }, 'failed').code,
        ).toBe('not-json');
    });

    it('belongsToEarlierParameters is only for a fetched sample with a different key', () => {
        expect(belongsToEarlierParameters(null, 'a')).toBe(false);
        expect(
            belongsToEarlierParameters(stored({ request_key: 'a' }), 'a'),
        ).toBe(false);
        expect(
            belongsToEarlierParameters(stored({ request_key: 'a' }), 'b'),
        ).toBe(true);
        expect(
            belongsToEarlierParameters(
                stored({ origin: 'pasted', request_key: null }),
                'b',
            ),
        ).toBe(false);
    });

    it('fetchedFacts reads the status, the time and the records of a fetched sample only', () => {
        expect(fetchedFacts(stored())).toEqual({
            status: 200,
            ms: 312,
            count: 3,
            path: 'data.monthly[]',
        });
        expect(fetchedFacts(stored({ origin: 'pasted' }))).toBeNull();
        expect(fetchedFacts(null)).toBeNull();
    });
});
