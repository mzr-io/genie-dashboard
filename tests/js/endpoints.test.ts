// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { Endpoint } from '../../resources/js/lib/endpoints';
import { pathPlaceholders } from '../../resources/js/lib/endpoints';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { endpointLabels as labels } from '../../resources/js/locales/labels';

// Story 2.9: the Endpoints tab (UX-DR-134, 27, 28, 23, 26, 22, 37, 282): the Data source tabs, the list states, the
// Endpoint field with its `method-prefix`, the `parameters-table`, header rows, the body template, field errors with
// focus, the required `post-readonly` checkbox with its risk confirmation, a stale save, and the saved row.
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
            visit: () => {},
            on: (name: string, handler: (event: unknown) => unknown) => {
                if (name === 'before') {
                    beforeHandlers.push(handler);
                }

                return () => {};
            },
        },
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
const { default: DataSourceTabs } =
    await import('../../resources/js/components/DataSourceTabs.vue');

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

const list = (data: Endpoint[], meta: Record<string, unknown> = {}) => ({
    data,
    meta: { total: data.length, matched: data.length, ...meta },
});
const source = { data: { data_source_id: DS, name: 'Sales API' }, meta: {} };
const one = (data: Endpoint) => ({ data });

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

async function mountPage() {
    wrapper = mount(DataSourceEndpoints as never, {
        attachTo: document.body,
        props: { dataSourceId: DS },
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();

    return wrapper;
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));

// The announcer sets its text on a later tick.
const spoken = async (): Promise<string | null | undefined> => {
    await new Promise((resolve) => setTimeout(resolve, 5));

    return document.querySelector('[data-announcer="polite"]')?.textContent;
};

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await flushPromises();
}

