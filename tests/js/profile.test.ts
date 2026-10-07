// @vitest-environment happy-dom
import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';

type Call = {
    method: 'post' | 'put';
    url: string;
    options: Record<string, (...args: unknown[]) => unknown>;
    data: Record<string, unknown>;
};

const calls: Call[] = [];
const forms: Record<string, unknown>[] = [];
const user = reactive<Record<string, unknown>>({
    name: 'Ada Lovelace',
    email: 'ada@example.test',
    avatar: null,
    locale: 'en',
    timezone: 'UTC',
    keyboard_shortcuts: true,
});

function makeForm(initial: Record<string, unknown>): Record<string, unknown> {
    let defaults = { ...initial };
    let transformer: (
        data: Record<string, unknown>,
    ) => Record<string, unknown> = (data) => data;
    const fields = Object.keys(initial);
    const form: Record<string, unknown> = reactive({
        ...initial,
        errors: {},
        processing: false,
        get isDirty() {
            return fields.some((key) => form[key] !== defaults[key]);
        },
        transform(fn: typeof transformer) {
            transformer = fn;

            return form;
        },
        defaults() {
            defaults = Object.fromEntries(fields.map((k) => [k, form[k]]));
        },
        reset() {
            fields.forEach((key) => {
                form[key] = defaults[key];
            });
        },
        clearErrors() {
            form.errors = {};
        },
        post(url: string, options: Call['options']) {
            send('post', url, options);
        },
        put(url: string, options: Call['options']) {
            send('put', url, options);
        },
    });

    function send(
        method: Call['method'],
        url: string,
        options: Call['options'],
    ) {
        form.processing = true;
        calls.push({
            method,
            url,
            options,
            data: transformer(
                Object.fromEntries(fields.map((k) => [k, form[k]])),
            ),
        });
    }

    forms.push(form);

    return form;
}

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user } } }),
    Head: { render: () => null },
    useForm: (initial: Record<string, unknown>) => makeForm(initial),
}));

const { default: Profile } =
    await import('../../resources/js/pages/settings/Profile.vue');
const { createCatalogue } = await import('../../resources/js/lib/i18n');
const { configureFormatting } = await import('../../resources/js/lib/format');
const { resetAnnouncer } = await import('../../resources/js/lib/announce');
const { clearUnsavedForms, hasUnsavedWork, saveUnsavedForms } =
    await import('../../resources/js/lib/unsavedForms');
const { profileLabels } = await import('../../resources/js/locales/labels');
const { default: en } = await import('../../resources/js/locales/en');

let wrapper: VueWrapper | null = null;

function mountProfile(props: Record<string, unknown> = {}): VueWrapper {
    wrapper = mount(Profile, {
        attachTo: document.body,
        props: {
            locales: ['en'],
            timezones: ['UTC', 'Asia/Dhaka', 'Europe/London'],
            passwordRules: 'minlength: 8;',
            avatarMaxBytes: 1000,
            ...props,
        },
        global: { plugins: [createCatalogue()], directives: { focus: {} } },
    });

    return wrapper;
}

const el = <T extends HTMLElement = HTMLElement>(selector: string) =>
    document.querySelector(selector) as T;

async function settle(): Promise<void> {
    await nextTick();
    await nextTick();
    await new Promise((resolve) => setTimeout(resolve, 5));
}

// What Inertia does when a request ends: the form stops processing, then `onFinish` runs.
async function finish(
    call: Call,
    form: Record<string, unknown>,
): Promise<void> {
    form.processing = false;
    await call.options.onFinish();
}

async function submit(selector: string): Promise<void> {
    el<HTMLFormElement>(selector).dispatchEvent(
        new Event('submit', { cancelable: true }),
    );
    await settle();
}

beforeEach(() => {
    calls.length = 0;
    forms.length = 0;
    clearUnsavedForms();
    configureFormatting({ locale: 'en', timeZone: 'UTC' });
    Object.assign(user, {
        name: 'Ada Lovelace',
        avatar: null,
        locale: 'en',
        timezone: 'UTC',
        keyboard_shortcuts: true,
    });
});

afterEach(() => {
    wrapper?.unmount();
    wrapper = null;
    resetAnnouncer();
    clearUnsavedForms();
    document.body.innerHTML = '';
});

