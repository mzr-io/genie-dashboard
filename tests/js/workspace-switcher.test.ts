// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, reactive } from 'vue';

const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/dashboard',
    props: {},
});
const post = vi.fn();
const flushAll = vi.fn();
const reload = vi.fn();
const listeners = new Map<string, (event: Event) => void>();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (name: string, fn: (event: Event) => void) => {
            listeners.set(name, fn);

            return () => {};
        },
        post: (...args: unknown[]) => post(...args),
        flushAll: () => flushAll(),
        reload: (...args: unknown[]) => reload(...args),
    },
    usePage: () => page,
    Link: defineComponent({
        props: ['href'],
        setup:
            (props, { slots, attrs }) =>
            () =>
                h('a', { ...attrs, href: props.href }, slots.default?.()),
    }),
    Head: defineComponent({ setup: () => () => null }),
}));

import AppShell from '../../resources/js/components/AppShell.vue';
import WorkspaceSwitcher from '../../resources/js/components/WorkspaceSwitcher.vue';
import {
    DRAFT_STORAGE_KEY,
    registerFormDraft,
    saveFormDrafts,
    setDraftOwnerResolver,
} from '../../resources/js/lib/formDrafts';
import { initializeFlashToast } from '../../resources/js/lib/flashToast';
import { createCatalogue } from '../../resources/js/lib/i18n';
import {
    clearUnsavedForms,
    hasUnsavedWork,
    registerUnsavedForm,
    saveUnsavedForms,
} from '../../resources/js/lib/unsavedForms';
import { shellLabels } from '../../resources/js/locales/labels';
import { useToasts } from '../../resources/js/stores/toasts';
import type { Shell, ShellWorkspace } from '../../resources/js/types/auth';

function workspaces(count: number): ShellWorkspace[] {
    return Array.from({ length: count }, (_, i) => ({
        id: `w-${i + 1}`,
        name: i === 0 ? 'Acme Industries' : `Workspace ${i + 1}`,
        label: i === 0 ? 'Acme <b>Production</b>' : `Label ${i + 1}`,
        role: i === 1 ? 'user' : 'admin',
    }));
}

function signedIn(list: ShellWorkspace[]): void {
    const current = list[0];
    const shell: Shell = {
        area: 'admin',
        workspace: { id: current.id, name: current.name, label: current.label },
        role: current.role,
        workspaces: list,
        can: {},
        switch_href: '/workspaces/switch',
        items: [],
        help_href: '/help',
        profile_href: '/settings/profile',
        sign_out_href: '/logout',
    };

    page.props = {
        auth: {
            user: { id: 7, name: 'Ada Lovelace', email: 'a@example.test' },
        },
        shell,
    };
}

let wrapper: VueWrapper | null = null;

function mountSwitcher() {
    const pinia = createPinia();
    setActivePinia(pinia);
    const Host = defineComponent({
        setup: () => () =>
            h(AppShell, { variant: 'sidebar' }, () => [h(WorkspaceSwitcher)]),
    });

    wrapper = mount(Host, {
        attachTo: document.body,
        global: { plugins: [pinia, createCatalogue()] },
    });
}

function $(selector: string): HTMLElement {
    const el = document.querySelector<HTMLElement>(selector);

    if (!el) {
        throw new Error(`nothing matches ${selector}`);
    }

    return el;
}

function all(selector: string): HTMLElement[] {
    return [...document.querySelectorAll<HTMLElement>(selector)];
}

async function settle(): Promise<void> {
    await nextTick();
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 5));
    await nextTick();
}

async function openPopover(): Promise<void> {
    $('[data-test="workspace-switcher"]').dispatchEvent(
        new PointerEvent('pointerdown', {
            bubbles: true,
            button: 0,
            pointerType: 'mouse',
        }),
    );
    $('[data-test="workspace-switcher"]').click();
    await settle();
}

