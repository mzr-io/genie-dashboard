// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    emptySource,
    intervalText,
    resolveRange,
    sourceConfigOf,
} from '../../resources/js/lib/blockSource';
import type { EndpointOption } from '../../resources/js/lib/blockSource';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';

// Story 3.7: wizard Step 2 (Data Source, Endpoint, parameters, refresh and default date range): every Given/When/Then of the story.
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

const SOURCE_A = 'a0000000-0000-7000-8000-000000000001';
const SOURCE_B = 'b0000000-0000-7000-8000-000000000002';
const EP_GET = 'e0000000-0000-7000-8000-000000000001';
const EP_POST = 'e0000000-0000-7000-8000-000000000002';
const EP_USER = 'e0000000-0000-7000-8000-000000000003';
const EP_PATH = 'e0000000-0000-7000-8000-000000000004';

const SOURCES = [
    {
        data_source_id: SOURCE_A,
        name: 'Finance API',
        health: 'healthy',
        live_capable: false,
        revision: 3,
    },
    {
        data_source_id: SOURCE_B,
        name: 'Ops API',
        health: 'degraded',
        live_capable: true,
        revision: 1,
    },
];

function endpoint(overrides: Partial<EndpointOption>): EndpointOption {
    return {
        endpoint_id: EP_GET,
        data_source_id: SOURCE_A,
        method: 'GET',
        path: '/api/v2/finance/revenue',
        params: [],
        read_only_query: false,
        requires_user_context: false,
        revision: 2,
        revision_id: 'r0000000-0000-7000-8000-000000000001',
        ...overrides,
    };
}

const ENDPOINTS: Record<string, EndpointOption[]> = {
    [SOURCE_A]: [
        endpoint({
            params: [
                {
                    name: 'from',
                    binding: 'date_range_from',
                    value: null,
                    kind: 'query',
                },
                {
                    name: 'to',
                    binding: 'date_range_to',
                    value: null,
                    kind: 'query',
                },
                {
                    name: 'region',
                    binding: 'fixed',
                    value: 'emea',
                    kind: 'query',
                },
                {
                    name: 'start',
                    binding: 'period_start',
                    value: null,
                    kind: 'query',
                },
            ],
        }),
        endpoint({
            endpoint_id: EP_POST,
            method: 'POST',
            path: '/api/v2/reports',
            read_only_query: true,
        }),
        endpoint({
            endpoint_id: EP_USER,
            path: '/api/v2/me',
            requires_user_context: true,
            params: [
                { name: 'uid', binding: 'user_id', value: null, kind: 'query' },
            ],
        }),
        endpoint({
            endpoint_id: EP_PATH,
            path: '/api/v2/accounts/{account}',
            params: [
                { name: 'account', binding: 'fixed', value: '', kind: 'path' },
            ],
        }),
    ],
    [SOURCE_B]: [
        endpoint({
            endpoint_id: 'e0000000-0000-7000-8000-000000000009',
            data_source_id: SOURCE_B,
            path: '/ops',
        }),
    ],
};

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
    };
}

type Call = { url: string; method: string; body: Record<string, any> | null };
const calls: Call[] = [];
let sources: unknown = SOURCES;
let intervals: unknown = { intervals: [60, 300, 900, 3600, 86400] };
let current: WizardDraft = draft();
let wrapper: VueWrapper | null = null;
const ok = (json: unknown) => ({
    ok: true,
    status: 200,
    json: async () => json,
});

function withShell(can: Record<string, boolean>): void {
    page.props = { shell: { area: 'admin', items: [], can } };
}

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
const puts = () => calls.filter((c) => c.method === 'PUT');

async function choose(selector: string, value: string): Promise<void> {
    const select = $(selector) as HTMLSelectElement;

    select.value = value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
}

async function typeInto(selector: string, value: string): Promise<void> {
    const input = $(selector) as HTMLInputElement;

    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await flushPromises();
}

const fetchReason = () =>
    $('[data-test=fetch]')?.parentElement?.textContent ?? '';
const blocked = (selector: string) =>
    $(selector)?.getAttribute('aria-disabled') === 'true';

