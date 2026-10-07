// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    DataSourceError,
    PollTimeout,
    POLL_DEADLINE_MS,
    pollOperation,
} from '../../resources/js/lib/dataSources';
import { createCatalogue } from '../../resources/js/lib/i18n';
import {
    dataSourceLabels as labels,
    technicalDetailsLabels,
} from '../../resources/js/locales/labels';

// Story 2.5: the Test connection control of the Data source form (UX-DR-135, 207, 22, 70, 276, 282): the button and its
// blocked states, the polite "Testing…" status, polling of the Operation, `test-ok` with the real status and latency and
// the fetch error card with its technical details, Retry, Copy request ID and focus on its title.
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
        router: { visit: vi.fn(), on: () => () => {} },
        usePage: () => ({
            url: '/admin/data-sources',
            props: {
                shell: { can: { 'data_sources.manage': true }, items: [] },
            },
        }),
    };
});

const { default: DataSourceForm } =
    await import('../../resources/js/pages/admin/DataSourceForm.vue');

type Reply = {
    ok: boolean;
    status: number;
    json: unknown;
    headers?: Record<string, string>;
};
type Call = { url: string; method: string; body: any };
const calls: Call[] = [];
let replies: Array<Reply | Promise<Reply>> = [];

const ok = (json: unknown): Reply => ({ ok: true, status: 200, json });
const accepted = (id = 'op-1'): Reply => ({
    ok: true,
    status: 202,
    json: { data: { operation_id: id, status: 'queued', expires_at: 'x' } },
});
const refused = (status: number, json: unknown = {}): Reply => ({
    ok: false,
    status,
    json,
});
const operation = (
    status: string,
    result: Record<string, unknown> | null = null,
): Reply =>
    ok({
        data: {
            id: 'op-1',
            kind: 'connection_test',
            status,
            result,
            expires_at: 'x',
        },
    });
const summary = (over: Record<string, unknown> = {}) => ({
    ok: true,
    status: 200,
    latency_ms: 184,
    code: null,
    reason: null,
    host: 'api.example.com',
    request_id: 'req-test-1',
    ...over,
});

let wrapper: VueWrapper | null = null;

