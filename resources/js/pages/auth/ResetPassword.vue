<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import RequiredNote from '@/components/RequiredNote.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useBlurValidation } from '@/composables/useBlurValidation';
import { passwordResetLabels as labels } from '@/locales/labels';
import { request, update } from '@/routes/password';

defineOptions({
    layout: {
        title: labels.resetTitle,
        description: labels.resetDescription,
    },
});

const props = defineProps<{
    token: string | null;
    email: string | null;
    expired?: boolean;
    passwordRules: string;
}>();

const { t } = useI18n();

const form = useForm({
    token: props.token ?? '',
    email: props.email ?? '',
    password: '',
    password_confirmation: '',
});

// An expired, used or tampered link, or one for another email, is one state (`reset-expired`).
const linkExpired = ref(props.expired === true);
const expiredRef = ref<HTMLElement | null>(null);

// A revisit with a new link (or an expired one) replaces the old props.
watch(
    () => [props.expired, props.token, props.email],
    () => {
        linkExpired.value = props.expired === true;
        form.token = props.token ?? '';
        form.email = props.email ?? '';
        failed.value = false;
    },
);

onMounted(() => {
    if (linkExpired.value) {
        expiredRef.value?.focus();
    }
});

// Blur and submit validation (UX-DR-274). The server applies Password::defaults(); its messages show in
// the same field-error pattern, and a rejected password leaves the link valid.
const validation = useBlurValidation({
    password: {
        label: labels.password,
        value: () => form.password,
        validate: (v) => (String(v) === '' ? labels.passwordRequired : null),
    },
    password_confirmation: {
        label: labels.passwordConfirmation,
        value: () => form.password_confirmation,
        validate: (v) => (v === form.password ? null : labels.passwordMismatch),
    },
});

const summaryRef = ref<InstanceType<typeof FormErrorSummary> | null>(null);
const failed = ref(false);
const failureRef = ref<HTMLElement | null>(null);

function bindSummary(el: unknown): void {
    summaryRef.value = el as InstanceType<typeof FormErrorSummary> | null;
    validation.summary.value = summaryRef.value;
}

async function showExpired(): Promise<void> {
    linkExpired.value = true;
    await nextTick();
    expiredRef.value?.focus();
}

async function showServerErrors(
    serverErrors: Record<string, string>,
): Promise<void> {
    failed.value = false;

    if (serverErrors.email === 'reset-expired') {
        await showExpired();

        return;
    }

    const names = Object.keys(serverErrors).filter((name) =>
        ['password', 'password_confirmation'].includes(name),
    );

    // An error that names none of the two fields is not a password problem: the generic failure.
    if (names.length === 0) {
        await showFailure();

        return;
    }

    names.forEach((name) => {
        validation.errors[name] = serverErrors[name] ?? validation.errors[name];
    });
    validation.showSummary.value = names.length >= 2;

    await nextTick();

    if (names.length === 1) {
        validation.focusField(names[0]);
    } else {
        summaryRef.value?.focus();
    }
}

async function showFailure(): Promise<void> {
    failed.value = true;
    await nextTick();
    failureRef.value?.focus();
}

function sendForm(): void {
    form.post(update.url(), {
        preserveScroll: true,
        onError: (serverErrors) => void showServerErrors(serverErrors),
        onNetworkError: () => void showFailure(),
        onHttpException: () => {
            void showFailure();

            return false;
        },
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}

async function submit(): Promise<void> {
    failed.value = false;
    await validation.submit(sendForm);
}
</script>

<template>
    <Head :title="linkExpired ? labels.expiredTitle : labels.resetTitle" />

    <div v-if="linkExpired" class="grid gap-4 text-center">
        <p
            ref="expiredRef"
            tabindex="-1"
            role="alert"
            data-test="reset-expired"
        >
            {{ t('reset-expired') }}
        </p>
        <Link
            :href="request().url"
            class="text-sm font-semibold text-accent-ink underline underline-offset-4"
            data-test="request-new-link"
            >{{ labels.requestNew }}</Link
        >
    </div>

    <form
        v-else
        class="grid gap-6"
        novalidate
        data-test="reset-password-form"
        @submit.prevent="submit"
    >
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
            data-test="submit-failed"
        >
            {{ labels.submitFailed }}
        </p>
        <RequiredNote />

        <FormField
            :id="validation.fieldId('password')"
            :label="labels.password"
            :error="validation.errors.password"
            required
            #default="{ field }"
        >
            <PasswordInput
                v-bind="field"
                v-model="form.password"
                name="password"
                autocomplete="new-password"
                :passwordrules="passwordRules"
                @blur="validation.onBlur('password')"
            />
        </FormField>

        <FormField
            :id="validation.fieldId('password_confirmation')"
            :label="labels.passwordConfirmation"
            :error="validation.errors.password_confirmation"
            required
            #default="{ field }"
        >
            <PasswordInput
                v-bind="field"
                v-model="form.password_confirmation"
                name="password_confirmation"
                autocomplete="new-password"
                :passwordrules="passwordRules"
                @blur="validation.onBlur('password_confirmation')"
            />
        </FormField>

        <Button
            type="submit"
            class="w-full"
            :disabled="form.processing"
            data-test="reset-password-button"
        >
            <Spinner v-if="form.processing" />
            {{ labels.submit }}
        </Button>
    </form>
</template>
