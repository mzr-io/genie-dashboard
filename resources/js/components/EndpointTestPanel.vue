<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, useId } from 'vue';
import BlockedReason from '@/components/BlockedReason.vue';
import FetchErrorCard from '@/components/FetchErrorCard.vue';
import FormField from '@/components/FormField.vue';
import JsonSampleViewer from '@/components/JsonSampleViewer.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DataSourceError, PollTimeout, pollOperation } from '@/lib/dataSources';
import type { ConnectionTestCode } from '@/lib/dataSources';
import {
    EndpointError,
    fetchSample,
    startEndpointTest,
    testFields,
} from '@/lib/endpoints';
import type { Endpoint, Sample } from '@/lib/endpoints';
import { SIGN_IN_URL } from '@/lib/session';
import { endpointLabels as labels } from '@/locales/labels';

// Test an Endpoint (Story 2.10; UX-DR-135, 25, 26, 70, 276, 279, 282). One labelled input per parameter (a date or period
// parameter takes a date), Run test, and then, while the `sample_fetch` Operation runs, a polite "Testing…". On success the
// status and latency are announced politely ("✓ 200 · 184 ms") and the body is shown in the JSON sample viewer; on failure the
// shared `fetch-error-card` (its technical details, Copy request ID, Retry) takes focus on its title. A result for a revision
// that is no longer current says so, with a Retry, and shows no body. The server renders the request and checks the values:
// this component only sends them. An empty path parameter stops the test here (`aria-disabled`, naming the parameter) and
// again on the server (422). The body lives only in this component's memory and goes with it.
const props = defineProps<{
    dataSourceId: string;
    endpoint: Endpoint;
    sourceName: string;
}>();

const emit = defineEmits<{ (e: 'gone'): void }>();

type Failure = {
    code: ConnectionTestCode;
    status: number | null;
    requestId: string | null;
    host: string | null;
    reason: string | null;
    sizeBytes: number | null;
    limitBytes: number | null;
};

type Outcome =
    | { kind: 'ok'; sample: Sample; line: string }
    | { kind: 'failure'; failure: Failure }
    | { kind: 'stale'; current: number | null }
    | { kind: 'expired' };

const prefix = useId();
const fields = computed(() => testFields(props.endpoint));
const values = reactive<Record<string, string>>({});
const errors = reactive<Record<string, string>>({});
const running = ref(false);
const waitSeconds = ref<number | null>(null);
// A refusal that names no input (the endpoint, the values as a whole), and why a Retry did nothing.
const formError = ref<string | null>(null);
const notice = ref<string | null>(null);
const outcome = ref<Outcome | null>(null);
const cardRef = ref<InstanceType<typeof FetchErrorCard> | null>(null);
const reasonId = useId();
const staleTitle = ref<HTMLElement | null>(null);
const staleId = useId();
let controller: AbortController | null = null;
let waitTimer: ReturnType<typeof setTimeout> | null = null;

for (const field of testFields(props.endpoint)) {
    values[field.key] = field.initial;
}

const fieldId = (key: string): string =>
    `${prefix}-${key.replace(/[^A-Za-z0-9_-]/g, '_')}`;

// The first path parameter without a value: nothing is sent until it has one.
const missingPath = computed(
    () =>
        fields.value.find(
            (field) => field.path && (values[field.key] ?? '') === '',
        ) ?? null,
);

const reason = computed<string | undefined>(() =>
    running.value
        ? labels.testRunning
        : waitSeconds.value !== null
          ? labels.testThrottled(waitSeconds.value)
          : missingPath.value
            ? labels.testMissing(missingPath.value.name)
            : undefined,
);

const okLine = computed(() =>
    outcome.value?.kind === 'ok' ? outcome.value.line : '',
);

function leaveForSignIn(error: unknown): boolean {
    if (
        (error instanceof EndpointError || error instanceof DataSourceError) &&
        (error.status === 401 || error.status === 419)
    ) {
        window.location.assign(SIGN_IN_URL);

        return true;
    }

    return false;
}

function throttle(seconds: number): void {
    waitSeconds.value = Math.max(1, Math.ceil(seconds));

    if (waitTimer) {
        clearTimeout(waitTimer);
    }

    waitTimer = setTimeout(() => {
        waitSeconds.value = null;
        waitTimer = null;
    }, waitSeconds.value * 1000);
}

