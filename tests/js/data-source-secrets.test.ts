// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { DataSource } from '../../resources/js/lib/dataSources';
import { saveFormDrafts } from '../../resources/js/lib/formDrafts';
import { createCatalogue } from '../../resources/js/lib/i18n';
import {
    controlLabels,
    dataSourceLabels as labels,
} from '../../resources/js/locales/labels';

// Story 2.4: the Authentication section. Only the fields of the chosen type show, secrets are masked and write-only (a saved
// one shows its date and "Replace"), the password is asked only when a secret or the auth type changes, a query-placed key
// warns, secret header rows seal their value, and no unsaved secret is ever stored.
const visit = vi.fn();

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
        router: {
            visit: (...args: unknown[]) => visit(...args),
            on: () => () => {},
        },
        usePage: () => ({ url: '/admin/data-sources', props: {} }),
    };
});

const { default: DataSourceForm } =
    await import('../../resources/js/pages/admin/DataSourceForm.vue');

const ceilings = {
    timeout_seconds: null,
    max_response_bytes: null,
    max_pages: null,
};
const CANARY = 'CANARY-ui-5521';

function source(overrides: Partial<DataSource> = {}): DataSource {
    return {
        data_source_id: 'ds-1',
        name: 'Sales API',
        base_url: 'https://api.example.com/v1',
        scheme: 'https',
        host: 'api.example.com',
        port: 443,
        auth_type: 'bearer',
        api_key_name: null,
        api_key_placement: null,
        headers: [],
        secrets: {
            bearer_token: {
                configured: true,
                updated_at: '2026-10-02T09:30:00Z',
            },
        },
        timeout_seconds: null,
        max_response_bytes: null,
        max_pages: null,
        live_capable: false,
        revision: 2,
        health: 'checking',
        last_successful_call_at: null,
        blocks_using: 0,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-02T09:30:00Z',
        ...overrides,
    };
}

const one = (data: DataSource) => ({ data, meta: { ceilings } });

type Reply = { ok: boolean; status: number; json: unknown };
const calls: Array<{ url: string; method: string; body: unknown }> = [];
let replies: Reply[] = [];
const ok = (json: unknown): Reply => ({ ok: true, status: 200, json });
const refused = (status: number, json: unknown = {}): Reply => ({
    ok: false,
    status,
    json,
});
const writes = () => calls.filter((call) => call.method !== 'GET');

let wrapper: VueWrapper | null = null;

