<script setup lang="ts">
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FormField from '@/components/FormField.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import RoleMenu from '@/components/RoleMenu.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { useBlurValidation } from '@/composables/useBlurValidation';
import { announce } from '@/lib/announce';
import { MemberUpdateError, updateMember } from '@/lib/members';
import type { Member } from '@/lib/members';
import { SIGN_IN_URL } from '@/lib/session';
import { accessLabels as labels, inviteLabels } from '@/locales/labels';

// The inline Roles & permissions editor of a member row (Story 1.22; UX-DR-143, 254, 255, 271): it expands in the
// table under the row, never as a modal. A role menu (chips and one-line descriptions), one checkbox per catalogue
// permission (only what the Admin holds can be changed; the rest are shown disabled with their reason, never hidden),
// the password prompt (when the set changes or the role becomes Admin) and Save. A User holds no permissions, so the
// checkboxes are disabled with a reason until Admin is chosen. Moving an Admin to User asks first, in an alertdialog
// that names the member and the impact with focus on Cancel. After a save `saved` is announced politely and focus
// stays on Save; a stale revision (409) shows `save-failed` form wording with the member's latest values.
const props = defineProps<{
    member: Member;
    // The permission keys the editing Admin holds now (the shell's `can` map).
    held: string[];
}>();

const emit = defineEmits<{
    // The server's new state of the member: the row must show it.
    saved: [member: Member];
    // The member is no longer in the Workspace: the page drops the row and reloads.
    gone: [];
    close: [];
}>();

const { t } = useI18n();

const catalogue = Object.keys(inviteLabels.permissionLabels);
const name = computed(() => props.member.name || props.member.email);

const revision = ref(props.member.revision ?? 1);
const savedRole = ref<'user' | 'admin'>(props.member.role);
const savedPermissions = ref<string[]>([...(props.member.permissions ?? [])]);
const role = ref<'user' | 'admin'>(savedRole.value);
const permissions = ref<string[]>([...savedPermissions.value]);
const confirmPassword = ref('');
const processing = ref(false);
const failure = ref<
    'conflict' | 'last' | 'self' | 'held' | 'inactive' | 'save' | null
>(null);
const permissionsError = ref<string | null>(null);
const root = ref<HTMLElement | null>(null);
const status = ref('');
const failureRef = ref<HTMLElement | null>(null);
const confirming = ref(false);
const permissionsId = useId();
const heading = ref<HTMLElement | null>(null);

const sameSet = (a: string[], b: string[]) =>
    a.length === b.length && [...a].sort().join() === [...b].sort().join();

const roleChanged = computed(() => role.value !== savedRole.value);
const setChanged = computed(() => {
    const target = role.value === 'user' ? [] : permissions.value;

    return !sameSet(target, savedPermissions.value);
});
const dirty = computed(() => roleChanged.value || setChanged.value);
const needsPassword = computed(
    () => setChanged.value || (roleChanged.value && role.value === 'admin'),
);
const downgrade = computed(
    () => savedRole.value === 'admin' && role.value === 'user',
);
const userRole = computed(() => role.value === 'user');

const validation = useBlurValidation({
    confirm_password: {
        label: inviteLabels.confirmPassword,
        value: () => confirmPassword.value,
        validate: (v) =>
            needsPassword.value && String(v) === ''
                ? labels.passwordRequired
                : null,
    },
});

function holds(key: string): boolean {
    return props.held.includes(key);
}

// Editing again clears a stale refusal and the saved note.
function edited(): void {
    status.value = '';
    failure.value = null;
    permissionsError.value = null;
}

function togglePermission(key: string, on: boolean | 'indeterminate'): void {
    edited();
    permissions.value =
        on === true
            ? [...new Set([...permissions.value, key])]
            : permissions.value.filter((held) => held !== key);
}

function setRole(next: 'user' | 'admin'): void {
    edited();
    role.value = next;

    if (next === 'user') {
        permissions.value = [];
    } else if (savedRole.value === 'admin') {
        permissions.value = [...savedPermissions.value];
    }
}

watch(needsPassword, (needed) => {
    if (!needed) {
        confirmPassword.value = '';
        validation.errors.confirm_password = null;
    }
});

function apply(current: {
    role: 'user' | 'admin';
    permissions: string[];
    revision: number;
}): void {
    revision.value = current.revision;
    savedRole.value = current.role;
    savedPermissions.value = [...current.permissions];
    role.value = current.role;
    permissions.value = [...current.permissions];
    confirmPassword.value = '';
    validation.errors.confirm_password = null;
}

