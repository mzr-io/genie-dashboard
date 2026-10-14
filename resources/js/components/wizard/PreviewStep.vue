<script setup lang="ts">
import KpiRenderer from "@block-types/kpi-card/v1/Renderer.vue";
import type { KpiCardPayload } from "@block-types/kpi-card/v1/payload";
import { usePage } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref, useId, watch } from "vue";
import SegmentedControl from "@/components/SegmentedControl.vue";
import BlockedReason from "@/components/BlockedReason.vue";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import FetchAsUserPanel from "@/components/wizard/FetchAsUserPanel.vue";
import { announce } from "@/lib/announce";
import { fetchMapping } from "@/lib/blockMapping";
import type { ChecklistSlot } from "@/lib/blockMapping";
import { createPreviewRunner, fetchPreview } from "@/lib/blockPreview";
import { rendererFor } from "@/lib/blockRenderers";
import type { PreviewProblem, PreviewResult } from "@/lib/blockPreview";
import { previewWarnings } from "@/lib/blockPreviewWarnings";
import type { FetchBody } from "@/lib/blockSample";
import { asUserRef, asUserUsable, endAsUser } from "@/lib/blockUserSample";
import type { AsUserState } from "@/lib/blockUserSample";
import { WizardError } from "@/lib/blockWizard";
import { expandKpiPayload } from "@/lib/textTemplate";
import type { DisplayConfig } from "@/lib/blockWizard";
import {
    blockAsUserLabels,
    blockPreviewLabels,
    blockPreviewStepLabels as labels,
    blockWizardLabels,
} from "@/locales/labels";

// Step 4, Preview (Story 3.16): the Draft's payload from the preview endpoint, drawn by the production kpi-card Renderer (no second renderer)
// full size and inside a 12-column, 14 px gap sample grid, on Desktop, Tablet and Mobile, in each state. The warnings come from the mapping
// checklist and the preview; each is acknowledged with a checkbox, kept by fingerprint in `draft_ui_state` (the page stores the list). The step
// reads the mapping and the preview afresh every time it opens, so a changed mapping shows and the acknowledgements of changed warnings go.
const props = withDefaults(
    defineProps<{
        draftId: string | null;
        name: string;
        description: string;
        display: DisplayConfig;
        // The acknowledged warning fingerprints stored in `draft_ui_state`.
        acknowledged: string[];
        // Why nothing can be changed (read-only, offline, published).
        blocked?: string;
        // Preview as (Story 3.17): the page's Fetch as user state, what the request is now and its key, for the control and for telling that a
        // result was taken with other parameters.
        asUser: AsUserState;
        requestKey: string;
        fetchBody: () => FetchBody;
        sourceName: string;
        fetchReason?: string;
        // The Block Type being drawn: its own Renderer draws the Block.
        typeKey?: string;
    }>(),
    { blocked: undefined, fetchReason: undefined, typeKey: "kpi-card" },
);

const emit = defineEmits<{
    (e: "update:acknowledged", value: string[]): void;
    // Why Continue cannot leave this step yet, or undefined.
    (e: "gate", reason: string | undefined): void;
    (e: "auth-error", error: unknown): void;
    // The fingerprints of the warnings now listed, or null while they are not known.
    (e: "live", ids: string[] | null): void;
    (e: "back"): void;
    (e: "continue"): void;
}>();

const device = ref("desktop");
const state = ref("normal");
const result = ref<PreviewResult | null>(null);
const slots = ref<ChecklistSlot[] | null>(null);
const problem = ref<PreviewProblem | null>(null);
const mappingFailed = ref(false);
const mappingPending = ref(true);
const previewPending = ref(true);
// Settles whenever both answers (or failures) are in, in either order.
const loading = computed(() => mappingPending.value || previewPending.value);
const gridId = useId();
const warningsId = useId();
const reasonId = useId();

const GRID_COLUMNS = 12;
const GRID_GAP = 14;
const CANVAS: Record<string, string> = {
    desktop: "100%",
    tablet: "768px",
    mobile: "375px",
};
const HEIGHT_PX: Record<string, number> = {
    small: 180,
    medium: 360,
    large: 540,
};

// ---- Loading ------------------------------------------------------------------------------------------------------

let mappingSeq = 0;

const runner = createPreviewRunner({
    load: (signal) =>
        props.draftId === null
            ? Promise.reject(new WizardError(0))
            : fetchPreview(props.draftId, signal),
    onStart() {
        problem.value = null;
    },
    onResult(next) {
        result.value = next;
        problem.value = null;
        previewPending.value = false;
    },
    onProblem(next) {
        problem.value = next;
        result.value = null;
        previewPending.value = false;

        if (next.kind === "session") {
            emit("auth-error", new WizardError(next.status ?? 401));
        }
    },
});

