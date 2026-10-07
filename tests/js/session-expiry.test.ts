// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';

const handlers: Record<string, (event: any) => void> = {};
const post = vi.fn();
const page = { props: { auth: { user: { id: 1 } as { id: number } | null } } };

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (name: string, handler: (event: any) => void) => {
            handlers[name] = handler;

            return () => {};
        },
        post: (...args: unknown[]) => post(...args),
        flushAll: () => {},
    },
    usePage: () => page,
}));

import SessionExpiryDialog from '../../resources/js/components/SessionExpiryDialog.vue';
import { useSessionExpiry } from '../../resources/js/composables/useSessionExpiry';
import {
    DRAFT_STORAGE_KEY,
    clearFormDrafts,
    formValues,
    hashOwner,
    isSecretName,
    registerFormDraft,
    saveFormDrafts,
    scrubSecrets,
} from '../../resources/js/lib/formDrafts';
import { initAnnouncer, resetAnnouncer } from '../../resources/js/lib/announce';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { sessionLabels } from '../../resources/js/locales/labels';

let wrapper: VueWrapper | null = null;
let remaining: number | (() => number) = 600;
let status = 200;
let body: unknown = null;
let failNetwork = false;
const calls: Array<{
    url: string;
    method: string;
    headers: Record<string, string>;
}> = [];

function fakeFetch(): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init: RequestInit) => {
            calls.push({
                url,
                method: String(init.method),
                headers: init.headers as Record<string, string>,
            });

            if (failNetwork) {
                throw new TypeError('offline');
            }

            if (String(url).endsWith('/extend') && status === 200) {
                remaining = 600;
            }

            return new Response(
                JSON.stringify(
                    body ?? {
                        remaining_seconds:
                            typeof remaining === 'function'
                                ? remaining()
                                : remaining,
                        area: 'user',
                    },
                ),
                { status },
            );
        }),
    );
}

async function flush(): Promise<void> {
    for (let i = 0; i < 5; i += 1) {
        await Promise.resolve();
        await nextTick();
    }
}

async function advance(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ms);
    await flush();
}

function announcer(channel: string): string {
    return (
        document.querySelector(`[data-announcer="${channel}"]`)?.textContent ??
        ''
    ).trim();
}

const statusCalls = () => calls.filter((c) => c.method === 'GET').length;

