<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import BlockedReason from '@/components/BlockedReason.vue';
import { Button } from '@/components/ui/button';
import { useShell } from '@/composables/useShell';
import { announce } from '@/lib/announce';
import { clockText } from '@/lib/blockSample';
import type { FetchBody, SampleClaim } from '@/lib/blockSample';
import {
    effectiveStatus,
    fetchValidationReport,
    publishDraft,
    publishReason,
    startValidation,
} from '@/lib/blockValidation';
import type {
    PublishReason,
    PublishedVersion,
    ValidationMismatch,
    ValidationReport,
} from '@/lib/blockValidation';
import { WizardError, blockTypeFor } from '@/lib/blockWizard';
import { ErrorCode } from '@/types/error-codes';
import { DataSourceError, PollTimeout, pollOperation } from '@/lib/dataSources';
import { blockValidateStepLabels as labels } from '@/locales/labels';

// Step 5, Validate (Story 3.18): the server fetches the Draft's real Endpoint live, runs the Draft's plan over the answer and stores a report
// bound to the Draft's revision. This step starts it, shows the version Publish will create (`version-safe`), the result (passed, failed with the
// mismatching paths, unreachable with `live-shape-unreachable`, or stale), and says whether Publish is allowed and, if not, why. A pasted
// sample is never proof; Save draft stays available whenever Publish is blocked. Publish needs `blocks.publish` (otherwise it stays rendered,
// `aria-disabled`, with `perm-publish`) and a passed report for the revision the Draft is at; it asks the server, which freezes and activates the
// version, and reports the published version to the page, which shows the toast and opens Published blocks. After a success Publish stays busy
// until the page has left, so a second click cannot ask for a version that is already live.
const props = defineProps<{
    draftId: string | null;
    typeKey: string;
    // The version Publish will create ("1.0").
    version: string;
    // The Draft's revision now, so a later autosave makes the report read stale at once.
    revision: number | null;
    // A real Data Source and Endpoint are chosen in Step 2; a pasted sample alone is never proof.
    hasSource: boolean;
    pasted: boolean;
    // What the request is now (its dates) and the lock claim of a save; null when this tab may not write.
    fetchBody: () => FetchBody;
    claim: () => SampleClaim | null;
    // Saves what is pending so the validation is of the Draft as it stands. Resolves whether it saved.
    save: () => Promise<boolean>;
    // Why nothing can be started (read-only, offline, published).
    blocked?: string;
}>();

const emit = defineEmits<{
    (e: 'auth-error', error: unknown): void;
    // A refused start the page handles like any save failure (a lost lock, a conflict, a missing Draft).
    (e: 'error', error: unknown): void;
    (e: 'back'): void;
    (e: 'save-draft'): void;
    // The version is live: the page shows `publish-success` and opens Published blocks.
    (e: 'published', version: PublishedVersion): void;
}>();

const { t } = useI18n();
const { can } = useShell();

const report = ref<ValidationReport | null>(null);
const loading = ref(true);
const loadFailed = ref(false);
const running = ref(false);
const publishing = ref(false);
const problem = ref<string | null>(null);
const resultRef = ref<HTMLElement | null>(null);
const resultId = useId();
const reasonId = useId();
const validateReasonId = useId();
const publishReasonId = useId();
let controller: AbortController | null = null;
let seq = 0;

const status = computed(() => effectiveStatus(report.value, props.revision));
const reason = computed<PublishReason | undefined>(() =>
    publishReason(props.hasSource, report.value, props.revision, running.value),
);
const ready = computed(() => reason.value === undefined);

const blockedText: Record<PublishReason, string> = {
    no_source: labels.blockedNoSource,
    not_validated: labels.blockedNotValidated,
    running: labels.blockedRunning,
    failed: labels.blockedFailed,
    strict: labels.blockedStrict,
    unreachable: labels.blockedUnreachable,
    stale: labels.blockedStale,
};