async function mountForm(props: Record<string, unknown> = {}) {
    wrapper = mount(DataSourceForm as never, {
        attachTo: document.body,
        props,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const testButton = () => $('[data-test="test-connection"]') as HTMLElement;

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

async function fill(): Promise<void> {
    await type('[data-test="name"]', 'Sales API');
    await type('[data-test="base-url"]', 'https://api.example.com/v1');
}

/** Lets the polling timers of the page run (the first read comes after the interval). */
async function tick(ms = 1000): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
    await flushPromises();
}

const posts = () => calls.filter((call) => call.method === 'POST');

beforeEach(() => {
    setActivePinia(createPinia());
    calls.length = 0;
    replies = [];
    window.history.replaceState({}, '', '/admin/data-sources/create');
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
                    headers: {
                        get: (name: string) => reply.headers?.[name] ?? null,
                    },
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

describe('Test connection button', () => {
    it('sits beside Save, secondary, enabled, and sends nothing until pressed', async () => {
        await mountForm();

        expect(testButton().textContent?.trim()).toBe(labels.testConnection);
        expect(testButton().getAttribute('type')).toBe('button');
        expect(testButton().getAttribute('aria-disabled')).toBeNull();
        expect(
            testButton().parentElement?.contains($('[data-test="save"]')),
        ).toBe(true);
        expect(calls).toHaveLength(0);
    });

    it('is disabled while the edit form loads', async () => {
        replies = [new Promise<Reply>(() => {})];
        await mountForm({ dataSourceId: 'ds-1' });

        expect(
            ($('[data-test="test-connection"]') as HTMLButtonElement | null)
                ?.disabled ?? true,
        ).toBe(true);
    });
});

describe('Testing a form', () => {
    it('shows a polite Testing… status, polls the Operation and ends with test-ok and the real values', async () => {
        vi.useFakeTimers();
        await mountForm();
        await fill();
        replies = [
            accepted(),
            operation('queued'),
            operation('running'),
            operation('succeeded', summary()),
        ];

        await click(testButton());

        // The status region is polite and exists before its text changes.
        const region = $('[data-test="test-status"]') as HTMLElement;
        expect(region.getAttribute('role')).toBe('status');
        expect(region.getAttribute('aria-live')).toBe('polite');
        expect(region.textContent).toContain(labels.testing);
        expect(posts()).toHaveLength(1);
        expect(posts()[0].url).toBe(
            '/api/v1/admin/data-sources/test-connection',
        );

        // The first read came with the start (queued); the next ones follow every second.
        await tick();
        expect(region.textContent).toContain(labels.testing);
        expect($('[data-test="test-ok"]')).toBeNull();

        await tick();
        expect($('[data-test="test-ok"]')?.textContent?.trim()).toBe(
            '✓ Connected · 200 · 184 ms',
        );
        expect(region.textContent).not.toContain(labels.testing);
        expect($('[data-test="fetch-error-card"]')).toBeNull();
        expect(calls.map((call) => call.url)).toEqual([
            '/api/v1/admin/data-sources/test-connection',
            '/api/v1/operations/op-1',
            '/api/v1/operations/op-1',
            '/api/v1/operations/op-1',
        ]);
        // Nothing was saved and the form is not dirty because of the test.
        expect(
            calls.filter((call) => call.url === '/api/v1/admin/data-sources'),
        ).toHaveLength(0);
        expect(($('[data-test="save"]') as HTMLButtonElement).disabled).toBe(
            false,
        );
    });

    it('sends the form as typed, with the typed secret, without the password and without autosaving anything', async () => {
        vi.useFakeTimers();
        await mountForm();
        await fill();
        const select = $('[data-test="auth-type"]') as HTMLSelectElement;
        select.value = 'bearer';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        await type('[data-test="secret-bearer_token"]', 'typed-token-1');
        replies = [accepted(), operation('succeeded', summary())];

        await click(testButton());
        await tick();

        const body = posts()[0].body;
        expect(body).toMatchObject({
            name: 'Sales API',
            base_url: 'https://api.example.com/v1',
            auth_type: 'bearer',
            secrets: { bearer_token: 'typed-token-1' },
        });
        // A test needs no password: the form's confirmation step is for saving.
        expect('confirm_password' in body).toBe(false);
        expect('data_source_id' in body).toBe(false);

        // The secret is in no storage.
        expect(
            JSON.stringify(Object.entries(window.localStorage)),
        ).not.toContain('typed-token-1');
        expect(
            JSON.stringify(Object.entries(window.sessionStorage)),
        ).not.toContain('typed-token-1');
    });

    it('names the saved Data Source when it edits one', async () => {
        vi.useFakeTimers();
        replies = [
            ok({
                data: {
                    data_source_id: 'ds-1',
                    name: 'Sales API',
                    base_url: 'https://api.example.com/v1',
                    scheme: 'https',
                    host: 'api.example.com',
                    port: 443,
                    auth_type: 'none',
                    api_key_name: null,
                    api_key_placement: null,
                    headers: [],
                    secrets: {},
                    timeout_seconds: null,
                    max_response_bytes: null,
                    max_pages: null,
                    live_capable: false,
                    revision: 3,
                },
                meta: {
                    ceilings: {
                        timeout_seconds: null,
                        max_response_bytes: null,
                        max_pages: null,
                    },
                },
            }),
        ];
        await mountForm({ dataSourceId: 'ds-1' });
        replies = [accepted(), operation('succeeded', summary())];

        await click(testButton());
        await tick();

        expect(posts()[0].body.data_source_id).toBe('ds-1');
        expect($('[data-test="test-ok"]')).not.toBeNull();
    });

    it('blocks the button with its reason while a test runs, and a second press sends nothing', async () => {
        vi.useFakeTimers();
        await mountForm();
        await fill();
        replies = [
            accepted(),
            operation('running'),
            operation('succeeded', summary()),
        ];

        await click(testButton());

        expect(testButton().getAttribute('aria-disabled')).toBe('true');
        const reason = document.getElementById(
            testButton().getAttribute('aria-describedby') as string,
        );
        expect(reason?.textContent).toBe(labels.testRunning);
        expect(document.activeElement).not.toBeNull();

        await click(testButton());
        expect(posts()).toHaveLength(1);

        await tick();
        await tick();
        expect(testButton().getAttribute('aria-disabled')).toBeNull();
        expect(testButton().getAttribute('aria-describedby')).toBeNull();
    });

    it('lets Save work without a test', async () => {
        await mountForm();

        expect(($('[data-test="save"]') as HTMLButtonElement).disabled).toBe(
            false,
        );
        expect(calls).toHaveLength(0);
    });

    it('drops the result when the form changes, so it never describes other values', async () => {
        vi.useFakeTimers();
        await mountForm();
        await fill();
        replies = [accepted(), operation('succeeded', summary())];

        await click(testButton());
        await tick();
        expect($('[data-test="test-ok"]')).not.toBeNull();

        await type('[data-test="base-url"]', 'https://api.example.com/v2');
        expect($('[data-test="test-ok"]')).toBeNull();
    });
});

describe('A failed test: the fetch error card', () => {
    async function failWith(result: Record<string, unknown>) {
        vi.useFakeTimers();
        await mountForm();
        await fill();
        replies = [
            accepted(),
            operation('failed', summary({ ok: false, ...result })),
        ];
        await click(testButton());
        await tick();
    }

    it('shows fetch-failed with the source name, collapsed technical details and focus on its title', async () => {
        await failWith({
            status: 503,
            latency_ms: 90,
            code: 'fetch-failed',
            reason: 'http_503',
        });

        const card = $('[data-test="fetch-error-card"]') as HTMLElement;
        const title = $('[data-test="fetch-error-title"]') as HTMLElement;

        expect(title.textContent?.trim()).toBe(labels.testFailedTitle);
        expect(document.activeElement).toBe(title);
        expect(
            $('[data-test="fetch-error-message"]')?.textContent?.trim(),
        ).toBe("We couldn't reach Sales API. Check the endpoint or try again.");
        expect(card.textContent).not.toContain('▸');

        const toggle = card.querySelector(
            'button[aria-expanded]',
        ) as HTMLElement;
        const panel = document.getElementById(
            toggle.getAttribute('aria-controls') as string,
        ) as HTMLElement;
        expect(toggle.textContent).toContain(technicalDetailsLabels.title);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(panel.hasAttribute('hidden')).toBe(true);

        await click(toggle);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(panel.hasAttribute('hidden')).toBe(false);
        expect(panel.querySelector('[data-field="status"]')?.textContent).toBe(
            '503',
        );
        expect(
            panel.querySelector('[data-field="host"]')?.textContent?.trim(),
        ).toBe('api.example.com');
        expect(
            panel
                .querySelector('[data-field="request-id"]')
                ?.textContent?.trim(),
        ).toBe('req-test-1');
        expect(
            panel.querySelector('[data-field="reason"]')?.textContent?.trim(),
        ).toBe('http_503');
        expect(card.textContent).not.toMatch(
            /\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/,
        );
    });

    it.each([
        [
            'host-not-allowlisted',
            "This host isn't on your workspace allowlist. Add it in System settings, or ask a platform operator for a private-network address.",
        ],
        [
            'blocked-address',
            "Dashflow can't call loopback, link-local or cloud-metadata addresses. Use the API's public or allowlisted host.",
        ],
    ])('shows %s from the catalogue', async (code, message) => {
        await failWith({
            status: null,
            latency_ms: null,
            code,
            reason: code.replace(/-/g, '_'),
        });

        expect(
            $('[data-test="fetch-error-message"]')?.textContent?.trim(),
        ).toBe(message);
        expect($('[data-test="fetch-error-card"]')?.textContent).not.toContain(
            '▸',
        );
        expect(
            $('[data-test="fetch-error-card"]')?.querySelector(
                '[data-field="status"]',
            ),
        ).toBeNull();
    });

    it('retries the test from the card, replacing it with the new result', async () => {
        await failWith({
            status: 500,
            code: 'fetch-failed',
            reason: 'http_500',
        });
        replies = [
            accepted('op-2'),
            ok({
                data: {
                    id: 'op-2',
                    kind: 'connection_test',
                    status: 'succeeded',
                    result: summary(),
                    expires_at: 'x',
                },
            }),
        ];

        await click($('[data-test="fetch-retry"]'));
        await tick();

        expect(posts()).toHaveLength(2);
        expect($('[data-test="fetch-error-card"]')).toBeNull();
        expect($('[data-test="test-ok"]')?.textContent).toContain('Connected');
    });

    it('copies the request ID from the card and says so', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });
        await failWith({
            status: 502,
            code: 'fetch-failed',
            reason: 'http_502',
        });

        await click($('[data-test="copy-request-id"]'));

        expect(writeText).toHaveBeenCalledWith('req-test-1');
        expect($('[data-test="fetch-error-card"]')?.textContent).toContain(
            technicalDetailsLabels.copied,
        );
        // One Copy request ID button on the card, not two.
        expect(
            $$('button').filter(
                (b) => b.textContent?.trim() === technicalDetailsLabels.copy,
            ),
        ).toHaveLength(1);
    });

    it('treats an Operation that expired as a failed call, and a lost connection to the server as one too', async () => {
        vi.useFakeTimers();
        await mountForm();
        await fill();
        replies = [accepted(), operation('expired')];

        await click(testButton());
        await tick();

        expect($('[data-test="fetch-error-card"]')).not.toBeNull();
        expect(
            $('[data-test="fetch-error-card"]')
                ?.querySelector('[data-field="reason"]')
                ?.textContent?.trim(),
        ).toBe('expired');
    });
});

describe('Refusals when starting a test', () => {
    it("shows the server's field errors inline with focus, and no card", async () => {
        await mountForm();
        await fill();
        replies = [
            refused(422, {
                error: { code: 'platform.validation_failed', request_id: 'r' },
                errors: {
                    base_url: [
                        'This workspace requires https. Use an https:// base URL.',
                    ],
                },
                reasons: { base_url: 'https-required' },
            }),
        ];

        await click(testButton());

        expect($('[data-slot="field-error"]')?.textContent).toContain(
            'requires https',
        );
        expect($('[data-test="fetch-error-card"]')).toBeNull();
        expect(document.activeElement).toBe($('[data-test="base-url"]'));
        expect(testButton().getAttribute('aria-disabled')).toBeNull();
    });

    it('blocks the button with the wait on a 429 and frees it when the wait is over', async () => {
        vi.useFakeTimers();
        await mountForm();
        await fill();
        replies = [
            refused(429, {
                error: {
                    code: 'platform.too_many_requests',
                    request_id: 'r',
                    retry_after: 7,
                },
            }),
        ];

        await click(testButton());

        expect(testButton().getAttribute('aria-disabled')).toBe('true');
        expect(
            document.getElementById(
                testButton().getAttribute('aria-describedby') as string,
            )?.textContent,
        ).toBe(labels.testThrottled(7));
        expect($('[data-test="fetch-error-card"]')).toBeNull();

        await click(testButton());
        expect(posts()).toHaveLength(1);

        await tick(7000);
        expect(testButton().getAttribute('aria-disabled')).toBeNull();
    });

    it('says credentials are unavailable on a 503 from the platform key, without a card', async () => {
        await mountForm();
        await fill();
        replies = [
            refused(503, {
                error: {
                    code: 'connector.secrets_not_configured',
                    request_id: 'r',
                },
            }),
        ];

        await click(testButton());

        expect($('[data-test="save-failure"]')?.textContent).toContain(
            labels.credentialsUnavailable,
        );
        expect($('[data-test="fetch-error-card"]')).toBeNull();
    });

    it('shows the generic failed-call card when the request cannot be made at all', async () => {
        await mountForm();
        await fill();
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                throw new TypeError('offline');
            }),
        );

        await click(testButton());

        expect($('[data-test="fetch-error-card"]')).not.toBeNull();
        expect($('[data-test="fetch-error-message"]')?.textContent).toContain(
            "We couldn't reach Sales API.",
        );
    });
});

