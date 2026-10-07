// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { Group } from '../../resources/js/lib/groups';
import { createCatalogue } from '../../resources/js/lib/i18n';
import type { Member } from '../../resources/js/lib/members';
import en from '../../resources/js/locales/en';
import { groupLabels } from '../../resources/js/locales/labels';

// Story 1.23: the Groups view of User configuration (UX-DR-261, 263): a sortable data table with search and the generic
// list states, an inline create form, an inline editor (rename, members with Remove, member search with Add) and the
// delete alertdialog that states the member count; the member list shows each member's groups.
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
            url: '/admin/users/groups',
            props: {
                shell: {
                    membership_id: 'm-ada',
                    can: { 'users.manage': true },
                    items: [],
                },
            },
        }),
    };
});

const { default: UserGroups } =
    await import('../../resources/js/pages/admin/UserGroups.vue');
const { default: Users } =
    await import('../../resources/js/pages/admin/Users.vue');

function group(overrides: Partial<Group> = {}): Group {
    return {
        group_id: 'g-1',
        name: 'Sales',
        member_count: 1,
        members: [
            {
                membership_id: 'm-bo',
                name: 'Bo User',
                email: 'bo@example.test',
                status: 'active',
            },
        ],
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-01T09:30:00Z',
        ...overrides,
    };
}

