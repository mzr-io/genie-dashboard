// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { DataSource } from '../../resources/js/lib/dataSources';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { dataSourceLabels as labels } from '../../resources/js/locales/labels';

// Story 2.3: the Data sources list and the register and edit form (UX-DR-207, 261, 263, 23, 26, 22, 37, 39, 274, 282):
// the generic list states, the placeholders for health, last call and blocks, the "Not encrypted" badge, the Base URL
// blur check with a blocked Save, server field errors with focus, the load states of the edit form, and a stale edit.
const visit = vi.fn();
const beforeHandlers: Array<(event: unknown) => unknown> = [];

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
        router: {
            visit: (...args: unknown[]) => visit(...args),
            on: (name: string, handler: (event: unknown) => unknown) => {
                if (name === 'before') {
                    beforeHandlers.push(handler);
                }

                return () => {};
            },
        },
        usePage: () => ({
            url: '/admin/data-sources',
            props: {
                shell: { can: { 'data_sources.manage': true }, items: [] },
            },
        }),
    };
});

const { default: DataSources } =
    await import('../../resources/js/pages/admin/DataSources.vue');
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
        scheme: 'https',
        host: 'api.example.com',
        port: 443,
        auth_type: 'none',
        api_key_name: null,
        api_key_placement: null,
        headers: [{ name: 'X-Team', value: 'finance' }],
        timeout_seconds: 30,
        max_response_bytes: null,
        max_pages: null,
        live_capable: false,
        revision: 1,
        health: 'checking',
        last_successful_call_at: null,
        blocks_using: 0,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-01T09:30:00Z',
        ...overrides,
    };
}

function list(data: DataSource[], meta: Record<string, unknown> = {}) {
    return {
        data,
        meta: {
            total: data.length,
            matched: data.length,
            sort: 'name',
            direction: 'asc',
            ceilings,
            ...meta,
        },
    };
}

const one = (data: DataSource) => ({ data, meta: { ceilings } });

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
const writes = () => calls.filter((call) => call.method !== 'GET');

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

async function blur(selector: string): Promise<void> {
    ($(selector) as HTMLElement).dispatchEvent(new Event('blur'));
    await flushPromises();
}

const save = () => $('[data-test="save"]') as HTMLButtonElement;

beforeEach(() => {
    setActivePinia(createPinia());
    calls.length = 0;
    replies = [];
    visit.mockClear();
    beforeHandlers.length = 0;
    window.history.replaceState({}, '', '/admin/data-sources');
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                // Story 2.8: the soft lock is off here (`{enabled: false}`), and its calls are not part of these tests' traffic.
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
    vi.useRealTimers();
});

