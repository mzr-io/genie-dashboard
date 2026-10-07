// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import { createCatalogue } from '../../resources/js/lib/i18n';
import type { Member } from '../../resources/js/lib/members';
import en from '../../resources/js/locales/en';
import { statusLabels } from '../../resources/js/locales/labels';
import { isPersistent, useToasts } from '../../resources/js/stores/toasts';

// Story 1.24: Deactivate and Reactivate on a member row, the alertdialog naming the member with focus on Cancel, the
// highlighted and focused row and the polite `saved` after a save, and the rollback toast and inline reasons on failure.
const page = vi.hoisted(() => ({
    shell: {
        membership_id: 'm-ada' as string | null,
        can: { 'users.manage': true },
        items: [],
    },
}));

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
            url: '/admin/users',
            props: { shell: page.shell },
        }),
    };
});

const { default: Users } =
    await import('../../resources/js/pages/admin/Users.vue');

function person(overrides: Partial<Member> = {}): Member {
    return {
        kind: 'member',
        membership_id: 'm-bo',
        name: 'Bo Builder',
        email: 'bo@example.test',
        role: 'admin',
        status: 'active',
        groups: [],
        last_active_at: null,
        permissions: ['blocks.edit'],
        revision: 3,
        ...overrides,
    };
}

const ada = person({
    membership_id: 'm-ada',
    name: 'Ada Admin',
    email: 'ada@example.test',
    permissions: ['users.manage'],
    revision: 1,
});

const invited: Member = {
    kind: 'invitation',
    invitation_id: 'i-1',
    name: '',
    email: 'new@example.test',
    role: 'user',
    status: 'invited',
    groups: [],
    last_active_at: null,
};

const list = (data: Member[]) => ({
    data,
    meta: {
        per_page: 15,
        next_cursor: null,
        total: data.length,
        matched: data.length,
        sort: 'name',
        direction: 'asc',
    },
});

type Reply = { status: number; json?: unknown };
type Call = { url: string; method: string; body: unknown };
const calls: Call[] = [];
let replies: Reply[] = [];
let wrapper: VueWrapper | null = null;

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const writes = () => calls.filter((call) => call.method !== 'GET');
const action = (key = 'm-bo') =>
    $(`[data-test="status-action"][data-member="${key}"]`);
const toasts = () => useToasts().items;

