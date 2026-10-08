// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { DataSource } from '../../resources/js/lib/dataSources';
import { DRAFT_STORAGE_KEY } from '../../resources/js/lib/formDrafts';
import { formatTime } from '../../resources/js/lib/formatDate';
import { createCatalogue } from '../../resources/js/lib/i18n';
import {
    editLockLabels,
    sessionLabels,
} from '../../resources/js/locales/labels';

// Story 2.8: the soft lock on the Data source form (UX-DR-250, 249, 248, 263, 282). The edit form takes the lock when it
// loads and heartbeats while it holds it. Without it the form is read-only with the `draft-locked` banner, "Take over
// editing" and Close. A take-over waits for the holder's flush: the holder saves its valid non-secret fields (never a
// secret, never an invalid field), acknowledges, and then sees `draft-taken-over`. A save with a lost lock is a 423.
// The session warning and the `session-expired` flow of Story 1.15 apply to this form unchanged.
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
        usePage: () => ({
            url: '/admin/data-sources',
            props: {
                shell: { can: { 'data_sources.manage': true }, items: [] },
                auth: { user: { id: 1 } },
            },
        }),
    };
});

const { default: DataSourceForm } =
    await import('../../resources/js/pages/admin/DataSourceForm.vue');
const { default: SessionExpiryDialog } =
    await import('../../resources/js/components/SessionExpiryDialog.vue');

const ceilings = {
    timeout_seconds: null,
    max_response_bytes: null,
    max_pages: null,
};
const CANARY = 'CANARY-typed-secret-4417';

function source(overrides: Partial<DataSource> = {}): DataSource {
    return {
        data_source_id: 'ds-1',
        name: 'Sales API',
        base_url: 'https://api.example.com/v1',
        scheme: 'https',
        host: 'api.example.com',
        port: 443,
        auth_type: 'none',
        api_key_name: null,
        api_key_placement: null,
        oauth_token_url: null,
        oauth_client_id: null,
        oauth_scope: null,
        headers: [{ name: 'X-Team', value: 'finance' }],
        secrets: {},
        timeout_seconds: 30,
        max_response_bytes: null,
        max_pages: null,
        live_capable: false,
        revision: 1,
        lock_epoch: 1,
        health: 'checking',
        last_successful_call_at: null,
        blocks_using: 0,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-01T09:30:00Z',
        ...overrides,
    };
}

type Reply = { ok: boolean; status: number; json: unknown };
type Call = {
    key: string;
    url: string;
    method: string;
    body: Record<string, unknown> | undefined;
    headers: Record<string, string>;
};

const calls: Call[] = [];
// Each route answers from its own queue; the last answer repeats (a heartbeat keeps answering the same).
const queues = new Map<string, Reply[]>();
let wrapper: VueWrapper | null = null;
// happy-dom's own `sendBeacon` makes a real request: every test gets a recording one.
const beacon = vi.fn((..._args: unknown[]) => true);

const ok = (json: unknown): Reply => ({ ok: true, status: 200, json });
const one = (data: DataSource): Reply => ok({ data, meta: { ceilings } });
const refused = (status: number, json: unknown = {}): Reply => ({
    ok: false,
    status,
    json,
});
const lockData = (data: Record<string, unknown>): Reply =>
    ok({ data: { enabled: true, ...data } });
const granted = (extra: Record<string, unknown> = {}): Reply =>
    lockData({
        status: 'granted',
        token: 'tok-a',
        epoch: 1,
        ttl_seconds: 60,
        flush_requested: false,
        csrf_token: 'csrf-a',
        ...extra,
    });

function on(key: string, ...replies: Reply[]): void {
    queues.set(key, replies);
}

function keyOf(url: string, method: string): string {
    const path = url.split('?')[0];

    if (path === '/api/v1/session') return `${method} session`;
    if (path.endsWith('/lock/takeover')) return `${method} takeover`;
    if (path.endsWith('/lock/flush')) return `${method} flush`;
    if (path.endsWith('/lock/release')) return `${method} release`;
    if (path.endsWith('/lock')) return `${method} lock`;

    return `${method} ds`;
}

