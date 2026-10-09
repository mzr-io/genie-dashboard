<script setup lang="ts">
import { nextTick, onMounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/FormField.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { addHost, HostAllowlistError } from '@/lib/hostAllowlist';
import type {
    AddedHost,
    HostAllowlistPage,
    HostScheme,
} from '@/lib/hostAllowlist';
import { SIGN_IN_URL } from '@/lib/session';
import { hostAllowlistLabels as labels } from '@/locales/labels';

// The inline add form of the Host allowlist (Story 2.1; UX-DR-23, 262, 274): it expands in the page, never as a modal.
// Every host rule is the server's: this form sends exactly what was typed (nothing is trimmed) and shows the server's
// reason as a field error with `aria-invalid` and `aria-describedby`, moving focus to the first invalid field. A stale
// list (409) hands the fresh list to the page and keeps the typed value here.
const props = defineProps<{ revision: number }>();

const emit = defineEmits<{
    added: [result: AddedHost];
    // The list changed under the Admin: the page shows this list; what was typed stays in the form.
    stale: [current: HostAllowlistPage];
    close: [];
}>();

const { t } = useI18n();
const hostId = useId();
const schemeId = useId();
const host = ref('');
const scheme = ref<HostScheme>('https');
const hostError = ref<string | null>(null);
const schemeError = ref<string | null>(null);
const failure = ref<'save' | 'throttled' | 'stale' | null>(null);
const failureRef = ref<HTMLElement | null>(null);
const saving = ref(false);

function focusFirstInvalid(): void {
    const target = hostError.value !== null ? hostId : schemeId;

    document.getElementById(target)?.focus();
}

async function refused(error: unknown): Promise<void> {
    if (error instanceof HostAllowlistError) {
        if (error.status === 401 || error.status === 419) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        if (error.status === 409 && error.current) {
            failure.value = 'stale';
            emit('stale', error.current);
            await nextTick();
            failureRef.value?.focus();

            return;
        }

        if (error.status === 422 && Object.keys(error.errors).length > 0) {
            hostError.value = error.errors.host?.length
                ? (labels.reasons[error.reasons.host] ?? labels.hostInvalid)
                : null;
            schemeError.value = error.errors.scheme?.length
                ? labels.reasons.invalid_scheme
                : null;

            if (hostError.value === null && schemeError.value === null) {
                failure.value = 'save';
                await nextTick();
                failureRef.value?.focus();

                return;
            }

            await nextTick();
            focusFirstInvalid();

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

    hostError.value = null;
    schemeError.value = null;
    failure.value = null;
    saving.value = true;

    try {
        emit(
            'added',
            await addHost({
                host: host.value,
                scheme: scheme.value,
                revision: props.revision,
            }),
        );
    } catch (error) {
        await refused(error);
    } finally {
        saving.value = false;
    }
}

function pickScheme(value: string): void {
    scheme.value = value === 'http' ? 'http' : 'https';
    schemeError.value = null;
}

// Expanding the form moves focus into it, to the host.
onMounted(() => document.getElementById(hostId)?.focus());
</script>

<template>
    <section
        :aria-label="labels.addRegion"
        data-slot="add-host-form"
        class="rounded-lg border border-border-default bg-surface-card p-4 sm:p-6"
    >
        <h2 class="type-title-md mb-4 text-text-primary">
            {{ labels.addTitle }}
        </h2>
        <form
            class="grid max-w-md gap-4"
            novalidate
            data-test="add-host-form"
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
                        failure === 'stale'
                            ? labels.conflict
                            : failure === 'throttled'
                              ? t('throttled')
                              : t('save-failed.form')
                    }}
                </p>
            </div>
            <FormField
                :id="hostId"
                :label="labels.host"
                :helper="labels.hostHelper"
                :error="hostError"
                required
                #default="{ field }"
            >
                <Input
                    v-bind="field"
                    v-model="host"
                    type="text"
                    maxlength="300"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    data-test="add-host-name"
                    @input="hostError = null"
                />
            </FormField>
            <div class="grid gap-1.5">
                <span :id="schemeId" class="type-label text-text-primary">{{
                    labels.scheme
                }}</span>
                <SegmentedControl
                    :model-value="scheme"
                    :label="labels.scheme"
                    :options="[
                        { value: 'https', label: labels.schemeHttps },
                        { value: 'http', label: labels.schemeHttp },
                    ]"
                    data-test="add-host-scheme"
                    @update:model-value="pickScheme"
                />
                <p class="type-caption text-text-muted">
                    {{ labels.schemeHelper }}
                </p>
                <p
                    v-if="scheme === 'http'"
                    class="type-caption text-text-secondary"
                    data-test="scheme-notice"
                >
                    {{ labels.schemeNotice }}
                </p>
                <p
                    v-if="schemeError"
                    class="type-caption text-error-text"
                    data-slot="field-error"
                >
                    {{ schemeError }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    :disabled="saving"
                    data-test="add-host-save"
                >
                    {{ labels.save }}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    data-test="add-host-cancel"
                    @click="emit('close')"
                >
                    {{ labels.cancel }}
                </Button>
            </div>
        </form>
    </section>
</template>
