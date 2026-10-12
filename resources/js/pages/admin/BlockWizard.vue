<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    watch,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    useId,
} from 'vue';
import { useI18n } from 'vue-i18n';
import BlockedReason from '@/components/BlockedReason.vue';
import EditLockBanner from '@/components/EditLockBanner.vue';
import GatedAction from '@/components/GatedAction.vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import ConflictChooser from '@/components/wizard/ConflictChooser.vue';
import ConfigureBlockStep from '@/components/wizard/ConfigureBlockStep.vue';
import DataSourceStep from '@/components/wizard/DataSourceStep.vue';
import LivePreview from '@/components/wizard/LivePreview.vue';
import MapDataStep from '@/components/wizard/MapDataStep.vue';
import PreviewStep from '@/components/wizard/PreviewStep.vue';
import ValidateStep from '@/components/wizard/ValidateStep.vue';
import WizardStepper from '@/components/wizard/WizardStepper.vue';
import { useEditLock } from '@/composables/useEditLock';
import { useOfflineAction } from '@/composables/useOfflineAction';
import { announce } from '@/lib/announce';
import { DRAFT_BLOCKS_URL } from '@/lib/draftBlocks';
import { lockClient } from '@/lib/editLock';
import { registerFlush } from '@/lib/flushRegistry';
import {
    customRangeError,
    emptyCatalog,
    emptySource,
    endpointOf,
    endpointText,
    fetchBodyOf,
    fetchEndpointOptions,
    fetchIntervals,
    fetchSources,
    paramRows,
    requestKey as sourceRequestKey,
    sourceConfigOf,
    sourceFormOf,
    sourceIsSet,
    stepGate,
} from '@/lib/blockSource';
import { registerUnsavedForm } from '@/lib/unsavedForms';
import { clearPending, readPending, stashPending } from '@/lib/wizardPending';
import type { PendingChanges, PendingDelta } from '@/lib/wizardPending';
import { ErrorCode } from '@/types/error-codes';
import {
    BLOCK_TYPES,
    DESCRIPTION_MAX,
    FIELD_IDS,
    NAME_MAX,
    PUBLISHED_BLOCKS_URL,
    STEP_COUNT,
    WizardError,
    blockTypeFor,
    createWizardDraft,
    defaultDisplay,
    fetchCategories,
    fetchWizardDraft,
    heightName,
    mergedDisplay,
    saveWizardDraft,
    validateDisplay,
} from '@/lib/blockWizard';
import type {
    BlockCategory,
    DraftConfig,
    SaveBody,
    StepForm,
    WizardDraft,
    WizardUiState,
} from '@/lib/blockWizard';
import { DataSourceError } from '@/lib/dataSources';
import { SIGN_IN_URL } from '@/lib/session';
import {
    clockText,
    emptySample,
    restoreSample,
    sampleBlock,
} from '@/lib/blockSample';
import type { SampleClaim } from '@/lib/blockSample';
import { emptyAsUser, endAsUser } from '@/lib/blockUserSample';
import type { MappingRead } from '@/lib/blockMapping';
import {
    blockAsUserLabels,
    blockSampleLabels,
    blockWizardLabels as labels,
    draftLockLabels,
} from '@/locales/labels';
import { useToasts } from '@/stores/toasts';

// The Create block wizard (Story 3.4): five steps with a progress `nav`, Step 1 (Configure Block) and a sticky live preview in steps 1 to 3.
// It opens a Draft from `?draft=<id>&step=<n>`, or starts one on the first Save draft or Continue. Steps 2 to 5 are placeholders until their
// stories. The Admin saves with Save draft or Continue; with `tunables.timeouts.autosave_debounce` set (Story 3.5) the Draft also saves itself
// after each pause in editing (a compare-and-set with `X-Background: 1`, so it never extends the idle session). The page recovers from an
// expired session (the pending changes wait in sessionStorage and are replayed after sign-in), from going offline, from a failed save, from a
// revision conflict (the Admin chooses a version) and from the Draft having been published, and asks before leaving with unsaved changes.
// The Draft is soft-locked (Story 3.6, the shared `useEditLock`): the first editor holds the lock and heartbeats while active; anyone else sees
// the page read-only with the `draft-locked` banner and can take over, which makes the holder flush its autosave first.
const { t } = useI18n();
const toasts = useToasts();

const params = new URLSearchParams(
    typeof window === 'undefined' ? '' : window.location.search,
);
const requestedStep = Number.parseInt(params.get('step') ?? '', 10);

const state = ref<'loading' | 'error' | 'forbidden' | 'notfound' | 'ready'>(
    'loading',
);
const draft = ref<WizardDraft | null>(null);
const categories = ref<BlockCategory[]>([]);
// The first step that is not completed; every step before it is completed.
const reached = ref(1);
const current = ref(1);
const saving = ref(false);
const dirty = ref(false);
// Story 3.5: the wizard's own recovery state.
const saveFailed = ref<string | null>(null);
const stopped = ref(false);
const conflict = ref<WizardDraft | null>(null);
const resolving = ref(false);
const leaveOpen = ref(false);
const uiState = reactive<WizardUiState>({
    acknowledged_warnings: [],
    slot_statuses: {},
});
let changeSeq = 0;
let autosaveTimer: ReturnType<typeof setTimeout> | null = null;
let leaveTarget: string | null = null;
let leaving = false;
let stashed = false;
const { offline, reason: offlineReason } = useOfflineAction();
const saveFailedId = useId();
const saveReasonId = useId();
const continueReasonId = useId();
const errors = reactive<Record<string, string | undefined>>({});

const type = BLOCK_TYPES[0];
const form = reactive<StepForm>({
    name: '',
    description: '',
    categoryId: '',
    type: type.key,
    icon: null,
    display: defaultDisplay(type),
    source: emptySource(),
});
// What Step 2 has loaded (Story 3.7): the Data Sources, the chosen one's Endpoints and the System-settings refresh intervals.
const catalog = reactive(emptyCatalog());
// The Sample Response (Story 3.8): the pasted text, what the server found, and the accepted sample it restores when the Draft reopens.
const sample = reactive(emptySample());
// Fetch as user and Preview as (Story 3.17): who was chosen, when the result ends and its value-free structure. The response itself stays on the
// server, in the Operation's blob, for this Admin only; nothing here is stored or autosaved.
const asUser = reactive(emptyAsUser());

