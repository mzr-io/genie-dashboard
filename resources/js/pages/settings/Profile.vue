<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import FormField from '@/components/FormField.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PageHeader from '@/components/PageHeader.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import RequiredNote from '@/components/RequiredNote.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { useBlurValidation } from '@/composables/useBlurValidation';
import { useInitials } from '@/composables/useInitials';
import { announce } from '@/lib/announce';
import {
    currentFormatting,
    formatCurrency,
    formatDateTime,
    formatNumber,
} from '@/lib/format';
import { registerUnsavedForm } from '@/lib/unsavedForms';
import { profileLabels as labels, shellPages } from '@/locales/labels';
import { edit } from '@/routes/profile';

// Profile & settings (Story 1.18): name, avatar, locale, time zone, the Keyboard shortcuts switch and the
// Change password section. There is no theme control: the light theme is the only one (UX-DR-286).
const props = defineProps<{
    locales: string[];
    timezones: string[];
    passwordRules: string;
    avatarMaxBytes?: number | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: shellPages.profile.title, href: edit() }],
    },
});

const { t } = useI18n();
const page = usePage();
const user = computed(() => page.props.auth.user);
const { getInitials } = useInitials();

const AVATAR_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

// ---------------------------------------------------------------- profile form

// The saved zone, else the browser's, else UTC: always one the list offers, so the select is never blank.
function initialTimeZone(): string {
    const offered = new Set(props.timezones);
    const choices = [
        user.value.timezone,
        currentFormatting().timeZone,
        Intl.DateTimeFormat().resolvedOptions().timeZone,
    ];

    return (
        choices.find((zone): zone is string => !!zone && offered.has(zone)) ??
        (offered.has('UTC') ? 'UTC' : (props.timezones[0] ?? 'UTC'))
    );
}

const profile = useForm({
    name: user.value.name,
    locale: user.value.locale ?? props.locales[0] ?? 'en',
    timezone: initialTimeZone(),
    keyboard_shortcuts: user.value.keyboard_shortcuts !== false,
    avatar: null as File | null,
});

const avatarInput = ref<HTMLInputElement | null>(null);
const saveButton = ref<InstanceType<typeof Button> | null>(null);
const failed = ref(false);
const saved = ref(false);
const failureRef = ref<HTMLElement | null>(null);
const summaryRef = ref<InstanceType<typeof FormErrorSummary> | null>(null);

function formatLimit(bytes: number): string {
    return bytes >= 1024 ** 2
        ? `${formatNumber(Math.round((bytes / 1024 ** 2) * 10) / 10)} MB`
        : `${formatNumber(Math.max(1, Math.round(bytes / 1024)))} KB`;
}

const validation = useBlurValidation({
    name: {
        label: labels.name,
        value: () => profile.name,
        validate: (v) => (String(v).trim() === '' ? labels.nameRequired : null),
    },
    avatar: {
        label: labels.avatar,
        value: () => profile.avatar,
        validate: (v) => {
            const file = v as File | null;

            if (!file) {
                return null;
            }

            if (!AVATAR_TYPES.includes(file.type)) {
                return labels.avatarWrongType;
            }

            return props.avatarMaxBytes && file.size > props.avatarMaxBytes
                ? labels.avatarTooLarge(formatLimit(props.avatarMaxBytes))
                : null;
        },
    },
});

function bindSummary(el: unknown): void {
    summaryRef.value = el as InstanceType<typeof FormErrorSummary> | null;
    validation.summary.value = summaryRef.value;
}

function chooseAvatar(event: Event): void {
    const input = event.target as HTMLInputElement;

    profile.avatar = input.files?.[0] ?? null;
    saved.value = false;
    validation.onBlur('avatar');
}

function focusSave(): void {
    const el = saveButton.value?.$el as HTMLElement | undefined;

    el?.focus();
}

async function showFailure(): Promise<void> {
    failed.value = true;
    saved.value = false;
    await nextTick();
    failureRef.value?.focus();
}

async function showServerErrors(errors: Record<string, string>): Promise<void> {
    failed.value = false;

    const names = ['name', 'avatar'].filter((name) => errors[name]);

    // An error on none of the visible fields is not something the person can fix: the generic failure.
    if (names.length === 0) {
        await showFailure();

        return;
    }

    for (const name of names) {
        validation.errors[name] = errors[name];
    }

    validation.showSummary.value = names.length >= 2;
    await nextTick();

    if (names.length === 1) {
        validation.focusField(names[0]);
    } else {
        summaryRef.value?.focus();
    }
}