async function type(selector: string, value: string): Promise<void> {
    const input = $(selector) as HTMLInputElement | HTMLTextAreaElement;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function blur(selector: string): Promise<void> {
    ($(selector) as HTMLElement).dispatchEvent(new Event('blur'));
    await flushPromises();
}

async function pick(selector: string, value: string): Promise<void> {
    const select = $(selector) as HTMLSelectElement;
    select.value = value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    select.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

const segment = (value: string) =>
    $$('[data-slot="method-prefix"] [role="radio"]').find(
        (item) => item.getAttribute('value') === value,
    ) as HTMLElement;

const save = () => $('[data-test="save"]') as HTMLButtonElement;

async function openAdd(): Promise<void> {
    replies = [ok(list([])), ok(source)];
    await mountPage();
    await click($('[data-test="add-endpoint"]'));
}

beforeEach(() => {
    setActivePinia(createPinia());
    calls.length = 0;
    replies = [];
    beforeHandlers.length = 0;
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

describe('Data source tabs', () => {
    it('lists Settings and Endpoints and marks the current one', () => {
        const tabs = mount(DataSourceTabs as never, {
            props: { dataSourceId: DS, current: 'endpoints' },
        });
        const links = tabs.findAll('a');

        expect(tabs.find('nav').attributes('aria-label')).toBe(
            labels.tabsLabel,
        );
        expect(links.map((a) => a.text())).toEqual([
            labels.tabSettings,
            labels.tabEndpoints,
        ]);
        expect(links.map((a) => a.attributes('href'))).toEqual([
            '/admin/data-sources/ds-1/edit',
            '/admin/data-sources/ds-1/endpoints',
        ]);
        expect(links.map((a) => a.attributes('aria-current'))).toEqual([
            undefined,
            'page',
        ]);
        tabs.unmount();
    });
});

describe('Endpoint list', () => {
    it('shows 5 skeleton rows while loading', async () => {
        replies = [new Promise<Reply>(() => {}), new Promise<Reply>(() => {})];
        await mountPage();

        expect($('[data-state="loading"]')).not.toBeNull();
        expect($$('[data-slot="skeleton-row"]')).toHaveLength(5);
    });

    it('shows a table of method, path, revision and updated with one primary button', async () => {
        replies = [
            ok(
                list([
                    endpoint(),
                    endpoint({
                        endpoint_id: 'ep-2',
                        method: 'POST',
                        path: '/search',
                        revision: 3,
                        read_only_query: true,
                    }),
                ]),
            ),
            ok(source),
        ];
        await mountPage();

        expect(
            $$('thead th').map((th) =>
                th.textContent?.trim().replace(/\s+/g, ' '),
            ),
        ).toEqual([
            labels.columns.method,
            labels.columns.path,
            labels.columns.revision,
            labels.dataColumn,
            labels.columns.updated,
            labels.testActions,
        ]);

        const rows = $$('tbody [data-slot="data-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].querySelector('[data-test="method"]')?.textContent).toBe(
            'GET',
        );
        expect(rows[0].textContent).toContain('/api/v2/finance/revenue');
        expect(
            rows[1].querySelector('[data-test="revision"]')?.textContent,
        ).toBe('r3');
        expect($('[data-slot="page-subtitle"]')?.textContent).toContain(
            'Sales API',
        );
        expect($$('[data-test="add-endpoint"]')).toHaveLength(1);
        expect($('[data-test="add-endpoint"]')?.textContent?.trim()).toBe(
            labels.add,
        );
        expect(calls[0].url).toBe('/api/v1/admin/data-sources/ds-1/endpoints');
        expect(
            $('[data-test="tab-endpoints"]')?.getAttribute('aria-current'),
        ).toBe('page');
    });

    it('shows list-empty with the add action when there are none', async () => {
        replies = [ok(list([])), ok(source)];
        await mountPage();

        expect($('[data-slot="list-empty"]')?.textContent).toContain(
            'No endpoints yet.',
        );
        expect($$('[data-test="add-endpoint"]')).toHaveLength(1);
        expect($('[data-slot="toolbar"]')).toBeNull();
    });

    it('shows a failure row with Retry, and Retry loads again', async () => {
        replies = [
            refused(500),
            ok(source),
            ok(list([endpoint()])),
            ok(source),
        ];
        await mountPage();

        expect($('[data-slot="load-failure"]')?.textContent).toContain(
            "We couldn't load endpoints. Try again.",
        );

        await click($('[data-test="retry"]'));

        expect($('[data-slot="load-failure"]')).toBeNull();
        expect($$('tbody [data-slot="data-row"]')).toHaveLength(1);
    });

    it('shows perm-denied on a 403 and a gone message on a 404', async () => {
        replies = [refused(403), ok(source)];
        await mountPage();

        expect($('[data-slot="perm-denied"]')).not.toBeNull();
        expect($('table')).toBeNull();
        wrapper?.unmount();
        document.body.innerHTML = '';

        replies = [refused(404), refused(404)];
        await mountPage();

        expect($('[data-test="missing"]')?.textContent).toContain(
            labels.missing,
        );
    });

    it('searches (debounced), shows the count caption and list-no-match with Clear search', async () => {
        vi.useFakeTimers();
        replies = [ok(list([endpoint()])), ok(source)];
        await mountPage();

        replies = [ok(list([], { total: 1, matched: 0 }))];
        await type('[data-test="search"]', 'zzz');
        await vi.advanceTimersByTimeAsync(400);
        await flushPromises();

        expect(calls[2].url).toContain('?q=zzz');
        expect($('[data-slot="list-no-match"]')?.textContent).toContain(
            "No endpoints match 'zzz'.",
        );
        expect($('[data-test="count"]')?.textContent).toBe('0 of 1 endpoint');

        replies = [ok(list([endpoint()]))];
        await click($('[data-test="clear-search"]'));

        expect($('[data-slot="list-no-match"]')).toBeNull();
        expect($$('tbody [data-slot="data-row"]')).toHaveLength(1);
    });
});

describe('Endpoint form', () => {
    it('opens with the method-prefix joined to a mono path input and an empty parameters table', async () => {
        await openAdd();

        const prefix = $('[data-slot="method-prefix"]');
        expect(prefix).not.toBeNull();
        expect(prefix?.querySelectorAll('[role="radio"]')).toHaveLength(2);
        expect(segment('GET').getAttribute('aria-checked')).toBe('true');
        expect(
            prefix?.querySelector('[data-test="path"]')?.className,
        ).toContain('font-mono');
        expect($('[data-slot="parameters-table"]')).not.toBeNull();
        expect($('[data-test="params-none"]')?.textContent).toBe(
            labels.parametersNone,
        );
        expect($('[data-slot="post-readonly"]')).toBeNull();
        expect($('[data-test="body-template"]')).toBeNull();
        expect($('[data-test="add-endpoint"]')).toBeNull();
        expect($$('[data-test="save"]')).toHaveLength(1);
        expect(save().getAttribute('aria-disabled')).toBeNull();
    });

    it('adds a parameter row per {placeholder}, with Name in mono, a Binding select and a Value input', async () => {
        await openAdd();
        await type('[data-test="path"]', '/customers/{id}/revenue');
        await blur('[data-test="path"]');

        const rows = $$('[data-test="params-row"]');
        expect(rows).toHaveLength(1);
        expect(
            (
                rows[0].querySelector(
                    '[data-test="params-name"]',
                ) as HTMLInputElement
            ).value,
        ).toBe('id');
        expect(
            rows[0].querySelector('[data-test="params-name"]')?.className,
        ).toContain('font-mono');
        expect(
            Array.from(
                rows[0].querySelectorAll('[data-test="params-binding"] option'),
            ).map((option) => option.textContent?.trim()),
        ).toEqual([
            labels.bindings.fixed,
            labels.bindings.date_range_from,
            labels.bindings.date_range_to,
            labels.bindings.period_start,
            labels.bindings.period_end,
            // Story 2.13: the "User context" group.
            labels.bindings.user_id,
            labels.bindings.user_email,
            labels.bindings.user_group,
        ]);
        expect(
            rows[0]
                .querySelector('[data-test="params-binding"] optgroup')
                ?.getAttribute('label'),
        ).toBe(labels.userContextGroup);
        expect(
            rows[0].querySelector('[data-test="params-value"]'),
        ).not.toBeNull();
    });

    it('shows the resolved placeholder in muted mono for a bound row, in place of a value input', async () => {
        await openAdd();
        await click($('[data-test="params-add"]'));
        await pick('[data-test="params-binding"]', 'date_range_from');

        const resolved = $('[data-test="params-row"] [data-test="resolved"]');
        expect(resolved?.textContent).toContain('date_range.from');
        expect(resolved?.className).toContain('font-mono');
        expect(resolved?.className).toContain('text-text-muted');
        expect($('[data-test="params-value"]')).toBeNull();
    });

    it('creates a GET Endpoint: sends only the bindings, then returns to the list with the saved row highlighted and focused', async () => {
        await openAdd();
        await type('[data-test="path"]', '/customers/{id}');
        await blur('[data-test="path"]');
        await type('[data-test="params-value"]', 'c-42');
        await click($('[data-test="params-add"]'));
        const names = $$('[data-test="params-name"]');
        await type(`#${names[1].id}`, 'from');
        await pick(
            `#${$$('[data-test="params-binding"]')[1].id}`,
            'date_range_from',
        );
        await click($('[data-test="headers-add"]'));
        await type('[data-test="headers-name"]', 'X-Team');
        await type('[data-test="headers-value"]', 'finance');

        const saved = endpoint({
            endpoint_id: 'ep-9',
            path: '/customers/{id}',
        });
        replies = [ok(one(saved)), ok(list([saved])), ok(source)];
        await click(save());

        expect(writes()).toHaveLength(1);
        expect(writes()[0]).toMatchObject({
            url: '/api/v1/admin/data-sources/ds-1/endpoints',
            method: 'POST',
            body: {
                method: 'GET',
                path: '/customers/{id}',
                params: [
                    { name: 'id', binding: 'fixed', value: 'c-42' },
                    { name: 'from', binding: 'date_range_from', value: null },
                ],
                headers: [
                    { name: 'X-Team', binding: 'fixed', value: 'finance' },
                ],
                body_template: null,
                read_only_query: false,
                confirm_read_only: false,
            },
        });
        expect($('[data-test="endpoint-form"]')).toBeNull();

        const row = $('[data-row-key="ep-9"]');
        expect(row?.getAttribute('data-highlighted')).toBe('true');
        expect(document.activeElement).toBe(row);
        expect(await spoken()).toBe('Changes saved.');
    });

    it('edits an Endpoint with its revision and shows the saved values', async () => {
        const saved = endpoint({
            params: [
                { name: 'q', binding: 'fixed', value: 'x', kind: 'query' },
            ],
            headers: [{ name: 'X-A', binding: 'period_start', value: null }],
            revision: 4,
        });
        replies = [ok(list([saved])), ok(source)];
        await mountPage();
        await click($('[data-test="edit-endpoint"]'));

        expect($('[data-test="form-title"]')?.textContent).toBe(
            labels.editTitle,
        );
        expect(($('[data-test="path"]') as HTMLInputElement).value).toBe(
            saved.path,
        );
        expect(($('[data-test="params-name"]') as HTMLInputElement).value).toBe(
            'q',
        );
        expect(
            $('[data-test="headers-row"] [data-test="resolved"]')?.textContent,
        ).toContain('period.start');

        replies = [
            ok(one({ ...saved, revision: 5 })),
            ok(list([{ ...saved, revision: 5 }])),
            ok(source),
        ];
        await click(save());

        expect(writes()[0]).toMatchObject({
            url: '/api/v1/admin/data-sources/ds-1/endpoints/ep-1',
            method: 'PUT',
            body: { revision: 4, method: 'GET' },
        });
        expect(
            $('[data-row-key="ep-1"] [data-test="revision"]')?.textContent,
        ).toBe('r5');
    });

    it('removes a row, announces it and moves focus on', async () => {
        await openAdd();
        await click($('[data-test="params-add"]'));
        await click($('[data-test="params-remove"]'));

        expect($$('[data-test="params-row"]')).toHaveLength(0);
        expect(await spoken()).toBe(labels.paramRemoved(1));
        expect(document.activeElement?.id).toContain('params-add');
    });

    it('shows field errors with aria-invalid, the server message that names a parameter, and focus on the first invalid field', async () => {
        await openAdd();
        await type('[data-test="path"]', '/customers/{id}');
        await blur('[data-test="path"]');
        await type('[data-test="params-value"]', 'a/b');

        replies = [
            refused(422, {
                errors: {
                    'params.0.value': [
                        "The value of id cannot be empty, '.', '..' or contain '/'.",
                    ],
                },
                reasons: { 'params.0.value': 'param-value-invalid' },
            }),
        ];
        await click(save());

        const value = $('[data-test="params-value"]') as HTMLInputElement;
        expect(value.getAttribute('aria-invalid')).toBe('true');
        expect(
            document.getElementById(
                value.getAttribute('aria-describedby') ?? '',
            )?.textContent,
        ).toContain('id');
        expect(document.activeElement).toBe(value);
        expect($('[data-slot="form-error-summary"]')).toBeNull();
    });

    it('shows a summary of links for two or more errors and focuses it', async () => {
        await openAdd();
        await type('[data-test="path"]', 'x');

        replies = [
            refused(422, {
                errors: {
                    path: ['The path must start with /.'],
                    'headers.0.name': ['Reserved.'],
                },
                reasons: {
                    path: 'path-leading-slash',
                    'headers.0.name': 'header-name-reserved',
                },
            }),
        ];
        await click(save());

        const summary = $('[data-slot="form-error-summary"]');
        expect(summary).not.toBeNull();
        expect(document.activeElement).toBe(summary);
        expect(summary?.querySelectorAll('a')).toHaveLength(2);
        expect(summary?.textContent).toContain(
            labels.reasons['path-leading-slash'],
        );
        expect(
            ($('[data-test="path"]') as HTMLInputElement).getAttribute(
                'aria-invalid',
            ),
        ).toBe('true');
    });

    it('maps a method refusal onto the method field and focuses the checked segment', async () => {
        await openAdd();
        replies = [
            refused(422, {
                errors: { method: ['Only GET and POST.'] },
                reasons: { method: 'method-not-allowed' },
            }),
        ];
        await click(save());

        expect($('[data-slot="form-field"]')?.textContent).toContain(
            labels.reasons['method-not-allowed'],
        );
        expect(document.activeElement).toBe(segment('GET'));
    });

    it('keeps what was typed on a stale save, and the next save uses the latest revision', async () => {
        const saved = endpoint({ revision: 2 });
        replies = [ok(list([saved])), ok(source)];
        await mountPage();
        await click($('[data-test="edit-endpoint"]'));
        await type('[data-test="path"]', '/mine');

        replies = [
            refused(409, {
                error: { code: 'connector.revision_conflict' },
                current: { data: endpoint({ revision: 3, path: '/theirs' }) },
            }),
        ];
        await click(save());

        expect($('[data-test="failure"]')?.textContent).toContain(
            labels.conflict,
        );
        expect(($('[data-test="path"]') as HTMLInputElement).value).toBe(
            '/mine',
        );

        replies = [
            ok(one(endpoint({ revision: 4, path: '/mine' }))),
            ok(list([endpoint({ revision: 4, path: '/mine' })])),
            ok(source),
        ];
        await click(save());

        expect(
            writes().map(
                (call) => (call.body as { revision: number }).revision,
            ),
        ).toEqual([2, 3]);
    });

    it('reloads the latest values after a stale save', async () => {
        replies = [ok(list([endpoint({ revision: 2 })])), ok(source)];
        await mountPage();
        await click($('[data-test="edit-endpoint"]'));
        await type('[data-test="path"]', '/mine');
        replies = [
            refused(409, {
                current: { data: endpoint({ revision: 3, path: '/theirs' }) },
            }),
        ];
        await click(save());
        await click($('[data-test="reload-latest"]'));

        expect(($('[data-test="path"]') as HTMLInputElement).value).toBe(
            '/theirs',
        );
        expect($('[data-test="failure"]')).toBeNull();
    });

    it('says when saves are throttled, and cancel returns to the list without writing', async () => {
        await openAdd();
        replies = [refused(429)];
        await click(save());
        expect($('[data-test="failure"]')?.textContent).toContain(
            labels.throttled,
        );

        await click($('[data-test="cancel"]'));

        expect($('[data-test="endpoint-form"]')).toBeNull();
        expect(writes()).toHaveLength(1);
    });
});

describe('Endpoint form guards', () => {
    it('adds parameter rows when the path is left, not while it is typed, and drops untouched rows whose placeholder went', async () => {
        await openAdd();
        await type('[data-test="path"]', '/c/{i}');
        expect($$('[data-test="params-row"]')).toHaveLength(0);

        await blur('[data-test="path"]');
        expect(
            $$('[data-test="params-name"]').map(
                (n) => (n as HTMLInputElement).value,
            ),
        ).toEqual(['i']);

        await type('[data-test="path"]', '/c/{id}');
        await blur('[data-test="path"]');
        expect(
            $$('[data-test="params-name"]').map(
                (n) => (n as HTMLInputElement).value,
            ),
        ).toEqual(['id']);

        // A row the person edited stays when its placeholder goes.
        await type('[data-test="params-value"]', 'c-42');
        await type('[data-test="path"]', '/c');
        await blur('[data-test="path"]');
        expect($$('[data-test="params-row"]')).toHaveLength(1);
    });

    it('asks before discarding typed work on Cancel, and cancels at once when nothing was typed', async () => {
        await openAdd();
        await click($('[data-test="cancel"]'));
        expect($('[data-test="endpoint-form"]')).toBeNull();

        await click($('[data-test="add-endpoint"]'));
        await type('[data-test="path"]', '/typed');
        await click($('[data-test="cancel"]'));

        expect($('[role="alertdialog"]')?.textContent).toContain(
            labels.discardTitle,
        );
        expect($('[data-test="endpoint-form"]')).not.toBeNull();

        await click(
            $$('[role="alertdialog"] button').find(
                (b) => b.textContent?.trim() === 'Cancel',
            ) ?? null,
        );
        expect(($('[data-test="path"]') as HTMLInputElement).value).toBe(
            '/typed',
        );

        await click($('[data-test="cancel"]'));
        await click(
            $$('[role="alertdialog"] button').find((b) =>
                b.textContent?.includes(labels.discardVerb),
            ) ?? null,
        );
        expect($('[data-test="endpoint-form"]')).toBeNull();
    });

    it('shows a 403 and a 404 on save as their own states that retrying cannot clear', async () => {
        await openAdd();
        await type('[data-test="path"]', '/x');
        replies = [refused(403)];
        await click(save());

        expect($('[data-test="failure"]')?.textContent).toContain(
            "You don't have permission",
        );
        expect(save().hasAttribute('disabled')).toBe(true);
        expect(writes()).toHaveLength(1);
        wrapper?.unmount();
        document.body.innerHTML = '';

        await openAdd();
        await type('[data-test="path"]', '/x');
        replies = [refused(404)];
        await click(save());

        expect($('[data-test="failure"]')?.textContent).toContain(
            labels.endpointGone,
        );
        expect(save().hasAttribute('disabled')).toBe(true);
    });
});

describe('POST', () => {
    it('shows the required post-readonly checkbox and a body template, and blocks Save with its reason until it is ticked', async () => {
        await openAdd();
        await type('[data-test="path"]', '/search');
        await click(segment('POST'));

        const box = $('[data-test="post-readonly"]') as HTMLElement;
        expect(box).not.toBeNull();
        expect(box.getAttribute('aria-checked')).toBe('false');
        expect($('[data-slot="post-readonly"]')?.textContent).toContain(
            'This POST is a read-only query',
        );
        expect($('[data-slot="post-readonly"]')?.textContent).toContain(
            'Dashflow never sends requests that change data.',
        );
        expect($('[data-test="body-template"]')).not.toBeNull();

        expect(save().getAttribute('aria-disabled')).toBe('true');
        const reason = $('[data-slot="blocked-reason"]');
        expect(reason?.textContent).toBe(labels.postReadonlyBlocked);
        expect(save().getAttribute('aria-describedby')).toBe(reason?.id);

        await click(save());
        expect(writes()).toHaveLength(0);
    });

    it('asks for a risk confirmation when ticked: Cancel leaves it unticked, Confirm ticks it and unblocks Save', async () => {
        await openAdd();
        await type('[data-test="path"]', '/search');
        await click(segment('POST'));

        await click($('[data-test="post-readonly"]'));
        const dialog = $('[role="alertdialog"]');
        expect(dialog?.textContent).toContain(labels.riskTitle);
        expect(dialog?.textContent).toContain(labels.riskDescription);

        await click(
            $$('[role="alertdialog"] button').find(
                (b) => b.textContent?.trim() === 'Cancel',
            ) ?? null,
        );
        expect(
            $('[data-test="post-readonly"]')?.getAttribute('aria-checked'),
        ).toBe('false');
        expect(save().getAttribute('aria-disabled')).toBe('true');

        await click($('[data-test="post-readonly"]'));
        await click(
            $$('[role="alertdialog"] button').find((b) =>
                b.textContent?.includes(labels.riskConfirm),
            ) ?? null,
        );

        expect(
            $('[data-test="post-readonly"]')?.getAttribute('aria-checked'),
        ).toBe('true');
        expect(save().getAttribute('aria-disabled')).toBeNull();
        expect($('[data-slot="blocked-reason"]')).toBeNull();
    });

    it('sends the flag, the confirmation and the body template', async () => {
        await openAdd();
        await type('[data-test="path"]', '/search');
        await click(segment('POST'));
        await type(
            '[data-test="body-template"]',
            '{"from": {"$param": "from"}}',
        );
        await click($('[data-test="post-readonly"]'));
        await click(
            $$('[role="alertdialog"] button').find((b) =>
                b.textContent?.includes(labels.riskConfirm),
            ) ?? null,
        );

        const saved = endpoint({
            endpoint_id: 'ep-5',
            method: 'POST',
            path: '/search',
            read_only_query: true,
        });
        replies = [ok(one(saved)), ok(list([saved])), ok(source)];
        await click(save());

        expect(writes()[0].body).toMatchObject({
            method: 'POST',
            path: '/search',
            body_template: '{"from": {"$param": "from"}}',
            read_only_query: true,
            confirm_read_only: true,
        });
    });

    it('does not send a body or the flag after switching back to GET', async () => {
        await openAdd();
        await type('[data-test="path"]', '/x');
        await click(segment('POST'));
        await type('[data-test="body-template"]', '{}');
        await click(segment('GET'));

        expect($('[data-slot="post-readonly"]')).toBeNull();

        replies = [ok(one(endpoint())), ok(list([endpoint()])), ok(source)];
        await click(save());

        expect(writes()[0].body).toMatchObject({
            method: 'GET',
            body_template: null,
            read_only_query: false,
            confirm_read_only: false,
        });
    });

    it('starts an Endpoint saved as a read-only POST already ticked', async () => {
        replies = [
            ok(
                list([
                    endpoint({
                        method: 'POST',
                        read_only_query: true,
                        body_template: '{}',
                    }),
                ]),
            ),
            ok(source),
        ];
        await mountPage();
        await click($('[data-test="edit-endpoint"]'));

        expect(
            $('[data-test="post-readonly"]')?.getAttribute('aria-checked'),
        ).toBe('true');
        expect(save().getAttribute('aria-disabled')).toBeNull();
        expect(
            ($('[data-test="body-template"]') as HTMLTextAreaElement).value,
        ).toBe('{}');
    });

    it('shows a body template error on the body field', async () => {
        await openAdd();
        await type('[data-test="path"]', '/x');
        await click(segment('POST'));
        await type('[data-test="body-template"]', '{"q":"{from}"}');
        await click($('[data-test="post-readonly"]'));
        await click(
            $$('[role="alertdialog"] button').find((b) =>
                b.textContent?.includes(labels.riskConfirm),
            ) ?? null,
        );

        replies = [
            refused(422, {
                errors: { body_template: ['x'] },
                reasons: { body_template: 'body-template-interpolation' },
            }),
        ];
        await click(save());

        const body = $('[data-test="body-template"]');
        expect(body?.getAttribute('aria-invalid')).toBe('true');
        expect(document.activeElement).toBe(body);
        expect($('[data-slot="field-error"]')?.textContent).toContain(
            labels.reasons['body-template-interpolation'],
        );
    });
});

describe('pathPlaceholders', () => {
    it('lists whole-segment placeholders once, in order', () => {
        expect(pathPlaceholders('/a/{id}/b/{order_id}/{id}')).toEqual([
            'id',
            'order_id',
        ]);
        expect(pathPlaceholders('/a/{1x}/b/x{id}/{ok}')).toEqual(['ok']);
        expect(pathPlaceholders('/plain')).toEqual([]);
    });
});
