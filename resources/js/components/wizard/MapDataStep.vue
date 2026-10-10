<script setup lang="ts">
import { ListChecks, SlidersHorizontal, Tags } from '@lucide/vue';
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
import RolesSection from '@/components/wizard/RolesSection.vue';
import SectionCard from '@/components/wizard/SectionCard.vue';
import { announce, announceDebounced } from '@/lib/announce';
import {
    announceKey,
    fetchMapping,
    flaggedFields,
    putFieldRole,
    putRecordPath,
} from '@/lib/blockMapping';
import type {
    MappingRead,
    MappingRole,
    MappingWrite,
    RoleField,
    RoleUndo,
} from '@/lib/blockMapping';
import type { SampleClaim, SampleState } from '@/lib/blockSample';
import { WizardError } from '@/lib/blockWizard';
import { blockMapLabels as labels } from '@/locales/labels';

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
// Why the last change was not saved (visible and announced); cleared when the next one is.
const notSaved = ref<string | null>(null);
// The Admin has chosen a record path in this visit; until then a pending choice keeps the roles open.
const pathChosen = ref(false);
const root = ref<HTMLElement | null>(null);
const rolesBox = ref<HTMLElement | null>(null);
const small = ref(false);
const bannerDismissed = ref(false);
const reasonId = 'map-data-blocked';
let etag: string | null = null;
let seq = 0;
let initialised = false;
let knownFlags = new Set<string>();
let pendingCollapse = false;
// A suggestion was just applied: the Admin is working in the roles, so they stay open until focus leaves (a button need not take focus on click).
let holdOpen = false;
let lastAnnounced: string | null = null;

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
function submit(
    call: (
        draftId: string,
        revision: number,
        claim: SampleClaim,
    ) => Promise<MappingWrite>,
): Promise<MappingWrite | null> {
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

            return done;
        } catch (error) {
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
): void {
    if (read.value === null) {
        return;
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

onMounted(() => {
    bannerDismissed.value = readDismissed();
    measure();
    window.addEventListener('resize', measure);
    void load();
});

onBeforeUnmount(() => {
    seq += 1;
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
                        @role="(field, role) => changeRole(field, role)"
                        @suggest="suggest"
                        @keep="keepAs"
                        @looks-right="looksRight"
                        @edit="editRoles"
                        @path="changePath"
                        @undo="undoRole"
                    />
                </div>
            </SectionCard>

            <SectionCard
                :title="labels.slotsTitle"
                :caption="labels.slotsCaption"
                :icon="ListChecks"
            >
                <p
                    class="type-body-sm text-text-secondary"
                    data-test="slots-placeholder"
                >
                    {{ labels.slotsPlaceholder }}
                </p>
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
