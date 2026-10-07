// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { HostEntry } from '../../resources/js/lib/hostAllowlist';
import { createCatalogue } from '../../resources/js/lib/i18n';
import {
    hostAllowlistLabels,
    settingsLabels,
} from '../../resources/js/locales/labels';
import { useToasts } from '../../resources/js/stores/toasts';

// Story 2.1: System settings > Host allowlist (UX-DR-115, 262, 263, 23): a sortable data table with search and the
// generic list states, an inline add form with server-side validation, a stale list (409) that keeps what was typed,
// and the removal alertdialog that lists the Data Sources the removal would block (focus on Cancel).
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
        usePage: () => ({
            url: '/admin/settings/host-allowlist',
            props: { shell: { can: { 'settings.manage': true }, items: [] } },
        }),
    };
});

const { default: HostAllowlist } =
    await import('../../resources/js/pages/admin/HostAllowlist.vue');
const { default: SystemSettings } =
    await import('../../resources/js/pages/admin/SystemSettings.vue');

function entry(overrides: Partial<HostEntry> = {}): HostEntry {
    return {
        entry_id: 'e-1',
        host: 'api.example.com',
        scheme: 'https',
        port: 443,
        added_by: 'Ada Admin',
        added_at: '2026-10-01T09:30:00Z',
        ...overrides,
    };
}

function list(
    data: HostEntry[],
    meta: Partial<{ revision: number; total: number; matched: number }> = {},
) {
    return {
        data,
        meta: {
            revision: 1,
            total: data.length,
            matched: data.length,
            sort: 'host',
            direction: 'asc',
            ...meta,
        },
    };
}

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