function option(name: string): HTMLElement {
    const found = all('[data-test="workspace-option"]').find((el) =>
        el.textContent!.includes(name),
    );

    if (!found) {
        throw new Error(`no option ${name}`);
    }

    return found;
}

beforeEach(() => {
    window.matchMedia = ((query: string) => ({
        matches: /min-width/.test(query),
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
        addListener: () => {},
        removeListener: () => {},
    })) as unknown as typeof window.matchMedia;
    post.mockReset();
    flushAll.mockReset();
    reload.mockReset();
    sessionStorage.clear();
    clearUnsavedForms();
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
});

describe('switcher card', () => {
    it('shows the avatar tile, name, descriptive label (escaped) and a chevron', () => {
        signedIn(workspaces(2));
        mountSwitcher();

        const card = $('[data-test="workspace-switcher"]');

        expect(card.textContent).toContain('AI');
        expect($('[data-slot="workspace-name"]').textContent).toBe(
            'Acme Industries',
        );
        // Shown verbatim as text, never as markup.
        expect($('[data-slot="workspace-label"]').textContent).toBe(
            'Acme <b>Production</b>',
        );
        expect(card.querySelector('b')).toBeNull();
        expect(card.querySelector('svg')).not.toBeNull();
        expect(card.className).toContain(
            'group-data-[collapsible=icon]:size-10',
        );
        // Name and label are hidden in the icon rail; the tile stays.
        expect(
            $('[data-slot="workspace-name"]').parentElement!.className,
        ).toContain('group-data-[collapsible=icon]:hidden');
    });

    it('has no switching affordance with a single Workspace', () => {
        signedIn(workspaces(1));
        mountSwitcher();

        expect($('[data-slot="workspace-name"]').textContent).toBe(
            'Acme Industries',
        );
        expect(
            $('[data-slot="workspace-slot"]').querySelector('button'),
        ).toBeNull();
    });
});

describe('popover', () => {
    it('lists name, label, role tag and a check on the current Workspace, without search up to 7', async () => {
        signedIn(workspaces(3));
        mountSwitcher();
        await openPopover();

        const rows = all('[data-test="workspace-option"]');

        expect(rows).toHaveLength(3);
        expect(rows[0].getAttribute('aria-current')).toBe('true');
        expect(rows[1].getAttribute('aria-current')).toBeNull();
        expect(rows[0].querySelector('svg')).not.toBeNull();
        expect(rows[1].querySelector('svg')).toBeNull();
        expect(rows[1].textContent).toContain('Workspace 2');
        expect(rows[1].textContent).toContain('Label 2');
        expect(rows[1].querySelector('[data-slot="tag"]')!.textContent).toBe(
            'User',
        );
        expect(rows[0].querySelector('[data-slot="tag"]')!.textContent).toBe(
            'Admin',
        );
        expect(
            document.querySelector('[data-test="workspace-search"]'),
        ).toBeNull();
    });

    it('shows search only above 7 Workspaces and filters by name and label', async () => {
        signedIn(workspaces(7));
        mountSwitcher();
        await openPopover();
        expect(
            document.querySelector('[data-test="workspace-search"]'),
        ).toBeNull();

        wrapper!.unmount();
        document.body.innerHTML = '';
        signedIn(workspaces(8));
        mountSwitcher();
        await openPopover();

        const search = $('[data-test="workspace-search"]') as HTMLInputElement;
        search.value = 'label 8';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();

        expect(all('[data-test="workspace-option"]')).toHaveLength(1);

        search.value = 'zzz';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        await settle();

        expect(document.body.textContent).toContain(
            shellLabels.noWorkspaceMatch,
        );
    });
});