function clearErrors(): void {
    for (const key of Object.keys(errors)) {
        delete errors[key];
    }
}

// The server names the parameter as `values.{key}`: each message goes to its input, and focus to the first invalid one.
async function showFieldErrors(error: EndpointError): Promise<boolean> {
    let first: string | null = null;

    for (const [field, messages] of Object.entries(error.errors)) {
        const key = field.startsWith('values.')
            ? field.slice('values.'.length)
            : null;

        if (key !== null && fields.value.some((f) => f.key === key)) {
            errors[key] = messages[0] ?? '';
            first ??= key;
        }
    }

    if (first === null) {
        return false;
    }

    await nextTick();
    document.getElementById(fieldId(first))?.focus();

    return true;
}

async function failWith(failure: Failure): Promise<void> {
    outcome.value = { kind: 'failure', failure };
    await nextTick();
    cardRef.value?.focus();
}

const generic = (
    status: number | null,
    requestId: string | null,
    reasonCode: string | null,
): Failure => ({
    code: 'fetch-failed',
    status,
    requestId,
    host: null,
    reason: reasonCode,
    sizeBytes: null,
    limitBytes: null,
});

async function run(): Promise<void> {
    if (running.value || waitSeconds.value !== null || missingPath.value) {
        // Retry on a card is not `aria-disabled` itself: say why nothing happened.
        notice.value = reason.value ?? null;

        return;
    }

    notice.value = null;
    formError.value = null;
    clearErrors();
    controller?.abort();
    controller = new AbortController();
    const mine = controller;
    let accepted = false;
    running.value = true;

    try {
        const started = await startEndpointTest(
            props.dataSourceId,
            props.endpoint.endpoint_id,
            { ...values },
        );

        accepted = true;
        // The earlier result stays until a new test is accepted: a refused start (422, 429) leaves it, and its Retry, in place.
        outcome.value = null;
        const operation = await pollOperation(
            started.operation_id,
            mine.signal,
        );
        const result = operation.result;

        if (operation.status === 'stale') {
            outcome.value = {
                kind: 'stale',
                current: result?.endpoint_revision ?? null,
            };
            await nextTick();
            staleTitle.value?.focus();

            return;
        }

        if (operation.status === 'succeeded' && result?.ok) {
            try {
                const sample = await fetchSample(
                    props.dataSourceId,
                    props.endpoint.endpoint_id,
                    started.operation_id,
                    mine.signal,
                );

                outcome.value = {
                    kind: 'ok',
                    sample,
                    line: labels.testOk(
                        sample.status,
                        result.latency_ms ?? sample.latency_ms,
                    ),
                };
            } catch (error) {
                if (mine.signal.aborted || leaveForSignIn(error)) {
                    return;
                }

                // Gone (expired, replaced by a newer revision, or never kept): the test has to be run again.
                outcome.value = { kind: 'expired' };
            }

            return;
        }

        // A result that never came (expired) is a failed call like any other.
        await failWith({
            code: result?.code ?? 'fetch-failed',
            status: result?.status ?? null,
            requestId: result?.request_id ?? null,
            host: result?.host ?? null,
            reason: result?.reason ?? operation.status,
            sizeBytes: result?.size_bytes ?? null,
            limitBytes: result?.limit_bytes ?? null,
        });
    } catch (error) {
        if (mine.signal.aborted || leaveForSignIn(error)) {
            return;
        }

        if (error instanceof EndpointError) {
            if (error.status === 422) {
                if (await showFieldErrors(error)) {
                    return;
                }

                const first = Object.values(error.errors)[0]?.[0];

                if (first) {
                    formError.value = first;

                    return;
                }
            }

            if (error.status === 429 && !accepted) {
                throttle(error.retryAfter ?? 5);

                return;
            }

            if (error.status === 404 && !accepted) {
                emit('gone');

                return;
            }
        }

        await failWith(
            generic(
                error instanceof EndpointError ||
                    error instanceof DataSourceError
                    ? error.status > 0
                        ? error.status
                        : null
                    : null,
                error instanceof EndpointError ||
                    error instanceof DataSourceError
                    ? error.requestId
                    : null,
                error instanceof PollTimeout ? 'no_worker_responded' : null,
            ),
        );
    } finally {
        if (controller === mine) {
            running.value = false;
        }
    }
}