async function mountPage(component: unknown = HostAllowlist) {
    wrapper = mount(component as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();

    return wrapper;
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const polite = () => $('[data-announcer="polite"]');

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await flushPromises();
}

async function type(selector: string, value: string): Promise<void> {
    const input = $(selector) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

beforeEach(() => {
    setActivePinia(createPinia());
    calls.length = 0;
    replies = [];
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

describe('System settings', () => {
    it('is a page that links to the Host allowlist', async () => {
        await mountPage(SystemSettings);

        expect($('h1')?.textContent).toBe(settingsLabels.pageTitle);
        const link = $('[data-test="open-host-allowlist"]');
        expect(link?.getAttribute('href')).toBe(
            '/admin/settings/host-allowlist',
        );
        expect(link?.textContent).toContain(settingsLabels.hostAllowlist);
    });
});

describe('Host allowlist page', () => {
    it('shows 5 skeleton rows while loading, then a table with caption, aria-sort and the Not encrypted cue on http rows', async () => {
        replies = [new Promise<Reply>(() => {})];
        await mountPage();

        expect($('[data-state="loading"]')).not.toBeNull();
        expect($$('[data-slot="skeleton-row"]')).toHaveLength(5);
        wrapper?.unmount();
        document.body.innerHTML = '';

        replies = [
            ok(
                list([
                    entry(),
                    entry({
                        entry_id: 'e-2',
                        host: 'legacy.example.com',
                        scheme: 'http',
                        port: 80,
                        added_by: null,
                    }),
                ]),
            ),
        ];
        await mountPage();

        expect($('table caption')?.textContent?.trim()).toBe(
            hostAllowlistLabels.caption,
        );
        const headers = $$('thead th');
        expect(headers).toHaveLength(6);
        expect(headers[0].getAttribute('aria-sort')).toBe('ascending');
        expect(headers[1].getAttribute('aria-sort')).toBe('none');
        expect(headers[3].hasAttribute('aria-sort')).toBe(false);
        const rows = $$('tbody [data-slot="data-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('api.example.com');
        expect(rows[0].textContent).toContain('Ada Admin');
        expect(rows[0].querySelector('[data-test="not-encrypted"]')).toBeNull();
        expect(
            rows[1].querySelector('[data-test="not-encrypted"]')?.textContent,
        ).toContain(hostAllowlistLabels.notEncrypted);
        expect(rows[1].textContent).toContain(
            hostAllowlistLabels.unknownMember,
        );
        expect(calls[0].url).toContain('/api/v1/admin/host-allowlist?');
    });

    it('sorts by a header, requests the order from the server and announces it', async () => {
        replies = [ok(list([entry()]))];
        await mountPage();

        replies.push(ok(list([entry()])));
        await click($('[data-column="port"]'));

        expect(calls[1].url).toContain('sort=port');
        expect($$('thead th')[2].getAttribute('aria-sort')).toBe('ascending');
        expect(polite()?.textContent).toContain(
            hostAllowlistLabels.sorted('Port', false),
        );
    });

    it('shows list-empty with a primary "+ Add host" action when the list is empty', async () => {
        replies = [ok(list([], { revision: 0 }))];
        await mountPage();

        expect($('[data-slot="list-empty"]')?.textContent).toBe(
            'No hosts yet. + Add host to start.',
        );
        const action = $('[data-state="empty"] [data-test="add-host"]');
        expect(action?.textContent?.trim()).toBe('+ Add host');
        expect($('table')).toBeNull();

        await click(action);
        expect($('[data-test="add-host-form"]')).not.toBeNull();
        expect(document.activeElement).toBe($('[data-test="add-host-name"]'));
    });

    it('shows the load failure with Retry, and loads again on Retry', async () => {
        replies = [refused(500)];
        await mountPage();

        expect($('[data-slot="load-failure"]')?.textContent).toContain(
            "We couldn't load hosts. Try again.",
        );

        replies.push(ok(list([entry()])));
        await click($('[data-test="retry"]'));
        expect($('table')).not.toBeNull();
    });

    it('shows list-no-match with Clear search when a search matches nothing', async () => {
        vi.useFakeTimers();
        replies = [ok(list([entry()]))];
        await mountPage();

        replies.push(ok(list([], { total: 1, matched: 0 })));
        await type('[data-test="search"]', 'zzz');
        await vi.advanceTimersByTimeAsync(400);
        await flushPromises();

        expect(calls[1].url).toContain('q=zzz');
        expect($('[data-slot="list-no-match"]')?.textContent).toContain(
            "No hosts match 'zzz'.",
        );
    });

    it('shows the permission message when the server answers 403', async () => {
        replies = [refused(403)];
        await mountPage();

        expect($('[data-slot="perm-denied"]')).not.toBeNull();
        expect($('table')).toBeNull();
    });

    it('adds a host: sends exactly what was typed with the revision, announces saved, highlights and focuses the new row', async () => {
        replies = [ok(list([entry()], { revision: 4 }))];
        await mountPage();
        await click($('[data-test="add-host"]'));

        replies.push(
            ok({
                data: entry({ entry_id: 'e-9', host: 'new.example.com' }),
                meta: { revision: 5 },
            }),
        );
        replies.push(
            ok(
                list(
                    [
                        entry(),
                        entry({ entry_id: 'e-9', host: 'new.example.com' }),
                    ],
                    { revision: 5 },
                ),
            ),
        );
        await type('[data-test="add-host-name"]', ' new.example.com:8443 ');
        await click($('[data-test="add-host-save"]'));

        expect(writes()[0]).toMatchObject({
            method: 'POST',
            url: '/api/v1/admin/host-allowlist',
            body: {
                host: ' new.example.com:8443 ',
                scheme: 'https',
                revision: 4,
            },
        });
        expect(polite()?.textContent).toContain('Changes saved.');
        expect(polite()?.textContent).toContain(
            hostAllowlistLabels.added('new.example.com'),
        );
        expect($('[data-test="add-host-form"]')).toBeNull();

        const row = $('[data-row-key="e-9"]');
        expect(row?.getAttribute('data-highlighted')).toBe('true');
        expect(document.activeElement).toBe(row);
    });

    it('sends plain http when chosen and shows that it is not encrypted', async () => {
        replies = [ok(list([entry()]))];
        await mountPage();
        await click($('[data-test="add-host"]'));
        expect($('[data-test="scheme-notice"]')).toBeNull();

        await click(
            $$('[data-slot="segmented-item"]').find(
                (item) => item.textContent?.trim() === 'http',
            ) ?? null,
        );
        expect($('[data-test="scheme-notice"]')?.textContent).toContain(
            hostAllowlistLabels.schemeNotice,
        );

        replies.push(
            ok({
                data: entry({ entry_id: 'e-9', scheme: 'http', port: 80 }),
                meta: { revision: 2 },
            }),
        );
        replies.push(ok(list([entry()])));
        await type('[data-test="add-host-name"]', 'plain.example.com');
        await click($('[data-test="add-host-save"]'));

        expect(writes()[0].body).toMatchObject({ scheme: 'http' });
    });

    it('shows a server validation error inline with aria-invalid and aria-describedby, focus on the field, and keeps the value', async () => {
        replies = [ok(list([entry()]))];
        await mountPage();
        await click($('[data-test="add-host"]'));

        replies.push(
            refused(422, {
                errors: {
                    host: [
                        'This address is in a range that cannot be allowed.',
                    ],
                },
                reasons: { host: 'blocked_address' },
            }),
        );
        await type('[data-test="add-host-name"]', '127.0.0.1');
        await click($('[data-test="add-host-save"]'));

        const field = $('[data-test="add-host-name"]') as HTMLInputElement;
        expect(field.getAttribute('aria-invalid')).toBe('true');
        expect(field.value).toBe('127.0.0.1');
        expect(document.activeElement).toBe(field);
        const describedBy = field.getAttribute('aria-describedby') ?? '';
        const errorId = describedBy
            .split(' ')
            .find((id) => id.endsWith('-error'));
        expect(document.getElementById(errorId ?? '')?.textContent).toContain(
            hostAllowlistLabels.reasons.blocked_address,
        );
        expect(writes()).toHaveLength(1);
    });

    it('shows an inline duplicate message', async () => {
        replies = [ok(list([entry()]))];
        await mountPage();
        await click($('[data-test="add-host"]'));

        replies.push(
            refused(422, {
                errors: {
                    host: ['This host and port are already on the allowlist.'],
                },
                reasons: { host: 'duplicate' },
            }),
        );
        await type('[data-test="add-host-name"]', 'api.example.com');
        await click($('[data-test="add-host-save"]'));

        expect($('[data-slot="field-error"]')?.textContent).toContain(
            hostAllowlistLabels.reasons.duplicate,
        );
        expect(document.activeElement).toBe($('[data-test="add-host-name"]'));
    });

    it('on a stale list (409) shows the fresh list and keeps the typed value, then adds against the new revision', async () => {
        replies = [ok(list([entry()], { revision: 1 }))];
        await mountPage();
        await click($('[data-test="add-host"]'));

        const other = entry({ entry_id: 'e-2', host: 'other.example.com' });
        replies.push(
            refused(409, {
                error: { code: 'connector.revision_conflict' },
                current: list([entry(), other], { revision: 2 }),
            }),
        );
        await type('[data-test="add-host-name"]', 'mine.example.com');
        await click($('[data-test="add-host-save"]'));

        expect($$('tbody [data-slot="data-row"]')).toHaveLength(2);
        expect(
            ($('[data-test="add-host-name"]') as HTMLInputElement).value,
        ).toBe('mine.example.com');
        expect($('[data-test="add-failure"]')?.textContent).toContain(
            hostAllowlistLabels.conflict,
        );
        expect(polite()?.textContent).toContain(hostAllowlistLabels.conflict);
        expect(document.activeElement).toBe($('[data-test="add-failure"]'));

        replies.push(
            ok({
                data: entry({ entry_id: 'e-3', host: 'mine.example.com' }),
                meta: { revision: 3 },
            }),
        );
        replies.push(ok(list([entry(), other], { revision: 3 })));
        await click($('[data-test="add-host-save"]'));
        expect(writes()[1].body).toMatchObject({
            host: 'mine.example.com',
            revision: 2,
        });
    });

    it('removes through an alertdialog: focus on Cancel, the destructive button repeats the host, dependents are listed, Cancel sends nothing', async () => {
        replies = [ok(list([entry()], { revision: 6 }))];
        await mountPage();

        replies.push(
            ok({
                data: [
                    { id: 'd-1', name: 'Sales API' },
                    { id: 'd-2', name: 'Support API' },
                ],
            }),
        );
        await click($('[data-test="remove-host"]'));

        expect(calls[1].url).toBe(
            '/api/v1/admin/host-allowlist/e-1/dependents',
        );
        const dialog = $('[role="alertdialog"]');
        expect(dialog).not.toBeNull();
        expect(dialog!.textContent).toContain(
            hostAllowlistLabels.removeTitle('api.example.com'),
        );
        expect(dialog!.textContent).toContain('Sales API, Support API');
        expect(dialog!.textContent).toContain(
            'will be blocked on the next call',
        );
        expect(document.activeElement?.textContent?.trim()).toBe('Cancel');

        await click(
            Array.from(dialog!.querySelectorAll('button')).find(
                (b) => b.textContent?.trim() === 'Cancel',
            ) ?? null,
        );
        expect($('[role="alertdialog"]')).toBeNull();
        expect(writes()).toHaveLength(0);

        replies.push(ok({ data: [] }));
        await click($('[data-test="remove-host"]'));
        expect($('[role="alertdialog"]')?.textContent).toContain(
            hostAllowlistLabels.removeNoDependents,
        );

        replies.push(ok({ data: { entry_id: 'e-1' }, meta: { revision: 7 } }));
        replies.push(ok(list([], { revision: 7 })));
        await click(
            $$('[role="alertdialog"] button').find((b) =>
                b.textContent?.includes('Remove api.example.com'),
            ) ?? null,
        );

        expect(writes()[0]).toMatchObject({
            method: 'DELETE',
            url: '/api/v1/admin/host-allowlist/e-1?revision=6',
        });
        expect(polite()?.textContent).toContain(
            hostAllowlistLabels.removed('api.example.com'),
        );
        expect($('[data-slot="list-empty"]')).not.toBeNull();
    });

    it('says so in the dialog when the dependents cannot be checked', async () => {
        replies = [ok(list([entry()]))];
        await mountPage();

        replies.push(refused(500));
        await click($('[data-test="remove-host"]'));

        expect($('[role="alertdialog"]')?.textContent).toContain(
            hostAllowlistLabels.removeDependentsFailed,
        );
    });

    it('shows the fresh list and an error toast when a removal meets a stale list (409)', async () => {
        replies = [ok(list([entry()], { revision: 1 }))];
        await mountPage();

        replies.push(ok({ data: [] }));
        await click($('[data-test="remove-host"]'));

        replies.push(
            refused(409, {
                error: { code: 'connector.revision_conflict' },
                current: list(
                    [
                        entry(),
                        entry({ entry_id: 'e-2', host: 'b.example.com' }),
                    ],
                    { revision: 2 },
                ),
            }),
        );
        await click(
            $$('[role="alertdialog"] button').find((b) =>
                b.textContent?.includes('Remove api.example.com'),
            ) ?? null,
        );

        expect($$('tbody [data-slot="data-row"]')).toHaveLength(2);
        expect(
            useToasts().items.some(
                (toast) => toast.message === hostAllowlistLabels.conflictRemove,
            ),
        ).toBe(true);
    });

    it('refreshes with a message when the entry is already gone (404)', async () => {
        replies = [ok(list([entry()]))];
        await mountPage();

        replies.push(refused(404));
        replies.push(ok(list([], { revision: 2 })));
        await click($('[data-test="remove-host"]'));

        expect(
            useToasts().items.some(
                (toast) => toast.message === hostAllowlistLabels.gone,
            ),
        ).toBe(true);
        expect($('[data-slot="list-empty"]')).not.toBeNull();
    });
});
