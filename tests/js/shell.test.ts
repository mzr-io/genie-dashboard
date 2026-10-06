// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, reactive } from 'vue';

// A mutable page and router shared by every mounted component of one test.
const page = reactive<{ url: string; props: Record<string, unknown> }>({
    url: '/dashboard',
    props: {},
});
const post = vi.fn();
const flushAll = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: () => () => {},
        post: (...args: unknown[]) => post(...args),
        flushAll: () => flushAll(),
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

import AppSidebarHeader from '../../resources/js/components/AppSidebarHeader.vue';
import AppShell from '../../resources/js/components/AppShell.vue';
import AppSidebar from '../../resources/js/components/AppSidebar.vue';
import ListStates from '../../resources/js/components/ListStates.vue';
import AppSidebarLayout from '../../resources/js/layouts/app/AppSidebarLayout.vue';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import {
    DRAFT_STORAGE_KEY,
    saveFormDrafts,
    registerFormDraft,
    setDraftOwnerResolver,
} from '../../resources/js/lib/formDrafts';
import { createCatalogue } from '../../resources/js/lib/i18n';
import { currentItem } from '../../resources/js/lib/shell';
import en from '../../resources/js/locales/en';
import { shellLabels, shellPages } from '../../resources/js/locales/labels';
import Placeholder from '../../resources/js/pages/Placeholder.vue';
import { useToasts } from '../../resources/js/stores/toasts';
import type { Shell, ShellItem } from '../../resources/js/types/auth';

const USER_ITEMS: Array<[string, string]> = [
    ['overview', '/dashboard'],
    ['my-dashboards', '/dashboards'],
    ['templates', '/templates'],
    ['profile', '/settings/profile'],
    ['help', '/help'],
];

const ADMIN_ITEMS: Array<[string, string, string | null]> = [
    ['admin-overview', '/admin', null],
    ['block-management', '/admin/blocks', 'blocks.edit'],
    ['create-block', '/admin/blocks/create', 'blocks.edit'],
    ['draft-blocks', '/admin/blocks/drafts', 'blocks.edit'],
    ['published-blocks', '/admin/blocks/published', 'blocks.edit'],
    ['block-categories', '/admin/categories', 'blocks.edit'],
    ['dashboard-templates', '/admin/templates', 'templates.manage'],
    ['data-sources', '/admin/data-sources', 'data_sources.manage'],
    ['user-configuration', '/admin/users', 'users.manage'],
    ['system-settings', '/admin/settings', 'settings.manage'],
    ['audit-log', '/admin/audit', 'audit.view'],
];

function shell(
    area: 'user' | 'admin',
    held: string[] = [],
    role: string | null = area,
): Shell {
    const items: ShellItem[] =
        area === 'admin'
            ? ADMIN_ITEMS.map(([key, href, permission]) => ({
                  key,
                  href,
                  permission,
                  allowed: permission === null || held.includes(permission),
              }))
            : USER_ITEMS.map(([key, href]) => ({
                  key,
                  href,
                  permission: null,
                  allowed: true,
              }));

    return {
        area,
        workspace: { id: 'w-1', name: 'Acme Industries' },
        role,
        items,
        help_href: '/help',
        profile_href: '/settings/profile',
        sign_out_href: '/logout',
    };
}

function signedIn(value: Shell, url: string): void {
    page.url = url;
    page.props = {
        auth: {
            user: { id: 7, name: 'Ada Lovelace', email: 'ada@example.test' },
        },
        shell: value,
    };
}

// Viewport width for useMediaQuery: the shell reads `(min-width: 1280px)` and `(max-width: 639px)`.
function viewport(width: number): void {
    window.matchMedia = ((query: string) => {
        const min = /min-width:\s*(\d+)px/.exec(query);
        const max = /max-width:\s*(\d+)px/.exec(query);
        const matches =
            (min ? width >= Number(min[1]) : true) &&
            (max ? width <= Number(max[1]) : true);

        return {
            matches,
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
            addListener: () => {},
            removeListener: () => {},
        };
    }) as unknown as typeof window.matchMedia;
}

let wrapper: VueWrapper | null = null;

function plugins() {
    const pinia = createPinia();

    setActivePinia(pinia);

    return [pinia, createCatalogue()];
}

