// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import { createCatalogue } from '../../resources/js/lib/i18n';
import type { Member } from '../../resources/js/lib/members';
import en from '../../resources/js/locales/en';
import { accessLabels, inviteLabels } from '../../resources/js/locales/labels';

// Story 1.22: the inline Roles & permissions editor on a member row, the permission checkboxes limited to what the
// Admin holds (the rest disabled with a reason), the password prompt, the downgrade alertdialog, saved and the
// conflict, last-holder and wrong-password refusals.
const page = vi.hoisted(() => ({
    shell: {
        membership_id: 'm-ada' as string | null,
        can: {
            'users.manage': true,
            'blocks.edit': true,
            'audit.view': true,
            'blocks.publish': false,
        },
        items: [],
    },
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    usePage: () => ({
        url: '/admin/users',
        props: { shell: page.shell },
    }),
}));

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
    permissions: ['users.manage', 'blocks.edit', 'audit.view'],
    revision: 1,
});

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

async function mountPage(members: Member[] = [ada, person()]) {
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

async function type(selector: string, value: string) {
    const input = $(selector) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function edit(memberKey = 'm-bo') {
    await click($(`[data-test="edit-access"][data-member="${memberKey}"]`));
}

async function tick(permission: string) {
    await click($(`[data-permission="${permission}"]`));
}

async function save() {
    $('[data-test="access-form"]')!.dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }),
    );
    await flushPromises();
}

async function chooseRole(role: 'user' | 'admin') {
    await click($('[data-test="role-button"]'));
    await click($(`[role="menuitemradio"][data-role="${role}"]`));
}

const saved = (overrides: Partial<Member> = {}) => ({
    status: 200,
    json: { data: person({ revision: 4, ...overrides }) },
});

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

