// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { setConnectionForTest } from '../../resources/js/composables/useConnection';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { clearUnsavedForms } from '../../resources/js/lib/unsavedForms';
import {
    PENDING_KEY,
    stashPending,
} from '../../resources/js/lib/wizardPending';
import en from '../../resources/js/locales/en';
import { draftLockLabels } from '../../resources/js/locales/labels';

// Story 3.6: the wizard's soft lock. The holder edits and heartbeats; anyone else sees the page read-only with `draft-locked` and can take
// over; the holder's take-over flush is the wizard's own save, acknowledged only when it worked; a lost lock shows the notice, never a toast.
const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/admin/blocks/create',
    props: {},
});

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    Head: defineComponent({ setup: () => () => null }),
    Link: defineComponent({
        props: ['href'],
        setup:
            (props, { slots, attrs }) =>
            () =>
                h('a', { ...attrs, href: props.href }, slots.default?.()),
    }),
    router: { visit: vi.fn(), on: () => () => {} },
}));

const { default: BlockWizard } =
    await import('../../resources/js/pages/admin/BlockWizard.vue');

const CATEGORIES = [{ id: 'c-general', name: 'General', archived: false }];

function draft(overrides: Partial<WizardDraft> = {}): WizardDraft {
    return {
        draft_id: 'd-1',
        block_id: 'b-1',
        name: 'Revenue today',
        type: 'kpi-card',
        category: 'General',
        category_id: 'c-general',
        version: '1.0',
        state: 'draft',
        revision: 1,
        lock_epoch: 1,
        step: 1,
        owner_membership_id: 'm-1',
        owner: null,
        created_at: '2026-10-01T09:30:00Z',
        updated_at: '2026-10-02T10:00:00Z',
        config: { schema_version: 1, description: 'Daily revenue' },
        ...overrides,
    };
}

const granted = (extra: Record<string, unknown> = {}) => ({
    enabled: true,
    status: 'granted',
    token: 'tok-1',
    epoch: 1,
    ttl_seconds: 30,
    flush_requested: false,
    csrf_token: 'csrf-1',
    ...extra,
});
const held = {
    enabled: true,
    status: 'held',
    holder: { name: 'Ada', since: '2026-10-09T09:00:00Z' },
};

type Reply = { status: number; json: unknown } | 'network';
type Call = { url: string; method: string; body: Record<string, any> | null };
const calls: Call[] = [];
let routes: Record<string, () => Reply> = {};
let wrapper: VueWrapper | null = null;
const beacon = vi.fn(() => true);

const key = (method: string, url: string) => `${method} ${url}`;
const lockCalls = (method: string, suffix = '') =>
    calls.filter(
        (c) =>
            c.method === method &&
            c.url === `/api/v1/admin/blocks/drafts/d-1/lock${suffix}`,
    );
const saves = () =>
    calls.filter(
        (c) =>
            c.method === 'PUT' && c.url === '/api/v1/admin/blocks/drafts/d-1',
    );

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);
const fields = () => $('[data-test=form-fields]') as HTMLFieldSetElement;

