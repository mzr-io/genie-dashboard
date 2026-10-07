// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, reactive } from 'vue';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';
import { signInLabels } from '../../resources/js/locales/labels';

const post = vi.fn();
const form = reactive({
    email: '',
    password: '',
    remember: false,
    role: 'user',
    processing: false,
    post,
    reset: vi.fn(),
});

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: defineComponent({
        props: ['href'],
        setup:
            (props, { slots, attrs }) =>
            () =>
                h('a', { ...attrs, href: props.href }, slots.default?.()),
    }),
    useForm: () => form,
}));

const { default: Login } =
    await import('../../resources/js/pages/auth/Login.vue');
const { default: SignInLayout } =
    await import('../../resources/js/layouts/SignInLayout.vue');

let wrapper: VueWrapper | null = null;

function open(props: { canResetPassword?: boolean; status?: string } = {}) {
    setActivePinia(createPinia());
    wrapper = mount(Login, {
        attachTo: document.body,
        props: { canResetPassword: true, ...props },
        global: { plugins: [createCatalogue()], directives: { focus: {} } },
    });

    return wrapper;
}

async function settle(): Promise<void> {
    await nextTick();
    await nextTick();
}

const el = (selector: string) =>
    document.querySelector(selector) as HTMLElement;

function fill(): void {
    Object.assign(form, { email: 'ada@example.test', password: 'secret-pass' });
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    post.mockReset();
    Object.assign(form, {
        email: '',
        password: '',
        remember: false,
        role: 'user',
        processing: false,
    });
});

describe('sign-in form', () => {
    it('shows the eyebrow, title, catalogue subtitle and a "Contact your workspace administrator" line linking to Help & support', () => {
        open();

        expect(document.body.textContent).toContain(signInLabels.eyebrow);
        expect(el('h1').textContent).toBe(signInLabels.title);
        expect(document.body.textContent).toContain(en['signin-subtitle']);
        expect(el('[data-test="help-line"] a').textContent).toContain(
            'Contact your workspace administrator',
        );
        expect(el('[data-test="help-line"] a').getAttribute('href')).toBe(
            '/help',
        );
    });

    it('has a User and Admin role-card radiogroup with a visible radio on each card, User selected', () => {
        open();

        const group = el('[role="radiogroup"]');
        const radios = group.querySelectorAll('[role="radio"]');

        expect(radios).toHaveLength(2);
        expect(radios[0].getAttribute('aria-checked')).toBe('true');
        expect(radios[1].getAttribute('aria-checked')).toBe('false');
        expect(el('[data-test="role-card-user"]').textContent).toContain(
            'Personal workspace',
        );
        expect(el('[data-test="role-card-admin"]').textContent).toContain(
            'System management',
        );
        // A visible radio sits inside each card.
        expect(
            el('[data-test="role-card-admin"] [role="radio"]'),
        ).not.toBeNull();
    });

    it('relabels the single primary button when a card is chosen', async () => {
        open();
        expect(el('[data-test="signin-button"]').textContent).toContain(
            'Sign in as User',
        );

        el('[data-test="role-card-admin"] [role="radio"]').click();
        await settle();

        expect(form.role).toBe('admin');
        expect(el('[data-test="signin-button"]').textContent).toContain(
            'Sign in as Admin',
        );
        expect(document.querySelectorAll('button[type="submit"]')).toHaveLength(
            1,
        );
    });

    it('labels the fields, sets autocomplete, and offers show/hide password, Remember me and Forgot password', async () => {
        open();

        const email = el('input[name="email"]') as HTMLInputElement;
        const password = el('input[name="password"]') as HTMLInputElement;

        expect(
            document.querySelector(`label[for="${email.id}"]`)?.textContent,
        ).toContain(signInLabels.email);
        expect(
            document.querySelector(`label[for="${password.id}"]`)?.textContent,
        ).toContain(signInLabels.password);
        expect(email.getAttribute('autocomplete')).toBe('username');
        expect(password.getAttribute('autocomplete')).toBe('current-password');
        expect(password.type).toBe('password');

        el('button[aria-label="Show password"]').click();
        await settle();
        expect(password.type).toBe('text');

        expect(
            document.querySelector('label[for="remember"]')?.textContent,
        ).toContain(signInLabels.remember);
        expect(el('[data-test="forgot-password"]').getAttribute('href')).toBe(
            '/forgot-password',
        );
        // No SSO.
        expect(document.body.textContent).not.toMatch(/SSO|Single sign-on/i);
    });

    it('hides Forgot password when resets are unavailable', () => {
        open({ canResetPassword: false });

        expect(
            document.querySelector('[data-test="forgot-password"]'),
        ).toBeNull();
    });
});