describe('switching', () => {
    it('posts the Workspace ID in the body and clears drafts and the cache once it succeeds', async () => {
        signedIn(workspaces(3));
        mountSwitcher();
        await openPopover();
        option('Workspace 2').click();
        await settle();

        expect(post).toHaveBeenCalledTimes(1);
        const [url, body, options] = post.mock.calls[0];

        expect(url).toBe('/workspaces/switch');
        expect(body).toEqual({ workspace_id: 'w-2' });

        setDraftOwnerResolver(() => 'owner');
        registerFormDraft({
            id: 'f',
            snapshot: () => ({ a: 1 }),
            restore: () => {},
        });
        saveFormDrafts();
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).not.toBeNull();

        options.onSuccess();
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).toBeNull();
        expect(flushAll).toHaveBeenCalled();
    });

    it('does nothing when the current Workspace is chosen', async () => {
        signedIn(workspaces(2));
        mountSwitcher();
        await openPopover();
        option('Acme Industries').click();
        await settle();

        expect(post).not.toHaveBeenCalled();
    });

    it('shows an error toast and no Inertia modal on a 403', async () => {
        signedIn(workspaces(2));
        mountSwitcher();
        await openPopover();
        option('Workspace 2').click();
        await settle();

        const options = post.mock.calls[0][2];

        expect(options.onHttpException({ status: 403 })).toBe(false);
        expect(reload).toHaveBeenCalledWith({ only: ['shell'] });
        expect(
            useToasts().items.some(
                (t) => t.message === shellLabels.switchFailed,
            ),
        ).toBe(true);
    });
});

describe('failure branches', () => {
    async function startSwitch() {
        signedIn(workspaces(2));
        mountSwitcher();
        await openPopover();
        option('Workspace 2').click();
        await settle();

        return post.mock.calls[0][2];
    }

    it.each([401, 419])(
        'redirects hard to sign-in on %i without a toast',
        async (status) => {
            const assign = vi.fn();
            vi.stubGlobal('location', { assign });
            const options = await startSwitch();

            expect(options.onHttpException({ status })).toBe(false);
            expect(assign).toHaveBeenCalledWith('/login');
            expect(useToasts().items).toHaveLength(0);
            expect(reload).not.toHaveBeenCalled();
            vi.unstubAllGlobals();
        },
    );

    it('shows the error toast on a network error', async () => {
        const options = await startSwitch();

        options.onNetworkError();

        expect(useToasts().items.map((t) => t.message)).toContain(
            shellLabels.switchFailed,
        );
    });

    it('ignores a second choice while switching, and disables the trigger and options', async () => {
        const options = await startSwitch();

        expect(
            $('[data-test="workspace-switcher"]').hasAttribute('disabled'),
        ).toBe(true);

        $('[data-test="workspace-switcher"]').removeAttribute('disabled');
        await openPopover();
        expect(
            all('[data-test="workspace-option"]').every((el) =>
                el.hasAttribute('disabled'),
            ),
        ).toBe(true);
        option('Acme Industries').click();
        await settle();
        expect(post).toHaveBeenCalledTimes(1);

        options.onFinish();
        await settle();
        expect(
            $('[data-test="workspace-switcher"]').hasAttribute('disabled'),
        ).toBe(false);
    });
});

