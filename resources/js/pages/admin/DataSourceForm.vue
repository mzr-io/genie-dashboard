<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    useId,
} from 'vue';
import { useI18n } from 'vue-i18n';
import BlockedReason from '@/components/BlockedReason.vue';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import RequiredNote from '@/components/RequiredNote.vue';
import TechnicalDetails from '@/components/TechnicalDetails.vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { announce } from '@/lib/announce';
import {
    checkBaseUrl,
    createDataSource,
    DataSourceError,
    fetchDataSource,
    updateDataSource,
} from '@/lib/dataSources';
import type {
    Ceilings,
    DataSource,
    DataSourceInput,
    OneDataSource,
} from '@/lib/dataSources';
import { SIGN_IN_URL } from '@/lib/session';
import { registerUnsavedForm } from '@/lib/unsavedForms';
import { dataSourceLabels as labels, shellLabels } from '@/locales/labels';
import { index } from '@/routes/admin/data-sources';

// Register and edit a Data Source (Story 2.3; UX-DR-207, 23, 26, 22, 37, 39, 274, 282). One form for both: `novalidate`,
// the server's rules shown as field errors (`aria-invalid`, `aria-describedby`) with focus on the first invalid field
// and a summary of links for two or more. The edit form loads the saved values first (skeletons and a disabled Save;
// a failure shows "We couldn't load these settings. Try again." with Retry and never stale values). Leaving the form
// with unsaved edits asks first. The Base URL is checked against the host allowlist on blur: nothing shows on success;
// a miss shows `host-not-allowlisted` inline and Save is `aria-disabled` with its reason beside it.
const props = defineProps<{ dataSourceId?: string | null }>();

const { t } = useI18n();
const editing = computed(() => !!props.dataSourceId);

type Row = { key: number; name: string; value: string };
type LoadState = 'loading' | 'error' | 'forbidden' | 'missing' | 'ready';

const prefix = useId();
const fieldId = (name: string): string => `${prefix}-${name}`;
const state = ref<LoadState>(editing.value ? 'loading' : 'ready');
const original = ref<DataSource | null>(null);
const revision = ref(0);
const ceilings = ref<Ceilings>({
    timeout_seconds: null,
    max_response_bytes: null,
    max_pages: null,
});

let nextRowKey = 1;
const form = reactive({
    name: '',
    base_url: '',
    headers: [] as Row[],
    timeout_seconds: '',
    max_response_bytes: '',
    max_pages: '',
    live_capable: false,
});
let baseline = snapshot();

const errors = reactive<Record<string, string | null>>({});
const urlRequestId = ref<string | null>(null);
// The blur check refused the Base URL: Save stays `aria-disabled` until it is changed and accepted.
const urlBlock = ref<'host' | 'url' | null>(null);
const urlChecking = ref(false);
const failure = ref<'save' | 'throttled' | 'stale' | null>(null);
const failureRef = ref<HTMLElement | null>(null);
const summaryRef = ref<InstanceType<typeof FormErrorSummary> | null>(null);
const showSummary = ref(false);
const saving = ref(false);
const saveReasonId = useId();
const leaveOpen = ref(false);
const technicalStatus = ref<number | null>(null);

let checkController: AbortController | null = null;
let leaveTarget: string | null = null;
let leaving = false;

function snapshot(): string {
    return JSON.stringify([
        form.name,
        form.base_url,
        form.headers.map((row) => [row.name, row.value]),
        form.timeout_seconds,
        form.max_response_bytes,
        form.max_pages,
        form.live_capable,
    ]);
}

const dirty = computed(
    () => state.value === 'ready' && !saving.value && snapshot() !== baseline,
);

function fill(source: DataSource): void {
    form.name = source.name;
    form.base_url = source.base_url;
    form.headers = source.headers.map((header) => ({
        key: nextRowKey++,
        name: header.name,
        value: header.value,
    }));
    form.timeout_seconds = source.timeout_seconds?.toString() ?? '';
    form.max_response_bytes = source.max_response_bytes?.toString() ?? '';
    form.max_pages = source.max_pages?.toString() ?? '';
    form.live_capable = source.live_capable;
    original.value = source;
    revision.value = source.revision;
    baseline = snapshot();
}

function clearErrors(): void {
    for (const key of Object.keys(errors)) {
        errors[key] = null;
    }

    showSummary.value = false;
    failure.value = null;
}

