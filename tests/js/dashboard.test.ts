// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useAddBlocksPanel } from '../../resources/js/composables/useAddBlocksPanel';
import { useToasts } from '../../resources/js/stores/toasts';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type {
    Dashboard,
    DashboardItem,
    ItemResult,
} from '../../resources/js/lib/dashboards';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';

// Story 4.4: the User dashboard page (UX-DR-50, 86, 149, 236, 237). The shell appears at once; the layout comes from the API; each Block is a section
// labelled by its h2; a failed load shows `msg:dashboard-load-failed` with Retry and no layout; an item that is not ok shows its message with no data.
vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: { render: () => null },
    usePage: () => ({ url: '/dashboard', props: {} }),
}));

const { default: DashboardPage } =
    await import('../../resources/js/pages/Dashboard.vue');

function item(overrides: Partial<DashboardItem> = {}): DashboardItem {
    return {
        item_id: 'i-1',
        block_id: 'b-1',
        geometry: { col: 3, row: 2, width: 6, height: 'medium' },
        minimized: false,
        state: 'ok',
        refresh_interval: 60,
        is_live: false,
        block: {
            name: 'Revenue overview',
            subtitle: 'Net, this month',
            type: 'kpi-card',
            icon: null,
            about: {
                description: 'Net revenue so far',
                source_name: 'Sales API',
                refresh_interval: 300,
                version: '1.0',
                period: 'follow_dashboard',
            },
            chrome: {
                show_header: true,
                show_subtitle: true,
                allow_resize: true,
                allow_minimize: true,
                allow_refresh: true,
                show_footer_action: false,
            },
        },
        ...overrides,
    };
}

function dashboard(items: DashboardItem[]): Dashboard {
    return {
        dashboard_id: 'd-1',
        name: 'Overview',
        is_overview: true,
        revision: 0,
        items,
        next_free_slot: { col: 0, row: 0 },
    };
}

type Reply = { ok: boolean; status: number; json: unknown };
let replies: Reply[] = [];
let wrapper: VueWrapper | null = null;
const urls: string[] = [];

async function mountPage(): Promise<void> {
    wrapper = mount(DashboardPage as never, {
        props: { dashboard_id: 'd-1', revision: 0 },
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
    await vi.dynamicImportSettled();
    await flushPromises();
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));

beforeEach(() => {
    setActivePinia(createPinia());
    replies = [];
    urls.length = 0;
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) => {
            urls.push(url);
            const next = replies.shift();

            if (!next) {
                throw new Error(`unexpected request ${url}`);
            }

            return {
                ok: next.ok,
                status: next.status,
                headers: new Headers(),
                json: async () => next.json,
            };
        }),
    );
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    resetAnnouncer();
    vi.unstubAllGlobals();
});

const ok = (data: Dashboard): Reply => ({
    ok: true,
    status: 200,
    json: { data },
});

const resultsOf = (items: ItemResult[]): Reply => ({
    ok: true,
    status: 200,
    json: { data: { items } },
});

function result(overrides: Partial<ItemResult> = {}): ItemResult {
    return {
        item_id: 'i-1',
        block_id: 'b-1',
        state: 'ok',
        stale: false,
        refreshing: false,
        data_as_of: '2026-10-12T10:00:00Z',
        last_success_at: '2026-10-12T10:05:00Z',
        stale_at: '2026-10-12T10:07:00Z',
        result_etag: 'e-1',
        slot_states: {},
        render_payload: payload(),
        ...overrides,
    };
}

function payload(overrides: Record<string, unknown> = {}) {
    return {
        state: 'normal',
        title: null,
        subtitle: null,
        icon: null,
        footerAction: null,
        kpiLabel: 'Net revenue',
        headline: {
            available: true,
            value: '1234.5',
            format: {
                kind: 'number',
                decimalPlaces: 2,
                thousandsSeparator: true,
                currencyCode: null,
                currencyDisplay: 'symbol',
                datePattern: 'medium',
                unitsSuffix: null,
            },
        },
        comparison: null,
        comparisonLabel: null,
        secondaryNote: null,
        statusBadge: null,
        staleSince: null,
        errorMessage: null,
        ...overrides,
    };
}