async function mountPage(members: Member[] = [ada, person(), invited]) {
    replies = [{ status: 200, json: list(members) }];
    wrapper = mount(Users, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
}

async function click(el: Element | null) {
    el?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await flushPromises();
}

const dialogButton = (label: string) =>
    $$('[role="alertdialog"] button').find((b) =>
        b.textContent?.includes(label),
    ) ?? null;

beforeEach(() => {
    page.shell.membership_id = 'm-ada';
    setActivePinia(createPinia());
    calls.length = 0;
    document.cookie = 'XSRF-TOKEN=abc%20123';
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: RequestInit) => {
            const next = replies.shift();

            calls.push({
                url,
                method: init?.method ?? 'GET',
                body: init?.body ? JSON.parse(init.body as string) : null,
            });

            if (!next) {
                throw new Error('unexpected request');
            }

            return {
                ok: next.status >= 200 && next.status < 300,
                status: next.status,
                json: async () => next.json ?? null,
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

describe('the row actions', () => {
    it("offers Deactivate on active members and Reactivate on deactivated ones, never on the Admin's own row or an invitation", async () => {
        await mountPage([
            ada,
            person(),
            person({
                membership_id: 'm-cy',
                name: 'Cy Clerk',
                email: 'cy@example.test',
                status: 'deactivated',
            }),
            invited,
        ]);

        expect(action('m-bo')!.textContent?.trim()).toBe(
            statusLabels.deactivate,
        );
        expect(action('m-cy')!.textContent?.trim()).toBe(
            statusLabels.reactivate,
        );
        expect(action('m-ada')).toBeNull();
        expect($$('[data-test="status-action"]')).toHaveLength(2);
        // The invitation row keeps Resend and Revoke.
        expect($('[data-test="resend"]')).not.toBeNull();
        expect($('[data-test="revoke"]')).not.toBeNull();
    });

    it("offers neither action while the person's own membership is unknown (fails closed)", async () => {
        page.shell.membership_id = null;
        await mountPage();

        expect($$('[data-test="status-action"]')).toHaveLength(0);
    });
});

describe('Deactivate', () => {
    it('confirms in an alertdialog that names the member and the impact, focus on Cancel, and sends nothing on Cancel', async () => {
        await mountPage();
        await click(action());

        const dialog = $('[role="alertdialog"]');
        expect(dialog).not.toBeNull();
        expect(dialog!.textContent).toContain(
            statusLabels.dialogTitle('Bo Builder'),
        );
        expect(dialog!.textContent).toContain(
            statusLabels.dialogImpact('Bo Builder'),
        );
        expect(document.activeElement?.textContent?.trim()).toBe('Cancel');

        await click(dialogButton('Cancel'));

        expect($('[role="alertdialog"]')).toBeNull();
        expect(writes()).toHaveLength(0);
    });

    it('sends the revision, refreshes the list, highlights and focuses the row and announces saved', async () => {
        await mountPage();
        await click(action());

        replies.push({
            status: 200,
            json: {
                data: {
                    kind: 'member',
                    membership_id: 'm-bo',
                    status: 'deactivated',
                    revision: 4,
                },
            },
        });
        replies.push({
            status: 200,
            json: list([
                ada,
                person({ status: 'deactivated', revision: 4 }),
                invited,
            ]),
        });
        await click(dialogButton('Deactivate Bo Builder'));

        expect(writes()).toEqual([
            {
                url: '/api/v1/admin/members/m-bo/deactivate',
                method: 'POST',
                body: { revision: 3 },
            },
        ]);
        const row = $('[data-row-key="m-bo"]')!;
        expect(row.getAttribute('data-highlighted')).toBe('true');
        expect(document.activeElement).toBe(row);
        expect(row.querySelector('[data-status]')!.textContent).toContain(
            'Deactivated',
        );
        expect(
            $('[data-announcer="polite"]')?.textContent ??
                $('[role="status"]')?.textContent ??
                '',
        ).toContain(en.saved);
        // Only the saved row is highlighted, and it now offers Reactivate.
        expect($$('[data-highlighted="true"]')).toHaveLength(1);
        expect(action()!.textContent?.trim()).toBe(statusLabels.reactivate);
        expect(toasts()).toHaveLength(0);
    });

    it('rolls the row back and shows a rollback toast that is not auto-dismissed when the request fails', async () => {
        await mountPage();
        await click(action());

        replies.push({ status: 500, json: null });
        await click(dialogButton('Deactivate Bo Builder'));

        const row = $('[data-row-key="m-bo"]')!;
        expect(row.querySelector('[data-status]')!.textContent).toContain(
            'Active',
        );
        expect(action()!.textContent?.trim()).toBe(statusLabels.deactivate);
        expect(row.getAttribute('data-highlighted')).toBeNull();
        expect(toasts()).toHaveLength(1);
        expect(toasts()[0].kind).toBe('rollback');
        expect(toasts()[0].message).toBe(
            statusLabels.rollback('deactivate', 'Bo Builder'),
        );
        expect(isPersistent(toasts()[0].kind)).toBe(true);
    });

    it('shows the last-holder reason inline with the rollback toast on 409', async () => {
        await mountPage();
        await click(action());

        replies.push({
            status: 409,
            json: { error: { code: 'access.last_users_manage_holder' } },
        });
        await click(dialogButton('Deactivate Bo Builder'));

        expect($('[data-test="status-reason"]')!.textContent).toContain(
            statusLabels.lastHolder,
        );
        expect(action()!.textContent?.trim()).toBe(statusLabels.deactivate);
        expect(toasts()[0].kind).toBe('rollback');
    });

    it('shows the self reason inline on 403 and reloads on a stale revision', async () => {
        await mountPage();
        await click(action());
        replies.push({
            status: 403,
            json: { error: { code: 'access.self_change_forbidden' } },
        });
        await click(dialogButton('Deactivate Bo Builder'));

        expect($('[data-test="status-reason"]')!.textContent).toContain(
            statusLabels.self,
        );

        await click(action());
        replies.push({
            status: 409,
            json: {
                error: { code: 'access.revision_conflict' },
                current: {
                    role: 'admin',
                    permissions: [],
                    revision: 9,
                    status: 'deactivated',
                },
            },
        });
        replies.push({
            status: 200,
            json: list([
                ada,
                person({ status: 'deactivated', revision: 9 }),
                invited,
            ]),
        });
        await click(dialogButton('Deactivate Bo Builder'));

        expect($('[data-test="status-reason"]')!.textContent).toContain(
            statusLabels.conflict,
        );
        expect(calls.filter((call) => call.method === 'GET')).toHaveLength(2);
        expect(action()!.textContent?.trim()).toBe(statusLabels.reactivate);
    });
});

describe('Reactivate', () => {
    it('reactivates without a dialog, highlights the row and announces saved', async () => {
        await mountPage([
            ada,
            person({ status: 'deactivated', revision: 4 }),
            invited,
        ]);

        replies.push({
            status: 200,
            json: {
                data: {
                    kind: 'member',
                    membership_id: 'm-bo',
                    status: 'active',
                    revision: 5,
                },
            },
        });
        replies.push({
            status: 200,
            json: list([ada, person({ revision: 5 }), invited]),
        });
        await click(action());

        expect($('[role="alertdialog"]')).toBeNull();
        expect(writes()).toEqual([
            {
                url: '/api/v1/admin/members/m-bo/reactivate',
                method: 'POST',
                body: { revision: 4 },
            },
        ]);
        expect(
            $('[data-row-key="m-bo"]')!.getAttribute('data-highlighted'),
        ).toBe('true');
        expect(action()!.textContent?.trim()).toBe(statusLabels.deactivate);
    });

    it('rolls back to Deactivated with a rollback toast when the request fails', async () => {
        await mountPage([
            ada,
            person({ status: 'deactivated', revision: 4 }),
            invited,
        ]);

        replies.push({ status: 500, json: null });
        await click(action());

        expect(action()!.textContent?.trim()).toBe(statusLabels.reactivate);
        expect(toasts()[0].kind).toBe('rollback');
        expect(toasts()[0].message).toBe(
            statusLabels.rollback('reactivate', 'Bo Builder'),
        );
    });
});

describe('refusals and edge cases', () => {
    async function deactivateWith(reply: Reply, ...after: Reply[]) {
        await click(action());
        replies.push(reply, ...after);
        await click(dialogButton('Deactivate Bo Builder'));
    }

    it.each([401, 419])(
        'goes to sign-in on %i with no rollback toast',
        async (status) => {
            const assign = vi.fn();
            vi.stubGlobal('location', { assign });
            await mountPage();
            await deactivateWith({ status, json: null });

            expect(assign).toHaveBeenCalledTimes(1);
            expect(toasts()).toHaveLength(0);
        },
    );

    it('shows the gone toast and reloads the list on 404', async () => {
        await mountPage();
        await deactivateWith(
            { status: 404, json: null },
            { status: 200, json: list([ada, invited]) },
        );

        expect(toasts()[0].message).toBe(statusLabels.gone);
        expect(calls.filter((call) => call.method === 'GET')).toHaveLength(2);
        expect(action('m-bo')).toBeNull();
    });

    it('rolls back with the forbidden reason on a plain 403', async () => {
        await mountPage();
        await deactivateWith({ status: 403, json: { error: { code: 'x' } } });

        expect($('[data-test="status-reason"]')!.textContent).toContain(
            statusLabels.forbidden,
        );
        expect(action()!.textContent?.trim()).toBe(statusLabels.deactivate);
        expect(calls.filter((call) => call.method === 'GET')).toHaveLength(1);
    });

    it('reloads on 403 access.not_authorized', async () => {
        await mountPage();
        await deactivateWith(
            {
                status: 403,
                json: { error: { code: 'access.not_authorized' } },
            },
            { status: 200, json: list([ada, person(), invited]) },
        );

        expect(calls.filter((call) => call.method === 'GET')).toHaveLength(2);
        expect(toasts()[0].kind).toBe('rollback');
    });

    it('shows the throttled message on 429', async () => {
        await mountPage();
        await deactivateWith({ status: 429, json: null });

        expect($('[data-test="status-reason"]')!.textContent).toContain(
            en.throttled,
        );
        expect(toasts()[0].message).toBe(en.throttled);
        expect(action()!.textContent?.trim()).toBe(statusLabels.deactivate);
    });

    it('ignores a second click while the row is busy', async () => {
        await mountPage([
            ada,
            person({ status: 'deactivated', revision: 4 }),
            invited,
        ]);
        replies.push(
            {
                status: 200,
                json: { data: { status: 'active', revision: 5 } },
            },
            {
                status: 200,
                json: list([ada, person({ revision: 5 }), invited]),
            },
        );
        const button = action()!;
        button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();

        expect(writes()).toHaveLength(1);
    });

    it('shows a rollback toast, not silence, when the row has no revision', async () => {
        await mountPage([
            ada,
            person({ status: 'deactivated', revision: undefined }),
            invited,
        ]);
        await click(action());

        expect(writes()).toHaveLength(0);
        expect(toasts()[0].kind).toBe('rollback');
    });

    it('announces saved with the name and keeps the new status when the refresh fails', async () => {
        await mountPage();
        await click(action());
        replies.push(
            {
                status: 200,
                json: { data: { status: 'deactivated', revision: 4 } },
            },
            { status: 500, json: null },
        );
        await click(dialogButton('Deactivate Bo Builder'));

        expect(
            $('[data-announcer="polite"]')?.textContent ??
                $('[role="status"]')?.textContent ??
                '',
        ).toContain(statusLabels.deactivated('Bo Builder'));
        expect(toasts()).toHaveLength(0);
        expect($('[data-highlighted="true"]')).toBeNull();
    });

    it('clears the highlight when the row loses focus', async () => {
        await mountPage();
        await click(action());
        replies.push(
            {
                status: 200,
                json: { data: { status: 'deactivated', revision: 4 } },
            },
            {
                status: 200,
                json: list([
                    ada,
                    person({ status: 'deactivated', revision: 4 }),
                    invited,
                ]),
            },
        );
        await click(dialogButton('Deactivate Bo Builder'));
        const row = $('[data-row-key="m-bo"]')!;
        expect(row.getAttribute('data-highlighted')).toBe('true');

        row.dispatchEvent(new FocusEvent('blur'));
        await flushPromises();

        expect(row.getAttribute('data-highlighted')).toBeNull();
    });

    it('does not highlight a row that is gone after the refresh', async () => {
        await mountPage();
        await click(action());
        replies.push(
            {
                status: 200,
                json: { data: { status: 'deactivated', revision: 4 } },
            },
            { status: 200, json: list([ada, invited]) },
        );
        await click(dialogButton('Deactivate Bo Builder'));

        expect($('[data-highlighted="true"]')).toBeNull();
    });
});