async function loadMapping(): Promise<void> {
    const id = props.draftId;
    const mine = (mappingSeq += 1);

    if (id === null) {
        mappingPending.value = false;
        mappingFailed.value = true;

        return;
    }

    try {
        const { data } = await fetchMapping(id, { background: true });

        if (mine !== mappingSeq) {
            return;
        }

        slots.value = data?.checklist
            ? data.checklist.groups.flatMap((group) => group.slots)
            : null;
        mappingFailed.value = slots.value === null;
        mappingPending.value = false;
    } catch (error) {
        if (mine !== mappingSeq) {
            return;
        }

        slots.value = null;
        mappingFailed.value = true;
        mappingPending.value = false;

        if (
            error instanceof WizardError &&
            (error.status === 401 || error.status === 419)
        ) {
            emit("auth-error", error);
        }
    }
}

function load(): void {
    mappingPending.value = true;
    previewPending.value = true;
    mappingFailed.value = false;
    void loadMapping();
    runner.request(true);
}

onMounted(load);
watch(
    () => props.draftId,
    (id, prev) => {
        if (id !== null && id !== prev) {
            load();
        }
    },
);
onBeforeUnmount(() => {
    mappingSeq += 1;
    runner.cancel();
    userRunner.cancel();
});

// ---- Preview as (Story 3.17) --------------------------------------------------------------------------------------

// The warnings and Continue's gate always come from the shared sample (their acknowledgements are keyed to it); a Fetch as user result only
// changes what the Block shows. It is computed by the same preview endpoint, from the Operation's ephemeral blob.
const userResult = ref<PreviewResult | null>(null);
const usingUser = computed(() => asUserUsable(props.asUser, props.requestKey));
// The as-user result is what is displayed only once it has arrived; until then (or if it failed) the shared data shows, labelled as shared.
const displayingUser = computed(
    () => usingUser.value && userResult.value !== null,
);
const userPending = ref(false);
const shown = computed<PreviewResult | null>(() =>
    displayingUser.value ? userResult.value : result.value,
);

const userRunner = createPreviewRunner({
    load: (signal) => {
        const ref = asUserUsable(props.asUser, props.requestKey)
            ? asUserRef(props.asUser)
            : null;

        return props.draftId === null || ref === null
            ? Promise.reject(new WizardError(0))
            : fetchPreview(props.draftId, signal, ref);
    },
    onStart() {
        userPending.value = true;
    },
    onResult(next) {
        userResult.value = next;
        userPending.value = false;
    },
    onProblem(next) {
        userResult.value = null;
        userPending.value = false;

        // Gone (expired, or not this Admin's any more): the shared sample is shown again, and the Admin is told.
        if (next.kind === "as_user_ended") {
            endAsUser(props.asUser, true);
            announce(blockAsUserLabels.ended, "polite");
        } else if (next.kind === "as_user_mismatch") {
            endAsUser(props.asUser, false);
            props.asUser.refusal = blockAsUserLabels.mismatch;
        } else if (next.kind === "as_user_denied") {
            props.asUser.using = false;
            props.asUser.refusal = blockAsUserLabels.denied;
        } else if (next.kind === "session") {
            emit("auth-error", new WizardError(next.status ?? 401));
        }
    },
});

watch(
    () =>
        [
            props.asUser.operationId,
            props.asUser.using,
            props.asUser.ended,
            props.asUser.expiresAt,
            props.requestKey,
        ] as const,
    () => {
        if (usingUser.value) {
            userRunner.request(true);
        } else {
            userRunner.cancel();
            userPending.value = false;
            userResult.value = null;
        }
    },
    { immediate: true },
);

// ---- Warnings -----------------------------------------------------------------------------------------------------

// Known only once both the mapping and the preview have answered.
const ready = computed(() => slots.value !== null && result.value !== null);
const warnings = computed(() =>
    ready.value ? previewWarnings(slots.value ?? [], result.value) : [],
);
const open = computed(
    () =>
        warnings.value.filter((w) => !props.acknowledged.includes(w.id)).length,
);

// A warning that changed has another fingerprint, so its old acknowledgement no longer counts (`open` only counts live ones). Nothing is
// written on open: the page drops stale ids from what it saves, and a toggle writes only live ones.
watch(
    [warnings, ready],
    () => emit("live", ready.value ? warnings.value.map((w) => w.id) : null),
    { immediate: true },
);