async function mountPage(search = '?draft=d-1'): Promise<void> {
    window.history.replaceState({}, '', `/admin/blocks/create${search}`);
    wrapper = mount(BlockWizard as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await flushPromises();
}

async function type(value: string): Promise<void> {
    const input = $('[data-test=name]') as HTMLInputElement;

    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();
}

async function advance(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
    await flushPromises();
}

beforeEach(() => {
    vi.useFakeTimers();
    setActivePinia(createPinia());
    page.props = {
        auth: { user: { id: 7 } },
        autosave: { debounce_ms: null },
        shell: {
            area: 'admin',
            items: [],
            can: { 'blocks.edit': true, 'blocks.publish': true },
        },
    };
    setConnectionForTest(true);
    calls.length = 0;
    routes = {
        [key('GET', '/api/v1/admin/blocks/categories')]: () => ({
            status: 200,
            json: { data: CATEGORIES },
        }),
        [key('GET', '/api/v1/admin/blocks/drafts/d-1')]: () => ({
            status: 200,
            json: { data: draft() },
        }),
        [key('POST', '/api/v1/admin/blocks/drafts/d-1/lock')]: () => ({
            status: 200,
            json: { data: granted() },
        }),
        [key('PUT', '/api/v1/admin/blocks/drafts/d-1/lock')]: () => ({
            status: 200,
            json: { data: granted() },
        }),
        [key('POST', '/api/v1/admin/blocks/drafts/d-1/lock/flush')]: () => ({
            status: 200,
            json: { data: { enabled: true, status: 'lost' } },
        }),
        [key('PUT', '/api/v1/admin/blocks/drafts/d-1')]: () => ({
            status: 200,
            json: { data: draft({ revision: 2 }) },
        }),
    };
    vi.stubGlobal(
        'fetch',
        vi.fn(
            async (url: string, init?: { method?: string; body?: string }) => {
                const method = init?.method ?? 'GET';

                calls.push({
                    url,
                    method,
                    body: init?.body ? JSON.parse(init.body) : null,
                });

                const reply = routes[key(method, url)]?.();

                if (!reply || reply === 'network') {
                    throw new TypeError('network');
                }

                return {
                    ok: reply.status < 400,
                    status: reply.status,
                    json: async () => reply.json,
                };
            },
        ),
    );
    Object.defineProperty(navigator, 'sendBeacon', {
        value: beacon,
        configurable: true,
    });
    beacon.mockClear();
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    clearUnsavedForms();
    sessionStorage.clear();
    resetAnnouncer();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('starting a Draft', () => {
    it('takes the lock after the create call, and the first save carries its token and epoch', async () => {
        routes[key('POST', '/api/v1/admin/blocks/drafts')] = () => ({
            status: 201,
            json: { data: draft() },
        });
        await mountPage('');
        await type('Revenue');
        $('[data-test=save-draft]')?.click();
        await flushPromises();

        const order = calls
            .filter((c) => c.method !== 'GET')
            .map((c) => `${c.method} ${c.url}`);

        expect(order).toEqual([
            'POST /api/v1/admin/blocks/drafts',
            'POST /api/v1/admin/blocks/drafts/d-1/lock',
            'PUT /api/v1/admin/blocks/drafts/d-1',
        ]);
        expect(saves()[0].body).toMatchObject({
            lock_token: 'tok-1',
            lock_epoch: 1,
        });
    });

    it('sends no save when the new Draft cannot be locked, and shows the read-only state', async () => {
        routes[key('POST', '/api/v1/admin/blocks/drafts')] = () => ({
            status: 201,
            json: { data: draft() },
        });
        routes[key('POST', '/api/v1/admin/blocks/drafts/d-1/lock')] = () => ({
            status: 200,
            json: { data: held },
        });
        await mountPage('');
        await type('Revenue');
        $('[data-test=save-draft]')?.click();
        await flushPromises();

        expect(saves()).toHaveLength(0);
        expect(fields().disabled).toBe(true);
        expect($('[data-test=lock-banner]')).not.toBeNull();
    });
});

describe('changes kept across a sign-in', () => {
    function pending(): void {
        stashPending({
            draftId: 'd-1',
            baseRevision: 1,
            lockEpoch: 1,
            viewStep: 1,
            acknowledged: true,
            delta: {
                name: 'Typed before expiry',
                type: 'kpi-card',
                category_id: 'c-general',
                step: 1,
                config: { schema_version: 1, description: 'Kept text' },
                ui_state: { acknowledged_warnings: [], slot_statuses: {} },
            },
        });
    }

    it('are not replayed while someone else holds the lock, and stay kept', async () => {
        pending();
        routes[key('POST', '/api/v1/admin/blocks/drafts/d-1/lock')] = () => ({
            status: 200,
            json: { data: held },
        });
        await mountPage();

        expect(saves()).toHaveLength(0);
        expect(sessionStorage.getItem(PENDING_KEY)).not.toBeNull();
    });

    it('are replayed by the holder with the lock token', async () => {
        pending();
        await mountPage();

        expect(saves()).toHaveLength(1);
        expect(saves()[0].body).toMatchObject({
            name: 'Typed before expiry',
            lock_token: 'tok-1',
            lock_epoch: 1,
            expected_revision: 1,
        });
        expect(sessionStorage.getItem(PENDING_KEY)).toBeNull();
    });
});

describe('after an idle lapse', () => {
    async function lapse(): Promise<void> {
        await mountPage();
        await advance(40_000);
    }

    it('activity asks the server at once, and the holder carries on when the lock is still its own', async () => {
        await lapse();
        const before = lockCalls('PUT').length;

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));
        await flushPromises();

        expect(lockCalls('PUT')).toHaveLength(before + 1);
        expect(fields().disabled).toBe(false);
    });

    it('ends read-only with the lost notice when the lock was lost and someone else holds it now', async () => {
        await lapse();
        routes[key('PUT', '/api/v1/admin/blocks/drafts/d-1/lock')] = () => ({
            status: 200,
            json: { data: { enabled: true, status: 'lost' } },
        });
        routes[key('POST', '/api/v1/admin/blocks/drafts/d-1/lock')] = () => ({
            status: 200,
            json: { data: held },
        });
        const acquires = lockCalls('POST').length;

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }));
        await flushPromises();

        expect(lockCalls('POST')).toHaveLength(acquires + 1);
        expect(fields().disabled).toBe(true);
        expect($('[data-test=lock-notice]')?.textContent).toBe(
            draftLockLabels.lost,
        );
    });
});