function authExpired(error: unknown): boolean {
    if (
        error instanceof DataSourceError &&
        (error.status === 401 || error.status === 419)
    ) {
        window.location.assign(SIGN_IN_URL);

        return true;
    }

    return false;
}

async function load(): Promise<void> {
    if (!props.dataSourceId) {
        return;
    }

    state.value = 'loading';

    try {
        const one: OneDataSource = await fetchDataSource(props.dataSourceId);

        fill(one.data);
        ceilings.value = one.meta.ceilings;
        state.value = 'ready';
    } catch (error) {
        if (authExpired(error)) {
            return;
        }

        // Never stale values: nothing is filled on a failure.
        state.value =
            error instanceof DataSourceError && error.status === 403
                ? 'forbidden'
                : error instanceof DataSourceError && error.status === 404
                  ? 'missing'
                  : 'error';
    }
}

// ---- Rows -------------------------------------------------------------------------------------------------------

function addHeader(): void {
    form.headers.push({ key: nextRowKey++, name: '', value: '' });
    const n = form.headers.length - 1;

    void nextTick(() =>
        document.getElementById(fieldId(`headers.${n}.name`))?.focus(),
    );
}

function removeHeader(position: number): void {
    form.headers.splice(position, 1);

    for (const key of Object.keys(errors)) {
        if (key.startsWith('headers.')) {
            errors[key] = null;
        }
    }

    announce(labels.headerRemoved(position + 1));
    void nextTick(() =>
        (
            document.getElementById(fieldId('add-header')) ??
            document.getElementById(fieldId('name'))
        )?.focus(),
    );
}

// ---- Base URL blur check -----------------------------------------------------------------------------------------

function messageFor(
    field: string,
    reason: string | undefined,
    server: string | undefined,
): string {
    if (reason === 'host-not-allowlisted') {
        // The catalogue message ends with its "Technical details" cue, which this form renders as its own disclosure.
        return t('host-not-allowlisted').replace(/\s*▸.*$/u, '');
    }

    // A ceiling message from the server names the limit.
    if (reason === 'above-ceiling' && server) {
        return server;
    }

    return (
        (reason ? labels.reasons[reason] : undefined) ??
        server ??
        t('save-failed.form')
    );
}

async function checkUrl(): Promise<void> {
    checkController?.abort();
    const url = form.base_url.trim();

    if (url === '') {
        errors.base_url = null;
        urlBlock.value = null;

        return;
    }

    checkController = new AbortController();
    const mine = checkController;
    urlChecking.value = true;

    try {
        await checkBaseUrl(url, mine.signal);

        if (!mine.signal.aborted) {
            errors.base_url = null;
            urlBlock.value = null;
            urlRequestId.value = null;
        }
    } catch (error) {
        if (mine.signal.aborted || authExpired(error)) {
            return;
        }

        if (error instanceof DataSourceError && error.status === 422) {
            const reason = error.reasons.base_url;

            errors.base_url = messageFor(
                'base_url',
                reason,
                error.errors.base_url?.[0],
            );
            urlBlock.value = reason === 'host-not-allowlisted' ? 'host' : 'url';
            urlRequestId.value = error.requestId;
            technicalStatus.value = 422;

            return;
        }

        // The check itself failed (offline, throttled): the save still asks the server, which refuses a blocked host.
        errors.base_url = null;
        urlBlock.value = null;
    } finally {
        if (checkController === mine) {
            urlChecking.value = false;
        }
    }
}

function onUrlInput(): void {
    checkController?.abort();
    urlChecking.value = false;
    errors.base_url = null;
    urlBlock.value = null;
}

const saveReason = computed(() =>
    urlBlock.value === 'host'
        ? labels.saveBlocked
        : urlBlock.value === 'url'
          ? labels.saveBlockedUrl
          : undefined,
);

// ---- Save --------------------------------------------------------------------------------------------------------

function limit(value: string): string | null {
    return value.trim() === '' ? null : value.trim();
}

function payload(): DataSourceInput {
    return {
        name: form.name.trim(),
        base_url: form.base_url.trim(),
        headers: form.headers
            .filter((row) => row.name !== '' || row.value !== '')
            .map((row) => ({ name: row.name, value: row.value })),
        timeout_seconds: limit(form.timeout_seconds),
        max_response_bytes: limit(form.max_response_bytes),
        max_pages: limit(form.max_pages),
        live_capable: form.live_capable,
        auth_type: 'none',
    };
}