const gate = computed<string | undefined>(() => {
    if (!ready.value) {
        return loading.value ? labels.blockedNotReady : labels.blockedNoPreview;
    }

    return open.value > 0 ? labels.blockedUnresolved(open.value) : undefined;
});

watch(gate, (reason) => emit("gate", reason), { immediate: true });

// Why Continue cannot run: the page's own reason (read-only, offline, published) first, then the warnings.
const continueReason = computed<string | undefined>(
    () => props.blocked ?? gate.value,
);

function toggle(id: string, on: boolean): void {
    if (props.blocked !== undefined) {
        return;
    }

    const live = new Set(warnings.value.map((w) => w.id));
    const next = props.acknowledged.filter(
        (item) => item !== id && live.has(item),
    );

    if (on) {
        next.push(id);
    }

    emit("update:acknowledged", next);

    const left = warnings.value.filter((w) => !next.includes(w.id)).length;

    announce(labels.announceAcknowledged(left), "polite");
}

// ---- The Block ----------------------------------------------------------------------------------------------------

const frame = computed(() => ({
    title: props.display.show_header ? props.name.trim() || null : null,
    subtitle: props.display.show_subtitle
        ? props.description.trim() || null
        : null,
    icon: null,
}));

const blank: Omit<KpiCardPayload, "state" | "title" | "subtitle" | "icon"> = {
    footerAction: null,
    kpiLabel: null,
    headline: { available: false, field: "headline_value" },
    comparison: null,
    comparisonLabel: null,
    secondaryNote: null,
    statusBadge: null,
    staleSince: null,
    errorMessage: null,
};

// The server's payload (the Shaper's output), with the state the switcher chose. The Renderer's own handling does the rest: Loading is its
// skeleton, Empty its message, Stale its note, Unavailable the catalogue message for a User (never a number).
const payload = computed<KpiCardPayload>(() => {
    const base: KpiCardPayload =
        shown.value !== null
            ? { ...shown.value.payload, ...frame.value }
            : { ...blank, state: "loading", ...frame.value };

    switch (state.value) {
        case "loading":
            return { ...base, state: "loading" };
        case "empty":
            return { ...base, state: "empty" };
        case "stale":
            return {
                ...base,
                state: "stale",
                staleSince: new Date(Date.now() - 3_600_000).toISOString(),
            };
        case "unavailable":
            return {
                ...base,
                state: "unavailable",
                headline: { available: false, field: "headline_value" },
                comparison: null,
                statusBadge: null,
            };
        default:
            // Normal, and Minimized when it is expanded again.
            return shown.value === null
                ? base
                : { ...base, state: "normal", staleSince: null };
    }
});

// Text Templates reach the browser in their wire form: the signed-in Admin's own name and email are what `{user.*}` shows here.
const page = usePage();
const shownPayload = computed(() => {
    const user = (
        page.props.auth as
            { user?: { name?: string; email?: string } } | undefined
    )?.user;

    return expandKpiPayload(payload.value, {
        name: user?.name ?? null,
        email: user?.email ?? null,
    });
});

// The Draft's own Block Type's Renderer; KPI Card for a type with none.
const Renderer = computed(() =>
    props.typeKey === "kpi-card"
        ? KpiRenderer
        : (rendererFor(props.typeKey) ?? KpiRenderer),
);

const minimized = computed(() => state.value === "minimized");
const heading = computed(() => frame.value.title ?? "");
const deviceLabel = computed(
    () =>
        blockWizardLabels.deviceOptions.find(
            (option) => option.value === device.value,
        )?.label ?? "",
);
const columns = computed(() =>
    device.value === "mobile" || !Number.isFinite(props.display.width)
        ? GRID_COLUMNS
        : Math.min(GRID_COLUMNS, Math.max(1, props.display.width)),
);
const heightPx = computed(() => HEIGHT_PX[props.display.height] ?? 180);
const canvasStyle = computed(() => ({
    width: CANVAS[device.value],
    maxWidth: "100%",
}));
const cardStyle = computed(() => ({ height: `${heightPx.value}px` }));
const gridStyle = computed(() => ({
    display: "grid",
    gridTemplateColumns: `repeat(${GRID_COLUMNS}, minmax(0, 1fr))`,
    gap: `${GRID_GAP}px`,
}));
const spanStyle = (n: number) => ({ gridColumn: `span ${n} / span ${n}` });
// What fills the rest of the sample dashboard: stand-in blocks, never data.
const fillers = computed(() => {
    const rest = GRID_COLUMNS - columns.value;

    return rest > 0 ? [rest] : [];
});