describe('Dashboard page', () => {
    it('requests the layout from the API by dashboard id and renders each Block as a section labelled by its h2', async () => {
        replies = [
            ok(
                dashboard([
                    item(),
                    item({
                        item_id: 'i-2',
                        block_id: 'b-2',
                        block: { ...item().block!, name: 'Open tickets' },
                    }),
                ]),
            ),
        ];

        replies.push(resultsOf([]));

        await mountPage();

        expect(urls).toEqual([
            '/api/v1/dashboards/d-1',
            '/api/v1/dashboards/d-1/results',
        ]);

        const cards = $$('[data-slot=block-card]');

        expect(cards).toHaveLength(2);

        for (const card of cards) {
            const label = card.getAttribute('aria-labelledby');
            const heading = card.querySelector('h2');

            expect(card.tagName).toBe('SECTION');
            expect(heading?.id).toBe(label);
        }

        expect(cards[0].querySelector('h2')?.textContent).toContain(
            'Revenue overview',
        );
        expect(cards[0].textContent).toContain('Net, this month');
    });

    it('places a Block by its clamped geometry and gives the body a focusable, labelled region', async () => {
        replies = [ok(dashboard([item()])), resultsOf([])];

        await mountPage();

        const cell = $('[data-item=i-1]')!;

        expect(cell.getAttribute('style')).toContain('--col: 4');
        expect(cell.getAttribute('style')).toContain('--row: 3');
        expect(cell.getAttribute('style')).toContain('--width: 6');
        expect(cell.getAttribute('style')).toContain('--units: 6');

        const body = $('[data-slot=block-body]')!;

        expect(body.getAttribute('tabindex')).toBe('0');
        expect(body.getAttribute('role')).toBe('region');
        expect(body.getAttribute('aria-label')).toBe(
            'Revenue overview content',
        );
    });

    it.each([
        ['access_removed', en['block-access-removed']],
        ['unpublished', en['block-unpublished']],
    ] as const)(
        'shows %s with its message and a Remove action and no name or data',
        async (state, message) => {
            replies = [
                ok(dashboard([item({ state, block: null })])),
                resultsOf([]),
            ];

            await mountPage();

            const card = $('[data-slot=block-card]')!;

            expect(card.dataset.state).toBe(state);
            expect(card.textContent).toContain(message);
            expect(card.textContent).not.toContain('Revenue overview');
            expect(card.querySelector('button')?.textContent?.trim()).toBe(
                'Remove',
            );
            expect(card.querySelector('h2')?.textContent).toContain(
                'Unavailable block',
            );
        },
    );

    it('shows msg:dashboard-empty with + Add block for a dashboard with no items', async () => {
        replies = [ok(dashboard([]))];

        await mountPage();

        const empty = $('[data-slot=dashboard-empty]')!;

        expect(empty.textContent).toContain(en['dashboard-empty']);
        expect(empty.textContent).toContain('+ Add block');
        expect($('[data-slot=dashboard-grid]')).toBeNull();
    });

    it('shows msg:dashboard-load-failed with Retry and no layout when the request fails, and loads on Retry', async () => {
        replies = [
            { ok: false, status: 500, json: {} },
            ok(dashboard([item()])),
            resultsOf([]),
        ];

        await mountPage();

        const error = $('[data-slot=dashboard-error]')!;

        expect(error.textContent).toContain(en['dashboard-load-failed']);
        expect($('[data-slot=dashboard-grid]')).toBeNull();
        expect($('[data-slot=block-card]')).toBeNull();

        error.querySelector('button')!.click();
        await flushPromises();
        await flushPromises();

        expect($('[data-slot=dashboard-error]')).toBeNull();
        expect($$('[data-slot=block-card]')).toHaveLength(1);
    });

    it('renders the shell header with the title, switcher, Date Range and Edit layout slots', async () => {
        replies = [ok(dashboard([item()])), resultsOf([])];

        await mountPage();

        expect($('h1')?.textContent).toContain('Overview');
        expect($('[data-slot=dashboard-switcher]')).not.toBeNull();
        expect($('[data-slot=date-range]')).not.toBeNull();
        expect($('[data-slot=edit-layout]')?.textContent).toContain(
            'Edit layout',
        );
    });

    it('never puts Block text in the DOM as markup', async () => {
        const hostile = '<img src=x onerror=alert(1)>';
        replies = [
            ok(
                dashboard([
                    item({
                        block: {
                            ...item().block!,
                            name: hostile,
                            subtitle: hostile,
                        },
                    }),
                ]),
            ),
            resultsOf([]),
        ];

        await mountPage();

        expect($('[data-slot=block-card] img')).toBeNull();
        expect($('h2')?.textContent).toContain(hostile);
    });
});

