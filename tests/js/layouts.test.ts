// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick } from 'vue';

const handlers: Record<string, (event: Event) => void> = {};

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (name: string, handler: (event: Event) => void) => {
            handlers[name] = handler;

            // The real router returns the function that removes the listener.
            return () => {};
        },
    },
    usePage: () => ({ props: { sidebarOpen: true, auth: { user: null } } }),
    Link: defineComponent({
        setup:
            (_, { slots, attrs }) =>
            () =>
                h('a', attrs, slots.default?.()),
    }),
    Head: defineComponent({ setup: () => () => null }),
}));

import AppHeaderLayout from '../../resources/js/layouts/app/AppHeaderLayout.vue';
import AppSidebarLayout from '../../resources/js/layouts/app/AppSidebarLayout.vue';
import AuthLayout from '../../resources/js/layouts/AuthLayout.vue';
import ToastRegion from '../../resources/js/components/ToastRegion.vue';
import { resetAnnouncer } from '../../resources/js/lib/announce';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';
import { initializeFlashToast } from '../../resources/js/lib/flashToast';
import { useToasts } from '../../resources/js/stores/toasts';

let wrapper: VueWrapper | null = null;

const stub = defineComponent({ setup: () => () => h('div', 'stub') });

function plugins() {
    const pinia = createPinia();

    setActivePinia(pinia);

    return [pinia, createCatalogue()];
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    resetAnnouncer();
    document.body.innerHTML = '';
});

function expectLandmarks(): void {
    const first = document.querySelector<HTMLElement>(
        'a[href], button, input, [tabindex="0"]',
    )!;

    expect(first.textContent).toContain('Skip to content');
    expect(document.getElementById('main-content')).not.toBeNull();
    expect(
        document
            .querySelector('[data-slot="toast-region"]')
            ?.getAttribute('aria-label'),
    ).toBe('Notifications');
    expect(document.querySelectorAll('[data-announcer]')).toHaveLength(3);
}

// The session watcher in the layouts polls on mount; no server answers in these tests.
beforeEach(() => {
    vi.stubGlobal('fetch', () => Promise.reject(new Error('offline')));
});

describe('layout landmarks', () => {
    it('AppSidebarLayout', () => {
        wrapper = mount(AppSidebarLayout, {
            attachTo: document.body,
            global: {
                plugins: plugins(),
                stubs: { AppSidebar: stub, AppSidebarHeader: stub },
            },
        });
        expectLandmarks();
    });

    it('AppHeaderLayout', () => {
        wrapper = mount(AppHeaderLayout, {
            attachTo: document.body,
            global: { plugins: plugins(), stubs: { AppHeader: stub } },
        });
        expectLandmarks();
    });

    it('AuthLayout', () => {
        wrapper = mount(AuthLayout, {
            attachTo: document.body,
            props: { title: 'Log in' },
            global: { plugins: plugins() },
        });
        expectLandmarks();
    });
});

describe('session watcher in the layouts', () => {
    it.each([
        [
            'AppSidebarLayout',
            AppSidebarLayout,
            { AppSidebar: stub, AppSidebarHeader: stub },
        ],
        ['AppHeaderLayout', AppHeaderLayout, { AppHeader: stub }],
    ])(
        '%s polls with X-Background: 1 and shows the warning',
        async (_name, layout, stubs) => {
            const calls: Array<{
                url: string;
                headers: Record<string, string>;
            }> = [];

            vi.stubGlobal(
                'fetch',
                async (
                    url: string,
                    init: { headers: Record<string, string> },
                ) => {
                    calls.push({ url, headers: init.headers });

                    return new Response(
                        JSON.stringify({
                            remaining_seconds: 100,
                            area: 'user',
                        }),
                        { status: 200 },
                    );
                },
            );

            wrapper = mount(layout, {
                attachTo: document.body,
                global: { plugins: plugins(), stubs },
            });

            for (let i = 0; i < 10; i += 1) {
                await nextTick();
                await new Promise((resolve) => setTimeout(resolve, 5));
            }

            expect(calls[0].url).toBe('/api/v1/session');
            expect(calls[0].headers['X-Background']).toBe('1');
            expect(
                document.querySelector('[data-session-warning]'),
            ).not.toBeNull();
        },
    );
});

describe('flash toasts', () => {
    it('shows msg:session-expired after signing back in', async () => {
        wrapper = mount(ToastRegion, {
            attachTo: document.body,
            global: { plugins: plugins() },
        });
        initializeFlashToast();

        handlers.flash!(
            new CustomEvent('flash', {
                detail: { flash: { session_expired: true } },
            }),
        );
        await nextTick();

        expect(useToasts().items.map((t) => t.message)).toEqual([
            en['session-expired'],
        ]);
    });

    it('adds a toast of each type to the Notifications region', async () => {
        wrapper = mount(ToastRegion, {
            attachTo: document.body,
            global: { plugins: plugins() },
        });
        initializeFlashToast();

        for (const type of ['success', 'info', 'warning', 'error']) {
            handlers.flash!(
                new CustomEvent('flash', {
                    detail: {
                        flash: { toast: { type, message: `m-${type}` } },
                    },
                }),
            );
        }

        handlers.flash!(new CustomEvent('flash', { detail: { flash: {} } }));
        await nextTick();

        const store = useToasts();

        expect(store.items.map((t) => t.kind)).toEqual([
            'success',
            'info',
            'warning',
            'error',
        ]);
        expect(
            [...document.querySelectorAll('[data-slot="toast"]')].map((e) =>
                e.getAttribute('data-kind'),
            ),
        ).toEqual(['success', 'info', 'warning', 'error']);
        expect(
            document.querySelector('[aria-label="Notifications"]')!.textContent,
        ).toContain('m-error');
    });
});
