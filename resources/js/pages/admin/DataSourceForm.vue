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
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import BlockedReason from '@/components/BlockedReason.vue';
import FetchErrorCard from '@/components/FetchErrorCard.vue';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import RequiredNote from '@/components/RequiredNote.vue';
import SecretField from '@/components/SecretField.vue';
import TechnicalDetails from '@/components/TechnicalDetails.vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { announce } from '@/lib/announce';
import {
    checkBaseUrl,
    createDataSource,
    DataSourceError,
    fetchDataSource,
    PollTimeout,
    pollOperation,
    startConnectionTest,
    updateDataSource,
} from '@/lib/dataSources';
import { SECRET_SLOTS } from '@/lib/dataSources';
import type {
    AuthType,
    Ceilings,
    ConnectionTestCode,
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

type Row = {
    key: number;
    name: string;
    value: string;
    // A secret header: its value is write-only. `savedAt` and `origName` describe the saved secret, if any.
    secret: boolean;
    savedAt: string | null;
    origName: string;
};
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
    auth_type: 'none' as AuthType,
    api_key_name: '',
    api_key_placement: 'header' as 'header' | 'query',
    confirm_password: '',
});
// Secret values live only here, in memory: never in the snapshot, a draft, storage or the URL.
const secretValues = reactive<Record<string, string>>({});
const replacing = reactive<Record<string, boolean>>({});
let baseline = snapshot();

const errors = reactive<Record<string, string | null>>({});
const urlRequestId = ref<string | null>(null);
// The blur check refused the Base URL: Save stays `aria-disabled` until it is changed and accepted.
const urlBlock = ref<'host' | 'url' | null>(null);
const urlChecking = ref(false);
const failure = ref<'save' | 'throttled' | 'stale' | 'unconfigured' | null>(
    null,
);
const failureRef = ref<HTMLElement | null>(null);
const summaryRef = ref<InstanceType<typeof FormErrorSummary> | null>(null);
const showSummary = ref(false);
const saving = ref(false);
const saveReasonId = useId();
const leaveOpen = ref(false);
const technicalStatus = ref<number | null>(null);

// Test connection (Story 2.5): one test at a time; the result is the real status and latency, or the error card.
type TestFailure = {
    code: ConnectionTestCode;
    status: number | null;
    requestId: string | null;
    host: string | null;
    reason: string | null;
};
const testing = ref(false);
const testOk = ref<{ status: number; ms: number } | null>(null);
const testFailure = ref<TestFailure | null>(null);
const testCardRef = ref<InstanceType<typeof FetchErrorCard> | null>(null);
// After a 429 the button stays blocked until the server says another test may start.
const testWaitSeconds = ref<number | null>(null);
const testReasonId = useId();

// Used only when a throttled answer names no wait at all.
const THROTTLE_FALLBACK_SECONDS = 10;

let testController: AbortController | null = null;
let testWaitTimer: ReturnType<typeof setTimeout> | null = null;
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
        form.auth_type,
        form.api_key_name,
        form.api_key_placement,
        // Whether a secret was typed, never what.
        Object.values(secretValues).map((value) => value !== ''),
        form.headers.map((row) => row.secret),
    ]);
}

const dirty = computed(
    () => state.value === 'ready' && !saving.value && snapshot() !== baseline,
);

function fill(source: DataSource): void {
    form.name = source.name;
    form.base_url = source.base_url;
    form.headers = source.headers.map((header) => {
        const status = header.secret
            ? source.secrets?.[`header:${header.name.toLowerCase()}`]
            : undefined;

        return {
            key: nextRowKey++,
            name: header.name,
            value: header.value ?? '',
            secret: header.secret === true,
            savedAt: status?.configured ? status.updated_at : null,
            origName: header.name,
        };
    });
    form.auth_type = (
        ['none', 'api_key', 'bearer', 'basic'].includes(source.auth_type)
            ? source.auth_type
            : 'none'
    ) as AuthType;
    form.api_key_name = source.api_key_name ?? '';
    form.api_key_placement = source.api_key_placement ?? 'header';
    form.confirm_password = '';
    clearSecretInputs();
    form.timeout_seconds = source.timeout_seconds?.toString() ?? '';
    form.max_response_bytes = source.max_response_bytes?.toString() ?? '';
    form.max_pages = source.max_pages?.toString() ?? '';
    form.live_capable = source.live_capable;
    original.value = source;
    revision.value = source.revision;
    baseline = snapshot();
}

