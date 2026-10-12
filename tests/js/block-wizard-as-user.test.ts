// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { setConnectionForTest } from '../../resources/js/composables/useConnection';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    asUserRef,
    asUserUsable,
    emptyAsUser,
    endAsUser,
} from '../../resources/js/lib/blockUserSample';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';

// Story 3.17: Fetch as user in wizard Step 2 and Preview as in Step 4. The browser sends the member and the request's non-bound choices only, polls
// the existing Operation endpoint and reads back the value-free structure; the preview is the same endpoint with the Operation named, labelled with
// the member's name and "Sample data", and it goes back to the shared sample (with a notice) when the result ends. Without
// `data.preview_as_user` both controls are aria-disabled with the reason and nothing is sent.
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

const BASE = '/api/v1/admin/blocks/drafts/d-1';
const SOURCE = 'a0000000-0000-7000-8000-000000000001';
const ENDPOINT = 'e0000000-0000-7000-8000-000000000001';
const OPERATION = 'f0000000-0000-7000-8000-000000000009';
const CANARY = 'CANARY-attr-91d2';

function draft(step: number): WizardDraft {
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
        step,
        owner_membership_id: 'm-1',
        owner: null,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-02T10:00:00Z',
        config: {
            schema_version: 1,
            description: 'Daily revenue',
            source: { data_source_id: SOURCE, endpoint_id: ENDPOINT },
        },
        ui_state: { acknowledged_warnings: [], slot_statuses: {} },
    } as WizardDraft;
}

const fmt = {
    kind: 'number',
    decimalPlaces: null,
    thousandsSeparator: true,
    currencyCode: null,
    currencyDisplay: 'symbol',
    datePattern: 'medium',
    unitsSuffix: null,
};

function preview(value: string, extra: Record<string, unknown> = {}) {
    return {
        payload: {
            state: 'normal',
            title: 'From the server',
            subtitle: null,
            icon: null,
            footerAction: null,
            kpiLabel: 'Revenue',
            headline: { available: true, value, format: fmt },
            comparison: null,
            comparisonLabel: null,
            secondaryNote: null,
            statusBadge: null,
            staleSince: null,
            errorMessage: null,
        },
        unavailable: {},
        slots: { headline_value: { value, path: '$.data.revenue' } },
        sample_revision: 1,
        draft_revision: 1,
        plan_stale: false,
        ...extra,
    };
}

type Reply = { status: number; json?: unknown };
type Call = { url: string; method: string; body: Record<string, any> | null };
const calls: Call[] = [];
let wrapper: VueWrapper | null = null;
let startReply: () => Reply;
let operationReply: () => Reply;
let shapeReply: () => Reply;
let userPreview: (body: Record<string, any>) => Reply;
let membersReply: Reply;
let expiresAt: string;

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const text = (selector: string) =>
    $(selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
const blocked = (selector: string) =>
    $(selector)?.getAttribute('aria-disabled') === 'true';

async function settle(): Promise<void> {
    await flushPromises();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await flushPromises();
}

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await settle();
}

async function choose(selector: string, value: string): Promise<void> {
    const select = $(selector) as HTMLSelectElement;

    select.value = value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    select.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
}