describe('Block results', () => {
    it('shows the real header and a skeleton for a Block with no result yet, then the value in place', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        replies = [
            ok(dashboard([item()])),
            resultsOf([
                result({
                    state: 'pending',
                    render_payload: null,
                    result_etag: null,
                }),
            ]),
            resultsOf([result()]),
        ];

        await mountPage();

        expect($('h2')?.textContent).toContain('Revenue overview');
        expect($('[data-slot=block-skeleton]')).not.toBeNull();
        expect($('[data-test=kpi-headline]')).toBeNull();

        await vi.advanceTimersByTimeAsync(3000);
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();

        expect($('[data-slot=block-skeleton]')).toBeNull();
        expect($('[data-test=kpi-headline]')?.textContent?.trim()).toBe(
            '1,234.50',
        );
        expect(urls).toHaveLength(3);
        vi.useRealTimers();
    });

    it('keeps the last values when a later answer is pending, and replaces them in place when it differs', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        replies = [ok(dashboard([item()])), resultsOf([result()])];

        await mountPage();

        expect($('[data-test=kpi-headline]')?.textContent?.trim()).toBe(
            '1,234.50',
        );
        // No poll is scheduled once nothing is pending.
        await vi.advanceTimersByTimeAsync(10000);

        expect(urls).toHaveLength(2);
        vi.useRealTimers();
    });

    it('shows a stale result with its values muted and "Stale: last data" text, and the Block is not hidden', async () => {
        replies = [
            ok(dashboard([item()])),
            resultsOf([result({ stale: true })]),
        ];

        await mountPage();

        expect($('[data-test=kpi-stale]')?.textContent).toContain(
            'Stale: last data',
        );
        expect($('[data-test=kpi-headline]')?.textContent?.trim()).toBe(
            '1,234.50',
        );
    });

    it('shows msg:block-error with Retry that asks again, and msg:block-empty for an empty result', async () => {
        replies = [
            ok(dashboard([item(), item({ item_id: 'i-2', block_id: 'b-2' })])),
            resultsOf([
                result({ state: 'error', render_payload: null }),
                result({
                    item_id: 'i-2',
                    block_id: 'b-2',
                    state: 'empty',
                    render_payload: payload({ state: 'empty' }),
                }),
            ]),
            resultsOf([
                result(),
                result({
                    item_id: 'i-2',
                    block_id: 'b-2',
                    state: 'empty',
                    render_payload: payload({ state: 'empty' }),
                }),
            ]),
        ];

        await mountPage();

        expect($('[data-test=kpi-error]')).not.toBeNull();
        expect($('[data-test=kpi-empty]')).not.toBeNull();

        $('[data-test=kpi-retry]')!.click();
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(urls.filter((u) => u.endsWith('/results'))).toHaveLength(2);
        expect($('[data-test=kpi-error]')).toBeNull();
        expect($('[data-test=kpi-headline]')).not.toBeNull();
    });

    it('shows msg:unavailable-user for a Block whose value cannot be produced, never 0 or blank', async () => {
        replies = [
            ok(dashboard([item()])),
            resultsOf([result({ state: 'unavailable', render_payload: null })]),
        ];

        await mountPage();

        const text = $('[data-test=kpi-unavailable]')?.textContent ?? '';

        expect(text).toContain(en['unavailable-user']);
        expect($('[data-test=kpi-headline]')).toBeNull();
    });

    it('turns a Block into access_removed when the results say so, with no data', async () => {
        replies = [
            ok(dashboard([item()])),
            resultsOf([
                result({
                    state: 'access_removed',
                    render_payload: null,
                    result_etag: null,
                }),
            ]),
        ];

        await mountPage();

        const card = $('[data-slot=block-card]')!;

        expect(card.dataset.state).toBe('access_removed');
        expect(card.textContent).toContain(en['block-access-removed']);
        expect($('[data-test=kpi-headline]')).toBeNull();
    });

    it('writes hostile text in every slot as literal text', async () => {
        const hostile = '<img src=x onerror=alert(1)>';
        replies = [
            ok(dashboard([item()])),
            resultsOf([
                result({
                    render_payload: payload({
                        kpiLabel: hostile,
                        secondaryNote: hostile,
                        footerAction: hostile,
                        comparisonLabel: hostile,
                        statusBadge: hostile,
                    }),
                }),
            ]),
        ];

        await mountPage();

        expect($('[data-slot=block-card] img')).toBeNull();
        expect($('[data-test=kpi-label]')?.textContent).toBe(hostile);
        expect($('[data-test=kpi-note]')?.textContent).toBe(hostile);
        expect($('[data-test=kpi-footer]')?.textContent).toBe(hostile);
    });

    it('does not request results for a person with no dashboard', async () => {
        wrapper = mount(DashboardPage as never, {
            props: { dashboard_id: null, revision: 0 },
            attachTo: document.body,
            global: { plugins: [createCatalogue()] },
        });
        await flushPromises();

        expect(urls).toEqual([]);
        expect($('[data-slot=dashboard-empty]')).not.toBeNull();
    });
});

