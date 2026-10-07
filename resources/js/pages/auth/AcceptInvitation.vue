<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import RequiredNote from '@/components/RequiredNote.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useBlurValidation } from '@/composables/useBlurValidation';
import { invitationLabels as labels } from '@/locales/labels';

defineOptions({
    layout: {
        title: labels.title,
        description: labels.description,
    },
});

const props = defineProps<{
    token: string;
    passwordRules: string;
}>();

const { t } = useI18n();

const form = useForm({
    email: '',
    name: '',
    password: '',
    password_confirmation: '',
});

// Blur and submit validation (UX-DR-274). The server applies Password::defaults(); its messages are
// shown in the same field-error pattern.
const validation = useBlurValidation({
    name: {
        label: labels.name,
        value: () => form.name,
        validate: (v) => (String(v).trim() === '' ? labels.nameRequired : null),
    },
    email: {
        label: labels.email,
        value: () => form.email,
        validate: (v) =>
            /^\S+@\S+\.\S+$/.test(String(v).trim()) ? null : t('field-error'),
    },
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

function sendForm(): void {
    form.post(`/invitations/${props.token}`, {
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

// Server-side field errors take the same path as client ones: one invalid field is focused,
// two or more show the summary, which takes focus.
async function showServerErrors(
    serverErrors: Record<string, string>,
): Promise<void> {
    failed.value = false;

    let names = Object.keys(serverErrors).filter((name) =>
        ['name', 'email', 'password', 'password_confirmation'].includes(name),
    );

    // An error that names none of the four fields still shows: the field-error copy on the email field.
    if (names.length === 0) {
        validation.errors.email = t('field-error');
        names = ['email'];
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

// Network, 419, 429 and 500 failures: a generic message that takes focus.
const failed = ref(false);
const failureRef = ref<HTMLElement | null>(null);

async function showFailure(): Promise<void> {
    failed.value = true;
    await nextTick();
    failureRef.value?.focus();
}

async function submit(): Promise<void> {
    failed.value = false;
    await validation.submit(sendForm);
}

function bindSummary(el: unknown): void {
    summaryRef.value = el as InstanceType<typeof FormErrorSummary> | null;
    validation.summary.value = summaryRef.value;
}
</script>

<template>
    <Head :title="labels.title" />

    <form
        class="grid gap-6"
        novalidate
        data-test="accept-invitation-form"
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
            :id="validation.fieldId('name')"
            :label="labels.name"
            :error="validation.errors.name"
            required
            #default="{ field }"
        >
            <Input
                v-bind="field"
                v-model="form.name"
                name="name"
                autocomplete="name"
                @blur="validation.onBlur('name')"
            />
        </FormField>

        <FormField
            :id="validation.fieldId('email')"
            :label="labels.email"
            :helper="labels.emailHelper"
            :error="validation.errors.email"
            required
            #default="{ field }"
        >
            <Input
                v-bind="field"
                v-model="form.email"
                type="email"
                name="email"
                autocomplete="email"
                @blur="validation.onBlur('email')"
            />
        </FormField>

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
            data-test="accept-invitation-button"
        >
            <Spinner v-if="form.processing" />
            {{ labels.submit }}
        </Button>
    </form>
</template>