// The row of a header error field (`headers.2.value`) is the row's position in the submitted list: rows left blank
// are dropped from the payload, so the server's position is mapped back to the form row.
function rowFor(sent: number): number {
    let seen = -1;

    for (let i = 0; i < form.headers.length; i++) {
        if (form.headers[i].name !== '' || form.headers[i].value !== '') {
            seen++;
        }

        if (seen === sent) {
            return i;
        }
    }

    return sent;
}

function fieldLabel(field: string): string {
    if (field === 'name') return labels.name;
    if (field === 'base_url') return labels.baseUrl;
    if (field === 'timeout_seconds') return labels.timeout;
    if (field === 'max_response_bytes') return labels.maxResponse;
    if (field === 'max_pages') return labels.maxPages;

    const match = /^headers\.(\d+)\.(name|value)$/.exec(field);

    if (match) {
        const n = Number(match[1]) + 1;

        return match[2] === 'name'
            ? labels.headerName(n)
            : labels.headerValue(n);
    }

    return labels.headers;
}

function elementFor(field: string): string {
    const match = /^headers\.(\d+)\.(name|value)$/.exec(field);

    return match
        ? fieldId(field)
        : fieldId(field === 'headers' ? 'add-header' : field);
}

const fieldOrder = ['name', 'base_url'];

const summaryItems = computed(() =>
    Object.keys(errors)
        .filter((field) => errors[field])
        .sort(order)
        .map((field) => ({
            id: elementFor(field),
            label: fieldLabel(field),
            message: errors[field] as string,
        })),
);

function order(a: string, b: string): number {
    const rank = (field: string): number => {
        const fixed = fieldOrder.indexOf(field);

        if (fixed >= 0) {
            return fixed;
        }

        const row = /^headers\.(\d+)\.(name|value)$/.exec(field);

        if (row) {
            return 10 + Number(row[1]) * 2 + (row[2] === 'value' ? 1 : 0);
        }

        return (
            1000 +
            [
                'headers',
                'timeout_seconds',
                'max_response_bytes',
                'max_pages',
                'live_capable',
            ].indexOf(field)
        );
    };

    return rank(a) - rank(b);
}

async function showServerErrors(error: DataSourceError): Promise<boolean> {
    const fields = Object.keys(error.errors).filter(
        (field) => error.errors[field]?.length,
    );

    if (fields.length === 0) {
        return false;
    }

    for (const field of fields) {
        const message = messageFor(
            field,
            error.reasons[field],
            error.errors[field][0],
        );

        // A header error names the position in the submitted list: map it to the form row it came from.
        const match = /^headers\.(\d+)\.(name|value)$/.exec(field);
        const key = match
            ? `headers.${rowFor(Number(match[1]))}.${match[2]}`
            : field;

        errors[key] = message;
    }

    if (error.errors.base_url?.length) {
        urlBlock.value =
            error.reasons.base_url === 'host-not-allowlisted' ? 'host' : null;
        urlRequestId.value = error.requestId;
        technicalStatus.value = 422;
    }

    const shown = Object.keys(errors).filter((key) => errors[key]);

    showSummary.value = shown.length >= 2;
    await nextTick();

    if (shown.length >= 2) {
        summaryRef.value?.focus();
    } else {
        const first = shown.sort(order)[0];

        document.getElementById(elementFor(first))?.focus();
    }

    return true;
}

async function failWith(kind: 'save' | 'throttled' | 'stale'): Promise<void> {
    failure.value = kind;
    await nextTick();
    failureRef.value?.focus();
}