beforeEach(() => {
    setActivePinia(createPinia());
    withShell({
        'blocks.edit': true,
        'blocks.publish': true,
        'data_sources.manage': true,
    });
    calls.length = 0;
    sources = SOURCES;
    intervals = { intervals: [60, 300, 900, 3600, 86400] };
    current = draft();
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                if (/\/lock(\/|$)/.test(url)) {
                    return ok({ data: { enabled: false } });
                }

                const method = init?.method ?? 'GET';

                calls.push({
                    url,
                    method,
                    body: init?.body ? JSON.parse(init.body) : null,
                });

                if (url === '/api/v1/admin/blocks/categories') {
                    return ok({
                        data: [
                            {
                                id: 'c-general',
                                name: 'General',
                                archived: false,
                            },
                        ],
                    });
                }

                if (url === '/api/v1/admin/blocks/data-sources') {
                    return ok({ data: sources });
                }

                if (url === '/api/v1/admin/blocks/refresh-intervals') {
                    return ok({ data: intervals });
                }

                const list = url.match(
                    /\/blocks\/data-sources\/([^/]+)\/endpoints$/,
                );

                if (list) {
                    return ok({ data: ENDPOINTS[list[1]] ?? [] });
                }

                if (method === 'PUT') {
                    const body = JSON.parse(init!.body!);
                    // The server pins the revisions itself.
                    const source = body.config.source
                        ? {
                              ...body.config.source,
                              ...(body.config.source.data_source_id
                                  ? { data_source_revision: 3 }
                                  : {}),
                              ...(body.config.source.endpoint_id
                                  ? {
                                        endpoint_revision_id:
                                            'r0000000-0000-7000-8000-000000000001',
                                    }
                                  : {}),
                          }
                        : undefined;

                    current = draft({
                        revision: current.revision + 1,
                        step: body.step,
                        config: {
                            ...body.config,
                            ...(source ? { source } : {}),
                        },
                    });
                    return ok({ data: current });
                }

                return ok({ data: current });
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

describe('Step 2: the Data Source and the Endpoint', () => {
    it('lists each Data Source with its health word, the chosen one with a dot, and Endpoints as METHOD /path', async () => {
        await mountPage();

        expect($('[data-test=step-2]')).not.toBeNull();
        expect(
            $$('[data-test=data-source] option').map((o) =>
                o.textContent?.trim(),
            ),
        ).toEqual([
            'Choose a data source',
            'Finance API (Healthy)',
            'Ops API (Degraded)',
        ]);

        await choose('[data-test=data-source]', SOURCE_A);

        expect($('[data-test=health-word]')?.textContent).toBe('Healthy');
        expect($('[data-slot=status-dot]')).not.toBeNull();
        expect(
            $$('[data-test=endpoint] option').map((o) => o.textContent?.trim()),
        ).toEqual([
            'Choose an endpoint',
            'GET /api/v2/finance/revenue',
            'POST /api/v2/reports',
            'GET /api/v2/me',
            'GET /api/v2/accounts/{account}',
        ]);
    });

    it('shows Edit data source to an Admin with data_sources.manage, in a new tab, and to nobody else', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);

        const link = $('[data-test=edit-source]') as HTMLAnchorElement;

        expect(link.textContent?.trim()).toBe('Edit data source');
        expect(link.getAttribute('href')).toBe(
            `/admin/data-sources/${SOURCE_A}/edit`,
        );
        expect(link.getAttribute('target')).toBe('_blank');

        wrapper?.unmount();
        document.body.innerHTML = '';
        withShell({ 'blocks.edit': true });
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);

        expect($('[data-test=edit-source]')).toBeNull();
    });

    it('a degraded Data Source can be chosen', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_B);

        expect(($('[data-test=data-source]') as HTMLSelectElement).value).toBe(
            SOURCE_B,
        );
        expect($('[data-test=health-word]')?.textContent).toBe('Degraded');
    });
});

describe('Step 2: POST and read-only', () => {
    it('GET is the default: no checkbox, and Fetch and Continue are open', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);
        await choose('[data-test=endpoint]', EP_GET);

        expect($('[data-test=post-readonly]')).toBeNull();
        expect(fetchReason()).toContain('built in a later update');
        expect(blocked('[data-test=continue]')).toBe(false);
    });

    it('requires the read-only checkbox for a POST: Fetch and Continue stay aria-disabled with the reason until it is ticked', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);
        await choose('[data-test=endpoint]', EP_POST);

        expect(
            $('[data-test=post-readonly]')?.getAttribute('aria-checked'),
        ).toBe('false');
        expect(en['post-readonly'].label).toBe(
            'This POST is a read-only query',
        );
        expect(blocked('[data-test=fetch]')).toBe(true);
        expect(blocked('[data-test=continue]')).toBe(true);
        expect($('[data-test=continue-reason]')?.textContent).toContain(
            'read-only query',
        );

        const before = calls.length;

        await click($('[data-test=continue]'));
        expect(puts()).toHaveLength(0);
        expect(calls).toHaveLength(before);

        await click($('[data-test=post-readonly]'));

        expect(fetchReason()).toContain('built in a later update');
        expect(blocked('[data-test=continue]')).toBe(false);
    });
});

