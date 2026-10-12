<script setup lang="ts">
import { FileJson } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import Banner from '@/components/Banner.vue';
import BlockedReason from '@/components/BlockedReason.vue';
import FetchErrorCard from '@/components/FetchErrorCard.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import SampleTree from '@/components/wizard/SampleTree.vue';
import SectionCard from '@/components/wizard/SectionCard.vue';
import { announce } from '@/lib/announce';
import {
    belongsToEarlierParameters,
    caretAt,
    checkSample,
    clockText,
    failureOf,
    fetchedFacts,
    fetchSample,
    restoreSample,
    shapeText,
    startSampleFetch,
    storeReasonOf,
    storeSample,
} from '@/lib/blockSample';
import type {
    FetchBody,
    FetchSummary,
    SampleCheck,
    SampleClaim,
    SampleMode,
    SampleState,
    StoreReason,
} from '@/lib/blockSample';
import { WizardError } from '@/lib/blockWizard';
import { DataSourceError, PollTimeout, pollOperation } from '@/lib/dataSources';
import { blockSampleLabels as labels } from '@/locales/labels';
import { blockSourceLabels } from '@/locales/labels';

// The Sample Response of Step 2: a segmented control (Fetch from API, Story 3.9, and Paste sample JSON, Story 3.8), a `json-textarea` that is checked on paste and on blur, and the accepted sample's summary, badge and structure. The server
// reads the text with the platform's lossless decoder and keeps it (encrypted, one per Draft, with an end time); the browser never parses it.
// The state belongs to the page (the Continue gate and the step summary read it); this component edits it in place. Nothing is stored in
// sessionStorage: a sample comes back from the server when the Draft reopens.
const props = defineProps<{
    sample: SampleState;
    draftId: string | null;
    // The edit-lock claim a write carries; null when this tab does not hold the lock.
    claim: () => SampleClaim | null;
    readOnly: boolean;
    // Why nothing can be fetched or saved right now (offline), beyond the form's own reason.
    alsoBlocked?: string;
    // Why Fetch cannot run (the form's own reason, else offline); undefined when it can.
    fetchReason?: string;
    outOfDate: boolean;
    // Fetch from API (Story 3.9): what the request is now, its key, and the Data Source's name for the error card.
    fetchBody: () => FetchBody;
    requestKey: string;
    sourceName: string;
}>();

const emit = defineEmits<{
    // The sample was accepted: the out-of-date flag clears and the Draft is dirty.
    (e: 'accepted'): void;
    // A fetch stored a sample; `current` says it was taken with the Draft's current parameters, so the out-of-date flag may clear.
    (e: 'fetched', current: boolean): void;
    (e: 'auth-error', error: unknown): void;
}>();

const { t } = useI18n();
const textId = useId();
const hintId = useId();
const statusId = useId();
const ackId = useId();
const useReasonId = useId();
const fetchReasonId = useId();
const fetchAckId = useId();
const fetchStatusId = useId();
const card = ref<InstanceType<typeof FetchErrorCard> | null>(null);
let controller: AbortController | null = null;
let waitTimer: ReturnType<typeof setInterval> | null = null;
const jsonShown = ref(false);
let pasted = false;
let runSeq = 0;

const state = computed(() => props.sample);
const modeOptions = [
    { value: 'fetch', label: labels.fetchOption },
    { value: 'paste', label: labels.pasteOption },
];
const text = computed(() => state.value.text);
const check = computed<SampleCheck | null>(() =>
    state.value.checkedText === state.value.text ? state.value.check : null,
);
const stored = computed(() => state.value.stored);
// The accepted text, shown again unchanged: nothing to accept.
const inUse = computed(
    () => stored.value !== null && stored.value.text === state.value.text,
);
const bytes = computed(() => check.value?.size_bytes ?? 0);
const time = computed(() =>
    clockText(state.value.pastedAt ?? stored.value?.pasted_at ?? null),
);

const validMessage = computed<string>(() => {
    const c = check.value;

    if (c === null || !c.valid || c.shape === null) {
        return '';
    }

    const shape = shapeText(c.shape);

    return c.shape.records
        ? t('json-valid', {
              count: c.shape.records.count,
              path: c.shape.records.display,
              time: time.value,
              bytes: bytes.value,
              shape,
          })
        : labels.validNoRecords(time.value, bytes.value, shape);
});

