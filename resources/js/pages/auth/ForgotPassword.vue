<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useBlurValidation } from '@/composables/useBlurValidation';
import { passwordResetLabels as labels } from '@/locales/labels';
import { login } from '@/routes';
import { email as sendLink } from '@/routes/password';

defineOptions({
    layout: {
        title: labels.forgotTitle,
        description: labels.forgotDescription,
    },
});

const props = defineProps<{
    status?: string;
}>();

const { t } = useI18n();

const form = useForm({ email: '' });

// Blur and submit validation (UX-DR-23, 274): a malformed email shows `field-error` inline.
const validation = useBlurValidation({
    email: {
        label: labels.email,
        value: () => form.email,
        validate: (v) =>
            /^\S+@\S+\.\S+$/.test(String(v).trim()) ? null : t('field-error'),
    },
});

// The server answers with catalogue keys only. `reset-requested` is the same whether or not an
// account exists; it is shown in a status line that takes focus.
const requested = ref(false);
const blocked = ref<string | null>(null);
const failed = ref(false);
const requestedRef = ref<HTMLElement | null>(null);
const blockedRef = ref<HTMLElement | null>(null);
const failureRef = ref<HTMLElement | null>(null);

async function showBlocked(message: string): Promise<void> {
    blocked.value = message;
    await nextTick();
    blockedRef.value?.focus();
}

async function showFailure(): Promise<void> {
    failed.value = true;
    await nextTick();
    failureRef.value?.focus();
}

function sendForm(): void {
    form.post(sendLink.url(), {
        preserveScroll: true,
        onSuccess: async () => {
            requested.value = true;
            await nextTick();
            requestedRef.value?.focus();
        },
        onError: async (errors) => {
            if (errors.email === 'throttled') {
                await showBlocked(t('throttled'));

                return;
            }

            // Only a rejected email is a field error; any other answer is the generic failure.
            if (errors.email !== 'field-error') {
                await showFailure();

                return;
            }

            validation.errors.email = t('field-error');
            await nextTick();
            validation.focusField('email');
        },
        onNetworkError: () => void showFailure(),
        onHttpException: (response) => {
            if (response.status === 429) {
                void showBlocked(t('throttled'));
            } else {
                void showFailure();
            }

            return false;
        },
    });
}

async function submit(): Promise<void> {
    requested.value = false;
    blocked.value = null;
    failed.value = false;
    await validation.submit(sendForm);
}
</script>

<template>
    <Head :title="labels.forgotTitle" />

    <div class="grid gap-6">
        <p
            v-if="requested || props.status === 'reset-requested'"
            ref="requestedRef"
            tabindex="-1"
            role="status"
            class="type-body-sm text-success-text"
            data-test="reset-requested"
        >
            {{ t('reset-requested') }}
        </p>
        <p
            v-if="blocked"
            ref="blockedRef"
            tabindex="-1"
            role="alert"
            class="type-body-sm text-error-text"
            data-test="reset-throttled"
        >
            {{ blocked }}
        </p>
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

        <form
            class="grid gap-6"
            novalidate
            data-test="forgot-password-form"
            @submit.prevent="submit"
        >
            <FormField
                :id="validation.fieldId('email')"
                :label="labels.email"
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
                    :placeholder="labels.emailPlaceholder"
                    @blur="validation.onBlur('email')"
                />
            </FormField>

            <Button
                type="submit"
                class="w-full"
                :disabled="form.processing"
                data-test="email-password-reset-link-button"
            >
                <Spinner v-if="form.processing" />
                {{ labels.sendLink }}
            </Button>
        </form>

        <Link
            :href="login().url"
            class="type-body-sm text-center font-semibold text-accent-ink underline underline-offset-4"
            data-test="back-to-sign-in"
        >
            {{ labels.backToSignIn }}
        </Link>
    </div>
</template>