describe('Step 2: the parameters table', () => {
    async function pick(
        endpointId: string,
        display: Record<string, unknown> = {},
    ) {
        current = draft({
            config: {
                schema_version: 1,
                description: 'Daily revenue',
                display,
            } as never,
        });
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);
        await choose('[data-test=endpoint]', endpointId);
    }

    it('shows Name in mono, the binding and the Value, with bound rows resolved in mono muted text and a Fixed row editable', async () => {
        await pick(EP_GET, { period: 'own_selector' });
        await choose('[data-test=date-range]', 'custom');
        await typeInto('[data-test=range-from]', '2026-01-01');
        await typeInto('[data-test=range-to]', '2026-03-31');

        const rows = $$('[data-test=param-row]');

        expect(rows).toHaveLength(4);
        expect(
            rows.map(
                (r) => r.querySelector('[data-test=param-name]')?.textContent,
            ),
        ).toEqual(['from', 'to', 'region', 'start']);
        expect(
            rows[0].querySelector('[data-test=param-name]')?.className,
        ).toContain('font-mono');
        expect(
            rows[0]
                .querySelector('[data-test=param-binding]')
                ?.textContent?.trim(),
        ).toBe('Date range: from');
        expect(
            rows[0].querySelector('[data-test=resolved]')?.textContent?.trim(),
        ).toBe('from = 2026-01-01');
        expect(
            rows[0].querySelector('[data-test=resolved]')?.className,
        ).toContain('font-mono');
        expect(
            rows[0].querySelector('[data-test=resolved]')?.className,
        ).toContain('text-text-muted');
        expect(
            rows[1].querySelector('[data-test=resolved]')?.textContent?.trim(),
        ).toBe('to = 2026-03-31');
        expect(
            rows[2].querySelector<HTMLInputElement>('[data-test=param-value]')
                ?.value,
        ).toBe('emea');
        // Own selector: the Block Period Selector row shows its binding and what it resolves to.
        expect(
            rows[3]
                .querySelector('[data-test=param-binding]')
                ?.textContent?.trim(),
        ).toBe('Block period: start');
        expect(
            rows[3].querySelector('[data-test=resolved]')?.textContent?.trim(),
        ).toBe('start = 2026-01-01');
    });

    it('shows a Block Period Selector row only when period behavior is Own selector', async () => {
        await pick(EP_GET, { period: 'follow_dashboard' });

        const row = $$('[data-test=param-row]')[3];

        expect(
            row.querySelector('[data-test=param-binding]')?.textContent,
        ).toContain('Own selector');
        expect(row.textContent).not.toContain('Block period: start');
    });

    it('marks a User context row with the chip, never a value, and no input', async () => {
        await pick(EP_USER);

        const row = $('[data-test=param-row]')!;

        expect(
            row
                .querySelector('[data-test=user-context-tag]')
                ?.textContent?.trim(),
        ).toBe('user context');
        expect(
            row.querySelector('[data-test=resolved]')?.textContent?.trim(),
        ).toBe('user context');
        expect(row.querySelector('input')).toBeNull();
        expect($('[data-test=shared-data]')).toBeNull();
    });

    it('blocks Fetch for an empty path parameter and names it, until a value is typed', async () => {
        await pick(EP_PATH);

        expect(blocked('[data-test=fetch]')).toBe(true);
        expect($('[data-test=fetch]')?.parentElement?.textContent).toContain(
            'Enter a value for the path parameter account.',
        );

        await typeInto('[data-test=param-value]', '42');

        expect(fetchReason()).toContain('built in a later update');
    });

    it('Fetch is rendered and does nothing yet', async () => {
        await pick(EP_GET);

        const before = calls.length;

        await click($('[data-test=fetch]'));
        expect(calls).toHaveLength(before);
    });
});