describe('the Roles & permissions editor', () => {
    it('expands inline under the row (no dialog) and collapses with focus back on its button', async () => {
        await mountPage();

        expect($('[data-slot="access-editor"]')).toBeNull();
        await edit();

        expect(
            $('[data-slot="detail-row"] [data-slot="access-editor"]'),
        ).not.toBeNull();
        expect($('[role="dialog"]')).toBeNull();
        expect(
            $('[data-test="edit-access"][data-member="m-bo"]')!.getAttribute(
                'aria-expanded',
            ),
        ).toBe('true');
        expect($('[data-test="access-title"]')!.textContent).toContain(
            'Bo Builder',
        );

        await click($('[data-test="access-close"]'));
        expect($('[data-slot="access-editor"]')).toBeNull();
        expect(document.activeElement).toBe(
            $('[data-test="edit-access"][data-member="m-bo"]'),
        );
    });

    it('shows every permission, enabling only what the Admin holds and giving the rest a reason', async () => {
        await mountPage();
        await edit();

        const boxes = $$('[data-permission]');
        expect(boxes).toHaveLength(
            Object.keys(inviteLabels.permissionLabels).length,
        );

        const publish = $('[data-permission="blocks.publish"]')!;
        expect(
            publish.hasAttribute('disabled') ||
                publish.getAttribute('data-disabled') !== null,
        ).toBe(true);
        expect($$('[data-test="permission-reason"]').length).toBeGreaterThan(0);
        expect($$('[data-test="permission-reason"]')[0].textContent).toBe(
            accessLabels.notHeldReason,
        );
        expect(publish.getAttribute('aria-describedby')).toBeTruthy();

        const edits = $('[data-permission="blocks.edit"]')!;
        expect(edits.hasAttribute('disabled')).toBe(false);
        expect(edits.getAttribute('aria-checked')).toBe('true');
        // The unheld permission's reason is the catalogue's perm-publish wording for Publish elsewhere; here it is the editor's own.
        expect(en['perm-publish']).toContain('publish');
    });

    it('disables the permission editor for a User with a reason, until Admin is chosen', async () => {
        await mountPage([
            ada,
            person({
                membership_id: 'm-cy',
                name: 'Cy',
                email: 'cy@example.test',
                role: 'user',
                permissions: [],
            }),
        ]);
        await edit('m-cy');

        expect($('[data-test="permissions"]')!.hasAttribute('disabled')).toBe(
            true,
        );
        expect($('[data-test="permissions-helper"]')!.textContent).toBe(
            accessLabels.userNoPermissions,
        );

        await chooseRole('admin');
        expect($('[data-test="permissions"]')!.hasAttribute('disabled')).toBe(
            false,
        );
        expect($('input[name="confirm_password"]')).not.toBeNull();
    });

    it('refuses to open the editor on your own row, with the reason, using the server membership ID', async () => {
        await mountPage();
        const own = $('[data-test="edit-access"][data-member="m-ada"]')!;

        expect(own.getAttribute('aria-disabled')).toBe('true');
        expect(
            document.getElementById(own.getAttribute('aria-describedby')!)!
                .textContent,
        ).toBe(accessLabels.selfReason);

        await click(own);
        expect($('[data-slot="access-editor"]')).toBeNull();
        expect(writes()).toHaveLength(0);
    });

    it('fails closed when the shell has no membership ID: every Edit is disabled', async () => {
        page.shell.membership_id = null;
        await mountPage();

        for (const button of $$('[data-test="edit-access"]')) {
            expect(button.getAttribute('aria-disabled')).toBe('true');
        }

        await edit();
        expect($('[data-slot="access-editor"]')).toBeNull();
    });

    it('disables Edit with a reason for a deactivated member', async () => {
        await mountPage([ada, person({ status: 'deactivated' })]);
        const button = $('[data-test="edit-access"][data-member="m-bo"]')!;

        expect(button.getAttribute('aria-disabled')).toBe('true');
        expect(
            document.getElementById(button.getAttribute('aria-describedby')!)!
                .textContent,
        ).toBe(accessLabels.inactiveReason);
    });

    it('points the Edit button at the detail row it expands', async () => {
        await mountPage();
        await edit();

        const button = $('[data-test="edit-access"][data-member="m-bo"]')!;
        expect($(`#${button.getAttribute('aria-controls')}`)).toBe(
            $('[data-slot="detail-row"]'),
        );
    });

    it('asks for the password when the permission set changes, saves, announces and keeps focus on Save', async () => {
        await mountPage();
        await edit();

        expect($('input[name="confirm_password"]')).toBeNull();
        await tick('audit.view');
        expect($('input[name="confirm_password"]')).not.toBeNull();

        // Without the password nothing is sent.
        await save();
        expect(writes()).toHaveLength(0);
        expect(document.body.textContent).toContain(
            accessLabels.passwordRequired,
        );
        expect(document.activeElement).toBe(
            $('input[name="confirm_password"]'),
        );

        await type('input[name="confirm_password"]', 'secret-pass');
        replies.push(saved({ permissions: ['audit.view', 'blocks.edit'] }));
        const button = $('[data-test="access-save"]')!;
        button.focus();
        await save();

        expect(writes()).toEqual([
            {
                url: '/api/v1/admin/members/m-bo',
                method: 'PATCH',
                body: {
                    revision: 3,
                    role: 'admin',
                    permissions: ['blocks.edit', 'audit.view'],
                    confirm_password: 'secret-pass',
                },
            },
        ]);
        expect($('[data-test="access-status"]')!.textContent).toContain(
            en.saved,
        );
        expect(
            document.querySelector('[role="status"]')?.textContent ?? '',
        ).toContain(en.saved);
        expect(document.activeElement).toBe(button);
        // The password is cleared and the row shows the new revision's values.
        expect($('input[name="confirm_password"]')).toBeNull();
        expect(
            $('[data-permission="audit.view"]')!.getAttribute('aria-checked'),
        ).toBe('true');
    });

    it('asks for the password to make a User an Admin', async () => {
        await mountPage([
            ada,
            person({
                membership_id: 'm-cy',
                name: 'Cy',
                email: 'cy@example.test',
                role: 'user',
                permissions: [],
                revision: 1,
            }),
        ]);
        await edit('m-cy');
        await chooseRole('admin');
        await type('input[name="confirm_password"]', 'secret-pass');
        replies.push(
            saved({ membership_id: 'm-cy', revision: 2, permissions: [] }),
        );
        await save();

        expect(writes()[0].body).toEqual({
            revision: 1,
            role: 'admin',
            permissions: [],
            confirm_password: 'secret-pass',
        });
    });

    it('confirms a downgrade in an alertdialog that names the member and impact, focus on Cancel, and sends nothing on Cancel', async () => {
        await mountPage();
        await edit();
        await chooseRole('user');
        await type('input[name="confirm_password"]', 'secret-pass');
        await save();

        const dialog = $('[role="alertdialog"]');
        expect(dialog).not.toBeNull();
        expect(dialog!.textContent).toContain(
            accessLabels.downgradeTitle('Bo Builder'),
        );
        expect(dialog!.textContent).toContain(
            accessLabels.downgradeImpact('Bo Builder'),
        );
        expect(writes()).toHaveLength(0);
        expect(document.activeElement?.textContent?.trim()).toBe('Cancel');

        await click(
            Array.from(dialog!.querySelectorAll('button')).find(
                (b) => b.textContent?.trim() === 'Cancel',
            ) ?? null,
        );
        expect($('[role="alertdialog"]')).toBeNull();
        expect(writes()).toHaveLength(0);

        await save();
        replies.push(saved({ role: 'user', permissions: [] }));
        const confirm = $$('[role="alertdialog"] button').find((b) =>
            b.textContent?.includes('Change Bo Builder to User'),
        );
        await click(confirm ?? null);

        expect(writes()).toHaveLength(1);
        expect(writes()[0].body).toMatchObject({
            revision: 3,
            role: 'user',
            permissions: [],
        });
    });

    it('shows save-failed form wording with the latest values when the revision is stale', async () => {
        await mountPage();
        await edit();
        await tick('audit.view');
        await type('input[name="confirm_password"]', 'secret-pass');
        replies.push({
            status: 409,
            json: {
                error: { code: 'access.revision_conflict' },
                current: {
                    role: 'admin',
                    permissions: ['blocks.edit', 'users.manage'],
                    revision: 5,
                },
            },
        });
        await save();

        const failure = $('[data-test="access-failure"]')!;
        expect(failure.textContent).toContain(en['save-failed'].form);
        expect(failure.getAttribute('role')).toBe('alert');
        expect(document.activeElement).toBe(failure);
        expect(
            $('[data-permission="users.manage"]')!.getAttribute('aria-checked'),
        ).toBe('true');
        expect(
            $('[data-permission="audit.view"]')!.getAttribute('aria-checked'),
        ).toBe('false');

        // The next save carries the latest revision.
        await tick('audit.view');
        await type('input[name="confirm_password"]', 'secret-pass');
        replies.push(saved({ revision: 6 }));
        await save();
        expect(writes()[1].body).toMatchObject({ revision: 5 });
    });

    it('explains the last users.manage holder inline and the editor stays as it was', async () => {
        await mountPage();
        await edit();
        await chooseRole('user');
        await type('input[name="confirm_password"]', 'secret-pass');
        replies.push({
            status: 409,
            json: { error: { code: 'access.last_users_manage_holder' } },
        });
        await save();
        await click(
            $$('[role="alertdialog"] button').find((b) =>
                b.textContent?.includes('Change'),
            ) ?? null,
        );

        expect($('[data-test="access-failure"]')!.textContent).toContain(
            accessLabels.lastHolder,
        );
    });

    it('shows a wrong password as a field error and focuses it', async () => {
        await mountPage();
        await edit();
        await tick('audit.view');
        await type('input[name="confirm_password"]', 'nope');
        replies.push({
            status: 422,
            json: {
                errors: { confirm_password: ['The password is incorrect.'] },
            },
        });
        await save();

        expect(document.body.textContent).toContain(
            inviteLabels.confirmPasswordWrong,
        );
        expect(document.activeElement).toBe(
            $('input[name="confirm_password"]'),
        );
    });

    it('says nothing is to save and sends nothing when nothing changed', async () => {
        await mountPage();
        await edit();
        await save();

        expect(writes()).toHaveLength(0);
        expect($('[data-test="access-status"]')!.textContent).toBe(
            accessLabels.unchanged,
        );
    });

    it('updates the list row and the next save on a 409, and a later edit clears the banner', async () => {
        await mountPage();
        await edit();
        await tick('audit.view');
        await type('input[name="confirm_password"]', 'secret-pass');
        replies.push({
            status: 409,
            json: {
                error: { code: 'access.revision_conflict' },
                current: { role: 'user', permissions: [], revision: 9 },
            },
        });
        await save();

        // The row in the list now shows the other Admin's change.
        const row = $$('[data-slot="data-row"]').find((r) =>
            r.textContent?.includes('Bo Builder'),
        )!;
        expect(row.textContent).toContain('User');
        expect($('[data-test="access-failure"]')).not.toBeNull();

        await chooseRole('admin');
        expect($('[data-test="access-failure"]')).toBeNull();

        await type('input[name="confirm_password"]', 'secret-pass');
        replies.push(saved({ revision: 10 }));
        await save();
        expect(writes()[1].body).toMatchObject({ revision: 9 });
    });

    it('drops the row and reloads the list on a 404', async () => {
        await mountPage();
        await edit();
        await tick('audit.view');
        await type('input[name="confirm_password"]', 'secret-pass');
        replies.push({
            status: 404,
            json: { error: { code: 'platform.not_found' } },
        });
        replies.push({ status: 200, json: list([ada]) });
        await save();

        expect($('[data-slot="access-editor"]')).toBeNull();
        expect(calls.filter((c) => c.method === 'GET')).toHaveLength(2);
        expect(document.body.textContent).not.toContain('Bo Builder');
    });

    it('reloads the page on 403 access.not_authorized', async () => {
        const reload = vi.fn();
        vi.stubGlobal('location', {
            href: window.location.href,
            reload,
            assign: vi.fn(),
        });
        await mountPage();
        await edit();
        await tick('audit.view');
        await type('input[name="confirm_password"]', 'secret-pass');
        replies.push({
            status: 403,
            json: { error: { code: 'access.not_authorized' } },
        });
        await save();

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('shows a 422 permissions error, and a server failure as the generic message with Retry', async () => {
        await mountPage();
        await edit();
        await tick('audit.view');
        await type('input[name="confirm_password"]', 'secret-pass');
        replies.push({
            status: 422,
            json: { errors: { permissions: ['A User holds no permissions.'] } },
        });
        await save();
        expect($('[data-test="permissions-error"]')!.textContent).toContain(
            'A User holds no permissions.',
        );

        replies.push({ status: 500, json: null });
        await save();
        expect($('[data-test="access-failure"]')!.textContent).toContain(
            en['save-failed'].form,
        );

        replies.push(saved({ permissions: ['audit.view', 'blocks.edit'] }));
        await click($('[data-test="access-retry"]'));
        expect($('[data-test="access-failure"]')).toBeNull();
        expect(writes()).toHaveLength(3);
    });

    it('does not claim a refresh for a permission the Admin does not hold', async () => {
        await mountPage();
        await edit();
        await tick('audit.view');
        await type('input[name="confirm_password"]', 'secret-pass');
        replies.push({
            status: 403,
            json: { error: { code: 'access.permission_not_held' } },
        });
        await save();

        expect($('[data-test="access-failure"]')!.textContent).toBe(
            accessLabels.held,
        );
        expect(accessLabels.held).not.toContain('refreshed');
    });

    it('keeps focus on Save after a save submitted with Enter in the password field', async () => {
        await mountPage();
        await edit();
        await tick('audit.view');
        await type('input[name="confirm_password"]', 'secret-pass');
        ($('input[name="confirm_password"]') as HTMLInputElement).focus();
        replies.push(saved({ permissions: ['audit.view', 'blocks.edit'] }));
        await save();

        expect($('input[name="confirm_password"]')).toBeNull();
        expect(document.activeElement).toBe($('[data-test="access-save"]'));
    });
});