beforeEach(() => {
    vi.useFakeTimers();
    sessionStorage.clear();
    calls.length = 0;
    remaining = 600;
    status = 200;
    body = null;
    failNetwork = false;
    post.mockReset();
    page.props.auth.user = { id: 1 };
    fakeFetch();
    setActivePinia(createPinia());
    resetAnnouncer();
    initAnnouncer();
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

function mountDialog(): VueWrapper {
    const pinia = createPinia();

    setActivePinia(pinia);
    const trigger = document.createElement('button');

    trigger.id = 'before';
    document.body.appendChild(trigger);
    trigger.focus();

    wrapper = mount(SessionExpiryDialog, {
        attachTo: document.body,
        global: { plugins: [pinia, createCatalogue()] },
    });

    return wrapper;
}

function button(label: string): HTMLButtonElement {
    return [...document.querySelectorAll('button')].find((b) =>
        b.textContent?.includes(label),
    )!;
}

// The composable alone, with its state exposed.
type Api = ReturnType<typeof useSessionExpiry>;

function harness(
    leave: () => void = () => {},
    message = (t: string) => t,
): Api {
    let api!: Api;

    wrapper = mount(
        defineComponent({
            setup() {
                api = useSessionExpiry({ message, leave });

                return () => h('div');
            },
        }),
    );

    return api;
}

describe('warning dialog', () => {
    it('stays closed with plenty of time and polls with X-Background: 1', async () => {
        mountDialog();
        await advance(10);

        expect(document.querySelector('[data-session-warning]')).toBeNull();
        expect(calls[0]).toMatchObject({ method: 'GET' });
        expect(calls[0].headers['X-Background']).toBe('1');

        await advance(61_000);
        expect(statusCalls()).toBeGreaterThan(1);
        expect(
            calls.every(
                (c) => c.headers['X-Background'] === '1' || c.method === 'POST',
            ),
        ).toBe(true);
    });

    it('opens two minutes before expiry with the countdown, focuses Stay signed in and announces 2:00, 1:00, 0:30', async () => {
        remaining = 125;
        mountDialog();
        await advance(10);
        expect(document.querySelector('[data-session-warning]')).toBeNull();

        await advance(6000);

        const dialog = document.querySelector('[data-session-warning]')!;

        expect(dialog).not.toBeNull();
        expect(dialog.textContent).toContain(
            "You'll be signed out in 1:59 for security.",
        );
        expect(dialog.textContent).toContain(sessionLabels.signOut);
        expect(document.activeElement?.textContent).toContain(
            sessionLabels.stay,
        );
        expect(announcer('once')).toBe(
            "You'll be signed out in 2:00. Stay signed in to keep working.",
        );

        await advance(60_000);
        expect(announcer('once')).toContain('in 1:00.');

        await advance(30_000);
        expect(announcer('once')).toContain('in 0:30.');
    });

    it('extends with Stay signed in, closes and returns focus to the previous element', async () => {
        remaining = 125;
        mountDialog();
        await advance(8000);

        button(sessionLabels.stay).click();
        await advance(10);

        expect(calls.at(-1)).toMatchObject({ method: 'POST' });
        expect(calls.at(-1)!.url).toBe('/api/v1/session/extend');
        expect(document.querySelector('[data-session-warning]')).toBeNull();
        expect(document.activeElement?.id).toBe('before');
    });

    it('keeps the dialog open and shows the error when extending fails, then clears it once closed', async () => {
        remaining = 125;
        mountDialog();
        await advance(8000);

        status = 500;
        button(sessionLabels.stay).click();
        await advance(10);

        expect(document.querySelector('[data-session-warning]')).not.toBeNull();
        expect(document.body.textContent).toContain(sessionLabels.extendFailed);
    });

    it('clears the extend error when the dialog closes', async () => {
        remaining = 125;
        const api = harness();

        await advance(8000);
        status = 500;
        await api.extend();
        expect(api.error.value).toBe(true);

        status = 200;
        remaining = 600;
        await api.extend();
        expect(api.open.value).toBe(false);
        expect(api.error.value).toBe(false);
    });

    it('syncs again when the tab becomes visible', async () => {
        harness();
        await advance(10);

        const before = statusCalls();

        document.dispatchEvent(new Event('visibilitychange'));
        await advance(10);

        expect(statusCalls()).toBe(before + 1);
    });

    it('reads the status again after an Inertia visit succeeds', async () => {
        harness();
        await advance(10);

        const before = statusCalls();

        handlers.success({});
        await advance(10);

        expect(statusCalls()).toBe(before + 1);
    });

    it('Sign out clears the drafts, stops the countdown and POSTs /logout', async () => {
        remaining = 125;
        mountDialog();
        const stop = registerFormDraft({
            id: 'f',
            snapshot: () => ({ a: 1 }),
            restore: () => {},
        });

        saveFormDrafts();
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).not.toBeNull();
        await advance(8000);

        button(sessionLabels.signOut).click();
        await advance(10);

        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).toBeNull();
        expect(post).toHaveBeenCalledWith('/logout', {}, expect.any(Object));
        expect(document.querySelector('[data-session-warning]')).toBeNull();
        stop();
    });
});

