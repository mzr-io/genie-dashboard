<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Banner from '@/components/Banner.vue';
import BlockedReason from '@/components/BlockedReason.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EndpointBindingRows from '@/components/EndpointBindingRows.vue';
import type { EditableRow } from '@/components/EndpointBindingRows.vue';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import FormField from '@/components/FormField.vue';
import RequiredNote from '@/components/RequiredNote.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { announce } from '@/lib/announce';
import {
    createEndpoint,
    EndpointError,
    pathPlaceholders,
    updateEndpoint,
} from '@/lib/endpoints';
import type {
    BindingRow,
    Endpoint,
    EndpointInput,
    Method,
} from '@/lib/endpoints';
import { SIGN_IN_URL } from '@/lib/session';
import { registerUnsavedForm } from '@/lib/unsavedForms';
import { endpointLabels as labels } from '@/locales/labels';

// Add and edit an Endpoint (Story 2.9; UX-DR-134, 27, 28, 23, 26, 22, 37): the Endpoint field (a `method-prefix` GET or POST
// segment joined to a mono path input), the `parameters-table`, header rows and, for a POST, the body template. `novalidate`:
// the server's rules show as field errors (`aria-invalid`, `aria-describedby`) with focus on the first invalid field and
// a summary of links for two or more. Choosing POST shows the required `post-readonly` checkbox: Save is `aria-disabled`
// with its reason beside it until the box is ticked, and ticking asks for a risk confirmation first. Saving never sends a
// request to the data source. An edit sends the Endpoint's `revision`; a stale one keeps what was typed (409).
const props = defineProps<{
    dataSourceId: string;
    // The Endpoint being edited; null to add one.
    endpoint: Endpoint | null;
}>();

const emit = defineEmits<{ saved: [endpoint: Endpoint]; cancel: [] }>();

const { t } = useI18n();
const prefix = useId();
const fieldId = (name: string): string =>
    `${prefix}-${name.replace(/\./g, '-')}`;

// Reasons whose server message names the parameter: the message is more useful than the generic text.
const NAMED = new Set([
    'path-param-missing',
    'param-value-invalid',
    'body-template-param-unknown',
]);

let nextKey = 1;
const toRows = (rows: BindingRow[]): EditableRow[] =>
    rows.map((row) => ({
        key: nextKey++,
        name: row.name,
        binding: row.binding,
        value: row.value ?? '',
    }));

const form = reactive({
    method: (props.endpoint?.method ?? 'GET') as Method,
    path: props.endpoint?.path ?? '',
    params: toRows(props.endpoint?.params ?? []),
    headers: toRows(props.endpoint?.headers ?? []),
    body: props.endpoint?.body_template ?? '',
    // The required `post-readonly` checkbox. An Endpoint saved as a read-only query was confirmed when it was saved.
    readOnly: props.endpoint?.read_only_query ?? false,
});
const revision = ref(props.endpoint?.revision ?? 0);
const errors = reactive<Record<string, string | null>>({});
const failure = ref<
    'save' | 'throttled' | 'stale' | 'forbidden' | 'missing' | null
>(null);
// A refusal that retrying cannot change: no permission, or the Endpoint is gone.
const terminal = computed(
    () => failure.value === 'forbidden' || failure.value === 'missing',
);
const discardOpen = ref(false);
const discardInvoker = ref<HTMLElement | null>(null);
const showSummary = ref(false);
const summaryRef = ref<InstanceType<typeof FormErrorSummary> | null>(null);
const saving = ref(false);
const riskOpen = ref(false);
const riskInvoker = ref<HTMLElement | null>(null);
const saveReasonId = useId();
const rootRef = ref<HTMLElement | null>(null);
const staleCurrent = ref<Endpoint | null>(null);

const isPost = computed(() => form.method === 'POST');
const saveReason = computed(() =>
    isPost.value && !form.readOnly ? labels.postReadonlyBlocked : undefined,
);

