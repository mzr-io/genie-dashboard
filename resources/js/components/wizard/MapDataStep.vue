<script setup lang="ts">
import {
    ChevronDown,
    ChevronRight,
    ListChecks,
    SlidersHorizontal,
    Tags,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import Banner from '@/components/Banner.vue';
import BlockedReason from '@/components/BlockedReason.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import FormErrorSummary from '@/components/FormErrorSummary.vue';
import FieldTree from '@/components/wizard/FieldTree.vue';
import RolesSection from '@/components/wizard/RolesSection.vue';
import SectionCard from '@/components/wizard/SectionCard.vue';
import SlotChecklist from '@/components/wizard/SlotChecklist.vue';
import { announce, announceDebounced } from '@/lib/announce';
import {
    announceKey,
    applyOrder,
    blockingSlots,
    computeOrder,
    fetchMapping,
    fetchSlotFields,
    flaggedFields,
    postAutoMap,
    postConfirm,
    postUndo,
    putFieldRole,
    putFieldType,
    putPresentation,
    putRecordPath,
    putSlotOverride,
} from '@/lib/blockMapping';
import type {
    FieldType,
    MappingRead,
    MappingRole,
    MappingWrite,
    OverrideNote,
    RoleField,
    RoleUndo,
} from '@/lib/blockMapping';
import {
    ROOT as PRESENTATION_ROOT,
    clean as cleanRules,
    judge as judgeRules,
    sameRules,
    serverErrors as presentationServerErrors,
} from '@/lib/blockPresentation';
import type { PresentationRules } from '@/lib/blockPresentation';
import type { SampleClaim, SampleState } from '@/lib/blockSample';
import { WizardError } from '@/lib/blockWizard';
import { ErrorCode } from '@/types/error-codes';
import {
    blockMapLabels as labels,
    blockPresentationLabels as presentationLabels,
} from '@/locales/labels';

// Wizard step 3, Map Data (Story 3.11b, UI stage 1; UX-DR-65, 66, 123 to 129, 138, 180 to 182). The left column is the step 1 and 2 summaries, a
// sticky status bar, "1 · Roles", a placeholder for "2 · Review block slots" (3.12b) and a collapsed "3 · Transforms" placeholder; the live preview
// stays on the right, owned by the page. Everything shown comes from one mapping read (`GET .../mapping`, with its ETag); a role or record-path
// write goes through the wizard's save chain, carries the Draft's revision and edit-lock claim, hands the revision the server stored back to the
// page and reads the mapping again. The slot checklist, the field picker, hotspots and the legend are later stages.
const props = defineProps<{
    draftId: string | null;
    // The accepted sample (its tables give the roles-table its rows).
    sample: SampleState;
    // The Draft's current revision and the lock claim, read when a write runs (never earlier, a save may have moved them on).
    revision: () => number | null;
    claim: () => SampleClaim | null;
    // Runs a write after the saves in flight, so it never races autosave.
    run: <T>(task: () => Promise<T>) => Promise<T>;
    // Why nothing can be changed now (read-only, offline, published); undefined when it can.
    blocked?: string;
    summaries: { label: string; summary: string | null }[];
}>();

const emit = defineEmits<{
    // The Draft revision a write stored: the page adopts it.
    (e: 'revision', revision: number): void;
    // A failure the page handles like any other save (409 chooser, 423 lost lock, 401 sign-in, 404).
    (e: 'error', error: unknown): void;
    (e: 'to-source'): void;
    // Every required slot is settled: the Admin goes on to Preview (the page saves and moves on).
    (e: 'continue'): void;
    // A mapping write landed (confirm, re-run, override, undo, treat as, role or record path): the live preview refreshes (Story 3.14).
    (e: 'mapped'): void;
    // What the live preview needs of the mapping: the slots (with their fields) and the roles; null while there is none.
    (
        e: 'view',
        view: {
            checklist: NonNullable<MappingRead['checklist']>;
            roles: MappingRead['roles'];
            sampleRevision: number | null;
        } | null,
    ): void;
}>();

const { t } = useI18n();
const SMALL_BELOW = 640;
const DISMISSED_KEY = 'dashflow.map-small-screen.dismissed';

const phase = ref<'loading' | 'ready' | 'error'>('loading');
const read = ref<MappingRead | null>(null);
const refreshFailed = ref(false);
const dismissed = ref<Set<string>>(new Set());
const expanded = ref(true);
const undo = ref<RoleUndo | null>(null);
// The most recent override only, while Undo can still restore it (gone after any other write).
const lastNote = ref<OverrideNote | null>(null);
const notes = computed<Record<string, OverrideNote>>(() =>
    lastNote.value ? { [lastNote.value.slot]: lastNote.value } : {},
);
// Why the last change was not saved (visible and announced); cleared when the next one is.
const notSaved = ref<string | null>(null);
// The Admin has chosen a record path in this visit; until then a pending choice keeps the roles open.
const pathChosen = ref(false);
const root = ref<HTMLElement | null>(null);
const rolesBox = ref<HTMLElement | null>(null);
const checklistRef = ref<InstanceType<typeof SlotChecklist> | null>(null);
const summaryRef = ref<InstanceType<typeof FormErrorSummary> | null>(null);
// The slots' order: Low first, computed on load and on Re-run only, so a row never jumps when it is confirmed.
const order = ref<string[]>([]);
// Continue was activated while blocked: the reason and (with several blockers) the error summary stay up.
const attempted = ref(false);
// A confirm or re-run is in flight.
const pending = ref(false);
const small = ref(false);
// "Browse all fields" (Story 3.11d): closed unless a required slot is missing; "Choose from the field tree" opens it for that slot.
const treeOpen = ref(false);
const treeSlot = ref<string | null>(null);
const treeRequest = ref(0);
const treeRef = ref<InstanceType<typeof FieldTree> | null>(null);
let treeInitialised = false;
const bannerDismissed = ref(false);
const reasonId = 'map-data-blocked';
const continueReasonId = 'map-data-continue-reason';
let etag: string | null = null;
let seq = 0;
let initialised = false;
let knownFlags = new Set<string>();
let pendingCollapse = false;
// A suggestion was just applied: the Admin is working in the roles, so they stay open until focus leaves (a button need not take focus on click).
let holdOpen = false;
let lastAnnounced: string | null = null;

// The presentation rules the editors show (Story 3.15b): the server's, or the Admin's latest edit while it is invalid or not yet saved.
const rules = ref<PresentationRules>({});
// Faults by `config.presentation.<path>`, from the client's own judgement or the server's refusal; any fault blocks Continue.
const presentationErrors = ref<Record<string, string>>({});
let rulesSeq = 0;
// Writes of the rules in flight, and the rules last asked for (what a no-op is measured against until the server answers).
let rulesInFlight = 0;
let lastRequested: PresentationRules | null = null;

const presentationFaultList = computed(() =>
    Object.entries(presentationErrors.value).map(([path, message]) => {
        const slot = /^config\.presentation\.slots\.([^.]+)/.exec(path)?.[1];

        return {
            path,
            message: slot ? `${labelOf(slot)}: ${message}` : message,
        };
    }),
);

const roles = computed(() => read.value?.roles ?? null);
const counts = computed(() => read.value?.checklist?.counts ?? null);
const stored = computed(() => props.sample.stored);
const flagged = computed(() =>
    roles.value ? flaggedFields(roles.value.fields, dismissed.value) : [],
);
const choicePending = computed(
    () => roles.value?.needs_choice === true && !pathChosen.value,
);
const usable = computed(
    () =>
        read.value !== null &&
        read.value.sample === 'ok' &&
        roles.value !== null,
);
const statusText = computed(() =>
    counts.value
        ? labels.statusSummary(
              counts.value.auto_mapped,
              counts.value.total,
              counts.value.confirmed,
              counts.value.required_missing,
          )
        : '',
);
const treeNodes = computed(() =>
    read.value?.sample === 'ok' ? (stored.value?.shape?.nodes ?? []) : [],
);
const treeAvailable = computed(() => treeNodes.value.length > 0);
const treeSlots = computed(() =>
    (read.value?.checklist?.groups ?? []).flatMap((group) => group.slots),
);
const requiredMissing = computed(() => counts.value?.required_missing ?? 0);
const slotGroups = computed(() =>
    read.value?.checklist
        ? applyOrder(read.value.checklist.groups, order.value)
        : [],
);
const blocking = computed(() =>
    read.value?.checklist
        ? blockingSlots(read.value.checklist, order.value)
        : [],
);
// "Map Headline value to continue…" from the catalogue for the required slots, one sentence for each other kind of blocker.
const blockerReason = computed(() => {
    const missing = blocking.value.filter((entry) =>
        entry.reasons.includes('required_missing'),
    );
    const parts: string[] = [];

    if (missing.length === 1) {
        parts.push(t('required-slot-missing.one', { slot: missing[0].label }));
    } else if (missing.length > 1) {
        parts.push(
            t('required-slot-missing.many', {
                count: missing.length,
                slots: missing.map((entry) => entry.label).join(', '),
            }),
        );
    }

    for (const entry of blocking.value) {
        for (const reason of entry.reasons) {
            if (reason === 'unconfirmed_low') {
                parts.push(labels.blockerUnconfirmed(entry.label));
            } else if (reason === 'type_mismatch') {
                parts.push(labels.blockerMismatch(entry.label));
            } else if (reason === 'api_changed') {
                parts.push(labels.blockerChanged(entry.label));
            } else if (reason !== 'required_missing') {
                parts.push(labels.blockerOther(entry.label));
            }
        }
    }

    return parts.join(' ');
});
// The API-change messages, one per affected slot: the catalogue's `api-changed`, and the match sentence built from the labels (the button the row
// draws carries "Use {match}"). The value clause is left out when the sample gives no example.
const changes = computed(() => {
    const out: Record<
        string,
        {
            text: string;
            match: { path: string; display: string } | null;
            matchText: string | null;
        }
    > = {};
    const all = slotGroups.value.flatMap((group) => group.slots);

    for (const entry of read.value?.checklist?.api_changed ?? []) {
        const label =
            all.find((slot) => slot.slot === entry.slot)?.label ?? entry.slot;
        const value =
            roles.value?.fields.find(
                (field) => field.path === entry.match?.path,
            )?.example ?? null;

        out[entry.slot] = {
            text: t('api-changed', { field: entry.display, slot: label }),
            match: entry.match,
            matchText: entry.match
                ? labels.apiMatchFound(entry.match.display, value)
                : null,
        };
    }

    return out;
});
const summaryItems = computed(() =>
    blocking.value.map((entry) => ({
        id: `slot-row-${entry.key}`,
        label: entry.label,
        message: entry.reasons
            .map(
                (reason) =>
                    labels.blockerShort[reason] ?? labels.blockerOtherShort,
            )
            .join(', '),
    })),
);
// Why Continue cannot run: no checklist yet, then the server's blockers, then what blocks every change.
const continueBlocked = computed<string | undefined>(() => {
    const checklist = read.value?.checklist ?? null;

    if (checklist === null) {
        return labels.checklistMissing;
    }

    if (Object.keys(presentationErrors.value).length > 0) {
        return presentationLabels.blockedContinue;
    }

    return checklist.blockers.length > 0 ? blockerReason.value : props.blocked;
});
const showSummary = computed(
    () => attempted.value && blocking.value.length > 1,
);
// The meter: one segment per slot, decorative (the summary text carries the meaning).
const SEGMENT: Record<string, string> = {
    auto_high: 'bg-success',
    confirmed: 'bg-success',
    auto_medium: 'bg-brand',
    auto_low: 'bg-warning-bar',
    overridden: 'bg-info',
    optional_empty: 'bg-surface-muted',
    required_missing: 'bg-error',
    type_mismatch: 'bg-error',
};
const segments = computed(() =>
    (read.value?.checklist?.groups ?? [])
        .flatMap((group) => group.slots)
        .map((slot) => ({
            key: slot.slot,
            tone: SEGMENT[slot.state] ?? 'bg-surface-muted',
        })),
);

function stillHere(): boolean {
    return root.value !== null;
}

function endsSession(error: unknown): boolean {
    return (
        error instanceof WizardError &&
        (error.status === 401 || error.status === 419 || error.status === 404)
    );
}

// Reads the mapping. A background read (after a write) sends `X-Background: 1` and the last ETag, and keeps what is on screen if it fails.
async function load(background = false): Promise<void> {
    const id = props.draftId;
    const mine = (seq += 1);

    if (id === null) {
        phase.value = 'error';

        return;
    }

    if (!background) {
        phase.value = 'loading';
    }

    try {
        const result = await fetchMapping(id, {
            etag: background ? etag : null,
            background,
        });

        if (mine !== seq || !stillHere()) {
            return;
        }

        if (result.data !== null) {
            read.value = result.data;
            etag = result.etag;

            if (!background && result.data.checklist) {
                order.value = computeOrder(result.data.checklist.groups);
            }

            syncExpansion();
        }

        refreshFailed.value = false;
        phase.value = 'ready';
    } catch (error) {
        if (mine !== seq || !stillHere()) {
            return;
        }

        if (endsSession(error)) {
            emit('error', error);
        }

        if (read.value === null) {
            phase.value = 'error';
        } else {
            refreshFailed.value = true;
        }
    }
}

// ---- Roles: open or accepted ------------------------------------------------------------------------------------------

function focusInside(): boolean {
    return (
        rolesBox.value !== null &&
        rolesBox.value.contains(document.activeElement)
    );
}

// Focus in the roles table itself (its chips and its scroll region), not just anywhere in the section.
function focusInTable(): boolean {
    return (
        rolesBox.value
            ?.querySelector('[data-test="roles-table"]')
            ?.contains(document.activeElement) === true
    );
}

// Opens on a first look when something is flagged and accepted otherwise. Once the last flag is resolved it collapses, but never while focus is
// inside (it waits for the next blur); a flag that appears later opens it again without moving focus.
function syncExpansion(): void {
    const paths = new Set(flagged.value.map((field) => field.path));

    if (!initialised) {
        initialised = true;
        expanded.value = paths.size > 0 || choicePending.value;
        knownFlags = paths;

        return;
    }

    const fresh = [...paths].some((path) => !knownFlags.has(path));
    const cleared = paths.size === 0 && knownFlags.size > 0;

    knownFlags = paths;

    if (fresh) {
        expanded.value = true;
        pendingCollapse = false;
    } else if (cleared && expanded.value && !choicePending.value) {
        if (holdOpen || focusInside()) {
            pendingCollapse = true;
        } else {
            expanded.value = false;
        }
    }
}

function onRolesFocusOut(event: FocusEvent): void {
    if (
        pendingCollapse &&
        !rolesBox.value?.contains(event.relatedTarget as Node | null)
    ) {
        pendingCollapse = false;
        expanded.value = false;
    }
}

async function focusChip(path: string): Promise<void> {
    await nextTick();
    rolesBox.value
        ?.querySelectorAll<HTMLElement>('[data-test="role-chip"]')
        .forEach((chip) => {
            if (chip.dataset.fieldPath === path) {
                chip.focus();
            }
        });
}

function keepAs(field: RoleField): void {
    dismissed.value = new Set([...dismissed.value, field.path]);
    syncExpansion();
}

async function looksRight(): Promise<void> {
    const wasInside = focusInTable();

    dismissed.value = new Set([
        ...dismissed.value,
        ...flagged.value.map((field) => field.path),
    ]);
    knownFlags = new Set();
    pendingCollapse = false;
    expanded.value = false;

    if (wasInside) {
        announce(labels.rolesAccepted, 'polite');
    }

    await nextTick();
    rolesBox.value
        ?.querySelector<HTMLElement>('[data-test="edit-roles"]')
        ?.focus();
}

async function editRoles(): Promise<void> {
    expanded.value = true;
    await nextTick();
    rolesBox.value
        ?.querySelector<HTMLElement>('[data-test="role-chip"]')
        ?.focus();
}

// ---- Writes -------------------------------------------------------------------------------------------------------------

// A page-handled refusal (the revision chooser, the lost lock, the session, a missing Draft) shows itself; anything else is said here too.
function handledByPage(error: unknown): boolean {
    return (
        error instanceof WizardError &&
        [401, 404, 409, 419, 423].includes(error.status)
    );
}

function failNotSaved(): void {
    notSaved.value = labels.notSaved;
    announce(labels.notSaved, 'assertive');
}

// One write, after the saves in flight. The revision the server stored is handed to the page; a refusal is the page's to show. A read that
// started earlier must not land over the result, so reads in flight are dropped when a write starts and again when it ends.
function submit<T extends { draft_revision: number } = MappingWrite>(
    call: (draftId: string, revision: number, claim: SampleClaim) => Promise<T>,
    // A refusal this step words itself (a field that does not fit, a stale undo): true when it did, so the page does not also react to it.
    local?: (error: WizardError) => boolean,
): Promise<T | null> {
    seq += 1;
    notSaved.value = null;

    return props.run(async () => {
        seq += 1;

        const claim = props.claim();
        const revision = props.revision();

        if (props.draftId === null || claim === null || revision === null) {
            failNotSaved();

            return null;
        }

        try {
            const done = await call(props.draftId, revision, claim);

            emit('revision', done.draft_revision);
            emit('mapped');

            return done;
        } catch (error) {
            if (
                error instanceof WizardError &&
                local !== undefined &&
                local(error)
            ) {
                return null;
            }

            emit('error', error);

            if (!handledByPage(error)) {
                failNotSaved();
            }

            return null;
        } finally {
            seq += 1;
        }
    });
}

function adopt(
    done: MappingWrite,
    patch?: (roles: NonNullable<MappingRead['roles']>) => void,
): boolean {
    if (read.value === null) {
        return false;
    }

    const next: MappingRead = {
        ...read.value,
        draft_revision: done.draft_revision,
        sample_revision: done.sample_revision,
        query_plan: done.query_plan,
        checklist: {
            groups: done.groups,
            counts: done.counts,
            blockers: done.blockers,
            baseline: done.baseline,
            api_changed: done.api_changed,
        },
    };

    if (next.roles !== null && patch) {
        next.roles = { ...next.roles, fields: [...next.roles.fields] };
        patch(next.roles);
    }

    read.value = next;

    return true;
}

async function changeRole(
    field: RoleField,
    role: MappingRole,
    restore: RoleUndo | null = null,
): Promise<boolean> {
    const before: RoleUndo = {
        path: field.path,
        field: field.display,
        role: field.role,
        now: role,
        overridden: field.overridden,
        confidence: field.confidence,
        suggestion: field.suggestion,
    };
    // Undoing a role that was only inferred clears the override instead of storing the old role as a new one.
    const clear = restore !== null && !restore.overridden;
    const done = await submit((id, revision, claim) =>
        putFieldRole(id, field.path, clear ? null : role, revision, claim),
    );

    if (done === null) {
        return false;
    }

    adopt(done, (report) => {
        report.fields = report.fields.map((candidate) =>
            candidate.path === field.path
                ? {
                      ...candidate,
                      role,
                      overridden: restore ? restore.overridden : true,
                      confidence: restore ? restore.confidence : 'high',
                      suggestion: restore ? restore.suggestion : null,
                  }
                : candidate,
        );
    });
    undo.value = restore === null ? before : null;
    announce(
        restore === null
            ? labels.roleChanged(field.display, labels.roleNames[role])
            : labels.undone(field.display, labels.roleNames[role]),
        'polite',
    );
    syncExpansion();
    // The server's own view settles anything the change moved (a suggestion, the counts).
    await load(true);

    return true;
}

async function suggest(field: RoleField): Promise<void> {
    const role = field.suggestion?.role;

    if (role === undefined) {
        return;
    }

    holdOpen = true;

    try {
        if (await changeRole(field, role)) {
            await focusChip(field.path);
        }
    } finally {
        holdOpen = false;
    }
}

async function undoRole(): Promise<void> {
    const last = undo.value;
    const field = roles.value?.fields.find((f) => f.path === last?.path);

    if (last !== null && field !== undefined) {
        await changeRole(field, last.role, last);
        await focusChip(field.path);
    }
}

async function changePath(path: string): Promise<void> {
    const done = await submit((id, revision, claim) =>
        putRecordPath(id, path, revision, claim),
    );

    if (done === null) {
        return;
    }

    adopt(done, (report) => {
        report.record_path = path;
    });
    pathChosen.value = true;
    undo.value = null;
    await load(true);
    announce(
        labels.recordPathChanged(roles.value?.record_path_display ?? path),
        'polite',
    );
}

// ---- Slot checklist -----------------------------------------------------------------------------------------------------

async function confirmSlot(slot: string): Promise<void> {
    if (pending.value) {
        return;
    }

    pending.value = true;

    try {
        const done = await submit((id, revision, claim) =>
            postConfirm(id, slot, revision, claim),
        );

        if (done === null) {
            // The page may have moved focus to its own failure message: the Admin stays on the Confirm they pressed.
            await new Promise((resolve) => setTimeout(resolve, 0));
            checklistRef.value?.focusConfirm(slot);

            return;
        }

        const label =
            slotGroups.value
                .flatMap((g) => g.slots)
                .find((s) => s.slot === slot)?.label ?? slot;

        // The row is kept (under Needs review) in the same tick its state changes, so it never drops out for a moment.
        checklistRef.value?.keep(slot);
        clearNote();

        if (!adopt(done)) {
            return;
        }

        announce(
            labels.confirmedAnnounce(label, done.counts.needs_review),
            'polite',
        );
        await nextTick();
        await checklistRef.value?.focusSlot(slot);
    } finally {
        pending.value = false;
    }
}

// ---- Field picker, override, Undo and Treat as (Story 3.13b) ---------------------------------------------------------------

function loadFields(slot: string) {
    return props.draftId === null
        ? Promise.reject(new WizardError(0))
        : fetchSlotFields(props.draftId, slot);
}

function labelOf(slot: string): string {
    return (
        slotGroups.value.flatMap((g) => g.slots).find((s) => s.slot === slot)
            ?.label ?? slot
    );
}

function say(message: string): void {
    notSaved.value = message;
    announce(message, 'assertive');
}

// A field the server refused for a slot (422 with a `slot.<key>` error): its reason is shown and announced, and nothing changed. Any other 422
// is the page's to show.
function refused(error: WizardError): boolean {
    const keys = Object.keys(error.fields ?? {}).filter((key) =>
        key.startsWith('slot.'),
    );

    if (error.status !== 422 || keys.length === 0) {
        return false;
    }

    say(error.fields[keys[0]]?.[0] ?? labels.notPicked);

    return true;
}

// A Treat as the server refused (422): said in its own words, nothing changed.
function treatRefused(error: WizardError): boolean {
    if (error.status !== 422) {
        return false;
    }

    say(Object.values(error.fields ?? {}).flat()[0] ?? labels.notTreated);

    return true;
}

function clearNote(): void {
    lastNote.value = null;
}

// ---- Presentation rules (Story 3.15b) ----------------------------------------------------------------------------------------

// The server refused the rules (422 with `config.presentation.<path>` errors): each fault is shown on its field, nothing was stored and the
// preview keeps its last valid state.
function presentationRefused(error: WizardError): boolean {
    const faults = presentationServerErrors(error.fields ?? {});

    if (error.status !== 422 || Object.keys(faults).length === 0) {
        return false;
    }

    presentationErrors.value = faults;
    announce(
        Object.values(faults)[0] ?? presentationLabels.refused,
        'assertive',
    );

    return true;
}

async function savePresentation(next: PresentationRules): Promise<void> {
    const wanted = cleanRules(next);
    const mine = (rulesSeq += 1);

    rules.value = wanted;

    // Judged here first: an invalid value is shown, blocks Continue and is never sent.
    const faults = judgeRules(wanted);

    if (Object.keys(faults).length > 0) {
        presentationErrors.value = faults;
        announce(Object.values(faults)[0], 'assertive');

        return;
    }

    presentationErrors.value = {};

    // Nothing changed from what is stored: no write.
    if (sameRules(wanted, lastRequested ?? read.value?.presentation ?? {})) {
        return;
    }

    lastRequested = wanted;
    rulesInFlight += 1;

    let done: Awaited<ReturnType<typeof putPresentation>> | null;

    try {
        done = await submit(
            (id, revision, claim) =>
                putPresentation(id, wanted, revision, claim),
            presentationRefused,
        );
    } finally {
        rulesInFlight -= 1;
    }

    if (done === null || read.value === null) {
        if (rulesInFlight === 0) {
            lastRequested = null;
        }

        return;
    }

    read.value = {
        ...read.value,
        draft_revision: done.draft_revision,
        query_plan: done.query_plan,
        presentation: done.presentation,
    };

    if (rulesInFlight === 0) {
        lastRequested = null;
    }

    if (
        mine === rulesSeq &&
        Object.keys(presentationErrors.value).length === 0
    ) {
        rules.value = cleanRules(done.presentation ?? wanted);
    }
}

// The server's rules are the editors' rules unless an edit is invalid or unsaved.
watch(
    () => read.value?.presentation,
    (stored) => {
        // A newer edit is still on its way: its answer settles the rules, not this read.
        if (
            rulesInFlight === 0 &&
            Object.keys(presentationErrors.value).length === 0
        ) {
            rules.value = cleanRules(stored ?? {});
        }
    },
    { immediate: true },
);

// Settles what a write moved (the roles, a suggestion) with the server's own view; a read that fails says so and keeps what is shown.
async function reread(): Promise<void> {
    try {
        await load(true);
    } catch {
        refreshFailed.value = true;
    }
}

async function pickField(slot: string, path: string | null): Promise<void> {
    if (pending.value) {
        return;
    }

    pending.value = true;

    try {
        const done = await submit(
            (id, revision, claim) =>
                putSlotOverride(id, slot, path, revision, claim),
            refused,
        );

        if (done === null) {
            await checklistRef.value?.focusSlot(slot);

            return;
        }

        clearNote();

        if (!adopt(done)) {
            await reread();

            return;
        }

        const display =
            done.groups.flatMap((g) => g.slots).find((s) => s.slot === slot)
                ?.display ?? null;
        const change = done.role_change;

        undo.value = null;

        if (done.undo) {
            lastNote.value = {
                slot,
                undo: done.undo,
                text: change
                    ? labels.overriddenNote(
                          display ?? change.path,
                          labels.roleNames[change.to] ?? change.to,
                          labels.roleNames[change.from] ?? change.from,
                      )
                    : labels.overriddenPlain(display, labelOf(slot)),
            };
        }

        announce(
            change
                ? labels.overriddenNote(
                      display ?? change.path,
                      labels.roleNames[change.to] ?? change.to,
                      labels.roleNames[change.from] ?? change.from,
                  )
                : labels.overriddenAnnounce(labelOf(slot), display),
            'polite',
        );
        await nextTick();
        await checklistRef.value?.focusSlot(slot);

        // The field's role may have moved: the server's own view settles the roles (the checklist is already fresh).
        if (change) {
            await reread();
        }
    } finally {
        pending.value = false;
    }
}

async function undoSlot(slot: string): Promise<void> {
    const note = lastNote.value;

    if (pending.value || note === null || note.slot !== slot) {
        return;
    }

    pending.value = true;

    try {
        const done = await submit(
            (id, revision, claim) => postUndo(id, note.undo, revision, claim),
            (error) => {
                if (
                    error.status !== 409 ||
                    error.code !== ErrorCode.BlocksSlotChanged
                ) {
                    return false;
                }

                // Someone changed the slot after the override: nothing was undone, and the page shows what is there now.
                if (error.current) {
                    emit('revision', error.current.revision);
                }

                clearNote();
                say(labels.undoStale);

                return true;
            },
        );

        if (done === null) {
            await reread();
            await checklistRef.value?.focusSlot(slot);

            return;
        }

        clearNote();
        undo.value = null;

        if (!adopt(done)) {
            await reread();

            return;
        }

        announce(labels.undoneSlot(labelOf(slot)), 'polite');
        await nextTick();
        await checklistRef.value?.focusSlot(slot);

        // Only a restored role needs the roles read again.
        if (note.undo.role) {
            await reread();
        }
    } finally {
        pending.value = false;
    }
}

async function treatAs(
    slot: string,
    path: string,
    type: FieldType | null,
): Promise<void> {
    if (pending.value) {
        return;
    }

    pending.value = true;

    try {
        const done = await submit(
            (id, revision, claim) =>
                putFieldType(id, path, type, revision, claim),
            treatRefused,
        );

        if (done === null) {
            await checklistRef.value?.focusSlot(slot);

            return;
        }

        clearNote();

        if (!adopt(done)) {
            await reread();

            return;
        }

        const shown =
            done.groups.flatMap((g) => g.slots).find((s) => s.slot === slot)
                ?.display ?? path;

        announce(
            type === null
                ? labels.treatedAuto(shown)
                : labels.treatedAs(shown, labels.typeNames[type] ?? type),
            'polite',
        );
        await nextTick();
        await checklistRef.value?.focusSlot(slot);

        // Re-read: the field's effective type moved, and so may its role and the plan.
        await reread();
    } finally {
        pending.value = false;
    }
}

async function rerun(): Promise<void> {
    if (pending.value) {
        return;
    }

    pending.value = true;

    try {
        const done = await submit((id, revision, claim) =>
            postAutoMap(id, revision, claim),
        );

        if (done === null || !adopt(done)) {
            return;
        }

        order.value = computeOrder(done.groups);
        clearNote();
        attempted.value = false;
        checklistRef.value?.clearKept();
        announce(labels.rerunDone, 'polite');
    } finally {
        pending.value = false;
    }
}

// Continue: blocked while a slot needs the Admin; activating it then says why and goes to the first blocking row (or, with several, the summary).
function onContinueBlocked(): HTMLElement | null {
    attempted.value = true;

    const list = blocking.value;
    const first = list[0];

    // A fault in the format and display settings: its row opens and takes focus.
    const fault = Object.keys(presentationErrors.value)[0];

    if (first === undefined && fault !== undefined) {
        const bands = `${PRESENTATION_ROOT}.bands`;
        const slot =
            /^config\.presentation\.slots\.([^.]+)/.exec(fault)?.[1] ??
            (fault === bands || fault.startsWith(`${bands}.`)
                ? 'headline_value'
                : fault === `${PRESENTATION_ROOT}.empty_display`
                  ? slotGroups.value
                        .flatMap((g) => g.slots)
                        .find((s) => s.slot !== 'headline_value')?.slot
                  : undefined);

        if (slot !== undefined && checklistRef.value !== null) {
            checklistRef.value.openRow = slot;
            void nextTick(async () => {
                await checklistRef.value?.focusSlot(slot);
                await nextTick();
                (
                    root.value?.querySelector<HTMLElement>(
                        `[data-slot-key="${slot}"] [data-test=presentation-editor] [aria-invalid=true]`,
                    ) ??
                    root.value?.querySelector<HTMLElement>(
                        `[data-slot-key="${slot}"] [data-test=presentation-editor] input, [data-slot-key="${slot}"] [data-test=presentation-editor] select`,
                    )
                )?.focus();
            });
        }

        return null;
    }

    if (first === undefined) {
        return null;
    }

    if (list.length > 1) {
        void nextTick(() => summaryRef.value?.focus());
    } else if (first.known) {
        void nextTick(() => checklistRef.value?.focusSlot(first.key));
    }

    return null;
}

// A summary link goes through the checklist, which shows the row first when a filter hides it.
function onSummaryClick(event: MouseEvent): void {
    const link = (event.target as HTMLElement).closest('a');

    if (link === null) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    void checklistRef.value?.focusSlot(
        (link.getAttribute('href') ?? '').replace(/^#slot-row-/, ''),
    );
}

// Once nothing blocks any more, a later block starts from a quiet page.
watch(
    () => read.value?.checklist?.blockers.length ?? 0,
    (count) => {
        if (count === 0) {
            attempted.value = false;
        }
    },
);

function onContinue(): void {
    attempted.value = false;
    emit('continue');
}

// ---- Field tree (Story 3.11d) ------------------------------------------------------------------------------------------

// Open on the first look when a required slot is missing, and again whenever one becomes missing.
watch(
    requiredMissing,
    (now, before) => {
        if (now > 0 && (!treeInitialised || before === 0)) {
            treeOpen.value = true;
        }

        treeInitialised = true;
    },
    { immediate: true },
);

async function chooseFromTree(slot: string): Promise<void> {
    if (!treeAvailable.value) {
        return;
    }

    treeSlot.value = slot;
    treeRequest.value += 1;
    treeOpen.value = true;
    await nextTick();
    treeRef.value?.focus();
}

// ---- Status announcements ----------------------------------------------------------------------------------------------

// The status bar speaks only when the needs-review or required-missing figure changes, not on every role edit.
watch(counts, (next) => {
    const key = announceKey(next);

    if (lastAnnounced !== null && key !== lastAnnounced) {
        // A tick after the role message, so the status never overwrites it.
        announceDebounced('map-status', statusText.value);
    }

    lastAnnounced = key;
});

// The Draft may arrive after the step opens (a first save): read as soon as there is one.
watch(
    () => props.draftId,
    (id) => {
        if (id !== null && read.value === null) {
            void load();
        }
    },
);

// ---- Small screens -----------------------------------------------------------------------------------------------------

function readDismissed(): boolean {
    try {
        return window.localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        return false;
    }
}

function dismissBanner(): void {
    bannerDismissed.value = true;

    try {
        window.localStorage.setItem(DISMISSED_KEY, '1');
    } catch {
        // A browser that keeps nothing still hides it for this visit.
    }
}

function measure(): void {
    small.value = window.innerWidth < SMALL_BELOW;
}

const showSmallBanner = computed(() => small.value && !bannerDismissed.value);

// The preview reads the checklist and roles from here (Story 3.14): sent on every change, and cleared when the step goes.
watch(
    read,
    (next) =>
        emit(
            'view',
            next?.checklist
                ? {
                      checklist: next.checklist,
                      roles: next.roles,
                      sampleRevision: next.sample_revision,
                  }
                : null,
        ),
    { immediate: true },
);

// A hotspot on the preview was activated (Story 3.14): scroll to the slot's row and open its picker; Escape returns focus to `from`.
function openSlot(slot: string, from: HTMLElement | null = null): void {
    void checklistRef.value?.openSlot(slot, from);
}

// The preview's skip link: focus moves to the slot checklist.
function focusChecklist(): void {
    checklistRef.value?.focusFirst();
}

defineExpose({ openSlot, focusChecklist });

onMounted(() => {
    bannerDismissed.value = readDismissed();
    measure();
    window.addEventListener('resize', measure);
    void load();
});

onBeforeUnmount(() => {
    seq += 1;
    emit('view', null);
    window.removeEventListener('resize', measure);
});
</script>

<template>
    <div
        ref="root"
        class="flex min-w-0 flex-col gap-4"
        :aria-label="labels.region"
        role="region"
        data-test="map-data-step"
    >
        <Banner
            v-if="showSmallBanner"
            variant="info"
            dismissible
            data-test="small-screen"
            @dismiss="dismissBanner"
        >
            {{ t('map-small-screen') }}
        </Banner>

        <ul
            class="m-0 grid list-none gap-2 p-0 sm:grid-cols-2"
            data-test="step-summaries"
        >
            <li
                v-for="(item, index) in summaries"
                :key="item.label"
                class="type-body-sm flex flex-col gap-0.5 rounded-lg border border-border-default bg-surface-card px-3 py-2"
                data-test="map-summary"
            >
                <span class="font-semibold text-text-primary">{{
                    labels.summaryLabel(index + 1, item.label)
                }}</span>
                <span class="text-text-secondary">{{
                    item.summary ?? '—'
                }}</span>
            </li>
        </ul>

        <div
            v-if="phase === 'loading'"
            role="status"
            aria-busy="true"
            class="flex flex-col gap-3"
            data-test="map-loading"
        >
            <span class="sr-only">{{ labels.loading }}</span>
            <Skeleton class="h-12 w-full" :caption="false" />
            <Skeleton class="h-56 w-full" :caption="false" />
        </div>

        <section
            v-else-if="
                phase === 'error' ||
                (read !== null && read.sample === 'ok' && read.roles === null)
            "
            class="flex flex-col items-start gap-4 rounded-lg border border-border-default bg-surface-card p-6"
            data-test="map-failed"
        >
            <p class="type-body-md text-text-secondary" role="alert">
                {{ labels.loadFailed }}
            </p>
            <Button
                type="button"
                variant="secondary"
                data-test="map-retry"
                @click="load()"
                >{{ labels.retry }}</Button
            >
        </section>

        <section
            v-else-if="read && read.sample !== 'ok'"
            class="flex flex-col items-start gap-3 rounded-lg border border-border-default bg-surface-card p-6"
            role="status"
            data-test="sample-gone"
        >
            <h2 class="type-title-sm text-text-primary">
                {{ labels.sampleGoneTitle }}
            </h2>
            <p class="type-body-md text-text-secondary">
                {{
                    read.sample === 'expired'
                        ? labels.sampleExpired
                        : labels.sampleInvalid
                }}
            </p>
            <Button
                type="button"
                variant="secondary"
                data-test="to-source"
                @click="emit('to-source')"
                >{{ labels.toDataSource }}</Button
            >
        </section>

        <template v-else-if="usable && roles">
            <div
                v-if="counts"
                class="rounded-lg border border-border-default bg-surface-card px-4 py-3 [@media(min-height:480px)]:sticky [@media(min-height:480px)]:top-0 [@media(min-height:480px)]:z-10"
                role="group"
                :aria-label="labels.statusBar"
                data-test="status-bar"
            >
                <p
                    class="type-title-sm text-text-primary tabular-nums"
                    data-test="status-summary"
                >
                    {{ statusText }}
                </p>
                <p class="type-caption text-text-muted">
                    {{ labels.statusSubtitle }}
                </p>
                <span
                    class="type-caption mt-1 inline-block rounded-sm bg-info-soft px-2 text-info-strong"
                    data-test="sample-badge"
                    >{{ labels.sampleBadge }}</span
                >
                <div
                    class="mt-2 flex gap-0.5"
                    aria-hidden="true"
                    data-test="status-meter"
                >
                    <span
                        v-for="segment in segments"
                        :key="segment.key"
                        class="h-1.5 min-w-2 flex-1 rounded-full"
                        :class="segment.tone"
                    />
                </div>
            </div>

            <BlockedReason
                v-if="blocked"
                :id="reasonId"
                data-test="map-blocked"
                >{{ blocked }}</BlockedReason
            >

            <p
                v-if="notSaved"
                class="type-body-sm text-error-text"
                data-test="map-not-saved"
            >
                {{ notSaved }}
            </p>

            <p
                v-if="refreshFailed"
                class="type-body-sm text-text-secondary"
                role="status"
                data-test="refresh-failed"
            >
                {{ labels.refreshFailed }}
            </p>

            <SectionCard
                :title="labels.rolesTitle"
                :caption="labels.rolesCaption"
                :icon="Tags"
            >
                <div
                    ref="rolesBox"
                    data-test="roles-section"
                    @focusout="onRolesFocusOut"
                >
                    <RolesSection
                        :roles="roles"
                        :sample="stored"
                        :expanded="expanded"
                        :dismissed="dismissed"
                        :blocked="blocked"
                        :undo="undo"
                        :suggestion="read?.dataset_suggestion ?? null"
                        :path-chosen="pathChosen"
                        @role="(field, role) => changeRole(field, role)"
                        @suggest="suggest"
                        @keep="keepAs"
                        @looks-right="looksRight"
                        @edit="editRoles"
                        @path="changePath"
                        @undo="undoRole"
                    />
                </div>

                <div
                    v-if="treeNodes.length > 0"
                    class="mt-3 grid gap-2"
                    data-test="tree-section"
                >
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="w-fit"
                        :aria-expanded="treeOpen ? 'true' : 'false'"
                        aria-controls="map-field-tree"
                        data-test="tree-toggle"
                        @click="treeOpen = !treeOpen"
                    >
                        <ChevronDown
                            v-if="treeOpen"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <ChevronRight
                            v-else
                            class="size-4"
                            aria-hidden="true"
                        />
                        {{ labels.treeBrowse }}
                    </Button>
                    <div v-if="treeOpen" id="map-field-tree">
                        <FieldTree
                            ref="treeRef"
                            :nodes="treeNodes"
                            :slots="treeSlots"
                            :preferred="treeSlot"
                            :request-key="treeRequest"
                            :record-path="roles.record_path"
                            :load-fields="loadFields"
                            :refresh-key="read?.draft_revision ?? 0"
                            :blocked="blocked"
                            :busy="pending"
                            @use="pickField"
                        />
                    </div>
                </div>
            </SectionCard>

            <SectionCard
                :title="labels.slotsTitle"
                :caption="labels.slotsCaption"
                :icon="ListChecks"
            >
                <SlotChecklist
                    ref="checklistRef"
                    :groups="slotGroups"
                    :baseline="read?.checklist?.baseline ?? []"
                    :blocked="blocked"
                    :reason-id="reasonId"
                    :busy="pending"
                    :tree-available="treeAvailable"
                    :load-fields="loadFields"
                    :notes="notes"
                    :changes="changes"
                    :rules="rules"
                    :presentation-errors="presentationErrors"
                    @presentation="savePresentation"
                    @confirm="confirmSlot"
                    @rerun="rerun"
                    @pick="pickField"
                    @undo="undoSlot"
                    @treat="treatAs"
                    @tree="chooseFromTree"
                />

                <FormErrorSummary
                    v-if="showSummary"
                    ref="summaryRef"
                    :items="summaryItems"
                    @click.capture="onSummaryClick"
                    data-test="blockers-summary"
                />

                <ul
                    v-if="presentationFaultList.length > 0"
                    class="m-0 grid list-none gap-1 rounded-md border border-error-border bg-error-soft p-3"
                    data-test="presentation-errors"
                >
                    <li
                        v-for="fault in presentationFaultList"
                        :key="fault.path"
                        class="type-body-sm text-error-text"
                    >
                        {{ fault.message }}
                    </li>
                </ul>

                <div class="flex flex-col items-end gap-1">
                    <Button
                        type="button"
                        :blocked="continueBlocked !== undefined"
                        :blocked-reason="continueBlocked"
                        :first-blocker="onContinueBlocked"
                        :aria-describedby="
                            continueBlocked ? continueReasonId : undefined
                        "
                        data-test="slots-continue"
                        @click="onContinue"
                        >{{ labels.continueToPreview }}</Button
                    >
                    <BlockedReason
                        v-if="continueBlocked"
                        :id="continueReasonId"
                        data-test="slots-continue-reason"
                        >{{ continueBlocked }}</BlockedReason
                    >
                </div>
            </SectionCard>

            <details
                class="rounded-lg border border-border-default bg-surface-card p-4"
                data-test="transforms-placeholder"
            >
                <summary
                    class="type-title-sm flex cursor-pointer items-center gap-3 text-text-primary"
                >
                    <SlidersHorizontal class="size-4" aria-hidden="true" />
                    {{ labels.transformsTitle }}
                </summary>
                <p class="type-caption mt-2 text-text-muted">
                    {{ labels.transformsCaption }}
                </p>
                <p class="type-body-sm mt-2 text-text-secondary">
                    {{ labels.transformsPlaceholder }}
                </p>
            </details>
        </template>
    </div>
</template>
