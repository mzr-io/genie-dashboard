<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import RequiredNote from '@/components/RequiredNote.vue';
import RoleMenu from '@/components/RoleMenu.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useBlurValidation } from '@/composables/useBlurValidation';
import { announce } from '@/lib/announce';
import {
    InvitationRequestError,
    inviteMember,
    resendInvitation,
} from '@/lib/members';
import type { Member } from '@/lib/members';
import { SIGN_IN_URL } from '@/lib/session';
import { inviteLabels as labels, userListLabels } from '@/locales/labels';

// The inline invite form of User configuration (Story 1.21; UX-DR-23, 143, 274): it expands in the page, never as a
// modal. Email, a role menu and, for Admin, only the permissions the inviter holds (`held`, the shell's `can` map);
// choosing any permission asks for the inviter's password before anything is sent. A server refusal takes the
// same path as a client one: one invalid field is focused, two or more show the summary. A delivery failure keeps the
// invitation (it shows as Invited) and offers Retry, which re-sends it. Success announces `saved` and closes.
const props = defineProps<{
    // The permission keys the inviter holds now.
    held: string[];
}>();

const emit = defineEmits<{
    // The invitation exists (created, re-sent or saved without its email): the list must reload.
    changed: [member: Member | null];
    close: [];
}>();

const { t } = useI18n();

const email = ref('');
const role = ref<'user' | 'admin'>('user');
const permissions = ref<string[]>([]);
const confirmPassword = ref('');
const processing = ref(false);
// A form-level failure message: `delivery` and `save` offer Retry; the rest only explain.
const failure = ref<
    'delivery' | 'save' | 'config' | 'held' | 'throttled' | null
>(null);
const failureRef = ref<HTMLElement | null>(null);
// The pending invitation this email already has, or the invitation whose email failed.
const pendingId = ref<string | null>(null);
const heading = ref<HTMLElement | null>(null);
const summaryRef = ref<InstanceType<typeof FormErrorSummary> | null>(null);
const permissionsId = useId();

const grantable = computed(() =>
    Object.keys(labels.permissionLabels).filter((key) =>
        props.held.includes(key),
    ),
);
// Every Admin invitation asks for the password, with or without permissions ticked.
const needsPassword = computed(() => role.value === 'admin');

const validation = useBlurValidation({
    email: {
        label: labels.email,
        value: () => email.value,
        validate: (v) =>
            /^\S+@\S+\.\S+$/.test(String(v).trim()) ? null : t('field-error'),
    },
    confirm_password: {
        label: labels.confirmPassword,
        value: () => confirmPassword.value,
        validate: (v) =>
            needsPassword.value && String(v) === ''
                ? labels.confirmPasswordRequired
                : null,
    },
});

const fields = ['email', 'role', 'permissions', 'confirm_password'];

function togglePermission(key: string, on: boolean | 'indeterminate'): void {
    permissions.value =
        on === true
            ? [...new Set([...permissions.value, key])]
            : permissions.value.filter((held) => held !== key);
}

function setRole(next: 'user' | 'admin'): void {
    role.value = next;

    if (next === 'user') {
        permissions.value = [];
        confirmPassword.value = '';
        validation.errors.confirm_password = null;
    }
}

function clearServerState(): void {
    failure.value = null;
    pendingId.value = null;
    fields.forEach((name) => {
        validation.errors[name] = null;
    });
    validation.showSummary.value = false;
}

async function showErrors(errors: Record<string, string[]>): Promise<void> {
    const names = fields.filter((name) => errors[name]?.length);

    names.forEach((name) => {
        // The catalogue words an invalid email; the pending and member cases have their own sentences.
        validation.errors[name] = errors[name]?.[0] ?? null;
    });

    if (names.length === 0) {
        failure.value = 'save';
        await focusFailure();

        return;
    }

    validation.showSummary.value = names.length >= 2;
    await nextTick();

    if (names.length === 1) {
        validation.focusField(names[0]);
    } else {
        summaryRef.value?.focus();
    }
}