function clearSecretInputs(): void {
    for (const key of Object.keys(secretValues)) {
        delete secretValues[key];
    }

    for (const key of Object.keys(replacing)) {
        delete replacing[key];
    }
}

// The saved date of a credential slot, or null when it holds nothing yet.
function savedAt(slot: string): string | null {
    const status = original.value?.secrets?.[slot];

    return status?.configured ? status.updated_at : null;
}

const slots = computed(() => SECRET_SLOTS[form.auth_type]);

function chooseAuth(type: AuthType): void {
    form.auth_type = type;
    clearSecretInputs();
    errors.auth_type = null;
}

// Any typed secret, a changed auth type, or a secret header removed or unflagged needs the password.
const authChanged = computed(
    () => form.auth_type !== (original.value?.auth_type ?? 'none'),
);
const needsPassword = computed(() => {
    if (authChanged.value) {
        return true;
    }

    if (Object.values(secretValues).some((value) => value !== '')) {
        return true;
    }

    // Rerouting a stored credential (another name or the query string) needs the password too.
    const saved = original.value;

    if (
        saved &&
        Object.values(saved.secrets ?? {}).some(
            (status) => status.configured,
        ) &&
        (form.api_key_name !== (saved.api_key_name ?? '') ||
            form.api_key_placement !== (saved.api_key_placement ?? 'header'))
    ) {
        return true;
    }

    if (form.headers.some((row) => row.secret && row.value !== '')) {
        return true;
    }

    const kept = new Set(
        form.headers
            .filter((row) => row.secret)
            .map((row) => row.name.toLowerCase()),
    );

    return (original.value?.headers ?? []).some(
        (header) => header.secret && !kept.has(header.name.toLowerCase()),
    );
});

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

// A secret header's saved date holds only while its name is the saved one: a renamed header is a new secret.
function headerSavedAt(row: Row): string | null {
    return row.secret && row.name.toLowerCase() === row.origName.toLowerCase()
        ? row.savedAt
        : null;
}

function toggleSecret(row: Row, on: boolean): void {
    row.secret = on;
    row.value = '';
}

// ---- Rows -------------------------------------------------------------------------------------------------------

function addHeader(): void {
    form.headers.push({
        key: nextRowKey++,
        name: '',
        value: '',
        secret: false,
        savedAt: null,
        origName: '',
    });
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
            .filter((row) => row.name !== '' || row.value !== '' || row.secret)
            .map((row) =>
                row.secret
                    ? {
                          name: row.name,
                          secret: true,
                          ...(row.value !== '' ? { value: row.value } : {}),
                      }
                    : { name: row.name, value: row.value },
            ),
        timeout_seconds: limit(form.timeout_seconds),
        max_response_bytes: limit(form.max_response_bytes),
        max_pages: limit(form.max_pages),
        live_capable: form.live_capable,
        auth_type: form.auth_type,
        ...(form.auth_type === 'api_key'
            ? {
                  api_key_name: form.api_key_name,
                  api_key_placement: form.api_key_placement,
              }
            : {}),
        ...secretsPayload(),
        ...(needsPassword.value
            ? { confirm_password: form.confirm_password }
            : {}),
    };
}

// Only the slots being set or replaced; an untouched saved slot is left out and stays as it is.
function secretsPayload(): Pick<DataSourceInput, 'secrets'> {
    const typed = Object.fromEntries(
        slots.value
            .filter((slot) => (secretValues[slot] ?? '') !== '')
            .map((slot) => [slot, secretValues[slot]]),
    );

    return Object.keys(typed).length > 0 ? { secrets: typed } : {};
}