describe('pollOperation', () => {
    it('stops at once on a refusal that will not change, and after three failed reads in a row', async () => {
        vi.useFakeTimers();
        replies = [refused(404)];
        await expect(
            pollOperation('op-1', undefined, 10),
        ).rejects.toBeInstanceOf(DataSourceError);
        expect(calls).toHaveLength(1);

        calls.length = 0;
        replies = [refused(500), refused(500), refused(500)];
        const failing = expect(
            pollOperation('op-1', undefined, 10),
        ).rejects.toBeInstanceOf(DataSourceError);
        await vi.advanceTimersByTimeAsync(100);
        await failing;
        expect(calls).toHaveLength(3);
    });

    it('tolerates a moment of failure and reads on, and stops when aborted', async () => {
        vi.useFakeTimers();
        replies = [
            refused(502),
            operation('running'),
            operation('succeeded', summary()),
        ];
        const done = pollOperation('op-1', undefined, 10);
        await vi.advanceTimersByTimeAsync(100);

        expect((await done).status).toBe('succeeded');
        expect(calls).toHaveLength(3);

        calls.length = 0;
        const controller = new AbortController();
        replies = [operation('queued'), operation('queued')];
        const aborted = expect(
            pollOperation('op-1', controller.signal, 10_000),
        ).rejects.toBeTruthy();
        await vi.advanceTimersByTimeAsync(5);
        controller.abort();
        await aborted;
        expect(calls).toHaveLength(1);
    });
});

