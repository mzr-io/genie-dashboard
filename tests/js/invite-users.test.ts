// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { useToasts } from '../../resources/js/stores/toasts';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    inviteLabels,
    userListLabels,
} from '../../resources/js/locales/labels';
import en from '../../resources/js/locales/en';
import type { Member } from '../../resources/js/lib/members';

// Story 1.21: the inline invite form, the role menu, the password prompt, the field errors, the delivery failure and
// the Resend and Revoke actions on an Invited row.
const held = ['users.manage', 'blocks.edit', 'audit.view'];

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    usePage: () => ({
        url: '/admin/users',
        props: {
            shell: {
                can: {
                    'users.manage': true,
                    'blocks.edit': true,
                    'audit.view': true,
                    'blocks.publish': false,
                },
                items: [],
            },
        },
    }),
}));

const { default: Users } =
    await import('../../resources/js/pages/admin/Users.vue');

function person(overrides: Partial<Member> = {}): Member {
    return {
        kind: 'member',
        membership_id: 'm-1',
        name: 'Ada Admin',
        email: 'ada@example.test',
        role: 'admin',
        status: 'active',
        groups: [],
        last_active_at: null,
        ...overrides,
    };
}

function invitation(overrides: Partial<Member> = {}): Member {
    return person({
        kind: 'invitation',
        invitation_id: 'i-1',
        membership_id: undefined,
        name: '',
        email: 'new@example.test',
        role: 'user',
        status: 'invited',
        ...overrides,
    });
}

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
type Call = { url: string; method: string; body: unknown; xsrf: string | null };
const calls: Call[] = [];
let replies: Reply[] = [];
let wrapper: VueWrapper | null = null;

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const writes = () => calls.filter((call) => call.method !== 'GET');

async function mountPage(
    first: Reply = { status: 200, json: list([person()]) },
) {
    replies = [first];
    wrapper = mount(Users, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
}

async function type(selector: string, value: string) {
    const input = $(selector) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function click(el: Element | null) {
    el?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await flushPromises();
}

async function submit() {
    $('[data-test="invite-form"]')!.dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }),
    );
    await flushPromises();
}

async function openForm() {
    await click($('[data-test="invite-user"]'));
}

async function chooseRole(role: 'user' | 'admin') {
    await click($('[data-test="role-button"]'));
    await click($(`[role="menuitemradio"][data-role="${role}"]`));
}