describe('Profile & settings page', () => {
    it('shows name, avatar, locale, time zone, Change password and the shortcuts switch', () => {
        mountProfile();

        expect(el<HTMLInputElement>('input[name="name"]').value).toBe(
            'Ada Lovelace',
        );
        expect(el('input[type="file"][name="avatar"]')).not.toBeNull();
        expect(el('select[name="locale"]')).not.toBeNull();
        expect(
            el('select[name="timezone"]').querySelectorAll('option'),
        ).toHaveLength(3);
        expect(el('[role="switch"]').getAttribute('aria-checked')).toBe('true');
        expect(el('[data-test="password-form"]')).not.toBeNull();
        expect(document.body.textContent).toContain(
            profileLabels.passwordHeading,
        );
    });

    it('renders no theme or Appearance control (UX-DR-286)', () => {
        mountProfile();

        expect(document.body.textContent).not.toMatch(
            /appearance|theme|dark mode/i,
        );
        expect(
            document.querySelector(
                '[aria-label*="theme" i], [name*="theme" i]',
            ),
        ).toBeNull();
    });

    it('labels every field and lists the single-key shortcuts', () => {
        mountProfile();

        for (const input of document.querySelectorAll<HTMLElement>(
            'input:not([type="hidden"]), select, [role="switch"]',
        )) {
            if (input.closest('[data-slot="password-input"]')) {
                continue;
            }

            expect(
                document.querySelector(`label[for="${input.id}"]`),
                input.outerHTML,
            ).not.toBeNull();
        }

        expect(el('form[data-test="profile-form"]').textContent).toContain(
            profileLabels.shortcutList[0].does,
        );
    });

    it('saves: announces `saved` politely, keeps focus on Save and applies the new locale and time zone', async () => {
        mountProfile();
        (forms[0] as { timezone: string }).timezone = 'Asia/Dhaka';
        await nextTick();

        expect(el('[data-test="preview-date"]').textContent).toContain('2:30');
        await submit('[data-test="profile-form"]');

        expect(calls).toHaveLength(1);
        expect(calls[0].method).toBe('post');
        expect(calls[0].data).toMatchObject({
            _method: 'patch',
            name: 'Ada Lovelace',
            timezone: 'Asia/Dhaka',
            keyboard_shortcuts: true,
        });

        // The server saved it and the shared props now carry the new profile.
        user.timezone = 'Asia/Dhaka';
        configureFormatting({ locale: 'en', timeZone: 'Asia/Dhaka' });
        (forms[0] as { processing: boolean }).processing = false;
        await calls[0].options.onSuccess();
        await calls[0].options.onFinish();
        await settle();

        expect(el('[data-announcer="polite"]').textContent).toBe(en.saved);
        expect(el('[data-test="saved"]').textContent).toContain(en.saved);
        expect(document.activeElement).toBe(
            el('[data-test="update-profile-button"]'),
        );
        expect(el('[data-test="preview-date"]').textContent).toContain('8:30');
    });

    it('shows a field error from the server and moves focus to the field', async () => {
        mountProfile();
        await submit('[data-test="profile-form"]');

        await calls[0].options.onError({ name: 'The name field is required.' });
        await settle();

        expect(el('[data-slot="field-error"]').textContent).toContain(
            'The name field is required.',
        );
        expect(document.activeElement).toBe(el('input[name="name"]'));
        expect(el('[data-test="saved"]')).toBeNull();
    });

    it('shows the form-wording save-failed message, keeps the values and moves focus to the message', async () => {
        mountProfile();
        const profile = forms[0] as { name: string };
        profile.name = 'Changed name';
        await submit('[data-test="profile-form"]');

        const handled = calls[0].options.onHttpException({ status: 500 });
        await settle();

        expect(handled).toBe(false);
        const message = el('[data-test="save-failed"]');
        expect(message.textContent).toBe(en['save-failed'].form);
        expect(message.getAttribute('role')).toBe('alert');
        expect(document.activeElement).toBe(message);
        expect(profile.name).toBe('Changed name');
        expect(el<HTMLInputElement>('input[name="name"]').value).toBe(
            'Changed name',
        );
    });

    it('shows save-failed on a network error and for an error on no visible field', async () => {
        mountProfile();
        await submit('[data-test="profile-form"]');

        await calls[0].options.onNetworkError();
        await settle();
        expect(el('[data-test="save-failed"]')).not.toBeNull();

        await submit('[data-test="profile-form"]');
        await calls[1].options.onError({
            locale: 'The selected locale is invalid.',
        });
        await settle();
        expect(el('[data-test="save-failed"]')).not.toBeNull();
    });

    it('refuses a wrong avatar type or an oversize one before sending, with a field error', async () => {
        mountProfile();
        const input = el<HTMLInputElement>('input[type="file"]');

        for (const [file, message] of [
            [
                new File(['<svg/>'], 'a.svg', { type: 'image/svg+xml' }),
                profileLabels.avatarWrongType,
            ],
            [
                new File([new Uint8Array(2000)], 'a.png', {
                    type: 'image/png',
                }),
                profileLabels.avatarTooLarge('2 KB'),
            ],
        ] as const) {
            Object.defineProperty(input, 'files', {
                value: [file],
                configurable: true,
            });
            input.dispatchEvent(new Event('change'));
            await settle();

            expect(el('[data-slot="field-error"]').textContent).toContain(
                message.replace('2 KB', '1 KB'),
            );
            await submit('[data-test="profile-form"]');
            expect(calls).toHaveLength(0);
        }
    });

    it('accepts a PNG, JPEG or WebP under the limit and sends it as multipart', async () => {
        mountProfile();
        const input = el<HTMLInputElement>('input[type="file"]');
        const file = new File([new Uint8Array(10)], 'a.webp', {
            type: 'image/webp',
        });
        Object.defineProperty(input, 'files', {
            value: [file],
            configurable: true,
        });
        input.dispatchEvent(new Event('change'));
        await submit('[data-test="profile-form"]');

        expect(calls).toHaveLength(1);
        expect(calls[0].options.forceFormData).toBe(true);
        expect((calls[0].data.avatar as File).name).toBe('a.webp');
    });

    it('sends the switch as 0 or 1 through the form data', async () => {
        mountProfile();
        el('[role="switch"]').click();
        await submit('[data-test="profile-form"]');

        expect(calls[0].data.keyboard_shortcuts).toBe(false);
    });

    it('falls back to a zone the list offers when the saved and browser zones are not in it', () => {
        const spy = vi
            .spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions')
            .mockReturnValue({
                timeZone: 'America/Nowhere',
            } as Intl.ResolvedDateTimeFormatOptions);
        user.timezone = 'Mars/Olympus';
        configureFormatting({ locale: 'en', timeZone: null });

        mountProfile();
        expect(el<HTMLSelectElement>('select[name="timezone"]').value).toBe(
            'UTC',
        );

        wrapper!.unmount();
        document.body.innerHTML = '';
        mountProfile({ timezones: ['Asia/Dhaka', 'Europe/London'] });
        expect(el<HTMLSelectElement>('select[name="timezone"]').value).toBe(
            'Asia/Dhaka',
        );

        wrapper!.unmount();
        document.body.innerHTML = '';
        spy.mockReturnValue({
            timeZone: 'Europe/London',
        } as Intl.ResolvedDateTimeFormatOptions);
        mountProfile();
        expect(el<HTMLSelectElement>('select[name="timezone"]').value).toBe(
            'Europe/London',
        );
        spy.mockRestore();
    });

    it('shows the too-large message on the avatar for a 413 (body over post_max_size)', async () => {
        mountProfile();
        await submit('[data-test="profile-form"]');

        const handled = calls[0].options.onHttpException({ status: 413 });
        await settle();

        expect(handled).toBe(false);
        expect(el('[data-slot="field-error"]').textContent).toContain(
            profileLabels.avatarTooLarge('1 KB'),
        );
        expect(el('[data-test="save-failed"]')).toBeNull();
        expect(document.activeElement).toBe(el('input[type="file"]'));
    });

    it('shows the current avatar when there is one', () => {
        user.avatar = '/avatars/1?v=abc';
        mountProfile();

        expect(el('[data-test="avatar-current"]')).not.toBeNull();
    });
});

