<script setup lang="ts">
import { nextTick, onMounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { createGroup, GroupRequestError } from '@/lib/groups';
import type { Group } from '@/lib/groups';
import { SIGN_IN_URL } from '@/lib/session';
import { groupLabels as labels } from '@/locales/labels';

// The inline create form of the Groups view (Story 1.23; UX-DR-23, 274): it expands in the page, never as a modal. A
// name is trimmed and checked here (required, 64 characters at most); the server's refusal of a name another group
// already has comes back as a field error with focus on the field. Success hands the new group to the page.
const emit = defineEmits<{ created: [group: Group]; close: [] }>();

const { t } = useI18n();
const nameId = useId();
const name = ref('');
const nameError = ref<string | null>(null);
const failure = ref<'save' | 'throttled' | null>(null);
const failureRef = ref<HTMLElement | null>(null);
const saving = ref(false);

async function refused(error: unknown): Promise<void> {
    if (error instanceof GroupRequestError) {
        if (error.status === 401 || error.status === 419) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        if (error.status === 422 && error.errors.name?.length) {
            nameError.value =
                error.reason === 'name_taken'
                    ? labels.nameTaken
                    : labels.nameInvalid;
            await nextTick();
            document.getElementById(nameId)?.focus();

            return;
        }

        failure.value = error.status === 429 ? 'throttled' : 'save';
    } else {
        failure.value = 'save';
    }

    await nextTick();
    failureRef.value?.focus();
}

async function submit(): Promise<void> {
    if (saving.value) {
        return;
    }

    const trimmed = name.value.trim();

    nameError.value =
        trimmed === ''
            ? labels.nameRequired
            : [...trimmed].length > 64
              ? labels.nameTooLong
              : null;
    failure.value = null;

    if (nameError.value !== null) {
        document.getElementById(nameId)?.focus();

        return;
    }

    saving.value = true;

    try {
        emit('created', await createGroup(trimmed));
    } catch (error) {
        await refused(error);
    } finally {
        saving.value = false;
    }
}

// Expanding the form moves focus into it, to the name.
onMounted(() => document.getElementById(nameId)?.focus());
</script>

<template>
    <section
        :aria-label="labels.createRegion"
        data-slot="create-group-form"
        class="rounded-lg border border-border-default bg-surface-card p-4 sm:p-6"
    >
        <h2 class="type-title-md mb-4 text-text-primary">
            {{ labels.createTitle }}
        </h2>
        <form
            class="grid max-w-md gap-4"
            novalidate
            data-test="create-group-form"
            @submit.prevent="submit"
        >
            <div
                v-if="failure"
                ref="failureRef"
                tabindex="-1"
                role="alert"
                class="rounded-md border-l-[3px] border-error bg-error-soft p-3"
                data-test="create-failure"
            >
                <p class="type-body-sm text-error-text">
                    {{
                        failure === 'throttled'
                            ? t('throttled')
                            : t('save-failed.form')
                    }}
                </p>
            </div>
            <FormField
                :id="nameId"
                :label="labels.name"
                :helper="labels.nameHelper"
                :error="nameError"
                required
                #default="{ field }"
            >
                <Input
                    v-bind="field"
                    v-model="name"
                    type="text"
                    maxlength="64"
                    autocomplete="off"
                    data-test="create-group-name"
                    @input="nameError = null"
                />
            </FormField>
            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    :disabled="saving"
                    data-test="create-group-save"
                >
                    {{ labels.create }}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    data-test="create-group-cancel"
                    @click="emit('close')"
                >
                    {{ labels.cancel }}
                </Button>
            </div>
        </form>
    </section>
</template>
