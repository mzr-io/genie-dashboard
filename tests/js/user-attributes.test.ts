// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import { createCatalogue } from '../../resources/js/lib/i18n';
import type { Member } from '../../resources/js/lib/members';
import type { AttributeKey } from '../../resources/js/lib/userAttributes';
import {
    settingsLabels,
    userAttributeLabels as labels,
} from '../../resources/js/locales/labels';

// Story 2.12: System settings > User attributes (a data table with search, the five skeleton rows, list-empty with
// "+ Add attribute", an inline add form with field errors and focus, inline label edit under a revision) and the
// Attributes editor on a member row in User configuration (one labelled input per defined key, only changed values
// sent, field errors, a polite announcement, never on the Admin's own row).
const page = vi.hoisted(() => ({
    shell: {
        membership_id: 'm-ada' as string | null,
        can: { 'users.manage': true, 'settings.manage': true } as Record<
            string,
            boolean
        >,
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
            url: '/admin/settings/user-attributes',
            props: { shell: page.shell },
        }),
    };
});

const { default: UserAttributes } =
    await import('../../resources/js/pages/admin/UserAttributes.vue');
const { default: SystemSettings } =
    await import('../../resources/js/pages/admin/SystemSettings.vue');
const { default: Users } =
    await import('../../resources/js/pages/admin/Users.vue');

const CANARY = 'CANARY-region-value';

function key(overrides: Partial<AttributeKey> = {}): AttributeKey {
    return {
        id: 'k-1',
        key_id: 'region',
        label: 'Region',
        value_type: 'text',
        revision: 1,
        created_at: '2026-10-09T09:00:00Z',
        updated_at: '2026-10-09T09:00:00Z',
        ...overrides,
    };
}

const keyList = (data: AttributeKey[], matched = data.length) => ({
    data,
    meta: { total: data.length, matched },
});

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

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const $$ = (selector: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(selector));
const polite = () => $('[data-announcer="polite"]');