onBeforeUnmount(() => {
    controller?.abort();

    if (waitTimer) {
        clearTimeout(waitTimer);
    }
});

defineExpose({ run });
</script>

<template>
    <div class="grid gap-6" data-test="endpoint-test">
        <form class="grid gap-6" novalidate @submit.prevent="run">
            <fieldset class="grid gap-3">
                <legend class="type-title-md mb-1 text-text-primary">
                    {{ labels.testValues }}
                </legend>
                <p class="type-caption text-text-muted">
                    {{ labels.testValuesHelper }}
                </p>
                <p
                    v-if="fields.length === 0"
                    class="type-body-sm text-text-muted"
                    data-test="test-values-none"
                >
                    {{ labels.testValuesNone }}
                </p>
                <FormField
                    v-for="field in fields"
                    :id="fieldId(field.key)"
                    :key="field.key"
                    :label="
                        field.header
                            ? labels.testFieldHeader(field.name)
                            : field.name
                    "
                    :helper="
                        field.bound
                            ? labels.testFieldHint(field.bound)
                            : undefined
                    "
                    :error="errors[field.key] ?? null"
                    #default="{ field: attrs }"
                >
                    <Input
                        v-bind="attrs"
                        v-model="values[field.key]"
                        :type="field.type"
                        :name="field.key"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        class="max-w-md font-mono"
                        data-test="test-value"
                        :data-field="field.key"
                        @input="delete errors[field.key]"
                    />
                </FormField>
            </fieldset>

            <div class="grid gap-2">
                <div class="flex flex-wrap items-center gap-3">
                    <Button
                        type="submit"
                        :blocked="reason !== undefined"
                        :blocked-reason="reason"
                        :aria-describedby="reason ? reasonId : undefined"
                        data-test="run-test"
                    >
                        {{ labels.testRun }}
                    </Button>
                </div>
                <BlockedReason v-if="reason" :id="reasonId">{{
                    reason
                }}</BlockedReason>
            </div>
        </form>

        <p
            v-if="formError"
            role="alert"
            class="type-body-sm text-error-text"
            data-test="form-error"
        >
            {{ formError }}
        </p>

        <p
            v-if="notice"
            role="alert"
            class="type-body-sm text-text-secondary"
            data-test="retry-blocked"
        >
            {{ notice }}
        </p>

        <!-- One polite status region: "Testing…" while the test runs, then "✓ 200 · 184 ms". -->
        <div role="status" aria-live="polite" data-test="test-status">
            <p
                v-if="running"
                class="type-body-sm text-text-secondary"
                data-test="testing"
            >
                {{ labels.testing }}
            </p>
            <p
                v-else-if="okLine"
                class="type-body-sm font-medium text-success-text"
                data-test="test-ok"
            >
                {{ okLine }}
            </p>
        </div>

        <JsonSampleViewer
            v-if="outcome?.kind === 'ok'"
            :body="outcome.sample.body"
            :status="outcome.sample.status"
            :latency-ms="outcome.sample.latency_ms"
        />

        <FetchErrorCard
            v-else-if="outcome?.kind === 'failure'"
            ref="cardRef"
            :heading="labels.testFailedTitle"
            :code="outcome.failure.code"
            :source="sourceName"
            :status="outcome.failure.status"
            :request-id="outcome.failure.requestId"
            :host="outcome.failure.host"
            :reason="outcome.failure.reason"
            :size-bytes="outcome.failure.sizeBytes"
            :limit-bytes="outcome.failure.limitBytes"
            @retry="run"
        />

        <section
            v-else-if="outcome?.kind === 'stale'"
            role="group"
            :aria-labelledby="staleId"
            data-test="sample-stale"
            class="grid gap-3 rounded-md border-l-[3px] border-warning bg-warning-soft p-4"
        >
            <h3
                :id="staleId"
                ref="staleTitle"
                tabindex="-1"
                class="type-title-sm text-text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {{ labels.testStale(outcome.current) }}
            </h3>
            <div>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    data-test="stale-retry"
                    @click="run"
                >
                    {{ labels.testStaleRetry }}
                </Button>
            </div>
        </section>

        <p
            v-else-if="outcome?.kind === 'expired'"
            role="alert"
            class="type-body-sm text-text-secondary"
            data-test="sample-expired"
        >
            {{ labels.sampleExpired }}
        </p>
    </div>
</template>