beforeEach(() => {
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
                xsrf:
                    (init?.headers as Record<string, string> | undefined)?.[
                        'X-XSRF-TOKEN'
                    ] ?? null,
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

describe('invite form', () => {
    it('expands inline (no dialog), focuses the email field and collapses on Cancel', async () => {
        await mountPage();

        expect($('[data-slot="invite-form"]')).toBeNull();
        await openForm();

        expect($('[data-slot="invite-form"]')).not.toBeNull();
        expect($('[role="dialog"]')).toBeNull();
        expect(
            $('[data-test="invite-user"]')!.getAttribute('aria-expanded'),
        ).toBe('true');
        expect(document.activeElement).toBe($('input[name="email"]'));

        await click($('[data-test="invite-cancel"]'));
        expect($('[data-slot="invite-form"]')).toBeNull();
        expect(document.activeElement).toBe($('[data-test="invite-user"]'));
    });

    it('opens the role menu with a role chip and a one-line description on every item', async () => {
        await mountPage();
        await openForm();
        await click($('[data-test="role-button"]'));

        const items = $$('[role="menuitemradio"]');
        expect($('[role="menu"]')).not.toBeNull();
        expect(items).toHaveLength(2);
        expect(
            items.map(
                (item) => item.querySelector('[data-slot="tag"]')?.textContent,
            ),
        ).toEqual(['User', 'Admin']);
        expect(items[0].textContent).toContain(
            inviteLabels.roleDescriptions.user,
        );
        expect(items[1].textContent).toContain(
            inviteLabels.roleDescriptions.admin,
        );
        expect(items[0].getAttribute('aria-checked')).toBe('true');

        await click(items[1]);
        expect($('[role="menu"]')).toBeNull();
        expect($('[data-test="role-button"]')!.textContent).toContain('Admin');
        expect(document.activeElement).toBe($('[data-test="role-button"]'));
    });

    it('closes the role menu with Escape and moves between items with the arrow keys', async () => {
        await mountPage();
        await openForm();
        await click($('[data-test="role-button"]'));

        const [user, admin] = $$('[role="menuitemradio"]');
        expect(document.activeElement).toBe(user);
        $('[role="menu"]')!.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
        );
        expect(document.activeElement).toBe(admin);
        $('[role="menu"]')!.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await flushPromises();
        expect($('[role="menu"]')).toBeNull();
        expect(document.activeElement).toBe($('[data-test="role-button"]'));
    });

    it('offers only the permissions the inviter holds, and only for Admin', async () => {
        await mountPage();
        await openForm();

        expect($('[data-test="permissions"]')).toBeNull();
        await chooseRole('admin');

        const offered = $$('[data-permission]').map((box) =>
            box.getAttribute('data-permission'),
        );
        expect(
            [...offered].sort((a, b) => String(a).localeCompare(String(b))),
        ).toEqual([...held].sort((a, b) => a.localeCompare(b)));
        expect(offered).not.toContain('blocks.publish');

        await chooseRole('user');
        expect($('[data-test="permissions"]')).toBeNull();
    });

    it('asks for the password for every Admin invitation, with or without permissions, and blocks sending without it', async () => {
        await mountPage();
        await openForm();
        await type('input[name="email"]', 'bo@example.test');

        expect($('input[name="confirm_password"]')).toBeNull();
        await chooseRole('admin');
        expect($('input[name="confirm_password"]')).not.toBeNull();

        await submit();
        expect(writes()).toHaveLength(0);
        expect($('[data-slot="field-error"]')?.textContent).toContain(
            inviteLabels.confirmPasswordRequired,
        );
        expect(document.activeElement).toBe(
            $('input[name="confirm_password"]'),
        );

        await chooseRole('user');
        expect($('input[name="confirm_password"]')).toBeNull();
    });

    it('shows the catalogue field error for an invalid email, focuses it and sends nothing', async () => {
        await mountPage();
        await openForm();
        await type('input[name="email"]', 'nope');
        await submit();

        expect(writes()).toHaveLength(0);
        expect($('[data-slot="field-error"]')?.textContent).toContain(
            'Enter an email address',
        );
        expect(document.activeElement).toBe($('input[name="email"]'));
    });

    it('sends a User invitation with the CSRF header, announces saved, closes and reloads the list', async () => {
        await mountPage();
        await openForm();
        await type('input[name="email"]', ' Bo@Example.test ');

        replies = [
            {
                status: 201,
                json: { data: invitation({ email: 'bo@example.test' }) },
            },
            {
                status: 200,
                json: list([
                    person(),
                    invitation({ email: 'bo@example.test' }),
                ]),
            },
        ];
        await submit();

        expect(writes()).toHaveLength(1);
        expect(writes()[0]).toMatchObject({
            url: '/api/v1/admin/invitations',
            method: 'POST',
            xsrf: 'abc 123',
            body: { email: 'Bo@Example.test', role: 'user', permissions: [] },
        });
        expect(writes()[0].body).not.toHaveProperty('confirm_password');
        expect($('[data-slot="invite-form"]')).toBeNull();
        expect(
            document.querySelector('[data-announcer="polite"]')?.textContent,
        ).toContain(en.saved);
        expect($$('tbody tr')).toHaveLength(2);
        expect($$('tbody tr')[1].textContent).toContain('Invited');
    });

    it('sends the chosen permissions with the password for an Admin invitation', async () => {
        await mountPage();
        await openForm();
        await type('input[name="email"]', 'bo@example.test');
        await chooseRole('admin');
        await click($('[data-permission="blocks.edit"]'));
        await type('input[name="confirm_password"]', 'secret-pass');

        replies = [
            { status: 201, json: { data: invitation({ role: 'admin' }) } },
            { status: 200, json: list([person()]) },
        ];
        await submit();

        expect(writes()[0].body).toEqual({
            email: 'bo@example.test',
            role: 'admin',
            permissions: ['blocks.edit'],
            confirm_password: 'secret-pass',
        });
    });

    it('shows a wrong password as a field error on the password field', async () => {
        await mountPage();
        await openForm();
        await type('input[name="email"]', 'bo@example.test');
        await chooseRole('admin');
        await click($('[data-permission="audit.view"]'));
        await type('input[name="confirm_password"]', 'wrong');

        replies = [
            {
                status: 422,
                json: {
                    error: { code: 'platform.validation_failed' },
                    errors: {
                        confirm_password: ['The password is incorrect.'],
                    },
                },
            },
        ];
        await submit();

        expect($('[data-slot="invite-form"]')).not.toBeNull();
        expect($('[data-slot="field-error"]')?.textContent).toContain(
            inviteLabels.confirmPasswordWrong,
        );
        expect(document.activeElement).toBe(
            $('input[name="confirm_password"]'),
        );
        // The typed password stays until success.
        expect(
            ($('input[name="confirm_password"]') as HTMLInputElement).value,
        ).toBe('wrong');
    });

    it('shows a duplicate pending invitation as a field error with Resend, which re-sends it', async () => {
        await mountPage();
        await openForm();
        await type('input[name="email"]', 'new@example.test');

        replies = [
            {
                status: 422,
                json: {
                    error: { code: 'platform.validation_failed' },
                    errors: {
                        email: ['This email already has a pending invitation.'],
                    },
                    reason: 'pending',
                    invitation_id: 'i-1',
                },
            },
        ];
        await submit();

        expect($('[data-slot="field-error"]')?.textContent).toContain(
            inviteLabels.emailPending,
        );
        expect(document.activeElement).toBe($('input[name="email"]'));

        replies = [
            { status: 200, json: { data: invitation() } },
            { status: 200, json: list([person(), invitation()]) },
        ];
        await click($('[data-test="invite-resend"]'));

        expect(writes()[1]).toMatchObject({
            url: '/api/v1/admin/invitations/i-1/resend',
            method: 'POST',
        });
        expect($('[data-slot="invite-form"]')).toBeNull();
    });

    it('shows an email that already belongs to a member as a field error', async () => {
        await mountPage();
        await openForm();
        await type('input[name="email"]', 'ada@example.test');

        replies = [
            {
                status: 422,
                json: {
                    error: { code: 'platform.validation_failed' },
                    errors: { email: ['x'] },
                    reason: 'member',
                },
            },
        ];
        await submit();

        expect($('[data-slot="field-error"]')?.textContent).toContain(
            inviteLabels.emailMember,
        );
        expect($('[data-test="invite-resend"]')).toBeNull();
    });

    it('shows save-failed form wording with Retry when the email did not go out, and Retry re-sends the invitation', async () => {
        await mountPage();
        await openForm();
        await type('input[name="email"]', 'bo@example.test');

        replies = [
            {
                status: 503,
                json: {
                    error: { code: 'identity.invitation_delivery_failed' },
                    invitation_id: 'i-9',
                },
            },
            {
                status: 200,
                json: list([person(), invitation({ invitation_id: 'i-9' })]),
            },
        ];
        await submit();

        const failure = $('[data-test="invite-failure"]')!;
        expect(failure.textContent).toContain(en['save-failed'].form);
        expect(document.activeElement).toBe(failure);
        // Only the Retry path remains: sending again would create nothing new.
        expect($('[data-test="invite-send"]')!.hasAttribute('disabled')).toBe(
            true,
        );
        // The list reloaded: the invitation shows as Invited with its Resend action.
        expect($$('tbody tr')).toHaveLength(2);

        replies = [
            {
                status: 200,
                json: { data: invitation({ invitation_id: 'i-9' }) },
            },
            {
                status: 200,
                json: list([person(), invitation({ invitation_id: 'i-9' })]),
            },
        ];
        await click($('[data-test="invite-retry"]'));

        expect(writes()[1]).toMatchObject({
            url: '/api/v1/admin/invitations/i-9/resend',
            method: 'POST',
        });
        expect($('[data-slot="invite-form"]')).toBeNull();
    });

    it('explains an unconfigured lifetime without offering Retry', async () => {
        await mountPage();
        await openForm();
        await type('input[name="email"]', 'bo@example.test');

        replies = [
            {
                status: 422,
                json: { error: { code: 'access.invitations_not_configured' } },
            },
        ];
        await submit();

        expect($('[data-test="invite-failure"]')?.textContent).toContain(
            inviteLabels.notConfigured,
        );
        expect($('[data-test="invite-retry"]')).toBeNull();
    });
});

describe('Invited rows', () => {
    it('shows Resend and Revoke on an Invited row only', async () => {
        await mountPage({ status: 200, json: list([person(), invitation()]) });

        const rows = $$('tbody tr');
        expect(rows[0].querySelector('[data-test="resend"]')).toBeNull();
        expect(
            rows[1]
                .querySelector('[data-test="resend"]')
                ?.getAttribute('aria-label'),
        ).toBe(userListLabels.resendFor('new@example.test'));
        expect(
            rows[1]
                .querySelector('[data-test="revoke"]')
                ?.getAttribute('aria-label'),
        ).toBe(userListLabels.revokeFor('new@example.test'));
    });

    it('re-sends an invitation and announces it', async () => {
        await mountPage({ status: 200, json: list([person(), invitation()]) });
        replies = [
            { status: 200, json: { data: invitation() } },
            { status: 200, json: list([person(), invitation()]) },
        ];
        await click($('[data-test="resend"]'));

        expect(writes()[0]).toMatchObject({
            url: '/api/v1/admin/invitations/i-1/resend',
            method: 'POST',
            xsrf: 'abc 123',
        });
        expect(
            document.querySelector('[data-announcer="polite"]')?.textContent,
        ).toContain(userListLabels.resent('new@example.test'));
    });

    it('revokes an invitation and reloads the list without it', async () => {
        await mountPage({ status: 200, json: list([person(), invitation()]) });
        replies = [{ status: 204 }, { status: 200, json: list([person()]) }];
        await click($('[data-test="revoke"]'));

        expect(writes()[0]).toMatchObject({
            url: '/api/v1/admin/invitations/i-1',
            method: 'DELETE',
        });
        expect($$('tbody tr')).toHaveLength(1);
        expect(
            document.querySelector('[data-announcer="polite"]')?.textContent,
        ).toContain(userListLabels.revoked('new@example.test'));
    });
});

describe('refusals', () => {
    async function rowAction(selector: string, reply: Reply) {
        await mountPage({ status: 200, json: list([person(), invitation()]) });
        replies = [
            reply,
            { status: 200, json: list([person(), invitation()]) },
        ];
        await click($(selector));
    }

    const toasts = () => useToasts().items.map((item) => item.message);

    it('shows the form-level throttle message when a 429 has no password field to show it on', async () => {
        await mountPage();
        await openForm();
        await type('input[name="email"]', 'bo@example.test');

        replies = [
            {
                status: 429,
                json: { error: { code: 'platform.too_many_requests' } },
            },
        ];
        await submit();

        expect($('[data-test="invite-failure"]')?.textContent).toContain(
            inviteLabels.throttled,
        );
        expect($('[data-test="invite-retry"]')).toBeNull();
    });

    it('shows a password throttle on the password field', async () => {
        await mountPage();
        await openForm();
        await type('input[name="email"]', 'bo@example.test');
        await chooseRole('admin');
        await type('input[name="confirm_password"]', 'x');

        replies = [
            {
                status: 429,
                json: {
                    error: { code: 'platform.too_many_requests' },
                    errors: { confirm_password: ['Too many attempts.'] },
                },
            },
        ];
        await submit();

        expect($('[data-slot="field-error"]')?.textContent).toContain(
            en.throttled,
        );
    });

    it('says why a revoke failed (500), and refreshes the list', async () => {
        await rowAction('[data-test="revoke"]', { status: 500, json: {} });

        expect(toasts()).toContain(en['save-failed'].form);
        expect($$('tbody tr')).toHaveLength(2);
    });

    it('says the invitation is gone on a 404', async () => {
        await rowAction('[data-test="revoke"]', { status: 404, json: {} });

        expect(toasts()).toContain(inviteLabels.gone);
    });

    it('signs in again on a 401', async () => {
        const assign = vi.fn();
        vi.stubGlobal('location', { assign });
        await rowAction('[data-test="resend"]', { status: 401, json: {} });

        expect(assign).toHaveBeenCalledWith('/login');
    });

    it('shows the throttle message for a row action that gets a 429', async () => {
        await rowAction('[data-test="resend"]', { status: 429, json: {} });

        expect(toasts()).toContain(en.throttled);
    });

    it('shows the specific message when a Resend is refused for missing permissions', async () => {
        await rowAction('[data-test="resend"]', {
            status: 403,
            json: { error: { code: 'access.permission_not_held' } },
        });

        expect(toasts()).toContain(inviteLabels.resendNotHeld);
    });
});