// The "Go to line N" sentence of `json-invalid` is a button: the message is cut at that sentence, so the catalogue stays the one wording.
const invalid = computed(() => {
    const c = check.value;

    if (c === null || c.valid || c.reason !== 'not_json' || c.line === null) {
        return null;
    }

    const line = c.line;
    const full = t('json-invalid', { line, problem: c.problem ?? '' });
    const go = `Go to line ${line}`;
    const at = full.lastIndexOf(go);

    return {
        before: at === -1 ? full : full.slice(0, at).trimEnd(),
        line,
        column: c.column ?? 1,
    };
});

const refusal = computed<string>(() => {
    const c = check.value;

    if (c === null || c.valid) {
        return '';
    }

    return c.reason === 'too_large'
        ? labels.tooLarge(c.size_bytes, c.limit ?? 0)
        : c.reason === 'too_deep'
          ? labels.tooDeep(c.limit ?? 0)
          : c.reason === 'limit_not_configured'
            ? labels.notConfigured(c.setting)
            : '';
});

const problem = computed<string>(() => {
    const p = state.value.problem;

    return p === null
        ? ''
        : p === 'check_failed'
          ? labels.checkFailed
          : p === 'locked'
            ? labels.storeLocked
            : p === 'store_failed'
              ? labels.storeFailed
              : p === 'unavailable'
                ? labels.unavailable
                : p === 'acknowledgement_required'
                  ? labels.ackRequired
                  : p === 'ttl_not_configured'
                    ? labels.ttlNotConfigured
                    : '';
});

const useBlocked = computed<string | undefined>(() => {
    if (props.readOnly || props.claim() === null) {
        return labels.needLock;
    }

    if (props.alsoBlocked) {
        return props.alsoBlocked;
    }

    if (check.value === null || !check.value.valid) {
        return labels.needValid;
    }

    return state.value.ack ? undefined : labels.needAck;
});

const fetching = computed(() => state.value.fetch);
const fetchedLine = computed<string>(() => {
    const facts = fetchedFacts(stored.value);

    if (facts === null) {
        return '';
    }

    return facts.count !== null && facts.path !== null
        ? t('fetch-ok', {
              status: facts.status,
              ms: facts.ms,
              count: facts.count,
              path: facts.path,
          })
        : labels.fetchOkNoRecords(facts.status, facts.ms);
});
const earlier = computed(() =>
    belongsToEarlierParameters(stored.value, props.requestKey),
);
const running = computed(() => fetching.value.phase === 'running');

// Why Fetch cannot run: the form's own reason first (a missing choice, then offline), then the lock, the acknowledgement and a wait.
const fetchBlocked = computed<string | undefined>(() => {
    if (props.fetchReason) {
        return props.fetchReason;
    }

    if (props.readOnly || props.claim() === null) {
        return labels.needLock;
    }

    if (!fetching.value.ack) {
        return labels.needAck;
    }

    return fetching.value.wait !== null
        ? labels.waitFor(fetching.value.wait)
        : undefined;
});

function setFetchAck(value: boolean | 'indeterminate'): void {
    fetching.value.ack = value === true;
}

// The button shows the wait that a 429 asked for.
function startWait(seconds: number): void {
    if (waitTimer !== null) {
        clearInterval(waitTimer);
    }

    fetching.value.wait = Math.max(1, Math.ceil(seconds));
    waitTimer = setInterval(() => {
        const left = (fetching.value.wait ?? 0) - 1;

        if (left <= 0) {
            fetching.value.wait = null;

            if (waitTimer !== null) {
                clearInterval(waitTimer);
                waitTimer = null;
            }

            return;
        }

        fetching.value.wait = left;
    }, 1000);
}

function ended(error: unknown): boolean {
    return (
        (error instanceof WizardError || error instanceof DataSourceError) &&
        (error.status === 401 || error.status === 419)
    );
}

