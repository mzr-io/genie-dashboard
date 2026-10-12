// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    caretAt,
    shapeText,
    visibleNodes,
} from '../../resources/js/lib/blockSample';
import type { StoredSample } from '../../resources/js/lib/blockSample';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { setConnectionForTest } from '../../resources/js/composables/useConnection';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';

// Story 3.8: the Sample Response of wizard Step 2, paste half: the segmented control, the json-textarea checked on paste and on blur, the
// valid and invalid messages, the limits, accepting a sample (acknowledgement, summary, badge), the structure view and restoring on reopen.
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
const BODY =
    '{"data":{"monthly":[{"month":"Jan","revenue":12345678901234567890.10,"rate":1.0},{"month":"Feb","revenue":2.50},{"month":"Mar","revenue":3}],"currency":"USD"}}';
const CANARY = 'CANARY-sample-77c1';

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
        config: { schema_version: 1, description: 'Daily revenue' },
        ...overrides,
    } as WizardDraft;
}

function shape(overrides: Record<string, unknown> = {}) {
    return {
        records: {
            path: '$.data.monthly[*]',
            display: 'data.monthly[]',
            count: 3,
        },
        scalars: 4,
        arrays: 1,
        objects: 2,
        ...overrides,
    };
}

function nodes() {
    const node = (
        id: number,
        parent: number | null,
        depth: number,
        key: string | null,
        path: string,
        display: string,
        type: string,
        example: string | null,
        count: number | null = null,
    ) => ({ id, parent, depth, key, path, display, type, example, count });

    return [
        node(0, null, 0, null, '$', '$', 'object', null),
        node(1, 0, 1, 'data', '$.data', 'data', 'object', null),
        node(
            2,
            1,
            2,
            'monthly',
            '$.data.monthly',
            'data.monthly',
            'array',
            null,
            3,
        ),
        node(
            3,
            2,
            3,
            null,
            '$.data.monthly[*]',
            'data.monthly[]',
            'object',
            null,
        ),
        node(
            4,
            3,
            4,
            'month',
            '$.data.monthly[*].month',
            'data.monthly[].month',
            'string',
            'Jan',
        ),
        node(
            5,
            3,
            4,
            'revenue',
            '$.data.monthly[*].revenue',
            'data.monthly[].revenue',
            'integer | decimal',
            '12345678901234567890.10',
        ),
        node(
            6,
            3,
            4,
            'rate',
            '$.data.monthly[*].rate',
            'data.monthly[].rate',
            'decimal',
            '1.0',
        ),
        node(
            7,
            1,
            2,
            'currency',
            '$.data.currency',
            'data.currency',
            'string',
            'USD',
        ),
    ];
}

function stored(overrides: Partial<StoredSample> = {}): StoredSample {
    return {
        origin: 'pasted',
        text: BODY,
        size_bytes: BODY.length,
        pasted_at: '2026-10-09T09:14:00Z',
        expires_at: '2026-10-09T10:14:00Z',
        contains_personal_data: true,
        valid: true,
        reason: null,
        line: null,
        column: null,
        problem: null,
        limit: null,
        setting: null,
        shape: {
            ...shape(),
            nodes: nodes(),
            tables: {
                '2': {
                    columns: [
                        { key: 'month', display: 'data.monthly[].month' },
                        { key: 'revenue', display: 'data.monthly[].revenue' },
                    ],
                    rows: [
                        ['Jan', '12345678901234567890.10'],
                        ['Feb', '2.50'],
                        ['Mar', '3'],
                    ],
                    total: 3,
                },
            },
        },
        size: undefined,
        ...overrides,
    } as StoredSample;
}

function check(overrides: Record<string, unknown> = {}) {
    return {
        valid: true,
        size_bytes: 412,
        reason: null,
        line: null,
        column: null,
        problem: null,
        limit: null,
        setting: null,
        shape: shape(),
        ...overrides,
    };
}

type Call = { url: string; method: string; body: Record<string, any> | null };
const calls: Call[] = [];
let checkReply: () => { status: number; json: unknown } = () => ({
    status: 200,
    json: { data: check() },
});
let storeReply: () => { status: number; json: unknown } = () => ({
    status: 200,
    json: { data: stored() },
});
let restoreReply: () => { status: number; json: unknown } = () => ({
    status: 200,
    json: { data: null },
});
let wrapper: VueWrapper | null = null;
const reply = (r: { status: number; json: unknown }) => ({
    ok: r.status < 400,
    status: r.status,
    json: async () => r.json,
});
const sampleCalls = (method: string) =>
    calls.filter((c) => c.method === method && /\/sample/.test(c.url));