describe('Change password section', () => {
    it('requires every field and a matching confirmation before sending', async () => {
        mountProfile();
        await submit('[data-test="password-form"]');

        expect(calls).toHaveLength(0);
        expect(
            el('[data-test="password-form"] [data-slot="form-error-summary"]'),
        ).not.toBeNull();

        const password = forms[1] as Record<string, string>;
        password.current_password = 'old';
        password.password = 'a-new-password-1';
        password.password_confirmation = 'different';
        await submit('[data-test="password-form"]');

        expect(calls).toHaveLength(0);
        expect(el('[data-test="password-form"]').textContent).toContain(
            profileLabels.passwordMismatch,
        );
    });

    it('shows a wrong current password as a field error, clears the secrets and focuses the field', async () => {
        mountProfile();
        const password = forms[1] as Record<string, string>;
        Object.assign(password, {
            current_password: 'wrong',
            password: 'a-new-password-1',
            password_confirmation: 'a-new-password-1',
        });
        await submit('[data-test="password-form"]');

        expect(calls).toHaveLength(1);
        expect(calls[0].method).toBe('put');
        expect(calls[0].url).toBe('/settings/password');

        await calls[0].options.onError({
            current_password: 'The password is incorrect.',
        });
        await settle();

        expect(
            el('[data-test="password-form"] [data-slot="field-error"]')
                .textContent,
        ).toContain('The password is incorrect.');
        expect(document.activeElement).toBe(
            el('input[name="current_password"]'),
        );
        expect(password.current_password).toBe('');
        expect(password.password).toBe('');
    });

    it('announces the change politely and keeps focus on its button', async () => {
        mountProfile();
        const password = forms[1] as Record<string, string>;
        Object.assign(password, {
            current_password: 'right',
            password: 'a-new-password-1',
            password_confirmation: 'a-new-password-1',
        });
        await submit('[data-test="password-form"]');

        (password as unknown as { processing: boolean }).processing = false;
        await calls[0].options.onSuccess();
        await settle();

        expect(el('[data-announcer="polite"]').textContent).toBe(
            profileLabels.passwordChanged,
        );
        expect(document.activeElement).toBe(
            el('[data-test="update-password-button"]'),
        );
        expect(password.password).toBe('');
    });

    it('never registers as unsaved work, so a secret is never kept for a switch', () => {
        mountProfile();
        (forms[1] as Record<string, string>).current_password = 'typed';

        expect(hasUnsavedWork()).toBe(false);
    });
});