async function focusFailure(): Promise<void> {
    await nextTick();
    failureRef.value?.focus();
}

async function refused(error: unknown): Promise<void> {
    if (!(error instanceof InvitationRequestError)) {
        failure.value = 'save';
        await focusFailure();

        return;
    }

    if (error.status === 401 || error.status === 419) {
        window.location.assign(SIGN_IN_URL);

        return;
    }

    if (error.code === 'identity.invitation_delivery_failed') {
        // Saved as Invited; the list shows it with Resend, and Retry here sends it again.
        pendingId.value = error.invitationId;
        failure.value = 'delivery';
        emit('changed', null);
        await focusFailure();

        return;
    }

    if (error.code === 'access.invitations_not_configured') {
        failure.value = 'config';
        await focusFailure();

        return;
    }

    if (error.code === 'access.permission_not_held') {
        failure.value = 'held';
        await focusFailure();

        return;
    }

    if (error.status === 429) {
        if (error.errors.confirm_password?.length && needsPassword.value) {
            await showErrors({ confirm_password: [t('throttled')] });
        } else {
            // The route's own limit, or no password field to show it on: a form-level message.
            failure.value = 'throttled';
            await focusFailure();
        }

        return;
    }

    if (error.status === 422) {
        const errors: Record<string, string[]> = {};

        if (error.errors.email?.length) {
            if (error.reason === 'pending') {
                pendingId.value = error.invitationId;
                errors.email = [labels.emailPending];
            } else if (error.reason === 'member') {
                errors.email = [labels.emailMember];
            } else {
                errors.email = [t('field-error')];
            }
        }

        if (error.errors.confirm_password?.length) {
            errors.confirm_password = [labels.confirmPasswordWrong];
        }

        if (error.errors.role?.length) {
            errors.role = [error.errors.role[0]];
        }

        if (error.errors.permissions?.length) {
            errors.permissions = [error.errors.permissions[0]];
        }

        await showErrors(errors);

        return;
    }

    failure.value = 'save';
    await focusFailure();
}

function done(member: Member | null, text: string): void {
    announce(`${t('saved')} ${text}`, 'polite');
    emit('changed', member);
    emit('close');
}

async function send(): Promise<void> {
    clearServerState();
    processing.value = true;

    try {
        const member = await inviteMember({
            email: email.value.trim(),
            role: role.value,
            permissions: role.value === 'admin' ? permissions.value : [],
            ...(needsPassword.value
                ? { confirm_password: confirmPassword.value }
                : {}),
        });

        done(member, userListLabels.invited(member.email));
    } catch (error) {
        await refused(error);
    } finally {
        processing.value = false;
    }
}

// Re-sends the invitation named by `pendingId` (a duplicate email, or an email that did not go out).
async function resend(): Promise<void> {
    const id = pendingId.value;

    if (id === null) {
        return;
    }

    processing.value = true;
    failure.value = null;

    try {
        const member = await resendInvitation(id);

        done(member, userListLabels.resent(member.email));
    } catch (error) {
        await refused(error);
    } finally {
        processing.value = false;
    }
}

async function submit(): Promise<void> {
    await validation.submit(send);
}

function bindSummary(el: unknown): void {
    summaryRef.value = el as InstanceType<typeof FormErrorSummary> | null;
    validation.summary.value = summaryRef.value;
}

onMounted(() => {
    // Expanding the form moves focus into it, to its first field.
    validation.focusField('email');
});

defineExpose({ focus: () => validation.focusField('email') });
</script>