async function mountPage(component: unknown = UserAttributes) {
    wrapper = mount(component as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
}

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

async function submit(selector: string): Promise<void> {
    $(selector)!.dispatchEvent(
        new Event('submit', { bubbles: true, cancelable: true }),
    );
    await flushPromises();
}

beforeEach(() => {
    page.shell.membership_id = 'm-ada';
    page.shell.can = { 'users.manage': true, 'settings.manage': true };
    setActivePinia(createPinia());
    calls.length = 0;
    replies = [];
    document.cookie = 'XSRF-TOKEN=abc%20123';
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
    it('links to User attributes', async () => {
        await mountPage(SystemSettings);

        const link = $('[data-test="open-user-attributes"]');
        expect(link?.getAttribute('href')).toBe(
            '/admin/settings/user-attributes',
        );
        expect(link?.textContent).toContain(settingsLabels.userAttributes);
    });
});

describe('User attributes page', () => {
    it('shows five skeleton rows while loading, then the table with key id, label and type and the count caption', async () => {
        replies = [new Promise<Reply>(() => {})];
        await mountPage();

        expect($$('[data-slot="skeleton-row"]')).toHaveLength(5);
        wrapper?.unmount();
        document.body.innerHTML = '';

        replies = [
            ok(
                keyList([
                    key(),
                    key({
                        id: 'k-2',
                        key_id: 'employee_no',
                        label: 'Employee number',
                        value_type: 'integer',
                    }),
                ]),
            ),
        ];
        await mountPage();

        expect($('table caption')?.textContent?.trim()).toBe(labels.caption);
        const rows = $$('tbody [data-slot="data-row"]');
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('region');
        expect(rows[0].textContent).toContain('Region');
        expect(rows[1].textContent).toContain(labels.types.integer);
        expect($('[data-test="count"]')?.textContent).toBe(labels.count(2, 2));
        expect(
            $$('button').filter((b) => b.textContent?.includes(labels.action)),
        ).toHaveLength(1);
    });

    it('shows list-empty with "+ Add attribute" as the only primary action', async () => {
        replies = [ok(keyList([]))];
        await mountPage();

        expect($('[data-state="empty"]')).not.toBeNull();
        const add = $$('[data-test="add-attribute"]');
        expect(add).toHaveLength(1);
        expect(add[0].textContent?.trim()).toBe(labels.action);
    });

    it('shows a failure state with Retry that loads again', async () => {
        replies = [refused(500), ok(keyList([key()]))];
        await mountPage();

        expect($('[data-state="error"]')).not.toBeNull();
        await click($('[data-state="error"] button'));

        expect($$('tbody [data-slot="data-row"]')).toHaveLength(1);
    });

    it('searches by key id or label', async () => {
        vi.useFakeTimers();
        replies = [ok(keyList([key()])), ok(keyList([key()], 1))];
        await mountPage();

        await type('[data-test="search"]', 'reg');
        await vi.advanceTimersByTimeAsync(400);
        await flushPromises();

        expect(calls[1].url).toBe('/api/v1/admin/user-attributes?q=reg');
    });

    it('adds a key: the form moves focus to the key id, sends the three fields and highlights and announces the new row', async () => {
        replies = [
            ok(keyList([key()])),
            {
                ok: true,
                status: 201,
                json: {
                    data: key({ id: 'k-2', key_id: 'team', label: 'Team' }),
                },
            },
            ok(
                keyList([
                    key(),
                    key({ id: 'k-2', key_id: 'team', label: 'Team' }),
                ]),
            ),
        ];
        await mountPage();

        await click($('[data-test="add-attribute"]'));
        expect($('[role="dialog"]')).toBeNull();
        expect(document.activeElement).toBe(
            $('[data-test="add-attribute-key"]'),
        );

        await type('[data-test="add-attribute-key"]', 'team');
        await type('[data-test="add-attribute-label"]', 'Team');
        await submit('[data-test="add-attribute-form"]');

        expect(writes()).toHaveLength(1);
        expect(writes()[0].body).toEqual({
            key_id: 'team',
            label: 'Team',
            value_type: 'text',
        });
        expect($('[data-test="add-attribute-form"]')).toBeNull();
        const row = $('[data-row-key="k-2"]');
        expect(
            row?.getAttribute('data-highlighted') ?? row?.className,
        ).toBeTruthy();
        expect(document.activeElement).toBe(row);
        expect(polite()?.textContent).toContain(labels.added('Team'));
    });

    it('shows field errors with aria-invalid and focuses the first invalid field', async () => {
        replies = [
            ok(keyList([key()])),
            refused(422, {
                errors: { key_id: ['taken'], label: ['taken'] },
                reasons: { key_id: 'duplicate', label: 'duplicate' },
            }),
        ];
        await mountPage();
        await click($('[data-test="add-attribute"]'));
        await type('[data-test="add-attribute-key"]', 'region');
        await type('[data-test="add-attribute-label"]', 'Region');
        await submit('[data-test="add-attribute-form"]');

        const keyInput = $('[data-test="add-attribute-key"]')!;
        expect(keyInput.getAttribute('aria-invalid')).toBe('true');
        expect(
            $('[data-test="add-attribute-label"]')!.getAttribute(
                'aria-invalid',
            ),
        ).toBe('true');
        expect(document.activeElement).toBe(keyInput);
        expect(document.body.textContent).toContain(labels.keyIdTaken);
        // What was typed stays.
        expect((keyInput as HTMLInputElement).value).toBe('region');
    });

    it('renames inline under the revision and never offers the key id or type for editing', async () => {
        replies = [
            ok(keyList([key()])),
            ok({ data: key({ label: 'Sales region', revision: 2 }) }),
            ok(keyList([key({ label: 'Sales region', revision: 2 })])),
        ];
        await mountPage();

        await click($('[data-test="rename"]'));
        expect($$('[data-test="rename-input"]')).toHaveLength(1);
        expect(document.activeElement).toBe($('[data-test="rename-input"]'));
        await type('[data-test="rename-input"]', 'Sales region');
        await submit('[data-test="rename-form-region"]');

        expect(writes()[0]).toMatchObject({
            url: '/api/v1/admin/user-attributes/region',
            method: 'PUT',
            body: { label: 'Sales region', revision: 1 },
        });
        expect($('[data-row-key="k-1"]')?.textContent).toContain(
            'Sales region',
        );
        expect(polite()?.textContent).toContain(labels.renamed('Sales region'));
    });

    it('shows the latest label with a notice on a stale revision (409)', async () => {
        replies = [
            ok(keyList([key()])),
            refused(409, {
                error: { code: 'access.revision_conflict' },
                current: key({ label: 'Territory', revision: 2 }),
            }),
        ];
        await mountPage();
        await click($('[data-test="rename"]'));
        await type('[data-test="rename-input"]', 'Mine');
        await submit('[data-test="rename-form-region"]');

        expect(document.body.textContent).toContain(labels.conflict);
        expect(
            ($('[data-test="rename-input"]') as HTMLInputElement).value,
        ).toBe('Mine');
        expect(
            $('[data-test="rename-input"]')!.getAttribute('aria-invalid'),
        ).toBe('true');
    });

    it('shows a permission denial as perm-denied', async () => {
        replies = [refused(403)];
        await mountPage();

        expect($('[data-slot="perm-denied"]')).not.toBeNull();
    });
});

describe('the member Attributes editor', () => {
    const person = (overrides: Partial<Member> = {}): Member => ({
        kind: 'member',
        membership_id: 'm-bo',
        name: 'Bo Builder',
        email: 'bo@example.test',
        role: 'user',
        status: 'active',
        groups: [],
        last_active_at: null,
        permissions: [],
        revision: 1,
        ...overrides,
    });
    const ada = person({
        membership_id: 'm-ada',
        name: 'Ada Admin',
        email: 'ada@example.test',
        role: 'admin',
    });
    const memberList = (data: Member[]) => ({
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
    const rows = (region: string | null, employee: string | null) => ({
        data: [
            {
                key_id: 'employee_no',
                label: 'Employee number',
                value_type: 'integer',
                value: employee,
            },
            {
                key_id: 'region',
                label: 'Region',
                value_type: 'text',
                value: region,
            },
        ],
    });

    async function open(members: Member[] = [ada, person()]) {
        replies = [ok(memberList(members)), ok(rows(CANARY, null))];
        await mountPage(Users);
        await click($('[data-test="edit-attributes"][data-member="m-bo"]'));
    }

    it("is not offered on the Admin's own row, nor without users.manage", async () => {
        replies = [ok(memberList([ada, person()]))];
        await mountPage(Users);

        expect(
            $('[data-test="edit-attributes"][data-member="m-ada"]'),
        ).toBeNull();
        expect(
            $('[data-test="edit-attributes"][data-member="m-bo"]'),
        ).not.toBeNull();
        wrapper?.unmount();
        document.body.innerHTML = '';

        page.shell.can = { 'users.manage': false };
        replies = [ok(memberList([ada, person()]))];
        await mountPage(Users);

        expect($('[data-test="edit-attributes"]')).toBeNull();
    });

    it('expands inline with one labelled input per defined key showing the current value', async () => {
        await open();

        expect(
            $('[data-slot="detail-row"] [data-slot="attributes-editor"]'),
        ).not.toBeNull();
        expect($('[role="dialog"]')).toBeNull();
        expect(calls[1].url).toBe('/api/v1/admin/members/m-bo/attributes');
        const region = $('[data-attribute="region"]') as HTMLInputElement;
        expect(region.value).toBe(CANARY);
        expect(
            document.querySelector(`label[for="${region.id}"]`)?.textContent,
        ).toContain('Region');
        expect(
            ($('[data-attribute="employee_no"]') as HTMLInputElement).value,
        ).toBe('');
    });

    it('sends only the changed values and announces the save politely', async () => {
        await open();
        replies = [
            ok({ data: { changed: ['employee_no'] } }),
            ok(rows(CANARY, '42')),
        ];
        await type('[data-attribute="employee_no"]', ' 42 ');
        await submit('[data-test="attributes-form"]');

        expect(writes()).toHaveLength(1);
        expect(writes()[0]).toMatchObject({
            url: '/api/v1/admin/members/m-bo/attributes',
            method: 'PUT',
            body: { values: { employee_no: ' 42 ' } },
        });
        expect(polite()?.textContent).toContain(
            labels.memberSaved('Bo Builder'),
        );
        expect(
            ($('[data-attribute="employee_no"]') as HTMLInputElement).value,
        ).toBe('42');
        // The value is in no announcement, toast or URL.
        expect(polite()?.textContent).not.toContain('42');
        expect(calls.map((call) => call.url).join()).not.toContain('42');
    });

    it('sends nothing when no value changed', async () => {
        await open();
        await submit('[data-test="attributes-form"]');

        expect(writes()).toHaveLength(0);
        expect($('[data-test="attributes-status"]')?.textContent).toContain(
            labels.memberUnchanged,
        );
    });

    it('shows a field error with aria-invalid and focuses the first invalid field, naming no value', async () => {
        await open();
        replies = [
            refused(422, {
                errors: { 'values.employee_no': ['invalid'] },
                reasons: { employee_no: 'invalid' },
            }),
        ];
        await type('[data-attribute="employee_no"]', 'abc');
        await submit('[data-test="attributes-form"]');

        const input = $('[data-attribute="employee_no"]')!;
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(document.activeElement).toBe(input);
        expect(document.body.textContent).toContain(
            labels.memberReasons.invalid,
        );
    });

    it('says the attributes are unavailable on a 503 and shows no form', async () => {
        replies = [
            ok(memberList([ada, person()])),
            refused(503, { error: { code: 'access.attributes_unavailable' } }),
        ];
        await mountPage(Users);
        await click($('[data-test="edit-attributes"][data-member="m-bo"]'));

        expect(
            $('[data-test="attributes-load-failure"]')?.textContent,
        ).toContain(labels.memberUnavailable);
        expect($('[data-test="attributes-form"]')).toBeNull();
    });

    it('refuses your own attributes with the self reason when the server says so', async () => {
        await open();
        replies = [
            refused(403, { error: { code: 'access.self_change_forbidden' } }),
        ];
        await type('[data-attribute="employee_no"]', '7');
        await submit('[data-test="attributes-form"]');

        expect($('[data-test="attributes-failure"]')?.textContent).toContain(
            labels.memberSelf,
        );
    });
});