let settle: ((saved: boolean) => void) | null = null;

function settled(ok: boolean): void {
    settle?.(ok);
    settle = null;
}

function sendProfile(): void {
    saved.value = false;
    failed.value = false;

    profile
        .transform((data) => ({ ...data, _method: 'patch' }))
        .post(ProfileController.update.url(), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                profile.avatar = null;

                if (avatarInput.value) {
                    avatarInput.value.value = '';
                }

                profile.defaults();
                saved.value = true;
                announce(t('saved'), 'polite');
                // The button was disabled while saving, which drops focus: it goes back to Save.
                void nextTick(focusSave);
                settled(true);
            },
            onError: (errors) => void showServerErrors(errors),
            onNetworkError: () => void showFailure(),
            onHttpException: (response) => {
                if (response.status === 401 || response.status === 419) {
                    window.location.assign('/login');

                    return false;
                }

                // A body over `post_max_size` is refused before the app runs, with every field dropped: it is
                // the picture that is too large.
                if (response.status === 413) {
                    void showServerErrors({
                        avatar: props.avatarMaxBytes
                            ? labels.avatarTooLarge(
                                  formatLimit(props.avatarMaxBytes),
                              )
                            : labels.avatarTooLargeGeneric,
                    });

                    return false;
                }

                void showFailure();

                return false;
            },
            onFinish: () => settled(false),
        });
}

function submitProfile(): Promise<void> {
    return validation.submit(sendProfile);
}

// Unsaved edits are asked about before a Workspace switch (Story 1.17); Save submits the form.
const stop = registerUnsavedForm({
    id: 'profile',
    isDirty: () => profile.isDirty,
    save: () => {
        // An earlier save still waiting is settled first, so no promise is left hanging.
        settled(false);

        return new Promise<boolean>((resolve) => {
            settle = resolve;
            void submitProfile().then(() => {
                // Validation refused: nothing was sent, so nothing will settle it.
                if (!profile.processing) {
                    settled(false);
                }
            });
        });
    },
});

onBeforeUnmount(() => {
    stop();
    settled(false);
});

watch(
    () => [
        profile.name,
        profile.locale,
        profile.timezone,
        profile.keyboard_shortcuts,
    ],
    () => {
        saved.value = false;
    },
);

// What the saved locale and time zone do to numbers, currency and dates (the applied settings).
const preview = computed(() => {
    // Read the state, so the preview follows a save.
    const { locale, timeZone } = currentFormatting();

    return {
        locale,
        timeZone,
        number: formatNumber('1234567.891'),
        currency: formatCurrency('1234.5', 'USD'),
        date: formatDateTime('2026-10-07T14:30:00Z'),
    };
});

function localeName(locale: string): string {
    try {
        return (
            new Intl.DisplayNames([locale], { type: 'language' }).of(locale) ??
            locale
        );
    } catch {
        return locale;
    }
}

// ----------------------------------------------------------- change password

const password = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const passwordValidation = useBlurValidation({
    current_password: {
        label: labels.currentPassword,
        value: () => password.current_password,
        validate: (v) =>
            String(v) === '' ? labels.currentPasswordRequired : null,
    },
    password: {
        label: labels.newPassword,
        value: () => password.password,
        validate: (v) => (String(v) === '' ? labels.newPasswordRequired : null),
    },
    password_confirmation: {
        label: labels.passwordConfirmation,
        value: () => password.password_confirmation,
        validate: (v) =>
            v === password.password ? null : labels.passwordMismatch,
    },
});

const passwordSummary = ref<InstanceType<typeof FormErrorSummary> | null>(null);
const passwordSaved = ref(false);
const passwordFailed = ref(false);
const passwordFailureRef = ref<HTMLElement | null>(null);
const passwordButton = ref<InstanceType<typeof Button> | null>(null);

function bindPasswordSummary(el: unknown): void {
    passwordSummary.value = el as InstanceType<typeof FormErrorSummary> | null;
    passwordValidation.summary.value = passwordSummary.value;
}

async function showPasswordFailure(): Promise<void> {
    passwordFailed.value = true;
    passwordSaved.value = false;
    await nextTick();
    passwordFailureRef.value?.focus();
}

async function showPasswordErrors(
    errors: Record<string, string>,
): Promise<void> {
    passwordFailed.value = false;

    const names = [
        'current_password',
        'password',
        'password_confirmation',
    ].filter((name) => errors[name]);

    if (names.length === 0) {
        await showPasswordFailure();

        return;
    }

    for (const name of names) {
        passwordValidation.errors[name] = errors[name];
    }

    passwordValidation.showSummary.value = names.length >= 2;
    await nextTick();

    if (names.length === 1) {
        passwordValidation.focusField(names[0]);
    } else {
        passwordSummary.value?.focus();
    }
}

