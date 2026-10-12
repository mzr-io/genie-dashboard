// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type {
    Dashboard,
    DashboardItem,
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

        await mountPage();

        expect(urls).toEqual(['/api/v1/dashboards/d-1']);

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
        replies = [ok(dashboard([item()]))];

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
            replies = [ok(dashboard([item({ state, block: null })]))];

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
        replies = [ok(dashboard([item()]))];

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
        ];

        await mountPage();

        expect($('[data-slot=block-card] img')).toBeNull();
        expect($('h2')?.textContent).toContain(hostile);
    });
});