const blockType = computed(() => blockTypeFor(form.type) ?? type);
const categoryName = computed(
    () => categories.value.find((c) => c.id === form.categoryId)?.name ?? '',
);
const heading = computed(() => form.name.trim() || labels.untitled);
const version = computed(() => draft.value?.version ?? '1.0');
const showPreview = computed(() => current.value <= 3);
// Map Data's live preview (Story 3.14): the mapping the step read (slots and roles for the hotspots and the legend), a counter the step bumps
// after every mapping write, and the endpoint the sample note names.
type MapView = {
    checklist: NonNullable<MappingRead['checklist']>;
    roles: MappingRead['roles'];
    sampleRevision: number | null;
};
const mapView = ref<MapView | null>(null);
const mapRef = ref<InstanceType<typeof MapDataStep> | null>(null);
const previewKey = ref(0);
const previewSlots = computed(
    () => mapView.value?.checklist.groups.flatMap((g) => g.slots) ?? [],
);
const previewEndpoint = computed(() => {
    const endpoint = endpointOf(catalog, form.source);

    return endpoint ? endpointText(endpoint) : null;
});
// The completed Data Source step's summary: the chosen Data Source and Endpoint, once the lists are loaded.
const sourceSummary = computed<string | null>(() => {
    const chosen = catalog.sources?.find(
        (s) => s.data_source_id === form.source.dataSourceId,
    );
    const endpoint = endpointOf(catalog, form.source);

    const named = chosen
        ? endpoint
            ? `${chosen.name} · ${endpointText(endpoint)}`
            : chosen.name
        : null;

    // "Pasted 09:14" once a sample is accepted (Story 3.8).
    return sample.stored
        ? [
              named,
              sample.stored.origin === 'fetched'
                  ? blockSampleLabels.fetchedAt(
                        clockText(sample.stored.pasted_at),
                    )
                  : blockSampleLabels.pastedAt(
                        clockText(sample.stored.pasted_at),
                    ),
          ]
              .filter((part) => part !== null)
              .join(' · ')
        : named;
});
const steps = computed(() =>
    labels.steps.map((step, index) => ({
        ...step,
        summary:
            index === 0
                ? labels.summary(
                      form.display.width,
                      heightName(form.display.height),
                      categoryName.value,
                  )
                : index === 1
                  ? sourceSummary.value
                  : null,
    })),
);

function clampStep(value: number): number {
    return Math.min(Math.max(value, 1), STEP_COUNT);
}

function clearErrors(): void {
    for (const key of Object.keys(errors)) {
        delete errors[key];
    }
}

function applyDraft(next: WizardDraft): void {
    draft.value = next;
    const known = blockTypeFor(next.type) ?? type;

    form.name = next.name;
    form.description = next.config.description ?? '';
    form.categoryId = next.category_id ?? form.categoryId;

    // The Draft's own category stays an option even when it has since been archived, so saving does not change it.
    if (
        next.category_id &&
        !categories.value.some((c) => c.id === next.category_id)
    ) {
        categories.value = [
            ...categories.value,
            { id: next.category_id, name: next.category ?? '', archived: true },
        ];
    }
    form.type = known.key;
    form.icon = next.config.icon ?? null;
    form.display = mergedDisplay(known, next.config.display);
    form.source = sourceFormOf(next.config.source);
    uiState.acknowledged_warnings = [
        ...(next.ui_state?.acknowledged_warnings ?? []),
    ];
    uiState.slot_statuses = { ...next.ui_state?.slot_statuses };
}

function general(): BlockCategory | null {
    return (
        categories.value.find((c) => c.name.toLowerCase() === 'general') ??
        categories.value[0] ??
        null
    );
}

// The session ended: keep the pending changes for the replay after sign-in, then go to sign-in. `acknowledged` is true only for the
// session-expiry flush, the one that was told the session was about to end.
function sessionEnded(error: unknown, acknowledged = false): boolean {
    if (
        error instanceof WizardError &&
        (error.status === 401 || error.status === 419)
    ) {
        if (dirty.value) {
            stash(acknowledged);
        }

        // The redirect must not be blocked by this page's own leave prompt; the changes are already kept.
        leaving = true;
        window.location.assign(SIGN_IN_URL);

        return true;
    }

    return false;
}

async function load(): Promise<void> {
    state.value = 'loading';

    try {
        categories.value = await fetchCategories();
        form.categoryId = general()?.id ?? '';

        const id = params.get('draft');

        if (id) {
            const loaded = await fetchWizardDraft(id);

            applyDraft(loaded);
            // The Draft's saved sample comes back from the server (never from the browser).
            void restoreSample(sample, loaded.draft_id);
            reached.value = clampStep(loaded.step);
            current.value = Number.isNaN(requestedStep)
                ? reached.value
                : Math.min(clampStep(requestedStep), reached.value);
            state.value = 'ready';

            // The lock comes first: every save carries its token, so changes kept when the session ended can only be replayed by the holder.
            await acquire();

            // Changes kept when the session ended come back now, saved against the revision they were made on.
            const pending = readPending(id);

            if (pending && !readOnly.value) {
                await replay(pending, loaded);
            }

            return;
        }

        state.value = 'ready';
    } catch (error) {
        if (sessionEnded(error)) {
            return;
        }

        state.value =
            error instanceof WizardError && error.status === 403
                ? 'forbidden'
                : error instanceof WizardError && error.status === 404
                  ? 'notfound'
                  : 'error';
    }
}

// ---- Step 2's lists (Story 3.7) -------------------------------------------------------------------------------

let endpointsFor: string | null = null;
let catalogLoading = false;

async function loadEndpoints(id: string | null): Promise<void> {
    catalog.endpoints = null;
    catalog.endpointsError = false;

    if (id === null) {
        return;
    }

    try {
        const found = await fetchEndpointOptions(id);

        // The Admin may have chosen another Data Source while this was on its way.
        if (form.source.dataSourceId === id) {
            catalog.endpoints = found;
        }
    } catch (error) {
        if (!sessionEnded(error) && form.source.dataSourceId === id) {
            catalog.endpointsError = true;
        }
    }
}

