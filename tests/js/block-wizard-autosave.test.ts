// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, reactive } from 'vue';
import { setConnectionForTest } from '../../resources/js/composables/useConnection';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import { runFlushes } from '../../resources/js/lib/flushRegistry';
import { hashOwner } from '../../resources/js/lib/formDrafts';
import { clearUnsavedForms } from '../../resources/js/lib/unsavedForms';
import {
    PENDING_KEY,
    stashPending,
} from '../../resources/js/lib/wizardPending';
import type { WizardDraft } from '../../resources/js/lib/blockWizard';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';
import { blockWizardLabels as labels } from '../../resources/js/locales/labels';
import { useToasts } from '../../resources/js/stores/toasts';

// Story 3.5: autosave (debounce, CAS, background header), the flush for the session-expiry warning, the pending changes across a sign-in,
// offline, an inline save failure with Retry, a published Draft, the revision-conflict chooser and the unsaved-changes guard.
const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/admin/blocks/create',
    props: {},
});
const handlers: Record<string, (event: any) => unknown> = {};
const visit = vi.fn();

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
    router: {
        visit: (...args: unknown[]) => visit(...args),
        on: (name: string, handler: (event: any) => unknown) => {
            handlers[name] = handler;

            return () => {};
        },
    },
}));

const { default: BlockWizard } =
    await import('../../resources/js/pages/admin/BlockWizard.vue');

const CATEGORIES = [{ id: 'c-general', name: 'General', archived: false }];
const USER_ID = 7;

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

type Reply = { ok: boolean; status: number; json: unknown };
type Call = {
    url: string;
    method: string;
    headers: Record<string, string>;
    body: Record<string, any> | null;
};
const calls: Call[] = [];
let replies: Array<Reply | 'network'> = [];
const ok = (json: unknown): Reply => ({ ok: true, status: 200, json });
const refused = (status: number, json: unknown = {}): Reply => ({
    ok: false,
    status,
    json,
});
const puts = () => calls.filter((c) => c.method === 'PUT');
let wrapper: VueWrapper | null = null;
const assign = vi.fn();

function setPage(debounce: number | null): void {
    page.props = {
        auth: { user: { id: USER_ID } },
        autosave: { debounce_ms: debounce },
        shell: {
            area: 'admin',
            items: [],
            can: { 'blocks.edit': true, 'blocks.publish': true },
        },
    };
}

async function settle(): Promise<void> {
    for (let i = 0; i < 10; i += 1) {
        await Promise.resolve();
        await nextTick();
    }
}

async function advance(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
    await settle();
}

async function mountPage(search = '?draft=d-1') {
    window.history.replaceState({}, '', `/admin/blocks/create${search}`);
    wrapper = mount(BlockWizard as never, {
        attachTo: document.body,
        global: { plugins: [createCatalogue()] },
    });
    await settle();
}

const $ = (selector: string) => document.querySelector<HTMLElement>(selector);

async function type(selector: string, value: string): Promise<void> {
    const input = $(selector) as HTMLInputElement | HTMLTextAreaElement;

    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
}

async function click(element: Element | null): Promise<void> {
    (element as HTMLElement).click();
    await settle();
}

beforeEach(() => {
    vi.useFakeTimers();
    setActivePinia(createPinia());
    setPage(500);
    sessionStorage.clear();
    calls.length = 0;
    replies = [];
    visit.mockReset();
    assign.mockReset();
    setConnectionForTest(true);
    Object.keys(handlers).forEach((key) => delete handlers[key]);
    vi.spyOn(window.location, 'assign').mockImplementation(assign);
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: any) => {
            // The soft lock (Story 3.6) is off in these tests; block-wizard-lock.test.ts covers it.
            if (/\/lock(\/|$)/.test(url)) {
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({ data: { enabled: false } }),
                };
            }

            // The Draft's saved sample (Story 3.8) has its own tests: here there is none.
            if (url.endsWith('/sample')) {
                return {
                    ok: true,
                    status: 200,
                    json: async () => ({ data: null }),
                };
            }

            calls.push({
                url,
                method: init?.method ?? 'GET',
                headers: init?.headers ?? {},
                body: init?.body ? JSON.parse(init.body) : null,
            });
            const next = replies.shift();

            if (!next) {
                throw new Error(`unexpected request ${url}`);
            }

            if (next === 'network') {
                throw new TypeError('offline');
            }

            return {
                ok: next.ok,
                status: next.status,
                json: async () => next.json,
            };
        }),
    );
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    clearUnsavedForms();
    document.body.innerHTML = '';
    resetAnnouncer();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    vi.useRealTimers();
});