// The sidebar and top bar inside the provider that the layouts supply.
function mountShell(crumbs: Array<{ title: string; href: string }> = []) {
    const Host = defineComponent({
        setup: () => () =>
            h(AppShell, { variant: 'sidebar' }, () => [
                h(AppSidebar),
                h(AppSidebarHeader, { breadcrumbs: crumbs }),
            ]),
    });

    wrapper = mount(Host, {
        attachTo: document.body,
        global: { plugins: plugins() },
    });

    return wrapper;
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

function key(el: Element, name: string): void {
    el.dispatchEvent(
        new KeyboardEvent('keydown', { key: name, bubbles: true }),
    );
}

beforeEach(() => {
    viewport(1280);
    post.mockReset();
    flushAll.mockReset();
    sessionStorage.clear();
    vi.stubGlobal('fetch', () => Promise.reject(new Error('offline')));
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    resetAnnouncer();
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('User area sidebar', () => {
    it('shows WORKSPACE with the five items, each to its page', () => {
        signedIn(shell('user'), '/dashboard');
        mountShell();

        const nav = $('nav[aria-label="Main navigation"]');

        expect(nav.textContent).toContain('WORKSPACE');
        expect(nav.textContent).not.toContain('ADMINISTRATION');

        const items = all('[data-nav-item]');

        expect(items.map((el) => el.textContent!.trim())).toEqual([
            'Overview',
            'My dashboards',
            'Templates',
            'Profile & settings',
            'Help & support',
        ]);
        expect(items.map((el) => el.getAttribute('href'))).toEqual(
            USER_ITEMS.map(([, href]) => href),
        );
    });

    it('marks the active item with the accent-soft fill and the 3px bar, and nothing else', () => {
        signedIn(shell('user'), '/dashboards');
        mountShell();

        const active = all('[data-nav-item][data-active="true"]');

        expect(active.map((el) => el.dataset.navItem)).toEqual([
            'my-dashboards',
        ]);
        expect(active[0].getAttribute('aria-current')).toBe('page');
        // The classes carry the fill and the leading bar (UX-DR-80).
        expect(active[0].className).toContain(
            'data-[active=true]:bg-sidebar-accent',
        );
        expect(active[0].className).toContain('before:w-[3px]');
        expect(active[0].className).toContain('before:bg-accent-ink-strong');
    });

    it('keeps Profile & settings active on its sub-pages', () => {
        signedIn(shell('user'), '/settings/security');
        mountShell();

        expect(
            all('[data-nav-item][data-active="true"]').map(
                (el) => el.dataset.navItem,
            ),
        ).toEqual(['profile']);
    });

    it('holds Help & support, Sign out and the user row in the footer, and no Appearance', () => {
        signedIn(shell('user'), '/dashboard');
        mountShell();

        const footer = $('[data-slot="sidebar-footer"]');

        expect(
            footer.querySelector('[data-test="footer-help"]'),
        ).not.toBeNull();
        expect(
            footer.querySelector('[data-test="sign-out-button"]')!.textContent,
        ).toContain('Sign out');

        const row = footer.querySelector('[data-test="sidebar-menu-button"]')!;

        expect(row.textContent).toContain('Ada Lovelace');
        expect(row.textContent).toContain('User');
        // The avatar is a circle (UX-DR-9).
        expect(row.querySelector('[data-slot="avatar"]')!.className).toContain(
            'rounded-full',
        );
        expect(row.querySelector('svg')).not.toBeNull();

        expect(document.body.textContent).not.toMatch(/appearance|theme/i);
        expect(
            document.querySelector(
                '[role="switch"], [aria-label*="theme" i], [data-test*="theme"]',
            ),
        ).toBeNull();
    });

    it('shows the current Workspace name in the switcher slot, nothing to switch', () => {
        signedIn(shell('user'), '/dashboard');
        mountShell();

        expect($('[data-slot="workspace-name"]').textContent).toBe(
            'Acme Industries',
        );
        expect(
            $('[data-slot="workspace-slot"]').querySelector('button, a'),
        ).toBeNull();
    });
});

describe('Admin area sidebar', () => {
    it('shows ADMINISTRATION with the eleven items', () => {
        signedIn(
            shell('admin', [
                'blocks.edit',
                'templates.manage',
                'data_sources.manage',
                'users.manage',
                'settings.manage',
                'audit.view',
            ]),
            '/admin',
        );
        mountShell();

        expect($('nav[aria-label="Main navigation"]').textContent).toContain(
            'ADMINISTRATION',
        );
        expect(
            all('[data-nav-item]').map((el) => el.textContent!.trim()),
        ).toEqual([
            'Admin overview',
            'Block management',
            'Create block',
            'Draft blocks',
            'Published blocks',
            'Block categories',
            'Dashboard templates',
            'Data sources',
            'User configuration',
            'System settings',
            'Audit log',
        ]);
        expect(all('[aria-disabled="true"][data-nav-item]')).toHaveLength(0);
        expect(
            all('[data-nav-item][data-active="true"]').map(
                (el) => el.dataset.navItem,
            ),
        ).toEqual(['admin-overview']);
    });

    it('shows an item the Admin lacks the permission for disabled with the reason, focusable, never hidden', () => {
        signedIn(shell('admin', ['audit.view']), '/admin');
        mountShell();

        const disabled = all('[aria-disabled="true"][data-nav-item]');

        expect(disabled.map((el) => el.dataset.navItem)).toEqual([
            'block-management',
            'create-block',
            'draft-blocks',
            'published-blocks',
            'block-categories',
            'dashboard-templates',
            'data-sources',
            'user-configuration',
            'system-settings',
        ]);

        for (const item of disabled) {
            // A button, so it stays in the tab order, and it is not a link to the denied page.
            expect(item.tagName).toBe('BUTTON');
            expect(item.getAttribute('tabindex')).toBeNull();
            expect(item.hasAttribute('disabled')).toBe(false);
            expect(item.hasAttribute('href')).toBe(false);

            const reason = document.getElementById(
                item.getAttribute('aria-describedby')!,
            )!;

            expect(reason.textContent).toBe(en['perm-denied']);
        }

        // The permitted items are links.
        expect(all('a[data-nav-item]').map((el) => el.dataset.navItem)).toEqual(
            ['admin-overview', 'audit-log'],
        );
    });

    it('announces the reason instead of navigating when a disabled item is activated', async () => {
        signedIn(shell('admin', []), '/admin');
        mountShell();

        const item = $('[data-nav-item="block-management"]');

        item.focus();
        item.click();
        await settle();

        expect(document.activeElement).toBe(item);
        expect(
            [...document.querySelectorAll('[data-announcer]')]
                .map((el) => el.textContent)
                .join(' '),
        ).toContain(en['perm-denied']);
    });
});

describe('profile menu', () => {
    async function open(): Promise<HTMLElement> {
        const trigger = $('[data-test="sidebar-menu-button"]');

        trigger.focus();
        key(trigger, 'Enter');
        await settle();

        return trigger;
    }

    it('shows the name and the role for the active Workspace with Profile & settings and Sign out', async () => {
        signedIn(shell('admin', []), '/admin');
        mountShell();

        await open();

        const content = $('[data-slot="dropdown-menu-content"]');

        expect(content.textContent).toContain('Ada Lovelace');
        expect(content.textContent).toContain('Admin in Acme Industries');
        expect(
            content
                .querySelector('[data-test="profile-menu-settings"]')!
                .getAttribute('href'),
        ).toBe('/settings/profile');
        expect(
            content.querySelector('[data-test="profile-menu-sign-out"]')!
                .textContent,
        ).toContain('Sign out');
    });

    it('closes on Esc and returns focus to the user row', async () => {
        signedIn(shell('user'), '/dashboard');
        mountShell();

        const trigger = await open();

        expect(
            document.querySelector('[data-slot="dropdown-menu-content"]'),
        ).not.toBeNull();

        key($('[data-slot="dropdown-menu-content"]'), 'Escape');
        await settle();
        await settle();

        expect(
            document.querySelector('[data-slot="dropdown-menu-content"]'),
        ).toBeNull();
        expect(document.activeElement).toBe(trigger);
    });
});

describe('sign out', () => {
    type Callbacks = {
        onSuccess: () => void;
        onHttpException: (r: { status: number }) => boolean | void;
        onNetworkError: (e: Error) => boolean | void;
        onError: () => void;
        onFinish: () => void;
    };

    function seedDraft(): () => void {
        setDraftOwnerResolver(() => 'owner');
        const stop = registerFormDraft({
            id: 'profile',
            snapshot: () => ({ name: 'Ada' }),
            restore: () => {},
        });

        saveFormDrafts();
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).not.toBeNull();

        return stop;
    }

    async function start(): Promise<Callbacks> {
        signedIn(shell('user'), '/dashboard');
        mountShell();
        $('[data-test="sign-out-button"]').click();
        await settle();

        expect(post).toHaveBeenCalledWith('/logout', {}, expect.any(Object));

        return post.mock.calls[0][2] as Callbacks;
    }

    function toastKinds(): string[] {
        return useToasts().items.map((t) => t.kind);
    }

    it('keeps drafts and the cache until the POST succeeds, then clears both', async () => {
        const stop = seedDraft();
        const options = await start();

        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).not.toBeNull();
        expect(flushAll).not.toHaveBeenCalled();

        options.onSuccess();
        options.onFinish();

        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).toBeNull();
        expect(flushAll).toHaveBeenCalled();
        expect(toastKinds()).toEqual([]);
        stop();
    });

    it.each([401, 419])(
        'goes to sign-in without a toast on %i and suppresses the error modal',
        async (status) => {
            const assign = vi.fn();

            vi.stubGlobal('location', {
                assign,
                origin: 'http://localhost',
            });
            const options = await start();

            expect(options.onHttpException({ status })).toBe(false);
            expect(assign).toHaveBeenCalledWith('/login');
            expect(toastKinds()).toEqual([]);
        },
    );

    it('shows the error toast, suppresses the modal and keeps drafts on a 500', async () => {
        const stop = seedDraft();
        const options = await start();

        expect(options.onHttpException({ status: 500 })).toBe(false);
        options.onFinish();

        expect(useToasts().items.map((t) => [t.kind, t.message])).toEqual([
            ['error', shellLabels.signOutFailed],
        ]);
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).not.toBeNull();
        expect(flushAll).not.toHaveBeenCalled();
        stop();
    });

    it('shows the error toast and keeps drafts on a network error', async () => {
        const stop = seedDraft();
        const options = await start();

        expect(options.onNetworkError(new Error('offline'))).toBe(false);

        expect(toastKinds()).toEqual(['error']);
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).not.toBeNull();
        stop();
    });

    it('shows the error toast and keeps drafts on a validation error', async () => {
        const stop = seedDraft();
        const options = await start();

        options.onError();

        expect(toastKinds()).toEqual(['error']);
        expect(sessionStorage.getItem(DRAFT_STORAGE_KEY)).not.toBeNull();
        stop();
    });

    it('works again after a failure', async () => {
        const options = await start();

        options.onHttpException({ status: 500 });
        options.onFinish();

        post.mockReset();
        $('[data-test="sign-out-button"]').click();
        expect(post).toHaveBeenCalledTimes(1);
    });
});