async function loadCatalog(): Promise<void> {
    if (catalogLoading) {
        return;
    }

    catalogLoading = true;
    catalog.sourcesError = false;
    catalog.intervalsError = false;

    try {
        const [sources, intervals] = await Promise.allSettled([
            fetchSources(),
            fetchIntervals(),
        ]);

        for (const result of [sources, intervals]) {
            if (result.status === 'rejected' && sessionEnded(result.reason)) {
                return;
            }
        }

        if (sources.status === 'fulfilled') {
            catalog.sources = sources.value;
        } else {
            catalog.sourcesError = true;
        }

        if (intervals.status === 'fulfilled') {
            catalog.intervals = intervals.value;
            catalog.intervalsLoaded = true;
        } else {
            catalog.intervalsError = true;
            catalog.intervalsLoaded = true;
        }

        // The Endpoints of the chosen Data Source are read again whenever the lists are.
        endpointsFor = null;
        await syncEndpoints();
    } finally {
        catalogLoading = false;
    }
}

async function syncEndpoints(): Promise<void> {
    const id = form.source.dataSourceId;

    if (catalog.sources === null || id === endpointsFor) {
        return;
    }

    endpointsFor = id;
    await loadEndpoints(id);
}

// Step 2 reads its lists when it is first shown, and the Endpoints again whenever another Data Source is chosen (or loaded with a Draft). Step 4
// reads them too (Story 3.17): "Preview as" sends the request's choices, so it needs the Endpoint's parameters like Step 2's Fetch does.
watch(
    () => [current.value, state.value, form.source.dataSourceId] as const,
    ([step, status]) => {
        if ((step === 2 || step === 4) && status === 'ready') {
            if (catalog.sources === null) {
                void loadCatalog();
            } else {
                void syncEndpoints();
            }
        }
    },
    { immediate: true },
);

const gate = computed(() => {
    const endpoint = endpointOf(catalog, form.source);

    return stepGate(
        catalog.sources,
        endpoint,
        paramRows(endpoint, form.source, form.display.period),
        form.source,
        t('datasource-none.message'),
    );
});
// What the request is now, for Fetch as user in Steps 2 and 4: its key (a result taken with other parameters is not previewed), the body, the Data
// Source's name for the error card and why a fetch cannot run (the form's own reason, then offline).
const requestKeyNow = computed(() => sourceRequestKey(form.source));
const fetchBodyNow = () => fetchBodyOf(form.source, form.display.period);
const sourceNameNow = computed(
    () =>
        catalog.sources?.find(
            (s) => s.data_source_id === form.source.dataSourceId,
        )?.name ?? '',
);
const fetchReasonNow = computed<string | undefined>(
    () => gate.value.fetch ?? (offline.value ? offlineReason.value : undefined),
);

// A Fetch as user result is short-lived: when it ends the preview goes back to the shared sample, and Step 4 says so.
let asUserTimer: ReturnType<typeof setTimeout> | null = null;

watch(
    () => asUser.expiresAt,
    (at) => {
        if (asUserTimer !== null) {
            clearTimeout(asUserTimer);
            asUserTimer = null;
        }

        if (at === null) {
            return;
        }

        asUserTimer = setTimeout(
            () => {
                if (asUser.operationId !== null) {
                    endAsUser(asUser, true);
                    announce(blockAsUserLabels.ended, 'polite');
                }
            },
            Math.max(0, Date.parse(at) - Date.now()),
        );
    },
    { immediate: true },
);

// Why Continue cannot leave Step 2 yet: a POST not marked read-only, or no Data Source to read.
// Step 4 (Story 3.16) says why itself: warnings still to acknowledge, or a preview not ready.
const previewGate = ref<string | undefined>(undefined);
// The fingerprints of the warnings Step 4 lists now; null while unknown. A save keeps only these acknowledgements.
const liveWarnings = ref<string[] | null>(null);
const stepBlocked = computed<string | undefined>(() =>
    current.value === 4
        ? previewGate.value
        : current.value === 2
          ? (gate.value.continue ??
            (sampleBlock(sample) === 'invalid'
                ? blockSampleLabels.invalidBlocked
                : sampleBlock(sample) === 'unchecked'
                  ? blockSampleLabels.uncheckedBlocked
                  : undefined))
          : undefined,
);

// The warnings Step 4 lists are acknowledged one by one; the list is kept in `draft_ui_state` with the next save.
function setAcknowledged(ids: string[]): void {
    uiState.acknowledged_warnings = [...ids];
    onChange();
}

// ---- Validation ------------------------------------------------------------------------------------------------

function checkName(): string | undefined {
    const name = form.name.trim();

    return name === ''
        ? labels.nameRequired
        : name.length > NAME_MAX
          ? labels.nameTooLong(NAME_MAX)
          : undefined;
}

function checkDescription(): string | undefined {
    const text = form.description.trim();

    return text === ''
        ? labels.descriptionRequired
        : text.length > DESCRIPTION_MAX
          ? labels.descriptionTooLong(DESCRIPTION_MAX)
          : undefined;
}

// Fills `errors` for Step 1. A Draft saves with only a name and a valid size, so the description is required on Continue alone.
function validate(requireDescription: boolean): boolean {
    clearErrors();
    errors.name = checkName();
    errors.description = requireDescription ? checkDescription() : undefined;

    for (const [key, message] of Object.entries(
        validateDisplay(form.display, blockType.value),
    )) {
        errors[`display.${key}`] = message;
    }

    return Object.keys(FIELD_IDS).every((key) => !errors[key]);
}

async function focusFirstError(): Promise<void> {
    await nextTick();
    const first = Object.keys(FIELD_IDS).find((key) => errors[key]);

    if (first) {
        document.getElementById(FIELD_IDS[first])?.focus();
    }
}

function onBlur(field: string): void {
    if (field === 'name') {
        errors.name = checkName();
    } else if (field === 'description') {
        errors.description = checkDescription();
    }
}

function onChange(): void {
    // Step 2's server errors go when the Admin edits again.
    for (const key of Object.keys(errors)) {
        if (key.startsWith('source.')) {
            delete errors[key];
        }
    }

    dirty.value = true;
    changeSeq += 1;
    scheduleAutosave();
}

// ---- Saving ----------------------------------------------------------------------------------------------------

function configOf(): DraftConfig {
    const description = form.description.trim();

    return {
        schema_version: 1,
        ...(description === '' ? {} : { description }),
        ...(form.icon === null ? {} : { icon: form.icon }),
        display: { ...form.display },
        // Step 2's settings ride along once there is something to say (or something stored to clear), so Step 1's save stays what it was.
        ...(sourceIsSet(form.source) || draft.value?.config.source !== undefined
            ? { source: sourceConfigOf(form.source) }
            : {}),
    };
}