describe('Profile unsaved work', () => {
    it('registers, reports dirty state and unregisters on unmount', () => {
        mountProfile();

        expect(hasUnsavedWork()).toBe(false);
        (forms[0] as { name: string }).name = 'Edited';
        expect(hasUnsavedWork()).toBe(true);

        wrapper!.unmount();
        wrapper = null;
        expect(hasUnsavedWork()).toBe(false);
    });

    it('resolves true when the save succeeds', async () => {
        mountProfile();
        (forms[0] as { name: string }).name = 'Edited';

        const saved = saveUnsavedForms();
        await settle();
        expect(calls).toHaveLength(1);

        await calls[0].options.onSuccess();
        await finish(calls[0], forms[0]);

        expect(await saved).toBe(true);
    });

    it('resolves false when the save errors, is refused by validation or is interrupted', async () => {
        mountProfile();
        const profile = forms[0] as { name: string };
        profile.name = 'Edited';

        const errored = saveUnsavedForms();
        await settle();
        await calls[0].options.onError({ name: 'Bad.' });
        await finish(calls[0], profile as Record<string, unknown>);
        expect(await errored).toBe(false);

        profile.name = '';
        const refused = saveUnsavedForms();
        await settle();
        expect(await refused).toBe(false);
        expect(calls).toHaveLength(1);

        profile.name = 'Again';
        const interrupted = saveUnsavedForms();
        await settle();
        await finish(calls[1], profile as Record<string, unknown>);
        expect(await interrupted).toBe(false);
    });

    it('settles a pending save as false when unmounted', async () => {
        mountProfile();
        (forms[0] as { name: string }).name = 'Edited';

        const pending = saveUnsavedForms();
        await settle();
        wrapper!.unmount();
        wrapper = null;

        expect(await pending).toBe(false);
    });
});