describe('announcements and the warning window', () => {
    it('announces only the latest mark, with the real time, when joining mid-countdown', async () => {
        remaining = 45;
        const messages: string[] = [];

        harness(
            () => {},
            (time) => {
                messages.push(time);

                return time;
            },
        );
        await advance(3000);

        expect(messages).toEqual(['0:45']);
    });

    it('does not announce a mark twice, and not again after an extend until it is reached', async () => {
        remaining = 121;
        const messages: string[] = [];
        const api = harness(
            () => {},
            (time) => {
                messages.push(time);

                return time;
            },
        );

        await advance(3000);
        expect(messages).toEqual(['2:00']);

        await api.extend();
        remaining = 121;
        await advance(61_000);
        await advance(3000);
        expect(messages.filter((m) => m === '2:00').length).toBe(2);
    });

    it('does not reopen at once after an extend when the limit is at most two minutes', async () => {
        remaining = 90;
        const api = harness();

        await advance(10);
        expect(api.open.value).toBe(true);

        remaining = 90;
        status = 200;
        body = { remaining_seconds: 90, area: 'user' };
        await api.extend();
        await advance(500);
        expect(api.open.value).toBe(false);

        await advance(3000);
        expect(api.open.value).toBe(true);
    });

    it('treats a malformed remaining_seconds as a failed call', async () => {
        body = { remaining_seconds: 'soon', area: 'user' };
        const leave = vi.fn();
        const api = harness(leave);

        await advance(10);

        expect(api.open.value).toBe(false);
        expect(api.remaining.value).toBeNull();
        expect(leave).not.toHaveBeenCalled();
    });

    it('ignores a slow status answer that started before an extend', async () => {
        let release!: (value: Response) => void;
        let first = true;

        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string, init: RequestInit) => {
                calls.push({
                    url,
                    method: String(init.method),
                    headers: init.headers as Record<string, string>,
                });

                if (first) {
                    first = false;

                    return new Promise<Response>((resolve) => {
                        release = resolve;
                    });
                }

                return new Response(
                    JSON.stringify({ remaining_seconds: 600, area: 'user' }),
                );
            }),
        );

        const api = harness();

        await advance(10);
        await api.extend();
        release(
            new Response(
                JSON.stringify({ remaining_seconds: 5, area: 'user' }),
            ),
        );
        await advance(10);

        expect(api.remaining.value).toBeGreaterThan(500);
        expect(api.open.value).toBe(false);
    });
});

describe('expiry', () => {
    it('stores registered drafts and leaves when the server says the session is over', async () => {
        const leave = vi.fn();
        const stop = registerFormDraft({
            id: 'profile',
            snapshot: () => ({ name: 'Ada', password: 'hunter2' }),
            restore: () => {},
        });

        status = 401;
        harness(leave);
        await advance(10);

        expect(leave).toHaveBeenCalledOnce();

        const saved = JSON.parse(sessionStorage.getItem(DRAFT_STORAGE_KEY)!);

        expect(saved.profile.values).toEqual({ name: 'Ada' });
        expect(saved.profile.owner).toBe(hashOwner(1));
        stop();
    });

    it('asks the server at zero before ending, so activity elsewhere keeps the session', async () => {
        const leave = vi.fn();

        remaining = 3;
        harness(leave);
        await advance(10);
        remaining = 400;
        await advance(4000);

        expect(leave).not.toHaveBeenCalled();
    });

    it('retries with a back-off when the confirming call fails offline, and ends only on a confirmed zero', async () => {
        const leave = vi.fn();

        remaining = 2;
        harness(leave);
        await advance(10);

        failNetwork = true;
        await advance(2500);
        const attempts = statusCalls();

        await advance(1000);
        await advance(2000);
        expect(leave).not.toHaveBeenCalled();
        expect(statusCalls()).toBeGreaterThan(attempts);

        failNetwork = false;
        status = 200;
        remaining = 0;
        await advance(20_000);
        expect(leave).toHaveBeenCalledOnce();
    });

    it('does not end on a server error at zero', async () => {
        const leave = vi.fn();

        remaining = 2;
        harness(leave);
        await advance(10);
        status = 503;
        await advance(8000);

        expect(leave).not.toHaveBeenCalled();
    });

    it('ends on a 401 from the confirming call', async () => {
        const leave = vi.fn();

        remaining = 2;
        harness(leave);
        await advance(10);
        status = 401;
        await advance(3000);

        expect(leave).toHaveBeenCalledOnce();
    });

    it('a 401 from an Inertia visit saves drafts, cancels the default handling and leaves', async () => {
        const leave = vi.fn();
        const stop = registerFormDraft({
            id: 'x',
            snapshot: () => ({ v: 1 }),
            restore: () => {},
        });

        harness(leave);
        await advance(10);

        const event = {
            detail: { response: { status: 401 } },
            preventDefault: vi.fn(),
        };

        handlers.httpException(event);

        expect(event.preventDefault).toHaveBeenCalled();
        expect(leave).toHaveBeenCalledOnce();
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).toContain('"x"');
        stop();
    });

    it('a 500 from an Inertia visit does neither', async () => {
        const leave = vi.fn();
        const stop = registerFormDraft({
            id: 'x',
            snapshot: () => ({ v: 1 }),
            restore: () => {},
        });

        harness(leave);
        await advance(10);

        const event = {
            detail: { response: { status: 500 } },
            preventDefault: vi.fn(),
        };

        handlers.httpException(event);

        expect(event.preventDefault).not.toHaveBeenCalled();
        expect(leave).not.toHaveBeenCalled();
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).toBeNull();
        stop();
    });
});