describe('top bar', () => {
    it('is a 64px banner with the settings gear to Profile & settings in the User area', () => {
        signedIn(shell('user'), '/dashboard');
        mountShell([{ title: 'Overview', href: '/dashboard' }]);

        const bar = $('header[data-slot="top-bar"]');

        expect(bar.className).toContain('h-(--df-topbar-height)');
        expect(bar.parentElement!.closest('main, article, section')).toBeNull();

        const gear = $('[data-test="topbar-settings"]');

        expect(gear.getAttribute('href')).toBe('/settings/profile');
        expect(gear.getAttribute('aria-label')).toBe('Settings');
    });

    it('points the gear at System settings in the Admin area, disabled when not permitted', () => {
        signedIn(shell('admin', ['settings.manage']), '/admin');
        mountShell();
        expect($('[data-test="topbar-settings"]').getAttribute('href')).toBe(
            '/admin/settings',
        );

        wrapper!.unmount();
        document.body.innerHTML = '';
        signedIn(shell('admin', []), '/admin');
        mountShell();

        const gear = $('[data-test="topbar-settings"]');

        expect(gear.getAttribute('aria-disabled')).toBe('true');
        expect(gear.hasAttribute('href')).toBe(false);
    });

    it('shows the notifications bell and the search as disabled controls with a reason', () => {
        signedIn(shell('user'), '/dashboard');
        mountShell();

        for (const [id, reason] of [
            ['topbar-search', shellLabels.searchReason],
            ['topbar-notifications', shellLabels.notificationsReason],
        ]) {
            const control = $(`[data-test="${id}"]`);

            expect(control.getAttribute('aria-disabled')).toBe('true');
            expect(control.hasAttribute('disabled')).toBe(false);
            expect(document.getElementById(`${id}-reason`)!.textContent).toBe(
                reason,
            );
            expect(control.getAttribute('aria-describedby')).toBe(
                `${id}-reason`,
            );
        }
    });

    it('offers the back button only with a parent breadcrumb', () => {
        signedIn(shell('user'), '/settings/security');
        mountShell([
            { title: 'Profile & settings', href: '/settings/profile' },
            { title: 'Security settings', href: '/settings/security' },
        ]);

        expect($('[data-test="back-button"]').getAttribute('href')).toBe(
            '/settings/profile',
        );

        wrapper!.unmount();
        document.body.innerHTML = '';
        mountShell([{ title: 'Overview', href: '/dashboard' }]);
        expect(document.querySelector('[data-test="back-button"]')).toBeNull();
    });
});