function list(
    data: Group[],
    meta: Partial<{ total: number; matched: number }> = {},
) {
    return {
        data,
        meta: {
            total: data.length,
            matched: data.length,
            sort: 'name',
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

async function mountPage(component: unknown = UserGroups) {
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

describe('Groups view', () => {
    it('shows 5 skeleton rows while loading, then a real table with a caption, aria-sort and sortable headers', async () => {
        replies = [new Promise<Reply>(() => {})];
        await mountPage();

        expect($('[data-state="loading"]')).not.toBeNull();
        expect($$('[data-slot="skeleton-row"]')).toHaveLength(5);
        wrapper?.unmount();
        document.body.innerHTML = '';

        replies = [
            ok(
                list([
                    group(),
                    group({
                        group_id: 'g-2',
                        name: 'Support',
                        member_count: 0,
                        members: [],
                    }),
                ]),
            ),
        ];
        await mountPage();

        expect($('table caption')?.textContent?.trim()).toBe(
            groupLabels.caption,
        );
        const headers = $$('thead th');
        expect(headers).toHaveLength(4);
        expect(headers.every((th) => th.getAttribute('scope') === 'col')).toBe(
            true,
        );
        expect(headers[0].getAttribute('aria-sort')).toBe('ascending');
        expect(headers[1].getAttribute('aria-sort')).toBe('none');
        expect(headers[3].hasAttribute('aria-sort')).toBe(false);
        expect($$('tbody [data-slot="data-row"]')).toHaveLength(2);
        expect($$('tbody [data-slot="data-row"]')[0].textContent).toContain(
            'Sales',
        );
        expect($('time')?.getAttribute('datetime')).toBe(
            '2026-10-01T09:30:00Z',
        );
        expect(calls[0].url).toContain('/api/v1/admin/groups?');
    });

    it('sorts by a header: aria-sort updates, the order is requested from the server and announced once it arrives', async () => {
        replies = [ok(list([group()]))];
        await mountPage();

        replies.push(ok(list([group()])));
        await click($('[data-column="members"]'));

        expect(calls[1].url).toContain('sort=members');
        expect(calls[1].url).toContain('direction=asc');
        expect($$('thead th')[1].getAttribute('aria-sort')).toBe('ascending');
        expect(polite()?.textContent).toContain(
            groupLabels.sorted('Members', false),
        );

        replies.push(ok(list([group()])));
        await click($('[data-column="members"]'));
        expect(calls[2].url).toContain('direction=desc');
        expect($$('thead th')[1].getAttribute('aria-sort')).toBe('descending');
    });

    it('shows list-empty with a primary "Create group" action when there are no groups', async () => {
        replies = [ok(list([]))];
        await mountPage();

        expect($('[data-slot="list-empty"]')?.textContent).toBe(
            'No groups yet. Create a group to start.',
        );
        const action = $('[data-state="empty"] [data-test="create-group"]');
        expect(action?.textContent?.trim()).toBe(groupLabels.create);
        expect($('table')).toBeNull();
        expect($('[role="search"]')).toBeNull();

        await click(action);
        expect($('[data-test="create-group-form"]')).not.toBeNull();
        expect(document.activeElement).toBe(
            $('[data-test="create-group-name"]'),
        );
    });

    it('shows list-no-match with Clear search and announces "0 of n" politely', async () => {
        vi.useFakeTimers();
        replies = [ok(list([group()]))];
        await mountPage();

        replies.push(ok(list([], { total: 1, matched: 0 })));
        await type('[data-test="search"]', 'zzz');
        await vi.advanceTimersByTimeAsync(400);
        await flushPromises();

        expect(calls[1].url).toContain('q=zzz');
        expect($('[data-slot="list-no-match"]')?.textContent).toContain(
            "No groups match 'zzz'.",
        );
        expect($('[data-test="count"]')?.textContent?.trim()).toBe(
            '0 of 1 group',
        );
        await vi.advanceTimersByTimeAsync(2000);
        expect(polite()?.textContent).toContain('0 of 1 group');

        replies.push(ok(list([group()])));
        await click($('[data-test="clear-search"]'));
        expect($('table')).not.toBeNull();
        expect(document.activeElement).toBe($('[data-test="search"]'));
    });

    it('shows the failure row with Retry, and Retry loads again', async () => {
        replies = [refused(500)];
        await mountPage();

        expect($('[data-slot="load-failure"]')?.textContent).toContain(
            "We couldn't load groups. Try again.",
        );

        replies.push(ok(list([group()])));
        await click($('[data-test="retry"]'));
        expect($('table')).not.toBeNull();
    });

    it('shows perm-denied for a 403', async () => {
        replies = [refused(403)];
        await mountPage();

        expect($('[data-slot="perm-denied"]')).not.toBeNull();
        expect($('table')).toBeNull();
    });

    it('creates a group inline (no modal): a field error for a duplicate, then success announced politely', async () => {
        replies = [ok(list([group()]))];
        await mountPage();

        await click($('[data-test="create-group"]'));
        expect($('[role="dialog"], [role="alertdialog"]')).toBeNull();
        expect(
            $('[data-test="create-group"]')?.getAttribute('aria-expanded'),
        ).toBe('true');

        // Empty: a field error, nothing sent.
        await click($('[data-test="create-group-save"]'));
        expect(
            $(
                '#' +
                    ($('[data-test="create-group-name"]') as HTMLElement).id +
                    '-error',
            )?.textContent,
        ).toContain(groupLabels.nameRequired);
        expect(writes()).toHaveLength(0);

        replies.push(
            refused(422, { errors: { name: ['taken'] }, reason: 'name_taken' }),
        );
        await type('[data-test="create-group-name"]', ' sales ');
        await click($('[data-test="create-group-save"]'));
        expect(writes()[0]).toMatchObject({
            method: 'POST',
            url: '/api/v1/admin/groups',
            body: { name: 'sales' },
        });
        expect($('[data-test="create-group-form"]')?.textContent).toContain(
            groupLabels.nameTaken,
        );
        expect(document.activeElement).toBe(
            $('[data-test="create-group-name"]'),
        );

        replies.push(
            ok({
                data: group({
                    group_id: 'g-new',
                    name: 'Ops',
                    member_count: 0,
                    members: [],
                }),
            }),
        );
        replies.push(
            ok(
                list([
                    group(),
                    group({
                        group_id: 'g-new',
                        name: 'Ops',
                        member_count: 0,
                        members: [],
                    }),
                ]),
            ),
        );
        await type('[data-test="create-group-name"]', 'Ops');
        await click($('[data-test="create-group-save"]'));

        expect(writes()[1].body).toEqual({ name: 'Ops' });
        expect($('[data-test="create-group-form"]')).toBeNull();
        expect(polite()?.textContent).toContain(groupLabels.created('Ops'));
        // The new group's editor opens on it.
        expect($('[data-slot="group-editor"]')?.textContent).toContain('Ops');
    });

    it('expands the inline editor under a row with aria-expanded and aria-controls', async () => {
        replies = [ok(list([group()]))];
        await mountPage();

        const button = $('[data-test="manage-group"]')!;
        expect(button.getAttribute('aria-expanded')).toBe('false');
        await click(button);

        expect(button.getAttribute('aria-expanded')).toBe('true');
        expect(button.getAttribute('aria-controls')).toBe('detail-g-1');
        expect($('#detail-g-1 [data-slot="group-editor"]')).not.toBeNull();
        expect(document.activeElement).toBe($('[data-test="group-title"]'));
        expect($('[data-test="group-members"]')?.textContent).toContain(
            'Bo User',
        );

        button.focus();
        await click(button);
        expect($('[data-slot="group-editor"]')).toBeNull();
        expect(document.activeElement).toBe(button);
    });

    it('renames a group: a duplicate is a field error, success is announced and updates the row', async () => {
        replies = [ok(list([group()]))];
        await mountPage();
        await click($('[data-test="manage-group"]'));

        replies.push(
            refused(422, { errors: { name: ['taken'] }, reason: 'name_taken' }),
        );
        await type('[data-test="group-rename-name"]', 'Support');
        await click($('[data-test="group-rename-save"]'));
        expect(writes()[0]).toMatchObject({
            method: 'PATCH',
            url: '/api/v1/admin/groups/g-1',
            body: { name: 'Support' },
        });
        expect($('[data-slot="group-editor"]')?.textContent).toContain(
            groupLabels.nameTaken,
        );

        replies.push(ok({ data: group({ name: 'Revenue' }) }));
        replies.push(ok(list([group({ name: 'Revenue' })])));
        await type('[data-test="group-rename-name"]', 'Revenue');
        await click($('[data-test="group-rename-save"]'));
        expect(polite()?.textContent).toContain(groupLabels.renamed('Revenue'));
        expect($('tbody [data-slot="data-row"]')?.textContent).toContain(
            'Revenue',
        );
    });

    it('removes a member and shows Deactivated for a deactivated member, who keeps their place', async () => {
        replies = [
            ok(
                list([
                    group({
                        member_count: 2,
                        members: [
                            {
                                membership_id: 'm-bo',
                                name: 'Bo User',
                                email: 'bo@example.test',
                                status: 'active',
                            },
                            {
                                membership_id: 'm-cy',
                                name: 'Cy User',
                                email: 'cy@example.test',
                                status: 'deactivated',
                            },
                        ],
                    }),
                ]),
            ),
        ];
        await mountPage();
        await click($('[data-test="manage-group"]'));

        const members = $$('[data-slot="group-member"]');
        expect(members).toHaveLength(2);
        expect(members[1].textContent).toContain(groupLabels.deactivated);
        expect(
            members[1].querySelector('[data-slot="tag"] svg'),
        ).not.toBeNull();
        expect(members[0].textContent).not.toContain(groupLabels.deactivated);

        const left = group({
            member_count: 1,
            members: [
                {
                    membership_id: 'm-cy',
                    name: 'Cy User',
                    email: 'cy@example.test',
                    status: 'deactivated',
                },
            ],
        });
        replies.push(ok({ data: left }));
        replies.push(ok(list([left])));
        await click(members[0].querySelector('[data-test="group-remove"]'));

        expect(writes()[0]).toMatchObject({
            method: 'DELETE',
            url: '/api/v1/admin/groups/g-1/members/m-bo',
        });
        expect($$('[data-slot="group-member"]')).toHaveLength(1);
        expect(polite()?.textContent).toContain(
            groupLabels.removed('Bo User', 'Sales'),
        );
        expect(document.activeElement).toBe($('[data-test="group-title"]'));
    });

    it('searches members to add (members only, not those already in the group) and adds one', async () => {
        vi.useFakeTimers();
        replies = [ok(list([group()]))];
        await mountPage();
        await click($('[data-test="manage-group"]'));

        const person = (
            id: string,
            name: string,
            extra: Partial<Member> = {},
        ): Member => ({
            kind: 'member',
            membership_id: id,
            name,
            email: `${name.toLowerCase()}@example.test`,
            role: 'user',
            status: 'active',
            groups: [],
            last_active_at: null,
            ...extra,
        });

        replies.push(
            ok({
                data: [
                    person('m-bo', 'Bo'),
                    person('m-cy', 'Cy'),
                    {
                        ...person('', 'Invitee'),
                        kind: 'invitation',
                        membership_id: undefined,
                        invitation_id: 'i-1',
                        status: 'invited',
                    },
                ],
                meta: {
                    per_page: 15,
                    next_cursor: null,
                    total: 3,
                    matched: 3,
                    sort: 'name',
                    direction: 'asc',
                },
            }),
        );
        await type('[data-test="group-member-search"]', 'c');
        await vi.advanceTimersByTimeAsync(400);
        await flushPromises();

        expect(calls[1].url).toContain('/api/v1/admin/members?');
        expect(calls[1].url).toContain('q=c');
        const candidates = $$('[data-slot="group-candidate"]');
        expect(candidates).toHaveLength(1);
        expect(candidates[0].textContent).toContain('Cy');
        await vi.advanceTimersByTimeAsync(2000);
        expect(polite()?.textContent).toContain(groupLabels.searchResults(1));

        const both = group({
            member_count: 2,
            members: [
                {
                    membership_id: 'm-bo',
                    name: 'Bo User',
                    email: 'bo@example.test',
                    status: 'active',
                },
                {
                    membership_id: 'm-cy',
                    name: 'Cy',
                    email: 'cy@example.test',
                    status: 'active',
                },
            ],
        });
        replies.push(ok({ data: both }));
        replies.push(ok(list([both])));
        await click(candidates[0].querySelector('[data-test="group-add"]'));

        expect(writes()[0]).toMatchObject({
            method: 'POST',
            url: '/api/v1/admin/groups/g-1/members/m-cy',
        });
        expect($$('[data-slot="group-member"]')).toHaveLength(2);
        expect($$('[data-slot="group-candidate"]')).toHaveLength(0);
        // Focus moves to the added member's Remove button.
        expect(document.activeElement).toBe(
            $('[data-test="group-remove"][data-member="m-cy"]'),
        );
        await vi.advanceTimersByTimeAsync(10);
        expect(polite()?.textContent).toContain(
            groupLabels.added('Cy', 'Sales'),
        );
    });

    it('deletes through an alertdialog that names the group and states the member count, focus on Cancel; Cancel sends nothing', async () => {
        replies = [ok(list([group({ member_count: 3 })]))];
        await mountPage();
        await click($('[data-test="manage-group"]'));
        await click($('[data-test="group-delete"]'));

        const dialog = $('[role="alertdialog"]');
        expect(dialog).not.toBeNull();
        expect(dialog!.textContent).toContain(groupLabels.deleteTitle('Sales'));
        expect(dialog!.textContent).toContain(
            groupLabels.deleteImpact('Sales', 3),
        );
        expect(dialog!.textContent).toContain('3 members');
        expect(document.activeElement?.textContent?.trim()).toBe('Cancel');

        await click(
            Array.from(dialog!.querySelectorAll('button')).find(
                (b) => b.textContent?.trim() === 'Cancel',
            ) ?? null,
        );
        expect($('[role="alertdialog"]')).toBeNull();
        expect(writes()).toHaveLength(0);

        await click($('[data-test="group-delete"]'));
        replies.push({ ok: true, status: 204, json: null });
        replies.push(ok(list([])));
        await click(
            $$('[role="alertdialog"] button').find((b) =>
                b.textContent?.includes('Delete group Sales'),
            ) ?? null,
        );

        expect(writes()[0]).toMatchObject({
            method: 'DELETE',
            url: '/api/v1/admin/groups/g-1',
        });
        expect(polite()?.textContent).toContain(groupLabels.deleted('Sales'));
        expect($('[data-slot="list-empty"]')).not.toBeNull();
    });

    it('closes the editor and reloads when the group no longer exists (404)', async () => {
        replies = [ok(list([group()]))];
        await mountPage();
        await click($('[data-test="manage-group"]'));

        replies.push(refused(404));
        replies.push(ok(list([])));
        await type('[data-test="group-rename-name"]', 'Gone');
        await click($('[data-test="group-rename-save"]'));

        expect($('[data-slot="group-editor"]')).toBeNull();
        expect($('[data-slot="list-empty"]')).not.toBeNull();
    });

    it('shows the save-failed message and keeps the editor on a server error', async () => {
        replies = [ok(list([group()]))];
        await mountPage();
        await click($('[data-test="manage-group"]'));

        replies.push(refused(500));
        await type('[data-test="group-rename-name"]', 'Other');
        await click($('[data-test="group-rename-save"]'));

        expect($('[data-test="group-failure"]')?.textContent).toContain(
            "We couldn't save your changes",
        );
        expect(document.activeElement).toBe($('[data-test="group-failure"]'));
    });
});

describe('Groups view, more', () => {
    const memberRow = (
        id: string,
        name: string,
        extra: Partial<Member> = {},
    ): Member => ({
        kind: 'member',
        membership_id: id,
        name,
        email: `${name.toLowerCase()}@example.test`,
        role: 'user',
        status: 'active',
        groups: [],
        last_active_at: null,
        ...extra,
    });
    const members = (data: Member[], next: string | null) =>
        ok({
            data,
            meta: {
                per_page: 15,
                next_cursor: next,
                total: 30,
                matched: 30,
                sort: 'name',
                direction: 'asc',
            },
        });

    async function openEditor(): Promise<void> {
        replies = [ok(list([group()]))];
        await mountPage();
        await click($('[data-test="manage-group"]'));
    }

    async function search(term: string): Promise<void> {
        vi.useFakeTimers();
        await type('[data-test="group-member-search"]', term);
        await vi.advanceTimersByTimeAsync(400);
        await flushPromises();
    }

    it('keeps reading following pages of matches until someone can be added', async () => {
        await openEditor();
        replies.push(members([memberRow('m-bo', 'Bo')], 'next-1'));
        replies.push(members([memberRow('m-cy', 'Cy')], null));
        await search('o');

        expect(calls[1].url).not.toContain('cursor=');
        expect(calls[2].url).toContain('cursor=next-1');
        expect($$('[data-slot="group-candidate"]')).toHaveLength(1);
        expect($('[data-test="group-search-none"]')).toBeNull();
    });

    it('says no members match only when the last page is read', async () => {
        await openEditor();
        replies.push(members([memberRow('m-bo', 'Bo')], 'next-1'));
        replies.push(members([memberRow('m-bo', 'Bo')], null));
        await search('o');

        expect(calls).toHaveLength(3);
        expect($('[data-test="group-search-none"]')?.textContent).toContain(
            groupLabels.searchNone,
        );
    });

    it('shows group-search-error when the member search fails', async () => {
        await openEditor();
        replies.push(refused(500));
        await search('o');

        expect($('[data-test="group-search-error"]')?.textContent).toContain(
            groupLabels.searchFailed,
        );
    });

    it('keeps a draft name when the group reloads, and announces an unchanged name', async () => {
        await openEditor();
        const input = $('[data-test="group-rename-name"]') as HTMLInputElement;

        // Unchanged: announced, nothing sent.
        await click($('[data-test="group-rename-save"]'));
        expect(writes()).toHaveLength(0);
        await vi.waitFor(() =>
            expect(polite()?.textContent).toContain(groupLabels.unchanged),
        );

        // A draft survives a reload that brings another name.
        await type('[data-test="group-rename-name"]', 'Draft');
        replies.push(ok({ data: group({ member_count: 2 }) }));
        replies.push(ok(list([group({ name: 'Elsewhere', member_count: 2 })])));
        await click($('[data-test="group-remove"]'));
        expect(input.value).toBe('Draft');
    });

    it('re-applies the sort and search with a reload after a member change', async () => {
        await openEditor();
        replies.push(ok({ data: group({ member_count: 0, members: [] }) }));
        replies.push(ok(list([group({ member_count: 0, members: [] })])));
        await click($('[data-test="group-remove"]'));

        expect(calls[calls.length - 1].method).toBe('GET');
        expect(calls[calls.length - 1].url).toContain('sort=name');
    });

    it('does not open the editor on a new group the reload did not bring, and shows the failure instead', async () => {
        replies = [ok(list([group()]))];
        await mountPage();
        await click($('[data-test="create-group"]'));
        replies.push(
            ok({
                data: group({
                    group_id: 'g-new',
                    name: 'Ops',
                    member_count: 0,
                    members: [],
                }),
            }),
        );
        replies.push(refused(500));
        await type('[data-test="create-group-name"]', 'Ops');
        await click($('[data-test="create-group-save"]'));

        expect($('[data-slot="group-editor"]')).toBeNull();
        expect($('[data-slot="load-failure"]')).not.toBeNull();
    });

    it('links back to User configuration with an Inertia link', async () => {
        replies = [ok(list([group()]))];
        await mountPage();

        expect($('[data-test="back-to-users"]')?.getAttribute('href')).toBe(
            '/admin/users',
        );
    });

    describe('refusals', () => {
        const assign = vi.fn();
        const reload = vi.fn();

        beforeEach(() => {
            assign.mockClear();
            reload.mockClear();
            vi.stubGlobal('location', { assign, reload, href: '/' });
        });

        const rename = async (reply: Reply, to = 'Other') => {
            await openEditor();
            replies.push(reply);
            await type('[data-test="group-rename-name"]', to);
            await click($('[data-test="group-rename-save"]'));
        };

        it('shows the throttled message for a 429', async () => {
            await rename(refused(429));
            expect($('[data-test="group-failure"]')?.textContent).toContain(
                en.throttled,
            );
        });

        it.each([401, 419])(
            'sends the person to sign in for a %i',
            async (status) => {
                await rename(refused(status));
                expect(assign).toHaveBeenCalledWith('/login');
            },
        );

        it('reloads the page for access.not_authorized', async () => {
            await rename(
                refused(403, { error: { code: 'access.not_authorized' } }),
            );
            expect(reload).toHaveBeenCalled();
        });

        it('closes the editor for a 404', async () => {
            replies = [];
            await openEditor();
            replies.push(refused(404));
            replies.push(ok(list([])));
            await type('[data-test="group-rename-name"]', 'Other');
            await click($('[data-test="group-rename-save"]'));
            expect($('[data-slot="group-editor"]')).toBeNull();
        });

        it('shows nameInvalid for a 422 without a name error text and nameTooLong over 64 characters', async () => {
            await rename(refused(422, { errors: { name: ['bad'] } }));
            expect($('[data-slot="group-editor"]')?.textContent).toContain(
                groupLabels.nameInvalid,
            );

            const before = writes().length;
            await type('[data-test="group-rename-name"]', 'x'.repeat(65));
            await click($('[data-test="group-rename-save"]'));
            expect($('[data-slot="group-editor"]')?.textContent).toContain(
                groupLabels.nameTooLong,
            );
            expect(writes()).toHaveLength(before);
        });

        it('in the create form: 429, sign-in redirect, 422 and a name over 64 characters', async () => {
            replies = [ok(list([group()]))];
            await mountPage();
            await click($('[data-test="create-group"]'));
            const submit = async (reply: Reply | null, name = 'Ops') => {
                if (reply) replies.push(reply);
                await type('[data-test="create-group-name"]', name);
                await click($('[data-test="create-group-save"]'));
            };

            await submit(refused(429));
            expect($('[data-test="create-failure"]')?.textContent).toContain(
                en.throttled,
            );

            await submit(refused(401));
            expect(assign).toHaveBeenCalledWith('/login');

            await submit(refused(422, { errors: { name: ['bad'] } }));
            expect($('[data-test="create-group-form"]')?.textContent).toContain(
                groupLabels.nameInvalid,
            );

            const before = writes().length;
            await submit(null, 'x'.repeat(65));
            expect($('[data-test="create-group-form"]')?.textContent).toContain(
                groupLabels.nameTooLong,
            );
            expect(writes()).toHaveLength(before);
        });
    });
});

describe('User configuration groups column', () => {
    it("shows each member's groups by name, and links to the Groups view", async () => {
        replies = [
            ok({
                data: [
                    {
                        kind: 'member',
                        membership_id: 'm-1',
                        name: 'Ada',
                        email: 'ada@example.test',
                        role: 'admin',
                        status: 'active',
                        groups: [
                            { id: 'g-1', name: 'Alpha' },
                            { id: 'g-2', name: 'Beta' },
                        ],
                        last_active_at: null,
                        permissions: [],
                        revision: 1,
                    },
                ],
                meta: {
                    per_page: 15,
                    next_cursor: null,
                    total: 1,
                    matched: 1,
                    sort: 'name',
                    direction: 'asc',
                },
            }),
        ];
        await mountPage(Users);

        expect($$('tbody tr')[0].textContent).toContain('Alpha, Beta');
        const link = $('[data-test="open-groups"]');
        expect(link?.tagName).toBe('A');
        expect(link?.getAttribute('href')).toBe('/admin/users/groups');
    });
});