describe('Step 2: the "shared data" badge', () => {
    it('shows when no binding is user context, and not otherwise', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);
        await choose('[data-test=endpoint]', EP_GET);

        expect($('[data-test=shared-data]')?.textContent?.trim()).toBe(
            'shared data',
        );

        await choose('[data-test=endpoint]', EP_USER);
        expect($('[data-test=shared-data]')).toBeNull();
    });
});

describe('Step 2: refresh interval and default date range', () => {
    it('lists the System-settings intervals, Live disabled with live-not-supported when the flag is off, and the date range presets', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);

        const options = $$('[data-test=refresh] option').map((o) =>
            o.textContent?.trim(),
        );

        expect(options).toEqual([
            'Choose how often to refresh',
            'Live',
            '1 minute',
            '5 minutes',
            '15 minutes',
            '60 minutes',
            'Daily',
        ]);
        expect(
            ($('[data-test=refresh-live]') as HTMLOptionElement).disabled,
        ).toBe(true);
        expect($('[data-test=live-not-supported]')?.textContent).toBe(
            "Live isn't available: Finance API isn't marked as supporting Live refresh.",
        );
        expect(
            $$('[data-test=date-range] option').map((o) =>
                o.textContent?.trim(),
            ),
        ).toEqual([
            'Choose a default date range',
            'Today',
            'This week',
            'This month',
            'Current year',
            'Custom',
        ]);
    });

    it('enables Live for a Live-capable Data Source', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_B);

        expect(
            ($('[data-test=refresh-live]') as HTMLOptionElement).disabled,
        ).toBe(false);
        expect($('[data-test=live-not-supported]')).toBeNull();
    });

    it('offers no interval and says why when the setting is unset, and preselects nothing', async () => {
        intervals = { intervals: null };
        await mountPage();

        expect($('[data-test=refresh-none]')?.textContent).toContain(
            'No refresh intervals are set up',
        );
        expect(
            $$('[data-test=refresh] option').map((o) => o.textContent?.trim()),
        ).toEqual(['Choose how often to refresh', 'Live']);
        expect(($('[data-test=refresh]') as HTMLSelectElement).value).toBe('');
        expect(($('[data-test=date-range]') as HTMLSelectElement).value).toBe(
            '',
        );
    });

    it('takes a custom date range as two date inputs and refuses a start after the end', async () => {
        await mountPage();
        await choose('[data-test=date-range]', 'custom');
        await typeInto('[data-test=range-from]', '2026-03-01');
        await typeInto('[data-test=range-to]', '2026-02-01');

        expect(
            $('[data-test=custom-range] [data-slot=field-error]')?.textContent,
        ).toContain('start date cannot be after');

        await click($('[data-test=save-draft]'));
        expect(puts()).toHaveLength(0);

        await typeInto('[data-test=range-to]', '2026-04-01');
        await click($('[data-test=save-draft]'));

        expect(puts()[0].body?.config.source.default_date_range).toEqual({
            preset: 'custom',
            from: '2026-03-01',
            to: '2026-04-01',
        });
    });

    it('words the intervals and dates a preset', () => {
        expect(
            [60, 300, 3600, 86400, 172800, 45, 90].map(intervalText),
        ).toEqual([
            '1 minute',
            '5 minutes',
            '60 minutes',
            'Daily',
            'Every 2 days',
            '45 seconds',
            '90 seconds',
        ]);

        const today = new Date(2026, 9, 9);

        expect(
            resolveRange({ preset: 'today', from: '', to: '' }, today),
        ).toEqual({ from: '2026-10-09', to: '2026-10-09' });
        expect(
            resolveRange({ preset: 'this_week', from: '', to: '' }, today),
        ).toEqual({ from: '2026-10-05', to: '2026-10-11' });
        expect(
            resolveRange({ preset: 'this_month', from: '', to: '' }, today),
        ).toEqual({ from: '2026-10-01', to: '2026-10-31' });
        expect(
            resolveRange({ preset: 'current_year', from: '', to: '' }, today),
        ).toEqual({ from: '2026-01-01', to: '2026-12-31' });
        expect(
            resolveRange({ preset: '', from: '', to: '' }, today),
        ).toBeNull();
    });
});