const notice = computed<string | null>(() => {
    const found = problem.value;

    if (found === null) {
        return result.value?.plan_stale ? blockPreviewLabels.stale : null;
    }

    switch (found.kind) {
        case "throttled":
            return blockPreviewLabels.throttled(found.retryAfter ?? null);
        case "guard":
            return blockPreviewLabels.guard;
        case "setting":
            return blockPreviewLabels.setting(found.setting ?? null);
        case "no_sample":
            return blockPreviewLabels.noSample;
        case "no_plan":
            return blockPreviewLabels.failedEmpty;
        default:
            return blockPreviewLabels.failedEmpty;
    }
});
</script>

<template>
    <section
        class="flex min-w-0 flex-col gap-5"
        data-test="step-4"
        aria-labelledby="wizard-step4-title"
    >
        <header class="flex flex-col gap-1">
            <h2 id="wizard-step4-title" class="type-title-md text-text-primary">
                {{ labels.title }}
            </h2>
            <p class="type-body-sm text-text-secondary">
                {{ labels.caption }}
            </p>
            <span
                class="type-caption mt-1 inline-block w-fit rounded-sm bg-info-soft px-2 text-info-strong"
                data-test="preview-sample-label"
                >{{
                    displayingUser
                        ? blockAsUserLabels.asLabel(asUser.name)
                        : labels.sampleLabel
                }}</span
            >
        </header>

        <div class="flex flex-wrap items-center gap-3" data-test="switchers">
            <SegmentedControl
                v-model="device"
                :options="blockWizardLabels.deviceOptions"
                :label="blockWizardLabels.devices"
            />
            <SegmentedControl
                v-model="state"
                :options="labels.stateOptions"
                :label="labels.states"
            />
        </div>

        <FetchAsUserPanel
            mode="preview"
            :as-user="asUser"
            :draft-id="draftId"
            :fetch-body="fetchBody"
            :request-key="requestKey"
            :source-name="sourceName"
            :fetch-reason="fetchReason"
            @auth-error="(error) => emit('auth-error', error)"
        />

        <div
            role="status"
            class="flex flex-col gap-1 empty:hidden"
            data-test="preview-notices"
        >
            <p
                v-if="usingUser && !displayingUser && !userPending"
                class="type-caption text-text-secondary"
                data-test="preview-as-unavailable"
            >
                {{ blockAsUserLabels.userUnavailable(asUser.name) }}
            </p>
            <p
                v-if="asUser.ended && asUser.operationId === null"
                class="type-caption text-warning-text"
                data-test="preview-as-ended"
            >
                {{ blockAsUserLabels.ended }}
            </p>
            <p
                v-if="loading && !ready"
                class="type-caption text-text-secondary"
                data-test="preview-loading"
            >
                {{ labels.loading }}
            </p>
            <p
                v-if="notice"
                class="type-caption text-text-secondary"
                data-test="preview-notice"
            >
                {{ notice }}
            </p>
        </div>

        <section class="flex min-w-0 flex-col gap-2" data-test="full-size">
            <h3 class="type-title-sm text-text-primary">
                {{ labels.fullSizeTitle }}
            </h3>
            <div
                class="min-w-0 rounded-md bg-surface-sunken p-3"
                data-test="preview-region"
            >
                <div
                    role="group"
                    :aria-label="blockWizardLabels.canvas(deviceLabel)"
                    :data-device="device"
                    :data-state="state"
                    class="mx-auto"
                    data-test="preview-canvas"
                    :style="canvasStyle"
                >
                    <div
                        v-if="minimized"
                        role="group"
                        :aria-label="
                            labels.minimizedBar(heading || display.height)
                        "
                        class="type-body-sm rounded-lg border border-border-default bg-surface-card px-4 py-2 text-text-primary"
                        data-test="block-minimized"
                    >
                        <span class="font-semibold">{{ heading }}</span>
                        <span class="type-caption block text-text-muted">{{
                            labels.minimizedHint
                        }}</span>
                    </div>
                    <div
                        v-else
                        class="overflow-hidden rounded-lg border border-border-default bg-surface-card [&>*]:h-full"
                        :style="cardStyle"
                        data-test="block-full"
                    >
                        <component
                            :is="Renderer"
                            :payload="shownPayload"
                            audience="user"
                        />
                    </div>
                </div>
            </div>
        </section>

        <section class="flex min-w-0 flex-col gap-2" data-test="sample-grid">
            <h3 :id="gridId" class="type-title-sm text-text-primary">
                {{ labels.gridTitle }}
            </h3>
            <p class="type-caption text-text-muted" data-test="grid-caption">
                {{ labels.gridCaption(columns, GRID_GAP) }}
            </p>
            <div class="min-w-0 rounded-md bg-surface-sunken p-3">
                <div
                    role="group"
                    :aria-labelledby="gridId"
                    class="mx-auto"
                    :data-device="device"
                    :data-columns="GRID_COLUMNS"
                    :data-gap="GRID_GAP"
                    data-test="grid-canvas"
                    :style="{ ...canvasStyle, ...gridStyle }"
                >
                    <div
                        v-if="minimized"
                        class="type-body-sm self-start rounded-lg border border-border-default bg-surface-card px-4 py-2 font-semibold text-text-primary"
                        :style="spanStyle(columns)"
                        data-test="grid-block"
                        :data-span="columns"
                    >
                        {{ heading }}
                    </div>
                    <div
                        v-else
                        class="min-w-0 overflow-hidden rounded-lg border border-border-default bg-surface-card [&>*]:h-full"
                        :style="{ ...spanStyle(columns), ...cardStyle }"
                        data-test="grid-block"
                        :data-span="columns"
                    >
                        <component
                            :is="Renderer"
                            :payload="shownPayload"
                            audience="user"
                        />
                    </div>
                    <div
                        v-for="(rest, index) in fillers"
                        :key="index"
                        aria-hidden="true"
                        class="type-caption flex items-center justify-center rounded-lg border border-dashed border-border-default text-text-muted"
                        :style="{ ...spanStyle(rest), ...cardStyle }"
                        data-test="grid-filler"
                    >
                        {{ labels.otherBlock }}
                    </div>
                </div>
            </div>
            <p class="type-caption text-text-muted">
                {{ labels.contentScrolls }}
            </p>
        </section>

        <section
            class="flex flex-col gap-3 rounded-lg border border-border-default bg-surface-card p-4"
            :aria-labelledby="warningsId"
            data-test="warnings"
        >
            <h3 :id="warningsId" class="type-title-sm text-text-primary">
                {{ labels.warningsTitle }}
            </h3>
            <p
                v-if="ready && warnings.length === 0"
                class="type-body-sm text-text-secondary"
                data-test="no-warnings"
            >
                {{ labels.noWarnings }}
            </p>
            <template v-else-if="ready">
                <p
                    class="type-caption text-text-secondary"
                    data-test="warnings-count"
                >
                    {{ labels.warningsCount(open, warnings.length) }}
                </p>
                <ul class="flex flex-col gap-3">
                    <li
                        v-for="warning in warnings"
                        :key="warning.id"
                        class="flex items-start gap-3"
                        data-test="warning"
                        :data-kind="warning.kind"
                        :data-slot-key="warning.slot"
                    >
                        <Checkbox
                            :id="`ack-${warning.id}`"
                            :model-value="acknowledged.includes(warning.id)"
                            :disabled="blocked !== undefined"
                            class="mt-1"
                            data-test="warning-ack"
                            @update:model-value="
                                (on: boolean | 'indeterminate') =>
                                    toggle(warning.id, on === true)
                            "
                        />
                        <Label
                            :for="`ack-${warning.id}`"
                            class="type-body-sm text-text-primary"
                            data-test="warning-text"
                        >
                            <span class="block">{{ warning.text }}</span>
                            <span class="type-caption block text-text-muted">{{
                                labels.acknowledge
                            }}</span>
                        </Label>
                    </li>
                </ul>
            </template>
            <div v-else-if="!loading" class="flex flex-col items-start gap-2">
                <p class="type-body-sm text-text-secondary" role="alert">
                    {{ labels.blockedNoPreview }}
                </p>
                <Button
                    type="button"
                    variant="secondary"
                    data-test="retry-preview"
                    @click="load"
                    >{{ blockWizardLabels.retry }}</Button
                >
            </div>
            <p
                v-if="blocked"
                class="type-caption text-text-muted"
                data-test="preview-readonly"
            >
                {{ labels.readOnly }}
            </p>
        </section>

        <div class="flex flex-col items-end gap-1" data-test="step-4-footer">
            <div class="flex justify-end gap-2">
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
                    :blocked="continueReason !== undefined"
                    :blocked-reason="continueReason"
                    :aria-describedby="continueReason ? reasonId : undefined"
                    data-test="continue"
                    @click="emit('continue')"
                >
                    {{ labels.continue }}
                </Button>
            </div>
            <BlockedReason
                v-if="continueReason"
                :id="reasonId"
                data-test="continue-reason"
                >{{ continueReason }}</BlockedReason
            >
        </div>
    </section>
</template>