describe('reflow', () => {
    it('shows the full sidebar from 1280px', () => {
        signedIn(shell('user'), '/dashboard');
        viewport(1280);
        mountShell();

        expect($('[data-slot="sidebar"]').getAttribute('data-state')).toBe(
            'expanded',
        );
    });

    it('collapses to the icon rail at a tablet width, with a tooltip on focus', async () => {
        signedIn(shell('user'), '/dashboard');
        viewport(768);
        mountShell();

        const sidebar = $('[data-slot="sidebar"]');

        expect(sidebar.getAttribute('data-state')).toBe('collapsed');
        expect(sidebar.getAttribute('data-collapsible')).toBe('icon');
        // The sidebar stays visible from 640px (the `sm` breakpoint), and the sheet trigger is absent.
        expect(sidebar.className).toContain('sm:block');
        expect(
            document.querySelector('[data-test="open-navigation"]'),
        ).toBeNull();

        const item = $('[data-nav-item="my-dashboards"]');

        item.focus();
        item.dispatchEvent(new FocusEvent('focus'));
        await settle();

        expect(
            document.querySelector('[data-slot="tooltip-content"]')
                ?.textContent,
        ).toContain('My dashboards');
    });

    it('becomes an overlay sheet below 640px, opened from the top bar, with a visible close button', async () => {
        signedIn(shell('user'), '/dashboard');
        viewport(639);
        mountShell();

        expect(document.querySelector('[data-slot="sidebar"]')).toBeNull();

        $('[data-test="open-navigation"]').click();
        await settle();

        const sheet = $('[data-mobile="true"]');

        expect(sheet.getAttribute('role')).toBe('dialog');
        expect(
            sheet.querySelector('nav[aria-label="Main navigation"]'),
        ).not.toBeNull();
        expect(sheet.querySelector('[data-slot="sheet-close"]')).not.toBeNull();
        expect(sheet.className).not.toContain('[&>button]:hidden');
    });

    it('lets the content column shrink so nothing scrolls sideways at 320px', () => {
        signedIn(shell('user'), '/dashboard');
        viewport(320);
        wrapper = mount(AppSidebarLayout, {
            attachTo: document.body,
            global: { plugins: plugins() },
        });

        const inset = $('[data-slot="sidebar-inset"]');

        expect(inset.className).toContain('min-w-0');
        expect(inset.className).toContain('overflow-x-clip');
    });
});