function sendPassword(): void {
    passwordSaved.value = false;
    passwordFailed.value = false;

    password.put(PasswordController.update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            password.reset();
            password.clearErrors();
            passwordSaved.value = true;
            announce(labels.passwordChanged, 'polite');
            void nextTick(() =>
                (passwordButton.value?.$el as HTMLElement | undefined)?.focus(),
            );
        },
        onError: (errors) => {
            // Nothing changed: the secrets are cleared, never kept in the page.
            password.reset();
            void showPasswordErrors(errors);
        },
        onNetworkError: () => void showPasswordFailure(),
        onHttpException: (response) => {
            if (response.status === 401 || response.status === 419) {
                window.location.assign('/login');

                return false;
            }

            void showPasswordFailure();

            return false;
        },
    });
}

function submitPassword(): Promise<void> {
    passwordSaved.value = false;

    return passwordValidation.submit(sendPassword);
}
</script>

<template>
    <Head :title="shellPages.profile.title" />

    <div class="flex flex-col gap-8 px-4 py-6 sm:px-7">
        <PageHeader :title="shellPages.profile.title" />

        <form
            class="grid max-w-xl gap-6"
            novalidate
            data-test="profile-form"
            @submit.prevent="submitProfile"
        >
            <h2 class="type-title-md text-text-primary">
                {{ labels.profileHeading }}
            </h2>

            <FormErrorSummary
                v-if="validation.showSummary.value"
                :ref="bindSummary"
                :items="validation.summaryItems.value"
            />
            <p
                v-if="failed"
                ref="failureRef"
                tabindex="-1"
                role="alert"
                class="type-body-sm text-error-text"
                data-test="save-failed"
            >
                {{ t('save-failed.form') }}
            </p>
            <RequiredNote />

            <FormField
                :id="validation.fieldId('name')"
                :label="labels.name"
                :error="validation.errors.name"
                required
                #default="{ field }"
            >
                <input
                    v-bind="field"
                    v-model="profile.name"
                    name="name"
                    autocomplete="name"
                    class="min-h-9 w-full min-w-0 rounded-md border border-border-control bg-surface-card px-3 py-1 text-sm text-text-primary focus:border-brand focus:shadow-[0_0_0_3px_var(--df-accent-soft)] aria-invalid:border-error"
                    @blur="validation.onBlur('name')"
                />
            </FormField>

            <FormField
                :id="validation.fieldId('avatar')"
                :label="labels.avatar"
                :helper="labels.avatarHelper"
                :error="validation.errors.avatar"
                #default="{ field }"
            >
                <div class="flex items-center gap-3">
                    <Avatar
                        class="size-12 shrink-0 overflow-hidden rounded-full"
                        data-test="avatar-current"
                    >
                        <AvatarImage
                            v-if="user.avatar"
                            :src="user.avatar"
                            :alt="labels.avatarCurrent(user.name)"
                        />
                        <AvatarFallback
                            class="rounded-full bg-surface-muted font-semibold text-text-primary"
                            >{{ getInitials(user.name) }}</AvatarFallback
                        >
                    </Avatar>
                    <input
                        v-bind="field"
                        ref="avatarInput"
                        type="file"
                        name="avatar"
                        accept="image/png,image/jpeg,image/webp"
                        class="min-h-9 w-full min-w-0 rounded-md border border-border-control bg-surface-card px-3 py-1 text-sm text-text-primary file:me-3 file:border-0 file:bg-transparent file:text-sm file:font-medium aria-invalid:border-error"
                        @change="chooseAvatar"
                        @blur="validation.onBlur('avatar')"
                    />
                </div>
            </FormField>

            <FormField
                :label="labels.locale"
                :error="profile.errors.locale"
                #default="{ field }"
            >
                <NativeSelect
                    v-bind="field"
                    v-model="profile.locale"
                    name="locale"
                >
                    <option
                        v-for="locale in locales"
                        :key="locale"
                        :value="locale"
                    >
                        {{ localeName(locale) }}
                    </option>
                </NativeSelect>
            </FormField>

            <FormField
                :label="labels.timezone"
                :error="profile.errors.timezone"
                #default="{ field }"
            >
                <NativeSelect
                    v-bind="field"
                    v-model="profile.timezone"
                    name="timezone"
                >
                    <option v-for="zone in timezones" :key="zone" :value="zone">
                        {{ zone }}
                    </option>
                </NativeSelect>
            </FormField>

            <dl
                class="type-caption grid gap-1 rounded-md bg-surface-muted p-3 text-text-secondary"
                data-test="format-preview"
                :lang="preview.locale"
            >
                <dt class="font-semibold text-text-primary">
                    {{ labels.formatPreview }}
                </dt>
                <dd>
                    {{ labels.formatPreviewNumber }}:
                    <span data-test="preview-number">{{ preview.number }}</span>
                </dd>
                <dd>
                    {{ labels.formatPreviewCurrency }}:
                    <span data-test="preview-currency">{{
                        preview.currency
                    }}</span>
                </dd>
                <dd>
                    {{ labels.formatPreviewDate }}:
                    <span data-test="preview-date">{{ preview.date }}</span>
                </dd>
            </dl>

            <FormField
                :label="labels.shortcuts"
                :helper="labels.shortcutsHelper"
                #default="{ field }"
            >
                <Switch
                    v-bind="field"
                    v-model="profile.keyboard_shortcuts"
                    data-test="keyboard-shortcuts"
                />
                <ul class="type-caption grid gap-1 text-text-secondary">
                    <li v-for="item in labels.shortcutList" :key="item.key">
                        <kbd
                            class="rounded border border-border-default px-1.5 font-mono"
                            >{{ item.key }}</kbd
                        >
                        {{ item.does }}
                    </li>
                </ul>
            </FormField>

            <div class="flex flex-wrap items-center gap-4">
                <Button
                    ref="saveButton"
                    type="submit"
                    :disabled="profile.processing"
                    data-test="update-profile-button"
                    >{{ labels.save }}</Button
                >
                <p
                    v-if="saved"
                    class="type-body-sm text-text-secondary"
                    data-test="saved"
                >
                    {{ t('saved') }}
                </p>
            </div>
        </form>

        <form
            class="grid max-w-xl gap-6"
            novalidate
            data-test="password-form"
            @submit.prevent="submitPassword"
        >
            <div class="grid gap-1">
                <h2 class="type-title-md text-text-primary">
                    {{ labels.passwordHeading }}
                </h2>
                <p class="type-caption text-text-muted">
                    {{ labels.passwordDescription }}
                </p>
            </div>

            <FormErrorSummary
                v-if="passwordValidation.showSummary.value"
                :ref="bindPasswordSummary"
                :items="passwordValidation.summaryItems.value"
            />
            <p
                v-if="passwordFailed"
                ref="passwordFailureRef"
                tabindex="-1"
                role="alert"
                class="type-body-sm text-error-text"
                data-test="password-failed"
            >
                {{ t('save-failed.form') }}
            </p>

            <FormField
                :id="passwordValidation.fieldId('current_password')"
                :label="labels.currentPassword"
                :error="passwordValidation.errors.current_password"
                required
                #default="{ field }"
            >
                <PasswordInput
                    v-bind="field"
                    v-model="password.current_password"
                    name="current_password"
                    autocomplete="current-password"
                    @blur="passwordValidation.onBlur('current_password')"
                />
            </FormField>

            <FormField
                :id="passwordValidation.fieldId('password')"
                :label="labels.newPassword"
                :error="passwordValidation.errors.password"
                required
                #default="{ field }"
            >
                <PasswordInput
                    v-bind="field"
                    v-model="password.password"
                    name="password"
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                    @blur="passwordValidation.onBlur('password')"
                />
            </FormField>

            <FormField
                :id="passwordValidation.fieldId('password_confirmation')"
                :label="labels.passwordConfirmation"
                :error="passwordValidation.errors.password_confirmation"
                required
                #default="{ field }"
            >
                <PasswordInput
                    v-bind="field"
                    v-model="password.password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    :passwordrules="passwordRules"
                    @blur="passwordValidation.onBlur('password_confirmation')"
                />
            </FormField>

            <div class="flex flex-wrap items-center gap-4">
                <Button
                    ref="passwordButton"
                    type="submit"
                    variant="secondary"
                    :disabled="password.processing"
                    data-test="update-password-button"
                    >{{ labels.passwordSubmit }}</Button
                >
                <p
                    v-if="passwordSaved"
                    class="type-body-sm text-text-secondary"
                    data-test="password-saved"
                >
                    {{ labels.passwordChanged }}
                </p>
            </div>
        </form>
    </div>
</template>
