// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { DataSource } from '../../resources/js/lib/dataSources';
import { SECRET_SLOTS } from '../../resources/js/lib/dataSources';
import { createCatalogue } from '../../resources/js/lib/i18n';
import {
    controlLabels,
    dataSourceLabels as labels,
} from '../../resources/js/locales/labels';

// Story 2.7: the OAuth2 client credentials fields of the Data source form. The Authentication section offers the type with
// Token URL (blur check against the allowlist), Client ID, a write-only Client secret and an optional Scope; the typed secret
// and fields travel to Save and to Test connection; the password is asked when the token URL or client ID of a source that
// holds a client secret changes; a failed token (authentication failed) shows its own card message.
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
const CANARY = 'CANARY-oauth-ui-8d20';

function source(overrides: Partial<DataSource> = {}): DataSource {
    return {
        data_source_id: 'ds-1',
        name: 'Sales API',
        base_url: 'https://api.example.com/v1',
        scheme: 'https',
        host: 'api.example.com',
        port: 443,
        auth_type: 'oauth2_client_credentials',
        api_key_name: null,
        api_key_placement: null,
        oauth_token_url: 'https://auth.example.com/oauth/token',
        oauth_client_id: 'client-1',
        oauth_scope: 'read write',
        headers: [],
        secrets: {
            oauth_client_secret: {
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
const calls: Array<{ url: string; method: string; body: any }> = [];
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

async function blur(selector: string): Promise<void> {
    ($(selector) as HTMLElement).dispatchEvent(new Event('blur'));
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
    window.history.replaceState({}, '', '/admin/data-sources/create');
    sessionStorage.clear();
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
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
                    headers: { get: () => null },
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

describe('OAuth2 client credentials fields', () => {
    it('knows the client secret as the only slot of the type', () => {
        expect(SECRET_SLOTS.oauth2_client_credentials).toEqual([
            'oauth_client_secret',
        ]);
    });

    it('offers the type with Token URL, Client ID, a masked Client secret and an optional Scope, and nothing of the other types', async () => {
        await mountForm();

        const options = Array.from(
            document.querySelectorAll('[data-test="auth-type"] option'),
        ).map((option) => (option as HTMLOptionElement).value);
        expect(options).toContain('oauth2_client_credentials');
        expect($('[data-test="oauth-token-url"]')).toBeNull();

        await choose('oauth2_client_credentials');

        expect($('[data-test="oauth-token-url"]')).not.toBeNull();
        expect($('[data-test="oauth-client-id"]')).not.toBeNull();
        expect($('[data-test="oauth-scope"]')).not.toBeNull();
        expect(
            $('[data-test="secret-oauth_client_secret"]')?.getAttribute('type'),
        ).toBe('password');
        expect($('[data-test="secret-bearer_token"]')).toBeNull();
        expect(document.body.textContent).toContain(labels.oauthTokenUrl);
        expect(document.body.textContent).toContain(labels.oauthClientId);
        expect(document.body.textContent).toContain(labels.oauthClientSecret);
        expect(document.body.textContent).toContain(labels.oauthScope);

        await choose('bearer');
        expect($('[data-test="oauth-token-url"]')).toBeNull();
        expect($('[data-test="secret-oauth_client_secret"]')).toBeNull();
    });

    it('checks the token URL on blur, shows host-not-allowlisted inline and holds Save aria-disabled with its reason', async () => {
        await mountForm();
        await choose('oauth2_client_credentials');
        replies = [
            refused(422, {
                error: { code: 'platform.validation_failed' },
                errors: {
                    oauth_token_url: [
                        "This host isn't on your workspace allowlist.",
                    ],
                },
                reasons: { oauth_token_url: 'host-not-allowlisted' },
            }),
        ];

        await type('[data-test="oauth-token-url"]', 'https://evil.example.net');
        await blur('[data-test="oauth-token-url"]');

        expect(calls[0]).toMatchObject({
            url: '/api/v1/admin/data-sources/check-url',
            method: 'POST',
            body: { oauth_token_url: 'https://evil.example.net' },
        });
        expect(
            $('[data-test="oauth-token-url"]')?.getAttribute('aria-invalid'),
        ).toBe('true');
        expect(document.body.textContent).toContain(
            "This host isn't on your workspace allowlist.",
        );
        expect(save().getAttribute('aria-disabled')).toBe('true');
        const reasonId = save().getAttribute('aria-describedby') as string;
        expect(document.getElementById(reasonId)?.textContent).toBe(
            labels.saveBlockedToken,
        );

        // Changing the address lifts the block until the next check.
        await type(
            '[data-test="oauth-token-url"]',
            'https://auth.example.com/token',
        );
        expect(save().getAttribute('aria-disabled')).toBeNull();
    });

    it('says "token URL" for a refusal written for the base URL, and accepts an allowed one without a message', async () => {
        await mountForm();
        await choose('oauth2_client_credentials');
        replies = [
            refused(422, {
                errors: { oauth_token_url: ['x'] },
                reasons: { oauth_token_url: 'scheme' },
            }),
            ok({ data: { allowed: true } }),
        ];

        await type('[data-test="oauth-token-url"]', 'ftp://auth.example.com');
        await blur('[data-test="oauth-token-url"]');
        expect(document.body.textContent).toContain(
            'The token URL must start with http:// or https://.',
        );
        // The text is the token URL's own label for the reason, not the base URL's rewritten.
        expect(document.body.textContent).toContain(
            labels.tokenUrlReasons.scheme,
        );
        expect(labels.tokenUrlReasons.scheme).not.toBe(labels.reasons.scheme);
        expect(save().getAttribute('aria-disabled')).toBe('true');
        expect(
            document.getElementById(
                save().getAttribute('aria-describedby') as string,
            )?.textContent,
        ).toBe(labels.saveBlockedTokenUrl);

        await type(
            '[data-test="oauth-token-url"]',
            'https://auth.example.com/token',
        );
        await blur('[data-test="oauth-token-url"]');
        expect(
            $('[data-test="oauth-token-url"]')?.getAttribute('aria-invalid'),
        ).toBeNull();
        expect(save().getAttribute('aria-disabled')).toBeNull();
    });

    it('needs the token URL, client ID and secret before it saves, and sends all of them with the password', async () => {
        await mountForm();
        await type('[data-test="name"]', 'Sales API');
        await type('[data-test="base-url"]', 'https://api.example.com/v1');
        await choose('oauth2_client_credentials');

        await click(save());
        expect(writes()).toHaveLength(0);
        expect(document.body.textContent).toContain(labels.tokenUrlRequired);
        expect(document.body.textContent).toContain(labels.clientIdRequired);

        replies = [ok(one(source({ revision: 1 })))];
        await type(
            '[data-test="oauth-token-url"]',
            ' https://auth.example.com/oauth/token ',
        );
        await type('[data-test="oauth-client-id"]', 'client-1');
        await type('[data-test="oauth-scope"]', 'read write');
        await type('[data-test="secret-oauth_client_secret"]', CANARY);
        await type('[data-test="confirm-password"]', 'my-password');
        await click(save());

        expect(writes()[0]).toMatchObject({
            method: 'POST',
            body: {
                auth_type: 'oauth2_client_credentials',
                oauth_token_url: 'https://auth.example.com/oauth/token',
                oauth_client_id: 'client-1',
                oauth_scope: 'read write',
                secrets: { oauth_client_secret: CANARY },
                confirm_password: 'my-password',
            },
        });
    });

    it('leaves the OAuth fields out of a request for another type', async () => {
        await mountForm();
        await type('[data-test="name"]', 'Sales API');
        await type('[data-test="base-url"]', 'https://api.example.com/v1');
        await choose('oauth2_client_credentials');
        await type('[data-test="oauth-token-url"]', 'https://auth.example.com');
        await choose('none');
        replies = [ok(one(source({ auth_type: 'none' })))];

        await click(save());

        const body = writes()[0].body as Record<string, unknown>;
        expect(body).not.toHaveProperty('oauth_token_url');
        expect(body).not.toHaveProperty('oauth_client_id');
        expect(body).not.toHaveProperty('oauth_scope');
        expect(body).not.toHaveProperty('secrets');
    });

    it('fills a saved source, shows the client secret as a mask with its date and no value', async () => {
        replies = [ok(one(source()))];
        await mountForm({ dataSourceId: 'ds-1' });

        expect(
            ($('[data-test="oauth-token-url"]') as HTMLInputElement).value,
        ).toBe('https://auth.example.com/oauth/token');
        expect(
            ($('[data-test="oauth-client-id"]') as HTMLInputElement).value,
        ).toBe('client-1');
        expect(($('[data-test="oauth-scope"]') as HTMLInputElement).value).toBe(
            'read write',
        );
        expect(document.body.textContent).toContain(controlLabels.secretMask);
        expect(document.body.textContent).toContain('set ');
        expect($('[data-test="confirm-password"]')).toBeNull();
        // A saved secret has no input and no value until Replace is pressed.
        expect($('[data-test="secret-oauth_client_secret"]')).toBeNull();
    });

    it('asks for the password when the token URL or client ID of a source with a client secret changes, but not for the scope', async () => {
        replies = [ok(one(source()))];
        await mountForm({ dataSourceId: 'ds-1' });

        await type('[data-test="oauth-scope"]', 'read');
        expect($('[data-test="confirm-password"]')).toBeNull();

        await type('[data-test="oauth-client-id"]', 'client-2');
        expect($('[data-test="confirm-password"]')).not.toBeNull();
        await type('[data-test="oauth-client-id"]', 'client-1');
        expect($('[data-test="confirm-password"]')).toBeNull();

        await type(
            '[data-test="oauth-token-url"]',
            'https://other.example.com/token',
        );
        expect($('[data-test="confirm-password"]')).not.toBeNull();

        await click(save());
        expect(writes()).toHaveLength(0);
        expect(document.body.textContent).toContain(
            labels.confirmPasswordHelper,
        );
    });

    it('sends a Replace of the client secret with the password and leaves an untouched one out', async () => {
        replies = [ok(one(source()))];
        await mountForm({ dataSourceId: 'ds-1' });
        replies = [ok(one(source({ revision: 3 })))];

        await type('[data-test="name"]', 'Renamed');
        await click(save());
        const quiet = writes()[0].body as Record<string, unknown>;
        expect(quiet).not.toHaveProperty('secrets');
        expect(quiet).not.toHaveProperty('confirm_password');
        expect(quiet).toMatchObject({
            oauth_token_url: 'https://auth.example.com/oauth/token',
            oauth_client_id: 'client-1',
            oauth_scope: 'read write',
        });
    });

    it('never keeps the client secret in a snapshot or storage', async () => {
        await mountForm();
        await choose('oauth2_client_credentials');
        await type('[data-test="secret-oauth_client_secret"]', CANARY);

        for (const store of [sessionStorage, localStorage]) {
            for (let i = 0; i < store.length; i++) {
                const key = store.key(i) as string;

                expect(key + store.getItem(key)).not.toContain(CANARY);
            }
        }
    });
});

describe('Test connection with OAuth2', () => {
    const operation = (result: Record<string, unknown>): Reply =>
        ok({
            data: {
                id: 'op-1',
                kind: 'connection_test',
                status: 'failed',
                result,
                expires_at: 'x',
            },
        });

    it('sends the OAuth fields and the typed client secret, and shows authentication failed on its card', async () => {
        vi.useFakeTimers();
        await mountForm();
        await type('[data-test="name"]', 'Sales API');
        await type('[data-test="base-url"]', 'https://api.example.com/v1');
        await choose('oauth2_client_credentials');
        await type(
            '[data-test="oauth-token-url"]',
            'https://auth.example.com/oauth/token',
        );
        await type('[data-test="oauth-client-id"]', 'client-1');
        await type('[data-test="secret-oauth_client_secret"]', CANARY);
        replies = [
            {
                ok: true,
                status: 202,
                json: {
                    data: {
                        operation_id: 'op-1',
                        status: 'queued',
                        expires_at: 'x',
                    },
                },
            },
            operation({
                ok: false,
                status: 200,
                latency_ms: 90,
                code: 'auth-failed',
                reason: 'connector.auth_failed:api_401',
                host: 'api.example.com',
                request_id: 'req-oauth-1',
            }),
        ];

        await click($('[data-test="test-connection"]'));
        await vi.advanceTimersByTimeAsync(1000);
        await flushPromises();

        expect(calls[0]).toMatchObject({
            url: '/api/v1/admin/data-sources/test-connection',
            method: 'POST',
            body: {
                auth_type: 'oauth2_client_credentials',
                oauth_token_url: 'https://auth.example.com/oauth/token',
                oauth_client_id: 'client-1',
                secrets: { oauth_client_secret: CANARY },
            },
        });
        expect(calls[0].body).not.toHaveProperty('confirm_password');

        const message = $('[data-test="fetch-error-message"]')?.textContent;
        expect(document.activeElement).toBe(
            $('[data-test="fetch-error-title"]'),
        );
        expect(message).toBe(labels.authFailed('Sales API'));
        expect(message).toContain('Authentication failed');
        expect(document.body.innerHTML).not.toContain(CANARY);
    });
});