async function submit(): Promise<void> {
    if (saving.value || state.value !== 'ready') {
        return;
    }

    clearErrors();

    // Required fields first, on the client: the server's rules are the rest.
    if (form.name.trim() === '') {
        errors.name = labels.nameRequired;
    }

    if (form.base_url.trim() === '') {
        errors.base_url = labels.baseUrlRequired;
    }

    if (errors.name || errors.base_url) {
        const shown = Object.keys(errors).filter((key) => errors[key]);

        showSummary.value = shown.length >= 2;
        await nextTick();

        if (shown.length >= 2) {
            summaryRef.value?.focus();
        } else {
            document.getElementById(fieldId(shown[0]))?.focus();
        }

        return;
    }

    saving.value = true;

    try {
        const saved = editing.value
            ? await updateDataSource(
                  props.dataSourceId as string,
                  payload(),
                  revision.value,
              )
            : await createDataSource(payload());

        baseline = snapshot();
        leaving = true;
        announce(t('saved'), 'polite');
        router.visit(
            `${index().url}?${editing.value ? 'updated' : 'created'}=${encodeURIComponent(saved.data.data_source_id)}`,
        );
    } catch (error) {
        await refused(error);
    } finally {
        saving.value = false;
    }
}

async function refused(error: unknown): Promise<void> {
    if (authExpired(error)) {
        return;
    }

    if (error instanceof DataSourceError) {
        if (error.status === 409 && error.current) {
            // Someone saved first: what was typed stays; the next save is against the latest revision.
            revision.value = error.current.data.revision;
            original.value = error.current.data;
            ceilings.value = error.current.meta.ceilings;
            await failWith('stale');

            return;
        }

        if (error.status === 404) {
            state.value = 'missing';

            return;
        }

        if (error.status === 422 && (await showServerErrors(error))) {
            return;
        }

        await failWith(error.status === 429 ? 'throttled' : 'save');

        return;
    }

    await failWith('save');
}

async function reloadLatest(): Promise<void> {
    if (!original.value) {
        return;
    }

    fill(original.value);
    clearErrors();
    urlBlock.value = null;
    announce(labels.reloaded, 'polite');
    await nextTick();
    document.getElementById(fieldId('name'))?.focus();
}

// ---- Leaving with unsaved edits ------------------------------------------------------------------------------------

const stopBefore = router.on('before', (event) => {
    if (leaving || !dirty.value) {
        return;
    }

    leaveTarget = event.detail.visit.url.href;
    leaveOpen.value = true;

    return false;
});

function onBeforeUnload(event: BeforeUnloadEvent): void {
    if (dirty.value && !leaving) {
        event.preventDefault();
    }
}

function discard(): void {
    leaving = true;

    if (leaveTarget) {
        router.visit(leaveTarget);
    }
}

function keep(): void {
    leaveTarget = null;
}

// Save from the leave question: a saved form goes to the list; a refused save keeps the person here.
async function saveAndLeave(): Promise<void> {
    leaveTarget = null;
    await submit();
}

const stopRegistry = registerUnsavedForm({
    id: 'data-source',
    isDirty: () => dirty.value,
    save: async () => {
        await submit();

        return leaving;
    },
});

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    void load();
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    checkController?.abort();
    stopBefore();
    stopRegistry();
});