describe('Step 2: a failed load', () => {
    it('says so with a retry, never an empty list, and keeps Continue open', async () => {
        sources = 'broken';
        await mountPage();

        expect($('[data-test=sources-failed]')?.textContent).toContain(
            "We couldn't load the data sources.",
        );
        expect($('[data-test=datasource-none]')).toBeNull();

        sources = SOURCES;
        await click($('[data-test=sources-retry]'));

        expect($('[data-test=sources-failed]')).toBeNull();
        expect($$('[data-test=data-source] option')).toHaveLength(3);
    });
});

describe('Step 2: no Data Source', () => {
    it('replaces the select with datasource-none and offers Register in a new tab with data_sources.manage; Fetch and Continue say why', async () => {
        sources = [];
        await mountPage();

        expect($('[data-test=data-source]')).toBeNull();
        expect($('[data-test=datasource-none]')?.textContent).toContain(
            'No data sources are registered yet.',
        );

        const link = $('[data-test=register-source]') as HTMLAnchorElement;

        expect(link.textContent?.trim()).toBe('+ Register data source');
        expect(link.getAttribute('href')).toBe('/admin/data-sources/create');
        expect(link.getAttribute('target')).toBe('_blank');
        expect(blocked('[data-test=fetch]')).toBe(true);
        expect(blocked('[data-test=continue]')).toBe(true);
        expect($('[data-test=continue-reason]')?.textContent).toContain(
            'No data sources are registered yet.',
        );
    });

    it('shows the ask-an-admin text without data_sources.manage', async () => {
        sources = [];
        withShell({ 'blocks.edit': true });
        await mountPage();

        expect($('[data-test=register-source]')).toBeNull();
        expect($('[data-test=ask-admin]')?.textContent).toContain(
            'Ask an admin who manages data sources',
        );
    });
});

describe('Step 2: saving, pins and out-of-date', () => {
    it('saves the selection with the sample marked out of date, never sending a pin, and shows the pin the server recorded', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);
        await choose('[data-test=endpoint]', EP_GET);
        await typeInto('[data-test=param-value]', 'apac');
        await choose('[data-test=refresh]', '300');
        await choose('[data-test=date-range]', 'this_month');

        expect($('[data-test=out-of-date]')?.textContent).toContain('changed');

        await click($('[data-test=save-draft]'));

        const source = puts()[0].body?.config.source;

        expect(source).toEqual({
            data_source_id: SOURCE_A,
            endpoint_id: EP_GET,
            bindings: { region: 'apac' },
            refresh_interval: 300,
            default_date_range: { preset: 'this_month' },
            sample_out_of_date: true,
        });
        expect(JSON.stringify(puts()[0].body)).not.toMatch(
            /revision_id|data_source_revision/,
        );
        expect($('[data-test=pinned]')?.textContent).toContain('revision 3');
    });

    it("drops a value equal to the Endpoint's own and marks any request change out of date again", async () => {
        current = draft({
            config: {
                schema_version: 1,
                source: {
                    data_source_id: SOURCE_A,
                    endpoint_id: EP_GET,
                    bindings: { region: 'apac' },
                    sample_out_of_date: false,
                    data_source_revision: 3,
                    endpoint_revision_id: 'r1',
                },
            } as never,
        });
        await mountPage();

        expect($('[data-test=out-of-date]')).toBeNull();
        expect(($('[data-test=param-value]') as HTMLInputElement).value).toBe(
            'apac',
        );

        await typeInto('[data-test=param-value]', 'emea');
        expect($('[data-test=out-of-date]')).not.toBeNull();

        await click($('[data-test=save-draft]'));
        expect(puts()[0].body?.config.source.bindings).toEqual({});
        expect(puts()[0].body?.config.source.sample_out_of_date).toBe(true);
    });

    it('does not mark the sample out of date for a refresh interval change', async () => {
        current = draft({
            config: {
                schema_version: 1,
                source: { data_source_id: SOURCE_A, sample_out_of_date: false },
            } as never,
        });
        await mountPage();
        await choose('[data-test=refresh]', '900');

        expect($('[data-test=out-of-date]')).toBeNull();
    });

    it('clears the endpoint and parameter values when another Data Source is chosen', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);
        await choose('[data-test=endpoint]', EP_GET);
        await typeInto('[data-test=param-value]', 'apac');
        await choose('[data-test=data-source]', SOURCE_B);

        expect(($('[data-test=endpoint]') as HTMLSelectElement).value).toBe('');
        expect(
            $$('[data-test=endpoint] option').map((o) => o.textContent?.trim()),
        ).toContain('GET /ops');

        await click($('[data-test=save-draft]'));
        expect(puts()[0].body?.config.source).toMatchObject({
            data_source_id: SOURCE_B,
            endpoint_id: null,
            bindings: {},
        });
    });

    it('Continue saves Step 2 and moves to Step 3 with the settings in the Draft', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);
        await choose('[data-test=endpoint]', EP_GET);
        await click($('[data-test=continue]'));

        expect(puts()[0].body).toMatchObject({
            step: 3,
            config: {
                source: { data_source_id: SOURCE_A, endpoint_id: EP_GET },
            },
        });
        expect($('li[data-step="3"]')?.getAttribute('aria-current')).toBe(
            'step',
        );
    });

    it('sends no source key while Step 2 was never touched, so Step 1 saves exactly as before', async () => {
        current = draft({ step: 1 });
        await mountPage('?draft=d-1&step=1');
        await click($('[data-test=save-draft]'));

        expect(puts()[0].body?.config).not.toHaveProperty('source');
    });

    it('builds the stored shape without a pin or the acknowledgement', () => {
        const form = emptySource();

        form.dataSourceId = SOURCE_A;
        form.endpointRevisionId = 'r1';
        form.dataSourceRevision = 9;
        form.readOnlyAck = true;

        expect(sourceConfigOf(form)).toEqual({
            data_source_id: SOURCE_A,
            endpoint_id: null,
            bindings: {},
            refresh_interval: null,
            sample_out_of_date: false,
        });
    });
});