function showWizardUrl(id: string): void {
    const url = new URL(window.location.href);

    url.searchParams.set('draft', id);
    url.searchParams.set('step', String(current.value));
    window.history.replaceState(window.history.state, '', url);
}

// The server's field errors, keyed as the form's: "config.display.width" is the width field.
function showFieldErrors(fields: Record<string, string[]>): void {
    for (const [key, messages] of Object.entries(fields)) {
        const field = key.replace(/^config\./, '');

        errors[field] = messages[0];
    }
}

// What a save sends, without the revision and epoch it is decided against: also what waits in sessionStorage across a sign-in.
function deltaOf(step: number): PendingDelta {
    return {
        name: form.name.trim(),
        type: form.type,
        category_id: form.categoryId || null,
        step,
        config: configOf(),
        ui_state: {
            acknowledged_warnings: (uiState.acknowledged_warnings ?? []).filter(
                (id) =>
                    current.value !== 4 ||
                    liveWarnings.value === null ||
                    liveWarnings.value.includes(id),
            ),
            slot_statuses: { ...uiState.slot_statuses },
        },
    };
}

// Keeps the pending changes in sessionStorage (never a secret: the wizard has none, and the store drops any secret-named key).
function stash(acknowledged: boolean): boolean {
    const target = draft.value;

    if (target === null) {
        return false;
    }

    const kept = stashPending({
        draftId: target.draft_id,
        baseRevision: target.revision,
        lockEpoch: target.lock_epoch,
        viewStep: current.value,
        acknowledged,
        delta: deltaOf(reached.value),
    });

    stashed = stashed || kept;

    return kept;
}

// ---- The soft lock (Story 3.6) ----------------------------------------------------------------------------------

// The Draft's lock: acquired when the wizard opens a Draft (or right after it starts one), renewed while the Admin is active, released on
// leave. Without it the page is read-only. A take-over makes the holder flush: the wizard's own save, so the taker loads the post-flush revision.
function lockAuthExpired(error: unknown): boolean {
    if (
        error instanceof DataSourceError &&
        (error.status === 401 || error.status === 419)
    ) {
        return sessionEnded(new WizardError(error.status));
    }

    return false;
}

// Loads the Draft again after a take-over, without the skeleton: the holder's flush is in it.
async function reloadDraft(): Promise<boolean> {
    const id = draft.value?.draft_id ?? params.get('draft');

    if (!id) {
        return false;
    }

    try {
        const loaded = await fetchWizardDraft(id);

        applyDraft(loaded);
        void restoreSample(sample, loaded.draft_id);
        draft.value = loaded;
        reached.value = clampStep(Math.max(loaded.step, reached.value));
        dirty.value = false;
        changeSeq += 1;

        return true;
    } catch (error) {
        // The failure and Retry path: the page shows its load error, and the lock is not taken on stale data.
        if (!sessionEnded(error)) {
            state.value = 'error';
        }

        return false;
    }
}

const {
    lock,
    readOnly,
    notice: lockNotice,
    claim,
    acquire,
    retryAcquire,
    takeOver,
    endLock,
} = useEditLock({
    client: lockClient(DRAFT_BLOCKS_URL),
    id: () => draft.value?.draft_id ?? params.get('draft'),
    labels: draftLockLabels,
    expectLock: () => params.get('draft') !== null,
    authExpired: lockAuthExpired,
    reload: reloadDraft,
    restart: load,
    activityGated: true,
    // Waits for a save in flight; the holder's flush is the wizard's own save, never a partial or invalid one.
    canFlush: () => state.value === 'ready' && !saving.value,
    flush: async () => {
        if (stopped.value || conflict.value !== null) {
            return false;
        }

        if (!dirty.value) {
            return true;
        }

        return savable() && (await persist(reached.value, 'handover'));
    },
    onEnded: () => cancelAutosave(),
    onReloaded: () => announce(labels.reloaded, 'polite'),
});

// What a save says about the lock: the token that holds it, when it is on.
function tokenOf(): { lock_token?: string } {
    const held = claim();

    return held ? { lock_token: held.token } : {};
}

// What a sample write carries (Story 3.8): the same claim as a Draft save. Null when this tab may not write.
function sampleClaim(): SampleClaim | null {
    const target = draft.value;

    return target === null || readOnly.value
        ? null
        : { lock_epoch: target.lock_epoch, ...tokenOf() };
}

// ---- Autosave --------------------------------------------------------------------------------------------------

// The debounce is a `pending_input` tunable, shared with the page; unset means autosave is inert and no value is invented here.
const debounceMs = computed<number | null>(() => {
    const value = (
        usePage().props as { autosave?: { debounce_ms?: number | null } }
    ).autosave?.debounce_ms;

    return typeof value === 'number' && Number.isFinite(value) && value > 0
        ? value
        : null;
});

function cancelAutosave(): void {
    if (autosaveTimer) {
        clearTimeout(autosaveTimer);
        autosaveTimer = null;
    }
}

// A Draft saves with a name and a valid size alone; a change that would be refused is not sent until it is fixed.
function savable(): boolean {
    const name = form.name.trim();

    return (
        name !== '' &&
        name.length <= NAME_MAX &&
        form.description.trim().length <= DESCRIPTION_MAX &&
        Object.keys(validateDisplay(form.display, blockType.value)).length ===
            0 &&
        customRangeError(form.source) === null
    );
}

function scheduleAutosave(): void {
    cancelAutosave();

    if (
        debounceMs.value === null ||
        draft.value === null ||
        stopped.value ||
        conflict.value !== null ||
        offline.value ||
        readOnly.value
    ) {
        return;
    }

    autosaveTimer = setTimeout(() => {
        autosaveTimer = null;
        void autosave();
    }, debounceMs.value);
}

async function autosave(): Promise<void> {
    if (!dirty.value || !savable()) {
        return;
    }

    await persist(reached.value, 'auto');
}

// ---- Persisting ------------------------------------------------------------------------------------------------

// Saves go one after another: a manual save waits for the autosave in flight, and the later state is what the last one sends.
let chain: Promise<unknown> = Promise.resolve();

function persist(
    step: number,
    kind: 'manual' | 'auto' | 'flush' | 'handover' = 'manual',
): Promise<boolean> {
    const run = chain.then(() => save(step, kind));

    chain = run.catch(() => undefined);

    return run;
}

