// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import { userListLabels } from '../../resources/js/locales/labels';
import type { Member } from '../../resources/js/lib/members';

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
}));

const { default: Users } =
    await import('../../resources/js/pages/admin/Users.vue');

function member(overrides: Partial<Member> = {}): Member {
    return {
        kind: 'member',
        membership_id: 'm-1',
        name: 'Ada Admin',
        email: 'ada@example.test',
        role: 'admin',
        status: 'active',
        groups: [],
        last_active_at: '2026-10-01T09:30:00Z',
        ...overrides,
    };
}

function body(
    data: Member[],
    meta: Partial<{
        total: number;
        matched: number;
        next_cursor: string | null;
    }> = {},
) {
    return {
        data,
        meta: {
            per_page: 15,
            next_cursor: null,
            total: data.length,
            matched: data.length,
            sort: 'name',
            direction: 'asc',
            ...meta,
        },
    };
}

type Reply = { ok: boolean; status: number; json: unknown };
const requests: string[] = [];
let replies: Array<Reply | Promise<Reply>> = [];

function ok(json: unknown): Reply {
    return { ok: true, status: 200, json };
}

let wrapper: VueWrapper | null = null;

async function mountPage() {
    wrapper = mount(Users, {
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

beforeEach(() => {
    requests.length = 0;
    replies = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string) => {
            requests.push(url);
            const next = replies.shift();

            if (!next) {
                throw new Error('unexpected request');
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

describe('User configuration', () => {
    it('shows the toolbar skeleton and 5 skeleton rows while loading', async () => {
        replies = [new Promise<Reply>(() => {})];
        mount(Users, {
            attachTo: document.body,
            global: { plugins: [createCatalogue()] },
        });
        await flushPromises();

        expect($('[data-state="loading"]')).not.toBeNull();
        expect($$('[data-slot="skeleton-row"]')).toHaveLength(5);
        expect($('table')).toBeNull();
    });

    it('lists members in a real table with a caption, scoped headers and sortable aria-sort buttons', async () => {
        replies = [
            ok(
                body([
                    member(),
                    member({
                        kind: 'invitation',
                        invitation_id: 'i-1',
                        membership_id: undefined,
                        name: '',
                        email: 'new@example.test',
                        role: 'user',
                        status: 'invited',
                        last_active_at: null,
                    }),
                    member({
                        membership_id: 'm-2',
                        name: 'Cy',
                        email: 'cy@example.test',
                        role: 'user',
                        status: 'deactivated',
                    }),
                ]),
            ),
        ];
        await mountPage();

        expect($('table caption')?.textContent?.trim()).toBe(
            userListLabels.caption,
        );
        const headers = $$('thead th');
        expect(headers).toHaveLength(6);
        expect(headers.every((th) => th.getAttribute('scope') === 'col')).toBe(
            true,
        );
        expect(headers[0].getAttribute('aria-sort')).toBe('ascending');
        expect(headers[1].getAttribute('aria-sort')).toBe('none');
        // Groups is not sortable: no button, no aria-sort.
        expect(headers[4].querySelector('button')).toBeNull();
        expect(headers[4].hasAttribute('aria-sort')).toBe(false);
        expect($$('thead button')).toHaveLength(5);

        const rows = $$('tbody tr');
        expect(rows).toHaveLength(3);
        expect(rows[0].querySelector('th')?.getAttribute('scope')).toBe('row');
        expect(rows[0].textContent).toContain('Ada Admin');
        expect(rows[0].textContent).toContain('Admin');
        expect(rows[0].textContent).toContain(userListLabels.noGroups);
        expect(rows[0].querySelector('time')?.getAttribute('datetime')).toBe(
            '2026-10-01T09:30:00Z',
        );
        // Status is a word with an icon, never colour alone.
        expect(rows[1].textContent).toContain('Invited');
        expect(rows[1].querySelector('[data-slot="tag"] svg')).not.toBeNull();
        expect(rows[2].textContent).toContain('Deactivated');
        expect(rows[1].querySelector('time')).toBeNull();
        expect(rows[1].textContent).toContain(userListLabels.noName);
        expect(requests[0]).toContain('/api/v1/admin/members?');
    });

    it('shows a Workspace with only the first Admin as that row, with Invite user disabled with its reason', async () => {
        replies = [ok(body([member()]))];
        await mountPage();

        expect($$('tbody tr')).toHaveLength(1);
        const invite = $('[data-test="invite-user"]')!;
        expect(invite.tagName).toBe('A');
        expect(invite.textContent?.trim()).toBe(userListLabels.invite);
        expect(invite.getAttribute('aria-disabled')).toBe('true');
        expect(invite.hasAttribute('href')).toBe(false);
        const reason = document.getElementById(
            invite.getAttribute('aria-describedby')!,
        );
        expect(reason?.textContent?.trim()).toBe(
            'Invitations arrive with the next release',
        );
    });

    it('shows msg:list-empty when there are no members at all', async () => {
        replies = [ok(body([]))];
        await mountPage();

        expect($('[data-slot="list-empty"]')?.textContent).toBe(
            'No users yet. Invite a user to start.',
        );
        expect($('table')).toBeNull();
        expect($('[data-test="invite-user"]')).not.toBeNull();
    });

    it('shows the failure row with Retry and loads again on Retry', async () => {
        replies = [{ ok: false, status: 500, json: {} }, ok(body([member()]))];
        await mountPage();

        expect($('[data-slot="load-failure"]')?.textContent).toContain(
            "We couldn't load users. Try again.",
        );

        $('[data-test="retry"]')!.click();
        await flushPromises();

        expect($('[data-slot="load-failure"]')).toBeNull();
        expect($$('tbody tr')).toHaveLength(1);
        expect(requests).toHaveLength(2);
    });

    it('treats a malformed answer as a failed load', async () => {
        replies = [ok({ nope: true })];
        await mountPage();

        expect($('[data-slot="load-failure"]')).not.toBeNull();
    });

    it('sorts on a header click: aria-sort updates, the order is announced politely and focus stays', async () => {
        replies = [
            ok(body([member()])),
            ok(body([member()])),
            ok(body([member()])),
        ];
        await mountPage();

        const email = $('[data-column="email"]')!;
        email.focus();
        email.click();
        await flushPromises();

        expect(requests[1]).toContain('sort=email');
        expect(requests[1]).toContain('direction=asc');
        expect($$('thead th')[1].getAttribute('aria-sort')).toBe('ascending');
        expect($$('thead th')[0].getAttribute('aria-sort')).toBe('none');
        expect(document.activeElement).toBe($('[data-column="email"]'));

        $('[data-column="email"]')!.click();
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 5));

        expect(requests[2]).toContain('direction=desc');
        expect($$('thead th')[1].getAttribute('aria-sort')).toBe('descending');
        expect(polite()?.textContent).toContain('Sorted by Email, descending');
    });

    it('searches after a pause, shows list-no-match with Clear search and announces "0 of n" politely', async () => {
        replies = [
            ok(
                body([
                    member(),
                    member({ membership_id: 'm-2', email: 'b@example.test' }),
                ]),
            ),
            ok(body([], { total: 2, matched: 0 })),
            ok(
                body([
                    member(),
                    member({ membership_id: 'm-2', email: 'b@example.test' }),
                ]),
            ),
        ];
        await mountPage();

        const search = $('[data-test="search"]') as HTMLInputElement;
        search.value = 'zzz';
        search.dispatchEvent(new Event('input'));
        await flushPromises();
        search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
        await flushPromises();

        expect(requests[1]).toContain('q=zzz');
        expect($('[data-slot="list-no-match"]')?.textContent).toContain(
            "No users match 'zzz'.",
        );
        expect($('table')).toBeNull();
        expect($('[data-test="count"]')?.textContent?.trim()).toBe(
            '0 of 2 users',
        );

        await new Promise((resolve) => setTimeout(resolve, 650));
        expect(polite()?.textContent).toContain('0 of 2 users');

        $('[data-test="clear-search"]')!.click();
        await flushPromises();

        expect(requests[2]).not.toContain('q=');
        expect($$('tbody tr')).toHaveLength(2);
        expect(($('[data-test="search"]') as HTMLInputElement).value).toBe('');
    });

    it('pages by cursor and announces a page change politely', async () => {
        replies = [
            ok(body([member()], { total: 2, next_cursor: 'abc' })),
            ok(
                body(
                    [member({ membership_id: 'm-2', email: 'b@example.test' })],
                    {
                        total: 2,
                    },
                ),
            ),
            ok(body([member()], { total: 2, next_cursor: 'abc' })),
        ];
        await mountPage();

        expect(
            ($('[data-test="previous"]') as HTMLButtonElement).disabled,
        ).toBe(true);

        $('[data-test="next"]')!.click();
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 5));

        expect(requests[1]).toContain('cursor=abc');
        expect(polite()?.textContent).toContain('Page 2, 1 user');
        expect(($('[data-test="next"]') as HTMLButtonElement).disabled).toBe(
            true,
        );

        $('[data-test="previous"]')!.click();
        await flushPromises();

        expect(requests[2]).not.toContain('cursor=');
    });

    it('renders user text as escaped text', async () => {
        replies = [
            ok(body([member({ name: '<img src=x onerror=alert(1)>' })])),
        ];
        await mountPage();

        expect(document.querySelector('tbody img')).toBeNull();
        expect($('tbody')!.textContent).toContain('<img src=x');
    });

    it('keeps the toolbar mounted while loading and after a failure, with the typed value and focus', async () => {
        replies = [
            ok(
                body([
                    member(),
                    member({ membership_id: 'm-2', email: 'b@example.test' }),
                ]),
            ),
            { ok: false, status: 500, json: {} },
            ok(body([member()])),
        ];
        await mountPage();

        const search = $('[data-test="search"]') as HTMLInputElement;
        search.focus();
        search.value = 'ada';
        search.dispatchEvent(new Event('input'));
        await flushPromises();
        search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
        await flushPromises();

        expect($('[data-slot="load-failure"]')).not.toBeNull();
        expect($('[data-test="search"]')).toBe(search);
        expect(search.value).toBe('ada');
        expect(document.activeElement).toBe(search);

        $('[data-test="retry"]')!.click();
        await flushPromises();
        expect($('[data-test="search"]')).toBe(search);
        expect($$('tbody tr')).toHaveLength(1);
    });

    it('keeps the toolbar while the first load is pending', async () => {
        replies = [new Promise<Reply>(() => {})];
        wrapper = mount(Users, {
            attachTo: document.body,
            global: { plugins: [createCatalogue()] },
        });
        await flushPromises();

        expect($('[data-test="search"]')).not.toBeNull();
        expect($('[data-state="loading"]')).not.toBeNull();
        expect($$('[data-slot="skeleton-row"]')).toHaveLength(5);
    });

    it('shows the primary Invite user action once, inside the empty region', async () => {
        replies = [ok(body([]))];
        await mountPage();

        expect($$('[data-test="invite-user"]')).toHaveLength(1);
        expect(
            $('[data-slot="list-states"]')!.contains(
                $('[data-test="invite-user"]'),
            ),
        ).toBe(true);
        expect($('[data-test="search"]')).toBeNull();
    });

    it('announces the Invite user reason for Enter and Space without scrolling', async () => {
        replies = [ok(body([member()]))];
        await mountPage();

        const invite = $('[data-test="invite-user"]')!;

        for (const key of ['Enter', ' ']) {
            const event = new KeyboardEvent('keydown', {
                key,
                bubbles: true,
                cancelable: true,
            });
            invite.dispatchEvent(event);
            expect(event.defaultPrevented).toBe(true);
        }

        await new Promise((resolve) => setTimeout(resolve, 5));
        expect(polite()?.textContent).toContain(
            'Invitations arrive with the next release',
        );
    });

    it('announces the sort order only after the load succeeds', async () => {
        let release!: (reply: Reply) => void;
        replies = [
            ok(body([member()])),
            new Promise<Reply>((resolve) => {
                release = resolve;
            }),
        ];
        await mountPage();

        $('[data-column="email"]')!.click();
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 5));
        expect(polite()?.textContent ?? '').not.toContain('Sorted by');

        release(ok(body([member()])));
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 5));
        expect(polite()?.textContent).toContain('Sorted by Email, ascending');

        replies = [{ ok: false, status: 500, json: {} }];
        $('[data-column="name"]')!.click();
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 5));
        expect(polite()?.textContent).not.toContain('Sorted by Name');
    });

    it('goes back to the first page when a later page comes back empty', async () => {
        replies = [
            ok(body([member()], { total: 2, next_cursor: 'abc' })),
            ok(body([], { total: 2, matched: 2 })),
            ok(body([member()], { total: 2 })),
        ];
        await mountPage();

        $('[data-test="next"]')!.click();
        await flushPromises();

        expect(requests[1]).toContain('cursor=abc');
        expect(requests[2]).not.toContain('cursor=');
        expect($$('tbody tr')).toHaveLength(1);
        expect($('[data-slot="pagination"]')).toBeNull();
    });

    it('sends 401 and 419 to sign-in', async () => {
        for (const status of [401, 419]) {
            const assign = vi.fn();
            vi.stubGlobal('location', { assign });
            replies = [{ ok: false, status, json: {} }];
            await mountPage();

            expect(assign).toHaveBeenCalledWith('/login');
            expect($('[data-slot="load-failure"]')).toBeNull();
            wrapper?.unmount();
            wrapper = null;
            document.body.innerHTML = '';
        }
    });

    it('shows perm-denied for a 403 instead of the generic failure', async () => {
        replies = [{ ok: false, status: 403, json: {} }];
        await mountPage();

        expect($('[data-slot="perm-denied"]')?.textContent?.trim()).toBe(
            "You don't have permission to view this. Ask a workspace admin.",
        );
        expect($('[data-slot="load-failure"]')).toBeNull();
    });

    it('uses singular and plural words from the labels', async () => {
        expect(userListLabels.count(1, 1)).toBe('1 of 1 user');
        expect(userListLabels.count(0, 3)).toBe('0 of 3 users');
        expect(userListLabels.pageChanged(2, 1)).toBe('Page 2, 1 user');
        expect(userListLabels.pageChanged(1, 15)).toBe('Page 1, 15 users');
        expect(userListLabels.sorted('Name', true)).toBe(
            'Sorted by Name, descending',
        );
    });

    it('makes the scrollable table a labelled, focusable region', async () => {
        replies = [ok(body([member()]))];
        await mountPage();

        const region = $('[data-slot="data-table"]')!;
        expect(region.getAttribute('role')).toBe('region');
        expect(region.getAttribute('tabindex')).toBe('0');
        expect(region.getAttribute('aria-label')).toBe(
            userListLabels.tableRegion,
        );
    });

    it('drops the cursor when sorting from page 2 and when searching from page 2', async () => {
        replies = [
            ok(body([member()], { total: 3, next_cursor: 'abc' })),
            ok(body([member()], { total: 3, next_cursor: 'def' })),
            ok(body([member()], { total: 3, next_cursor: 'ghi' })),
            ok(body([member()], { total: 3, next_cursor: 'abc' })),
            ok(body([member()], { total: 3, matched: 1 })),
        ];
        await mountPage();

        $('[data-test="next"]')!.click();
        await flushPromises();
        expect(requests[1]).toContain('cursor=abc');

        $('[data-column="email"]')!.click();
        await flushPromises();
        expect(requests[2]).not.toContain('cursor=');
        expect($('[data-slot="pagination"]')?.textContent).toContain('Page 1');

        $('[data-test="next"]')!.click();
        await flushPromises();
        expect(requests[3]).toContain('cursor=ghi');

        const search = $('[data-test="search"]') as HTMLInputElement;
        search.value = 'ada';
        search.dispatchEvent(new Event('input'));
        await flushPromises();
        search.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
        await flushPromises();
        expect(requests[4]).toContain('q=ada');
        expect(requests[4]).not.toContain('cursor=');
    });
});
