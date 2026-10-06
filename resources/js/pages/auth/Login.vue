<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Lock, Mail } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import SignInRoleCards from '@/components/signin/SignInRoleCards.vue';
import type { Role } from '@/components/signin/SignInRoleCards.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useBlurValidation } from '@/composables/useBlurValidation';
import SignInLayout from '@/layouts/SignInLayout.vue';
import { signInLabels as labels } from '@/locales/labels';

defineOptions({ layout: SignInLayout });

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();

const { t } = useI18n();

const form = useForm<{
    email: string;
    password: string;
    remember: boolean;
    role: Role;
}>({
    email: '',
    password: '',
    remember: false,
    role: 'user',
});

const buttonLabel = computed(() =>
    labels.submit(form.role === 'admin' ? labels.roleAdmin : labels.roleUser),
);

// Blur and submit validation (UX-DR-23, 274). Whether the credentials match is the server's answer.
const validation = useBlurValidation({
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
});

const roleId = `${validation.fieldId('role')}`;

// The catalogue keys the server answers with, each shown as the summary's single item.
const CATALOGUE_KEYS = ['signin-failed', 'signin-role-denied', 'throttled'];

type ServerItem = { id: string; label: string; message: string };
const serverItem = ref<ServerItem | null>(null);
const failed = ref(false);
let keepPassword = false;

const summaryRef = ref<InstanceType<typeof FormErrorSummary> | null>(null);
const failureRef = ref<HTMLElement | null>(null);

const summaryItems = computed(() =>
    serverItem.value ? [serverItem.value] : validation.summaryItems.value,
);
const summaryVisible = computed(
    () => serverItem.value !== null || validation.showSummary.value,
);

function bindSummary(el: unknown): void {
    summaryRef.value = el as InstanceType<typeof FormErrorSummary> | null;
    validation.summary.value = summaryRef.value;
}

function copy(key: string | undefined): string | null {
    if (!key) {
        return null;
    }

    // Only catalogue copy is shown: any other server text becomes the generic failure.
    return t(CATALOGUE_KEYS.includes(key) ? key : 'signin-failed');
}

// A failed attempt shows one message in the error summary, which takes focus. A refused Admin choice also
// offers the User card (the message asks "Sign in as User instead?").
async function showServerError(
    errors: Record<string, string>,
    status?: number,
): Promise<void> {
    failed.value = false;
    keepPassword = false;

    const denied = errors.role === 'signin-role-denied';
    const message =
        status === 429 ? t('throttled') : copy(errors.role ?? errors.email);

    if (message === null) {
        await showFailure();

        return;
    }

    if (denied) {
        form.role = 'user';
        // The person only chose the wrong card: they keep what they typed.
        keepPassword = true;
    }

    serverItem.value = {
        id: denied ? `${roleId}-user` : validation.fieldId('email'),
        label: denied ? labels.summaryRole : labels.email,
        message,
    };

    await nextTick();
    summaryRef.value?.focus();
}

// Network, 419 and 500 failures: a generic message that takes focus.
async function showFailure(): Promise<void> {
    failed.value = true;
    await nextTick();
    failureRef.value?.focus();
}

function sendForm(): void {
    form.post('/login', {
        preserveScroll: true,
        onError: (errors) => void showServerError(errors),
        onNetworkError: () => void showFailure(),
        onHttpException: (response) => {
            if (response.status === 429) {
                void showServerError({}, 429);
            } else {
                void showFailure();
            }

            return false;
        },
        onFinish: () => {
            if (!keepPassword) {
                form.reset('password');
            }
        },
    });
}

async function submit(): Promise<void> {
    failed.value = false;
    keepPassword = false;
    serverItem.value = null;
    await validation.submit(sendForm);
}
</script>

<template>
    <Head :title="labels.title" />

    <div class="flex flex-col gap-6">
        <header class="flex flex-col gap-2">
            <p class="type-label-caps text-accent-ink">{{ labels.eyebrow }}</p>
            <h1 class="type-headline-lg text-text-primary">
                {{ labels.title }}
            </h1>
            <p class="type-body-sm text-text-muted">
                {{ t('signin-subtitle') }}
            </p>
        </header>

        <p
            v-if="props.status"
            class="type-body-sm text-success-text"
            data-test="signin-status"
        >
            {{ props.status }}
        </p>

        <form
            class="grid gap-5"
            novalidate
            data-test="signin-form"
            @submit.prevent="submit"
        >
            <FormErrorSummary
                v-if="summaryVisible"
                :ref="bindSummary"
                :items="summaryItems"
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

            <SignInRoleCards :id="roleId" v-model="form.role" />

            <FormField
                :id="validation.fieldId('email')"
                :label="labels.email"
                :error="validation.errors.email"
                required
                #default="{ field }"
            >
                <div class="relative">
                    <Mail
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-text-muted"
                        aria-hidden="true"
                    />
                    <Input
                        v-bind="field"
                        v-model="form.email"
                        type="email"
                        name="email"
                        autocomplete="username"
                        :placeholder="labels.emailPlaceholder"
                        class="pl-9"
                        @blur="validation.onBlur('email')"
                    />
                </div>
            </FormField>

            <FormField
                :id="validation.fieldId('password')"
                :label="labels.password"
                :error="validation.errors.password"
                required
                #default="{ field }"
            >
                <div class="relative">
                    <Lock
                        class="pointer-events-none absolute top-1/2 left-3 z-10 size-4 -translate-y-1/2 text-text-muted"
                        aria-hidden="true"
                    />
                    <PasswordInput
                        v-bind="field"
                        v-model="form.password"
                        name="password"
                        autocomplete="current-password"
                        class="pl-9"
                        @blur="validation.onBlur('password')"
                    />
                </div>
            </FormField>

            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="remember"
                        v-model="form.remember"
                        name="remember"
                    />
                    <Label for="remember" class="type-body-sm">{{
                        labels.remember
                    }}</Label>
                </div>
                <Link
                    v-if="props.canResetPassword"
                    href="/forgot-password"
                    class="type-body-sm font-semibold text-accent-ink underline underline-offset-4"
                    data-test="forgot-password"
                >
                    {{ labels.forgot }}
                </Link>
            </div>

            <Button
                type="submit"
                class="w-full"
                :disabled="form.processing"
                data-test="signin-button"
            >
                <Spinner v-if="form.processing" />
                {{ buttonLabel }}
                <ArrowRight v-if="!form.processing" aria-hidden="true" />
            </Button>
        </form>

        <div class="flex items-center gap-3" role="presentation">
            <Separator class="flex-1" />
            <span class="type-caption text-text-muted">{{
                labels.divider
            }}</span>
            <Separator class="flex-1" />
        </div>

        <p
            class="type-body-sm text-center text-text-muted"
            data-test="help-line"
        >
            <Link
                href="/help"
                class="font-semibold text-accent-ink underline underline-offset-4"
                >{{ labels.help }}</Link
            >
        </p>
    </div>
</template>