async function open(step: 2 | 4) {
    window.history.replaceState(
        {},
        '',
        `/admin/blocks/create?draft=d-1&step=${step}`,
    );
    wrapper = mount(BlockWizard as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await settle();
}

const asUserCalls = () =>
    calls.filter((c) => c.url === `${BASE}/sample/fetch-as-user`);
const previews = () =>
    calls.filter((c) => c.method === 'POST' && c.url === `${BASE}/preview`);

beforeEach(() => {
    setActivePinia(createPinia());
    page.props = {
        autosave: { debounce_ms: null },
        shell: {
            area: 'admin',
            items: [],
            can: {
                'blocks.edit': true,
                'blocks.publish': true,
                'data.preview_as_user': true,
            },
        },
    };
    setConnectionForTest(true);
    calls.length = 0;
    sessionStorage.clear();
    expiresAt = new Date(Date.now() + 10 * 60_000).toISOString();
    startReply = () => ({
        status: 202,
        json: {
            data: {
                operation_id: OPERATION,
                status: 'queued',
                expires_at: expiresAt,
                endpoint_revision: 2,
            },
        },
    });
    operationReply = () => ({
        status: 200,
        json: {
            data: {
                id: OPERATION,
                kind: 'fetch_as_user',
                status: 'succeeded',
                result: {
                    ok: true,
                    status: 200,
                    latency_ms: 120,
                    code: null,
                    reason: null,
                    host: 'api.example.com',
                    request_id: 'req-1',
                    missing: null,
                },
                expires_at: expiresAt,
            },
        },
    });
    shapeReply = () => ({
        status: 200,
        json: {
            data: {
                status: 200,
                latency_ms: 120,
                expires_at: expiresAt,
                shape: {
                    records: null,
                    scalars: 1,
                    arrays: 0,
                    objects: 2,
                    nodes: [
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
                            key: 'revenue',
                            path: '$.revenue',
                            display: 'revenue',
                            type: 'decimal',
                            example: null,
                            count: null,
                        },
                    ],
                },
            },
        },
    });
    userPreview = () => ({
        status: 200,
        json: { data: preview('99', { as_user: { expires_at: expiresAt } }) },
    });
    membersReply = {
        status: 200,
        json: {
            data: {
                members: [
                    {
                        membership_id: 'm-2',
                        name: 'Grace Hopper',
                        email: 'grace@example.test',
                        groups: ['Finance'],
                    },
                ],
            },
        },
    };
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                const method = init?.method ?? 'GET';
                const body = init?.body ? JSON.parse(init.body) : null;
                let reply: Reply;

                if (/\/lock(\/|$)/.test(url)) {
                    reply = { status: 200, json: { data: { enabled: false } } };

                    return {
                        ok: true,
                        status: 200,
                        headers: { get: () => null },
                        json: async () => reply.json,
                    };
                }

                calls.push({ url, method, body });

                if (url === '/api/v1/admin/blocks/categories') {
                    reply = {
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
                    };
                } else if (url === '/api/v1/admin/blocks/data-sources') {
                    reply = {
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
                    };
                } else if (url === '/api/v1/admin/blocks/refresh-intervals') {
                    reply = {
                        status: 200,
                        json: { data: { intervals: [60] } },
                    };
                } else if (
                    /\/blocks\/data-sources\/[^/]+\/endpoints$/.test(url)
                ) {
                    reply = {
                        status: 200,
                        json: {
                            data: [
                                {
                                    endpoint_id: ENDPOINT,
                                    data_source_id: SOURCE,
                                    method: 'GET',
                                    path: '/revenue/{region}',
                                    params: [
                                        {
                                            name: 'region',
                                            binding: 'user_attribute',
                                            value: 'region',
                                            kind: 'path',
                                        },
                                    ],
                                    read_only_query: false,
                                    requires_user_context: true,
                                    revision: 2,
                                    revision_id:
                                        'r0000000-0000-7000-8000-000000000001',
                                },
                            ],
                        },
                    };
                } else if (
                    url.startsWith(
                        '/api/v1/admin/blocks/preview-as-user/members',
                    )
                ) {
                    reply = membersReply;
                } else if (url === `${BASE}/sample/fetch-as-user`) {
                    reply = startReply();
                } else if (url.startsWith('/api/v1/operations/')) {
                    reply = operationReply();
                } else if (url.startsWith(`${BASE}/sample/as-user/`)) {
                    reply = shapeReply();
                } else if (url === `${BASE}/preview`) {
                    reply =
                        body && body.as_user_operation
                            ? userPreview(body)
                            : {
                                  status: 200,
                                  json: { data: preview('284680') },
                              };
                } else if (url === `${BASE}/mapping`) {
                    reply = {
                        status: 200,
                        json: {
                            data: {
                                checklist: {
                                    groups: [],
                                    counts: {
                                        auto_mapped: 0,
                                        total: 0,
                                        confirmed: 0,
                                        needs_review: 0,
                                        required_missing: 0,
                                        type_mismatch: 0,
                                    },
                                    blockers: [],
                                    baseline: [],
                                    api_changed: [],
                                },
                                roles: null,
                                slot_map: {},
                                query_plan: null,
                                draft_revision: 1,
                                sample_revision: 1,
                                plan_stale: false,
                                sample: 'ok',
                            },
                        },
                    };
                } else if (url === `${BASE}/sample`) {
                    reply = { status: 200, json: { data: null } };
                } else if (url === BASE && method === 'GET') {
                    reply = {
                        status: 200,
                        json: {
                            data: draft(
                                window.location.search.includes('step=2')
                                    ? 2
                                    : 4,
                            ),
                        },
                    };
                } else {
                    reply = { status: 200, json: { data: [] } };
                }

                return {
                    ok: reply.status < 400,
                    status: reply.status,
                    headers: { get: () => null },
                    json: async () => reply.json ?? {},
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
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('the Fetch as user state', () => {
    it('is usable only while it exists, is in use, has not ended and matches the request', () => {
        const state = emptyAsUser();

        expect(asUserUsable(state, 'k')).toBe(false);

        Object.assign(state, {
            operationId: OPERATION,
            dataSourceId: SOURCE,
            endpointId: ENDPOINT,
            requestKey: 'k',
            using: true,
            expiresAt: new Date(Date.now() + 60_000).toISOString(),
        });

        expect(asUserUsable(state, 'k')).toBe(true);
        expect(asUserUsable(state, 'other')).toBe(false);
        expect(asUserUsable(state, 'k', Date.now() + 120_000)).toBe(false);
        expect(asUserRef(state)).toEqual({
            as_user_operation: OPERATION,
            data_source_id: SOURCE,
            endpoint_id: ENDPOINT,
        });

        endAsUser(state, true);

        expect(asUserUsable(state, 'k')).toBe(false);
        expect(state.ended).toBe(true);
        expect(asUserRef(state)).toBeNull();
    });
});

describe('Step 2: Fetch as user', () => {
    it('fetches as the chosen member, sends only the member and the non-bound choices, and shows the value-free structure', async () => {
        await open(2);

        expect(text('[data-test=as-user-fetch]')).toContain('Fetch as user');
        expect(blocked('[data-test=as-user-run]')).toBe(true);
        expect(text('[data-test=as-user-reason]')).toContain(
            'Choose a member first.',
        );
        expect($('[data-test=as-user-member]')!.textContent).toContain(
            'Grace Hopper (grace@example.test · Finance)',
        );

        await choose('[data-test=as-user-member]', 'm-2');
        expect(blocked('[data-test=as-user-run]')).toBe(false);

        await click($('[data-test=as-user-run]'));
        await settle();

        const start = asUserCalls();

        expect(start).toHaveLength(1);
        expect(start[0].body).toMatchObject({
            membership: 'm-2',
            data_source_id: SOURCE,
            endpoint_id: ENDPOINT,
        });
        expect(Object.keys(start[0].body!).sort()).toEqual([
            'bindings',
            'data_source_id',
            'endpoint_id',
            'membership',
            'period',
            'range',
        ]);
        expect(
            calls.some((c) => c.url.startsWith(`${BASE}/sample/as-user/`)),
        ).toBe(true);
        expect(text('[data-test=as-user-name]')).toBe(
            'Previewing as Grace Hopper',
        );
        expect(text('[data-test=as-user-badge]')).toBe('Sample data');
        expect(text('[data-test=as-user-fetch]')).toContain('revenue');
        expect(document.body.textContent).not.toContain(CANARY);
        // The result is never written to the Draft or the stored sample.
        expect(
            calls.filter(
                (c) => c.method === 'PUT' && c.url.startsWith(`${BASE}/sample`),
            ),
        ).toHaveLength(0);
    });

    it('names the missing attribute and shows no result when the member lacks the bound attribute', async () => {
        operationReply = () => ({
            status: 200,
            json: {
                data: {
                    id: OPERATION,
                    kind: 'fetch_as_user',
                    status: 'failed',
                    result: {
                        ok: false,
                        status: null,
                        latency_ms: null,
                        code: 'access.context_missing',
                        reason: 'context_missing',
                        host: null,
                        request_id: 'req-2',
                        missing: 'region',
                    },
                    expires_at: expiresAt,
                },
            },
        });
        await open(2);
        await choose('[data-test=as-user-member]', 'm-2');
        await click($('[data-test=as-user-run]'));
        await settle();

        expect(text('[data-test=fetch-error-message]')).toContain('region');
        expect(text('[data-test=fetch-error-message]')).toContain(
            'nothing was sent',
        );
        expect($('[data-test=as-user-result]')).toBeNull();
        expect(
            calls.some((c) => c.url.startsWith(`${BASE}/sample/as-user/`)),
        ).toBe(false);
    });

    it('is aria-disabled with the reason, and sends nothing, without data.preview_as_user', async () => {
        (page.props.shell as any).can['data.preview_as_user'] = false;
        await open(2);

        expect(blocked('[data-test=as-user-run]')).toBe(true);
        expect(text('[data-test=as-user-reason]')).toContain(
            "You don't have permission to fetch or preview as a user.",
        );
        expect(
            calls.some((c) => c.url.includes('/preview-as-user/members')),
        ).toBe(false);

        await click($('[data-test=as-user-run]'));

        expect(asUserCalls()).toHaveLength(0);
    });

    it('puts the wait a 429 asked for on the button and starts nothing more', async () => {
        startReply = () => ({
            status: 429,
            json: {
                error: {
                    code: 'platform.too_many_requests',
                    retry_after: 7,
                },
            },
        });
        await open(2);
        await choose('[data-test=as-user-member]', 'm-2');
        await click($('[data-test=as-user-run]'));
        await settle();

        expect(text('[data-test=as-user-run]')).toBe('Wait 7s');
        expect(blocked('[data-test=as-user-run]')).toBe(true);
        expect(asUserCalls()).toHaveLength(1);
        expect($('[data-test=as-user-result]')).toBeNull();
        expect(calls.some((c) => c.url.startsWith('/api/v1/operations/'))).toBe(
            false,
        );
    });

    it('says why when the server refuses the member (404) and keeps nothing', async () => {
        startReply = () => ({ status: 404, json: {} });
        await open(2);
        await choose('[data-test=as-user-member]', 'm-2');
        await click($('[data-test=as-user-run]'));
        await settle();

        expect(text('[data-test=as-user-refusal]')).toContain(
            'no longer in the workspace',
        );
        expect($('[data-test=as-user-result]')).toBeNull();
    });
});

describe('Step 4: Preview as', () => {
    it('is aria-disabled with the reason without data.preview_as_user, and the shared preview is unaffected', async () => {
        (page.props.shell as any).can['data.preview_as_user'] = false;
        await open(4);

        expect(blocked('[data-test=as-user-run]')).toBe(true);
        expect(text('[data-test=as-user-preview]')).toContain(
            "You don't have permission to fetch or preview as a user.",
        );
        expect(text('[data-test=kpi-headline]')).toBe('284,680');
        expect(text('[data-test=preview-sample-label]')).toBe('Sample data');
    });

    it('previews from the fetched result, labelled with the member and Sample data, and switches back to the shared sample', async () => {
        await open(4);

        expect(text('[data-test=block-full] [data-test=kpi-headline]')).toBe(
            '284,680',
        );

        await choose('[data-test=as-user-member]', 'm-2');
        await click($('[data-test=as-user-run]'));
        await settle();
        await settle();

        const withUser = previews().filter((c) => c.body?.as_user_operation);

        expect(withUser.length).toBeGreaterThan(0);
        expect(withUser[0].body).toEqual({
            as_user_operation: OPERATION,
            data_source_id: SOURCE,
            endpoint_id: ENDPOINT,
        });
        expect(text('[data-test=block-full] [data-test=kpi-headline]')).toBe(
            '99',
        );
        expect(text('[data-test=preview-sample-label]')).toBe(
            'Sample data · previewing as Grace Hopper',
        );

        const shared = Array.from(
            document.querySelectorAll<HTMLElement>('[role=radio]'),
        ).find((r) => r.textContent?.trim() === 'Shared sample')!;

        await click(shared);

        expect(text('[data-test=block-full] [data-test=kpi-headline]')).toBe(
            '284,680',
        );
        expect(text('[data-test=preview-sample-label]')).toBe('Sample data');
    });

    it('goes back to the shared sample with a notice when the result has ended on the server', async () => {
        await open(4);
        await choose('[data-test=as-user-member]', 'm-2');
        userPreview = () => ({
            status: 422,
            json: {
                error: {
                    code: 'blocks.preview_as_user_ended',
                    message: 'The fetched user data is no longer available.',
                },
            },
        });
        await click($('[data-test=as-user-run]'));
        await settle();
        await settle();

        expect(text('[data-test=preview-as-ended]')).toContain(
            'expired, so the preview shows the shared sample again',
        );
        expect(text('[data-test=block-full] [data-test=kpi-headline]')).toBe(
            '284,680',
        );
        expect(text('[data-test=preview-sample-label]')).toBe('Sample data');
        expect($('[data-test=as-user-result]')).toBeNull();
    });

    it('goes back to the shared sample with the same notice when the result expires by time', async () => {
        expiresAt = new Date(Date.now() + 1500).toISOString();
        await open(4);
        await choose('[data-test=as-user-member]', 'm-2');
        await click($('[data-test=as-user-run]'));
        await settle();
        await settle();

        expect(text('[data-test=block-full] [data-test=kpi-headline]')).toBe(
            '99',
        );

        await new Promise((resolve) => setTimeout(resolve, 1700));
        await settle();

        expect(text('[data-test=preview-as-ended]')).toContain(
            'shows the shared sample again',
        );
        expect(text('[data-test=block-full] [data-test=kpi-headline]')).toBe(
            '284,680',
        );
    });

    it('shows the shared label and a notice, not the member label, while the as-user preview has failed', async () => {
        await open(4);
        await choose('[data-test=as-user-member]', 'm-2');
        userPreview = () => ({ status: 503, json: {} });
        await click($('[data-test=as-user-run]'));
        await settle();
        await settle();

        expect(text('[data-test=preview-sample-label]')).toBe('Sample data');
        expect(text('[data-test=preview-as-unavailable]')).toContain(
            'shared sample is shown',
        );
        expect(text('[data-test=block-full] [data-test=kpi-headline]')).toBe(
            '284,680',
        );
    });

    it('keeps the warnings and the Continue gate on the shared sample while previewing as a member', async () => {
        await open(4);
        await choose('[data-test=as-user-member]', 'm-2');
        await click($('[data-test=as-user-run]'));
        await settle();
        await settle();

        // The shared preview is still asked for (its warnings are what Continue is gated on), without the as-user fields.
        expect(
            previews().some((c) => !c.body || !c.body.as_user_operation),
        ).toBe(true);
        expect(
            $('[data-test=continue]')?.getAttribute('aria-disabled'),
        ).not.toBe('true');
    });
});