// A newer revision of the member arrived from the page (a reload): show it, unless a save is in flight.
watch(
    () => props.member.revision,
    (next) => {
        if (
            !processing.value &&
            next !== undefined &&
            next !== revision.value
        ) {
            apply({
                role: props.member.role,
                permissions: props.member.permissions ?? [],
                revision: next,
            });
        }
    },
);

async function focusFailure(): Promise<void> {
    await nextTick();
    failureRef.value?.focus();
}

async function refused(error: unknown): Promise<void> {
    if (!(error instanceof MemberUpdateError)) {
        failure.value = 'save';
        await focusFailure();

        return;
    }

    if (error.status === 401 || error.status === 419) {
        window.location.assign(SIGN_IN_URL);

        return;
    }

    if (error.code === 'access.not_authorized') {
        // The Admin lost their own access: reload to the denial the gate now gives.
        window.location.reload();

        return;
    }

    if (error.code === 'access.revision_conflict') {
        if (error.current) {
            apply(error.current);
            // The list row and later reopenings use the new revision.
            emit('saved', { ...props.member, ...error.current });
        }

        failure.value = 'conflict';
        await focusFailure();

        return;
    }

    if (error.code === 'access.last_users_manage_holder') {
        failure.value = 'last';
    } else if (error.code === 'access.self_change_forbidden') {
        failure.value = 'self';
    } else if (error.code === 'access.permission_not_held') {
        failure.value = 'held';
    } else if (error.status === 404) {
        emit('gone');

        return;
    } else if (error.status === 429) {
        validation.errors.confirm_password = t('throttled');
        await nextTick();
        validation.focusField('confirm_password');

        return;
    } else if (error.status === 422 && error.reason === 'membership_inactive') {
        failure.value = 'inactive';
    } else if (error.status === 422 && error.errors.confirm_password?.length) {
        validation.errors.confirm_password = inviteLabels.confirmPasswordWrong;
        await nextTick();
        validation.focusField('confirm_password');

        return;
    } else if (error.status === 422 && error.errors.permissions?.length) {
        permissionsError.value = error.errors.permissions[0];
        await nextTick();
        document.getElementById(permissionsId)?.focus();

        return;
    } else {
        // Offline (status 0), a server error or anything unexpected: the generic message with Retry.
        failure.value = 'save';
    }

    await focusFailure();
}

async function send(): Promise<void> {
    if (processing.value) {
        return;
    }

    failure.value = null;
    status.value = '';
    permissionsError.value = null;
    processing.value = true;

    try {
        const member = await updateMember(props.member.membership_id ?? '', {
            revision: revision.value,
            role: role.value,
            permissions: role.value === 'user' ? [] : permissions.value,
            ...(needsPassword.value
                ? { confirm_password: confirmPassword.value }
                : {}),
        });

        apply({
            role: member.role,
            permissions: member.permissions ?? [],
            revision: member.revision ?? revision.value + 1,
        });
        status.value = `${t('saved')} ${labels.savedFor(name.value)}`;
        announce(status.value, 'polite');
        emit('saved', member);
        // The password field has just unmounted: focus stays on Save (also after Enter in that field).
        await nextTick();
        root.value
            ?.querySelector<HTMLElement>('[data-test="access-save"]')
            ?.focus();
    } catch (error) {
        await refused(error);
    } finally {
        processing.value = false;
    }
}

async function submit(): Promise<void> {
    if (!dirty.value) {
        status.value = labels.unchanged;
        announce(labels.unchanged, 'polite');

        return;
    }

    await validation.submit(() => {
        if (downgrade.value) {
            confirming.value = true;

            return;
        }

        return send();
    });
}

onMounted(() => heading.value?.focus());

defineExpose({ focus: () => heading.value?.focus() });
</script>