function snapshot(): string {
    return JSON.stringify([
        form.method,
        form.path,
        form.params.map((r) => [r.name, r.binding, r.value]),
        form.headers.map((r) => [r.name, r.binding, r.value]),
        form.body,
        form.readOnly,
    ]);
}

let baseline = snapshot();
const stopUnsaved = registerUnsavedForm({
    id: 'endpoint-form',
    isDirty: () => snapshot() !== baseline,
});

onBeforeUnmount(stopUnsaved);

// A `{name}` in the path gets a parameter row of its own, so the person only has to choose its binding. This runs when the
// path field is left (and on save), not on every key, so `{i}` on the way to `{id}` leaves nothing behind; an added row that
// is still untouched goes when its placeholder does.
function syncPlaceholders(): void {
    const names = pathPlaceholders(form.path);

    form.params = form.params.filter(
        (row) => !(row.auto && !names.includes(row.name)),
    );

    for (const name of names) {
        if (!form.params.some((row) => row.name === name)) {
            form.params.push({
                key: nextKey++,
                name,
                binding: 'fixed',
                value: '',
                auto: true,
            });
        }
    }
}

// Any edit of an added row makes it the person's own.
function edited(field: string): void {
    clear(field);
    const row = /^params\.(\d+)\./.exec(field);

    if (row && form.params[Number(row[1])]) {
        form.params[Number(row[1])].auto = false;
    }
}

function clear(field: string): void {
    errors[field] = null;
}

function addRow(kind: 'params' | 'headers'): void {
    form[kind].push({ key: nextKey++, name: '', binding: 'fixed', value: '' });
    void nextTick(() =>
        document
            .getElementById(`${prefix}-${kind}-${form[kind].length - 1}-name`)
            ?.focus(),
    );
}

function removeRow(kind: 'params' | 'headers', index: number): void {
    form[kind].splice(index, 1);

    for (const key of Object.keys(errors)) {
        if (key.startsWith(`${kind}.`)) {
            errors[key] = null;
        }
    }

    announce(
        kind === 'params'
            ? labels.paramRemoved(index + 1)
            : labels.headerRemoved(index + 1),
    );
    const next =
        form[kind].length === 0
            ? `${prefix}-${kind}-add`
            : `${prefix}-${kind}-${Math.min(index, form[kind].length - 1)}-name`;

    void nextTick(() => document.getElementById(next)?.focus());
}

// Ticking the POST checkbox asks first; unticking needs no question.
function onTick(value: boolean | 'indeterminate'): void {
    if (value === true) {
        riskInvoker.value = document.getElementById(fieldId('read_only_query'));
        riskOpen.value = true;

        return;
    }

    form.readOnly = false;
}

function confirmRisk(): void {
    form.readOnly = true;
    clear('read_only_query');
    clear('confirm_read_only');
}

function input(): EndpointInput {
    const row = (r: EditableRow): BindingRow => ({
        name: r.name,
        binding: r.binding,
        value: r.binding === 'fixed' ? r.value : null,
    });
    const post = isPost.value;

    return {
        method: form.method,
        path: form.path,
        params: form.params.map(row),
        headers: form.headers.map(row),
        body_template: post && form.body.trim() !== '' ? form.body : null,
        read_only_query: post && form.readOnly,
        confirm_read_only: post && form.readOnly,
    };
}

const FIELD_ORDER = ['method', 'path'];

function rank(field: string): number {
    const fixed = FIELD_ORDER.indexOf(field);

    if (fixed >= 0) {
        return fixed;
    }

    const row = /^(params|headers)\.(\d+)\.(name|binding|value)$/.exec(field);

    if (row) {
        return (
            10 +
            (row[1] === 'headers' ? 1000 : 0) +
            Number(row[2]) * 3 +
            ['name', 'binding', 'value'].indexOf(row[3])
        );
    }

    return (
        {
            params: 9,
            headers: 999,
            body_template: 3000,
            read_only_query: 3001,
            confirm_read_only: 3002,
        }[field] ?? 4000
    );
}

