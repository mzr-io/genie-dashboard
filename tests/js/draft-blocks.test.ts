// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { DraftBlock } from '../../resources/js/lib/draftBlocks';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { draftBlockLabels as labels } from '../../resources/js/locales/labels';
import { useToasts } from '../../resources/js/stores/toasts';

// Story 3.3: the Draft blocks list (UX-DR-261, 263, 271, 282): the generic list states, the columns and the Draft badge, search
// with a count, a row linking to the wizard at its saved step, `perm-denied` for a 403 and the discard confirmation.
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
            url: '/admin/blocks/drafts',
            props: { shell: { can: { 'blocks.edit': true }, items: [] } },
        }),
    };
});

const { default: DraftBlocks } =
    await import('../../resources/js/pages/admin/DraftBlocks.vue');

function draft(overrides: Partial<DraftBlock> = {}): DraftBlock {
    return {
        draft_id: 'd-1',
        block_id: 'b-1',
        name: 'Revenue today',
        type: 'kpi-card',
        category: null,
        version: '1.0',
        state: 'draft',
        revision: 3,
        lock_epoch: 1,
        step: 2,
        owner_membership_id: 'm-1',
        owner: 'Ada Lovelace',
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-02T10:00:00Z',
        ...overrides,
    };
}

const list = (
    data: DraftBlock[],
    matched = data.length,
    total = data.length,
) => ({
    data,
    meta: { total, matched },
});

type Reply = { ok: boolean; status: number; json: unknown };
type Call = { url: string; method: string };
const calls: Call[] = [];
let replies: Array<Reply | Promise<Reply>> = [];

const ok = (json: unknown): Reply => ({ ok: true, status: 200, json });
const refused = (status: number, json: unknown = {}): Reply => ({
    ok: false,
    status,
    json,
});

let wrapper: VueWrapper | null = null;