describe('autosave', () => {
    it('saves once after the debounce with a compare-and-set and X-Background, and Save draft does not send the header', async () => {
        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: draft() }),
            ok({ data: draft({ revision: 2, name: 'Revenue now' }) }),
            ok({ data: draft({ revision: 3, name: 'Revenue now' }) }),
        ];
        await mountPage();
        await type('[data-test=name]', 'Revenue now');
        await advance(499);
        expect(puts()).toHaveLength(0);

        await type('[data-test=name]', 'Revenue now!');
        await advance(499);
        expect(puts()).toHaveLength(0);
        await advance(1);

        expect(puts()).toHaveLength(1);
        expect(puts()[0].headers['X-Background']).toBe('1');
        expect(puts()[0].body).toMatchObject({
            name: 'Revenue now!',
            expected_revision: 1,
            lock_epoch: 1,
            ui_state: { acknowledged_warnings: [], slot_statuses: {} },
        });
        expect($('[data-test=autosave-status]')?.textContent).toBe(
            labels.saved,
        );

        await click($('[data-test=save-draft]'));
        expect(puts()).toHaveLength(2);
        expect(puts()[1].headers['X-Background']).toBeUndefined();
        expect(puts()[1].body).toMatchObject({ expected_revision: 2 });
    });

    it('is inert without the tunable: nothing saves by itself, Save draft still works', async () => {
        setPage(null);
        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: draft() }),
            ok({ data: draft({ revision: 2 }) }),
        ];
        await mountPage();
        await type('[data-test=name]', 'Revenue now');
        await advance(600_000);
        expect(puts()).toHaveLength(0);

        await click($('[data-test=save-draft]'));
        expect(puts()).toHaveLength(1);
        expect(puts()[0].headers['X-Background']).toBeUndefined();
    });

    it('does not send a change the server would refuse: a blank name waits', async () => {
        replies = [ok({ data: CATEGORIES }), ok({ data: draft() })];
        await mountPage();
        await type('[data-test=name]', '   ');
        await advance(2000);
        expect(puts()).toHaveLength(0);
    });
});

describe('save failure', () => {
    it('shows save-failed beside Save draft with Retry, moves focus to it, keeps every change and saves on Retry', async () => {
        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: draft() }),
            refused(503, { error: { code: 'platform.unavailable' } }),
            ok({ data: draft({ revision: 2, name: 'Revenue now' }) }),
        ];
        await mountPage();
        await type('[data-test=name]', 'Revenue now');
        await advance(500);

        const failed = $('[data-test=save-failed]')!;

        expect(failed.textContent).toContain(en['save-failed'].draft);
        expect(document.activeElement).toBe(failed);
        expect(($('[data-test=name]') as HTMLInputElement).value).toBe(
            'Revenue now',
        );
        expect(useToasts().items).toHaveLength(0);

        await click($('[data-test=retry-save]'));
        expect(puts()).toHaveLength(2);
        expect(puts()[1].body).toMatchObject({
            name: 'Revenue now',
            expected_revision: 1,
        });
        expect($('[data-test=save-failed]')).toBeNull();
    });
});