// Why the report does or does not allow Publish, in words (the readiness card).
const publishBlocked = computed<string>(() =>
    reason.value === undefined
        ? labels.publishReady
        : reason.value === 'unreachable'
          ? t('live-shape-unreachable')
          : blockedText[reason.value],
);

// Why the Publish action itself cannot be used now: the permission first (`perm-publish`), then the report, then what blocks every change here
// (read-only, offline, published); undefined when it can run.
const mayPublish = computed(() => can.value['blocks.publish'] === true);
const publishActionBlocked = computed<string | undefined>(() => {
    if (!mayPublish.value) {
        return t('perm-publish');
    }

    if (publishing.value) {
        return labels.publishing;
    }

    if (reason.value !== undefined) {
        return publishBlocked.value;
    }

    return (
        props.blocked ??
        (props.draftId === null ? labels.publishNoDraft : undefined)
    );
});

// Why Validate cannot run: no real source, or nothing can be changed here right now.
const validateBlocked = computed<string | undefined>(() =>
    !props.hasSource
        ? props.pasted
            ? labels.pastedOnly
            : labels.blockedNoSource
        : (props.blocked ??
          (props.draftId === null ? labels.startFailed : undefined)),
);

const slotLabels = computed<Record<string, string>>(() => {
    const out: Record<string, string> = {};

    for (const slot of blockTypeFor(props.typeKey)?.slots ?? []) {
        out[slot.key] = slot.label;
    }

    return out;
});

function slotName(slot: string | null): string {
    return slot === null ? '' : (slotLabels.value[slot] ?? slot);
}

// One line per mismatch, in words; the path is the stored path the plan reads, never a value.
function mismatchText(item: ValidationMismatch): string {
    const slot = slotName(item.slot);
    const path = item.path ?? '';
    const lines = labels.mismatch;

    switch (item.type) {
        case 'missing':
            return lines.missing(slot, path);
        case 'wrong_type':
            return lines.wrong_type(slot, path, item.expected ?? 'value');
        case 'not_single_value':
            return lines.not_single_value(slot, path);
        case 'required_slot_unmapped':
            return lines.required_slot_unmapped(slot);
        case 'no_plan':
            return lines.no_plan;
        default:
            return lines.invalid(path || slot);
    }
}

const title = computed(() => {
    const current = report.value;

    if (current === null || status.value === null) {
        return '';
    }

    if (status.value === 'passed') {
        return labels.passedTitle;
    }

    if (status.value === 'stale') {
        return labels.staleTitle;
    }

    if (status.value === 'unreachable') {
        return labels.unreachableTitle;
    }

    return current.reason === 'strict_schema'
        ? labels.strictTitle
        : current.reason === 'unbound_publishable'
          ? labels.unboundTitle
          : current.reason === 'context_missing'
            ? labels.contextMissingTitle
            : labels.failedTitle;
});

const body = computed(() => {
    const current = report.value;

    if (current === null || status.value === null) {
        return '';
    }

    if (status.value === 'passed') {
        return labels.passedBody(props.version);
    }

    if (status.value === 'stale') {
        return labels.staleBody;
    }

    if (status.value === 'unreachable') {
        return t('live-shape-unreachable');
    }

    const specific: Record<string, string> = {
        unbound_publishable: labels.unboundBody,
        context_missing: labels.contextMissingBody,
        guard_exceeded: labels.guardExceeded,
        setting_not_configured: labels.settingMissing,
        invalid_input: labels.invalidInput,
        engine_error: labels.engineError,
    };

    return specific[current.reason ?? ''] ?? '';
});

// The list is the report's own mismatches, shown only for the revision it was made for.
const mismatches = computed<ValidationMismatch[]>(() =>
    status.value === 'failed' && report.value ? report.value.mismatches : [],
);

function ended(error: unknown): boolean {
    return (
        (error instanceof WizardError || error instanceof DataSourceError) &&
        (error.status === 401 || error.status === 419)
    );
}