describe('sign-in submit', () => {
    it('focuses the error summary of links when both fields are invalid and sends nothing', async () => {
        open();

        await wrapper!.find('form').trigger('submit');
        await settle();

        const summary = el('[data-slot="form-error-summary"]');
        expect(post).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(summary);
        expect(summary.querySelectorAll('a').length).toBe(2);
    });

    it('posts to /login when valid', async () => {
        open();
        fill();

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(post).toHaveBeenCalledOnce();
        expect(post.mock.calls[0][0]).toBe('/login');
    });

    it('shows signin-failed from the catalogue in a focused error summary', async () => {
        open();
        fill();
        post.mockImplementation(
            (_url: string, options: { onError: (e: object) => void }) =>
                options.onError({ email: 'signin-failed' }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        const summary = el('[data-slot="form-error-summary"]');
        expect(summary.textContent).toContain(en['signin-failed']);
        expect(document.activeElement).toBe(summary);
    });

    it('shows signin-role-denied and offers the User card', async () => {
        open();
        fill();
        form.role = 'admin';
        post.mockImplementation(
            (_url: string, options: { onError: (e: object) => void }) =>
                options.onError({ role: 'signin-role-denied' }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        const summary = el('[data-slot="form-error-summary"]');
        expect(summary.textContent).toContain(en['signin-role-denied']);
        expect(document.activeElement).toBe(summary);
        expect(form.role).toBe('user');
        expect(
            el('[data-test="role-card-user"] [role="radio"]').getAttribute(
                'aria-checked',
            ),
        ).toBe('true');
        expect(el('[data-test="signin-button"]').textContent).toContain(
            'Sign in as User',
        );
    });

    it('shows throttled on an HTTP 429 and suppresses the default modal', async () => {
        open();
        fill();
        let returned: unknown;
        post.mockImplementation(
            (
                _url: string,
                options: {
                    onHttpException: (r: { status: number }) => unknown;
                },
            ) => {
                returned = options.onHttpException({ status: 429 });
            },
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        const summary = el('[data-slot="form-error-summary"]');
        expect(summary.textContent).toContain(en.throttled);
        expect(document.activeElement).toBe(summary);
        expect(returned).toBe(false);
    });

    it.each([419, 500])(
        'shows a focused generic message on an HTTP %i failure',
        async (status) => {
            open();
            fill();
            post.mockImplementation(
                (
                    _url: string,
                    options: {
                        onHttpException: (r: { status: number }) => unknown;
                    },
                ) => {
                    options.onHttpException({ status });
                },
            );

            await wrapper!.find('form').trigger('submit');
            await settle();

            const alert = el('[data-test="submit-failed"]');
            expect(alert.textContent).toContain(signInLabels.submitFailed);
            expect(document.activeElement).toBe(alert);
        },
    );
});

describe('sign-in layout', () => {
    function layout(): VueWrapper {
        setActivePinia(createPinia());
        wrapper = mount(SignInLayout, {
            attachTo: document.body,
            slots: { default: '<p data-test="slot">form</p>' },
            global: { plugins: [createCatalogue()], directives: { focus: {} } },
        });

        return wrapper;
    }

    it('shows the hero content: logo, descriptor, tagline, headline, two trust lines, illustration and footer', () => {
        layout();

        const hero = el('[data-slot="signin-hero"]');
        const text = hero.textContent ?? '';

        for (const expected of [
            signInLabels.product,
            'Dashboard Management System',
            'One workspace. Every insight.',
            signInLabels.headline,
            'Enterprise-grade security',
            'Live data updates',
            '© 2026 Dashflow. All rights reserved.',
        ]) {
            expect(text).toContain(expected);
        }
        expect(
            el('[data-slot="signin-illustration"]').getAttribute('aria-hidden'),
        ).toBe('true');
    });

    it('splits at 1024 px: the hero is about 55% wide from lg up and hidden below; the logo shows above the form below lg', () => {
        layout();

        const hero = el('[data-slot="signin-hero"]');
        expect(hero.className).toContain('hidden');
        expect(hero.className).toContain('lg:flex');
        expect(hero.className).toContain('lg:w-[55%]');

        const main = el('main#main-content');
        expect(main.className).toContain('justify-center');
        expect(main.querySelector('.lg\\:hidden')).not.toBeNull();
        expect(el('[data-test="slot"]')).not.toBeNull();
    });

    it('lets the form shrink to 320 px: a 16 px gutter, no fixed width', () => {
        layout();

        const main = el('main#main-content');
        expect(main.className).toContain('px-4');
        expect(main.className).toContain('min-w-0');
        expect(main.innerHTML).not.toMatch(/\bw-\[\d+px\]|min-w-\[\d+px\]/);
    });
});

describe('sign-in review fixes', () => {
    it('maps an unknown server message to signin-failed', async () => {
        open();
        fill();
        post.mockImplementation(
            (_url: string, options: { onError: (e: object) => void }) =>
                options.onError({ email: 'SQLSTATE[23000] boom' }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        const text = el('[data-slot="form-error-summary"]').textContent;
        expect(text).toContain(en['signin-failed']);
        expect(text).not.toContain('SQLSTATE');
    });

    it('keeps the typed password when only the role is denied, and links the summary to the first radio', async () => {
        open();
        fill();
        form.role = 'admin';
        post.mockImplementation(
            (
                _url: string,
                options: { onError: (e: object) => void; onFinish: () => void },
            ) => {
                options.onError({ role: 'signin-role-denied' });
                options.onFinish();
            },
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(form.reset).not.toHaveBeenCalled();
        const link = el('[data-slot="form-error-summary"] a');
        link.click();
        expect(document.activeElement).toBe(
            el('[data-test="role-card-user"] [role="radio"]'),
        );
    });

    it('clears the password after another failure', async () => {
        open();
        fill();
        post.mockImplementation(
            (
                _url: string,
                options: { onError: (e: object) => void; onFinish: () => void },
            ) => {
                options.onError({ email: 'signin-failed' });
                options.onFinish();
            },
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(form.reset).toHaveBeenCalledWith('password');
    });

    it('has no heading before the page h1 and names the hero region', () => {
        setActivePinia(createPinia());
        wrapper = mount(SignInLayout, {
            attachTo: document.body,
            slots: { default: '<h1>Title</h1>' },
            global: { plugins: [createCatalogue()], directives: { focus: {} } },
        });

        expect(document.querySelectorAll('h2')).toHaveLength(0);
        expect(el('[data-slot="signin-hero"]').getAttribute('aria-label')).toBe(
            signInLabels.heroIllustration,
        );
    });
});