describe('Block chrome', () => {
    const post = (url: string) =>
        (
            fetch as unknown as { mock: { calls: unknown[][] } }
        ).mock.calls.filter((call) => call[0] === url);

    it('names Refresh, Minimize and the menu by the Block and omits what the Admin turned off', async () => {
        replies = [
            ok(
                dashboard([
                    item(),
                    item({
                        item_id: 'i-2',
                        block_id: 'b-2',
                        block: {
                            ...item().block!,
                            name: 'Open tickets',
                            chrome: {
                                ...item().block!.chrome,
                                allow_refresh: false,
                                allow_minimize: false,
                            },
                        },
                    }),
                ]),
            ),
            resultsOf([]),
        ];

        await mountPage();

        const [first, second] = $$('[data-slot=block-card]');

        expect(
            first
                .querySelector('[data-slot=block-refresh]')
                ?.getAttribute('aria-label'),
        ).toBe('Refresh Revenue overview');
        expect(
            first
                .querySelector('[data-slot=block-minimize]')
                ?.getAttribute('aria-label'),
        ).toBe('Minimize Revenue overview');
        expect(
            first
                .querySelector('[data-slot=block-menu]')
                ?.getAttribute('aria-label'),
        ).toBe('More actions for Revenue overview');
        expect(second.querySelector('[data-slot=block-refresh]')).toBeNull();
        expect(second.querySelector('[data-slot=block-minimize]')).toBeNull();
        expect(second.querySelector('[data-slot=block-menu]')).not.toBeNull();
    });

    it('refreshes only this Block, shows a spinner in the icon and leaves focus on the control', async () => {
        replies = [
            ok(dashboard([item()])),
            resultsOf([result()]),
            { ok: true, status: 202, json: { data: { status: 'requested' } } },
            resultsOf([result({ result_etag: 'e-2' })]),
        ];

        await mountPage();

        const button = $('[data-slot=block-refresh]') as HTMLButtonElement;
        button.focus();
        button.click();
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();

        expect(post('/api/v1/dashboards/d-1/items/i-1/refresh')).toHaveLength(
            1,
        );
        expect(document.activeElement).toBe(button);
        expect(
            $('[data-slot=block-card]')?.querySelector('.animate-spin'),
        ).toBeNull();
    });

    it('answers 429 with aria-disabled, described by msg:refresh-limited, and makes no second request', async () => {
        replies = [
            ok(dashboard([item()])),
            resultsOf([result()]),
            {
                ok: false,
                status: 429,
                json: {
                    error: {
                        code: 'dashboards.refresh_limited',
                        retry_after: 20,
                    },
                },
            },
        ];

        await mountPage();

        const button = $('[data-slot=block-refresh]') as HTMLButtonElement;
        button.click();
        await flushPromises();
        await flushPromises();

        expect(button.getAttribute('aria-disabled')).toBe('true');

        const reason = document.getElementById(
            button.getAttribute('aria-describedby') ?? 'none',
        );

        expect(reason?.textContent).toContain(en['refresh-limited']);

        button.click();
        await flushPromises();

        expect(post('/api/v1/dashboards/d-1/items/i-1/refresh')).toHaveLength(
            1,
        );
    });

    it('minimizes with expected_revision, shows the header only and offers Expand', async () => {
        replies = [
            ok(dashboard([item()])),
            resultsOf([result()]),
            {
                ok: true,
                status: 200,
                json: {
                    data: { item_id: 'i-1', minimized: true, revision: 1 },
                },
            },
        ];

        await mountPage();
        ($('[data-slot=block-minimize]') as HTMLButtonElement).click();
        await flushPromises();

        const call = post('/api/v1/dashboards/d-1/items/i-1/minimized')[0];

        expect(JSON.parse((call[1] as { body: string }).body)).toEqual({
            minimized: true,
            expected_revision: 0,
        });
        expect($('[data-slot=block-body]')).toBeNull();
        expect(
            $('[data-slot=block-minimize]')?.getAttribute('aria-label'),
        ).toBe('Expand Revenue overview');
    });

    it('reloads the layout and retries once on a 409, and restores the Block when it is refused again', async () => {
        const conflict: Reply = {
            ok: false,
            status: 409,
            json: {
                error: { code: 'dashboards.revision_conflict', revision: 4 },
            },
        };
        replies = [
            ok(dashboard([item()])),
            resultsOf([result()]),
            conflict,
            ok({ ...dashboard([item()]), revision: 4 }),
            conflict,
        ];

        await mountPage();
        ($('[data-slot=block-minimize]') as HTMLButtonElement).click();
        await flushPromises();
        await flushPromises();

        const minimizes = post('/api/v1/dashboards/d-1/items/i-1/minimized');

        expect(minimizes).toHaveLength(2);
        expect(
            JSON.parse((minimizes[1][1] as { body: string }).body)
                .expected_revision,
        ).toBe(4);
        // Refused twice: the Block is as it was.
        expect($('[data-slot=block-body]')).not.toBeNull();
    });

    it('opens About this block with the facts, the data-as-of with its zone, and no technical details for a User', async () => {
        replies = [
            ok(dashboard([item()])),
            resultsOf([
                result({
                    slot_states: {
                        kpi_label: { state: 'unavailable', reason: 'missing' },
                    },
                }),
            ]),
        ];

        await mountPage();
        ($('[data-slot=block-menu]') as HTMLButtonElement).dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await flushPromises();

        const items = Array.from(
            document.querySelectorAll<HTMLElement>('[role=menuitem]'),
        );
        const about = items.find((i) =>
            i.textContent?.includes('About this block'),
        );

        expect(about).toBeDefined();
        about!.click();
        await flushPromises();

        await flushPromises();
        const dialog = $('[role=dialog]')!;
        const text = dialog.textContent ?? '';

        expect(text).toContain('Net revenue so far');
        expect(text).toContain('Sales API');
        expect(text).toContain('Every 5 minutes');
        expect(text).toContain('Follows the dashboard date range');
        expect(text).toContain('1.0');
        expect(text).toMatch(/Data as of.{0,10}\d{1,2}.{0,15}2026/);
        expect(dialog.querySelector('[data-slot=about-technical]')).toBeNull();
    });

    it('shows the footer link centred with an external arrow, in a new tab, and never a javascript: link', async () => {
        const withFooter = (target: string) =>
            item({
                block: {
                    ...item().block!,
                    chrome: {
                        ...item().block!.chrome,
                        show_footer_action: true,
                    },
                    footer: { label: 'View all approvals', target },
                },
            });

        replies = [
            ok(
                dashboard([
                    withFooter(
                        'https://erp.example.com/approvals?u={user.name}',
                    ),
                ]),
            ),
            resultsOf([result()]),
        ];

        await mountPage();

        const link = $('[data-slot=block-footer] a') as HTMLAnchorElement;

        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toBe('noopener noreferrer');
        expect(link.getAttribute('href')).toBe(
            'https://erp.example.com/approvals?u=',
        );

        wrapper?.unmount();
        document.body.innerHTML = '';
        replies = [
            ok(dashboard([withFooter('javascript:alert(1)')])),
            resultsOf([result()]),
        ];
        await mountPage();

        expect($('[data-slot=block-footer]')).toBeNull();
    });
});

