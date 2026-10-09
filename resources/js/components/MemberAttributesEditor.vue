<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { announce } from '@/lib/announce';
import type { Member } from '@/lib/members';
import { SIGN_IN_URL } from '@/lib/session';
import {
    fetchMemberAttributes,
    saveMemberAttributes,
    UserAttributesError,
} from '@/lib/userAttributes';
import type { MemberAttribute } from '@/lib/userAttributes';
import { userAttributeLabels as labels } from '@/locales/labels';
import { userAttributes as userAttributesPage } from '@/routes/admin/settings';

// The inline Attributes editor of a member row (Story 2.12; UX-DR-23, 254, 282): it expands in the table under the row,
// never as a modal, with one labelled input per defined attribute showing the member's current value. Save sends only the
// changed values; field errors carry `aria-invalid` and focus moves to the first invalid field; a save is announced
// politely. A value cannot be cleared once set (an empty value is refused by the server). Values live only in this
// component's state: never in the URL, a toast or a log.
const props = defineProps<{ member: Member }>();

const emit = defineEmits<{
    // The member is no longer in the Workspace: the page drops the row and reloads.
    gone: [];
    close: [];
}>();

const { t } = useI18n();
const name = computed(() => props.member.name || props.member.email);
const membershipId = computed(() => props.member.membership_id ?? '');

const state = ref<'loading' | 'error' | 'unavailable' | 'ready'>('loading');
const attributes = ref<MemberAttribute[]>([]);
const saved = ref<Record<string, string>>({});
const draft = ref<Record<string, string>>({});
const errors = ref<Record<string, string>>({});
const failure = ref<'save' | 'throttled' | 'unavailable' | 'self' | null>(null);
const failureRef = ref<HTMLElement | null>(null);
const heading = ref<HTMLElement | null>(null);
const root = ref<HTMLElement | null>(null);
const processing = ref(false);
const status = ref('');

let controller: AbortController | null = null;

const changed = computed(() =>
    attributes.value
        .map((attribute) => attribute.key_id)
        .filter(
            (keyId) =>
                (draft.value[keyId] ?? '') !== (saved.value[keyId] ?? ''),
        ),
);

function fieldId(keyId: string): string {
    return `member-attribute-${membershipId.value}-${keyId}`;
}

function show(rows: MemberAttribute[]): void {
    attributes.value = rows;
    saved.value = Object.fromEntries(
        rows.map((row) => [row.key_id, row.value ?? '']),
    );
    draft.value = { ...saved.value };
    errors.value = {};
}

function signedOut(error: unknown): boolean {
    if (
        error instanceof UserAttributesError &&
        (error.status === 401 || error.status === 419)
    ) {
        window.location.assign(SIGN_IN_URL);

        return true;
    }

    return false;
}

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const mine = controller;
    state.value = 'loading';

    try {
        const rows = await fetchMemberAttributes(
            membershipId.value,
            mine.signal,
        );

        if (mine.signal.aborted) {
            return;
        }

        show(rows);
        state.value = 'ready';
    } catch (error) {
        if (mine.signal.aborted || signedOut(error)) {
            return;
        }

        if (error instanceof UserAttributesError && error.status === 404) {
            emit('gone');

            return;
        }

        state.value =
            error instanceof UserAttributesError && error.status === 503
                ? 'unavailable'
                : 'error';
    }
}

function edited(keyId: string): void {
    status.value = '';
    failure.value = null;
    delete errors.value[keyId];
}

async function focusFailure(): Promise<void> {
    await nextTick();
    failureRef.value?.focus();
}

async function refused(error: unknown): Promise<void> {
    if (signedOut(error)) {
        return;
    }

    if (error instanceof UserAttributesError) {
        if (error.code === 'access.not_authorized') {
            window.location.reload();

            return;
        }

        if (error.status === 404) {
            emit('gone');

            return;
        }

        if (error.code === 'access.self_change_forbidden') {
            failure.value = 'self';
        } else if (error.status === 503) {
            failure.value = 'unavailable';
        } else if (
            error.status === 422 &&
            Object.keys(error.reasons).length > 0
        ) {
            errors.value = Object.fromEntries(
                Object.entries(error.reasons).map(([keyId, reason]) => [
                    keyId,
                    labels.memberReasons[reason] ??
                        labels.memberReasons.invalid,
                ]),
            );
            await nextTick();
            const first = attributes.value.find(
                (attribute) => errors.value[attribute.key_id],
            );

            document.getElementById(fieldId(first?.key_id ?? ''))?.focus();

            return;
        } else {
            failure.value = error.status === 429 ? 'throttled' : 'save';
        }
    } else {
        failure.value = 'save';
    }

    await focusFailure();
}