describe('Data sources list', () => {
    it('shows 5 skeleton rows while loading, then a table with the placeholders and one primary button', async () => {
        replies = [new Promise<Reply>(() => {})];
        await mountPage(DataSources);

        expect($('[data-state="loading"]')).not.toBeNull();
        expect($$('[data-slot="skeleton-row"]')).toHaveLength(5);
        wrapper?.unmount();
        document.body.innerHTML = '';

        replies = [
            ok(
                list([
                    source(),
                    source({
                        data_source_id: 'ds-2',
                        name: 'Legacy',
                        host: 'legacy.example.com',
                        scheme: 'http',
                        port: 8080,
                    }),
                ]),
            ),
        ];
        await mountPage(DataSources);

        expect($('table caption')?.textContent?.trim()).toBe(labels.caption);
        expect(
            $$('thead th').map((th) =>
                th.textContent?.trim().replace(/\s+/g, ' '),
            ),
        ).toEqual([
            labels.columns.name,
            labels.columns.host,
            labels.columns.auth_type,
            labels.columns.health,
            labels.columns.last_success,
            labels.columns.blocks,
        ]);
        expect($$('thead th')[0].getAttribute('aria-sort')).toBe('ascending');
        expect($$('thead th')[3].hasAttribute('aria-sort')).toBe(false);

        const rows = $$('tbody [data-slot="data-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('Sales API');
        expect(rows[0].textContent).toContain('api.example.com');
        expect(rows[0].textContent).toContain(labels.authTypes.none);
        expect(
            rows[0].querySelector('[data-test="health"]')?.textContent?.trim(),
        ).toBe(labels.checking);
        expect(
            rows[0].querySelector('[data-test="last-success"]')?.textContent,
        ).toBe(labels.noCall);
        expect(
            rows[0].querySelector('[data-test="blocks-using"]')?.textContent,
        ).toBe('0');
        expect(rows[0].querySelector('[data-test="not-encrypted"]')).toBeNull();
        expect(rows[1].textContent).toContain('legacy.example.com:8080');
        expect(
            rows[1].querySelector('[data-test="not-encrypted"]')?.textContent,
        ).toBe(labels.notEncrypted);
        expect(rows[0].querySelector('a')?.getAttribute('href')).toBe(
            '/admin/data-sources/ds-1/edit',
        );

        const register = $$('[data-test="register"]');
        expect(register).toHaveLength(1);
        expect(register[0].textContent).toBe(labels.register);
        expect(register[0].getAttribute('href')).toBe(
            '/admin/data-sources/create',
        );
        expect(calls[0].url).toContain('/api/v1/admin/data-sources?');
        expect(calls[0].url).toContain('sort=name');
    });

    it('shows list-empty with the register action when there are none', async () => {
        replies = [ok(list([]))];
        await mountPage(DataSources);

        expect($('[data-slot="list-empty"]')?.textContent).toContain(
            'No data sources yet.',
        );
        expect($$('[data-test="register"]')).toHaveLength(1);
        expect($('[data-slot="toolbar"]')).toBeNull();
    });

    it('shows a failure row with Retry, and Retry loads again', async () => {
        replies = [refused(500), ok(list([source()]))];
        await mountPage(DataSources);

        expect($('[data-slot="load-failure"]')?.textContent).toContain(
            "We couldn't load data sources. Try again.",
        );

        await click($('[data-test="retry"]'));

        expect($('[data-slot="load-failure"]')).toBeNull();
        expect($$('tbody [data-slot="data-row"]')).toHaveLength(1);
    });

    it('shows perm-denied on a 403 and never lists anything', async () => {
        replies = [refused(403)];
        await mountPage(DataSources);

        expect($('[data-slot="perm-denied"]')).not.toBeNull();
        expect($('table')).toBeNull();
    });

    it('searches (debounced), announces the count and shows list-no-match with Clear search', async () => {
        vi.useFakeTimers();
        replies = [ok(list([source()]))];
        await mountPage(DataSources);

        replies = [ok(list([], { total: 1, matched: 0 }))];
        await type('[data-test="search"]', 'zzz');
        await vi.advanceTimersByTimeAsync(400);
        await flushPromises();

        expect(calls[1].url).toContain('q=zzz');
        expect($('[data-slot="list-no-match"]')?.textContent).toContain(
            "No data sources match 'zzz'.",
        );

        replies = [ok(list([source()]))];
        await click($('[data-test="clear-search"]'));

        expect(calls[2].url).not.toContain('q=');
        expect($$('tbody [data-slot="data-row"]')).toHaveLength(1);
    });

    it('sorts by a column through the server and flips the direction', async () => {
        replies = [ok(list([source()]))];
        await mountPage(DataSources);

        replies = [ok(list([source()], { sort: 'host' }))];
        await click($('[data-column="host"]'));
        expect(calls[1].url).toContain('sort=host');
        expect(calls[1].url).toContain('direction=asc');

        replies = [ok(list([source()], { sort: 'host', direction: 'desc' }))];
        await click($('[data-column="host"]'));
        expect(calls[2].url).toContain('direction=desc');
        expect($$('thead th')[1].getAttribute('aria-sort')).toBe('descending');
    });

    it('highlights and focuses the row named by ?created= and clears the query', async () => {
        window.history.replaceState({}, '', '/admin/data-sources?created=ds-2');
        replies = [
            ok(
                list([
                    source(),
                    source({ data_source_id: 'ds-2', name: 'Zeta' }),
                ]),
            ),
        ];
        await mountPage(DataSources);
        await flushPromises();

        const row = $('[data-row-key="ds-2"]');
        expect(row?.getAttribute('data-highlighted')).toBe('true');
        expect(document.activeElement).toBe(row);
        expect(window.location.search).toBe('');
        expect($('[data-announcer="polite"]')?.textContent).toContain(
            'Changes saved.',
        );
    });
});

describe('Data sources list: untrusted query', () => {
    it('ignores a created key holding quotes or brackets instead of throwing', async () => {
        window.history.replaceState(
            {},
            '',
            '/admin/data-sources?created=' + encodeURIComponent('a"] [x'),
        );
        replies = [ok(list([source()]))];
        await mountPage(DataSources);

        expect($$('tbody [data-slot="data-row"]')).toHaveLength(1);
        expect($('[data-highlighted="true"]')).toBeNull();
    });
});

describe('Data source reason labels', () => {
    it('has a label for every reason code the server can return', async () => {
        const { readFileSync } = await import('node:fs');
        const read = (path: string) => readFileSync(path, 'utf8');
        const found = new Set<string>(['name-taken', 'https-required']);

        for (const m of read(
            'app/Modules/Connector/Application/ValidateDataSourceInput.php',
        ).matchAll(/\$fail\([^,]+,\s*'([a-z-]+)'/g)) {
            found.add(m[1]);
        }

        for (const m of read(
            'app/Modules/Connector/Contracts/UrlProblem.php',
        ).matchAll(/case \w+ = '([a-z-]+)'/g)) {
            found.add(m[1]);
        }

        found.delete('host-not-allowlisted');

        expect(found.size).toBeGreaterThan(15);
        expect([...found].filter((reason) => !labels.reasons[reason])).toEqual(
            [],
        );
    });
});

describe('Data source form: register', () => {
    it('starts ready with Save enabled, the Live switch off with its helper, and no headers', async () => {
        await mountPage(DataSourceForm);

        expect(save().disabled).toBe(false);
        expect(save().textContent?.trim()).toBe(labels.create);
        expect($('form')?.hasAttribute('novalidate')).toBe(true);
        expect(
            $('[data-test="live-capable"]')?.getAttribute('aria-checked'),
        ).toBe('false');
        expect(document.body.textContent).toContain(labels.liveHelper);
        expect($('[data-test="no-headers"]')).not.toBeNull();
        expect(calls).toHaveLength(0);
    });

    it('adds and removes default header rows and announces the removal', async () => {
        await mountPage(DataSourceForm);

        await click($('[data-test="add-header"]'));
        await click($('[data-test="add-header"]'));
        expect($$('[data-test="header-row"]')).toHaveLength(2);
        expect(document.activeElement?.id).toContain('headers.1.name');

        await click($$('[data-test="remove-header"]')[0]);
        expect($$('[data-test="header-row"]')).toHaveLength(1);
        expect($('[data-announcer="polite"]')?.textContent).toContain(
            labels.headerRemoved(1),
        );
    });

    it('checks the Base URL on blur: nothing shows on success', async () => {
        await mountPage(DataSourceForm);
        replies = [ok({ data: { allowed: true } })];

        await type('[data-test="base-url"]', 'https://api.example.com/v1');
        await blur('[data-test="base-url"]');

        expect(calls).toEqual([
            {
                url: '/api/v1/admin/data-sources/check-url',
                method: 'POST',
                body: { base_url: 'https://api.example.com/v1' },
            },
        ]);
        expect($('[data-slot="field-error"]')).toBeNull();
        expect(save().getAttribute('aria-disabled')).toBeNull();
    });

    it('shows host-not-allowlisted inline on a miss, with Save aria-disabled and its reason adjacent, and sends nothing', async () => {
        await mountPage(DataSourceForm);
        replies = [
            refused(422, {
                error: {
                    code: 'platform.validation_failed',
                    request_id: 'req-1',
                },
                errors: {
                    base_url: ["This host isn't on your workspace allowlist."],
                },
                reasons: { base_url: 'host-not-allowlisted' },
            }),
        ];

        await type('[data-test="name"]', 'Sales API');
        await type('[data-test="base-url"]', 'https://evil.example.net');
        await blur('[data-test="base-url"]');

        const error = $('[data-slot="field-error"]');
        expect(error?.textContent).toContain(
            "This host isn't on your workspace allowlist.",
        );
        expect(error?.textContent).not.toContain('Technical details');
        expect($('[data-test="base-url"]')?.getAttribute('aria-invalid')).toBe(
            'true',
        );
        expect(save().getAttribute('aria-disabled')).toBe('true');
        const reasonId = save().getAttribute('aria-describedby') as string;
        expect(document.getElementById(reasonId)?.textContent).toBe(
            labels.saveBlocked,
        );

        await click(save());

        expect(writes()).toHaveLength(1);
        expect(
            calls.filter((call) => call.url.endsWith('/data-sources')),
        ).toHaveLength(0);

        // Changing the address lifts the block until the next check.
        await type('[data-test="base-url"]', 'https://api.example.com');
        expect(save().getAttribute('aria-disabled')).toBeNull();
    });

    it('shows the Not encrypted badge for an http Base URL, and not for https', async () => {
        await mountPage(DataSourceForm);

        await type('[data-test="base-url"]', 'http://legacy.example.com');
        expect($('[data-test="not-encrypted"]')?.textContent).toBe(
            labels.notEncrypted,
        );

        await type('[data-test="base-url"]', 'https://legacy.example.com');
        expect($('[data-test="not-encrypted"]')).toBeNull();
    });

    it('requires a name and a Base URL on the client, with a summary of links for two', async () => {
        await mountPage(DataSourceForm);

        await click(save());

        expect($('[data-slot="form-error-summary"]')).not.toBeNull();
        expect($$('[data-slot="form-error-summary"] a')).toHaveLength(2);
        expect($('[data-test="name"]')?.getAttribute('aria-invalid')).toBe(
            'true',
        );
        expect(calls).toHaveLength(0);

        await type('[data-test="name"]', 'Sales API');
        await click(save());

        expect($('[data-slot="form-error-summary"]')).toBeNull();
        expect(document.activeElement).toBe($('[data-test="base-url"]'));
    });

    it('creates the Data Source with what was typed and returns to the list with the new row named', async () => {
        await mountPage(DataSourceForm);
        replies = [ok(one(source({ data_source_id: 'ds-9' })))];

        await type('[data-test="name"]', '  Sales API ');
        await type('[data-test="base-url"]', 'https://api.example.com/v1');
        await click($('[data-test="add-header"]'));
        await type('[data-test="header-name"]', 'X-Team');
        await type('[data-test="header-value"]', 'finance');
        await click($('[data-test="add-header"]'));
        await type('[data-test="timeout"]', ' 30 ');
        await click($('[data-test="live-capable"]'));
        await click(save());

        expect(writes()).toEqual([
            {
                url: '/api/v1/admin/data-sources',
                method: 'POST',
                body: {
                    name: 'Sales API',
                    base_url: 'https://api.example.com/v1',
                    headers: [{ name: 'X-Team', value: 'finance' }],
                    timeout_seconds: '30',
                    max_response_bytes: null,
                    max_pages: null,
                    live_capable: true,
                    auth_type: 'none',
                },
            },
        ]);
        expect(visit).toHaveBeenCalledWith('/admin/data-sources?created=ds-9');
    });

    it("shows the server's field errors inline with aria-invalid and focuses the first invalid field", async () => {
        await mountPage(DataSourceForm);
        replies = [
            refused(422, {
                errors: {
                    'headers.0.value': [
                        'A header value uses visible ASCII characters only, on one line.',
                    ],
                },
                reasons: { 'headers.0.value': 'header-value-invalid' },
            }),
        ];

        await type('[data-test="name"]', 'Sales API');
        await type('[data-test="base-url"]', 'https://api.example.com');
        await click($('[data-test="add-header"]'));
        await type('[data-test="header-name"]', 'X-Team');
        await type('[data-test="header-value"]', 'caf\u00e9');
        await click(save());

        const input = $('[data-test="header-value"]');
        expect(input?.getAttribute('aria-invalid')).toBe('true');
        expect(document.activeElement).toBe(input);
        expect($('[data-slot="field-error"]')?.textContent).toContain(
            labels.reasons['header-value-invalid'],
        );
        expect(visit).not.toHaveBeenCalled();
        expect((input as HTMLInputElement).value).toBe('caf\u00e9');
    });

    it('lists two or more server errors in a summary and shows a ceiling message from the server', async () => {
        await mountPage(DataSourceForm);
        replies = [
            refused(422, {
                errors: {
                    name: ['A data source with this name already exists.'],
                    timeout_seconds: [
                        'The timeout cannot be more than the platform limit of 60.',
                    ],
                },
                reasons: {
                    name: 'name-taken',
                    timeout_seconds: 'above-ceiling',
                },
            }),
        ];

        await type('[data-test="name"]', 'Sales API');
        await type('[data-test="base-url"]', 'https://api.example.com');
        await click(save());

        expect($$('[data-slot="form-error-summary"] li')).toHaveLength(2);
        expect(document.body.textContent).toContain(
            labels.reasons['name-taken'],
        );
        expect(document.body.textContent).toContain('platform limit of 60');
    });

    it('shows the throttled and save failures without losing what was typed', async () => {
        await mountPage(DataSourceForm);
        replies = [refused(429), refused(500)];

        await type('[data-test="name"]', 'Sales API');
        await type('[data-test="base-url"]', 'https://api.example.com');
        await click(save());
        expect($('[data-test="save-failure"]')?.textContent).toContain(
            'Too many attempts',
        );

        await click(save());
        expect($('[data-test="save-failure"]')?.textContent).toContain(
            "We couldn't save your changes.",
        );
        expect(($('[data-test="name"]') as HTMLInputElement).value).toBe(
            'Sales API',
        );
    });

    it('asks before leaving with unsaved edits and lets a clean form go', async () => {
        await mountPage(DataSourceForm);
        const event = {
            detail: {
                visit: { url: new URL('http://localhost/admin/data-sources') },
            },
        };

        expect(beforeHandlers).toHaveLength(1);
        expect(beforeHandlers[0](event)).toBeUndefined();

        await type('[data-test="name"]', 'Sales API');

        expect(beforeHandlers[0](event)).toBe(false);
        await flushPromises();
        expect(document.body.textContent).toContain(
            'You have unsaved changes.',
        );
    });
});

describe('Data source form: edit', () => {
    it('shows skeletons with Save disabled until the saved values arrive, then fills the form', async () => {
        let release: (reply: Reply) => void = () => {};
        replies = [new Promise<Reply>((resolve) => (release = resolve))];
        await mountPage(DataSourceForm, { dataSourceId: 'ds-1' });

        expect($('[data-slot="form-loading"]')).not.toBeNull();
        expect($$('[data-slot="skeleton-group"]').length).toBeGreaterThan(0);
        expect(save().disabled).toBe(true);
        expect($('[data-test="name"]')).toBeNull();

        release(ok(one(source({ live_capable: true }))));
        await flushPromises();

        expect(calls[0].url).toBe('/api/v1/admin/data-sources/ds-1');
        expect(save().disabled).toBe(false);
        expect(save().textContent?.trim()).toBe(labels.save);
        expect(($('[data-test="name"]') as HTMLInputElement).value).toBe(
            'Sales API',
        );
        expect(($('[data-test="base-url"]') as HTMLInputElement).value).toBe(
            'https://api.example.com/v1',
        );
        expect(($('[data-test="header-name"]') as HTMLInputElement).value).toBe(
            'X-Team',
        );
        expect(($('[data-test="timeout"]') as HTMLInputElement).value).toBe(
            '30',
        );
        expect(
            $('[data-test="live-capable"]')?.getAttribute('aria-checked'),
        ).toBe('true');
        expect(document.body.textContent).toContain(
            'The platform limit is 60.',
        );
    });

    it('shows the load failure with Retry and never stale values', async () => {
        replies = [refused(500), ok(one(source()))];
        await mountPage(DataSourceForm, { dataSourceId: 'ds-1' });

        expect($('[data-slot="load-failure"]')?.textContent).toContain(
            labels.loadFailed,
        );
        expect($('[data-test="name"]')).toBeNull();
        expect(save()).toBeNull();

        await click($('[data-test="retry"]'));

        expect($('[data-slot="load-failure"]')).toBeNull();
        expect(($('[data-test="name"]') as HTMLInputElement).value).toBe(
            'Sales API',
        );
    });

    it('says so when the Data Source no longer exists, and on a 403', async () => {
        replies = [refused(404)];
        await mountPage(DataSourceForm, { dataSourceId: 'ds-1' });
        expect($('[data-slot="not-found"]')?.textContent).toBe(labels.notFound);
        wrapper?.unmount();
        document.body.innerHTML = '';

        replies = [refused(403)];
        await mountPage(DataSourceForm, { dataSourceId: 'ds-1' });
        expect($('[data-slot="perm-denied"]')).not.toBeNull();
    });

    it('saves with the current revision and returns to the list with the row named', async () => {
        replies = [ok(one(source({ revision: 3 })))];
        await mountPage(DataSourceForm, { dataSourceId: 'ds-1' });
        replies = [ok(one(source({ revision: 4 })))];

        await type('[data-test="name"]', 'Sales API v2');
        await click(save());

        expect(writes()[0]).toMatchObject({
            url: '/api/v1/admin/data-sources/ds-1',
            method: 'PUT',
            body: {
                name: 'Sales API v2',
                revision: 3,
                headers: [{ name: 'X-Team', value: 'finance' }],
            },
        });
        expect(visit).toHaveBeenCalledWith('/admin/data-sources?updated=ds-1');
    });

    it('keeps what was typed on a stale save (409), says so, and saves against the latest revision next', async () => {
        replies = [ok(one(source({ revision: 1 })))];
        await mountPage(DataSourceForm, { dataSourceId: 'ds-1' });
        replies = [
            refused(409, {
                error: { code: 'connector.revision_conflict' },
                current: one(source({ name: 'Renamed by Bob', revision: 2 })),
            }),
        ];

        await type('[data-test="name"]', 'My rename');
        await click(save());

        expect($('[data-test="save-failure"]')?.textContent).toContain(
            labels.conflict,
        );
        expect(($('[data-test="name"]') as HTMLInputElement).value).toBe(
            'My rename',
        );
        expect(visit).not.toHaveBeenCalled();

        replies = [ok(one(source({ revision: 3 })))];
        await click(save());
        expect(writes()[1].body).toMatchObject({
            name: 'My rename',
            revision: 2,
        });

        replies = [];
    });

    it('can reload the latest values after a stale save', async () => {
        replies = [ok(one(source({ revision: 1 })))];
        await mountPage(DataSourceForm, { dataSourceId: 'ds-1' });
        replies = [
            refused(409, {
                error: { code: 'connector.revision_conflict' },
                current: one(source({ name: 'Renamed by Bob', revision: 2 })),
            }),
        ];

        await type('[data-test="name"]', 'My rename');
        await click(save());
        await click($('[data-test="reload-latest"]'));

        expect(($('[data-test="name"]') as HTMLInputElement).value).toBe(
            'Renamed by Bob',
        );
        expect($('[data-test="save-failure"]')).toBeNull();
    });

    it('shows the Not encrypted badge on every later view of an http source', async () => {
        replies = [
            ok(
                one(
                    source({
                        base_url: 'http://legacy.example.com',
                        scheme: 'http',
                        host: 'legacy.example.com',
                        port: 80,
                    }),
                ),
            ),
        ];
        await mountPage(DataSourceForm, { dataSourceId: 'ds-1' });

        expect($('[data-test="not-encrypted"]')?.textContent).toBe(
            labels.notEncrypted,
        );
    });
});

describe('Data source labels', () => {
    it('reuses the host allowlist wording for the Not encrypted badge', async () => {
        const { hostAllowlistLabels } =
            await import('../../resources/js/locales/labels');

        expect(labels.notEncrypted).toBe(hostAllowlistLabels.notEncrypted);
    });
});