async function load(): Promise<void> {
    const id = props.draftId;
    const mine = (seq += 1);

    if (id === null) {
        loading.value = false;

        return;
    }

    loading.value = true;
    loadFailed.value = false;

    try {
        const found = await fetchValidationReport(id);

        if (mine === seq) {
            report.value = found;
        }
    } catch (error) {
        if (mine !== seq) {
            return;
        }

        if (ended(error)) {
            emit('auth-error', error);
        } else {
            loadFailed.value = true;
        }
    } finally {
        if (mine === seq) {
            loading.value = false;
        }
    }
}

function refusalOf(error: WizardError): string {
    const why = error.body.reason;

    if (error.status === 422 && why === 'no_real_source') {
        return labels.refusedNoSource;
    }

    if (error.status === 422 && why === 'setting_not_configured') {
        return labels.settingNotConfigured(
            typeof error.body.setting === 'string' ? error.body.setting : null,
        );
    }

    if (error.status === 429) {
        const after = error.body.retry_after;

        return labels.throttled(typeof after === 'number' ? after : 5);
    }

    return labels.startFailed;
}

async function run(): Promise<void> {
    const id = props.draftId;

    if (running.value) {
        return;
    }

    if (validateBlocked.value !== undefined || id === null) {
        problem.value = validateBlocked.value ?? labels.startFailed;

        return;
    }

    controller?.abort();
    controller = new AbortController();
    const mine = controller;

    problem.value = null;
    running.value = true;
    announce(labels.startedAnnounce, 'polite');

    try {
        // What is validated is the Draft as stored: pending changes are saved first, and a save that fails stops here.
        if (!(await props.save())) {
            return;
        }

        const claim = props.claim();

        if (claim === null) {
            problem.value = props.blocked ?? labels.startFailed;

            return;
        }

        const dates = props.fetchBody();
        const started = await startValidation(
            id,
            { range: dates.range, period: dates.period },
            claim,
        );
        const operation = await pollOperation(
            started.operation_id,
            mine.signal,
        );

        if (mine.signal.aborted) {
            return;
        }

        if (operation.status === 'expired') {
            problem.value = labels.expired;
        }

        // The report is the authority, but only this run's: an older one is never presented as its result.
        const stored = (operation.result as { report_id?: unknown } | null)
            ?.report_id;
        const found = await fetchValidationReport(id, mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        if (
            found !== null &&
            typeof stored === 'string' &&
            found.id === stored
        ) {
            report.value = found;
            await nextTick();
            resultRef.value?.focus();
        } else {
            // The run stored no report (it failed, was cancelled or hit a worker error), or a newer one has landed meanwhile.
            problem.value = problem.value ?? labels.checkFailed;
        }
    } catch (error) {
        if (mine.signal.aborted) {
            return;
        }

        if (ended(error)) {
            emit('auth-error', error);

            return;
        }

        if (error instanceof WizardError) {
            if (error.status === 422 || error.status === 429) {
                problem.value = refusalOf(error);

                return;
            }

            if (error.status !== 0 && error.status < 500) {
                emit('error', error);

                return;
            }

            problem.value = labels.startFailed;

            return;
        }

        problem.value =
            error instanceof PollTimeout || error instanceof DataSourceError
                ? labels.checkFailed
                : labels.startFailed;
    } finally {
        if (controller === mine) {
            running.value = false;
        }
    }
}

async function publish(): Promise<void> {
    const id = props.draftId;

    if (
        publishing.value ||
        publishActionBlocked.value !== undefined ||
        id === null
    ) {
        return;
    }

    controller?.abort();
    controller = new AbortController();
    const mine = controller;

    problem.value = null;
    publishing.value = true;
    let done = false;
    announce(labels.publishing, 'polite');

    try {
        // What is published is the Draft as stored: pending changes are saved first. A save moves the revision, so the report is judged again.
        if (!(await props.save())) {
            return;
        }

        await nextTick();

        if (!ready.value) {
            problem.value = labels.publishStale;

            return;
        }

        const claim = props.claim();

        if (claim === null) {
            problem.value = props.blocked ?? labels.publishFailed;

            return;
        }

        const version = await publishDraft(id, claim);

        done = true;
        emit('published', version);
    } catch (error) {
        if (ended(error)) {
            emit('auth-error', error);

            return;
        }

        if (error instanceof WizardError) {
            if (error.status === 403) {
                problem.value = labels.publishDenied;
            } else if (
                error.status === 409 &&
                error.code === ErrorCode.BlocksPublishNotReady
            ) {
                // The report no longer allows it, or the block cannot be frozen as it is: say which, and show the report as the server has it.
                const why = error.body.reason;

                problem.value =
                    why === 'fields_unresolved'
                        ? labels.publishFieldsUnresolved
                        : why === 'block_type_unavailable'
                          ? labels.publishTypeUnavailable
                          : labels.publishNotReady;
                await load();
            } else if (error.code === ErrorCode.BlocksPublishPending) {
                // Frozen and valid, only its activation is outstanding: not a save failure, and publishing again completes it.
                problem.value = labels.publishPending;
            } else if (error.status === 429) {
                const after = error.body.retry_after;

                problem.value = labels.throttled(
                    typeof after === 'number' ? after : 5,
                );
            } else if (error.status !== 0 && error.status < 500) {
                // A lost lock, a published or missing Draft: the page handles it like any save failure.
                emit('error', error);
            } else {
                problem.value = labels.publishFailed;
            }

            return;
        }

        problem.value = labels.publishFailed;
    } finally {
        if (controller === mine && !done) {
            publishing.value = false;
        }
    }
}

onMounted(() => void load());
watch(
    () => props.draftId,
    (id, prev) => {
        if (id !== null && id !== prev) {
            void load();
        }
    },
);
onBeforeUnmount(() => {
    seq += 1;
    controller?.abort();
});

const validatedAt = computed(() =>
    report.value ? clockText(report.value.validated_at) : '',
);
</script>

<template>
    <section
        class="flex flex-col gap-4"
        data-test="validate-step"
        :aria-label="labels.title"
    >
        <header class="flex flex-col gap-1">
            <h2 class="type-title-md text-text-primary">{{ labels.title }}</h2>
            <p class="type-body-sm text-text-secondary">{{ labels.caption }}</p>
        </header>

        <section
            class="flex flex-col gap-2 rounded-lg border border-border-default bg-surface-card p-4"
            data-test="version-card"
        >
            <h3 class="type-title-sm text-text-primary">
                {{ labels.versionTitle }}
            </h3>
            <p class="type-body-md text-text-primary" data-test="version">
                {{ labels.versionValue(version) }}
            </p>
            <p class="type-caption text-text-muted" data-test="version-safe">
                {{ t('version-safe', { version }) }}
            </p>
        </section>

        <section
            class="flex flex-col gap-3 rounded-lg border border-border-default bg-surface-card p-4"
            data-test="validation"
        >
            <div class="flex flex-wrap items-start gap-3">
                <span class="inline-flex flex-col items-start gap-1">
                    <Button
                        type="button"
                        variant="secondary"
                        :blocked="validateBlocked !== undefined || running"
                        :blocked-reason="
                            running ? labels.running : validateBlocked
                        "
                        :aria-describedby="
                            validateBlocked ? validateReasonId : undefined
                        "
                        data-test="validate"
                        @click="run"
                    >
                        {{
                            running
                                ? labels.running
                                : report !== null
                                  ? labels.validateAgain
                                  : labels.validate
                        }}
                    </Button>
                    <BlockedReason
                        v-if="validateBlocked"
                        :id="validateReasonId"
                        data-test="validate-reason"
                        >{{ validateBlocked }}</BlockedReason
                    >
                </span>
            </div>

            <p
                v-if="loadFailed"
                role="alert"
                class="type-body-sm text-error-text"
                data-test="load-failed"
            >
                {{ labels.loadFailed }}
            </p>
            <p
                v-if="problem"
                role="alert"
                class="type-body-sm text-error-text"
                data-test="validate-problem"
            >
                {{ problem }}
            </p>

            <div
                :id="resultId"
                ref="resultRef"
                tabindex="-1"
                role="status"
                aria-live="polite"
                class="flex flex-col gap-2 empty:hidden"
                data-test="validation-result"
                :data-status="status ?? undefined"
            >
                <template v-if="report !== null && !running && !loading">
                    <h3
                        class="type-title-sm"
                        :class="
                            status === 'passed'
                                ? 'text-success-text'
                                : 'text-error-text'
                        "
                        data-test="result-title"
                    >
                        {{ title }}
                    </h3>
                    <p
                        v-if="body"
                        class="type-body-sm text-text-secondary"
                        data-test="result-body"
                    >
                        {{ body }}
                    </p>
                    <p
                        v-if="status === 'unreachable' && report.reason"
                        class="type-caption text-text-muted"
                        data-test="unreachable-reason"
                    >
                        {{ labels.unreachableReason(report.reason) }}
                    </p>
                    <div
                        v-if="mismatches.length > 0"
                        class="flex flex-col gap-1"
                    >
                        <h4
                            class="type-body-sm font-semibold text-text-primary"
                        >
                            {{ labels.mismatchesTitle }}
                        </h4>
                        <ul class="flex flex-col gap-1" data-test="mismatches">
                            <li
                                v-for="(item, index) in mismatches"
                                :key="index"
                                class="type-body-sm text-text-primary"
                                data-test="mismatch"
                                :data-slot-key="item.slot ?? undefined"
                                :data-type="item.type"
                            >
                                {{ mismatchText(item) }}
                            </li>
                        </ul>
                    </div>
                    <p
                        class="type-caption text-text-muted"
                        data-test="validated-at"
                    >
                        {{ labels.validatedAt(validatedAt) }}
                    </p>
                </template>
            </div>
        </section>

        <section
            class="flex flex-col gap-2 rounded-lg border border-border-default bg-surface-card p-4"
            :data-state="ready ? 'ready' : 'blocked'"
            data-test="publish-readiness"
        >
            <h3
                class="type-title-sm"
                :class="ready ? 'text-success-text' : 'text-text-primary'"
                data-test="readiness-title"
            >
                {{ ready ? labels.readyTitle : labels.notReadyTitle }}
            </h3>
            <p
                v-if="ready"
                class="type-body-sm text-text-secondary"
                data-test="readiness-body"
            >
                {{ labels.readyBody(version) }}
            </p>
            <p
                :id="reasonId"
                :class="
                    ready
                        ? 'type-caption text-text-muted'
                        : 'type-body-sm text-text-secondary'
                "
                data-test="publish-reason"
            >
                {{ publishBlocked }}
            </p>
        </section>

        <div
            class="flex flex-wrap items-start justify-end gap-2"
            data-test="step-5-footer"
        >
            <Button
                type="button"
                variant="secondary"
                data-test="back"
                @click="emit('back')"
            >
                {{ labels.back }}
            </Button>
            <Button
                type="button"
                variant="secondary"
                :blocked="blocked !== undefined"
                :blocked-reason="blocked"
                data-test="save-draft-step"
                @click="emit('save-draft')"
            >
                {{ labels.saveDraft }}
            </Button>
            <span class="inline-flex flex-col items-start gap-1">
                <Button
                    type="button"
                    :blocked="publishActionBlocked !== undefined"
                    :blocked-reason="publishActionBlocked"
                    :aria-describedby="
                        publishActionBlocked === undefined
                            ? undefined
                            : mayPublish
                              ? reasonId
                              : publishReasonId
                    "
                    data-test="publish-step"
                    @click="publish"
                >
                    {{ publishing ? labels.publishing : labels.publish }}
                </Button>
                <BlockedReason
                    v-if="!mayPublish"
                    :id="publishReasonId"
                    data-test="publish-denied"
                    >{{ t('perm-publish') }}</BlockedReason
                >
            </span>
        </div>
    </section>
</template>