describe('sign out', () => {
    it('marks the session over: no drafts saved, 401 or 419 from /logout goes to sign-in', async () => {
        const leave = vi.fn();
        const stop = registerFormDraft({
            id: 'x',
            snapshot: () => ({ v: 1 }),
            restore: () => {},
        });
        const api = harness(leave);

        await advance(10);
        api.signOut();

        const options = post.mock.calls[0][2];

        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).toBeNull();
        expect(options.onHttpException({ status: 419 })).toBe(false);
        expect(leave).toHaveBeenCalledOnce();
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).toBeNull();
        stop();
    });

    it('resumes the countdown when /logout fails for another reason', async () => {
        remaining = 125;
        const leave = vi.fn();
        const api = harness(leave);

        await advance(8000);
        api.signOut();
        expect(api.open.value).toBe(false);

        post.mock.calls[0][2].onHttpException({ status: 500 });
        await advance(1500);

        expect(leave).not.toHaveBeenCalled();
        expect(api.open.value).toBe(true);
    });
});

describe('form-draft hook', () => {
    afterEach(() => clearFormDrafts());

    it('restores a registered form once after expiry and never an unregistered one', () => {
        const stop = registerFormDraft({
            id: 'a',
            snapshot: () => ({ x: 1 }),
            restore: () => {},
        });

        saveFormDrafts();
        stop();
        expect(
            JSON.parse(sessionStorage.getItem(DRAFT_STORAGE_KEY)!).a,
        ).toMatchObject({
            owner: hashOwner(1),
            values: { x: 1 },
        });

        const restoreOther = vi.fn();
        const stopOther = registerFormDraft({
            id: 'b',
            snapshot: () => ({}),
            restore: restoreOther,
        });

        expect(restoreOther).not.toHaveBeenCalled();
        stopOther();

        const restore = vi.fn();

        registerFormDraft({ id: 'a', snapshot: () => ({}), restore })();
        expect(restore).toHaveBeenCalledWith({ x: 1 });
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).toBeNull();
    });

    it('discards a draft that belongs to another person without restoring it', () => {
        const stop = registerFormDraft({
            id: 'a',
            snapshot: () => ({ x: 1 }),
            restore: () => {},
        });

        saveFormDrafts();
        stop();
        page.props.auth.user = { id: 2 };

        const restore = vi.fn();

        registerFormDraft({ id: 'a', snapshot: () => ({}), restore })();

        expect(restore).not.toHaveBeenCalled();
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).toBeNull();
    });

    it('waits for a signed-in page when nobody is signed in yet', () => {
        const stop = registerFormDraft({
            id: 'a',
            snapshot: () => ({ x: 1 }),
            restore: () => {},
        });

        saveFormDrafts();
        stop();
        page.props.auth.user = null;

        const restore = vi.fn();

        registerFormDraft({ id: 'a', snapshot: () => ({}), restore })();

        expect(restore).not.toHaveBeenCalled();
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).not.toBeNull();
    });

    it('keeps the draft when restore throws', () => {
        const stop = registerFormDraft({
            id: 'a',
            snapshot: () => ({ x: 1 }),
            restore: () => {},
        });

        saveFormDrafts();
        stop();

        registerFormDraft({
            id: 'a',
            snapshot: () => ({}),
            restore: () => {
                throw new Error('boom');
            },
        })();

        expect(
            JSON.parse(sessionStorage.getItem(DRAFT_STORAGE_KEY)!).a.values,
        ).toEqual({ x: 1 });
    });

    it('merges with drafts that were never restored and writes nothing when nothing was captured', () => {
        const stopA = registerFormDraft({
            id: 'a',
            snapshot: () => ({ x: 1 }),
            restore: () => {},
        });

        saveFormDrafts();
        stopA();

        const setItem = vi.spyOn(Storage.prototype, 'setItem');

        saveFormDrafts();
        expect(setItem).not.toHaveBeenCalled();

        const stopB = registerFormDraft({
            id: 'b',
            snapshot: () => ({ y: 2 }),
            restore: () => {},
        });

        saveFormDrafts();
        stopB();
        setItem.mockRestore();

        expect(
            Object.keys(
                JSON.parse(sessionStorage.getItem(DRAFT_STORAGE_KEY)!),
            ).sort(),
        ).toEqual(['a', 'b']);
    });

    it('keeps no personal data in the storage key or the owner', () => {
        expect(DRAFT_STORAGE_KEY).toBe('dashflow:form-drafts');
        expect(hashOwner(42)).not.toContain('42');
        expect(hashOwner(null)).toBeNull();
    });

    it('scrubs secret-looking keys at any depth', () => {
        expect(
            scrubSecrets({
                a: 1,
                api_token: 'x',
                n: { current_password: 'y', ok: [{ secret: 1, v: 2 }] },
            }),
        ).toEqual({
            a: 1,
            n: { ok: [{ v: 2 }] },
        });
    });

    it.each([
        'password',
        'current_password',
        'newPassword',
        'user[password]',
        'api_key',
        'apiKey',
        'otp',
        'pin',
        'cvv',
        'card_number',
        'Authorization',
        'credentials',
        'access-token',
    ])('treats %s as secret', (name) => {
        expect(isSecretName(name)).toBe(true);
    });

    it.each([
        'passenger',
        'tokenizer',
        'title',
        'spinner',
        'cardholder',
        'compass',
        'description',
    ])('keeps %s', (name) => {
        expect(isSecretName(name)).toBe(false);
    });

    it('formValues skips password inputs, data-secret fields and secret-named fields', () => {
        const form = document.createElement('form');

        form.innerHTML = `
            <input name="title" value="Report">
            <input name="passenger" value="Ada">
            <input name="pw" type="password" value="p">
            <input name="visible" data-secret value="s">
            <div data-secret><input name="inner" value="i"></div>
            <input name="api_token" value="t">
            <input name="otp" value="123456">
            <input name="agree" type="checkbox" checked value="yes">
            <input name="no" type="checkbox" value="no">
            <textarea name="notes">hello</textarea>`;

        expect(formValues(form)).toEqual({
            title: 'Report',
            passenger: 'Ada',
            agree: 'yes',
            notes: 'hello',
        });
    });

    it('formValues collects same-name checkboxes and multiple selects as arrays', () => {
        const form = document.createElement('form');

        form.innerHTML = `
            <input name="tags" type="checkbox" value="a" checked>
            <input name="tags" type="checkbox" value="b">
            <input name="tags" type="checkbox" value="c" checked>
            <input name="empty" type="checkbox" value="x">
            <input name="empty" type="checkbox" value="y">
            <select name="roles" multiple>
                <option value="r1" selected>1</option>
                <option value="r2">2</option>
                <option value="r3" selected>3</option>
            </select>
            <select name="one"><option value="o1">1</option><option value="o2" selected>2</option></select>
            <input name="size" type="radio" value="s">
            <input name="size" type="radio" value="m" checked>`;

        expect(formValues(form)).toEqual({
            tags: ['a', 'c'],
            empty: [],
            roles: ['r1', 'r3'],
            one: 'o2',
            size: 'm',
        });
    });
});