describe('offline', () => {
    it('blocks Save draft and Publish with the reason inline, keeps editing, and saves what changed on reconnect', async () => {
        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: draft() }),
            ok({ data: draft({ revision: 2, name: 'Edited offline' }) }),
        ];
        await mountPage();
        setConnectionForTest(false);
        await settle();

        const save = $('[data-test=save-draft]')!;
        const publish = $('[data-slot=gated-action]')!;

        expect(save.getAttribute('aria-disabled')).toBe('true');
        expect(publish.getAttribute('aria-disabled')).toBe('true');
        expect(
            document.getElementById(save.getAttribute('aria-describedby')!)
                ?.textContent,
        ).toBe(en['offline-editing']);
        expect(
            document.getElementById(publish.getAttribute('aria-describedby')!)
                ?.textContent,
        ).toBe(en['offline-editing']);

        await click(save);
        await type('[data-test=name]', 'Edited offline');
        await advance(5000);
        expect(puts()).toHaveLength(0);
        expect(($('[data-test=name]') as HTMLInputElement).value).toBe(
            'Edited offline',
        );

        setConnectionForTest(true);
        await advance(10);
        expect(puts()).toHaveLength(1);
        expect(puts()[0].body).toMatchObject({ name: 'Edited offline' });
        expect(save.getAttribute('aria-disabled')).toBeNull();
    });
});

describe('published Draft', () => {
    it('stops saving, says so and links to Published blocks', async () => {
        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: draft() }),
            refused(409, { error: { code: 'blocks.draft_published' } }),
        ];
        await mountPage();
        await type('[data-test=name]', 'Revenue now');
        await advance(500);

        const panel = $('[data-test=draft-published]')!;

        expect(panel.textContent).toContain(labels.publishedTitle);
        expect($('[data-test=published-link]')?.getAttribute('href')).toBe(
            '/admin/blocks/published',
        );
        expect(document.activeElement).toBe(panel);
        expect($('[data-test=conflict-chooser]')).toBeNull();

        await type('[data-test=name]', 'More');
        await advance(5000);
        await click($('[data-test=save-draft]'));
        expect(puts()).toHaveLength(1);
        expect($('[data-test=save-draft]')?.getAttribute('aria-disabled')).toBe(
            'true',
        );
    });
});