describe('landmarks', () => {
    it('gives every page the banner, navigation, a main named by the page and the skip link first', () => {
        signedIn(shell('user'), '/dashboards');
        wrapper = mount(AppSidebarLayout, {
            attachTo: document.body,
            global: { plugins: plugins() },
        });

        const first = $('a[href], button, input, [tabindex="0"]');

        expect(first.textContent).toContain('Skip to content');
        expect(first.getAttribute('href')).toBe('#main-content');

        expect($('header[data-slot="top-bar"]')).not.toBeNull();
        expect($('nav[aria-label="Main navigation"]')).not.toBeNull();

        const main = $('main');

        expect(main.id).toBe('main-content');
        // No navigation item's page names itself in the breadcrumb: the item's title does.
        expect(main.getAttribute('aria-label')).toBe('My dashboards');
        expect(all('main')).toHaveLength(1);
        expect(all('header')).toHaveLength(1);
    });

    it('names main by the last breadcrumb when the page sets one', () => {
        signedIn(shell('user'), '/settings/profile');
        wrapper = mount(AppSidebarLayout, {
            attachTo: document.body,
            props: {
                breadcrumbs: [
                    { title: 'Profile & settings', href: '/settings/profile' },
                    { title: 'Security settings', href: '/settings/security' },
                ],
            },
            global: { plugins: plugins() },
        });

        expect($('main').getAttribute('aria-label')).toBe('Security settings');
    });
});