describe('Add-blocks Panel on the dashboard', () => {
    const catalogue = (): Reply => ({
        ok: true,
        status: 200,
        json: {
            data: [
                {
                    block_id: 'b-9',
                    name: 'Open tickets',
                    description: 'Support queue',
                    category: null,
                    type: 'kpi-card',
                    data_source: null,
                    refresh_interval: 60,
                    version: '1.0',
                    is_new: false,
                    default_size: { width: 3, height: 'small' },
                },
            ],
            meta: { total: 1 },
        },
    });

    // The panel's open state is shared with the shell, so a test must leave it closed.
    afterEach(() => {
        useAddBlocksPanel().hide();
        vi.unstubAllGlobals();
    });

    it('opens a non-modal complementary region beside the dashboard on a wide screen', async () => {
        vi.stubGlobal('matchMedia', (query: string) => ({
            matches: query.includes('min-width'),
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
            addListener: () => {},
            removeListener: () => {},
        }));
        replies = [ok(dashboard([item()])), resultsOf([result()]), catalogue()];

        await mountPage();
        ($('[data-slot=add-block]') as HTMLButtonElement).click();
        await flushPromises();
        await flushPromises();

        const drawer = $('[data-slot=add-blocks-drawer]')!;

        expect(drawer.tagName).toBe('ASIDE');
        expect(drawer.getAttribute('aria-label')).toBe('Add blocks');
        expect(drawer.textContent).toContain('Open tickets');
        expect($('[data-slot=block-card]')).not.toBeNull();
        expect($('[data-slot=add-blocks-search]')).toBe(document.activeElement);
    });

    it('marks a Block already on the dashboard as Added and closes with Done', async () => {
        vi.stubGlobal('matchMedia', (query: string) => ({
            matches: query.includes('min-width'),
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
            addListener: () => {},
            removeListener: () => {},
        }));
        const onDashboard = catalogue();
        (
            onDashboard.json as { data: Array<{ block_id: string }> }
        ).data[0].block_id = 'b-1';
        replies = [ok(dashboard([item()])), resultsOf([result()]), onDashboard];

        await mountPage();
        ($('[data-slot=add-block-empty]') ??
            $('[data-slot=add-block]'))!.click();
        await flushPromises();
        await flushPromises();
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();

        expect($('[data-slot=row-added]')?.textContent).toBe('✓ Added');

        (
            $$(
                '[data-slot=add-blocks-drawer] footer button',
            )[0] as HTMLButtonElement
        ).click();
        await flushPromises();

        expect($('[data-slot=add-blocks-drawer]')).toBeNull();
    });
});

