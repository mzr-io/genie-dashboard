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

// Story 2.13: the Binding select lists "User context", a bound row shows the tag and the text "user context" and never a value,
// an Endpoint without a user binding shows "shared data", and Fetch as user has a member picker, never sends a bound value and is
// `aria-disabled` with `perm-denied` without `data.preview_as_user`; a member without a value ends in the error card naming keys.
const shell = { can: {} as Record<string, boolean>, items: [] };

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
            props: { shell },
        }),
    };
});

const { default: DataSourceEndpoints } =
    await import('../../resources/js/pages/admin/DataSourceEndpoints.vue');

const DS = 'ds-1';
const OP = '018f0000-0000-7000-8000-0000000000bb';
const MEMBER = '018f0000-0000-7000-8000-0000000000cc';

function endpoint(overrides: Partial<Endpoint> = {}): Endpoint {
    return {
        endpoint_id: 'ep-1',
        data_source_id: DS,
        method: 'GET',
        path: '/reports/{id}',
        path_ast: [],
        params: [
            { name: 'id', binding: 'fixed', value: 'r-1', kind: 'path' },
            { name: 'uid', binding: 'user_id', value: null, kind: 'query' },
            {
                name: 'region',
                binding: 'user_attribute',
                value: 'region',
                kind: 'query',
            },
        ],
        headers: [{ name: 'X-Mail', binding: 'user_email', value: null }],
        body_template: null,
        read_only_query: false,
        requires_user_context: true,
        scope_by_caller: false,
        revision: 2,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-01T09:30:00Z',
        ...overrides,
    };
}

const shared = (): Endpoint =>
    endpoint({
        endpoint_id: 'ep-2',
        path: '/shared',
        params: [],
        headers: [],
        requires_user_context: false,
    });

const list = (data: Endpoint[]) => ({
    data,
    meta: { total: data.length, matched: data.length },
});
const source = { data: { data_source_id: DS, name: 'Sales API' }, meta: {} };
const options = {
    data: {
        bindings: ['user_id', 'user_email', 'user_group', 'user_attribute'],
        attributes: [
            { key_id: 'region', label: 'Sales region', value_type: 'text' },
        ],
        members: [
            {
                membership_id: MEMBER,
                name: 'Grace',
                email: 'grace@example.test',
            },
        ],
        may_preview: true,
    },
};