async function mountForm(props: Record<string, unknown> = {}) {
    wrapper = mount(DataSourceForm as never, {
        attachTo: document.body,
        props,
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

async function type(selector: string, value: string): Promise<void> {
    const input = $(selector) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function choose(type_: string): Promise<void> {
    const select = $('[data-test="auth-type"]') as HTMLSelectElement;
    select.value = type_;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await flushPromises();
}

const save = () => $('[data-test="save"]') as HTMLButtonElement;

beforeEach(() => {
    setActivePinia(createPinia());
    calls.length = 0;
    replies = [];
    visit.mockClear();
    sessionStorage.clear();
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                // Story 2.8: the soft lock is off here (`{enabled: false}`), and its calls are not part of these tests' traffic.
                if (/\/lock(\/|\?|$)/.test(url)) {
                    return {
                        ok: true,
                        status: 200,
                        headers: { get: () => null },
                        json: async () => ({ data: { enabled: false } }),
                    };
                }

                calls.push({
                    url,
                    method: init?.method ?? 'GET',
                    body: init?.body ? JSON.parse(init.body) : undefined,
                });
                const reply = replies.shift();

                if (!reply) {
                    throw new Error(`unexpected request ${url}`);
                }

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
});

describe('Authentication section', () => {
    it('shows only the fields of the chosen type, with secrets masked as password inputs', async () => {
        await mountForm();

        expect($('[data-test="auth-type"]')).not.toBeNull();
        expect($('[data-test="secret-bearer_token"]')).toBeNull();
        expect($('[data-test="api-key-name"]')).toBeNull();

        await choose('bearer');
        expect(
            $('[data-test="secret-bearer_token"]')?.getAttribute('type'),
        ).toBe('password');
        expect($('[data-test="api-key-name"]')).toBeNull();

        await choose('basic');
        expect($('[data-test="secret-bearer_token"]')).toBeNull();
        expect(
            $('[data-test="secret-basic_username"]')?.getAttribute('type'),
        ).toBe('password');
        expect(
            $('[data-test="secret-basic_password"]')?.getAttribute('type'),
        ).toBe('password');

        await choose('api_key');
        expect($('[data-test="api-key-name"]')).not.toBeNull();
        expect($('[data-test="secret-api_key"]')).not.toBeNull();
        expect($('[data-test="secret-basic_password"]')).toBeNull();

        await choose('none');
        expect($('[data-slot="secret-field"]')).toBeNull();
    });

    it('warns visibly when the API key is placed in the query string', async () => {
        await mountForm();
        await choose('api_key');
        expect($('[data-test="query-warning"]')).toBeNull();

        const placement = $(
            '[data-test="api-key-placement"]',
        ) as HTMLSelectElement;
        placement.value = 'query';
        placement.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();

        expect($('[data-test="query-warning"]')?.textContent).toContain(
            labels.queryWarning,
        );
    });

    it('shows a saved secret as a mask, its date and Replace, with no value and no reveal control', async () => {
        replies = [ok(one(source()))];
        await mountForm({ dataSourceId: 'ds-1' });

        const field = $('[data-slot="secret-field"]') as HTMLElement;
        expect(field.getAttribute('data-state')).toBe('saved');
        expect(field.textContent).toContain(controlLabels.secretMask);
        expect(field.textContent).toContain('set ');
        expect(field.textContent).toContain('Secret set on');
        expect(field.querySelector('input')).toBeNull();
        expect(field.textContent).not.toContain(CANARY);
        expect(
            $$('button').filter((b) =>
                /show|reveal/i.test(b.textContent ?? ''),
            ),
        ).toEqual([]);
        // Nothing changed, so no password is asked for.
        expect($('[data-test="confirm-password"]')).toBeNull();
    });

    it('clears the field on Replace and requires a new value before saving', async () => {
        replies = [ok(one(source()))];
        await mountForm({ dataSourceId: 'ds-1' });

        await click($('[aria-label="' + controlLabels.replaceToken + '"]'));
        expect($('[data-state="replacing"]')).not.toBeNull();

        await click(save());
        expect(writes()).toHaveLength(0);
        expect(document.body.textContent).toContain(
            labels.reasons['secret-required'],
        );
    });

    it('asks for the password only when a secret value or the auth type changes, and sends it with the new value', async () => {
        replies = [ok(one(source()))];
        await mountForm({ dataSourceId: 'ds-1' });
        expect($('[data-test="confirm-password"]')).toBeNull();

        await click($('[aria-label="' + controlLabels.replaceToken + '"]'));
        await type('[data-test="secret-bearer_token"]', CANARY);
        expect($('[data-test="confirm-password"]')?.getAttribute('type')).toBe(
            'password',
        );

        await click(save());
        expect(writes()).toHaveLength(0);
        expect(document.body.textContent).toContain(
            labels.confirmPasswordHelper,
        );

        replies = [ok(one(source({ revision: 3 })))];
        await type('[data-test="confirm-password"]', 'my-password');
        await click(save());

        expect(writes()[0]).toMatchObject({
            method: 'PUT',
            body: {
                auth_type: 'bearer',
                revision: 2,
                secrets: { bearer_token: CANARY },
                confirm_password: 'my-password',
            },
        });
        expect(visit).toHaveBeenCalledWith('/admin/data-sources?updated=ds-1');
    });

    it('leaves an untouched saved secret out of the request', async () => {
        replies = [ok(one(source()))];
        await mountForm({ dataSourceId: 'ds-1' });
        replies = [ok(one(source({ revision: 3 })))];

        await type('[data-test="name"]', 'Renamed');
        await click(save());

        const body = writes()[0].body as Record<string, unknown>;
        expect(body).not.toHaveProperty('secrets');
        expect(body).not.toHaveProperty('confirm_password');
    });

    it('asks for the password when the auth type changes', async () => {
        replies = [ok(one(source()))];
        await mountForm({ dataSourceId: 'ds-1' });

        await choose('none');
        expect($('[data-test="confirm-password"]')).not.toBeNull();
    });

    it('shows a wrong password as a field error and keeps the typed values', async () => {
        await mountForm();
        await type('[data-test="name"]', 'Sales API');
        await type('[data-test="base-url"]', 'https://api.example.com/v1');
        await choose('bearer');
        await type('[data-test="secret-bearer_token"]', CANARY);
        await type('[data-test="confirm-password"]', 'wrong');

        replies = [
            refused(422, {
                errors: { confirm_password: [labels.confirmPasswordWrong] },
            }),
        ];
        await click(save());

        expect(document.body.textContent).toContain(
            labels.confirmPasswordWrong,
        );
        expect(
            ($('[data-test="secret-bearer_token"]') as HTMLInputElement).value,
        ).toBe(CANARY);
    });

    it('tells the Admin when credentials cannot be saved yet (503)', async () => {
        await mountForm();
        await type('[data-test="name"]', 'Sales API');
        await type('[data-test="base-url"]', 'https://api.example.com/v1');
        await choose('bearer');
        await type('[data-test="secret-bearer_token"]', CANARY);
        await type('[data-test="confirm-password"]', 'pw');

        replies = [
            refused(503, {
                error: { code: 'connector.secrets_not_configured' },
            }),
        ];
        await click(save());

        expect($('[data-test="save-failure"]')?.textContent).toContain(
            labels.credentialsUnavailable,
        );
    });
});

describe('Secret default headers', () => {
    it('marks a header secret, masks its value and sends it with the flag', async () => {
        await mountForm();
        await type('[data-test="name"]', 'Sales API');
        await type('[data-test="base-url"]', 'https://api.example.com/v1');
        await click($('[data-test="add-header"]'));
        await type('[data-test="header-name"]', 'X-Team-Key');
        await click($('[data-test="header-secret"]'));

        expect(
            $('[data-test="header-secret-value"]')?.getAttribute('type'),
        ).toBe('password');
        await type('[data-test="header-secret-value"]', CANARY);
        await type('[data-test="confirm-password"]', 'pw');

        replies = [
            ok(
                one(
                    source({
                        data_source_id: 'ds-9',
                        auth_type: 'none',
                        secrets: {},
                    }),
                ),
            ),
        ];
        await click(save());

        expect(writes()[0].body).toMatchObject({
            headers: [{ name: 'X-Team-Key', secret: true, value: CANARY }],
            confirm_password: 'pw',
        });
    });

    it('shows a saved secret header as set-and-replace only', async () => {
        replies = [
            ok(
                one(
                    source({
                        auth_type: 'none',
                        headers: [{ name: 'X-Team-Key', secret: true }],
                        secrets: {
                            'header:x-team-key': {
                                configured: true,
                                updated_at: '2026-10-02T09:30:00Z',
                            },
                        },
                    }),
                ),
            ),
        ];
        await mountForm({ dataSourceId: 'ds-1' });

        const field = $('[data-slot="secret-field"]') as HTMLElement;
        expect(field.getAttribute('data-state')).toBe('saved');
        expect(field.querySelector('input')).toBeNull();
    });
});

describe('Changes that need the password without a new secret', () => {
    it('asks for it when a saved API key is moved to the query string or renamed', async () => {
        replies = [
            ok(
                one(
                    source({
                        auth_type: 'api_key',
                        api_key_name: 'X-Api-Key',
                        api_key_placement: 'header',
                        secrets: {
                            api_key: {
                                configured: true,
                                updated_at: '2026-10-02T09:30:00Z',
                            },
                        },
                    }),
                ),
            ),
        ];
        await mountForm({ dataSourceId: 'ds-1' });
        expect($('[data-test="confirm-password"]')).toBeNull();

        const placement = $(
            '[data-test="api-key-placement"]',
        ) as HTMLSelectElement;
        placement.value = 'query';
        placement.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        expect($('[data-test="confirm-password"]')).not.toBeNull();

        placement.value = 'header';
        placement.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        expect($('[data-test="confirm-password"]')).toBeNull();

        await type('[data-test="api-key-name"]', 'X-Other');
        expect($('[data-test="confirm-password"]')).not.toBeNull();
    });

    it('asks for it, and sends it, when a saved secret header is unflagged or removed', async () => {
        const saved = () =>
            ok(
                one(
                    source({
                        auth_type: 'none',
                        headers: [{ name: 'X-Team-Key', secret: true }],
                        secrets: {
                            'header:x-team-key': {
                                configured: true,
                                updated_at: '2026-10-02T09:30:00Z',
                            },
                        },
                    }),
                ),
            );
        replies = [saved()];
        await mountForm({ dataSourceId: 'ds-1' });
        expect($('[data-test="confirm-password"]')).toBeNull();

        // Unflagged: the value becomes a plain one.
        await click($('[data-test="header-secret"]'));
        expect($('[data-test="confirm-password"]')).not.toBeNull();

        // Removed: the secret goes with the row.
        wrapper?.unmount();
        document.body.innerHTML = '';
        replies = [saved()];
        await mountForm({ dataSourceId: 'ds-1' });
        await click($('[data-test="remove-header"]'));
        expect($('[data-test="confirm-password"]')).not.toBeNull();

        await type('[data-test="confirm-password"]', 'pw');
        replies = [ok(one(source({ auth_type: 'none', secrets: {} })))];
        await click(save());

        expect(writes()[0].body).toMatchObject({
            headers: [],
            confirm_password: 'pw',
        });
    });
});

describe('Unsaved secrets', () => {
    it('are never stored: an expiry snapshot keeps no secret value, and the form registers no draft', async () => {
        await mountForm();
        await choose('bearer');
        await type('[data-test="secret-bearer_token"]', CANARY);

        saveFormDrafts();

        expect(JSON.stringify(Object.entries(sessionStorage))).not.toContain(
            CANARY,
        );
        expect(JSON.stringify(Object.entries(localStorage))).not.toContain(
            CANARY,
        );
        expect(window.location.href).not.toContain(CANARY);
    });
});