async function mountPage() {
    wrapper = mount(DraftBlocks as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await flushPromises();
}

beforeEach(() => {
    setActivePinia(createPinia());
    calls.length = 0;
    replies = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: { method?: string }) => {
            calls.push({ url, method: init?.method ?? 'GET' });
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
    vi.useRealTimers();
});

describe('Draft blocks list', () => {
    it('shows 5 skeleton rows while loading', async () => {
        replies = [new Promise<Reply>(() => {})];
        await mountPage();

        expect($('[data-state="loading"]')).not.toBeNull();
        expect($$('[data-slot="skeleton-row"]')).toHaveLength(5);
    });

    it('shows an accessible table: caption, columns, a Draft badge and a link to the wizard at the saved step', async () => {
        replies = [
            ok(
                list([
                    draft(),
                    draft({
                        draft_id: 'd-2',
                        name: 'Headcount',
                        step: 4,
                        category: 'People',
                        owner: null,
                    }),
                ]),
            ),
        ];
        await mountPage();

        expect(calls[0]).toEqual({
            url: '/api/v1/admin/blocks/drafts',
            method: 'GET',
        });
        expect($('table caption')?.textContent?.trim()).toBe(labels.caption);
        expect(
            $$('thead th').map((th) =>
                th.textContent?.trim().replace(/\s+/g, ' '),
            ),
        ).toEqual([
            labels.columns.name,
            labels.columns.category,
            labels.columns.type,
            labels.columns.version,
            labels.columns.owner,
            labels.columns.updated,
            labels.columns.status,
            labels.columns.actions,
        ]);

        const rows = $$('[data-slot="data-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].querySelector('th')?.textContent?.trim()).toBe(
            'Revenue today',
        );
        expect(rows[0].textContent).toContain('KPI card');
        expect(rows[0].textContent).toContain('1.0');
        expect(rows[0].textContent).toContain('Ada Lovelace');
        expect(rows[0].textContent).toContain(labels.noCategory);
        expect(
            rows[0].querySelector('[data-test="status"]')?.textContent?.trim(),
        ).toBe(labels.draft);
        expect(rows[1].textContent).toContain('People');
        expect(rows[1].textContent).toContain(labels.noOwner);
        expect(
            $$('[data-test="open-link"]').map((a) => a.getAttribute('href')),
        ).toEqual([
            '/admin/blocks/create?draft=d-1&step=2',
            '/admin/blocks/create?draft=d-2&step=4',
        ]);
        expect($('[data-test="count"]')).toBeNull();
    });

    it('shows list-empty with the action when there are no Drafts', async () => {
        replies = [ok(list([]))];
        await mountPage();

        expect($('[data-state="empty"]')).not.toBeNull();
        expect($('[data-test="create-block"]')?.getAttribute('href')).toBe(
            '/admin/blocks/create',
        );
        expect($('[data-slot="toolbar"]')).toBeNull();
    });

    it('shows a failure row with Retry that loads again', async () => {
        replies = [
            refused(500, { error: { code: 'server_error' } }),
            ok(list([draft()])),
        ];
        await mountPage();

        expect($('[data-slot="load-failure"]')).not.toBeNull();
        const retry = $('[data-slot="load-failure"] button') as HTMLElement;
        await click(retry);

        expect($$('[data-slot="data-row"]')).toHaveLength(1);
        expect(calls).toHaveLength(2);
    });

    it('shows perm-denied for a 403 and no table', async () => {
        replies = [refused(403, { error: { code: 'access.not_authorized' } })];
        await mountPage();

        expect($('[data-slot="perm-denied"]')).not.toBeNull();
        expect($('table')).toBeNull();
        expect($('[data-slot="toolbar"]')).toBeNull();
    });

    it('searches on the server, shows the count and list-no-match with Clear search', async () => {
        vi.useFakeTimers();
        replies = [
            ok(list([draft(), draft({ draft_id: 'd-2', name: 'Headcount' })])),
        ];
        await mountPage();

        replies = [ok(list([], 0, 2))];
        const search = $('[data-test="search"]') as HTMLInputElement;
        search.value = 'zzz';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(350);
        await flushPromises();

        expect(calls[1].url).toBe('/api/v1/admin/blocks/drafts?q=zzz');
        expect($('[data-test="count"]')?.textContent?.trim()).toBe(
            labels.count(0, 2),
        );
        expect($('[data-slot="list-no-match"]')).not.toBeNull();

        replies = [ok(list([draft()], 1, 2))];
        await click($('[data-test="clear-search"]'));
        vi.useRealTimers();
        await flushPromises();

        expect(calls[2].url).toBe('/api/v1/admin/blocks/drafts');
        expect($$('[data-slot="data-row"]')).toHaveLength(1);
    });
});

describe('Discarding a Draft', () => {
    it('asks first and sends nothing when cancelled', async () => {
        replies = [ok(list([draft()]))];
        await mountPage();

        await click($('[data-test="discard"]'));
        const dialog = $('[role="alertdialog"]');
        expect(dialog).not.toBeNull();
        expect(dialog?.textContent).toContain(
            labels.discardTitle('Revenue today'),
        );
        expect(dialog?.textContent).toContain(labels.discardImpact);

        const cancel = Array.from(
            dialog?.querySelectorAll('button') ?? [],
        ).find((b) => b.textContent?.trim() === 'Cancel');
        await click(cancel ?? null);

        expect(calls.filter((call) => call.method === 'DELETE')).toHaveLength(
            0,
        );
        expect($$('[data-slot="data-row"]')).toHaveLength(1);
    });

    it('discards on confirm and reloads without the row', async () => {
        replies = [
            ok(list([draft(), draft({ draft_id: 'd-2', name: 'Headcount' })])),
        ];
        await mountPage();

        replies = [
            ok({ data: { draft_id: 'd-1', state: 'discarded' } }),
            ok(list([draft({ draft_id: 'd-2', name: 'Headcount' })])),
        ];
        await click($('[data-test="discard"]'));
        const confirm = Array.from(
            $('[role="alertdialog"]')?.querySelectorAll('button') ?? [],
        ).find((b) =>
            b.textContent?.includes(`${labels.discard} Revenue today`),
        );
        await click(confirm ?? null);

        expect(calls.filter((call) => call.method === 'DELETE')).toEqual([
            { url: '/api/v1/admin/blocks/drafts/d-1', method: 'DELETE' },
        ]);
        expect($$('[data-slot="data-row"]')).toHaveLength(1);
        expect($('[data-slot="data-row"]')?.textContent).toContain('Headcount');
        expect($('[role="alertdialog"]')).toBeNull();
    });

    it('tells the person when the Draft was already gone and reloads', async () => {
        replies = [ok(list([draft()]))];
        await mountPage();

        replies = [
            refused(404, { error: { code: 'not_found' } }),
            ok(list([])),
        ];
        await click($('[data-test="discard"]'));
        const confirm = Array.from(
            $('[role="alertdialog"]')?.querySelectorAll('button') ?? [],
        ).find((b) => b.textContent?.includes(labels.discard + ' '));
        await click(confirm ?? null);

        expect(useToasts().items.map((toast) => toast.message)).toContain(
            labels.gone,
        );
        expect($('[data-state="empty"]')).not.toBeNull();
    });

    it('keeps the row and shows a failure toast when the discard fails', async () => {
        replies = [ok(list([draft()]))];
        await mountPage();

        replies = [refused(500, { error: { code: 'server_error' } })];
        await click($('[data-test="discard"]'));
        const confirm = Array.from(
            $('[role="alertdialog"]')?.querySelectorAll('button') ?? [],
        ).find((b) => b.textContent?.includes(labels.discard + ' '));
        await click(confirm ?? null);

        expect(useToasts().items.some((toast) => toast.kind === 'error')).toBe(
            true,
        );
        expect($$('[data-slot="data-row"]')).toHaveLength(1);
        expect(calls).toHaveLength(2);
    });

    it('ignores a second confirmation while the discard is in flight', async () => {
        replies = [ok(list([draft()]))];
        await mountPage();

        let finish: (reply: Reply) => void = () => {};
        replies = [
            new Promise<Reply>((resolve) => {
                finish = resolve;
            }),
            ok(list([])),
        ];
        await click($('[data-test="discard"]'));
        const confirm = Array.from(
            $('[role="alertdialog"]')?.querySelectorAll('button') ?? [],
        ).find((b) => b.textContent?.includes(labels.discard + ' '));
        (confirm as HTMLElement).click();
        (confirm as HTMLElement).click();
        await flushPromises();
        finish(ok({ data: { draft_id: 'd-1', state: 'discarded' } }));
        await flushPromises();

        expect(calls.filter((call) => call.method === 'DELETE')).toHaveLength(
            1,
        );
        expect(useToasts().items).toHaveLength(0);
        expect($('[data-state="empty"]')).not.toBeNull();
    });
});