describe('unsaved work', () => {
    async function chooseWithUnsavedWork(save?: () => Promise<boolean>) {
        signedIn(workspaces(2));
        registerUnsavedForm({ id: 'f', isDirty: () => true, save });
        mountSwitcher();
        await openPopover();
        option('Workspace 2').click();
        await settle();
    }

    it('asks first, focuses Keep editing, and Keep editing cancels the switch', async () => {
        await chooseWithUnsavedWork();

        const dialog = $('[role="dialog"]');

        expect(dialog.textContent).toContain('You have unsaved changes.');
        expect(dialog.textContent).toContain('Save');
        expect(dialog.textContent).toContain('Discard changes');
        expect(post).not.toHaveBeenCalled();
        expect(document.activeElement?.textContent?.trim()).toBe(
            'Keep editing',
        );

        (document.activeElement as HTMLElement).click();
        await settle();

        expect(post).not.toHaveBeenCalled();
        expect(document.querySelector('[role="dialog"]')).toBeNull();
    });

    it('Discard changes switches', async () => {
        await chooseWithUnsavedWork();

        const discard = all('[role="dialog"] button').find(
            (b) => b.textContent!.trim() === 'Discard changes',
        )!;
        discard.click();
        await settle();

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toEqual({ workspace_id: 'w-2' });
    });

    it('Save saves, then switches; a failed save keeps the person where they are', async () => {
        const saved = vi.fn().mockResolvedValue(true);
        await chooseWithUnsavedWork(saved);

        all('[role="dialog"] button')
            .find((b) => b.textContent!.trim() === 'Save')!
            .click();
        await settle();

        expect(saved).toHaveBeenCalled();
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('does not switch when the save fails', async () => {
        await chooseWithUnsavedWork(() => Promise.resolve(false));

        all('[role="dialog"] button')
            .find((b) => b.textContent!.trim() === 'Save')!
            .click();
        await settle();

        expect(post).not.toHaveBeenCalled();
    });

    it('shows an error toast when the save fails', async () => {
        await chooseWithUnsavedWork(() => Promise.resolve(false));

        all('[role="dialog"] button')
            .find((b) => b.textContent!.trim() === 'Save')!
            .click();
        await settle();

        expect(useToasts().items.map((t) => t.message)).toContain(
            shellLabels.switchFailed,
        );
    });

    it('Escape closes the dialog, clears the choice and returns focus to the trigger', async () => {
        await chooseWithUnsavedWork();

        $('[role="dialog"]').dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await settle();

        expect(document.querySelector('[role="dialog"]')).toBeNull();
        expect(post).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(
            $('[data-test="workspace-switcher"]'),
        );
    });

    it('does not ask when nothing is unsaved', async () => {
        signedIn(workspaces(2));
        registerUnsavedForm({ id: 'f', isDirty: () => false });
        mountSwitcher();
        await openPopover();
        option('Workspace 2').click();
        await settle();

        expect(document.querySelector('[role="dialog"]')).toBeNull();
        expect(post).toHaveBeenCalledTimes(1);
    });
});

describe('registerUnsavedForm', () => {
    it('reports dirty forms, unregisters, and treats a throwing form as dirty', async () => {
        const stop = registerUnsavedForm({ id: 'a', isDirty: () => true });

        expect(hasUnsavedWork()).toBe(true);
        stop();
        expect(hasUnsavedWork()).toBe(false);

        registerUnsavedForm({
            id: 'b',
            isDirty: () => {
                throw new Error('x');
            },
        });
        expect(hasUnsavedWork()).toBe(true);
        // A dirty form with no way to save cannot be saved.
        expect(await saveUnsavedForms()).toBe(false);
    });

    it('saves only dirty forms and reports whether all saved', async () => {
        const clean = vi.fn();
        const dirty = vi.fn().mockResolvedValue(true);

        registerUnsavedForm({ id: 'c', isDirty: () => false, save: clean });
        registerUnsavedForm({ id: 'd', isDirty: () => true, save: dirty });

        expect(await saveUnsavedForms()).toBe(true);
        expect(clean).not.toHaveBeenCalled();
        expect(dirty).toHaveBeenCalled();
    });
});

describe('workspace-role message', () => {
    it('toasts msg:workspace-role with the real Workspace name', () => {
        setActivePinia(createPinia());
        initializeFlashToast();

        listeners.get('flash')!(
            new CustomEvent('flash', {
                detail: {
                    flash: { workspace_role: { workspace: 'Acme Ltd' } },
                },
            }),
        );

        expect(useToasts().items.map((t) => t.message)).toContain(
            "You're a User in Acme Ltd.",
        );
    });

    it('keeps $-patterns in the Workspace name literal', () => {
        setActivePinia(createPinia());
        initializeFlashToast();

        listeners.get('flash')!(
            new CustomEvent('flash', {
                detail: {
                    flash: { workspace_role: { workspace: 'R&D $& Co $1' } },
                },
            }),
        );

        expect(useToasts().items.map((t) => t.message)).toContain(
            "You're a User in R&D $& Co $1.",
        );
    });
});