describe('Throttling, deadlines and stale results', () => {
    it('falls back to the Retry-After header on a 429 without retry_after, and shows the throttled state, not a card', async () => {
        vi.useFakeTimers();
        await mountForm();
        await fill();
        replies = [
            {
                ok: false,
                status: 429,
                json: { error: { code: 'platform.too_many_requests' } },
                headers: { 'Retry-After': '12' },
            },
        ];

        await click(testButton());

        expect(testButton().getAttribute('aria-disabled')).toBe('true');
        expect(
            document.getElementById(
                testButton().getAttribute('aria-describedby') as string,
            )?.textContent,
        ).toBe(labels.testThrottled(12));
        expect($('[data-test="fetch-error-card"]')).toBeNull();
    });

    it('still throttles on a bare 429 with no wait at all', async () => {
        await mountForm();
        await fill();
        replies = [refused(429, {})];

        await click(testButton());

        expect(testButton().getAttribute('aria-disabled')).toBe('true');
        expect($('[data-test="fetch-error-card"]')).toBeNull();
    });

    it('waits out a 429 on the poll and carries on to the result', async () => {
        vi.useFakeTimers();
        replies = [
            refused(429),
            refused(429),
            operation('succeeded', summary()),
        ];
        const done = pollOperation('op-1', undefined, 10);
        await vi.advanceTimersByTimeAsync(100);

        expect((await done).status).toBe('succeeded');
    });

    it('does not turn the form into "missing" when the poll answers 404, only when the start does', async () => {
        vi.useFakeTimers();
        replies = [
            ok({
                data: {
                    data_source_id: 'ds-1',
                    name: 'Sales API',
                    base_url: 'https://api.example.com/v1',
                    scheme: 'https',
                    host: 'api.example.com',
                    port: 443,
                    auth_type: 'none',
                    api_key_name: null,
                    api_key_placement: null,
                    headers: [],
                    secrets: {},
                    timeout_seconds: null,
                    max_response_bytes: null,
                    max_pages: null,
                    live_capable: false,
                    revision: 1,
                },
                meta: {
                    ceilings: {
                        timeout_seconds: null,
                        max_response_bytes: null,
                        max_pages: null,
                    },
                },
            }),
        ];
        await mountForm({ dataSourceId: 'ds-1' });
        replies = [accepted(), refused(404)];

        await click(testButton());

        expect($('[data-slot="not-found"]')).toBeNull();
        expect($('[data-test="fetch-error-card"]')).not.toBeNull();

        replies = [refused(404, {})];
        await click($('[data-test="fetch-retry"]'));

        expect($('[data-slot="not-found"]')).not.toBeNull();
    });

    it('gives up after the deadline with the fetch-failed card and a "no worker responded" reason', async () => {
        vi.useFakeTimers();
        await mountForm();
        await fill();
        replies = [
            accepted(),
            ...Array.from({ length: 200 }, () => operation('queued')),
        ];

        await click(testButton());
        await tick(POLL_DEADLINE_MS + 2000);

        const card = $('[data-test="fetch-error-card"]');
        expect(card).not.toBeNull();
        expect(
            card?.querySelector('[data-field="reason"]')?.textContent?.trim(),
        ).toBe('no_worker_responded');
        expect($('[data-test="testing"]')).toBeNull();
        expect(testButton().getAttribute('aria-disabled')).toBeNull();
    });

    it('throws PollTimeout from the poll once the deadline passes', async () => {
        vi.useFakeTimers();
        replies = Array.from({ length: 50 }, () => operation('running'));
        const outcome = expect(
            pollOperation('op-1', undefined, 10, 50),
        ).rejects.toBeInstanceOf(PollTimeout);
        await vi.advanceTimersByTimeAsync(200);
        await outcome;
    });

    it('discards the result of a test whose form was edited meanwhile', async () => {
        vi.useFakeTimers();
        await mountForm();
        await fill();
        replies = [
            accepted(),
            operation('queued'),
            operation('succeeded', summary()),
        ];

        await click(testButton());
        await type('[data-test="base-url"]', 'https://api.example.com/v2');
        await tick();

        expect($('[data-test="test-ok"]')).toBeNull();
        expect($('[data-test="fetch-error-card"]')).toBeNull();
        expect($('[data-test="testing"]')).toBeNull();
    });

    it('discards a failure of a test whose form was edited meanwhile too', async () => {
        vi.useFakeTimers();
        await mountForm();
        await fill();
        replies = [
            accepted(),
            operation('queued'),
            operation(
                'failed',
                summary({
                    ok: false,
                    code: 'fetch-failed',
                    reason: 'http_500',
                    status: 500,
                }),
            ),
        ];

        await click(testButton());
        await type('[data-test="name"]', 'Other name');
        await tick();

        expect($('[data-test="fetch-error-card"]')).toBeNull();
    });
});