// Map Data's writes (Story 3.11b) join the same chain, so a role or record-path change never races an autosave.
function runInChain<T>(task: () => Promise<T>): Promise<T> {
    const run = chain.then(task);

    chain = run.catch(() => undefined);

    return run;
}

// The preview's skip link: the checklist takes focus (by id when the step is not there to ask).
function skipToChecklist(): void {
    if (mapRef.value) {
        mapRef.value.focusChecklist();
    } else {
        document.getElementById('slot-checklist')?.focus();
    }
}

// A mapping write stored a new Draft revision: the next save is decided against it.
function adoptRevision(revision: number): void {
    if (draft.value !== null) {
        draft.value = { ...draft.value, revision };
    }
}

// A refused mapping write is handled like a refused save: the revision chooser, the lost lock, the session, a missing Draft.
async function onMappingError(error: unknown): Promise<void> {
    await failed(error, 'manual');
}

// Why Map Data cannot be changed right now.
const mapBlocked = computed<string | undefined>(() =>
    stopped.value
        ? labels.publishedBody
        : readOnly.value
          ? draftLockLabels.readOnly
          : conflict.value !== null
            ? labels.conflictTitle
            : offline.value
              ? offlineReason.value
              : undefined,
);

// The server pins the revisions when it stores a selection: the form shows what it pinned, and never sends it back.
function syncPins(saved: WizardDraft): void {
    const stored = saved.config.source;

    if (!stored || Array.isArray(stored)) {
        return;
    }

    form.source.endpointRevisionId = stored.endpoint_revision_id ?? null;
    form.source.dataSourceRevision = stored.data_source_revision ?? null;
}

// Starts the Draft when there is none, then saves the form. `step` is the furthest step the Draft reaches. Returns whether it was saved.
// An autosave or a flush is a background call: no field errors on the form, and `X-Background: 1`.
async function save(
    step: number,
    kind: 'manual' | 'auto' | 'flush' | 'handover',
): Promise<boolean> {
    // Read-only (someone else holds the lock, or it was lost): nothing is sent, and the server would refuse it anyway.
    if (stopped.value || conflict.value !== null || readOnly.value) {
        return false;
    }

    if (kind !== 'manual' && !dirty.value) {
        return true;
    }

    saving.value = true;
    const seq = changeSeq;

    try {
        let target = draft.value;

        if (target === null) {
            target = await createWizardDraft(form.name.trim(), form.type);
            draft.value = target;
            showWizardUrl(target.draft_id);
            // A Draft this tab just started has no other editor: take its lock before the first save.
            await acquire();

            // Not the holder (blocked, unavailable): a save would carry no token and be refused as a lost lock.
            if (readOnly.value) {
                return false;
            }
        }

        const body: SaveBody = {
            ...deltaOf(step),
            expected_revision: target.revision,
            lock_epoch: target.lock_epoch,
            ...tokenOf(),
        };

        draft.value = await saveWizardDraft(target.draft_id, body, {
            background: kind !== 'manual',
        });
        syncPins(draft.value);
        // Changes made while this save was in flight are still unsaved.
        dirty.value = changeSeq !== seq;
        saveFailed.value = null;
        clearPending();

        if (dirty.value) {
            scheduleAutosave();
        }

        return true;
    } catch (error) {
        await failed(error, kind);

        return false;
    } finally {
        saving.value = false;
    }
}

async function focusSaveFailed(): Promise<void> {
    await nextTick();
    document.getElementById(saveFailedId)?.focus();
}

// What each way a save can fail leads to. Every change stays in the form, and nothing is saved over a conflict without a choice.
async function failed(
    error: unknown,
    kind: 'manual' | 'auto' | 'flush' | 'handover',
): Promise<void> {
    if (sessionEnded(error, kind === 'flush' || kind === 'handover')) {
        return;
    }

    if (error instanceof WizardError) {
        if (
            error.status === 409 &&
            error.code === ErrorCode.BlocksDraftPublished
        ) {
            stopped.value = true;
            cancelAutosave();
            saveFailed.value = null;
            await nextTick();
            document
                .querySelector<HTMLElement>('[data-test=draft-published]')
                ?.focus();

            return;
        }

        if (error.status === 409 && error.current) {
            conflict.value = error.current;
            cancelAutosave();

            return;
        }

        if (error.status === 409) {
            toasts.add({ kind: 'error', message: labels.conflict });

            return;
        }

        if (error.status === 423) {
            // The lock was taken over, expired or never held: nothing was written. The page is read-only with the lost-lock notice,
            // which never claims a save; what was typed stays on the page.
            cancelAutosave();
            saveFailed.value = null;
            endLock(null);

            return;
        }

        if (error.status === 404) {
            state.value = 'notfound';

            return;
        }

        // Offline: the changes wait, the banner says so, and they save when the connection is back.
        if (error.status === 0 && offline.value) {
            if (kind === 'flush' || kind === 'handover') {
                stash(true);
            }

            return;
        }

        if (error.status === 422 && kind === 'manual') {
            await showFieldFailure(error);

            return;
        }
    }

    // A take-over flush that failed is not acknowledged: the taker waits for the flush timeout, and the failure is shown here as for any save.
    // A flush that could not reach the server keeps the changes for the next sign-in instead.
    if (kind === 'flush' || kind === 'handover') {
        stash(true);
    }

    saveFailed.value =
        error instanceof WizardError && error.status === 429
            ? t('throttled')
            : t('save-failed.draft');
    await focusSaveFailed();
}

async function showFieldFailure(error: WizardError): Promise<void> {
    showFieldErrors(error.fields);

    // The icon picker shows no error text, so an icon error counts as unmapped.
    if (Object.keys(FIELD_IDS).some((key) => key !== 'icon' && errors[key])) {
        await focusFirstError();

        return;
    }

    // Nothing the form can point at (an icon, a step, a refused secret): say so, and move focus to the message.
    toasts.add({ kind: 'error', message: t('save-failed.form') });
    await nextTick();
    document
        .querySelector<HTMLElement>(
            '[data-slot="toast"] [data-slot="toast-dismiss"]',
        )
        ?.focus();
}

async function retrySave(): Promise<void> {
    saveFailed.value = null;

    if (await persist(reached.value)) {
        announce(labels.saved, 'polite');
    }
}