<template>
    <section
        :aria-label="labels.region"
        data-slot="invite-form"
        class="rounded-lg border border-border-default bg-surface-card p-4 sm:p-6"
    >
        <h2 ref="heading" class="type-title-md mb-4 text-text-primary">
            {{ labels.title }}
        </h2>

        <form
            class="grid max-w-xl gap-5"
            novalidate
            data-test="invite-form"
            @submit.prevent="submit"
        >
            <FormErrorSummary
                v-if="validation.showSummary.value"
                :ref="bindSummary"
                :items="validation.summaryItems.value"
            />

            <div
                v-if="failure"
                ref="failureRef"
                tabindex="-1"
                role="alert"
                class="flex flex-wrap items-center justify-between gap-3 rounded-md border-l-[3px] border-error bg-error-soft p-3"
                data-test="invite-failure"
            >
                <p class="type-body-sm text-error-text">
                    <template v-if="failure === 'config'">{{
                        labels.notConfigured
                    }}</template>
                    <template v-else-if="failure === 'held'">{{
                        labels.notHeld
                    }}</template>
                    <template v-else-if="failure === 'throttled'">{{
                        labels.throttled
                    }}</template>
                    <template v-else-if="failure === 'delivery'"
                        >{{ t('save-failed.form') }}
                        {{ labels.deliveryFailed }}</template
                    >
                    <template v-else>{{ t('save-failed.form') }}</template>
                </p>
                <Button
                    v-if="failure === 'delivery' || failure === 'save'"
                    type="button"
                    variant="secondary"
                    size="sm"
                    :disabled="processing"
                    data-test="invite-retry"
                    @click="failure === 'delivery' ? resend() : submit()"
                >
                    {{ labels.retry }}
                </Button>
            </div>

            <RequiredNote />

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
                    v-model="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    maxlength="254"
                    @blur="validation.onBlur('email')"
                />
            </FormField>

            <Button
                v-if="pendingId && failure !== 'delivery'"
                type="button"
                variant="secondary"
                class="w-fit"
                :disabled="processing"
                data-test="invite-resend"
                @click="resend"
            >
                {{ labels.resendPending }}
            </Button>

            <FormField
                :id="validation.fieldId('role')"
                :label="labels.role"
                :error="validation.errors.role"
                #default="{ field }"
            >
                <RoleMenu
                    v-bind="field"
                    :model-value="role"
                    @update:model-value="setRole"
                />
            </FormField>

            <fieldset
                v-if="role === 'admin'"
                :id="validation.fieldId('permissions')"
                class="grid gap-2"
                data-test="permissions"
                :aria-describedby="`${permissionsId}-helper`"
                tabindex="-1"
            >
                <legend class="type-label text-text-primary">
                    {{ labels.permissions }}
                </legend>
                <p
                    :id="`${permissionsId}-helper`"
                    class="type-caption text-text-muted"
                >
                    {{
                        grantable.length > 0
                            ? labels.permissionsHelper
                            : labels.permissionsNone
                    }}
                </p>
                <label
                    v-for="key in grantable"
                    :key="key"
                    class="type-body-sm flex items-center gap-2 text-text-primary"
                >
                    <Checkbox
                        :model-value="permissions.includes(key)"
                        :name="`permissions[${key}]`"
                        :data-permission="key"
                        @update:model-value="(on) => togglePermission(key, on)"
                    />
                    {{ labels.permissionLabels[key] }}
                </label>
                <p
                    v-if="validation.errors.permissions"
                    class="type-caption text-error-text"
                >
                    {{ validation.errors.permissions }}
                </p>
            </fieldset>

            <FormField
                v-if="needsPassword"
                :id="validation.fieldId('confirm_password')"
                :label="labels.confirmPassword"
                :helper="labels.confirmPasswordHelper"
                :error="validation.errors.confirm_password"
                required
                #default="{ field }"
            >
                <PasswordInput
                    v-bind="field"
                    v-model="confirmPassword"
                    name="confirm_password"
                    autocomplete="current-password"
                    @blur="validation.onBlur('confirm_password')"
                />
            </FormField>

            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    :disabled="processing || failure === 'delivery'"
                    data-test="invite-send"
                >
                    <Spinner v-if="processing" />
                    {{ labels.send }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="processing"
                    data-test="invite-cancel"
                    @click="emit('close')"
                >
                    {{ labels.cancel }}
                </Button>
            </div>
        </form>
    </section>
</template>