describe('the holder', () => {
    it('acquires on open, is editable, and every save carries the lock token', async () => {
        await mountPage();

        expect(lockCalls('POST')).toHaveLength(1);
        expect(fields().disabled).toBe(false);
        expect($('[data-test=lock-banner]')).toBeNull();

        await type('Revenue');
        $('[data-test=save-draft]')?.click();
        await flushPromises();

        expect(saves()).toHaveLength(1);
        expect(saves()[0].body).toMatchObject({
            lock_token: 'tok-1',
            lock_epoch: 1,
            expected_revision: 1,
        });
    });

    it('heartbeats while the Admin is active, and lets an idle lock lapse', async () => {
        await mountPage();

        await advance(10_000);
        expect(lockCalls('PUT')).toHaveLength(1);

        await type('Revenue');
        await advance(10_000);
        expect(lockCalls('PUT').length).toBeGreaterThanOrEqual(2);

        const before = lockCalls('PUT').length;
        await advance(120_000);
        // No activity for the TTL: the beats stop, so the server frees the lock.
        expect(lockCalls('PUT').length).toBeLessThan(before + 5);
        const settled = lockCalls('PUT').length;
        await advance(60_000);
        expect(lockCalls('PUT')).toHaveLength(settled);
    });

    it('releases the lock by beacon with the token in the body when the page is left', async () => {
        await mountPage();

        window.dispatchEvent(new Event('pagehide'));

        expect(beacon).toHaveBeenCalledTimes(1);
        const [url, blob] = beacon.mock.calls[0] as unknown as [string, Blob];

        expect(url).toBe('/api/v1/admin/blocks/drafts/d-1/lock/release');
        expect(JSON.parse(await blob.text())).toEqual({
            token: 'tok-1',
            _token: 'csrf-1',
        });
    });

    it('leaves the form as before when the lock is disabled', async () => {
        routes[key('POST', '/api/v1/admin/blocks/drafts/d-1/lock')] = () => ({
            status: 200,
            json: { data: { enabled: false } },
        });
        await mountPage();

        expect(fields().disabled).toBe(false);
        expect($('[data-test=lock-banner]')).toBeNull();
        await type('Revenue');
        $('[data-test=save-draft]')?.click();
        await flushPromises();
        expect(saves()[0].body?.lock_token).toBeUndefined();
    });
});

describe('another editor', () => {
    beforeEach(() => {
        routes[key('POST', '/api/v1/admin/blocks/drafts/d-1/lock')] = () => ({
            status: 200,
            json: { data: held },
        });
    });

    it('is read-only with the draft-locked banner, Take over editing and Close, and sends no write', async () => {
        await mountPage();

        expect(fields().disabled).toBe(true);
        expect($('[data-test=lock-notice]')?.textContent).toContain('Ada');
        expect($('[data-test=lock-notice]')?.textContent).toContain(
            en['draft-locked'].split('{user}')[1].split('(')[0].trim(),
        );
        expect($('[data-test=take-over]')).not.toBeNull();
        expect($('[data-test=lock-close]')?.getAttribute('href')).toBe(
            '/admin/blocks/drafts',
        );
        expect($('[data-test=save-draft]')?.getAttribute('aria-disabled')).toBe(
            'true',
        );
        expect($('[data-test=continue]')?.getAttribute('aria-disabled')).toBe(
            'true',
        );
        expect(
            $('[data-slot=gated-action]')?.getAttribute('aria-disabled'),
        ).toBe('true');

        $('[data-test=save-draft]')?.click();
        ($('[data-test=step-1]') as HTMLFormElement).dispatchEvent(
            new Event('submit', { cancelable: true }),
        );
        await flushPromises();
        expect(saves()).toHaveLength(0);
        expect(lockCalls('PUT')).toHaveLength(0);
    });

    it('takes over: waits, then loads the post-flush revision and is editable', async () => {
        routes[key('POST', '/api/v1/admin/blocks/drafts/d-1/lock/takeover')] =
            () => ({
                status: 200,
                json: {
                    data: { enabled: true, status: 'waiting', token: 'tok-2' },
                },
            });
        routes[key('GET', '/api/v1/admin/blocks/drafts/d-1/lock/takeover')] =
            () => ({
                status: 200,
                json: { data: granted({ token: 'tok-2', epoch: 2 }) },
            });
        await mountPage();

        // The post-flush revision is what the reload shows.
        routes[key('GET', '/api/v1/admin/blocks/drafts/d-1')] = () => ({
            status: 200,
            json: {
                data: draft({
                    name: 'Ada flushed',
                    revision: 2,
                    lock_epoch: 2,
                }),
            },
        });
        $('[data-test=take-over]')?.click();
        await flushPromises();
        expect($('[data-test=lock-notice]')?.textContent).toBe(
            draftLockLabels.waiting('Ada'),
        );
        expect(fields().disabled).toBe(true);

        await advance(1000);

        expect(fields().disabled).toBe(false);
        expect(($('[data-test=name]') as HTMLInputElement).value).toBe(
            'Ada flushed',
        );
        $('[data-test=save-draft]')?.click();
        await flushPromises();
        expect(saves()[0].body).toMatchObject({
            lock_token: 'tok-2',
            lock_epoch: 2,
            expected_revision: 2,
        });
    });
});

