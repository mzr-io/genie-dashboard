// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';
import { passwordResetLabels } from '../../resources/js/locales/labels';

const post = vi.fn();
const form = reactive<Record<string, unknown>>({
    email: '',
    token: '',
    password: '',
    password_confirmation: '',
    processing: false,
    post,
    reset: vi.fn(),
});

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: () => form,
}));

const { default: ForgotPassword } =
    await import('../../resources/js/pages/auth/ForgotPassword.vue');
const { default: ResetPassword } =
    await import('../../resources/js/pages/auth/ResetPassword.vue');
const { default: Login } =
    await import('../../resources/js/pages/auth/Login.vue');

let wrapper: VueWrapper | null = null;

function mountPage(
    component: object,
    props: Record<string, unknown> = {},
): VueWrapper {
    wrapper = mount(component, {
        attachTo: document.body,
        props,
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

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    post.mockReset();
    Object.assign(form, {
        email: '',
        token: '',
        password: '',
        password_confirmation: '',
        processing: false,
    });
});

describe('forgot-password page', () => {
    it('has one labelled email field and a Send reset link button', () => {
        mountPage(ForgotPassword);

        const input = el('input[name="email"]') as HTMLInputElement;
        expect(
            document.querySelector(`label[for="${input.id}"]`)?.textContent,
        ).toContain(passwordResetLabels.email);
        expect(
            el('[data-test="email-password-reset-link-button"]').textContent,
        ).toContain('Send reset link');
        expect(el('[data-test="back-to-sign-in"]').getAttribute('href')).toBe(
            '/login',
        );
    });

    it('shows field-error inline on blur for a malformed email and sends nothing', async () => {
        mountPage(ForgotPassword);
        form.email = 'abc';

        el('input[name="email"]').dispatchEvent(new Event('blur'));
        await settle();

        expect(document.body.textContent).toContain(
            'Enter an email address, like name@company.com.',
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(post).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(el('input[name="email"]'));
    });

    it('posts a well-formed email and shows reset-requested in a focused status', async () => {
        mountPage(ForgotPassword);
        form.email = 'ada@example.test';
        post.mockImplementation(
            (_url: string, options: { onSuccess: () => void }) =>
                options.onSuccess(),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(post.mock.calls[0][0]).toBe('/forgot-password');
        const status = el('[data-test="reset-requested"]');
        expect(status.textContent).toContain(en['reset-requested']);
        expect(status.getAttribute('role')).toBe('status');
        expect(document.activeElement).toBe(status);
    });

    it('shows reset-requested from the flashed status', () => {
        mountPage(ForgotPassword, { status: 'reset-requested' });

        expect(el('[data-test="reset-requested"]').textContent).toContain(
            en['reset-requested'],
        );
    });

    it('shows field-error inline and focuses the field when the server rejects the email', async () => {
        mountPage(ForgotPassword);
        form.email = 'ada@example.test';
        post.mockImplementation(
            (_url: string, options: { onError: (e: object) => void }) =>
                options.onError({ email: 'field-error' }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(document.body.textContent).toContain(
            'Enter an email address, like name@company.com.',
        );
        expect(document.activeElement).toBe(el('input[name="email"]'));
    });

    it('shows throttled from the server error key', async () => {
        mountPage(ForgotPassword);
        form.email = 'ada@example.test';
        post.mockImplementation(
            (_url: string, options: { onError: (e: object) => void }) =>
                options.onError({ email: 'throttled' }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(el('[data-test="reset-throttled"]').textContent).toContain(
            en.throttled,
        );
    });

    it('shows the generic failure, not a field error, for an unknown error key', async () => {
        mountPage(ForgotPassword);
        form.email = 'ada@example.test';
        post.mockImplementation(
            (_url: string, options: { onError: (e: object) => void }) =>
                options.onError({ other: 'boom' }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(el('[data-test="submit-failed"]')).not.toBeNull();
        expect(document.activeElement).toBe(el('[data-test="submit-failed"]'));
        expect(document.body.textContent).not.toContain(
            'Enter an email address',
        );
    });

    it('shows throttled from the catalogue on HTTP 429', async () => {
        mountPage(ForgotPassword);
        form.email = 'ada@example.test';
        post.mockImplementation(
            (
                _url: string,
                options: { onHttpException: (r: { status: number }) => void },
            ) => options.onHttpException({ status: 429 }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(el('[data-test="reset-throttled"]').textContent).toContain(
            en.throttled,
        );
        expect(document.activeElement).toBe(
            el('[data-test="reset-throttled"]'),
        );
    });
});

describe('reset-password page', () => {
    const link = {
        token: 'T'.repeat(43),
        email: 'ada@example.test',
        passwordRules: 'minlength: 8;',
    };

    it('renders new and confirm password fields with new-password autocomplete', () => {
        mountPage(ResetPassword, link);

        for (const name of ['password', 'password_confirmation']) {
            const input = el(`input[name="${name}"]`) as HTMLInputElement;
            expect(input.getAttribute('autocomplete')).toBe('new-password');
            expect(
                document.querySelector(`label[for="${input.id}"]`),
            ).not.toBeNull();
        }
    });

    it('focuses the summary when both fields are invalid and sends nothing', async () => {
        mountPage(ResetPassword, link);
        form.password_confirmation = 'something-else';

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(post).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(
            el('[data-slot="form-error-summary"]'),
        );
    });

    it('posts to /reset-password and keeps the form for a weak password, focusing the field', async () => {
        mountPage(ResetPassword, link);
        form.password = 'short';
        form.password_confirmation = 'short';
        post.mockImplementation(
            (_url: string, options: { onError: (e: object) => void }) =>
                options.onError({ password: 'The password is too short.' }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(post.mock.calls[0][0]).toBe('/reset-password');
        expect(document.body.textContent).toContain(
            'The password is too short.',
        );
        expect(document.activeElement).toBe(el('input[name="password"]'));
        expect(
            document.querySelector('[data-test="reset-expired"]'),
        ).toBeNull();
    });

    it('shows reset-expired with a link to request a new one when the link is expired', () => {
        mountPage(ResetPassword, {
            token: null,
            email: null,
            expired: true,
            passwordRules: 'minlength: 8;',
        });

        expect(el('[data-test="reset-expired"]').textContent).toContain(
            en['reset-expired'],
        );
        expect(el('[data-test="request-new-link"]').getAttribute('href')).toBe(
            '/forgot-password',
        );
        expect(document.querySelector('input[name="password"]')).toBeNull();
    });

    it('shows the generic failure for an error naming none of the known fields', async () => {
        mountPage(ResetPassword, link);
        form.password = 'a-long-new-password';
        form.password_confirmation = 'a-long-new-password';
        post.mockImplementation(
            (_url: string, options: { onError: (e: object) => void }) =>
                options.onError({ token: 'boom' }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(el('[data-test="submit-failed"]')).not.toBeNull();
        expect(document.body.textContent).not.toContain(
            'Enter a new password.',
        );
        expect(document.activeElement).toBe(el('[data-test="submit-failed"]'));
    });

    it('re-seeds the form and the expired state when the props change', async () => {
        const page = mountPage(ResetPassword, link);

        await page.setProps({ token: null, email: null, expired: true });
        await settle();
        expect(el('[data-test="reset-expired"]')).not.toBeNull();
        expect(form.token).toBe('');

        await page.setProps({
            token: 'N'.repeat(43),
            email: 'bob@example.test',
            expired: false,
        });
        await settle();
        expect(
            document.querySelector('[data-test="reset-expired"]'),
        ).toBeNull();
        expect(form.token).toBe('N'.repeat(43));
        expect(form.email).toBe('bob@example.test');
    });

    it('switches to the expired state when the server answers reset-expired', async () => {
        mountPage(ResetPassword, link);
        form.password = 'a-long-new-password';
        form.password_confirmation = 'a-long-new-password';
        post.mockImplementation(
            (_url: string, options: { onError: (e: object) => void }) =>
                options.onError({ email: 'reset-expired' }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(el('[data-test="reset-expired"]').textContent).toContain(
            en['reset-expired'],
        );
        expect(document.activeElement).toBe(el('[data-test="reset-expired"]'));
    });
});

describe('sign-in page after a reset', () => {
    it('shows password-changed from the catalogue', () => {
        mountPage(Login, {
            canResetPassword: true,
            status: 'password-changed',
        });

        expect(el('[data-test="signin-status"]').textContent).toContain(
            en['password-changed'],
        );
    });

    it('shows nothing for any other status string', () => {
        mountPage(Login, { canResetPassword: true, status: 'whatever' });

        expect(
            document.querySelector('[data-test="signin-status"]'),
        ).toBeNull();
    });
});