<template>
    <section
        ref="root"
        :aria-label="labels.region(name)"
        data-slot="access-editor"
        class="grid max-w-xl gap-5"
    >
        <h3
            ref="heading"
            tabindex="-1"
            class="type-title-md text-text-primary"
            data-test="access-title"
        >
            {{ labels.title(name) }}
        </h3>

        <form
            class="grid gap-5"
            novalidate
            data-test="access-form"
            @submit.prevent="submit"
        >
            <div
                v-if="failure"
                ref="failureRef"
                tabindex="-1"
                role="alert"
                class="rounded-md border-l-[3px] border-error bg-error-soft p-3"
                data-test="access-failure"
            >
                <p class="type-body-sm text-error-text">
                    <template v-if="failure === 'last'">{{
                        labels.lastHolder
                    }}</template>
                    <template v-else-if="failure === 'self'">{{
                        labels.selfReason
                    }}</template>
                    <template v-else-if="failure === 'held'">{{
                        labels.held
                    }}</template>
                    <template v-else-if="failure === 'inactive'">{{
                        labels.inactive
                    }}</template>
                    <template v-else-if="failure === 'conflict'"
                        >{{ t('save-failed.form') }}
                        {{ labels.conflict }}</template
                    >
                    <template v-else>{{ t('save-failed.form') }}</template>
                </p>
                <Button
                    v-if="failure === 'save'"
                    type="button"
                    variant="secondary"
                    size="sm"
                    class="mt-2"
                    :disabled="processing"
                    data-test="access-retry"
                    @click="submit()"
                >
                    {{ labels.retry }}
                </Button>
            </div>

            <FormField
                :id="validation.fieldId('role')"
                :label="inviteLabels.role"
                #default="{ field }"
            >
                <RoleMenu
                    v-bind="field"
                    :model-value="role"
                    @update:model-value="setRole"
                />
            </FormField>

            <fieldset
                :id="permissionsId"
                tabindex="-1"
                class="grid gap-2"
                data-test="permissions"
                :disabled="userRole"
                :aria-describedby="`${permissionsId}-helper`"
            >
                <legend class="type-label text-text-primary">
                    {{ inviteLabels.permissions }}
                </legend>
                <p
                    :id="`${permissionsId}-helper`"
                    class="type-caption text-text-secondary"
                    data-test="permissions-helper"
                >
                    {{
                        userRole
                            ? labels.userNoPermissions
                            : inviteLabels.permissionsHelper
                    }}
                </p>
                <div v-for="key in catalogue" :key="key" class="grid gap-0.5">
                    <label
                        class="type-body-sm flex items-center gap-2 text-text-primary"
                    >
                        <Checkbox
                            :model-value="permissions.includes(key)"
                            :name="`permissions[${key}]`"
                            :data-permission="key"
                            :disabled="userRole || !holds(key)"
                            :aria-describedby="
                                !userRole && !holds(key)
                                    ? `${permissionsId}-${key}`
                                    : undefined
                            "
                            @update:model-value="
                                (on) => togglePermission(key, on)
                            "
                        />
                        {{ inviteLabels.permissionLabels[key] }}
                    </label>
                    <p
                        v-if="!userRole && !holds(key)"
                        :id="`${permissionsId}-${key}`"
                        class="type-caption ps-6 text-text-secondary"
                        data-test="permission-reason"
                    >
                        {{ labels.notHeldReason }}
                    </p>
                </div>
                <p
                    v-if="permissionsError"
                    class="type-caption text-error-text"
                    data-test="permissions-error"
                >
                    {{ permissionsError }}
                </p>
            </fieldset>

            <FormField
                v-if="needsPassword"
                :id="validation.fieldId('confirm_password')"
                :label="inviteLabels.confirmPassword"
                :helper="labels.passwordHelper"
                :error="validation.errors.confirm_password"
                required
                #default="{ field }"
            >
                <PasswordInput
                    v-bind="field"
                    v-model="confirmPassword"
                    name="confirm_password"
                    @update:model-value="edited"
                    autocomplete="current-password"
                    @blur="validation.onBlur('confirm_password')"
                />
            </FormField>

            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    :aria-busy="processing ? 'true' : undefined"
                    data-test="access-save"
                >
                    <Spinner v-if="processing" />
                    {{ labels.save }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    data-test="access-close"
                    @click="emit('close')"
                >
                    {{ labels.close }}
                </Button>
                <p
                    class="type-caption text-text-secondary"
                    data-test="access-status"
                >
                    {{ status }}
                </p>
            </div>
        </form>

        <ConfirmDialog
            v-model:open="confirming"
            :title="labels.downgradeTitle(name)"
            :description="labels.downgradeImpact(name)"
            :object-name="labels.downgradeObject(name)"
            :verb="labels.downgradeVerb"
            @confirm="send"
        />
    </section>
</template>