function elementFor(field: string): string {
    const row = /^(params|headers)\.(\d+)\.(name|binding|value)$/.exec(field);

    if (row) {
        return `${prefix}-${row[1]}-${row[2]}-${row[3]}`;
    }

    return fieldId(
        {
            params: 'params-add',
            headers: 'headers-add',
            confirm_read_only: 'read_only_query',
            body_template: 'body',
        }[field] ?? field,
    );
}

function labelFor(field: string): string {
    const row = /^(params|headers)\.(\d+)\.(name|binding|value)$/.exec(field);

    if (row) {
        const n = Number(row[2]) + 1;
        const names =
            row[1] === 'params'
                ? {
                      name: labels.paramName,
                      binding: labels.paramBinding,
                      value: labels.paramValue,
                  }
                : {
                      name: labels.headerName,
                      binding: labels.headerBinding,
                      value: labels.headerValue,
                  };

        return names[row[3] as 'name' | 'binding' | 'value'](n);
    }

    return (
        {
            method: labels.methodLabel,
            path: labels.pathLabel,
            params: labels.parameters,
            headers: labels.headers,
            body_template: labels.body,
            read_only_query: t('post-readonly.label'),
            confirm_read_only: t('post-readonly.label'),
        }[field] ?? field
    );
}

const invalid = computed(() =>
    Object.keys(errors)
        .filter((field) => errors[field])
        .sort((a, b) => rank(a) - rank(b)),
);
const summaryItems = computed(() =>
    invalid.value.map((field) => ({
        id: elementFor(field),
        label: labelFor(field),
        message: errors[field] as string,
    })),
);

function focusField(id: string): void {
    const element = document.getElementById(id);

    if (!element) {
        return;
    }

    // The method is a radio group: focus the segment that is checked.
    const target =
        element.getAttribute('role') === 'radiogroup'
            ? (element.querySelector<HTMLElement>('[aria-checked="true"]') ??
              element.querySelector<HTMLElement>('[role="radio"]') ??
              element)
            : element;

    target.focus();
}

async function showErrors(): Promise<void> {
    showSummary.value = invalid.value.length >= 2;
    await nextTick();

    if (showSummary.value) {
        summaryRef.value?.focus();
    } else if (invalid.value[0]) {
        focusField(elementFor(invalid.value[0]));
    }
}

function applyErrors(error: EndpointError): void {
    for (const key of Object.keys(errors)) {
        errors[key] = null;
    }

    for (const [field, messages] of Object.entries(error.errors)) {
        const reason = error.reasons[field];
        const own = reason ? labels.reasons[reason] : undefined;

        errors[field] =
            reason && NAMED.has(reason)
                ? (messages[0] ?? own ?? null)
                : (own ?? messages[0] ?? null);
    }
}

async function submit(): Promise<void> {
    if (saving.value || saveReason.value) {
        return;
    }

    syncPlaceholders();
    saving.value = true;
    failure.value = null;
    staleCurrent.value = null;

    try {
        const saved = props.endpoint
            ? await updateEndpoint(
                  props.dataSourceId,
                  props.endpoint.endpoint_id,
                  input(),
                  revision.value,
              )
            : await createEndpoint(props.dataSourceId, input());

        baseline = snapshot();
        emit('saved', saved);
    } catch (error) {
        await fail(error);
    } finally {
        saving.value = false;
    }
}

async function fail(error: unknown): Promise<void> {
    if (!(error instanceof EndpointError)) {
        failure.value = 'save';

        return;
    }

    if (error.status === 401 || error.status === 419) {
        window.location.assign(SIGN_IN_URL);

        return;
    }

    if (error.status === 422) {
        applyErrors(error);
        await showErrors();

        return;
    }

    if (error.status === 409 && error.current) {
        // What was typed stays; the next save is decided against the latest revision.
        staleCurrent.value = error.current;
        revision.value = error.current.revision;
        failure.value = 'stale';
    } else if (error.status === 403 || error.status === 404) {
        failure.value = error.status === 403 ? 'forbidden' : 'missing';
    } else {
        failure.value = error.status === 429 ? 'throttled' : 'save';
    }

    await nextTick();
    rootRef.value?.querySelector<HTMLElement>('[data-test="failure"]')?.focus();
}