async function mountPage(search = '?draft=d-1&step=2') {
    window.history.replaceState({}, '', `/admin/blocks/create${search}`);
    wrapper = mount(BlockWizard as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const text = (selector: string) =>
    $(selector)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
const blocked = (selector: string) =>
    $(selector)?.getAttribute('aria-disabled') === 'true';

async function paste(value: string): Promise<void> {
    const area = $('[data-test=json-textarea]') as HTMLTextAreaElement;

    area.dispatchEvent(new Event('paste', { bubbles: true }));
    area.value = value;
    area.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function typeText(value: string): Promise<void> {
    const area = $('[data-test=json-textarea]') as HTMLTextAreaElement;

    area.value = value;
    area.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function openPaste(): Promise<void> {
    const radios = $$('[data-test=sample-mode] [role=radio]');

    radios.find((r) => r.textContent?.includes('Paste sample JSON'))!.click();
    await flushPromises();
}

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
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
    sessionStorage.clear();
    checkReply = () => ({ status: 200, json: { data: check() } });
    storeReply = () => ({ status: 200, json: { data: stored() } });
    restoreReply = () => ({ status: 200, json: { data: null } });
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                if (/\/lock(\/|$)/.test(url)) {
                    return reply({
                        status: 200,
                        json: { data: { enabled: false } },
                    });
                }

                const method = init?.method ?? 'GET';

                calls.push({
                    url,
                    method,
                    body: init?.body ? JSON.parse(init.body) : null,
                });

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
                    return reply({ status: 200, json: { data: [] } });
                }

                if (url.endsWith('/sample/check')) {
                    return reply(checkReply());
                }

                if (url.endsWith('/sample')) {
                    return reply(
                        method === 'PUT' ? storeReply() : restoreReply(),
                    );
                }

                if (method === 'PUT') {
                    const body = JSON.parse(init!.body!);

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

                return reply({ status: 200, json: { data: draft() } });
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

describe('Sample Response: choosing how to get one', () => {
    it('shows a segmented control with Fetch from API first and inert, and Paste sample JSON beside it', async () => {
        await mountPage();

        expect(
            $$('[data-test=sample-mode] [role=radio]').map((r) =>
                r.textContent?.trim(),
            ),
        ).toEqual(['Fetch from API', 'Paste sample JSON']);
        expect(
            $('[data-test=sample-mode] [aria-checked=true]')?.textContent,
        ).toContain('Fetch from API');
        expect($('[data-test=fetch]')).not.toBeNull();
        expect($('[data-test=json-textarea]')).toBeNull();
        expect(blocked('[data-test=fetch]')).toBe(true);
    });

    it('selecting Paste sample JSON shows a json-textarea and removes Fetch', async () => {
        await mountPage();
        await openPaste();

        expect($('[data-test=json-textarea]')?.tagName).toBe('TEXTAREA');
        expect($('[data-test=json-textarea]')?.getAttribute('data-slot')).toBe(
            'json-textarea',
        );
        expect($('[data-test=fetch]')).toBeNull();
        expect($('label[for]')?.textContent).toBeDefined();
    });
});

describe('Sample Response: validation on paste and on blur', () => {
    it('checks a paste on the server at once, and says Valid JSON with the records, time, bytes and shape, politely', async () => {
        await mountPage();
        await openPaste();
        await paste(BODY);

        expect(sampleCalls('POST')).toHaveLength(1);
        expect(sampleCalls('POST')[0].url).toBe(
            '/api/v1/admin/blocks/drafts/d-1/sample/check',
        );
        expect(sampleCalls('POST')[0].body).toEqual({ text: BODY });
        expect(text('[data-test=json-valid]')).toMatch(
            /^✓ Valid JSON · 3 records found at data\.monthly\[\] · Pasted \d\d:\d\d · 412 bytes · 4 scalars, 1 array$/,
        );
        expect(
            document.querySelector('[data-announcer=polite]')?.textContent,
        ).toContain('✓ Valid JSON');
    });

    it('checks on blur when the text changed since the last check, and only then', async () => {
        await mountPage();
        await openPaste();
        await typeText(BODY);
        expect(sampleCalls('POST')).toHaveLength(0);

        const area = $('[data-test=json-textarea]') as HTMLTextAreaElement;

        area.dispatchEvent(new Event('blur'));
        await flushPromises();
        expect(sampleCalls('POST')).toHaveLength(1);

        area.dispatchEvent(new Event('blur'));
        await flushPromises();
        expect(sampleCalls('POST')).toHaveLength(1);
    });

    it('shows json-invalid with Go to line, which moves the caret to that line and column, and disables Continue', async () => {
        const broken = '{\n  "a": 1,\n  "b": [1, 2],\n  "c": 3,\n}';

        checkReply = () => ({
            status: 200,
            json: {
                data: check({
                    valid: false,
                    reason: 'not_json',
                    line: 5,
                    column: 1,
                    problem: 'an object key must be a string',
                    shape: null,
                }),
            },
        });
        await mountPage();
        await openPaste();
        await paste(broken);

        expect(text('[data-test=json-invalid]')).toBe(
            "This isn't valid JSON. Line 5 has an object key must be a string. Go to line 5",
        );
        expect(
            $('[data-test=json-textarea]')?.getAttribute('aria-invalid'),
        ).toBe('true');
        expect(blocked('[data-test=continue]')).toBe(true);
        expect($('[data-test=continue-reason]')?.textContent).toContain(
            'Fix the JSON',
        );

        await click($('[data-test=go-to-line]'));

        const area = $('[data-test=json-textarea]') as HTMLTextAreaElement;

        expect(document.activeElement).toBe(area);
        expect(area.selectionStart).toBe(broken.indexOf('}'));
        expect(area.selectionEnd).toBe(broken.indexOf('}'));
    });

    it('keeps Continue disabled while a non-empty text is unchecked, stale, failed to check or invalid, and frees it when valid or empty', async () => {
        await mountPage();
        await openPaste();
        expect(blocked('[data-test=continue]')).toBe(false);

        // Typed but not yet checked.
        await typeText('{"a":1}');
        expect(blocked('[data-test=continue]')).toBe(true);
        expect($('[data-test=continue-reason]')?.textContent).toContain(
            'checked',
        );

        // Checked and valid.
        await paste('{"a":2}');
        expect(blocked('[data-test=continue]')).toBe(false);

        // Edited since the check: stale.
        await typeText('{"a":3}');
        expect(blocked('[data-test=continue]')).toBe(true);

        // Invalid.
        checkReply = () => ({
            status: 200,
            json: {
                data: check({
                    valid: false,
                    reason: 'not_json',
                    line: 1,
                    column: 1,
                    problem: 'a value is expected',
                    shape: null,
                }),
            },
        });
        await paste('nope');
        expect(blocked('[data-test=continue]')).toBe(true);
        expect($('[data-test=continue-reason]')?.textContent).toContain(
            'Fix the JSON',
        );

        // The check could not be made.
        checkReply = () => ({ status: 500, json: { error: { code: 'x' } } });
        await paste('{"b":1}');
        expect(blocked('[data-test=continue]')).toBe(true);

        // Cleared: nothing to wait for.
        await typeText('');
        expect(blocked('[data-test=continue]')).toBe(false);
    });

    it('states the limit when a paste is over the byte or depth limit, and offers no way to accept it', async () => {
        checkReply = () => ({
            status: 200,
            json: {
                data: check({
                    valid: false,
                    reason: 'too_large',
                    size_bytes: 9000,
                    limit: 5000,
                    shape: null,
                }),
            },
        });
        await mountPage();
        await openPaste();
        await paste(BODY);

        expect(text('[data-test=json-refused]')).toContain(
            '9000 bytes and the limit is 5000 bytes',
        );
        expect($('[data-test=use-sample]')).toBeNull();

        checkReply = () => ({
            status: 200,
            json: {
                data: check({
                    valid: false,
                    reason: 'too_deep',
                    limit: 10,
                    shape: null,
                }),
            },
        });
        await paste(`${BODY} `);
        expect(text('[data-test=json-refused]')).toContain(
            'more than 10 levels deep',
        );
    });

    it('says which limit is not configured and stores nothing', async () => {
        checkReply = () => ({
            status: 200,
            json: {
                data: check({
                    valid: false,
                    reason: 'limit_not_configured',
                    setting: 'max_bytes',
                    shape: null,
                }),
            },
        });
        await mountPage();
        await openPaste();
        await paste(BODY);

        expect(text('[data-test=json-refused]')).toContain(
            'size limit is not set in System settings',
        );
        expect(sampleCalls('PUT')).toHaveLength(0);
    });

    it('keeps the text and says so when the check cannot be made', async () => {
        checkReply = () => ({ status: 500, json: { error: { code: 'x' } } });
        await mountPage();
        await openPaste();
        await paste(BODY);

        expect(text('[data-test=sample-problem]')).toContain(
            "couldn't check the JSON",
        );
        expect(
            ($('[data-test=json-textarea]') as HTMLTextAreaElement).value,
        ).toBe(BODY);
    });
});

describe('Sample Response: accepting a sample', () => {
    it('needs the personal-data acknowledgement before it is stored, with the reason inline', async () => {
        await mountPage();
        await openPaste();
        await paste(BODY);

        expect(blocked('[data-test=use-sample]')).toBe(true);
        expect(text('[data-slot=blocked-reason]')).toContain(
            'Confirm the personal data box',
        );

        await click($('[data-test=use-sample]'));
        expect(sampleCalls('PUT')).toHaveLength(0);
    });

    it('stores the text with the acknowledgement and the lock claim, then shows Pasted time, the badge and the step summary', async () => {
        await mountPage();
        await openPaste();
        await paste(BODY);
        await click($('[data-test=personal-data]'));
        expect(blocked('[data-test=use-sample]')).toBe(false);
        await click($('[data-test=use-sample]'));

        expect(sampleCalls('PUT')).toHaveLength(1);
        expect(sampleCalls('PUT')[0].url).toBe(
            '/api/v1/admin/blocks/drafts/d-1/sample',
        );
        expect(sampleCalls('PUT')[0].body).toEqual({
            text: BODY,
            contains_personal_data: true,
            lock_epoch: 1,
        });
        expect(text('[data-test=sample-summary]')).toMatch(
            /^Pasted \d\d:\d\d · \d+ bytes$/,
        );
        expect(text('[data-test=sample-badge]')).toBe(en['sample-wizard']);
        expect($('[data-test=accept]')).toBeNull();
        expect(
            document.querySelector('[data-announcer=polite]')?.textContent,
        ).toContain('Sample saved.');
        expect(document.body.textContent).toContain('Pasted 09:14'.slice(0, 7));
    });

    it('clears the out-of-date notice for the fresh sample and saves that with the Draft', async () => {
        const marked = draft({
            config: {
                schema_version: 1,
                description: 'Daily revenue',
                source: { data_source_id: SOURCE, sample_out_of_date: true },
            } as never,
        });

        vi.stubGlobal(
            'fetch',
            vi.fn(
                async (
                    url: string,
                    init?: { method?: string; body?: string },
                ) => {
                    const method = init?.method ?? 'GET';

                    if (/\/lock/.test(url)) {
                        return reply({
                            status: 200,
                            json: { data: { enabled: false } },
                        });
                    }

                    calls.push({
                        url,
                        method,
                        body: init?.body ? JSON.parse(init.body) : null,
                    });

                    if (url.endsWith('categories'))
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
                    if (url.endsWith('data-sources'))
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
                    if (url.endsWith('refresh-intervals'))
                        return reply({
                            status: 200,
                            json: { data: { intervals: [] } },
                        });
                    if (url.endsWith('endpoints'))
                        return reply({ status: 200, json: { data: [] } });
                    if (url.endsWith('sample/check'))
                        return reply(checkReply());
                    if (url.endsWith('/sample'))
                        return reply(
                            method === 'PUT' ? storeReply() : restoreReply(),
                        );
                    if (method === 'PUT')
                        return reply({
                            status: 200,
                            json: {
                                data: draft({
                                    revision: 2,
                                    config: JSON.parse(init!.body!).config,
                                }),
                            },
                        });

                    return reply({ status: 200, json: { data: marked } });
                },
            ),
        );
        await mountPage();
        expect($('[data-test=out-of-date]')).not.toBeNull();

        await openPaste();
        await paste(BODY);
        await click($('[data-test=personal-data]'));
        await click($('[data-test=use-sample]'));
        expect($('[data-test=out-of-date]')).toBeNull();

        await click($('[data-test=save-draft]'));
        const saved = calls.filter(
            (c) => c.method === 'PUT' && !/sample/.test(c.url),
        );

        expect(saved.at(-1)?.body?.config.source.sample_out_of_date).toBe(
            false,
        );
    });

    it("shows the server's reason when it refuses (no lifetime set), keeps the text and shows no summary", async () => {
        storeReply = () => ({
            status: 422,
            json: {
                error: { code: 'blocks.sample_invalid' },
                reason: 'ttl_not_configured',
            },
        });
        await mountPage();
        await openPaste();
        await paste(BODY);
        await click($('[data-test=personal-data]'));
        await click($('[data-test=use-sample]'));

        expect(text('[data-test=sample-problem]')).toContain(
            'sample lifetime is not set',
        );
        expect($('[data-test=sample-summary]')).toBeNull();
        expect(
            ($('[data-test=json-textarea]') as HTMLTextAreaElement).value,
        ).toBe(BODY);
    });

    it('says the lock was lost on a 423, and the key problem on a 503', async () => {
        storeReply = () => ({
            status: 423,
            json: { error: { code: 'platform.edit_lock_lost' } },
        });
        await mountPage();
        await openPaste();
        await paste(BODY);
        await click($('[data-test=personal-data]'));
        await click($('[data-test=use-sample]'));
        expect(text('[data-test=sample-problem]')).toContain(
            'no longer hold the edit lock',
        );

        storeReply = () => ({
            status: 503,
            json: { error: { code: 'blocks.sample_unavailable' } },
        });
        await click($('[data-test=use-sample]'));
        expect(text('[data-test=sample-problem]')).toContain(
            'cannot be saved or opened',
        );
    });

    it('blocks accepting while offline, with the reason', async () => {
        await mountPage();
        await openPaste();
        await paste(BODY);
        await click($('[data-test=personal-data]'));
        setConnectionForTest(false);
        await flushPromises();

        expect(blocked('[data-test=use-sample]')).toBe(true);
    });

    it('never puts the sample in sessionStorage or the Draft save', async () => {
        await mountPage();
        await openPaste();
        await paste(BODY.replace('USD', CANARY));
        await click($('[data-test=personal-data]'));
        await click($('[data-test=use-sample]'));
        await click($('[data-test=save-draft]'));

        const kept = Array.from({ length: sessionStorage.length }, (_, i) =>
            sessionStorage.getItem(sessionStorage.key(i) ?? ''),
        ).join('');

        expect(kept).not.toContain(CANARY);
        expect(
            calls
                .filter((c) => c.method === 'PUT' && !/sample/.test(c.url))
                .map((c) => JSON.stringify(c.body))
                .join(''),
        ).not.toContain(CANARY);
    });
});

describe('Sample Response: the structure of an accepted sample', () => {
    beforeEach(() => {
        restoreReply = () => ({ status: 200, json: { data: stored() } });
    });

    it('restores the saved sample on reopen: Paste mode, the text, the summary and the badge', async () => {
        await mountPage();

        expect(
            $('[data-test=sample-mode] [aria-checked=true]')?.textContent,
        ).toContain('Paste sample JSON');
        expect(
            ($('[data-test=json-textarea]') as HTMLTextAreaElement).value,
        ).toBe(BODY);
        expect(text('[data-test=sample-summary]')).toContain('bytes');
        expect(text('[data-test=sample-badge]')).toBe(
            'Sample data · used for mapping and preview only',
        );
        expect(sampleCalls('GET')).toHaveLength(1);
        // The accepted text needs no accepting again.
        expect($('[data-test=accept]')).toBeNull();
    });

    it("opens a collapsible tree showing each node's display path, type and example, with every digit", async () => {
        await mountPage();
        await click($('[data-test=view-json]'));

        const rows = $$('[data-test=tree-node]');
        const revenue = rows.find(
            (r) => r.getAttribute('data-path') === '$.data.monthly[*].revenue',
        )!;

        expect(rows).toHaveLength(8);
        expect(
            revenue.querySelector('[data-test=node-path]')?.textContent,
        ).toBe('data.monthly[].revenue');
        expect(
            revenue
                .querySelector('[data-test=node-path]')
                ?.getAttribute('title'),
        ).toBe('$.data.monthly[*].revenue');
        expect(
            revenue.querySelector('[data-test=node-type]')?.textContent,
        ).toBe('integer | decimal');
        expect(
            revenue.querySelector('[data-test=node-example]')?.textContent,
        ).toBe('12345678901234567890.10');
        expect(
            rows
                .find(
                    (r) =>
                        r.getAttribute('data-path') ===
                        '$.data.monthly[*].rate',
                )
                ?.querySelector('[data-test=node-example]')?.textContent,
        ).toBe('1.0');
        expect(
            rows
                .find((r) => r.getAttribute('data-path') === '$.data.monthly')
                ?.querySelector('[data-test=node-count]')?.textContent,
        ).toBe('3 items');

        const toggle = rows
            .find((r) => r.getAttribute('data-path') === '$.data.monthly')!
            .querySelector('[data-test=tree-toggle]') as HTMLElement;

        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        await click(toggle);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(
            $$('[data-test=tree-node]').map((r) => r.getAttribute('data-path')),
        ).toEqual(['$', '$.data', '$.data.monthly', '$.data.currency']);
        await click(toggle);
        expect($$('[data-test=tree-node]')).toHaveLength(8);
    });

    it('offers a table view of the records, with each cell as received', async () => {
        await mountPage();
        await click($('[data-test=view-json]'));

        const table = $$('[data-test=structure-view] [role=radio]').find(
            (r) => r.textContent?.trim() === 'Table',
        )!;

        await click(table);

        expect($$('thead th').map((th) => th.textContent?.trim())).toEqual([
            'data.monthly[].month',
            'data.monthly[].revenue',
        ]);
        expect(
            $$('[data-test=table-row]').map((row) =>
                Array.from(row.querySelectorAll('td')).map((td) =>
                    td.textContent?.trim(),
                ),
            ),
        ).toEqual([
            ['Jan', '12345678901234567890.10'],
            ['Feb', '2.50'],
            ['Mar', '3'],
        ]);
        expect($('[data-test=table-truncated]')).toBeNull();
    });

    it('shows "Pasted 09:14" in the step summary of the Data Source step', async () => {
        await mountPage();

        expect(document.body.textContent).toMatch(/Pasted \d\d:\d\d/);
    });

    it('offers to retry when the saved sample cannot be loaded, and keeps working without it', async () => {
        restoreReply = () => ({ status: 500, json: { error: { code: 'x' } } });
        await mountPage();

        expect($('[data-test=restore-failed]')).not.toBeNull();

        restoreReply = () => ({ status: 200, json: { data: stored() } });
        await click($('[data-test=restore-retry]'));
        expect($('[data-test=restore-failed]')).toBeNull();
        expect($('[data-test=sample-badge]')).not.toBeNull();
    });
});

describe('Sample helpers', () => {
    it('places the caret at a 1-based line and column, in characters', () => {
        const body = 'ab\n😀x\n\nlast';

        expect(caretAt(body, 1, 1)).toBe(0);
        expect(caretAt(body, 1, 3)).toBe(2);
        expect(caretAt(body, 2, 1)).toBe(3);
        expect(caretAt(body, 2, 2)).toBe(5);
        expect(caretAt(body, 2, 3)).toBe(6);
        expect(caretAt(body, 3, 1)).toBe(7);
        expect(caretAt(body, 4, 5)).toBe(body.length);
        expect(caretAt(body, 99, 1)).toBe(8);
        expect(caretAt(body, 1, 99)).toBe(2);
    });

    it('words the shape, and hides the children of a collapsed node', () => {
        expect(
            shapeText({ records: null, scalars: 4, arrays: 1, objects: 0 }),
        ).toBe('4 scalars, 1 array');
        expect(
            shapeText({ records: null, scalars: 1, arrays: 2, objects: 0 }),
        ).toBe('1 scalar, 2 arrays');
        expect(visibleNodes(nodes(), new Set([1])).map((n) => n.id)).toEqual([
            0, 1,
        ]);
        expect(visibleNodes(nodes(), new Set([2])).map((n) => n.id)).toEqual([
            0, 1, 2, 7,
        ]);
    });

    it('has the catalogue messages the section uses', () => {
        expect(en['json-valid']).toContain('{count} records found at {path}');
        expect(en['json-invalid']).toContain('Go to line {line}');
        expect(en['sample-wizard']).toBe(
            'Sample data · used for mapping and preview only',
        );
    });
});
