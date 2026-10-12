// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import { createCatalogue } from '../../resources/js/lib/i18n';
import type { PublishedBlock } from '../../resources/js/lib/publishedBlocks';
import { publishedBlockLabels as labels } from '../../resources/js/locales/labels';

// Story 3.19: Admin > Published blocks (UX-DR-261, 263, 282): the columns and the Published badge, the generic list states, `perm-denied` for a 403 and
// the row the wizard names with `?published=<block id>` highlighted and focused.
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
            url: '/admin/blocks/published',
            props: { shell: { can: { 'blocks.edit': true }, items: [] } },
        }),
    };
});

const { default: PublishedBlocks } =
    await import('../../resources/js/pages/admin/PublishedBlocks.vue');

function block(overrides: Partial<PublishedBlock> = {}): PublishedBlock {
    return {
        block_id: 'b-1',
        version_id: 'v-1',
        name: 'Revenue overview',
        type: 'kpi-card',
        category: 'Finance',
        version: '1.0',
        owner_membership_id: 'm-1',
        owner: 'Ada Lovelace',
        updated_at: '2026-10-12T09:20:00Z',
        status: 'published',
        ...overrides,
    };
}

type Reply = { ok: boolean; status: number; json: unknown };
let replies: Array<Reply | Promise<Reply>> = [];
let wrapper: VueWrapper | null = null;
const urls: string[] = [];

const list = (data: PublishedBlock[]): Reply => ({
    ok: true,
    status: 200,
    json: { data, meta: { total: data.length } },
});

async function mountPage(): Promise<void> {
    wrapper = mount(PublishedBlocks as never, {
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
    window.history.replaceState({}, '', '/admin/blocks/published');
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) => {
            urls.push(url);
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

describe('Published blocks list', () => {
    it('lists name, category, type, version, owner, last updated and the Published status', async () => {
        replies = [list([block()])];
        await mountPage();

        const headers = $$('thead th').map((th) => th.textContent?.trim());
        const cells = $$('tbody tr:first-child > *').map((cell) =>
            cell.textContent?.replace(/\s+/g, ' ').trim(),
        );

        expect(urls).toEqual(['/api/v1/admin/blocks/published']);
        expect(headers).toEqual([
            labels.columns.name,
            labels.columns.category,
            labels.columns.type,
            labels.columns.version,
            labels.columns.owner,
            labels.columns.updated,
            labels.columns.status,
        ]);
        expect(cells[0]).toBe('Revenue overview');
        expect(cells[1]).toBe('Finance');
        expect(cells[2]).toBe('KPI card');
        expect(cells[3]).toBe('1.0');
        expect(cells[4]).toBe('Ada Lovelace');
        expect(cells[6]).toBe('Published');
    });

    it('highlights and focuses the row the wizard named, and drops the query', async () => {
        window.history.replaceState(
            {},
            '',
            '/admin/blocks/published?published=b-2',
        );
        replies = [
            list([block(), block({ block_id: 'b-2', name: 'Cash on hand' })]),
        ];
        await mountPage();

        const row = $$('[data-row-key]').find(
            (r) => r.dataset.rowKey === 'b-2',
        )!;

        expect(row.dataset.highlighted).toBe('true');
        expect(document.activeElement).toBe(row);
        expect(window.location.search).toBe('');
        expect(
            $$('[data-row-key]').filter(
                (r) => r.dataset.highlighted === 'true',
            ),
        ).toHaveLength(1);
    });

    it('shows list-empty with the action when nothing is published', async () => {
        replies = [list([])];
        await mountPage();

        expect($('[data-slot=list-empty]')?.textContent).toContain(
            labels.items,
        );
        expect($('table')).toBeNull();
    });

    it('shows perm-denied for a 403 and a Retry row for a failure', async () => {
        replies = [{ ok: false, status: 403, json: {} }];
        await mountPage();

        expect($('[data-slot=perm-denied]')).not.toBeNull();

        wrapper?.unmount();
        document.body.innerHTML = '';
        replies = [{ ok: false, status: 500, json: {} }, list([block()])];
        await mountPage();

        expect($('[data-slot=load-failure]')).not.toBeNull();

        $('[data-test=retry]')!.click();
        await flushPromises();
        await flushPromises();

        expect($('table')).not.toBeNull();
    });
});