// Leaving with typed work asks first.
function cancel(): void {
    if (snapshot() === baseline) {
        emit('cancel');

        return;
    }

    discardInvoker.value = document.querySelector<HTMLElement>(
        '[data-test="cancel"]',
    );
    discardOpen.value = true;
}

function reloadLatest(): void {
    const current = staleCurrent.value;

    if (!current) {
        return;
    }

    form.method = current.method;
    form.path = current.path;
    form.params = toRows(current.params);
    form.headers = toRows(current.headers);
    form.body = current.body_template ?? '';
    form.readOnly = current.read_only_query;
    revision.value = current.revision;
    baseline = snapshot();
    failure.value = null;
    staleCurrent.value = null;
    announce(labels.reloaded);
}
</script>

<template>
    <form
        ref="rootRef"
        novalidate
        class="grid max-w-3xl gap-6"
        :aria-label="labels.formRegion"
        data-test="endpoint-form"
        @submit.prevent="submit"
    >
        <section
            v-if="failure"
            tabindex="-1"
            data-test="failure"
            role="alert"
            class="grid gap-3"
        >
            <Banner variant="error">
                {{
                    failure === 'stale'
                        ? labels.conflict
                        : failure === 'forbidden'
                          ? t('perm-denied')
                          : failure === 'missing'
                            ? labels.endpointGone
                            : failure === 'throttled'
                              ? labels.throttled
                              : labels.saveFailed
                }}
            </Banner>
            <div v-if="failure === 'stale'">
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    data-test="reload-latest"
                    @click="reloadLatest"
                >
                    {{ labels.reload }}
                </Button>
            </div>
        </section>

        <FormErrorSummary
            v-if="showSummary"
            ref="summaryRef"
            :items="summaryItems"
        />

        <RequiredNote />

        <fieldset class="grid gap-4">
            <legend class="type-title-md mb-2 text-text-primary">
                {{ labels.request }}
            </legend>
            <FormField
                :id="fieldId('path')"
                :label="labels.endpoint"
                :helper="labels.endpointHelper"
                :error="errors.method ?? errors.path"
                required
                #default="{ field }"
            >
                <!-- The method-prefix: the GET or POST segment joined to the mono path input. -->
                <div class="flex items-stretch" data-slot="method-prefix">
                    <SegmentedControl
                        :id="fieldId('method')"
                        v-model="form.method"
                        :label="labels.methodLabel"
                        :options="labels.methodOptions"
                        class="shrink-0"
                        :aria-invalid="errors.method ? 'true' : undefined"
                        data-test="method"
                        @update:model-value="clear('method')"
                    />
                    <Input
                        v-bind="field"
                        v-model="form.path"
                        name="path"
                        type="text"
                        maxlength="1100"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        :placeholder="labels.pathPlaceholder"
                        class="min-w-0 flex-1 rounded-s-none font-mono"
                        data-test="path"
                        @input="clear('path')"
                        @blur="syncPlaceholders"
                    />
                </div>
            </FormField>
        </fieldset>

        <fieldset class="grid gap-3">
            <legend class="type-title-md mb-1 text-text-primary">
                {{ labels.parameters }}
            </legend>
            <p class="type-caption text-text-muted">
                {{ labels.parametersHelper }}
            </p>
            <EndpointBindingRows
                kind="params"
                :rows="form.params"
                :errors="errors"
                :id-prefix="prefix"
                :caption="labels.parameters"
                :empty="labels.parametersNone"
                :add-label="labels.addParameter"
                @add="addRow('params')"
                @remove="(i) => removeRow('params', i)"
                @edited="edited"
            />
            <p
                v-if="errors.params"
                class="type-caption text-error-text"
                data-slot="field-error"
            >
                {{ errors.params }}
            </p>
        </fieldset>

        <fieldset class="grid gap-3">
            <legend class="type-title-md mb-1 text-text-primary">
                {{ labels.headers }}
            </legend>
            <p class="type-caption text-text-muted">
                {{ labels.headersHelper }}
            </p>
            <EndpointBindingRows
                kind="headers"
                :rows="form.headers"
                :errors="errors"
                :id-prefix="prefix"
                :caption="labels.headers"
                :empty="labels.headersNone"
                :add-label="labels.addHeader"
                @add="addRow('headers')"
                @remove="(i) => removeRow('headers', i)"
                @edited="edited"
            />
            <p
                v-if="errors.headers"
                class="type-caption text-error-text"
                data-slot="field-error"
            >
                {{ errors.headers }}
            </p>
        </fieldset>

        <template v-if="isPost">
            <FormField
                :id="fieldId('body')"
                :label="labels.body"
                :helper="labels.bodyHelper"
                :error="errors.body_template"
                #default="{ field }"
            >
                <Textarea
                    v-bind="field"
                    v-model="form.body"
                    name="body_template"
                    rows="6"
                    spellcheck="false"
                    autocapitalize="off"
                    :placeholder="labels.bodyPlaceholder"
                    class="font-mono"
                    data-test="body-template"
                    @input="clear('body_template')"
                />
            </FormField>

            <!-- The required `post-readonly` checkbox (UX-DR-134): Save stays `aria-disabled` until it is ticked. -->
            <div class="grid gap-1.5" data-slot="post-readonly">
                <div class="flex items-start gap-2">
                    <Checkbox
                        :id="fieldId('read_only_query')"
                        :model-value="form.readOnly"
                        required
                        :aria-invalid="
                            errors.read_only_query || errors.confirm_read_only
                                ? 'true'
                                : undefined
                        "
                        :aria-describedby="`${fieldId('read_only_query')}-hint`"
                        class="mt-1"
                        data-test="post-readonly"
                        @update:model-value="onTick"
                    />
                    <Label :for="fieldId('read_only_query')">
                        {{ t('post-readonly.label') }}
                        <span aria-hidden="true" class="text-error-text"
                            >*</span
                        >
                    </Label>
                </div>
                <p
                    :id="`${fieldId('read_only_query')}-hint`"
                    class="type-caption text-text-muted"
                >
                    {{ t('post-readonly.hint') }}
                </p>
                <p
                    v-if="errors.read_only_query || errors.confirm_read_only"
                    class="type-caption text-error-text"
                    data-slot="field-error"
                >
                    {{ errors.read_only_query ?? errors.confirm_read_only }}
                </p>
            </div>
        </template>

        <div class="grid gap-2">
            <div class="flex flex-wrap items-center gap-3">
                <Button
                    type="submit"
                    :disabled="saving || terminal"
                    :blocked="saveReason !== undefined"
                    :blocked-reason="saveReason"
                    :aria-describedby="saveReason ? saveReasonId : undefined"
                    data-test="save"
                >
                    {{ saving ? labels.saving : labels.save }}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    data-test="cancel"
                    @click="cancel"
                >
                    {{ labels.cancel }}
                </Button>
            </div>
            <BlockedReason v-if="saveReason" :id="saveReasonId">{{
                saveReason
            }}</BlockedReason>
        </div>

        <ConfirmDialog
            v-model:open="discardOpen"
            :title="labels.discardTitle"
            :description="t('unsaved-changes')"
            :object-name="labels.discardObject"
            :verb="labels.discardVerb"
            :invoker="discardInvoker"
            @confirm="emit('cancel')"
        />

        <ConfirmDialog
            v-model:open="riskOpen"
            :title="labels.riskTitle"
            :description="labels.riskDescription"
            :object-name="`${form.method} ${form.path}`"
            :verb="labels.riskConfirm"
            :invoker="riskInvoker"
            @confirm="confirmRisk"
        />
    </form>
</template>