// What a fetch the server would not start says, in words: a setting that is unset (named), a value that does not fit, a lost lock.
function refusalOf(error: WizardError): string {
    const reason = error.body.reason;

    if (error.status === 423) {
        return labels.storeLocked;
    }

    if (reason === 'setting_not_configured') {
        return labels.settingNotConfigured(
            typeof error.body.setting === 'string' ? error.body.setting : null,
        );
    }

    if (reason === 'ttl_not_configured') {
        return labels.ttlNotConfigured;
    }

    if (reason === 'limit_not_configured') {
        const found = error.body.check as SampleCheck | undefined;

        return labels.notConfigured(found?.setting ?? null);
    }

    if (reason === 'acknowledgement_required') {
        return labels.ackRequired;
    }

    if (reason === 'endpoint_not_found') {
        return labels.endpointGone;
    }

    // A value the Endpoint refuses: the server's own message names the parameter.
    return Object.values(error.fields)[0]?.[0] ?? labels.fetchRefused;
}

async function runFetch(): Promise<void> {
    const draftId = props.draftId;
    const claim = props.claim();

    if (running.value) {
        return;
    }

    // Retry on the card is not `aria-disabled` itself: say why nothing happened.
    if (
        fetchBlocked.value !== undefined ||
        draftId === null ||
        claim === null
    ) {
        fetching.value.refusal = fetchBlocked.value ?? labels.needLock;

        return;
    }

    controller?.abort();
    controller = new AbortController();
    const mine = controller;
    const fetchState = fetching.value;

    fetchState.phase = 'running';
    fetchState.refusal = null;
    fetchState.failure = null;

    try {
        const started = await startSampleFetch(
            draftId,
            props.fetchBody(),
            fetchState.ack,
            claim,
        );
        const operation = await pollOperation(
            started.operation_id,
            mine.signal,
        );
        const result = operation.result as Partial<FetchSummary> | null;

        if (operation.status === 'stale') {
            fetchState.phase = 'stale';
            announce(labels.fetchStale, 'polite');

            return;
        }

        if (operation.status === 'expired') {
            fetchState.phase = 'expired';
            announce(labels.fetchExpired, 'polite');

            return;
        }

        if (operation.status === 'succeeded' && result?.ok) {
            let found: Awaited<ReturnType<typeof fetchSample>> = null;

            try {
                found = await fetchSample(draftId);
            } catch (error) {
                if (ended(error)) {
                    throw error;
                }
            }

            if (mine.signal.aborted) {
                return;
            }

            // Gone already (the sample's lifetime, or the Draft was discarded): the fetch has to be made again.
            if (found === null) {
                fetchState.phase = 'expired';

                return;
            }

            state.value.stored = found;
            state.value.pastedAt = new Date(found.pasted_at);
            fetchState.phase = 'ok';
            announce(fetchedLine.value, 'polite');
            emit('fetched', found.request_key === props.requestKey);

            return;
        }

        fetchState.failure = failureOf(result, operation.status);
        fetchState.phase = 'failure';
        await nextTick();
        card.value?.focus();
    } catch (error) {
        if (mine.signal.aborted) {
            return;
        }

        if (ended(error)) {
            fetchState.phase = 'idle';
            emit('auth-error', error);

            return;
        }

        if (error instanceof WizardError) {
            fetchState.phase = 'idle';

            if (error.status === 429) {
                const after = error.body.retry_after;

                startWait(typeof after === 'number' ? after : 5);

                return;
            }

            fetchState.refusal =
                error.status === 422 || error.status === 423
                    ? refusalOf(error)
                    : labels.fetchStartFailed;

            return;
        }

        // The Operation could not be read, or no worker answered: a failed call like any other, with its card.
        fetchState.failure = failureOf(
            null,
            error instanceof PollTimeout ? 'no_worker_responded' : null,
        );
        fetchState.phase = 'failure';
        await nextTick();
        card.value?.focus();
    }
}

function pasteInstead(): void {
    setMode('paste');
    void nextTick(() => document.getElementById(textId)?.focus());
}

onBeforeUnmount(() => {
    controller?.abort();

    if (waitTimer !== null) {
        clearInterval(waitTimer);
    }
});

function setMode(value: string): void {
    // A fetch still running must not land over what the Admin switches to.
    controller?.abort();
    fetching.value.phase =
        fetching.value.phase === 'running' ? 'idle' : fetching.value.phase;
    state.value.mode = value as SampleMode;
}

function onPaste(): void {
    pasted = true;
    state.value.pastedAt = new Date();
}