// The session-expiry warning's flush (Story 3.5): true when the changes are safe, saved or kept for the next sign-in.
async function flush(): Promise<boolean> {
    if (stopped.value || conflict.value !== null || !dirty.value) {
        return true;
    }

    stashed = false;

    if (offline.value || draft.value === null) {
        return stash(true);
    }

    return (await persist(reached.value, 'flush')) || stashed;
}

// After sign-in: the kept changes are applied to the form and saved against the revision they were made on. A revision that has moved on
// opens the chooser with both versions; anything else leaves the changes in the form, unsaved, with the failure shown.
async function replay(
    pending: PendingChanges,
    loaded: WizardDraft,
): Promise<void> {
    applyDraft({
        ...loaded,
        name: pending.delta.name,
        type: pending.delta.type,
        category_id: pending.delta.category_id ?? loaded.category_id,
        config: pending.delta.config ?? loaded.config,
        ui_state: pending.delta.ui_state ?? loaded.ui_state,
    });
    draft.value = loaded;
    dirty.value = true;
    changeSeq += 1;
    saving.value = true;

    try {
        const saved = await saveWizardDraft(loaded.draft_id, {
            ...pending.delta,
            expected_revision: pending.baseRevision,
            lock_epoch: pending.lockEpoch,
            ...tokenOf(),
        });

        applyDraft(saved);
        draft.value = saved;
        dirty.value = false;
        clearPending();
        reached.value = clampStep(Math.max(loaded.step, saved.step));
        current.value = Math.min(clampStep(pending.viewStep), reached.value);
        showWizardUrl(saved.draft_id);

        if (pending.acknowledged) {
            toasts.add({ kind: 'info', message: t('session-expired') });
        }
    } catch (error) {
        await failed(error, 'manual');
    } finally {
        saving.value = false;
    }
}

// The chooser: both versions are shown and the Admin picks one. Keeping the Admin's saves it over the version that is there now.
async function keepMine(): Promise<void> {
    const theirs = conflict.value;

    if (!theirs || resolving.value) {
        return;
    }

    resolving.value = true;
    draft.value = theirs;
    conflict.value = null;

    try {
        if (await persist(reached.value)) {
            announce(labels.conflictKept, 'polite');
        }
    } finally {
        resolving.value = false;
    }
}

function useTheirs(): void {
    const theirs = conflict.value;

    if (!theirs) {
        return;
    }

    applyDraft(theirs);
    draft.value = theirs;
    reached.value = clampStep(theirs.step);
    current.value = Math.min(current.value, reached.value);
    dirty.value = false;
    conflict.value = null;
    clearPending();
    announce(labels.conflictTook, 'polite');
}

const mineVersion = computed(() => ({
    name: form.name.trim(),
    description: form.description.trim(),
    width: form.display.width,
    height: form.display.height,
    savedAt: null,
}));

async function saveDraft(): Promise<void> {
    if (readOnly.value) {
        return;
    }

    // Step 1's fields are checked on Step 1; Step 2 saves with what it has (a custom range must still be in order).
    if (current.value === 1 ? !validate(false) : !savable()) {
        await focusFirstError();

        return;
    }

    if (await persist(reached.value)) {
        announce(labels.saved, 'polite');
    }
}

async function onContinue(): Promise<void> {
    if (readOnly.value) {
        return;
    }

    if (
        current.value === 1 ? !validate(true) : !savable() || stepBlocked.value
    ) {
        await focusFirstError();

        return;
    }

    const next = Math.min(current.value + 1, STEP_COUNT);
    const furthest = Math.max(reached.value, next);

    if (await persist(furthest)) {
        reached.value = furthest;
        current.value = next;

        if (draft.value) {
            showWizardUrl(draft.value.draft_id);
        }
    }
}

function goTo(step: number): void {
    // Back to a completed step, forward only to the first one that is not completed.
    if (step < 1 || step > reached.value) {
        announce(labels.notReady, 'polite');

        return;
    }

    current.value = step;

    if (draft.value) {
        showWizardUrl(draft.value.draft_id);
    }
}

// Step 5 validates the Draft as it is stored: what is still pending is saved first (nothing is sent when nothing changed, so no revision is spent).
async function settleForValidation(): Promise<boolean> {
    return dirty.value ? persist(reached.value) : true;
}

function onPreview(): void {
    goTo(4);
}

function onPublish(): void {
    // Publishing is the last step's work (Story 3.19); until the earlier steps exist this only points the way.
    goTo(5);
}

function beforeUnload(event: BeforeUnloadEvent): void {
    if (dirty.value && !stopped.value && !leaving) {
        event.preventDefault();
    }
}

// ---- Leaving with unsaved changes -------------------------------------------------------------------------------

const DRAFT_BLOCKS_PAGE = '/admin/blocks/drafts';

// Any visit away from the wizard with changes not saved asks first: Save draft, Discard changes or Keep editing.
const stopBefore = router.on('before', (event) => {
    if (leaving || !dirty.value || stopped.value) {
        return;
    }

    leaveTarget = event.detail.visit.url.href;
    leaveOpen.value = true;

    return false;
});

// The Back button: no changes returns directly, changes ask through the guard above.
function leaveWizard(): void {
    router.visit(DRAFT_BLOCKS_PAGE);
}

function discardAndLeave(): void {
    leaving = true;
    dirty.value = false;
    clearPending();

    if (leaveTarget) {
        router.visit(leaveTarget);
    }
}

function keepEditing(): void {
    leaveTarget = null;
}

// Save from the leave question: only a saved Draft lets the Admin go; a refused save keeps them here with the failure shown.
async function saveAndLeave(): Promise<void> {
    const target = leaveTarget;

    if (!(await persist(reached.value))) {
        leaveTarget = null;

        return;
    }

    leaving = true;

    if (target) {
        router.visit(target);
    }
}

const stopRegistry = registerUnsavedForm({
    id: 'block-wizard',
    isDirty: () => dirty.value && !stopped.value,
    save: () => persist(reached.value),
});
const stopFlush = registerFlush(flush);

// Back online: the changes made while offline are saved, in the order they were made (the last state is what the Draft keeps).
watch(offline, (isOffline) => {
    if (isOffline) {
        cancelAutosave();

        return;
    }

    if (dirty.value && debounceMs.value !== null && draft.value !== null) {
        void autosave();
    }
});

