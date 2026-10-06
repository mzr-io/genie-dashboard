// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import { createCatalogue } from '../../resources/js/lib/i18n';
import en from '../../resources/js/locales/en';
import { invitationLabels } from '../../resources/js/locales/labels';

const post = vi.fn();
const form = reactive({
    email: '',
    name: '',
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

const { default: AcceptInvitation } =
    await import('../../resources/js/pages/auth/AcceptInvitation.vue');
const { default: InvitationExpired } =
    await import('../../resources/js/pages/auth/InvitationExpired.vue');

let wrapper: VueWrapper | null = null;

function open(): VueWrapper {
    wrapper = mount(AcceptInvitation, {
        attachTo: document.body,
        props: { token: 'T'.repeat(43), passwordRules: 'minlength: 8;' },
        global: { plugins: [createCatalogue()], directives: { focus: {} } },
    });

    return wrapper;
}

async function settle(): Promise<void> {
    await nextTick();
    await nextTick();
}

function input(name: string): HTMLInputElement {
    return document.querySelector(`input[name="${name}"]`) as HTMLInputElement;
}

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    document.body.innerHTML = '';
    post.mockReset();
    Object.assign(form, {
        email: '',
        name: '',
        password: '',
        password_confirmation: '',
    });
});

describe('accept-invitation page', () => {
    it('renders four labelled required fields with autocomplete attributes', () => {
        open();

        for (const [name, label, autocomplete] of [
            ['name', invitationLabels.name, 'name'],
            ['email', invitationLabels.email, 'email'],
            ['password', invitationLabels.password, 'new-password'],
            [
                'password_confirmation',
                invitationLabels.passwordConfirmation,
                'new-password',
            ],
        ]) {
            const el = input(name);
            const forLabel = document.querySelector(`label[for="${el.id}"]`);

            expect(forLabel?.textContent).toContain(label);
            expect(el.required).toBe(true);
            expect(el.getAttribute('autocomplete')).toBe(autocomplete);
        }
    });

    it('validates on blur, never on input, with the field-error copy for a bad email', async () => {
        open();
        const email = input('email');

        email.value = 'nope';
        email.dispatchEvent(new Event('input'));
        await settle();
        expect(document.querySelector('[data-slot="field-error"]')).toBeNull();

        email.dispatchEvent(new Event('blur'));
        await settle();

        const error = document.querySelector('[data-slot="field-error"]');
        expect(error?.textContent).toContain(
            en['field-error'].replace("{'@'}", '@'),
        );
        expect(email.getAttribute('aria-invalid')).toBe('true');
    });

    it('focuses the only invalid field on submit and sends nothing', async () => {
        open();
        Object.assign(form, {
            name: 'Ada',
            email: 'ada@example.test',
            password: 'a-long-enough-password',
            password_confirmation: 'different',
        });

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(post).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(input('password_confirmation'));
        expect(
            document.querySelector('[data-slot="form-error-summary"]'),
        ).toBeNull();
    });

    it('focuses an error summary of links when two or more fields are invalid, and each link focuses its field', async () => {
        open();

        await wrapper!.find('form').trigger('submit');
        await settle();

        const summary = document.querySelector(
            '[data-slot="form-error-summary"]',
        ) as HTMLElement;

        expect(post).not.toHaveBeenCalled();
        expect(document.activeElement).toBe(summary);

        const links = summary.querySelectorAll('a');
        expect(links.length).toBeGreaterThanOrEqual(2);

        (links[0] as HTMLElement).click();
        expect(document.activeElement).toBe(input('name'));
    });

    it('posts to the token URL when everything is valid', async () => {
        open();
        Object.assign(form, {
            name: 'Ada',
            email: 'ada@example.test',
            password: 'a-long-enough-password',
            password_confirmation: 'a-long-enough-password',
        });

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(post).toHaveBeenCalledOnce();
        expect(post.mock.calls[0][0]).toBe(`/invitations/${'T'.repeat(43)}`);
    });

    it('shows server password errors in the field-error pattern and focuses the field', async () => {
        open();
        Object.assign(form, {
            name: 'Ada',
            email: 'ada@example.test',
            password: 'short-pw',
            password_confirmation: 'short-pw',
        });
        post.mockImplementation(
            (_url: string, options: { onError: (e: object) => void }) =>
                options.onError({
                    password:
                        'The password field must be at least 8 characters.',
                }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        const error = document.querySelector('[data-slot="field-error"]');
        expect(error?.textContent).toContain('at least 8 characters');
        expect(document.activeElement).toBe(input('password'));
    });
});

describe('accept-invitation failures', () => {
    const fill = () =>
        Object.assign(form, {
            name: 'Ada',
            email: 'ada@example.test',
            password: 'a-long-enough-password',
            password_confirmation: 'a-long-enough-password',
        });

    it('falls back to the field-error copy on the email field, focused, when the error names no known field', async () => {
        open();
        fill();
        post.mockImplementation(
            (_url: string, options: { onError: (e: object) => void }) =>
                options.onError({ token: 'unexpected' }),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        expect(
            document.querySelector('[data-slot="field-error"]')?.textContent,
        ).toContain(en['field-error'].replace("{'@'}", '@'));
        expect(document.activeElement).toBe(input('email'));
    });

    it('shows a focused generic message on a network error', async () => {
        open();
        fill();
        post.mockImplementation(
            (_url: string, options: { onNetworkError: () => void }) =>
                options.onNetworkError(),
        );

        await wrapper!.find('form').trigger('submit');
        await settle();

        const alert = document.querySelector('[data-test="submit-failed"]');
        expect(alert?.textContent).toContain(invitationLabels.submitFailed);
        expect(document.activeElement).toBe(alert);
    });

    it.each([419, 429, 500])(
        'shows the generic message on an HTTP %i failure and suppresses the default modal',
        async (status) => {
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
                    returned = options.onHttpException({ status });
                },
            );

            await wrapper!.find('form').trigger('submit');
            await settle();

            expect(
                document.querySelector('[data-test="submit-failed"]'),
            ).not.toBeNull();
            expect(returned).toBe(false);
        },
    );
});

describe('invitation-expired page', () => {
    it('shows only the neutral catalogue copy', () => {
        wrapper = mount(InvitationExpired, {
            global: { plugins: [createCatalogue()] },
        });

        expect(wrapper.get('[data-test="invitation-expired"]').text()).toBe(
            en['reset-expired'],
        );
    });
});