function onInput(value: string | number): void {
    state.value.text = String(value);
    state.value.problem = null;

    if (state.value.checkedText !== state.value.text) {
        state.value.check = null;
    }

    // The text a paste put in is checked as soon as it is in the field.
    if (pasted) {
        pasted = false;
        void run();
    }
}

function onBlur(): void {
    if (
        state.value.text.trim() !== '' &&
        state.value.checkedText !== state.value.text &&
        !state.value.checking
    ) {
        void run();
    }
}

async function run(): Promise<void> {
    const draftId = props.draftId;
    const asked = state.value.text;

    if (draftId === null || asked.trim() === '') {
        return;
    }

    const seq = (runSeq += 1);
    state.value.checking = true;
    state.value.problem = null;

    try {
        const result = await checkSample(draftId, asked);

        if (seq !== runSeq) {
            return;
        }

        state.value.check = result;
        state.value.checkedText = asked;
        state.value.pastedAt ??= new Date();
        announce(
            result.valid
                ? validMessage.value
                : (invalid.value?.before ?? refusal.value),
            'polite',
        );
    } catch (error) {
        if (seq === runSeq) {
            if (
                error instanceof WizardError &&
                (error.status === 401 || error.status === 419)
            ) {
                emit('auth-error', error);

                return;
            }

            state.value.problem = 'check_failed';
        }
    } finally {
        if (seq === runSeq) {
            state.value.checking = false;
        }
    }
}

async function goToLine(): Promise<void> {
    const where = invalid.value;

    if (where === null) {
        return;
    }

    await nextTick();
    const field = document.getElementById(textId) as HTMLTextAreaElement | null;

    if (field === null) {
        return;
    }

    const caret = caretAt(state.value.text, where.line, where.column);

    field.focus();
    field.setSelectionRange(caret, caret);
}

async function accept(): Promise<void> {
    const draftId = props.draftId;
    const claim = props.claim();

    if (draftId === null || claim === null || state.value.saving) {
        return;
    }

    state.value.saving = true;
    state.value.problem = null;
    const sent = state.value.text;

    try {
        const result = await storeSample(draftId, sent, state.value.ack, claim);

        state.value.stored = result;
        state.value.pastedAt = new Date(result.pasted_at);
        state.value.ack = false;
        announce(labels.accepted, 'polite');
        emit('accepted');
    } catch (error) {
        if (
            error instanceof WizardError &&
            (error.status === 401 || error.status === 419)
        ) {
            emit('auth-error', error);

            return;
        }

        const reason = storeReasonOf(error);

        if (reason !== null) {
            state.value.problem = reason as StoreReason;

            // A refusal that is about the text carries what the check found.
            const found = (error as WizardError).body.check as
                | SampleCheck
                | undefined;

            if (found) {
                state.value.check = found;
                state.value.checkedText = sent;
            }
        } else {
            state.value.problem =
                error instanceof WizardError && error.status === 503
                    ? 'unavailable'
                    : 'store_failed';
        }

        if (error instanceof WizardError && error.status === 423) {
            state.value.problem = 'locked';
        }
    } finally {
        state.value.saving = false;
    }
}

async function retryRestore(): Promise<void> {
    if (props.draftId !== null) {
        await restoreSample(state.value, props.draftId);
    }
}

function setAck(value: boolean | 'indeterminate'): void {
    state.value.ack = value === true;
}
</script>