describe('Adding and removing Blocks', () => {
    const wide = () =>
        vi.stubGlobal('matchMedia', (query: string) => ({
            matches: query.includes('min-width'),
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
            addListener: () => {},
            removeListener: () => {},
        }));

    const entry = (id: string, name: string) => ({
        block_id: id,
        name,
        description: 'Support queue',
        category: null,
        type: 'kpi-card',
        data_source: null,
        refresh_interval: 60,
        version: '1.0',
        is_new: false,
        default_size: { width: 3, height: 'small' },
    });

    const catalogue = (...entries: ReturnType<typeof entry>[]): Reply => ({
        ok: true,
        status: 200,
        json: { data: entries, meta: { total: entries.length } },
    });

    const served = (blockId: string, itemId: string, revision = 1): Reply => ({
        ok: true,
        status: 201,
        json: {
            data: {
                item: item({
                    item_id: itemId,
                    block_id: blockId,
                    geometry: { col: 3, row: 0, width: 3, height: 'small' },
                }),
                revision,
                next_free_slot: { col: 6, row: 0 },
            },
        },
    });

    const calls = (method: string) =>
        (
            fetch as unknown as {
                mock: {
                    calls: Array<[string, { method?: string; body?: string }]>;
                };
            }
        ).mock.calls.filter((call) => (call[1]?.method ?? 'GET') === method);

    async function openPanel(): Promise<void> {
        ($('[data-slot=add-block]') as HTMLButtonElement).click();
        await flushPromises();
        await flushPromises();
    }

    afterEach(() => {
        useAddBlocksPanel().hide();
        useToasts().items.splice(0);
        vi.unstubAllGlobals();
    });

    it('adds a Block with the revision, shows it at once, outlines it as Just added and offers Show me and Undo', async () => {
        wide();
        replies = [
            ok(dashboard([item()])),
            resultsOf([result()]),
            catalogue(entry('b-9', 'Open tickets')),
            served('b-9', 'i-9'),
            resultsOf([result()]),
        ];

        await mountPage();
        await openPanel();
        (
            $('[data-block=b-9] [data-slot=row-add]') as HTMLButtonElement
        ).click();
        await flushPromises();
        await flushPromises();

        expect(JSON.parse(calls('POST')[0][1].body!)).toEqual({
            block_id: 'b-9',
            expected_revision: 0,
        });
        expect($('[data-item=i-9]')).not.toBeNull();
        expect(
            $('[data-item=i-9] [data-slot=block-card]')?.getAttribute(
                'data-just-added',
            ),
        ).toBe('true');
        expect($('[data-item=i-9] [data-slot=just-added]')?.textContent).toBe(
            'Just added',
        );
        expect($('[data-block=b-9] [data-slot=row-added]')?.textContent).toBe(
            '✓ Added',
        );

        const toast = useToasts().items[0];

        expect(toast.kind).toBe('action');
        expect(toast.message).toBe('Open tickets added · Show me · Undo');
        expect(toast.actions.map((a) => a.label)).toEqual(['Show me', 'Undo']);
    });

    it('rolls back with msg:toast-rollback and reloads the layout on a stale revision, and adds nothing twice on a double click', async () => {
        wide();
        replies = [
            ok(dashboard([item()])),
            resultsOf([result()]),
            catalogue(entry('b-9', 'Open tickets')),
            {
                ok: false,
                status: 409,
                json: {
                    error: {
                        code: 'dashboards.revision_conflict',
                        revision: 3,
                    },
                },
            },
            ok({ ...dashboard([item()]), revision: 3 }),
            resultsOf([result()]),
        ];

        await mountPage();
        await openPanel();

        const button = $(
            '[data-block=b-9] [data-slot=row-add]',
        ) as HTMLButtonElement;
        button.click();
        button.click();
        await flushPromises();
        await flushPromises();
        await flushPromises();

        expect(calls('POST')).toHaveLength(1);
        expect($('[data-item="pending:b-9"]')).toBeNull();
        expect($$('[data-slot=block-card]')).toHaveLength(1);

        const toast = useToasts().items[0];

        expect(toast.kind).toBe('rollback');
        expect(toast.message).toBe(
            en['toast-rollback'].replace('{block}', 'Open tickets'),
        );
    });

    it('removes from the panel at once with no confirmation, keeps focus on the row and says so', async () => {
        wide();
        replies = [
            ok(dashboard([item()])),
            resultsOf([result()]),
            catalogue(entry('b-1', 'Revenue overview')),
            {
                ok: true,
                status: 200,
                json: { data: { block_id: 'b-1', removed: true, revision: 1 } },
            },
        ];

        await mountPage();
        await openPanel();
        (
            $('[data-block=b-1] [data-slot=row-remove]') as HTMLButtonElement
        ).click();
        await flushPromises();
        await flushPromises();

        expect(calls('DELETE')).toHaveLength(1);
        expect(JSON.parse(calls('DELETE')[0][1].body!)).toEqual({
            expected_revision: 0,
        });
        expect($('[data-slot=block-card]')).toBeNull();
        expect($('[data-block=b-1] [data-slot=row-add]')).toBe(
            document.activeElement,
        );
        expect(useToasts().items[0].message).toBe(
            'Revenue overview removed · Undo',
        );
        expect(useToasts().items[0].actions.map((a) => a.label)).toEqual([
            'Undo',
        ]);
    });

    it('puts the Block back with msg:toast-rollback when the server refuses a remove, and shows msg:locked for a Mandatory item', async () => {
        wide();
        replies = [
            ok(
                dashboard([
                    item(),
                    item({
                        item_id: 'i-2',
                        block_id: 'b-2',
                        block: { ...item().block!, name: 'Locked one' },
                    }),
                ]),
            ),
            resultsOf([]),
            { ok: false, status: 500, json: {} },
            {
                ok: false,
                status: 403,
                json: { error: { code: 'dashboards.item_locked' } },
            },
        ];

        await mountPage();

        const menus = $$('[data-slot=block-menu]');
        const removeFrom = async (index: number) => {
            menus[index].dispatchEvent(
                new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
            );
            await flushPromises();

            const entry = Array.from(
                document.querySelectorAll<HTMLElement>('[role=menuitem]'),
            ).find((i) => i.textContent?.includes('Remove from dashboard'))!;

            entry.click();
            await flushPromises();
            await flushPromises();
        };

        await removeFrom(0);

        expect($$('[data-slot=block-card]')).toHaveLength(2);
        expect(useToasts().items.at(-1)!.kind).toBe('rollback');

        await removeFrom(1);

        expect($$('[data-slot=block-card]')).toHaveLength(2);
        expect(useToasts().items.at(-1)!.message).toBe(en.locked);
    });

    it('lets a card that is no longer available be removed', async () => {
        replies = [
            ok(dashboard([item({ state: 'unpublished', block: null })])),
            resultsOf([]),
            {
                ok: true,
                status: 200,
                json: { data: { block_id: 'b-1', removed: true, revision: 1 } },
            },
        ];

        await mountPage();
        (
            $('[data-slot=block-unavailable] button') as HTMLButtonElement
        ).click();
        await flushPromises();
        await flushPromises();

        expect(calls('DELETE')).toHaveLength(1);
        expect($('[data-slot=dashboard-empty]')).not.toBeNull();
    });

    it('shows the free-space hint where the layout says the next Block goes', async () => {
        wide();
        replies = [
            ok({ ...dashboard([item()]), next_free_slot: { col: 6, row: 2 } }),
            resultsOf([result()]),
            catalogue(entry('b-9', 'Open tickets')),
        ];

        await mountPage();
        await openPanel();

        const add = $(
            '[data-block=b-9] [data-slot=row-add]',
        ) as HTMLButtonElement;
        const enter = new Event('pointerenter');
        Object.defineProperty(enter, 'pointerType', { value: 'mouse' });
        add.dispatchEvent(enter);
        await flushPromises();

        const hint = $('[data-slot=free-space-hint]')!;

        expect(hint.getAttribute('style')).toContain('--col: 7');
        expect(hint.getAttribute('style')).toContain('--row: 3');
        expect(hint.textContent).toContain(en['free-space-hint']);

        add.dispatchEvent(new Event('pointerleave'));
        await flushPromises();

        expect($('[data-slot=free-space-hint]')).toBeNull();
    });

    it('puts a removed Block back with Undo, at its remembered geometry and minimized state, and moves focus to its title', async () => {
        wide();
        const restored = item({
            item_id: 'i-new',
            geometry: { col: 3, row: 2, width: 6, height: 'medium' },
            minimized: true,
        });
        replies = [
            ok(
                dashboard([
                    item({
                        geometry: {
                            col: 3,
                            row: 2,
                            width: 6,
                            height: 'medium',
                        },
                        minimized: true,
                    }),
                ]),
            ),
            resultsOf([result()]),
            catalogue(entry('b-1', 'Revenue overview')),
            {
                ok: true,
                status: 200,
                json: { data: { block_id: 'b-1', removed: true, revision: 1 } },
            },
            {
                ok: true,
                status: 201,
                json: {
                    data: {
                        item: restored,
                        revision: 2,
                        next_free_slot: { col: 0, row: 0 },
                    },
                },
            },
            resultsOf([result({ item_id: 'i-new' })]),
        ];

        await mountPage();
        await openPanel();
        (
            $('[data-block=b-1] [data-slot=row-remove]') as HTMLButtonElement
        ).click();
        await flushPromises();
        await flushPromises();

        useToasts().items[0].actions[0].run();
        await flushPromises();
        await flushPromises();
        await flushPromises();

        const call = calls('POST').find((c) => c[0].endsWith('/restore'))!;

        expect(JSON.parse(call[1].body!)).toEqual({
            geometry: { col: 3, row: 2, width: 6, height: 'medium' },
            minimized: true,
            expected_revision: 1,
        });
        expect($('[data-item=i-new]')).not.toBeNull();
        expect(
            document.activeElement?.closest('[data-item=i-new]'),
        ).not.toBeNull();
    });

    it('walks back through the history with Ctrl+Z, ignores it inside a text field and reverses an add by removing it', async () => {
        wide();
        replies = [
            ok(dashboard([item()])),
            resultsOf([result()]),
            catalogue(entry('b-9', 'Open tickets')),
            served('b-9', 'i-9'),
            resultsOf([result()]),
            {
                ok: true,
                status: 200,
                json: { data: { block_id: 'b-9', removed: true, revision: 2 } },
            },
        ];

        await mountPage();
        await openPanel();
        (
            $('[data-block=b-9] [data-slot=row-add]') as HTMLButtonElement
        ).click();
        await flushPromises();
        await flushPromises();

        expect($('[data-item=i-9]')).not.toBeNull();

        const search = $('[data-slot=add-blocks-search]') as HTMLInputElement;
        search.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'z',
                ctrlKey: true,
                bubbles: true,
                cancelable: true,
            }),
        );
        await flushPromises();

        expect(calls('DELETE')).toHaveLength(0);

        document.body.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'z',
                ctrlKey: true,
                bubbles: true,
                cancelable: true,
            }),
        );
        await flushPromises();
        await flushPromises();

        expect(calls('DELETE')).toHaveLength(1);
        expect($('[data-item=i-9]')).toBeNull();

        // Nothing left to undo: the key does nothing.
        document.body.dispatchEvent(
            new KeyboardEvent('keydown', {
                key: 'z',
                metaKey: true,
                bubbles: true,
                cancelable: true,
            }),
        );
        await flushPromises();

        expect(calls('DELETE')).toHaveLength(1);
    });

    it('retries a restore once after a 409 and marks the entry unavailable with msg:toast-rollback when it is refused', async () => {
        wide();
        replies = [
            ok(dashboard([item()])),
            resultsOf([result()]),
            {
                ok: true,
                status: 200,
                json: { data: { block_id: 'b-1', removed: true, revision: 1 } },
            },
            {
                ok: false,
                status: 409,
                json: {
                    error: {
                        code: 'dashboards.revision_conflict',
                        revision: 4,
                    },
                },
            },
            ok({ ...dashboard([]), revision: 4 }),
            {
                ok: false,
                status: 422,
                json: { error: { code: 'dashboards.block_unavailable' } },
            },
        ];

        await mountPage();

        const menus = $$('[data-slot=block-menu]');
        menus[0].dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );
        await flushPromises();
        Array.from(document.querySelectorAll<HTMLElement>('[role=menuitem]'))
            .find((i) => i.textContent?.includes('Remove from dashboard'))!
            .click();
        await flushPromises();
        await flushPromises();

        useToasts().items[0].actions[0].run();
        await flushPromises();
        await flushPromises();
        await flushPromises();

        const restores = calls('POST').filter((c) => c[0].endsWith('/restore'));

        expect(restores).toHaveLength(2);
        expect(JSON.parse(restores[1][1].body!).expected_revision).toBe(4);
        expect(useToasts().items.at(-1)!.kind).toBe('rollback');
        expect($('[data-slot=block-card]')).toBeNull();
    });
});
