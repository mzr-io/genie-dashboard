// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    categoriesOf,
    highlight,
    matches,
    NO_FILTERS,
} from '../../resources/js/lib/blockCatalogue';
import type { CatalogueBlock } from '../../resources/js/lib/blockCatalogue';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';

// Story 4.7: the Add-blocks Panel (FR-52, UX-DR-67 to 69, 213, 214, 221, 222): the list, search with highlights, chips, filter, the announced count,
// each state, row status and the controls that are in place but wait for Story 4.9.
const { default: AddBlocksPanel } =
    await import('../../resources/js/components/AddBlocksPanel.vue');

function block(overrides: Partial<CatalogueBlock> = {}): CatalogueBlock {
    return {
        block_id: 'b-1',
        name: 'Headcount',
        description: 'People employed this month',
        category: 'People',
        type: 'kpi-card',
        data_source: 'HR analytics API',
        refresh_interval: 3600,
        version: '1.0',
        is_new: false,
        default_size: { width: 4, height: 'medium' },
        ...overrides,
    };
}

const THREE = [
    block(),
    block({
        block_id: 'b-2',
        name: 'Revenue overview',
        description: 'Net revenue',
        category: 'Finance',
        data_source: 'ERP',
        is_new: true,
    }),
    block({
        block_id: 'b-3',
        name: 'Open tickets',
        description: 'Support queue',
        category: null,
        data_source: null,
    }),
];

type Reply = { ok: boolean; status: number; json: unknown };
let replies: Reply[] = [];
let wrapper: VueWrapper | null = null;