describe('Step 2: Fetch, Live and server errors', () => {
    it('keeps Fetch aria-disabled with the later-update reason even when nothing else blocks it', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);
        await choose('[data-test=endpoint]', EP_GET);

        expect(blocked('[data-test=fetch]')).toBe(true);
        expect(fetchReason()).toContain('built in a later update');
    });

    it('resets a Live choice to unset when another, non-Live source is chosen, so the save sends null', async () => {
        await mountPage();
        await choose('[data-test=data-source]', SOURCE_B);
        await choose('[data-test=refresh]', 'live');
        await choose('[data-test=data-source]', SOURCE_A);

        expect(($('[data-test=refresh]') as HTMLSelectElement).value).toBe('');

        await click($('[data-test=save-draft]'));
        expect(puts()[0].body?.config.source.refresh_interval).toBeNull();
    });

    it('resets a stored Live on a source that does not support it once the lists load', async () => {
        current = draft({
            config: {
                schema_version: 1,
                source: { data_source_id: SOURCE_A, refresh_interval: 'live' },
            } as never,
        });
        await mountPage();

        expect(($('[data-test=refresh]') as HTMLSelectElement).value).toBe('');
    });

    it('keeps a stored interval that the settings no longer list as the selected value', async () => {
        current = draft({
            config: {
                schema_version: 1,
                source: { data_source_id: SOURCE_A, refresh_interval: 120 },
            } as never,
        });
        await mountPage();

        expect(($('[data-test=refresh]') as HTMLSelectElement).value).toBe(
            '120',
        );
        expect(
            $$('[data-test=refresh] option').map((o) => o.textContent?.trim()),
        ).toContain('2 minutes');
    });

    it('shows a server error for Step 2 beside its control', async () => {
        const base = globalThis.fetch;

        await mountPage();
        await choose('[data-test=data-source]', SOURCE_A);
        await choose('[data-test=endpoint]', EP_GET);

        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string, init?: { method?: string }) =>
                init?.method === 'PUT'
                    ? {
                          ok: false,
                          status: 422,
                          json: async () => ({
                              errors: {
                                  'config.source.endpoint_id': [
                                      'Choose an endpoint of the data source.',
                                  ],
                                  'config.source.bindings.region': [
                                      'Only a fixed parameter holds a value here.',
                                  ],
                              },
                          }),
                      }
                    : (base as typeof fetch)(url),
            ),
        );
        await click($('[data-test=save-draft]'));

        expect(
            $('[data-test=endpoint]')?.closest('[data-slot=form-field]')
                ?.textContent,
        ).toContain('Choose an endpoint of the data source.');
        expect($('[data-test=param-error]')?.textContent).toContain(
            'Only a fixed parameter',
        );
    });
});
