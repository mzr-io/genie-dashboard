<script setup lang="ts">
import { nextTick, onMounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { createAttributeKey, UserAttributesError } from '@/lib/userAttributes';
import type { AttributeKey, AttributeValueType } from '@/lib/userAttributes';
import { SIGN_IN_URL } from '@/lib/session';
import { userAttributeLabels as labels } from '@/locales/labels';

// The inline add form of User attributes (Story 2.12; UX-DR-23, 274): it expands in the page, never as a modal. The key
// id and the type are chosen once (they never change); the label can be renamed later. Server field errors show with
// `aria-invalid` and `aria-describedby`, and focus moves to the first invalid field.
const emit = defineEmits<{ added: [key: AttributeKey]; close: [] }>();

const { t } = useI18n();
const keyIdId = useId();
const labelId = useId();
const typeId = useId();
const keyId = ref('');
const label = ref('');
const valueType = ref<AttributeValueType>('text');
const keyIdError = ref<string | null>(null);
const labelError = ref<string | null>(null);
const typeError = ref<string | null>(null);
const failure = ref<'save' | 'throttled' | null>(null);
const failureRef = ref<HTMLElement | null>(null);
const saving = ref(false);

function focusFirstInvalid(): void {
    const target =
        keyIdError.value !== null
            ? keyIdId
            : labelError.value !== null
              ? labelId
              : typeId;

    document.getElementById(target)?.focus();
}

function fieldError(
    error: UserAttributesError,
    field: string,
    invalid: string,
    taken: string,
): string | null {
    if (!error.errors[field]?.length) {
        return null;
    }

    return error.reasons[field] === 'duplicate' ? taken : invalid;
}

async function refused(error: unknown): Promise<void> {
    if (error instanceof UserAttributesError) {
        if (error.status === 401 || error.status === 419) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        if (error.status === 422 && Object.keys(error.errors).length > 0) {
            keyIdError.value = fieldError(
                error,
                'key_id',
                labels.keyIdInvalid,
                labels.keyIdTaken,
            );
            labelError.value = fieldError(
                error,
                'label',
                labels.labelInvalid,
                labels.labelTaken,
            );
            typeError.value = error.errors.value_type?.length
                ? labels.typeInvalid
                : null;

            if (keyIdError.value || labelError.value || typeError.value) {
                await nextTick();
                focusFirstInvalid();

                return;
            }
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

    keyIdError.value = null;
    labelError.value = null;
    typeError.value = null;
    failure.value = null;
    saving.value = true;

    try {
        emit(
            'added',
            await createAttributeKey({
                key_id: keyId.value,
                label: label.value,
                value_type: valueType.value,
            }),
        );
    } catch (error) {
        await refused(error);
    } finally {
        saving.value = false;
    }
}

function pickType(value: string): void {
    valueType.value =
        value === 'identifier' || value === 'integer' ? value : 'text';
    typeError.value = null;
}

// Expanding the form moves focus into it, to the key id.
onMounted(() => document.getElementById(keyIdId)?.focus());
</script>

<template>
    <section
        :aria-label="labels.addRegion"
        data-slot="add-attribute-form"
        class="rounded-lg border border-border-default bg-surface-card p-4 sm:p-6"
    >
        <h2 class="type-title-md mb-4 text-text-primary">
            {{ labels.addTitle }}
        </h2>
        <form
            class="grid max-w-md gap-4"
            novalidate
            data-test="add-attribute-form"
            @submit.prevent="submit"
        >
            <div
                v-if="failure"
                ref="failureRef"
                tabindex="-1"
                role="alert"
                class="rounded-md border-l-[3px] border-error bg-error-soft p-3"
                data-test="add-failure"
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
                :id="keyIdId"
                :label="labels.keyId"
                :helper="labels.keyIdHelper"
                :error="keyIdError"
                required
                #default="{ field }"
            >
                <Input
                    v-bind="field"
                    v-model="keyId"
                    type="text"
                    maxlength="48"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    data-test="add-attribute-key"
                    @input="keyIdError = null"
                />
            </FormField>
            <FormField
                :id="labelId"
                :label="labels.label"
                :helper="labels.labelHelper"
                :error="labelError"
                required
                #default="{ field }"
            >
                <Input
                    v-bind="field"
                    v-model="label"
                    type="text"
                    maxlength="64"
                    autocomplete="off"
                    data-test="add-attribute-label"
                    @input="labelError = null"
                />
            </FormField>
            <div class="grid gap-1.5">
                <span :id="typeId" class="type-label text-text-primary">{{
                    labels.type
                }}</span>
                <SegmentedControl
                    :model-value="valueType"
                    :label="labels.type"
                    :options="[
                        { value: 'text', label: labels.types.text },
                        { value: 'identifier', label: labels.types.identifier },
                        { value: 'integer', label: labels.types.integer },
                    ]"
                    data-test="add-attribute-type"
                    @update:model-value="pickType"
                />
                <p class="type-caption text-text-muted">
                    {{ labels.typeHelper }} {{ labels.typeHelpers[valueType] }}
                </p>
                <p
                    v-if="typeError"
                    class="type-caption text-error-text"
                    data-slot="field-error"
                >
                    {{ typeError }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    :disabled="saving"
                    data-test="add-attribute-save"
                >
                    {{ labels.save }}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    data-test="add-attribute-cancel"
                    @click="emit('close')"
                >
                    {{ labels.cancel }}
                </Button>
            </div>
        </form>
    </section>
</template>