const title = computed(() =>
    editing.value ? labels.editTitle : labels.registerTitle,
);
const ready = computed(() => state.value === 'ready');
const notEncrypted = computed(() => /^http:\/\//i.test(form.base_url.trim()));
const ceilingHelper = (value: number | null): string | undefined =>
    value === null ? undefined : labels.ceiling(value);
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader
            :title="title"
            :subtitle="
                editing && original
                    ? labels.editSubtitle(original.name)
                    : labels.registerSubtitle
            "
        >
            <Link
                :href="index().url"
                class="type-body-sm text-text-secondary underline underline-offset-4 hover:text-text-primary"
                data-test="back-to-list"
                >{{ labels.back }}</Link
            >
        </PageHeader>

        <p
            v-if="state === 'forbidden'"
            role="alert"
            data-slot="perm-denied"
            class="type-body text-text-secondary"
        >
            {{ t('perm-denied') }}
        </p>

        <p
            v-else-if="state === 'missing'"
            role="alert"
            data-slot="not-found"
            class="type-body text-text-secondary"
        >
            {{ labels.notFound }}
        </p>

        <section
            v-else-if="state === 'error'"
            role="alert"
            data-slot="load-failure"
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border-l-[3px] border-error bg-error-soft p-4"
        >
            <p class="type-body-sm text-error-text">{{ labels.loadFailed }}</p>
            <Button
                type="button"
                variant="secondary"
                size="sm"
                data-test="retry"
                @click="load"
            >
                {{ shellLabels.retry }}
            </Button>
        </section>

        <form
            v-else
            novalidate
            :aria-label="labels.formRegion"
            :aria-busy="state === 'loading' ? 'true' : undefined"
            class="grid max-w-2xl gap-6"
            data-test="data-source-form"
            @submit.prevent="submit"
        >
            <div
                v-if="state === 'loading'"
                role="status"
                class="grid gap-4"
                data-slot="form-loading"
            >
                <span class="sr-only">{{ labels.loading }}</span>
                <Skeleton
                    v-for="row in 5"
                    :key="row"
                    class="h-10 w-full"
                    :caption="row === 1"
                />
            </div>

            <template v-else>
                <div
                    v-if="failure"
                    ref="failureRef"
                    tabindex="-1"
                    role="alert"
                    class="grid gap-2 rounded-md border-l-[3px] border-error bg-error-soft p-3"
                    data-test="save-failure"
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
                </div>

                <FormErrorSummary
                    v-if="showSummary"
                    ref="summaryRef"
                    :items="summaryItems"
                />

                <RequiredNote />

                <fieldset class="grid gap-4">
                    <legend class="type-title-md mb-2 text-text-primary">
                        {{ labels.connection }}
                    </legend>
                    <FormField
                        :id="fieldId('name')"
                        :label="labels.name"
                        :helper="labels.nameHelper"
                        :error="errors.name"
                        required
                        #default="{ field }"
                    >
                        <Input
                            v-bind="field"
                            v-model="form.name"
                            name="name"
                            type="text"
                            maxlength="200"
                            autocomplete="off"
                            data-test="name"
                            @input="errors.name = null"
                        />
                    </FormField>
                    <div class="grid gap-2">
                        <FormField
                            :id="fieldId('base_url')"
                            :label="labels.baseUrl"
                            :helper="labels.baseUrlHelper"
                            :error="errors.base_url"
                            required
                            #default="{ field }"
                        >
                            <Input
                                v-bind="field"
                                v-model="form.base_url"
                                name="base_url"
                                type="text"
                                inputmode="url"
                                maxlength="2100"
                                autocomplete="off"
                                autocapitalize="off"
                                spellcheck="false"
                                data-test="base-url"
                                @input="onUrlInput"
                                @blur="checkUrl"
                            />
                        </FormField>
                        <div
                            v-if="notEncrypted"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Badge
                                data-test="not-encrypted"
                                :title="labels.notEncryptedNote"
                                >{{ labels.notEncrypted }}</Badge
                            >
                            <span class="type-caption text-text-secondary">{{
                                labels.notEncryptedNote
                            }}</span>
                        </div>
                        <TechnicalDetails
                            v-if="urlBlock === 'host' && urlRequestId"
                            area="admin"
                            :status="technicalStatus"
                            path="base_url"
                            :request-id="urlRequestId"
                        />
                    </div>
                </fieldset>

                <fieldset class="grid gap-4">
                    <legend class="type-title-md mb-1 text-text-primary">
                        {{ labels.headers }}
                    </legend>
                    <p class="type-caption text-text-muted">
                        {{ labels.headersHelper }}
                    </p>
                    <p
                        v-if="form.headers.length === 0"
                        class="type-body-sm text-text-secondary"
                        data-test="no-headers"
                    >
                        {{ labels.headersNone }}
                    </p>
                    <ul class="grid gap-4" data-test="header-rows">
                        <li
                            v-for="(row, position) in form.headers"
                            :key="row.key"
                            class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start"
                            data-test="header-row"
                        >
                            <FormField
                                :id="fieldId(`headers.${position}.name`)"
                                :label="labels.headerName(position + 1)"
                                :error="errors[`headers.${position}.name`]"
                                #default="{ field }"
                            >
                                <Input
                                    v-bind="field"
                                    v-model="row.name"
                                    type="text"
                                    maxlength="200"
                                    autocomplete="off"
                                    autocapitalize="off"
                                    spellcheck="false"
                                    data-test="header-name"
                                    @input="
                                        errors[`headers.${position}.name`] =
                                            null
                                    "
                                />
                            </FormField>
                            <FormField
                                :id="fieldId(`headers.${position}.value`)"
                                :label="labels.headerValue(position + 1)"
                                :error="errors[`headers.${position}.value`]"
                                #default="{ field }"
                            >
                                <Input
                                    v-bind="field"
                                    v-model="row.value"
                                    type="text"
                                    maxlength="2100"
                                    autocomplete="off"
                                    spellcheck="false"
                                    data-test="header-value"
                                    @input="
                                        errors[`headers.${position}.value`] =
                                            null
                                    "
                                />
                            </FormField>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                class="sm:mt-6"
                                :aria-label="labels.removeHeader(position + 1)"
                                data-test="remove-header"
                                @click="removeHeader(position)"
                            >
                                {{ labels.removeHeaderButton }}
                            </Button>
                        </li>
                    </ul>
                    <p
                        v-if="errors.headers"
                        class="type-caption text-error-text"
                        data-slot="field-error"
                    >
                        {{ errors.headers }}
                    </p>
                    <div>
                        <Button
                            :id="fieldId('add-header')"
                            type="button"
                            variant="secondary"
                            data-test="add-header"
                            @click="addHeader"
                        >
                            {{ labels.addHeader }}
                        </Button>
                    </div>
                </fieldset>

                <fieldset class="grid gap-4">
                    <legend class="type-title-md mb-1 text-text-primary">
                        {{ labels.limits }}
                    </legend>
                    <p class="type-caption text-text-muted">
                        {{ labels.limitsHelper }}
                    </p>
                    <FormField
                        :id="fieldId('timeout_seconds')"
                        :label="labels.timeout"
                        :helper="ceilingHelper(ceilings.timeout_seconds)"
                        :error="errors.timeout_seconds"
                        #default="{ field }"
                    >
                        <Input
                            v-bind="field"
                            v-model="form.timeout_seconds"
                            name="timeout_seconds"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            class="max-w-xs"
                            data-test="timeout"
                            @input="errors.timeout_seconds = null"
                        />
                    </FormField>
                    <FormField
                        :id="fieldId('max_response_bytes')"
                        :label="labels.maxResponse"
                        :helper="ceilingHelper(ceilings.max_response_bytes)"
                        :error="errors.max_response_bytes"
                        #default="{ field }"
                    >
                        <Input
                            v-bind="field"
                            v-model="form.max_response_bytes"
                            name="max_response_bytes"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            class="max-w-xs"
                            data-test="max-response"
                            @input="errors.max_response_bytes = null"
                        />
                    </FormField>
                    <FormField
                        :id="fieldId('max_pages')"
                        :label="labels.maxPages"
                        :helper="ceilingHelper(ceilings.max_pages)"
                        :error="errors.max_pages"
                        #default="{ field }"
                    >
                        <Input
                            v-bind="field"
                            v-model="form.max_pages"
                            name="max_pages"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            class="max-w-xs"
                            data-test="max-pages"
                            @input="errors.max_pages = null"
                        />
                    </FormField>
                </fieldset>

                <fieldset class="grid gap-3">
                    <legend class="type-title-md mb-1 text-text-primary">
                        {{ labels.refresh }}
                    </legend>
                    <div class="flex flex-wrap items-center gap-3">
                        <Switch
                            :id="fieldId('live_capable')"
                            v-model="form.live_capable"
                            :aria-labelledby="fieldId('live-label')"
                            :aria-describedby="fieldId('live-helper')"
                            data-test="live-capable"
                        />
                        <span
                            :id="fieldId('live-label')"
                            class="type-label text-text-primary"
                            >{{ labels.live }}</span
                        >
                    </div>
                    <p
                        :id="fieldId('live-helper')"
                        class="type-caption text-text-muted"
                    >
                        {{ labels.liveHelper }}
                    </p>
                </fieldset>
            </template>

            <div class="grid gap-2">
                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        type="submit"
                        :disabled="!ready || saving"
                        :blocked="ready && saveReason !== undefined"
                        :blocked-reason="saveReason"
                        :aria-describedby="
                            saveReason ? saveReasonId : undefined
                        "
                        data-test="save"
                    >
                        {{ editing ? labels.save : labels.create }}
                    </Button>
                    <Button as-child variant="secondary">
                        <Link :href="index().url" data-test="cancel">{{
                            labels.cancel
                        }}</Link>
                    </Button>
                </div>
                <BlockedReason v-if="saveReason" :id="saveReasonId">{{
                    saveReason
                }}</BlockedReason>
            </div>
        </form>

        <UnsavedChangesDialog
            v-model:open="leaveOpen"
            @save="saveAndLeave"
            @discard="discard"
            @keep="keep"
        />
    </div>
</template>