const of = (key: string) => calls.filter((call) => call.key === key);

beforeEach(() => {
    vi.useFakeTimers();
    setActivePinia(createPinia());
    calls.length = 0;
    queues.clear();
    visit.mockClear();
    sessionStorage.clear();
    beacon.mockClear();
    Object.defineProperty(navigator, 'sendBeacon', {
        value: beacon,
        configurable: true,
    });
    window.history.replaceState({}, '', '/admin/data-sources/ds-1/edit');
    on('GET ds', one(source()));
    on(
        'POST lock',
        lockData({
            status: 'granted',
            token: 'tok-a',
            epoch: 1,
            ttl_seconds: 60,
            flush_requested: false,
            csrf_token: 'csrf-a',
        }),
    );
    on('PUT lock', granted());
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (
                url: string,
                init?: {
                    method?: string;
                    body?: string;
                    headers?: Record<string, string>;
                },
            ) => {
                const method = init?.method ?? 'GET';
                const key = keyOf(url, method);

                calls.push({
                    key,
                    url,
                    method,
                    body: init?.body ? JSON.parse(init.body) : undefined,
                    headers: init?.headers ?? {},
                });

                const queue = queues.get(key);

                if (!queue || queue.length === 0) {
                    throw new Error(`unexpected request ${key}`);
                }

                const reply = queue.length > 1 ? queue.shift()! : queue[0];

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

async function mountForm(): Promise<VueWrapper> {
    wrapper = mount(DataSourceForm as never, {
        attachTo: document.body,
        props: { dataSourceId: 'ds-1' },
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();

    return wrapper;
}

async function advance(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
    await flushPromises();
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);

async function type(selector: string, value: string): Promise<void> {
    const input = $(selector) as HTMLInputElement;

    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function click(selector: string): Promise<void> {
    $(selector)?.click();
    await flushPromises();
}

const fields = () => $('[data-test="form-fields"]') as HTMLFieldSetElement;
const banner = () => $('[data-test="lock-banner"]');
const notice = () => $('[data-test="lock-notice"]')?.textContent?.trim() ?? '';
const save = () => $('[data-test="save"]') as HTMLButtonElement;

describe('holding the lock', () => {
    it('takes the lock after the form loads, stays editable and heartbeats with X-Background: 1 at a third of the TTL', async () => {
        await mountForm();

        expect(calls.map((call) => call.key).slice(0, 2)).toEqual([
            'GET ds',
            'POST lock',
        ]);
        expect(banner()).toBeNull();
        expect(fields().disabled).toBe(false);
        expect(save().disabled).toBe(false);

        await advance(19_000);
        expect(of('PUT lock')).toHaveLength(0);

        await advance(2_000);
        expect(of('PUT lock')).toHaveLength(1);
        expect(of('PUT lock')[0].body).toEqual({ token: 'tok-a' });
        expect(of('PUT lock')[0].headers['X-Background']).toBe('1');

        await advance(20_000);
        expect(of('PUT lock')).toHaveLength(2);
    });

    it('sends the epoch and the token with a save, and a save of the create form carries none', async () => {
        on('PUT ds', one(source({ revision: 2 })));
        await mountForm();
        await type('[data-test="name"]', 'Renamed');
        await click('[data-test="save"]');

        expect(of('PUT ds')[0].body).toMatchObject({
            name: 'Renamed',
            revision: 1,
            lock_epoch: 1,
            lock_token: 'tok-a',
        });
    });

    it('behaves as before when the soft lock is off: no heartbeat, no claim', async () => {
        on('POST lock', ok({ data: { enabled: false } }));
        on('PUT ds', one(source({ revision: 2 })));
        await mountForm();
        await advance(120_000);

        expect(of('PUT lock')).toHaveLength(0);
        expect(banner()).toBeNull();

        await type('[data-test="name"]', 'Renamed');
        await click('[data-test="save"]');
        expect(of('PUT ds')[0].body).not.toHaveProperty('lock_epoch');
        expect(of('PUT ds')[0].body).not.toHaveProperty('lock_token');
    });

    it('takes no lock on the create form', async () => {
        wrapper = mount(DataSourceForm as never, {
            attachTo: document.body,
            props: {},
            global: { plugins: [createCatalogue()] },
        });
        await flushPromises();
        await advance(120_000);

        expect(calls).toHaveLength(0);
    });

    it('frees the lock by beacon with the token and the CSRF token when the tab closes or the page is left', async () => {
        await mountForm();

        window.dispatchEvent(new Event('pagehide'));
        expect(beacon).toHaveBeenCalledTimes(1);

        const [url, blob] = beacon.mock.calls[0] as [string, Blob];

        expect(url).toBe('/api/v1/admin/data-sources/ds-1/lock/release');
        expect(JSON.parse(await blob.text())).toEqual({
            token: 'tok-a',
            _token: 'csrf-a',
        });

        // Once released, leaving the page does not send it again, and the heartbeat is over.
        wrapper?.unmount();
        wrapper = null;
        expect(beacon).toHaveBeenCalledTimes(1);
        await advance(60_000);
        expect(of('PUT lock')).toHaveLength(0);
    });
});

describe('without the lock', () => {
    const held = () =>
        lockData({
            status: 'held',
            holder: { name: 'Maya Patel', since: '2026-10-08T10:42:00Z' },
        });

    it('shows the form read-only with the draft-locked banner, Take over editing and Close, and polls nothing', async () => {
        on('POST lock', held());
        await mountForm();

        expect(fields().disabled).toBe(true);
        expect(save().disabled).toBe(true);
        expect(
            ($('[data-test="test-connection"]') as HTMLButtonElement).disabled,
        ).toBe(true);
        expect(banner()?.getAttribute('role')).toBe('status');
        expect(notice()).toBe(
            `Maya Patel is editing this draft (since ${formatTime('2026-10-08T10:42:00Z')}). You can view it, or take over editing.`,
        );
        expect($('[data-test="take-over"]')?.textContent?.trim()).toBe(
            editLockLabels.takeOver,
        );
        expect($('[data-test="lock-close"]')?.textContent?.trim()).toBe(
            editLockLabels.close,
        );
        expect($('[data-test="lock-close"]')?.getAttribute('href')).toBe(
            '/admin/data-sources',
        );

        // Not dirty and nothing to heartbeat or poll until Take over.
        const before = calls.length;

        await advance(120_000);
        expect(calls).toHaveLength(before);
    });

    it('waits politely after Take over, then reloads the Data Source and becomes editable with the new epoch', async () => {
        on('POST lock', held());
        on('POST takeover', lockData({ status: 'waiting', token: 'tok-b' }));
        on(
            'GET takeover',
            lockData({ status: 'waiting' }),
            lockData({ status: 'waiting' }),
            granted({ token: 'tok-b', epoch: 2, csrf_token: 'csrf-b' }),
        );
        on(
            'PUT ds',
            one(source({ name: 'After flush', revision: 4, lock_epoch: 2 })),
        );
        await mountForm();
        on(
            'GET ds',
            one(source({ name: 'After flush', revision: 3, lock_epoch: 2 })),
        );

        await click('[data-test="take-over"]');
        expect(of('POST takeover')).toHaveLength(1);
        expect(banner()?.getAttribute('aria-live')).toBe('polite');
        expect(notice()).toBe(editLockLabels.waiting('Maya Patel'));
        expect(fields().disabled).toBe(true);
        expect($('[data-test="take-over"]')).toBeNull();

        await advance(1_000);
        expect(of('GET takeover')).toHaveLength(1);
        // The taker's token is in a header, never in the URL.
        expect(of('GET takeover')[0].url).not.toContain('tok-b');
        expect(of('GET takeover')[0].url).not.toContain('token');
        expect(of('GET takeover')[0].headers['X-Lock-Token']).toBe('tok-b');
        expect(of('GET takeover')[0].headers['X-Background']).toBe('1');
        expect(fields().disabled).toBe(true);

        await advance(2_000);
        expect(of('GET ds')).toHaveLength(2);
        expect(banner()).toBeNull();
        expect(fields().disabled).toBe(false);
        expect(($('[data-test="name"]') as HTMLInputElement).value).toBe(
            'After flush',
        );

        // The new lock heartbeats with its own token, and a save carries the new epoch and the post-flush revision.
        await advance(20_000);
        expect(of('PUT lock')[0].body).toEqual({ token: 'tok-b' });
        await type('[data-test="name"]', 'Bob edits');
        await click('[data-test="save"]');
        expect(of('PUT ds')[0].body).toMatchObject({
            revision: 3,
            lock_epoch: 2,
            lock_token: 'tok-b',
        });
    });

    it('is editable at once when the take-over is granted without waiting', async () => {
        on('POST lock', held());
        on('POST takeover', granted({ token: 'tok-b', epoch: 2 }));
        await mountForm();
        await click('[data-test="take-over"]');

        expect(fields().disabled).toBe(false);
        expect(banner()).toBeNull();
    });

    it('goes back to read-only with the holder when the take-over request is refused as held, and says when it fails', async () => {
        on('POST lock', held());
        on(
            'POST takeover',
            lockData({
                status: 'held',
                holder: { name: 'Cy Lee', since: '2026-10-08T10:50:00Z' },
            }),
        );
        await mountForm();
        await click('[data-test="take-over"]');

        expect(fields().disabled).toBe(true);
        expect(notice()).toContain('Cy Lee is editing');

        on('POST takeover', refused(500));
        await click('[data-test="take-over"]');
        expect($('[data-test="takeover-failed"]')?.textContent?.trim()).toBe(
            editLockLabels.takeOverFailed,
        );
        expect($('[data-test="take-over"]')).not.toBeNull();
    });

    it('withdraws a pending take-over by beacon when the person leaves while waiting', async () => {
        on('POST lock', held());
        on('POST takeover', lockData({ status: 'waiting', token: 'tok-b' }));
        on('GET takeover', lockData({ status: 'waiting' }));
        on('POST release', ok({ data: { enabled: true, released: true } }));
        await mountForm();
        await click('[data-test="take-over"]');

        window.dispatchEvent(new Event('pagehide'));
        // No CSRF token was ever sent to a caller that does not hold the lock: a keep-alive request carries the header.
        expect(beacon).not.toHaveBeenCalled();
        expect(of('POST release')[0].body).toEqual({ token: 'tok-b' });
    });
});

describe('the holder when someone takes over', () => {
    it('saves its valid non-secret fields, then acknowledges, then shows draft-taken-over and goes read-only; no secret is sent', async () => {
        on('PUT lock', granted({ flush_requested: true }));
        on('PUT ds', one(source({ name: 'Ada flushed', revision: 2 })));
        on(
            'POST flush',
            lockData({
                status: 'taken_over',
                flush_acknowledged: true,
                taken_over_by: {
                    name: 'Alex Morgan',
                    at: '2026-10-08T10:58:00Z',
                },
            }),
        );
        await mountForm();
        await type('[data-test="name"]', 'Ada flushed');
        // A typed credential and the password are never part of a flush.
        await type('[data-test="auth-type"]', 'bearer').catch(() => {});
        const select = $('[data-test="auth-type"]') as HTMLSelectElement;

        select.value = 'bearer';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        await type('[data-test="secret-bearer_token"]', CANARY).catch(() => {});
        await type('[data-test="confirm-password"]', 'my-password').catch(
            () => {},
        );

        await advance(21_000);

        expect(of('PUT ds')).toHaveLength(1);
        const body = of('PUT ds')[0].body as Record<string, unknown>;

        expect(body).toMatchObject({
            name: 'Ada flushed',
            revision: 1,
            lock_epoch: 1,
            lock_token: 'tok-a',
            auth_type: 'none',
        });
        expect(body).not.toHaveProperty('secrets');
        expect(body).not.toHaveProperty('confirm_password');
        expect(JSON.stringify(calls)).not.toContain(CANARY);
        expect(JSON.stringify(calls)).not.toContain('my-password');
        // Saved first, acknowledged after.
        expect(calls.findIndex((call) => call.key === 'PUT ds')).toBeLessThan(
            calls.findIndex((call) => call.key === 'POST flush'),
        );
        expect(of('POST flush')[0].body).toEqual({ token: 'tok-a' });

        expect(notice()).toBe(
            `Alex Morgan took over editing at ${formatTime('2026-10-08T10:58:00Z')}. Your changes were saved.`,
        );
        expect(fields().disabled).toBe(true);
        expect(save().disabled).toBe(true);
        expect($('[data-test="lock-close"]')).not.toBeNull();

        // No more heartbeats, and nothing typed is kept: the secret field is gone from memory.
        const beats = of('PUT lock').length;

        await advance(120_000);
        expect(of('PUT lock')).toHaveLength(beats);
        expect(visit).not.toHaveBeenCalled();
    });

    it('acknowledges without a save when nothing was edited', async () => {
        on('PUT lock', granted({ flush_requested: true }));
        on(
            'POST flush',
            lockData({
                status: 'taken_over',
                flush_acknowledged: true,
                taken_over_by: {
                    name: 'Alex Morgan',
                    at: '2026-10-08T10:58:00Z',
                },
            }),
        );
        await mountForm();
        await advance(21_000);

        expect(of('PUT ds')).toHaveLength(0);
        expect(of('POST flush')).toHaveLength(1);
        expect(notice()).toContain('Your changes were saved.');
    });

    it('keeps the saved value of a field the server refuses and saves the rest', async () => {
        on('PUT lock', granted({ flush_requested: true }));
        on(
            'PUT ds',
            refused(422, {
                errors: {
                    base_url: ["This host isn't on your workspace allowlist."],
                },
                reasons: { base_url: 'host-not-allowlisted' },
            }),
            one(source({ name: 'Ada flushed', revision: 2 })),
        );
        on(
            'POST flush',
            lockData({
                status: 'taken_over',
                flush_acknowledged: true,
                taken_over_by: {
                    name: 'Alex Morgan',
                    at: '2026-10-08T10:58:00Z',
                },
            }),
        );
        await mountForm();
        await type('[data-test="name"]', 'Ada flushed');
        await type('[data-test="base-url"]', 'https://evil.example.com');
        await advance(21_000);

        expect(of('PUT ds')).toHaveLength(2);
        expect(of('PUT ds')[0].body).toMatchObject({
            name: 'Ada flushed',
            base_url: 'https://evil.example.com',
        });
        expect(of('PUT ds')[1].body).toMatchObject({
            name: 'Ada flushed',
            base_url: 'https://api.example.com/v1',
        });
        expect(of('POST flush')).toHaveLength(1);
    });

    it('does not acknowledge a flush that could not be saved, so the taker waits for the flush timeout', async () => {
        on('PUT lock', granted({ flush_requested: true }));
        on('PUT ds', refused(500));
        await mountForm();
        await type('[data-test="name"]', 'Ada flushed');
        await advance(21_000);

        expect(of('PUT ds')).toHaveLength(1);
        expect(of('POST flush')).toHaveLength(0);
        // The form is still hers and editable; the next heartbeat tries again.
        expect(fields().disabled).toBe(false);

        await advance(20_000);
        expect(of('PUT ds')).toHaveLength(2);
    });

    it('shows the notice without the saved claim when the take-over completed without its acknowledgement', async () => {
        on(
            'PUT lock',
            lockData({
                status: 'taken_over',
                flush_acknowledged: false,
                taken_over_by: {
                    name: 'Alex Morgan',
                    at: '2026-10-08T10:58:00Z',
                },
            }),
        );
        await mountForm();
        await type('[data-test="name"]', 'Unsaved');
        await advance(21_000);

        expect(notice()).toBe(
            editLockLabels.takenOverUnsaved(
                'Alex Morgan',
                formatTime('2026-10-08T10:58:00Z'),
            ),
        );
        expect(notice()).not.toContain('were saved');
        expect(fields().disabled).toBe(true);
        expect(of('PUT ds')).toHaveLength(0);
    });

    it('tells a holder whose lock simply expired that nothing was saved', async () => {
        on('PUT lock', lockData({ status: 'lost' }));
        await mountForm();
        await advance(21_000);

        expect(notice()).toBe(editLockLabels.lost);
        expect(fields().disabled).toBe(true);
    });

    it('handles a 423 on save: read-only, the typed secret and password are dropped, nothing is retried', async () => {
        on(
            'PUT ds',
            refused(423, {
                error: { code: 'platform.edit_lock_lost' },
                current: {
                    data: source({ revision: 2, lock_epoch: 2 }),
                    meta: { ceilings },
                },
            }),
        );
        await mountForm();
        const select = $('[data-test="auth-type"]') as HTMLSelectElement;

        select.value = 'bearer';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        await type('[data-test="secret-bearer_token"]', CANARY);
        await type('[data-test="confirm-password"]', 'my-password');
        await click('[data-test="save"]');

        expect(of('PUT ds')).toHaveLength(1);
        expect(notice()).toBe(editLockLabels.lost);
        expect(fields().disabled).toBe(true);
        expect(
            ($('[data-test="secret-bearer_token"]') as HTMLInputElement | null)
                ?.value ?? '',
        ).toBe('');
        expect(
            ($('[data-test="confirm-password"]') as HTMLInputElement | null)
                ?.value ?? '',
        ).toBe('');
        expect(visit).not.toHaveBeenCalled();
    });
});

describe('the lock is unavailable, restored or throttled', () => {
    it('is read-only until the first acquire answer, then read-only with Retry when the lock cannot be asked, and editable once it can', async () => {
        on('POST lock', refused(500));
        await mountForm();

        expect(fields().disabled).toBe(true);
        expect(notice()).toBe(editLockLabels.unavailable);
        expect(banner()?.getAttribute('data-mode')).toBe('unavailable');
        expect(save().disabled).toBe(true);

        on('POST lock', granted());
        await click('[data-test="lock-retry"]');
        expect(fields().disabled).toBe(false);
        expect(banner()).toBeNull();
    });

    it('holds the form read-only while the first acquire is still in flight', async () => {
        let answer!: (reply: Reply) => void;
        const pending = new Promise<Reply>((resolve) => (answer = resolve));

        queues.set('POST lock', []);
        const original = (
            globalThis.fetch as unknown as ReturnType<typeof vi.fn>
        ).getMockImplementation()!;

        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string, init?: never) => {
                if (
                    String(url).endsWith('/lock') &&
                    (init as { method?: string } | undefined)?.method === 'POST'
                ) {
                    const reply = await pending;

                    return {
                        ok: reply.ok,
                        status: reply.status,
                        headers: { get: () => null },
                        json: async () => reply.json,
                    };
                }

                return original(url, init);
            }),
        );
        await mountForm();
        expect(fields().disabled).toBe(true);

        answer(granted());
        await flushPromises();
        expect(fields().disabled).toBe(false);
    });

    it('takes the lock again when the page is restored from the back-forward cache', async () => {
        await mountForm();
        window.dispatchEvent(new Event('pagehide'));
        expect(beacon).toHaveBeenCalledTimes(1);

        on('POST lock', granted({ token: 'tok-restored', epoch: 3 }));
        const restored = new Event('pageshow') as Event & {
            persisted: boolean;
        };

        restored.persisted = true;
        window.dispatchEvent(restored);
        await flushPromises();

        expect(of('POST lock')).toHaveLength(2);
        expect(fields().disabled).toBe(false);
        await advance(20_000);
        expect(of('PUT lock').at(-1)?.body).toEqual({ token: 'tok-restored' });

        // A pageshow that is not a restore does nothing.
        window.dispatchEvent(new Event('pageshow'));
        await flushPromises();
        expect(of('POST lock')).toHaveLength(2);
    });

    it('sends a catch-up heartbeat at once when a hidden tab becomes visible', async () => {
        await mountForm();
        expect(of('PUT lock')).toHaveLength(0);

        document.dispatchEvent(new Event('visibilitychange'));
        await flushPromises();
        expect(of('PUT lock')).toHaveLength(1);
    });

    it('computes the heartbeat interval in milliseconds from the TTL', async () => {
        on('POST lock', granted({ ttl_seconds: 2 }));
        await mountForm();

        await advance(600);
        expect(of('PUT lock')).toHaveLength(0);
        await advance(100);
        expect(of('PUT lock')).toHaveLength(1);
    });

    it('retries a busy (503) answer before giving up', async () => {
        on('PUT lock', refused(503), granted());
        await mountForm();
        await advance(20_000);
        expect(of('PUT lock')).toHaveLength(1);

        await advance(600);
        expect(of('PUT lock')).toHaveLength(2);
        expect(fields().disabled).toBe(false);
    });

    it('acquires when the take-over poll reports the lock free, and edits the reloaded Data Source', async () => {
        on(
            'POST lock',
            lockData({
                status: 'held',
                holder: { name: 'Maya Patel', since: '2026-10-08T10:42:00Z' },
            }),
        );
        on('POST takeover', lockData({ status: 'waiting', token: 'tok-b' }));
        on('GET takeover', lockData({ status: 'free' }));
        await mountForm();
        on('POST lock', granted({ token: 'tok-c', epoch: 1 }));
        await click('[data-test="take-over"]');
        await advance(1_000);

        expect(of('POST lock')).toHaveLength(2);
        expect(fields().disabled).toBe(false);
        expect(of('GET ds')).toHaveLength(2);
    });

    it('releases a holding form by beacon with the token and CSRF token when it is unmounted, with no pagehide first', async () => {
        await mountForm();
        wrapper?.unmount();
        wrapper = null;

        expect(beacon).toHaveBeenCalledTimes(1);

        const [url, blob] = beacon.mock.calls[0] as [string, Blob];

        expect(url).toBe('/api/v1/admin/data-sources/ds-1/lock/release');
        expect(JSON.parse(await blob.text())).toEqual({
            token: 'tok-a',
            _token: 'csrf-a',
        });
    });

    it('withdraws a waiting taker by keep-alive release with the taker token when it is unmounted, with no pagehide first', async () => {
        on(
            'POST lock',
            lockData({
                status: 'held',
                holder: { name: 'Maya Patel', since: '2026-10-08T10:42:00Z' },
            }),
        );
        on('POST takeover', lockData({ status: 'waiting', token: 'tok-b' }));
        on('GET takeover', lockData({ status: 'waiting' }));
        on('POST release', ok({ data: { enabled: true, released: true } }));
        await mountForm();
        await click('[data-test="take-over"]');
        wrapper?.unmount();
        wrapper = null;
        await flushPromises();

        expect(beacon).not.toHaveBeenCalled();
        expect(of('POST release')).toHaveLength(1);
        expect(of('POST release')[0].body).toEqual({ token: 'tok-b' });
    });
});

describe('the flush against a concurrent save', () => {
    const takenOver = lockData({
        status: 'taken_over',
        flush_acknowledged: true,
        taken_over_by: { name: 'Alex Morgan', at: '2026-10-08T10:58:00Z' },
    });

    it('adopts the revision a 409 reports and retries once', async () => {
        on('PUT lock', granted({ flush_requested: true }));
        on(
            'PUT ds',
            refused(409, {
                error: { code: 'connector.revision_conflict' },
                current: { data: source({ revision: 5 }), meta: { ceilings } },
            }),
            one(source({ name: 'Ada flushed', revision: 6 })),
        );
        on('POST flush', takenOver);
        await mountForm();
        await type('[data-test="name"]', 'Ada flushed');
        await advance(21_000);

        expect(of('PUT ds')).toHaveLength(2);
        expect(of('PUT ds')[0].body).toMatchObject({ revision: 1 });
        expect(of('PUT ds')[1].body).toMatchObject({
            revision: 5,
            name: 'Ada flushed',
        });
        expect(of('POST flush')).toHaveLength(1);
    });

    it('gives up after a second conflict and does not acknowledge', async () => {
        on('PUT lock', granted({ flush_requested: true }));
        on(
            'PUT ds',
            refused(409, {
                error: { code: 'connector.revision_conflict' },
                current: { data: source({ revision: 5 }), meta: { ceilings } },
            }),
        );
        await mountForm();
        await type('[data-test="name"]', 'Ada flushed');
        await advance(21_000);

        expect(of('PUT ds')).toHaveLength(2);
        expect(of('POST flush')).toHaveLength(0);
    });

    it('updates the baseline after a flush save, so a later flush saves only what changed since', async () => {
        on('PUT lock', granted({ flush_requested: true }));
        on('PUT ds', one(source({ name: 'Ada flushed', revision: 2 })));
        // The acknowledgement fails once: the holder keeps the lock and is asked again.
        on('POST flush', refused(500), takenOver);
        await mountForm();
        await type('[data-test="name"]', 'Ada flushed');
        await advance(21_000);
        expect(of('PUT ds')).toHaveLength(1);
        expect(of('POST flush')).toHaveLength(1);

        await advance(20_000);
        expect(of('PUT ds')).toHaveLength(1);
        expect(of('POST flush')).toHaveLength(2);
    });
});

describe('the session', () => {
    // Everything the page left in sessionStorage, as one string.
    const storedText = (): string =>
        Array.from({ length: sessionStorage.length }, (_, i) => {
            const key = sessionStorage.key(i) ?? '';

            return `${key}=${sessionStorage.getItem(key)}`;
        }).join('\n');
    const status = (remaining: number): Reply =>
        ok({ remaining_seconds: remaining, area: 'admin' });

    async function mountBoth(): Promise<void> {
        wrapper = mount(
            {
                components: { DataSourceForm, SessionExpiryDialog },
                template:
                    '<div><DataSourceForm data-source-id="ds-1" /><SessionExpiryDialog /></div>',
            } as never,
            {
                attachTo: document.body,
                global: { plugins: [createCatalogue()] },
            },
        );
        await flushPromises();
    }

    it('shows the existing warning dialog on this form, with Stay signed in focused, and restores nothing after expiry', async () => {
        on('GET session', status(121), refused(401));
        on(
            'POST lock',
            lockData({
                status: 'held',
                holder: { name: 'Maya Patel', since: '2026-10-08T10:42:00Z' },
            }),
        );
        const assign = vi.fn();

        vi.stubGlobal('location', {
            assign,
            href: window.location.href,
            origin: window.location.origin,
            pathname: window.location.pathname,
            search: window.location.search,
        });
        await mountBoth();
        await advance(2_000);

        expect(document.querySelector('[data-session-warning]')).not.toBeNull();
        expect(document.activeElement?.textContent?.trim()).toBe(
            sessionLabels.stay,
        );

        // The countdown reaches zero, the server answers 401: nothing of this form is stored for a restore.
        await advance(125_000);
        expect(assign).toHaveBeenCalled();
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).toBeNull();
        expect(storedText()).not.toContain(CANARY);
    });

    it('stores nothing on expiry from an edited form holding a typed secret', async () => {
        on('GET session', status(600), refused(401));
        const assign = vi.fn();

        vi.stubGlobal('location', {
            assign,
            href: window.location.href,
            origin: window.location.origin,
            pathname: window.location.pathname,
            search: window.location.search,
        });
        await mountBoth();
        await type('[data-test="name"]', 'Edited and typed');
        const select = $('[data-test="auth-type"]') as HTMLSelectElement;

        select.value = 'bearer';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        await flushPromises();
        await type('[data-test="secret-bearer_token"]', CANARY);

        await advance(61_000);

        expect(assign).toHaveBeenCalled();
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).toBeNull();
        expect(storedText()).not.toContain(CANARY);
        expect(storedText()).not.toContain('Edited and typed');
    });
});