// The row of a header error field (`headers.2.value`) is the row's position in the submitted list: rows left blank
// are dropped from the payload, so the server's position is mapped back to the form row.
function rowFor(sent: number): number {
    let seen = -1;

    for (let i = 0; i < form.headers.length; i++) {
        if (
            form.headers[i].name !== '' ||
            form.headers[i].value !== '' ||
            form.headers[i].secret
        ) {
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
    if (field === 'auth_type') return labels.authType;
    if (field === 'api_key_name') return labels.apiKeyName;
    if (field === 'api_key_placement') return labels.apiKeyPlacement;
    if (field === 'confirm_password') return labels.confirmPassword;

    const secret = /^secrets\.(\w+)$/.exec(field);

    if (secret) {
        return slotLabel(secret[1]);
    }

    const match = /^headers\.(\d+)\.(name|value)$/.exec(field);

    if (match) {
        const n = Number(match[1]) + 1;

        return match[2] === 'name'
            ? labels.headerName(n)
            : labels.headerValue(n);
    }

    return labels.headers;
}

function slotLabel(slot: string): string {
    return slot === 'api_key'
        ? labels.apiKey
        : slot === 'bearer_token'
          ? labels.bearerToken
          : slot === 'basic_username'
            ? labels.basicUsername
            : labels.basicPassword;
}

function elementFor(field: string): string {
    const match = /^headers\.(\d+)\.(name|value)$/.exec(field);

    return match
        ? fieldId(field)
        : fieldId(field === 'headers' ? 'add-header' : field);
}

const fieldOrder = [
    'name',
    'base_url',
    'auth_type',
    'api_key_name',
    'api_key_placement',
    'secrets.api_key',
    'secrets.bearer_token',
    'secrets.basic_username',
    'secrets.basic_password',
];

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
                'confirm_password',
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

async function failWith(
    kind: 'save' | 'throttled' | 'stale' | 'unconfigured',
): Promise<void> {
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

    if (form.auth_type === 'api_key' && form.api_key_name === '') {
        errors.api_key_name = labels.reasons['api-key-name-required'];
    }

    // A credential needs a value: a new one, or the saved one left alone. "Replace" asks for a new value.
    for (const slot of slots.value) {
        if (
            (secretValues[slot] ?? '') === '' &&
            (savedAt(slot) === null || replacing[slot] === true)
        ) {
            errors[`secrets.${slot}`] = labels.reasons['secret-required'];
        }
    }

    form.headers.forEach((row, i) => {
        if (
            row.secret &&
            row.value === '' &&
            (headerSavedAt(row) === null || replacing[`header:${row.key}`])
        ) {
            errors[`headers.${i}.value`] = labels.reasons['secret-required'];
        }
    });

    if (needsPassword.value && form.confirm_password === '') {
        errors.confirm_password = labels.confirmPasswordHelper;
    }

    if (Object.keys(errors).some((key) => errors[key])) {
        const shown = Object.keys(errors).filter((key) => errors[key]);

        showSummary.value = shown.length >= 2;
        await nextTick();

        if (shown.length >= 2) {
            summaryRef.value?.focus();
        } else {
            document.getElementById(elementFor(shown.sort(order)[0]))?.focus();
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

        if (
            error.status === 503 &&
            error.errors.confirm_password === undefined
        ) {
            await failWith(
                error.code === 'connector.secrets_not_configured'
                    ? 'unconfigured'
                    : 'save',
            );

            return;
        }

        if (error.status === 429 && error.errors.confirm_password) {
            errors.confirm_password = t('throttled');
            form.confirm_password = '';
            await nextTick();
            document.getElementById(fieldId('confirm_password'))?.focus();

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

// ---- Test connection -----------------------------------------------------------------------------------------------

const testReason = computed(() =>
    testing.value
        ? labels.testRunning
        : testWaitSeconds.value !== null
          ? labels.testThrottled(testWaitSeconds.value)
          : undefined,
);

// What the form holds now, secrets included, but never the password: a test saves nothing.
function testPayload(): DataSourceInput {
    const body = payload();

    delete body.confirm_password;

    return body;
}

function clearTestResult(): void {
    testOk.value = null;
    testFailure.value = null;
}

function throttleTest(seconds: number): void {
    testWaitSeconds.value = Math.max(1, Math.ceil(seconds));

    if (testWaitTimer) {
        clearTimeout(testWaitTimer);
    }

    testWaitTimer = setTimeout(() => {
        testWaitSeconds.value = null;
        testWaitTimer = null;
    }, testWaitSeconds.value * 1000);
}

async function showTestFailure(failure: TestFailure): Promise<void> {
    testFailure.value = failure;
    await nextTick();
    testCardRef.value?.focus();
}

async function testConnection(): Promise<void> {
    if (
        state.value !== 'ready' ||
        testing.value ||
        testWaitSeconds.value !== null
    ) {
        return;
    }

    clearErrors();
    clearTestResult();
    testController?.abort();
    testController = new AbortController();
    const mine = testController;
    // What was tested: a result for other values (the form changed during the test) is not shown.
    const tested = snapshot();
    let accepted = false;
    testing.value = true;

    try {
        const started = await startConnectionTest(
            testPayload(),
            props.dataSourceId,
        );

        accepted = true;
        const operation = await pollOperation(
            started.operation_id,
            mine.signal,
        );
        const result = operation.result;

        if (snapshot() !== tested) {
            return;
        }

        if (operation.status === 'succeeded' && result?.ok) {
            testOk.value = {
                status: result.status ?? 200,
                ms: result.latency_ms ?? 0,
            };

            return;
        }

        // A result that never came (expired, stale) is a failed call like any other.
        await showTestFailure({
            code: result?.code ?? 'fetch-failed',
            status: result?.status ?? null,
            requestId: result?.request_id ?? null,
            host: result?.host ?? null,
            reason: result?.reason ?? operation.status,
        });
    } catch (error) {
        if (mine.signal.aborted || authExpired(error)) {
            return;
        }

        if (error instanceof DataSourceError) {
            if (error.status === 422 && (await showServerErrors(error))) {
                return;
            }

            // A throttled start without the wait in its body still names it in `Retry-After`.
            if (error.status === 429 && !accepted) {
                throttleTest(error.retryAfter ?? THROTTLE_FALLBACK_SECONDS);

                return;
            }

            if (
                !accepted &&
                error.status === 404 &&
                error.code === null &&
                props.dataSourceId
            ) {
                state.value = 'missing';

                return;
            }

            if (
                error.status === 503 &&
                error.code === 'connector.secrets_not_configured'
            ) {
                await failWith('unconfigured');

                return;
            }
        }

        if (snapshot() !== tested) {
            return;
        }

        await showTestFailure({
            code: 'fetch-failed',
            status:
                error instanceof DataSourceError && error.status > 0
                    ? error.status
                    : null,
            requestId:
                error instanceof DataSourceError ? error.requestId : null,
            host: null,
            reason: error instanceof PollTimeout ? 'no_worker_responded' : null,
        });
    } finally {
        if (testController === mine) {
            testing.value = false;
        }
    }
}

// What was tested is no longer what the form holds once it changes: the old result goes.
watch(
    () => snapshot(),
    () => {
        if (!testing.value) {
            clearTestResult();
        }
    },
);

const testSource = computed(
    () => form.name.trim() || original.value?.name || labels.testSourceFallback,
);

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
    testController?.abort();

    if (testWaitTimer) {
        clearTimeout(testWaitTimer);
    }

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
                                : failure === 'unconfigured'
                                  ? labels.credentialsUnavailable
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

                <fieldset class="grid gap-4" data-test="auth-section">
                    <legend class="type-title-md mb-1 text-text-primary">
                        {{ labels.authentication }}
                    </legend>
                    <p class="type-caption text-text-muted">
                        {{ labels.authenticationHelper }}
                    </p>
                    <FormField
                        :id="fieldId('auth_type')"
                        :label="labels.authType"
                        :error="errors.auth_type"
                        #default="{ field }"
                    >
                        <select
                            v-bind="field"
                            :value="form.auth_type"
                            name="auth_type"
                            class="bg-surface h-9 w-full max-w-xs rounded-md border border-border px-3 text-sm"
                            data-test="auth-type"
                            @change="
                                chooseAuth(
                                    ($event.target as HTMLSelectElement)
                                        .value as AuthType,
                                )
                            "
                        >
                            <option
                                v-for="type in [
                                    'none',
                                    'api_key',
                                    'bearer',
                                    'basic',
                                ]"
                                :key="type"
                                :value="type"
                            >
                                {{ labels.authOptions[type] }}
                            </option>
                        </select>
                    </FormField>

                    <template v-if="form.auth_type === 'api_key'">
                        <FormField
                            :id="fieldId('api_key_name')"
                            :label="labels.apiKeyName"
                            :helper="labels.apiKeyNameHelper"
                            :error="errors.api_key_name"
                            required
                            #default="{ field }"
                        >
                            <Input
                                v-bind="field"
                                v-model="form.api_key_name"
                                name="api_key_name"
                                type="text"
                                maxlength="128"
                                autocomplete="off"
                                autocapitalize="off"
                                spellcheck="false"
                                data-test="api-key-name"
                                @input="errors.api_key_name = null"
                            />
                        </FormField>
                        <FormField
                            :id="fieldId('api_key_placement')"
                            :label="labels.apiKeyPlacement"
                            :error="errors.api_key_placement"
                            #default="{ field }"
                        >
                            <select
                                v-bind="field"
                                v-model="form.api_key_placement"
                                name="api_key_placement"
                                class="bg-surface h-9 w-full max-w-xs rounded-md border border-border px-3 text-sm"
                                data-test="api-key-placement"
                            >
                                <option value="header">
                                    {{ labels.placementHeader }}
                                </option>
                                <option value="query">
                                    {{ labels.placementQuery }}
                                </option>
                            </select>
                        </FormField>
                        <p
                            v-if="form.api_key_placement === 'query'"
                            role="note"
                            class="type-body-sm rounded-md border-l-[3px] border-warning bg-warning-soft p-3 text-text-primary"
                            data-test="query-warning"
                        >
                            {{ labels.queryWarning }}
                        </p>
                    </template>

                    <FormField
                        v-for="slot in slots"
                        :key="slot"
                        :id="fieldId(`secrets.${slot}`)"
                        :label="slotLabel(slot)"
                        :error="errors[`secrets.${slot}`]"
                        required
                        #default="{ field }"
                    >
                        <SecretField
                            v-bind="field"
                            v-model="secretValues[slot]"
                            :saved-at="savedAt(slot)"
                            :data-test="`secret-${slot}`"
                            data-secret
                            @update:replacing="replacing[slot] = $event"
                            @update:model-value="
                                errors[`secrets.${slot}`] = null
                            "
                        />
                    </FormField>

                    <FormField
                        v-if="needsPassword"
                        :id="fieldId('confirm_password')"
                        :label="labels.confirmPassword"
                        :helper="labels.confirmPasswordHelper"
                        :error="errors.confirm_password"
                        required
                        #default="{ field }"
                    >
                        <Input
                            v-bind="field"
                            v-model="form.confirm_password"
                            name="confirm_password"
                            type="password"
                            autocomplete="current-password"
                            maxlength="255"
                            data-test="confirm-password"
                            @input="errors.confirm_password = null"
                        />
                    </FormField>
                </fieldset>

                <fieldset class="grid gap-4">
                    <legend class="type-title-md mb-1 text-text-primary">
                        {{ labels.headers }}
                    </legend>
                    <p class="type-caption text-text-muted">
                        {{ labels.headersHelper }} {{ labels.secretHeaderHint }}
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
                            class="grid gap-3 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-start"
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
                                :label="
                                    row.secret
                                        ? labels.secretHeaderValue(position + 1)
                                        : labels.headerValue(position + 1)
                                "
                                :error="errors[`headers.${position}.value`]"
                                #default="{ field }"
                            >
                                <SecretField
                                    v-if="row.secret"
                                    v-bind="field"
                                    v-model="row.value"
                                    :saved-at="headerSavedAt(row)"
                                    data-secret
                                    data-test="header-secret-value"
                                    @update:replacing="
                                        replacing[`header:${row.key}`] = $event
                                    "
                                    @update:model-value="
                                        errors[`headers.${position}.value`] =
                                            null
                                    "
                                />
                                <Input
                                    v-else
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
                            <div class="flex items-center gap-2 sm:mt-6">
                                <Switch
                                    :id="fieldId(`headers.${position}.secret`)"
                                    :model-value="row.secret"
                                    :aria-label="`${labels.secretHeader}: ${labels.headerName(position + 1)}`"
                                    data-test="header-secret"
                                    @update:model-value="
                                        toggleSecret(row, $event === true)
                                    "
                                />
                                <Label
                                    :for="fieldId(`headers.${position}.secret`)"
                                    class="type-caption"
                                    >{{ labels.secretHeader }}</Label
                                >
                            </div>
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
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="!ready"
                        :blocked="ready && testReason !== undefined"
                        :blocked-reason="testReason"
                        :aria-describedby="
                            testReason ? testReasonId : undefined
                        "
                        data-test="test-connection"
                        @click="testConnection"
                    >
                        {{ labels.testConnection }}
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
                <BlockedReason v-if="testReason" :id="testReasonId">{{
                    testReason
                }}</BlockedReason>
                <p class="type-caption text-text-muted">
                    {{ labels.testHint }}
                </p>
                <!-- One polite status region: "Testing…" while a test runs, then the real result. -->
                <div role="status" aria-live="polite" data-test="test-status">
                    <p
                        v-if="testing"
                        class="type-body-sm text-text-secondary"
                        data-test="testing"
                    >
                        {{ labels.testing }}
                    </p>
                    <p
                        v-else-if="testOk"
                        class="type-body-sm font-medium text-success-text"
                        data-test="test-ok"
                    >
                        {{
                            t('test-ok', {
                                status: testOk.status,
                                ms: testOk.ms,
                            })
                        }}
                    </p>
                </div>
                <FetchErrorCard
                    v-if="testFailure"
                    ref="testCardRef"
                    :code="testFailure.code"
                    :source="testSource"
                    :status="testFailure.status"
                    :request-id="testFailure.requestId"
                    :host="testFailure.host"
                    :reason="testFailure.reason"
                    @retry="testConnection"
                />
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