describe('a take-over request reaches the holder', () => {
    function requested(): void {
        routes[key('PUT', '/api/v1/admin/blocks/drafts/d-1/lock')] = () => ({
            status: 200,
            json: { data: granted({ flush_requested: true }) },
        });
    }

    it('flushes its unsaved changes through its own save, acknowledges, and says draft-taken-over', async () => {
        routes[key('POST', '/api/v1/admin/blocks/drafts/d-1/lock/flush')] =
            () => ({
                status: 200,
                json: {
                    data: {
                        enabled: true,
                        status: 'taken_over',
                        flush_acknowledged: true,
                        taken_over_by: {
                            name: 'Bob',
                            at: '2026-10-09T10:00:00Z',
                        },
                    },
                },
            });
        await mountPage();
        await type('Typed by the holder');
        requested();
        await advance(10_000);

        expect(saves()).toHaveLength(1);
        expect(saves()[0].body).toMatchObject({
            name: 'Typed by the holder',
            lock_token: 'tok-1',
        });
        expect(lockCalls('POST', '/flush')).toHaveLength(1);
        expect($('[data-test=lock-notice]')?.textContent).toContain('Bob');
        expect($('[data-test=lock-notice]')?.textContent).toContain(
            'Your changes were saved.',
        );
        expect(fields().disabled).toBe(true);
    });

    it('acknowledges at once when there is nothing unsaved', async () => {
        requested();
        await mountPage();
        await advance(10_000);

        expect(saves()).toHaveLength(0);
        expect(lockCalls('POST', '/flush')).toHaveLength(1);
    });

    it('does not acknowledge a flush that failed, and later says nothing was saved', async () => {
        await mountPage();
        await type('Typed offline');
        requested();
        routes[key('PUT', '/api/v1/admin/blocks/drafts/d-1')] = () => 'network';
        await advance(10_000);

        expect(saves().length).toBeGreaterThanOrEqual(1);
        expect(lockCalls('POST', '/flush')).toHaveLength(0);

        // The flush timeout passed: the next heartbeat says the lock was taken over without an acknowledgement.
        routes[key('PUT', '/api/v1/admin/blocks/drafts/d-1/lock')] = () => ({
            status: 200,
            json: {
                data: {
                    enabled: true,
                    status: 'taken_over',
                    flush_acknowledged: false,
                    taken_over_by: { name: 'Bob', at: '2026-10-09T10:00:00Z' },
                },
            },
        });
        await advance(10_000);

        const notice = $('[data-test=lock-notice]')?.textContent ?? '';

        expect(notice).toBe(
            draftLockLabels.takenOverUnsaved(
                'Bob',
                notice.match(/at (.*)\. Changes/)?.[1] ?? '',
            ),
        );
        expect(notice).not.toContain('were saved.');
        expect(fields().disabled).toBe(true);
    });
});

describe('a save refused with 423', () => {
    it('shows the lost-lock state in place of a toast, writes nothing more and keeps the page read-only', async () => {
        await mountPage();
        await type('Too late');
        routes[key('PUT', '/api/v1/admin/blocks/drafts/d-1')] = () => ({
            status: 423,
            json: {
                error: { code: 'platform.edit_lock_lost' },
                current: { data: draft({ revision: 2, lock_epoch: 2 }) },
            },
        });
        $('[data-test=save-draft]')?.click();
        await flushPromises();

        expect($('[data-test=lock-notice]')?.textContent).toBe(
            draftLockLabels.lost,
        );
        expect($('[data-test=lock-notice]')?.textContent).not.toContain(
            'were saved',
        );
        expect(fields().disabled).toBe(true);
        expect($('[data-slot=toast]')).toBeNull();
        expect(lockCalls('PUT')).toHaveLength(0);

        $('[data-test=save-draft]')?.click();
        await flushPromises();
        expect(saves()).toHaveLength(1);
    });
});