// A reason a save cannot run right now, shown beside Save draft and Continue.
const saveBlocked = computed<string | undefined>(() =>
    stopped.value
        ? labels.publishedBody
        : readOnly.value
          ? draftLockLabels.readOnly
          : conflict.value !== null
            ? labels.conflictTitle
            : offline.value
              ? offlineReason.value
              : undefined,
);

// A reason Continue cannot run right now: the save reasons, then Step 2's own.
const continueBlocked = computed<string | undefined>(
    () => saveBlocked.value ?? stepBlocked.value,
);

// The line that tells how the Draft stands; shown only where autosave is on.
const autosaveStatus = computed(() =>
    debounceMs.value === null || draft.value === null || stopped.value
        ? ''
        : saving.value
          ? labels.saving
          : dirty.value
            ? ''
            : labels.saved,
);

onMounted(() => {
    window.addEventListener('beforeunload', beforeUnload);
    void load();
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnload);
    cancelAutosave();
    stopBefore();
    stopRegistry();
    stopFlush();

    if (asUserTimer !== null) {
        clearTimeout(asUserTimer);
    }
});
</script>

<template>
    <Head :title="labels.pageTitle" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <header
            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3"
            data-slot="wizard-header"
        >
            <div class="min-w-0">
                <p class="type-caption text-text-muted" data-test="breadcrumb">
                    {{ labels.breadcrumb }}
                </p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <h1
                        class="type-headline-md min-w-0 break-words text-text-primary"
                        data-test="title"
                    >
                        {{ heading }}
                    </h1>
                    <Badge variant="draft" data-test="draft-badge">{{
                        labels.draftBadge
                    }}</Badge>
                </div>
            </div>
            <div
                v-if="state === 'ready'"
                class="flex flex-wrap items-start gap-2"
                data-slot="wizard-actions"
            >
                <span class="inline-flex flex-col items-start gap-1">
                    <Button
                        type="button"
                        variant="secondary"
                        :blocked="saveBlocked !== undefined"
                        :blocked-reason="saveBlocked"
                        :aria-describedby="
                            saveBlocked ? saveReasonId : undefined
                        "
                        data-test="save-draft"
                        @click="saveDraft"
                    >
                        {{
                            saving && !autosaveStatus
                                ? labels.saving
                                : labels.saveDraft
                        }}
                    </Button>
                    <BlockedReason v-if="saveBlocked" :id="saveReasonId">{{
                        saveBlocked
                    }}</BlockedReason>
                    <p
                        v-if="saveFailed"
                        :id="saveFailedId"
                        tabindex="-1"
                        role="alert"
                        data-test="save-failed"
                        class="type-body-sm text-error-text"
                    >
                        {{ saveFailed }}
                        <button
                            type="button"
                            class="underline"
                            data-test="retry-save"
                            @click="retrySave"
                        >
                            {{ labels.retrySave }}
                        </button>
                    </p>
                    <p
                        role="status"
                        aria-live="polite"
                        data-test="autosave-status"
                        class="type-caption text-text-muted empty:hidden"
                    >
                        {{ autosaveStatus }}
                    </p>
                </span>
                <Button
                    type="button"
                    variant="secondary"
                    data-test="preview"
                    @click="onPreview"
                >
                    {{ labels.preview }}
                </Button>
                <GatedAction
                    permission="blocks.publish"
                    :reason="t('perm-publish')"
                    :also-blocked="
                        readOnly
                            ? draftLockLabels.readOnly
                            : offline
                              ? offlineReason
                              : stopped
                                ? labels.publishedBody
                                : undefined
                    "
                    variant="primary"
                    data-test="publish"
                    @click="onPublish"
                >
                    {{ labels.publish }}
                </GatedAction>
            </div>
        </header>

        <EditLockBanner
            :mode="lock.mode"
            :notice="lockNotice"
            :takeover-failed="lock.takeoverFailed"
            :labels="draftLockLabels"
            :close-href="DRAFT_BLOCKS_PAGE"
            @take-over="takeOver"
            @retry="retryAcquire"
        />

        <div
            v-if="state === 'loading'"
            role="status"
            aria-busy="true"
            class="flex flex-col gap-3"
        >
            <span class="sr-only">{{ labels.loading }}</span>
            <Skeleton class="h-16 w-full" :caption="false" />
            <Skeleton class="h-72 w-full" :caption="false" />
        </div>

        <p
            v-else-if="state === 'forbidden'"
            role="alert"
            data-slot="perm-denied"
            class="type-body text-text-secondary"
        >
            {{ t('perm-denied') }}
        </p>

        <section
            v-else-if="state === 'notfound'"
            data-slot="draft-not-found"
            class="flex flex-col items-start gap-4 rounded-lg border border-border-default bg-surface-card p-6"
        >
            <p class="type-body-md text-text-secondary" role="alert">
                {{ labels.notFound }}
            </p>
            <Button as-child variant="secondary">
                <Link href="/admin/blocks/drafts">{{
                    labels.backToDrafts
                }}</Link>
            </Button>
        </section>

        <section
            v-else-if="state === 'error'"
            data-slot="draft-load-failed"
            class="flex flex-col items-start gap-4 rounded-lg border border-border-default bg-surface-card p-6"
        >
            <p class="type-body-md text-text-secondary" role="alert">
                {{ labels.loadFailed }}
            </p>
            <Button
                type="button"
                variant="secondary"
                data-test="retry"
                @click="load"
                >{{ labels.retry }}</Button
            >
        </section>

        <template v-else>
            <section
                v-if="stopped"
                tabindex="-1"
                role="alert"
                data-test="draft-published"
                class="flex flex-col items-start gap-3 rounded-lg border border-border-default bg-surface-card p-4"
            >
                <h2 class="type-title-sm text-text-primary">
                    {{ labels.publishedTitle }}
                </h2>
                <p class="type-body-sm text-text-secondary">
                    {{ labels.publishedBody }}
                </p>
                <Button as-child variant="secondary">
                    <Link
                        :href="PUBLISHED_BLOCKS_URL"
                        data-test="published-link"
                        >{{ labels.publishedLink }}</Link
                    >
                </Button>
            </section>

            <ConflictChooser
                v-if="conflict"
                :mine="mineVersion"
                :theirs="conflict"
                :busy="resolving"
                @keep-mine="keepMine"
                @use-theirs="useTheirs"
            />

            <WizardStepper
                :steps="steps"
                :current="current"
                :reached="reached"
                @go="goTo"
            />

            <div
                class="grid gap-6"
                :class="
                    showPreview
                        ? 'lg:grid-cols-[minmax(0,11fr)_minmax(0,9fr)]'
                        : ''
                "
            >
                <div class="min-w-0">
                    <form
                        v-if="current <= 2"
                        novalidate
                        class="flex flex-col gap-4"
                        :data-test="`step-${current}`"
                        :aria-label="
                            readOnly ? draftLockLabels.readOnly : undefined
                        "
                        @submit.prevent="onContinue"
                    >
                        <fieldset
                            :disabled="readOnly"
                            class="m-0 flex min-w-0 flex-col gap-4 border-0 p-0"
                            data-test="form-fields"
                        >
                            <ConfigureBlockStep
                                v-if="current === 1"
                                :form="form"
                                :errors="errors"
                                :categories="categories"
                                @change="onChange"
                                @blur="onBlur"
                            />
                            <DataSourceStep
                                v-else
                                :form="form"
                                :errors="errors"
                                :catalog="catalog"
                                :gate="gate"
                                :also-blocked="
                                    offline ? offlineReason : undefined
                                "
                                :sample="sample"
                                :draft-id="draft?.draft_id ?? null"
                                :claim="sampleClaim"
                                :read-only="readOnly"
                                :as-user="asUser"
                                @change="onChange"
                                @retry="loadCatalog"
                                @auth-error="sessionEnded"
                            />
                        </fieldset>
                        <div class="flex flex-col items-end gap-1">
                            <div class="flex justify-end gap-2">
                                <Button
                                    v-if="current === 1"
                                    type="button"
                                    variant="secondary"
                                    data-test="leave"
                                    @click="leaveWizard"
                                >
                                    {{ labels.back }}
                                </Button>
                                <Button
                                    v-else
                                    type="button"
                                    variant="secondary"
                                    data-test="back"
                                    @click="goTo(1)"
                                >
                                    {{ labels.back }}
                                </Button>
                                <Button
                                    type="submit"
                                    :blocked="continueBlocked !== undefined"
                                    :blocked-reason="continueBlocked"
                                    :aria-describedby="
                                        saveBlocked
                                            ? saveReasonId
                                            : stepBlocked
                                              ? continueReasonId
                                              : undefined
                                    "
                                    data-test="continue"
                                >
                                    {{ labels.continue }}
                                </Button>
                            </div>
                            <BlockedReason
                                v-if="stepBlocked && !saveBlocked"
                                :id="continueReasonId"
                                data-test="continue-reason"
                                >{{ stepBlocked }}</BlockedReason
                            >
                        </div>
                    </form>

                    <div
                        v-else-if="current === 3"
                        class="flex flex-col gap-4"
                        data-test="step-3"
                    >
                        <MapDataStep
                            ref="mapRef"
                            :draft-id="draft?.draft_id ?? null"
                            :sample="sample"
                            :revision="() => draft?.revision ?? null"
                            :claim="sampleClaim"
                            :run="runInChain"
                            :blocked="mapBlocked"
                            :summaries="steps.slice(0, 2)"
                            @revision="adoptRevision"
                            @mapped="previewKey += 1"
                            @view="(view) => (mapView = view)"
                            @error="onMappingError"
                            @to-source="goTo(2)"
                            @continue="onContinue"
                        />
                        <div class="flex justify-end">
                            <Button
                                type="button"
                                variant="secondary"
                                data-test="back"
                                @click="goTo(2)"
                            >
                                {{ labels.back }}
                            </Button>
                        </div>
                    </div>

                    <div
                        v-else-if="current === 4"
                        class="flex flex-col gap-4"
                        data-test="step-4-wrap"
                    >
                        <PreviewStep
                            :draft-id="draft?.draft_id ?? null"
                            :name="form.name"
                            :description="form.description"
                            :display="form.display"
                            :acknowledged="uiState.acknowledged_warnings ?? []"
                            :blocked="mapBlocked"
                            :as-user="asUser"
                            :request-key="requestKeyNow"
                            :fetch-body="fetchBodyNow"
                            :source-name="sourceNameNow"
                            :fetch-reason="fetchReasonNow"
                            @update:acknowledged="setAcknowledged"
                            @gate="(reason) => (previewGate = reason)"
                            @live="(ids) => (liveWarnings = ids)"
                            @auth-error="sessionEnded"
                            @back="goTo(3)"
                            @continue="onContinue"
                        />
                    </div>

                    <div
                        v-else
                        class="flex flex-col gap-4"
                        data-test="step-5-wrap"
                    >
                        <ValidateStep
                            :draft-id="draft?.draft_id ?? null"
                            :type-key="form.type"
                            :version="version"
                            :revision="draft?.revision ?? null"
                            :has-source="
                                form.source.dataSourceId !== null &&
                                form.source.endpointId !== null
                            "
                            :pasted="sample.stored?.origin === 'pasted'"
                            :fetch-body="fetchBodyNow"
                            :claim="sampleClaim"
                            :save="settleForValidation"
                            :blocked="mapBlocked"
                            @auth-error="sessionEnded"
                            @error="onMappingError"
                            @back="goTo(4)"
                            @save-draft="saveDraft"
                        />
                    </div>
                </div>

                <div
                    v-if="showPreview"
                    class="min-w-0 lg:sticky lg:top-4 lg:self-start"
                    data-test="preview-column"
                >
                    <LivePreview
                        :name="form.name"
                        :description="form.description"
                        :display="form.display"
                        :version="version"
                        :live="current === 3"
                        :draft-id="draft?.draft_id ?? null"
                        :refresh-key="previewKey"
                        :sample-revision="mapView?.sampleRevision ?? null"
                        :endpoint="previewEndpoint"
                        :slots="previewSlots"
                        :roles="mapView?.roles?.fields ?? []"
                        :blocked="mapBlocked"
                        @change="
                            (slot, opener) => mapRef?.openSlot(slot, opener)
                        "
                        @skip="skipToChecklist"
                        @auth-error="sessionEnded"
                    />
                </div>
            </div>
        </template>

        <UnsavedChangesDialog
            v-model:open="leaveOpen"
            :save-label="labels.saveDraft"
            @save="saveAndLeave"
            @discard="discardAndLeave"
            @keep="keepEditing"
        />
    </div>
</template>