async function mountPanel(addedIds: string[] = []): Promise<void> {
    wrapper = mount(AddBlocksPanel as never, {
        props: { addedIds },
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
    await flushPromises();
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const ok = (data: CatalogueBlock[]): Reply => ({
    ok: true,
    status: 200,
    json: { data, meta: { total: data.length } },
});

async function type(value: string): Promise<void> {
    const input = $('[data-slot=add-blocks-search]') as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

beforeEach(() => {
    replies = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => {
            const next = replies.shift();

            if (!next) {
                throw new Error('unexpected request');
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
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Add-blocks Panel', () => {
    it('lists every Block with thumbnail, name, description, category tag and New badge, counted', async () => {
        replies = [ok(THREE)];

        await mountPanel();

        const rows = $$('[data-slot=add-blocks-list] > li');

        expect(rows).toHaveLength(3);
        expect($('[data-slot=add-blocks-list]')?.getAttribute('role')).toBe(
            'list',
        );
        expect(rows[0].querySelector('svg')?.getAttribute('aria-hidden')).toBe(
            'true',
        );
        expect(rows[0].textContent).toContain('Headcount');
        expect(rows[0].textContent).toContain('People employed this month');
        expect(
            rows[0].querySelector('[data-slot=row-category]')?.textContent,
        ).toBe('People');
        expect(rows[1].querySelector('[data-slot=row-new]')?.textContent).toBe(
            'New',
        );
        expect(rows[0].querySelector('[data-slot=row-new]')).toBeNull();
        expect($('[data-slot=add-blocks-count]')?.textContent).toContain(
            '3 available blocks',
        );
    });

    it('moves focus to search on open and names the search box', async () => {
        replies = [ok(THREE)];

        await mountPanel();

        const input = $('[data-slot=add-blocks-search]') as HTMLInputElement;

        expect(document.activeElement).toBe(input);
        expect(
            document.querySelector(`label[for="${input.id}"]`)?.textContent,
        ).toBe('Search blocks');
        expect(input.getAttribute('placeholder')).toBe('Search blocks…');
    });

    it('filters by search over names, descriptions and categories, highlights the match as text and counts "n of m"', async () => {
        replies = [ok(THREE)];

        await mountPanel();
        await type('net');

        const rows = $$('[data-slot=add-blocks-list] > li');

        expect(rows).toHaveLength(1);
        expect(rows[0].textContent).toContain('Revenue overview');
        expect(rows[0].querySelectorAll('mark').length).toBeGreaterThan(0);
        expect($('[data-slot=add-blocks-count]')?.textContent).toContain(
            '1 of 3 blocks',
        );

        await type('finance');

        expect($$('[data-slot=add-blocks-list] > li')).toHaveLength(1);
    });

    it('shows only the categories that have a Block, plus All, and narrows by chip, type and Data Source', async () => {
        replies = [ok(THREE)];

        await mountPanel();

        const chips = $$('[data-slot=category-chips] button').map((b) =>
            b.textContent?.trim(),
        );

        expect(chips).toEqual(['All', 'Finance', 'People']);

        $$('[data-slot=category-chips] button')[2].click();
        await flushPromises();

        expect($$('[data-slot=add-blocks-list] > li')).toHaveLength(1);

        $$('[data-slot=category-chips] button')[0].click();
        await flushPromises();

        const source = $('[data-slot=filter-source]') as HTMLSelectElement;
        source.value = 'ERP';
        source.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();

        expect($$('[data-slot=add-blocks-list] > li')).toHaveLength(1);
        expect($('[data-slot=add-blocks-list]')?.textContent).toContain(
            'Revenue overview',
        );
    });

    it('shows msg:drawer-empty-search with Clear search that restores the list', async () => {
        replies = [ok(THREE)];

        await mountPanel();
        await type('zzzz');

        const empty = $('[data-slot=add-blocks-empty-search]')!;

        expect(empty.textContent).toContain("No blocks match 'zzzz'");

        empty.querySelector('button')!.click();
        await flushPromises();

        expect($$('[data-slot=add-blocks-list] > li')).toHaveLength(3);
    });

    it('shows msg:drawer-none when nothing is published', async () => {
        replies = [ok([])];

        await mountPanel();

        expect($('[data-slot=add-blocks-none]')?.textContent).toContain(
            en['drawer-none'],
        );
    });

    it('shows skeleton rows while loading, then msg:drawer-load-failed with Retry and disabled search and chips', async () => {
        replies = [{ ok: false, status: 500, json: {} }, ok(THREE)];
        wrapper = mount(AddBlocksPanel as never, {
            props: { addedIds: [] },
            attachTo: document.body,
            global: { plugins: [createCatalogue()] },
        });

        expect($('[data-slot=add-blocks-loading]')).not.toBeNull();

        await flushPromises();
        await flushPromises();

        expect($('[data-slot=add-blocks-error]')?.textContent).toContain(
            en['drawer-load-failed'],
        );
        expect(
            ($('[data-slot=add-blocks-search]') as HTMLInputElement).disabled,
        ).toBe(true);
        expect(
            $$('[data-slot=category-chips] button').every(
                (b) => (b as HTMLButtonElement).disabled,
            ),
        ).toBe(true);

        $('[data-slot=add-blocks-error] button')!.click();
        await flushPromises();
        await flushPromises();

        expect($$('[data-slot=add-blocks-list] > li')).toHaveLength(3);
    });

    it('shows ✓ Added as status text and × Remove for a Block already on the dashboard, and + Add for the others, never reordering', async () => {
        replies = [ok(THREE)];

        await mountPanel(['b-1']);

        const rows = $$('[data-slot=add-blocks-list] > li');
        const added = rows[0].querySelector('[data-slot=row-added]')!;

        expect(added.textContent).toBe('✓ Added');
        expect(added.tagName).toBe('SPAN');
        expect(
            rows[0]
                .querySelector('[data-slot=row-remove]')
                ?.getAttribute('aria-label'),
        ).toBe('Remove Headcount from dashboard');

        const add = rows[1].querySelector('[data-slot=row-add]')!;

        expect(add.getAttribute('aria-label')).toBe(
            'Add Revenue overview to dashboard',
        );
        expect(add.getAttribute('aria-disabled')).toBe('true');
        expect(add.getAttribute('title')).toBe('Adding is not enabled yet');
        expect(rows.map((r) => r.dataset.block)).toEqual(['b-1', 'b-2', 'b-3']);
    });

    it('has a disclosure button per row with its description and the meta line, and no drag grips or Request a block', async () => {
        replies = [ok(THREE)];

        await mountPanel();

        const button = $('[data-slot=row-disclosure]') as HTMLButtonElement;

        expect(button.getAttribute('aria-expanded')).toBe('false');
        expect(button.getAttribute('aria-controls')).toBe('preview-b-1');
        expect(
            document.getElementById(button.getAttribute('aria-describedby')!)
                ?.textContent,
        ).toContain('People employed this month');

        button.click();
        await flushPromises();

        const details = $('#preview-b-1')!;

        expect(button.getAttribute('aria-expanded')).toBe('true');
        expect(details.textContent).toContain(
            'Data source: HR analytics API · Refreshes hourly · v1.0 · Default size 4 columns × Medium',
        );
        expect(document.body.textContent).not.toMatch(/Request a block/i);
        expect($('[draggable=true]')).toBeNull();
    });

    it('announces the count politely once the search settles', async () => {
        replies = [ok(THREE)];
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });

        await mountPanel();
        await type('net');
        await vi.advanceTimersByTimeAsync(600);

        const region = document.querySelector('[data-announcer=polite]');

        expect(region?.textContent).toContain('1 of 3 blocks');
    });

    it('shows the footer message and Done closes the panel', async () => {
        replies = [ok(THREE)];

        await mountPanel();

        expect($('footer')?.textContent).toContain(en['drawer-footer']);

        $$('footer button')[0].click();

        expect(wrapper!.emitted('close')).toHaveLength(1);
    });

    it('offers a skip link back to the dashboard', async () => {
        replies = [ok(THREE)];

        await mountPanel();
        $('[data-slot=skip-to-dashboard]')!.click();

        expect(wrapper!.emitted('skip')).toHaveLength(1);
    });
});

describe('Preview with sample values', () => {
    const sample = (value = '4821.37'): Reply => ({
        ok: true,
        status: 200,
        json: {
            data: {
                block_id: 'b-1',
                type: 'kpi-card',
                version: '1.0',
                sample: true,
                render_payload: {
                    state: 'normal',
                    title: null,
                    subtitle: null,
                    icon: null,
                    footerAction: null,
                    kpiLabel: 'Headcount',
                    headline: {
                        available: true,
                        value,
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
                },
            },
        },
    });

    async function openFirst(): Promise<void> {
        ($('[data-slot=row-disclosure]') as HTMLButtonElement).click();
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();
    }

    it('opens an accordion preview named for the Block, with the real renderer, the sample note and an inert card', async () => {
        replies = [ok(THREE), sample()];

        await mountPanel();
        await openFirst();

        const region = $('[data-slot=row-preview]')!;

        expect(region.getAttribute('role')).toBe('region');
        expect(region.getAttribute('aria-label')).toBe(
            'Preview of Headcount with sample data',
        );
        expect(
            region
                .querySelector('[data-test=kpi-headline]')
                ?.textContent?.trim(),
        ).toBe('4,821.37');
        expect(
            region
                .querySelector('[data-slot=preview-card]')
                ?.hasAttribute('inert'),
        ).toBe(true);
        expect(
            region.querySelector('[data-slot=sample-note]')?.textContent,
        ).toContain(en['sample-drawer']);
        expect(document.body.textContent).toContain(
            'Data source: HR analytics API · Refreshes hourly · v1.0 · Default size 4 columns × Medium',
        );
        expect(fetch).toHaveBeenLastCalledWith(
            '/api/v1/blocks/b-1/sample',
            expect.anything(),
        );
    });

    it('opens one preview at a time and shows the same values when opened again', async () => {
        replies = [ok(THREE), sample('1000.5'), sample('2000.5')];

        await mountPanel();
        await openFirst();

        expect($$('[data-slot=row-preview]')).toHaveLength(1);

        ($$('[data-slot=row-disclosure]')[1] as HTMLButtonElement).click();
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();

        expect($$('[data-slot=row-preview]')).toHaveLength(1);
        expect($('[data-block=b-1] [data-slot=row-preview]')).toBeNull();

        ($$('[data-slot=row-disclosure]')[0] as HTMLButtonElement).click();
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();

        // The first sample is kept, not asked for again: previewed twice gives identical values.
        expect(
            $('[data-block=b-1] [data-test=kpi-headline]')?.textContent?.trim(),
        ).toBe('1,000.50');
    });

    it('falls back to a labelled placeholder, never 0, when no sample can be made', async () => {
        replies = [ok(THREE), { ok: false, status: 404, json: {} }];

        await mountPanel();
        await openFirst();

        const placeholder = $('[data-slot=preview-placeholder]')!;

        expect(placeholder.textContent).toContain('Sample value');
        expect($('[data-test=kpi-headline]')).toBeNull();
        expect($('[data-slot=sample-note]')?.textContent).toContain(
            en['sample-drawer'],
        );
    });

    it('shows + Add to dashboard aria-disabled with its reason, and Close preview and Esc collapse it, a second Esc closing the drawer', async () => {
        replies = [ok(THREE), sample(), sample()];

        await mountPanel();
        await openFirst();

        const add = $('[data-slot=preview-add]')!;

        expect(add.getAttribute('aria-disabled')).toBe('true');
        expect(add.getAttribute('title')).toBe('Adding is not enabled yet');

        $('[data-slot=preview-close]')!.click();
        await flushPromises();

        expect($('[data-slot=row-preview]')).toBeNull();

        await openFirst();
        $('[data-slot=add-blocks-panel]')!.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await flushPromises();

        expect($('[data-slot=row-preview]')).toBeNull();
        expect(wrapper!.emitted('close')).toBeUndefined();

        $('[data-slot=add-blocks-panel]')!.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );

        expect(wrapper!.emitted('close')).toHaveLength(1);
    });

    it('opens from a click on the row text and leaves the buttons to their own action', async () => {
        replies = [ok(THREE), sample()];

        await mountPanel();
        ($('[data-block=b-1] p') as HTMLElement).click();
        await flushPromises();
        await vi.dynamicImportSettled();
        await flushPromises();

        expect($('[data-block=b-1] [data-slot=row-preview]')).not.toBeNull();
    });
});

describe('catalogue helpers', () => {
    it('matches every term across name, description, category and type', () => {
        const b = block();

        expect(matches(b, { ...NO_FILTERS, query: 'people employed' })).toBe(
            true,
        );
        expect(matches(b, { ...NO_FILTERS, query: 'people zebra' })).toBe(
            false,
        );
        expect(matches(b, { ...NO_FILTERS, type: 'bar-chart' })).toBe(false);
        expect(matches(b, { ...NO_FILTERS, source: 'HR analytics API' })).toBe(
            true,
        );
    });

    it('splits text around matches without producing markup', () => {
        expect(highlight('Net revenue', 'net rev')).toEqual([
            { text: 'Net', hit: true },
            { text: ' ', hit: false },
            { text: 'rev', hit: true },
            { text: 'enue', hit: false },
        ]);
        expect(highlight('<b>x</b>', 'b')).toEqual([
            { text: '<', hit: false },
            { text: 'b', hit: true },
            { text: '>x</', hit: false },
            { text: 'b', hit: true },
            { text: '>', hit: false },
        ]);
    });

    it('lists the categories that have a Block, sorted', () => {
        expect(categoriesOf(THREE)).toEqual(['Finance', 'People']);
    });
});