describe('session expiry', () => {
    it('flushes pending changes in the background when the warning asks, and does nothing when there are none', async () => {
        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: draft() }),
            ok({ data: draft({ revision: 2, name: 'Typed late' }) }),
        ];
        await mountPage();
        expect(await runFlushes()).toBe(true);
        expect(puts()).toHaveLength(0);

        setPage(null);
        await type('[data-test=name]', 'Typed late');
        expect(await runFlushes()).toBe(true);
        expect(puts()).toHaveLength(1);
        expect(puts()[0].headers['X-Background']).toBe('1');
    });

    it('keeps the pending changes in sessionStorage on a 401, without secrets, owner-hashed, and goes to sign-in', async () => {
        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: draft({ step: 2 }) }),
            // Step 2 reads its Data Sources and refresh intervals when it opens.
            ok({ data: [] }),
            ok({ data: { intervals: null } }),
            refused(401, { message: 'Unauthenticated.' }),
        ];
        await mountPage('?draft=d-1&step=2');
        await click($('[data-step="1"] button'));
        await type('[data-test=name]', 'Typed before expiry');
        await advance(500);

        expect(assign).toHaveBeenCalledWith('/login');

        const raw = sessionStorage.getItem(PENDING_KEY)!;
        const stored = JSON.parse(raw);

        expect(stored).toMatchObject({
            draftId: 'd-1',
            baseRevision: 1,
            viewStep: 1,
            owner: hashOwner(USER_ID),
            acknowledged: false,
            delta: { name: 'Typed before expiry', step: 2 },
        });
        expect(raw).not.toMatch(/token|password|secret|api_?key/i);
    });

    it('marks the changes acknowledged when the expiry flush kept them', async () => {
        setPage(null);
        replies = [ok({ data: CATEGORIES }), ok({ data: draft() }), 'network'];
        await mountPage();
        await type('[data-test=name]', 'Typed late');
        setConnectionForTest(false);
        await settle();

        expect(await runFlushes()).toBe(true);
        expect(JSON.parse(sessionStorage.getItem(PENDING_KEY)!)).toMatchObject({
            acknowledged: true,
            delta: { name: 'Typed late' },
        });
        expect(puts()).toHaveLength(0);
    });

    it('keeps the changes for the replay when the expiry flush fails while online', async () => {
        setPage(null);
        replies = [ok({ data: CATEGORIES }), ok({ data: draft() }), 'network'];
        await mountPage();
        await type('[data-test=name]', 'Typed late');
        await settle();

        expect(await runFlushes()).toBe(true);
        expect(JSON.parse(sessionStorage.getItem(PENDING_KEY)!)).toMatchObject({
            acknowledged: true,
            delta: { name: 'Typed late' },
        });
    });

    it("never stores a secret-named key, and drops another person's changes", () => {
        stashPending({
            draftId: 'd-1',
            baseRevision: 1,
            lockEpoch: 1,
            viewStep: 1,
            acknowledged: true,
            delta: {
                name: 'x',
                type: 'kpi-card',
                config: {
                    schema_version: 1,
                    api_key: 'abc',
                    token: 't',
                } as never,
            },
        });
        expect(sessionStorage.getItem(PENDING_KEY)).not.toMatch(
            /api_key|token|abc/,
        );
    });

    function pending(acknowledged: boolean, viewStep = 2): void {
        stashPending({
            draftId: 'd-1',
            baseRevision: 1,
            lockEpoch: 1,
            viewStep,
            acknowledged,
            delta: {
                name: 'Typed before expiry',
                type: 'kpi-card',
                category_id: 'c-general',
                step: 2,
                config: { schema_version: 1, description: 'Kept text' },
                ui_state: { acknowledged_warnings: [], slot_statuses: {} },
            },
        });
    }

    it('replays the kept changes with a CAS after sign-in, reopens at the stored step and says session-expired when acknowledged', async () => {
        pending(true);
        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: draft({ step: 1 }) }),
            ok({
                data: draft({
                    revision: 2,
                    step: 2,
                    name: 'Typed before expiry',
                    config: { schema_version: 1, description: 'Kept text' },
                }),
            }),
        ];
        await mountPage('?draft=d-1');

        expect(puts()).toHaveLength(1);
        expect(puts()[0].body).toMatchObject({
            expected_revision: 1,
            lock_epoch: 1,
            name: 'Typed before expiry',
        });
        expect($('[aria-current=step]')?.getAttribute('data-step')).toBe('2');
        expect(useToasts().items.map((t) => t.message)).toEqual([
            en['session-expired'],
        ]);
        expect(sessionStorage.getItem(PENDING_KEY)).toBeNull();
    });

    it('says nothing when the flush was not acknowledged', async () => {
        pending(false);
        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: draft() }),
            ok({ data: draft({ revision: 2, step: 2 }) }),
        ];
        await mountPage('?draft=d-1');

        expect(puts()).toHaveLength(1);
        expect(useToasts().items).toHaveLength(0);
    });

    it('shows both versions when the revision moved on, and never overwrites without a choice', async () => {
        pending(true);
        const theirs = draft({
            revision: 3,
            name: 'Colleague name',
            config: { schema_version: 1, description: 'Their text' },
        });

        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: theirs }),
            refused(409, {
                error: { code: 'blocks.revision_conflict' },
                current: { data: theirs },
            }),
        ];
        await mountPage('?draft=d-1');

        const chooser = $('[data-test=conflict-chooser]')!;

        expect(chooser.textContent).toContain('Typed before expiry');
        expect(chooser.textContent).toContain('Colleague name');
        expect(
            document.activeElement?.closest('[data-test=conflict-chooser]'),
        ).not.toBeNull();
        expect(puts()).toHaveLength(1);
        expect(useToasts().items).toHaveLength(0);

        // Keep mine: saved explicitly over the revision that is there now.
        replies = [
            ok({ data: draft({ revision: 4, name: 'Typed before expiry' }) }),
        ];
        await click($('[data-test=conflict-keep-mine]'));
        expect(puts()).toHaveLength(2);
        expect(puts()[1].body).toMatchObject({
            expected_revision: 3,
            name: 'Typed before expiry',
        });
        expect($('[data-test=conflict-chooser]')).toBeNull();
        expect(sessionStorage.getItem(PENDING_KEY)).toBeNull();
    });

    it('uses the saved version when the Admin chooses it, without another save', async () => {
        pending(true);
        const theirs = draft({ revision: 3, name: 'Colleague name' });

        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: theirs }),
            refused(409, {
                error: { code: 'blocks.revision_conflict' },
                current: { data: theirs },
            }),
        ];
        await mountPage('?draft=d-1');
        await click($('[data-test=conflict-use-theirs]'));

        expect(puts()).toHaveLength(1);
        expect(($('[data-test=name]') as HTMLInputElement).value).toBe(
            'Colleague name',
        );
        expect($('[data-test=conflict-chooser]')).toBeNull();
        expect(sessionStorage.getItem(PENDING_KEY)).toBeNull();
    });
});