<template>
    <SectionCard
        :title="labels.title"
        :caption="labels.caption"
        :icon="FileJson"
    >
        <SegmentedControl
            :model-value="sample.mode"
            :options="modeOptions"
            :label="labels.modeLabel"
            data-test="sample-mode"
            @update:model-value="setMode"
        />

        <Banner v-if="earlier" variant="warning">
            <span data-test="earlier-parameters">{{
                labels.earlierParameters
            }}</span>
        </Banner>

        <Banner v-if="outOfDate" variant="warning">
            <span data-test="out-of-date">{{
                blockSourceLabels.outOfDate
            }}</span>
        </Banner>

        <p
            v-if="sample.restoreFailed"
            role="alert"
            class="type-body-sm flex flex-wrap items-center gap-2 text-error-text"
            data-test="restore-failed"
        >
            {{ labels.restoreFailed }}
            <Button
                type="button"
                variant="secondary"
                size="sm"
                data-test="restore-retry"
                @click="retryRestore"
                >{{ labels.retry }}</Button
            >
        </p>

        <div
            v-if="sample.mode === 'fetch'"
            class="grid gap-3"
            data-test="fetch-panel"
        >
            <div class="flex items-start gap-2">
                <Checkbox
                    :id="fetchAckId"
                    :model-value="fetching.ack"
                    required
                    class="mt-1"
                    data-test="fetch-personal-data"
                    :disabled="readOnly"
                    @update:model-value="setFetchAck"
                />
                <div class="grid gap-0.5">
                    <Label :for="fetchAckId">
                        {{ labels.ackLabel }}
                        <span aria-hidden="true" class="text-error-text"
                            >*</span
                        >
                    </Label>
                    <p class="type-caption text-text-muted">
                        {{ labels.fetchAckHint }}
                    </p>
                </div>
            </div>

            <div class="flex flex-col items-start gap-1">
                <Button
                    type="button"
                    variant="secondary"
                    :blocked="fetchBlocked !== undefined || running"
                    :blocked-reason="fetchBlocked"
                    :aria-describedby="
                        running
                            ? fetchStatusId
                            : fetchBlocked
                              ? fetchReasonId
                              : undefined
                    "
                    data-test="fetch"
                    @click="runFetch"
                >
                    {{
                        fetching.wait !== null
                            ? labels.waitButton(fetching.wait)
                            : blockSourceLabels.fetch
                    }}
                </Button>
                <BlockedReason
                    v-if="fetchBlocked && !running"
                    :id="fetchReasonId"
                    >{{ fetchBlocked }}</BlockedReason
                >
            </div>

            <p
                v-if="running"
                :id="fetchStatusId"
                role="status"
                class="type-body-sm text-text-secondary"
                data-test="fetch-running"
            >
                {{ labels.fetching }}
            </p>
            <p
                v-if="fetching.refusal"
                role="alert"
                class="type-body-sm text-error-text"
                data-test="fetch-refusal"
            >
                {{ fetching.refusal }}
            </p>

            <FetchErrorCard
                v-if="fetching.phase === 'failure' && fetching.failure"
                ref="card"
                :code="fetching.failure.code"
                :source="sourceName"
                :heading="labels.fetchFailedTitle"
                :status="fetching.failure.status"
                :request-id="fetching.failure.requestId"
                :host="fetching.failure.host"
                :reason="fetching.failure.reason"
                :size-bytes="fetching.failure.sizeBytes"
                :limit-bytes="fetching.failure.limitBytes"
                :page="fetching.failure.page"
                :pages="fetching.failure.pages"
                @retry="runFetch"
            >
                <template #actions>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        data-test="paste-instead"
                        @click="pasteInstead"
                        >{{ labels.pasteInstead }}</Button
                    >
                </template>
            </FetchErrorCard>

            <div
                v-if="fetching.phase === 'expired'"
                class="flex flex-wrap items-center gap-2"
                data-test="fetch-expired"
            >
                <p class="type-body-sm text-warning-text">
                    {{ labels.fetchExpired }}
                </p>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    data-test="fetch-expired-retry"
                    @click="runFetch"
                    >{{ labels.fetchRetry }}</Button
                >
            </div>
            <div
                v-if="fetching.phase === 'stale'"
                class="flex flex-wrap items-center gap-2"
                data-test="fetch-stale"
            >
                <p class="type-body-sm text-warning-text">
                    {{ labels.fetchStale }}
                </p>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    data-test="fetch-stale-retry"
                    @click="runFetch"
                    >{{ labels.fetchRetry }}</Button
                >
            </div>

            <p
                v-if="fetchedLine"
                class="type-body-sm text-success-text"
                data-test="fetch-ok"
            >
                {{ fetchedLine }}
            </p>
        </div>

        <div v-else class="grid gap-3" data-test="paste">
            <div class="grid gap-1.5">
                <Label :for="textId">{{ labels.textLabel }}</Label>
                <Textarea
                    :id="textId"
                    :model-value="sample.text"
                    rows="10"
                    spellcheck="false"
                    autocomplete="off"
                    autocapitalize="off"
                    wrap="off"
                    :aria-describedby="`${hintId} ${statusId}`"
                    :aria-invalid="
                        check !== null && !check.valid ? 'true' : undefined
                    "
                    class="max-h-96 min-h-48 overflow-auto font-mono text-sm whitespace-pre"
                    data-slot="json-textarea"
                    data-test="json-textarea"
                    :disabled="readOnly"
                    @paste="onPaste"
                    @blur="onBlur"
                    @update:model-value="onInput"
                />
                <p :id="hintId" class="type-caption text-text-muted">
                    {{ labels.textHint }}
                </p>
            </div>

            <div :id="statusId" class="grid gap-2">
                <p
                    v-if="sample.checking"
                    class="type-body-sm text-text-secondary"
                    data-test="json-checking"
                >
                    {{ labels.checking }}
                </p>
                <p
                    v-else-if="validMessage"
                    class="type-body-sm text-success-text"
                    data-test="json-valid"
                >
                    {{ validMessage }}
                </p>
                <p
                    v-else-if="invalid"
                    class="type-body-sm flex flex-wrap items-center gap-x-2 text-error-text"
                    data-test="json-invalid"
                >
                    <span>{{ invalid.before }}</span>
                    {{ ' ' }}
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-test="go-to-line"
                        @click="goToLine"
                        >{{ labels.goToLine(invalid.line) }}</Button
                    >
                </p>
                <p
                    v-else-if="refusal"
                    class="type-body-sm text-error-text"
                    data-test="json-refused"
                >
                    {{ refusal }}
                </p>
                <p
                    v-if="problem"
                    role="alert"
                    class="type-body-sm text-error-text"
                    data-test="sample-problem"
                >
                    {{ problem }}
                </p>
            </div>

            <div
                v-if="check?.valid && !inUse"
                class="grid gap-2"
                data-test="accept"
            >
                <div class="flex items-start gap-2">
                    <Checkbox
                        :id="ackId"
                        :model-value="sample.ack"
                        required
                        class="mt-1"
                        data-test="personal-data"
                        :disabled="readOnly"
                        @update:model-value="setAck"
                    />
                    <div class="grid gap-0.5">
                        <Label :for="ackId">
                            {{ labels.ackLabel }}
                            <span aria-hidden="true" class="text-error-text"
                                >*</span
                            >
                        </Label>
                        <p class="type-caption text-text-muted">
                            {{ labels.ackHint }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-col items-start gap-1">
                    <Button
                        type="button"
                        variant="primary"
                        :blocked="useBlocked !== undefined || sample.saving"
                        :blocked-reason="useBlocked"
                        :aria-describedby="useBlocked ? useReasonId : undefined"
                        data-test="use-sample"
                        @click="accept"
                    >
                        {{
                            sample.saving
                                ? labels.saving
                                : stored
                                  ? labels.useReplace
                                  : labels.use
                        }}
                    </Button>
                    <BlockedReason v-if="useBlocked" :id="useReasonId">{{
                        useBlocked
                    }}</BlockedReason>
                </div>
            </div>
        </div>

        <div v-if="stored" class="grid gap-3" data-test="sample-in-use">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="type-body-sm font-semibold text-text-primary">{{
                    labels.inUse
                }}</span>
                <span
                    class="type-caption text-text-secondary tabular-nums"
                    data-test="sample-summary"
                    >{{
                        stored.origin === 'fetched'
                            ? labels.fetchedSummary(
                                  clockText(stored.pasted_at),
                                  stored.size_bytes,
                              )
                            : labels.summary(
                                  clockText(stored.pasted_at),
                                  stored.size_bytes,
                              )
                    }}</span
                >
                <span
                    class="type-caption rounded-sm bg-info-soft px-2 text-info-strong"
                    data-test="sample-badge"
                    >{{ t('sample-wizard') }}</span
                >
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :aria-expanded="jsonShown"
                    data-test="view-json"
                    @click="jsonShown = !jsonShown"
                    >{{ jsonShown ? labels.hideJson : labels.viewJson }}</Button
                >
            </div>
            <SampleTree
                v-if="jsonShown && stored.shape"
                :shape="stored.shape"
            />
        </div>
    </SectionCard>
</template>