type Reply = { ok: boolean; status: number; json: unknown };
type Call = { url: string; method: string; body: unknown };
const calls: Call[] = [];
let replies: Reply[] = [];
const ok = (json: unknown): Reply => ({ ok: true, status: 200, json });
const operation = (status: string, result: Record<string, unknown> | null) =>
    ok({
        data: {
            id: OP,
            kind: 'fetch_as_user',
            status,
            result,
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

async function mountPage(endpoints: Endpoint[]): Promise<void> {
    replies = [ok(list(endpoints)), ok(source)];
    wrapper = mount(DataSourceEndpoints as never, {
        attachTo: document.body,
        props: { dataSourceId: DS },
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
}

beforeEach(() => {
    setActivePinia(createPinia());
    calls.length = 0;
    replies = [];
    shell.can = {};
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                calls.push({
                    url,
                    method: init?.method ?? 'GET',
                    body: init?.body ? JSON.parse(init.body) : undefined,
                });
                const reply = url.includes('/binding-options')
                    ? ok(options)
                    : replies.shift();

                if (!reply) {
                    throw new Error(`unexpected request ${url}`);
                }

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
});

describe('the Endpoints table', () => {
    it('tags a user-bound Endpoint and shows "shared data" in text for the others', async () => {
        await mountPage([endpoint(), shared()]);
        const rows = $$('tbody [data-slot="data-row"]');

        expect(
            rows[0].querySelector('[data-test="user-context-tag"]')
                ?.textContent,
        ).toBe(labels.userContextTag);
        expect(rows[0].querySelector('[data-test="shared-data"]')).toBeNull();
        expect(
            rows[1].querySelector('[data-test="shared-data"]')?.textContent,
        ).toContain(labels.sharedData);
        expect(rows[1].textContent).toContain(labels.sharedDataHint);
    });

    it('keeps Fetch as user aria-disabled with perm-denied without data.preview_as_user, and opens nothing', async () => {
        await mountPage([endpoint()]);
        const button = $('[data-test="fetch-as-user"]');

        expect(button?.getAttribute('aria-disabled')).toBe('true');
        expect($('[data-test="fetch-as-user-denied"]')).not.toBeNull();

        await click(button);

        expect($('[data-test="member-picker"]')).toBeNull();
    });
});

describe('the Binding select', () => {
    it('lists the User context group with the three fixed bindings and each defined attribute by label', async () => {
        await mountPage([endpoint()]);
        await click($('[data-test="edit-endpoint"]'));
        await flushPromises();

        const group = $('[data-test="params-binding"] optgroup');

        expect(group?.getAttribute('label')).toBe(labels.userContextGroup);
        expect(
            Array.from(group!.querySelectorAll('option')).map((o) =>
                o.textContent?.trim(),
            ),
        ).toEqual([
            labels.bindings.user_id,
            labels.bindings.user_email,
            labels.bindings.user_group,
            labels.attributeOption('Sales region'),
        ]);
    });

    it('shows a bound row as the tag and the text "user context", never a value, and keeps the key id out of what is read out', async () => {
        await mountPage([endpoint()]);
        await click($('[data-test="edit-endpoint"]'));
        await flushPromises();
        const rows = $$('[data-test="params-row"]');

        for (const row of rows.slice(1)) {
            expect(
                row.querySelector('[data-test="user-context-tag"]')
                    ?.textContent,
            ).toBe(labels.userContextTag);
            expect(
                row
                    .querySelector('[data-test="resolved"]')
                    ?.textContent?.trim(),
            ).toBe(labels.userContextResolved);
            expect(row.querySelector('[data-test="params-value"]')).toBeNull();
        }

        expect(
            $(
                '[data-test="headers-row"] [data-test="resolved"]',
            )?.textContent?.trim(),
        ).toBe(labels.userContextResolved);
        expect($('[data-slot="parameters-table"]')?.textContent).not.toMatch(
            /grace|@example/i,
        );
    });

    it('sends the attribute key id as the value of a user_attribute binding and no value for the others', async () => {
        await mountPage([]);
        await click($('[data-test="add-endpoint"]'));
        const path = $('[data-test="path"]') as HTMLInputElement;
        path.value = '/reports';
        path.dispatchEvent(new Event('input', { bubbles: true }));
        await click($('[data-test="params-add"]'));
        const name = $('[data-test="params-name"]') as HTMLInputElement;
        name.value = 'region';
        name.dispatchEvent(new Event('input', { bubbles: true }));
        const select = $('[data-test="params-binding"]') as HTMLSelectElement;
        select.value = 'user_attribute:region';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        select.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        // The scope control appears once a row is bound to user context.
        expect($('[data-test="scope-by-caller"]')).not.toBeNull();

        replies = [ok({ data: endpoint() })];
        await click($('[data-test="save"]'));

        const post = calls.find((c) => c.method === 'POST');
        expect((post!.body as { params: unknown[] }).params).toEqual([
            { name: 'region', binding: 'user_attribute', value: 'region' },
        ]);
    });
});

describe('Fetch as user', () => {
    const started = ok({
        data: {
            operation_id: OP,
            status: 'queued',
            expires_at: '2026-10-09T10:00:00Z',
            endpoint_revision: 2,
        },
    });

    async function open(): Promise<void> {
        shell.can = { 'data.preview_as_user': true };
        await mountPage([endpoint()]);
        await click($('[data-test="fetch-as-user"]'));
        await flushPromises();
    }

    it('has no input for a user-bound parameter', () => {
        expect(testFields(endpoint()).map((f) => f.key)).toEqual(['id']);
    });

    it('shows a member picker, needs a member, and sends the member and only the non-bound values', async () => {
        await open();
        const run = $('[data-test="run-test"]') as HTMLButtonElement;

        expect($('[data-test="member-picker"]')).not.toBeNull();
        expect($$('[data-test="test-value"]')).toHaveLength(1);
        expect(run.getAttribute('aria-disabled')).toBe('true');
        expect($('[data-slot="blocked-reason"]')?.textContent).toBe(
            labels.fetchAsUserMemberRequired,
        );

        const select = $('[data-test="member-select"]') as HTMLSelectElement;
        select.value = MEMBER;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        select.dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();
        replies = [
            started,
            operation('failed', {
                ok: false,
                status: null,
                latency_ms: null,
                code: 'access.context_missing',
                reason: 'context_missing',
                missing: 'region,team',
                host: 'api.example.com',
                request_id: 'req-9',
                endpoint_revision: 2,
            }),
        ];
        await click($('[data-test="run-test"]'));

        const post = calls.find((c) => c.method === 'POST');
        expect(post?.url).toBe(
            '/api/v1/admin/data-sources/ds-1/endpoints/ep-1/fetch-as-user',
        );
        expect(post?.body).toEqual({
            membership: MEMBER,
            values: { id: 'r-1' },
        });
        expect($('[data-test="fetch-error-title"]')?.textContent).toContain(
            labels.fetchAsUserFailedTitle,
        );
        expect($('[data-test="fetch-error-message"]')?.textContent).toBe(
            labels.contextMissing(['region', 'team']),
        );
    });
});

describe('the error card for access.context_missing', () => {
    it('names the keys, and says something else when no key is missing', async () => {
        const { default: Card } =
            await import('../../resources/js/components/FetchErrorCard.vue');
        const text = (props: Record<string, unknown>) => {
            const card = mount(Card as never, {
                props: {
                    code: 'access.context_missing',
                    source: 'S',
                    ...props,
                },
                global: { plugins: [createCatalogue()] },
            });
            const out = card.find('[data-test="fetch-error-message"]').text();
            card.unmount();

            return out;
        };

        expect(text({ missing: 'region', reason: 'context_missing' })).toBe(
            labels.contextMissing(['region']),
        );
        expect(text({ missing: null, reason: 'member_unavailable' })).toBe(
            labels.contextMemberUnavailable,
        );
        expect(text({ missing: null, reason: 'context_value_invalid' })).toBe(
            labels.contextMissing([]),
        );
    });
});