describe('Admin area on User-area pages', () => {
    const held = ['blocks.edit'];

    it('marks Help & support active in the footer and names main by it on /help', () => {
        signedIn(shell('admin', held), '/help');
        wrapper = mount(AppSidebarLayout, {
            attachTo: document.body,
            global: { plugins: plugins() },
        });

        expect($('[data-test="footer-help"]').getAttribute('data-active')).toBe(
            'true',
        );
        expect($('main').getAttribute('aria-label')).toBe('Help & support');
    });

    it('names main by Profile & settings on /settings/profile', () => {
        signedIn(shell('admin', held), '/settings/profile');
        wrapper = mount(AppSidebarLayout, {
            attachTo: document.body,
            global: { plugins: plugins() },
        });

        expect($('main').getAttribute('aria-label')).toBe('Profile & settings');
    });
});

describe('sidebar details', () => {
    it('wraps only the navigation items in the navigation landmark', () => {
        signedIn(shell('user'), '/dashboard');
        mountShell();

        const nav = $('nav[aria-label="Main navigation"]');

        expect(nav.querySelector('[data-slot="workspace-slot"]')).toBeNull();
        expect(nav.querySelector('[data-slot="sidebar-footer"]')).toBeNull();
        expect(
            nav.querySelector('[data-test="sidebar-menu-button"]'),
        ).toBeNull();
        expect(nav.querySelector('[data-test="sign-out-button"]')).toBeNull();
        expect(all('[data-nav-item]').every((el) => nav.contains(el))).toBe(
            true,
        );
    });

    it('dims a disabled item on the control, keeps the reason once and hides the tooltip from assistive tech', async () => {
        signedIn(shell('admin', []), '/admin');
        viewport(768);
        mountShell();

        const item = $('[data-nav-item="block-management"]');

        expect(item.className).toContain('aria-disabled:opacity-45');
        const nav = $('nav[aria-label="Main navigation"]');

        expect(
            [...nav.querySelectorAll('.sr-only')].filter(
                (el) => el.textContent === en['perm-denied'],
            ),
        ).toHaveLength(10);

        item.focus();
        item.dispatchEvent(new FocusEvent('focus'));
        await settle();

        expect(
            $('[data-slot="tooltip-content"]').getAttribute('aria-hidden'),
        ).toBe('true');
    });

    it('closes the overlay sheet after a link is activated', async () => {
        signedIn(shell('user'), '/dashboard');
        viewport(500);
        mountShell();

        $('[data-test="open-navigation"]').click();
        await settle();
        expect(document.querySelector('[data-mobile="true"]')).not.toBeNull();

        $('[data-mobile="true"] [data-nav-item="templates"]').click();
        await settle();
        await settle();

        expect(document.querySelector('[data-mobile="true"]')).toBeNull();
    });

    it('closes the overlay sheet once sign out succeeds', async () => {
        signedIn(shell('user'), '/dashboard');
        viewport(500);
        mountShell();

        $('[data-test="open-navigation"]').click();
        await settle();
        $('[data-mobile="true"] [data-test="sign-out-button"]').click();
        await settle();

        // Still open while the request runs, so a failure resumes the shell.
        expect(document.querySelector('[data-mobile="true"]')).not.toBeNull();

        (post.mock.calls[0][2] as { onSuccess: () => void }).onSuccess();
        await settle();
        await settle();

        expect(document.querySelector('[data-mobile="true"]')).toBeNull();
    });
});