describe('leaving with unsaved changes', () => {
    it('asks with Keep editing focused, and Keep editing stays', async () => {
        setPage(null);
        replies = [ok({ data: CATEGORIES }), ok({ data: draft() })];
        await mountPage();
        await type('[data-test=name]', 'Changed');

        const result = handlers.before({
            detail: { visit: { url: new URL('http://localhost/admin') } },
        });

        await settle();
        expect(result).toBe(false);

        const dialog = document.querySelector('[role=dialog]')!;

        expect(dialog.textContent).toContain(en['unsaved-changes']);
        expect(
            [...dialog.querySelectorAll('button')].map((b) =>
                b.textContent?.trim(),
            ),
        ).toEqual(
            expect.arrayContaining([
                'Save draft',
                'Discard changes',
                'Keep editing',
            ]),
        );
        expect(document.activeElement?.textContent?.trim()).toBe(
            'Keep editing',
        );

        await click(document.activeElement);
        expect(document.querySelector('[role=dialog]')).toBeNull();
        expect(visit).not.toHaveBeenCalled();
    });

    it('Discard changes goes on, Save draft saves first and then goes on', async () => {
        setPage(null);
        replies = [
            ok({ data: CATEGORIES }),
            ok({ data: draft() }),
            ok({ data: draft({ revision: 2, name: 'Changed' }) }),
        ];
        await mountPage();
        await type('[data-test=name]', 'Changed');

        const ask = () =>
            handlers.before({
                detail: { visit: { url: new URL('http://localhost/admin/x') } },
            });
        const choose = (label: string) =>
            [...document.querySelectorAll('[role=dialog] button')].find(
                (b) => b.textContent?.trim() === label,
            );

        ask();
        await settle();
        await click(choose('Save draft')!);
        expect(puts()).toHaveLength(1);
        expect(visit).toHaveBeenCalledWith('http://localhost/admin/x');

        // Saved: nothing left to lose, the guard lets the visit through.
        expect(ask()).toBeUndefined();
    });

    it('Discard changes leaves without saving', async () => {
        setPage(null);
        replies = [ok({ data: CATEGORIES }), ok({ data: draft() })];
        await mountPage();
        await type('[data-test=name]', 'Changed');
        handlers.before({
            detail: { visit: { url: new URL('http://localhost/admin/y') } },
        });
        await settle();
        await click(
            [...document.querySelectorAll('[role=dialog] button')].find(
                (b) => b.textContent?.trim() === 'Discard changes',
            )!,
        );

        expect(puts()).toHaveLength(0);
        expect(visit).toHaveBeenCalledWith('http://localhost/admin/y');
    });

    it('returns directly from Back when nothing changed, and asks when something did', async () => {
        setPage(null);
        replies = [ok({ data: CATEGORIES }), ok({ data: draft() })];
        await mountPage();

        await click($('[data-test=leave]'));
        expect(visit).toHaveBeenCalledWith('/admin/blocks/drafts');
        expect(
            handlers.before({
                detail: {
                    visit: {
                        url: new URL('http://localhost/admin/blocks/drafts'),
                    },
                },
            }),
        ).toBeUndefined();
        expect(document.querySelector('[role=dialog]')).toBeNull();

        await type('[data-test=name]', 'Changed');
        expect(
            handlers.before({
                detail: {
                    visit: {
                        url: new URL('http://localhost/admin/blocks/drafts'),
                    },
                },
            }),
        ).toBe(false);
    });
});