async function submit(): Promise<void> {
    if (processing.value) {
        return;
    }

    if (changed.value.length === 0) {
        status.value = labels.memberUnchanged;
        announce(labels.memberUnchanged, 'polite');

        return;
    }

    failure.value = null;
    status.value = '';
    errors.value = {};
    processing.value = true;

    try {
        const values = Object.fromEntries(
            changed.value.map((keyId) => [keyId, draft.value[keyId] ?? '']),
        );

        await saveMemberAttributes(membershipId.value, values);
        // Show what the server now holds (trimmed values), not what was typed.
        show(await fetchMemberAttributes(membershipId.value));
        status.value = `${t('saved')} ${labels.memberSaved(name.value)}`;
        announce(status.value, 'polite');
        await nextTick();
        root.value
            ?.querySelector<HTMLElement>('[data-test="attributes-save"]')
            ?.focus();
    } catch (error) {
        await refused(error);
    } finally {
        processing.value = false;
    }
}

onMounted(() => {
    heading.value?.focus();
    void load();
});

onBeforeUnmount(() => controller?.abort());

defineExpose({ focus: () => heading.value?.focus() });
</script>

<template>
    <section
        ref="root"
        :aria-label="labels.memberRegion(name)"
        data-slot="attributes-editor"
        class="grid max-w-xl gap-5"
    >
        <div class="grid gap-1">
            <h3
                ref="heading"
                tabindex="-1"
                class="type-title-md text-text-primary"
                data-test="attributes-title"
            >
                {{ labels.memberTitle(name) }}
            </h3>
            <p class="type-caption text-text-secondary">
                {{ labels.memberSubtitle }}
            </p>
        </div>

        <div
            v-if="state === 'loading'"
            role="status"
            aria-busy="true"
            class="grid gap-3"
            data-test="attributes-loading"
        >
            <span class="sr-only">{{ labels.memberLoading }}</span>
            <Skeleton v-for="n in 2" :key="n" class="h-9 w-full" />
        </div>

        <div
            v-else-if="state === 'error' || state === 'unavailable'"
            ref="failureRef"
            tabindex="-1"
            role="alert"
            class="rounded-md border-l-[3px] border-error bg-error-soft p-3"
            data-test="attributes-load-failure"
        >
            <p class="type-body-sm text-error-text">
                {{
                    state === 'unavailable'
                        ? labels.memberUnavailable
                        : t('save-failed.form')
                }}
            </p>
            <Button
                v-if="state === 'error'"
                type="button"
                variant="secondary"
                size="sm"
                class="mt-2"
                data-test="attributes-retry"
                @click="load"
            >
                {{ labels.retry }}
            </Button>
        </div>

        <p
            v-else-if="attributes.length === 0"
            class="type-body-sm text-text-secondary"
            data-test="attributes-none"
        >
            {{ labels.memberNone }}
            <Link
                :href="userAttributesPage().url"
                class="underline underline-offset-4"
                >{{ labels.memberNoneAction }}</Link
            >
        </p>

        <form
            v-else
            class="grid gap-5"
            novalidate
            data-test="attributes-form"
            @submit.prevent="submit"
        >
            <div
                v-if="failure"
                ref="failureRef"
                tabindex="-1"
                role="alert"
                class="rounded-md border-l-[3px] border-error bg-error-soft p-3"
                data-test="attributes-failure"
            >
                <p class="type-body-sm text-error-text">
                    <template v-if="failure === 'unavailable'">{{
                        labels.memberUnavailable
                    }}</template>
                    <template v-else-if="failure === 'self'">{{
                        labels.memberSelf
                    }}</template>
                    <template v-else-if="failure === 'throttled'">{{
                        t('throttled')
                    }}</template>
                    <template v-else>{{ t('save-failed.form') }}</template>
                </p>
            </div>

            <FormField
                v-for="attribute in attributes"
                :id="fieldId(attribute.key_id)"
                :key="attribute.key_id"
                :label="attribute.label"
                :helper="
                    labels.memberHelper(labels.types[attribute.value_type])
                "
                :error="errors[attribute.key_id] ?? null"
                #default="{ field }"
            >
                <Input
                    v-bind="field"
                    v-model="draft[attribute.key_id]"
                    type="text"
                    :inputmode="
                        attribute.value_type === 'integer' ? 'numeric' : 'text'
                    "
                    :placeholder="
                        saved[attribute.key_id]
                            ? undefined
                            : labels.memberNotSet
                    "
                    maxlength="300"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    :data-attribute="attribute.key_id"
                    @input="edited(attribute.key_id)"
                />
            </FormField>

            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    variant="secondary"
                    :aria-busy="processing ? 'true' : undefined"
                    data-test="attributes-save"
                >
                    <Spinner v-if="processing" />
                    {{ labels.memberSave }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    data-test="attributes-close"
                    @click="emit('close')"
                >
                    {{ labels.memberClose }}
                </Button>
                <p
                    class="type-caption text-text-secondary"
                    data-test="attributes-status"
                >
                    {{ status }}
                </p>
            </div>
        </form>

        <Button
            v-if="state !== 'ready' || attributes.length === 0"
            type="button"
            variant="ghost"
            class="justify-self-start"
            data-test="attributes-close"
            @click="emit('close')"
        >
            {{ labels.memberClose }}
        </Button>
    </section>
</template>