describe('placeholder pages', () => {
    function page_(key: keyof typeof shellPages) {
        wrapper = mount(Placeholder, {
            attachTo: document.body,
            props: { page: key },
            global: { plugins: plugins() },
        });
    }

    it('shows the title, the subtitle and the empty state of the Overview', () => {
        signedIn(shell('user'), '/dashboard');
        page_('overview');

        expect($('h1').textContent).toBe('Overview');
        expect($('[data-slot="page-subtitle"]').textContent).toBe(
            en['page-subtitles'].overview,
        );
        expect($('[data-slot="list-empty"]').textContent!.trim()).toBe(
            'No dashboards yet. Create a dashboard to start.',
        );
    });

    it('uses the admin subtitle on Admin overview and offers + Create block', () => {
        signedIn(shell('admin', ['blocks.edit']), '/admin');
        page_('admin-overview');

        expect($('[data-slot="page-subtitle"]').textContent).toBe(
            en['page-subtitles'].admin,
        );
        expect($('a[href="/admin/blocks/create"]').textContent).toContain(
            '+ Create block',
        );
    });

    it('disables + Create block with the reason when the Admin lacks blocks.edit', () => {
        signedIn(shell('admin', []), '/admin');
        page_('admin-overview');

        const button = $('button[aria-disabled="true"]');

        expect(button.textContent).toContain('+ Create block');
        expect(
            document.getElementById(button.getAttribute('aria-describedby')!)!
                .textContent,
        ).toBe(en['perm-denied']);
    });

    it.each(Object.keys(shellPages))(
        '%s has a title, a list-empty state and no subtitle unless one exists',
        (page) => {
            signedIn(shell('user'), '/x');
            page_(page as keyof typeof shellPages);

            expect($('h1').textContent).toBe(
                shellPages[page as keyof typeof shellPages].title,
            );
            expect($('[data-slot="list-empty"]').textContent).toContain(
                ' yet. ',
            );

            const hasSubtitle = ['overview', 'admin-overview'].includes(page);

            expect(
                document.querySelector('[data-slot="page-subtitle"]') !== null,
            ).toBe(hasSubtitle);
        },
    );
});

describe('generic list states', () => {
    function states(state: 'loading' | 'error' | 'empty') {
        const onRetry = vi.fn();

        wrapper = mount(ListStates, {
            attachTo: document.body,
            props: {
                state,
                items: 'blocks',
                action: 'Create a block',
                onRetry,
            },
            global: { plugins: plugins() },
        });

        return onRetry;
    }

    it('shows five skeleton rows while loading', () => {
        states('loading');

        expect(all('[data-slot="skeleton-row"]')).toHaveLength(5);
        expect($('[role="status"]').getAttribute('aria-busy')).toBe('true');
    });

    it('shows "We couldn\'t load {items}. Try again." with Retry on a failed load', () => {
        const retry = states('error');

        expect($('[role="alert"]').textContent).toContain(
            "We couldn't load blocks. Try again.",
        );

        $('[data-test="retry"]').click();
        expect(retry).toHaveBeenCalledTimes(1);
    });

    it('shows msg:list-empty when empty', () => {
        states('empty');

        expect($('[data-slot="list-empty"]').textContent!.trim()).toBe(
            'No blocks yet. Create a block to start.',
        );
    });
});

describe('active item', () => {
    const items = shell('admin', []).items;

    it('matches an exact path first, then the longest owning path', () => {
        expect(currentItem(items, '/admin/blocks')?.key).toBe(
            'block-management',
        );
        expect(currentItem(items, '/admin/blocks/create')?.key).toBe(
            'create-block',
        );
        expect(currentItem(items, '/admin/blocks/42/edit')?.key).toBe(
            'block-management',
        );
        expect(currentItem(items, '/admin')?.key).toBe('admin-overview');
        expect(currentItem(items, '/admin/unknown')).toBeNull();
        expect(currentItem(items, '/elsewhere')).toBeNull();
    });
});
