<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AddBlocksPanel from '@/components/AddBlocksPanel.vue';
import DashboardGrid from '@/components/DashboardGrid.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useAddBlocksPanel } from '@/composables/useAddBlocksPanel';
import { announce, announceAggregated } from '@/lib/announce';
import type { CatalogueBlock } from '@/lib/blockCatalogue';
import {
    DashboardError,
    addBlock,
    fetchDashboard,
    fetchResults,
    refreshItem,
    removeBlock,
    restoreBlock,
    saveCompactOrder,
    saveLayout,
    setMinimized,
} from '@/lib/dashboards';
import type {
    Dashboard,
    DashboardItem,
    HeightPreset,
    ItemResult,
} from '@/lib/dashboards';
import { formatDateTime } from '@/lib/format';
import {
    NO_LIMITS,
    describeMove,
    place,
    resizeStep,
    sameLayout,
    step,
} from '@/lib/layout';
import type { Geometry, Layout } from '@/lib/layout';
import { SIGN_IN_URL } from '@/lib/session';
import { registerShortcut } from '@/lib/shortcuts';
import { useToasts } from '@/stores/toasts';
import {
    addBlocksLabels,
    dashboardLabels as labels,
    shellPages,
} from '@/locales/labels';

// A member's dashboard (Story 4.4; UX-DR-236, 237): the shell appears at once with `{dashboard_id, revision}` and the layout is fetched from
// `GET /api/v1/dashboards/{id}`. A failed load shows `msg:dashboard-load-failed` with Retry and no partial or default layout, and saves nothing.
// The switcher, Date Range and Edit layout controls are in place; their behaviour arrives with Stories 4.11, 4.15 and 4.16.
// `dashboard_id` is null for a person with no active membership: there is nothing to load and the empty state shows.
const props = defineProps<{ dashboard_id: string | null; revision: number }>();

const { t } = useI18n();
const page = usePage();

// The Add-blocks Panel (Story 4.7): at 1024 px and wider a non-modal complementary region that pushes the dashboard; below it a modal sheet.
const {
    open: panelOpen,
    show: showPanel,
    hide: hidePanel,
} = useAddBlocksPanel();
const wide = useMediaQuery('(min-width: 1024px)');
const addedIds = computed(
    () => dashboard.value?.items.map((item) => item.block_id) ?? [],
);

async function skipToDashboard(): Promise<void> {
    if (!wide.value) {
        hidePanel();
    }

    await nextTick();

    const first = document.querySelector<HTMLElement>(
        '[data-slot=block-card] h2',
    );

    if (first !== null) {
        first.setAttribute('tabindex', '-1');
        first.focus();
    } else {
        document.querySelector<HTMLElement>('[data-slot=add-block]')?.focus();
    }
}

// Admins also see technical details in About this block; the footer's `{user.*}` tokens come from the signed-in profile.
const admin = computed(
    () =>
        (page.props.shell as { role?: string | null } | null)?.role === 'admin',
);
const profile = computed(() => {
    const user = (
        page.props.auth as
            | { user?: { name?: string; email?: string } }
            | undefined
    )?.user;

    return { name: user?.name ?? null, email: user?.email ?? null };
});

const dashboard = ref<Dashboard | null>(null);
const toasts = useToasts();
// Add and remove (Story 4.9): the Blocks with a command in flight, those just added (outlined for a few seconds), where the next Block would go
// while "+ Add" is hovered or focused, and how many new Blocks sit below the visible area.
const busy = ref<string[]>([]);
const justAdded = ref<string[]>([]);
const hint = ref<{
    col: number;
    row: number;
    width: number;
    height: HeightPreset;
} | null>(null);
const below = ref<{ itemId: string; count: number } | null>(null);

// The history of this dashboard's adds and removes while it is open (Story 4.10): Ctrl+Z walks back through it with no time limit, and the toast's
// Undo reverses its own entry. An entry whose Block can no longer be put back is marked and skipped.
type Entry = {
    id: number;
    kind: 'add' | 'remove';
    blockId: string;
    name: string;
    geometry: DashboardItem['geometry'];
    minimized: boolean;
    unavailable: boolean;
};
const history = ref<Entry[]>([]);
let entryId = 0;

// Edit Layout Mode (Story 4.11): drag and resize act on a working copy of the layout, with its own Undo and Redo, and nothing is saved until Done.
// A pointer gesture previews from the layout as it stood when the gesture began, so a drag never compounds.
const editing = ref(false);
const working = ref<Layout>({});
const committed = ref<Layout>({});
const past = ref<Layout[]>([]);
const future = ref<Layout[]>([]);
const saving = ref(false);
// The Block picked up with the keyboard (Story 4.12), the layout it was lifted from, and whether one more Esc would finish the mode.
const picked = ref<{ id: string; base: Layout } | null>(null);
let escArmed = false;
const desktop = useMediaQuery('(min-width: 768px)');
const compactOrder = ref<string[] | null>(null);
const saveError = ref<'failed' | 'conflict' | null>(null);
const focusEditButton = (): void =>
    document.querySelector<HTMLElement>('[data-slot=edit-layout]')?.focus();

// The member's own minimize choices and the revision every layout command carries (AD-14), the Blocks being refreshed and those in their window.
const minimized = ref<Record<string, boolean>>({});
const revision = ref(props.revision);
const refreshing = ref<string[]>([]);
const limited = ref<string[]>([]);
// The latest result of each item, kept in memory by item id and replaced only when its `result_etag` changes; a pending answer never replaces data.
const results = ref<Record<string, ItemResult>>({});
let resultsTimer: ReturnType<typeof setTimeout> | null = null;
let resultsController: AbortController | null = null;
let polls = 0;
let announcedStale = 0;

// Pending results are asked for again, a bounded number of times, until they arrive; live updates (Story 4.13) replace this.
const POLL_MS = 3000;
const MAX_POLLS = 20;
const state = ref<'loading' | 'ready' | 'error'>('loading');
let controller: AbortController | null = null;

const title = computed(
    () => dashboard.value?.name ?? shellPages.overview.title,
);
const layoutOf = (list: DashboardItem[]): Layout =>
    Object.fromEntries(
        list.map((item) => [item.item_id, { ...item.geometry }]),
    );
// What the grid draws: the saved geometry, or the working copy while the layout is being edited.
const items = computed(() =>
    (dashboard.value?.items ?? []).map((item) =>
        editing.value && working.value[item.item_id] !== undefined
            ? { ...item, geometry: working.value[item.item_id] }
            : item,
    ),
);
const canUndoLayout = computed(() => past.value.length > 0);
const canRedoLayout = computed(() => future.value.length > 0);

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const mine = controller;

    if (props.dashboard_id === null) {
        dashboard.value = null;
        state.value = 'ready';

        return;
    }

    state.value = 'loading';

    try {
        const result = await fetchDashboard(props.dashboard_id, mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        dashboard.value = result;
        revision.value = result.revision;
        compactOrder.value = result.compact_order ?? null;
        minimized.value = Object.fromEntries(
            result.items.map((item) => [item.item_id, item.minimized]),
        );
        state.value = 'ready';
    } catch (error) {
        if (mine.signal.aborted) {
            return;
        }

        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        dashboard.value = null;
        state.value = 'error';
    }
}

function applyResults(items: ItemResult[]): boolean {
    let pending = false;
    const next = { ...results.value };

    for (const item of items) {
        const known = next[item.item_id];

        if (item.state === 'pending') {
            pending = true;

            if (known === undefined) {
                next[item.item_id] = item;
            }

            continue;
        }

        if (
            known === undefined ||
            known.result_etag !== item.result_etag ||
            known.state !== item.state ||
            known.stale !== item.stale
        ) {
            next[item.item_id] = item;
        }
    }

    results.value = next;

    return pending;
}

// Stale blocks are announced once, together, about two seconds after the change; recovery says so once.
function announceStale(): void {
    const stale = Object.values(results.value).filter((r) => r.stale);

    if (stale.length === announcedStale) {
        return;
    }

    if (stale.length === 0) {
        announce(t('stale-aggregate.recovered'));
    } else {
        const latest = stale
            .map((r) => r.last_success_at)
            .filter((at): at is string => at !== null)
            .sort()
            .at(-1);

        announceAggregated(
            'stale-aggregate',
            t(
                'stale-aggregate.announce',
                {
                    count: stale.length,
                    time:
                        latest === undefined
                            ? ''
                            : formatDateTime(latest, { timeStyle: 'short' }),
                },
                stale.length,
            ),
        );
    }

    announcedStale = stale.length;
}

async function loadResults(): Promise<void> {
    if (props.dashboard_id === null) {
        return;
    }

    resultsController?.abort();
    resultsController = new AbortController();
    const mine = resultsController;

    if (resultsTimer !== null) {
        clearTimeout(resultsTimer);
        resultsTimer = null;
    }

    try {
        const items = await fetchResults(props.dashboard_id, null, mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        const pending = applyResults(items);
        announceStale();
        polls = pending ? polls + 1 : 0;

        if (pending && polls < MAX_POLLS) {
            resultsTimer = setTimeout(loadResults, POLL_MS);
        }
    } catch (error) {
        if (mine.signal.aborted) {
            return;
        }

        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);
        }

        // Any other failure leaves what is shown as it is: a Block with a result keeps it, one without keeps its skeleton.
    }
}

// Minimize or Expand: `SetMinimized` with the revision held. A stale revision reloads the layout and tries once more; a second refusal leaves the
// Block as it was. The change is shown at once and undone when it fails.
async function onMinimize(itemId: string, value: boolean): Promise<void> {
    if (props.dashboard_id === null) {
        return;
    }

    const before = minimized.value[itemId];
    minimized.value = { ...minimized.value, [itemId]: value };

    try {
        let outcome = await setMinimized(
            props.dashboard_id,
            itemId,
            value,
            revision.value,
        );

        if (!outcome.ok && outcome.conflict) {
            await load();

            outcome = await setMinimized(
                props.dashboard_id,
                itemId,
                value,
                revision.value,
            );
        }

        if (outcome.ok) {
            revision.value = outcome.revision;
            minimized.value = { ...minimized.value, [itemId]: value };

            return;
        }
    } catch (error) {
        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }
    }

    minimized.value = { ...minimized.value, [itemId]: before ?? !value };
}

// Refresh this Block only: it asks for its data to be fetched at the next tick and re-reads the results. Inside the window the control is
// `aria-disabled` and described by `msg:refresh-limited` until it ends.
async function onRefresh(itemId: string): Promise<void> {
    if (props.dashboard_id === null || refreshing.value.includes(itemId)) {
        return;
    }

    refreshing.value = [...refreshing.value, itemId];

    try {
        const outcome = await refreshItem(props.dashboard_id, itemId);

        if (outcome.status === 'limited') {
            limited.value = [...limited.value, itemId];
            setTimeout(() => {
                limited.value = limited.value.filter((id) => id !== itemId);
            }, outcome.retryAfter * 1000);
        } else if (outcome.status === 'requested') {
            polls = 0;
            await loadResults();
        }
    } catch (error) {
        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);
        }
    } finally {
        refreshing.value = refreshing.value.filter((id) => id !== itemId);
    }
}

const JUST_ADDED_MS = 3000;

function titleOf(itemId: string): HTMLElement | null {
    const cell = document.querySelector<HTMLElement>(
        `[data-item="${CSS.escape(itemId)}"]`,
    );

    return cell?.querySelector<HTMLElement>('h2') ?? null;
}

// "Show me": the view scrolls to the Block (smooth unless motion is reduced), focus moves to its title and the outline pulses; on a phone the sheet
// closes first.
async function showMe(itemId: string): Promise<void> {
    if (!wide.value) {
        hidePanel();
    }

    await nextTick();

    const cell = document.querySelector<HTMLElement>(
        `[data-item="${CSS.escape(itemId)}"]`,
    );
    const reduced =
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ===
        true;

    cell?.scrollIntoView({
        block: 'center',
        behavior: reduced ? 'auto' : 'smooth',
    });

    const title = titleOf(itemId);

    if (title !== null) {
        title.setAttribute('tabindex', '-1');
        title.focus({ preventScroll: true });
    }

    justAdded.value = [...justAdded.value, itemId];
    setTimeout(() => {
        justAdded.value = justAdded.value.filter((id) => id !== itemId);
    }, JUST_ADDED_MS);

    if (below.value?.itemId === itemId) {
        below.value = null;
    }
}

function neighbourOf(item: DashboardItem): string | null {
    const others = (dashboard.value?.items ?? []).filter(
        (other) => other.item_id !== item.item_id && other.block !== null,
    );
    let best: DashboardItem | null = null;
    let distance = Infinity;

    for (const other of others) {
        const d =
            Math.abs(other.geometry.row - item.geometry.row) +
            Math.abs(other.geometry.col - item.geometry.col);

        if (d < distance) {
            distance = d;
            best = other;
        }
    }

    return best?.block?.name ?? null;
}

function rollback(name: string): void {
    toasts.add({
        kind: 'rollback',
        message: t('toast-rollback', { block: name }),
    });
}

// Add a Block: it appears at once where the layout's next free slot is, and the server's answer replaces it (or it is taken away again with
// `msg:toast-rollback`). A repeated add is the same item. Existing Blocks never move.
async function onAdd(block: CatalogueBlock): Promise<void> {
    const current = dashboard.value;

    if (
        props.dashboard_id === null ||
        current === null ||
        busy.value.includes(block.block_id) ||
        current.items.some((item) => item.block_id === block.block_id)
    ) {
        return;
    }

    busy.value = [...busy.value, block.block_id];

    const temporary: DashboardItem = {
        item_id: `pending:${block.block_id}`,
        block_id: block.block_id,
        geometry: {
            ...current.next_free_slot,
            width: block.default_size.width,
            height: block.default_size.height,
        },
        minimized: false,
        state: 'ok',
        refresh_interval: block.refresh_interval,
        is_live: block.refresh_interval === 'live',
        block: {
            name: block.name,
            subtitle: block.description,
            type: block.type,
            icon: null,
            chrome: {
                show_header: true,
                show_subtitle: true,
                allow_resize: true,
                allow_minimize: false,
                allow_refresh: false,
                show_footer_action: false,
            },
            sizes: {
                min_width: 3,
                max_width: 12,
                width: block.default_size.width,
                min_height: 'small',
                max_height: 'large',
                height: block.default_size.height,
            },
            about: {
                description: block.description,
                source_name: block.data_source,
                refresh_interval: block.refresh_interval,
                version: block.version,
                period: null,
            },
        },
    };

    current.items.push(temporary);

    try {
        const outcome = await addBlock(
            props.dashboard_id,
            block.block_id,
            revision.value,
        );

        current.items = current.items.filter(
            (item) => item.item_id !== temporary.item_id,
        );

        if (!outcome.ok || outcome.item === null) {
            rollback(block.name);

            if (!outcome.ok && outcome.reason === 'conflict') {
                await load();
            }

            return;
        }

        revision.value = outcome.revision;
        current.revision = outcome.revision;

        if (outcome.nextFreeSlot !== null) {
            current.next_free_slot = outcome.nextFreeSlot;
        }

        const item = outcome.item;

        // A repeated add answers with the item that is already there: it is not shown twice.
        if (
            !current.items.some((existing) => existing.item_id === item.item_id)
        ) {
            current.items.push(item);
            minimized.value = {
                ...minimized.value,
                [item.item_id]: item.minimized,
            };
        }

        justAdded.value = [...justAdded.value, item.item_id];
        setTimeout(() => {
            justAdded.value = justAdded.value.filter(
                (id) => id !== item.item_id,
            );
        }, JUST_ADDED_MS);

        const entry: Entry = {
            id: ++entryId,
            kind: 'add',
            blockId: block.block_id,
            name: block.name,
            geometry: item.geometry,
            minimized: item.minimized,
            unavailable: false,
        };
        history.value.push(entry);

        await nextTick();
        void loadResults();

        const element = document.querySelector<HTMLElement>(
            `[data-item="${CSS.escape(item.item_id)}"]`,
        );
        const outOfView =
            element !== null &&
            element.getBoundingClientRect().top > window.innerHeight;
        const neighbour = neighbourOf(item);

        if (outOfView) {
            below.value = {
                itemId: item.item_id,
                count: (below.value?.count ?? 0) + 1,
            };
        }

        toasts.add({
            kind: 'action',
            message: t(
                outOfView ? 'toast-add-below.visible' : 'toast-add.visible',
                { block: block.name },
            ),
            announce:
                neighbour === null
                    ? addBlocksLabels.announceAdded(block.name)
                    : t(
                          outOfView
                              ? 'toast-add-below.announce'
                              : 'toast-add.announce',
                          {
                              block: block.name,
                              neighbour,
                              shortcut: 'Ctrl+Z',
                          },
                      ),
            actions: [
                {
                    label: outOfView
                        ? addBlocksLabels.showMeBelow
                        : addBlocksLabels.showMe,
                    run: () => void showMe(item.item_id),
                },
                {
                    label: addBlocksLabels.undo,
                    run: () => void undoEntry(entry),
                },
            ],
        });
    } catch (error) {
        current.items = current.items.filter(
            (item) => item.item_id !== temporary.item_id,
        );

        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        rollback(block.name);
    } finally {
        busy.value = busy.value.filter((id) => id !== block.block_id);
    }
}

// Remove a Block, from the panel, the ⋯ menu, an unavailable card or the add toast's Undo: it goes at once, with no confirmation, and comes back with
// `msg:toast-rollback` when the server refuses. Focus lands where the person was working: on the row (now "+ Add"), on the next Block's title, the
// previous one's, or "+ Add block".
async function onRemove(
    blockId: string,
    from: 'menu' | 'card' | 'panel' | 'toast',
    record = true,
): Promise<void> {
    const current = dashboard.value;

    if (
        props.dashboard_id === null ||
        current === null ||
        busy.value.includes(blockId)
    ) {
        return;
    }

    const index = current.items.findIndex((item) => item.block_id === blockId);

    if (index === -1) {
        return;
    }

    const item = current.items[index];
    const name = item.block?.name ?? addBlocksLabels.title;
    const sibling =
        current.items[index + 1] ?? current.items[index - 1] ?? null;

    busy.value = [...busy.value, blockId];
    current.items.splice(index, 1);

    try {
        const outcome = await removeBlock(
            props.dashboard_id,
            blockId,
            revision.value,
        );

        if (!outcome.ok) {
            current.items.splice(index, 0, item);

            if (outcome.reason === 'locked') {
                toasts.add({ kind: 'info', message: t('locked') });
            } else {
                rollback(name);

                if (outcome.reason === 'conflict') {
                    await load();
                }
            }

            return;
        }

        revision.value = outcome.revision;
        current.revision = outcome.revision;
        below.value = below.value?.itemId === item.item_id ? null : below.value;

        // The remove is remembered with where the Block was and whether it was minimized, so Undo can put it back exactly.
        const entry: Entry | null = record
            ? {
                  id: ++entryId,
                  kind: 'remove',
                  blockId,
                  name,
                  geometry: item.geometry,
                  minimized: minimized.value[item.item_id] ?? item.minimized,
                  unavailable: false,
              }
            : null;

        if (entry !== null) {
            history.value.push(entry);
        }

        toasts.add({
            kind: entry === null ? 'info' : 'action',
            message:
                entry === null
                    ? addBlocksLabels.removed(name)
                    : t('toast-remove.visible', { block: name }),
            announce:
                entry === null
                    ? addBlocksLabels.announceRemoved(name)
                    : t('toast-remove.announce', {
                          block: name,
                          shortcut: 'Ctrl+Z',
                      }),
            actions:
                entry === null
                    ? []
                    : [
                          {
                              label: addBlocksLabels.undo,
                              run: () => void undoEntry(entry),
                          },
                      ],
        });

        await nextTick();

        if (from === 'panel') {
            document
                .querySelector<HTMLElement>(
                    `[data-block="${CSS.escape(blockId)}"] [data-slot=row-add]`,
                )
                ?.focus();
        } else {
            const target = sibling === null ? null : titleOf(sibling.item_id);

            if (target !== null) {
                target.setAttribute('tabindex', '-1');
                target.focus();
            } else {
                document
                    .querySelector<HTMLElement>(
                        '[data-slot=add-block], [data-slot=add-block-empty]',
                    )
                    ?.focus();
            }
        }
    } catch (error) {
        current.items.splice(index, 0, item);

        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        rollback(name);
    } finally {
        busy.value = busy.value.filter((id) => id !== blockId);
    }
}

// Put a removed Block back: RestoreItem with its remembered geometry and minimized state. A stale revision reloads the layout and retries once; a
// refusal marks the entry unavailable and says so with `msg:toast-rollback`. Focus goes to the restored Block's title.
async function restoreEntry(entry: Entry): Promise<boolean> {
    const current = dashboard.value;

    if (
        props.dashboard_id === null ||
        current === null ||
        busy.value.includes(entry.blockId)
    ) {
        return false;
    }

    busy.value = [...busy.value, entry.blockId];

    try {
        const body = { geometry: entry.geometry, minimized: entry.minimized };
        let outcome = await restoreBlock(
            props.dashboard_id,
            entry.blockId,
            body,
            revision.value,
        );

        if (!outcome.ok && outcome.reason === 'conflict') {
            await load();
            outcome = await restoreBlock(
                props.dashboard_id,
                entry.blockId,
                body,
                revision.value,
            );
        }

        if (!outcome.ok || outcome.item === null) {
            entry.unavailable = true;
            rollback(entry.name);

            return false;
        }

        const restored = outcome.item;

        revision.value = outcome.revision;
        current.revision = outcome.revision;

        if (outcome.nextFreeSlot !== null) {
            current.next_free_slot = outcome.nextFreeSlot;
        }

        if (
            !current.items.some(
                (existing) => existing.item_id === restored.item_id,
            )
        ) {
            current.items.push(restored);
        }

        minimized.value = {
            ...minimized.value,
            [restored.item_id]: restored.minimized,
        };

        await nextTick();
        void loadResults();

        const title = titleOf(restored.item_id);

        if (title !== null) {
            title.setAttribute('tabindex', '-1');
            title.focus();
        }

        return true;
    } catch (error) {
        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return false;
        }

        entry.unavailable = true;
        rollback(entry.name);

        return false;
    } finally {
        busy.value = busy.value.filter((id) => id !== entry.blockId);
    }
}

// Reverse one entry: an add is removed again, a remove is restored. The reversal itself is not a new history entry.
async function undoEntry(entry: Entry): Promise<void> {
    history.value = history.value.filter(
        (candidate) => candidate.id !== entry.id,
    );

    if (entry.kind === 'add') {
        await onRemove(entry.blockId, 'toast', false);
    } else {
        await restoreEntry(entry);
    }
}

// Ctrl+Z (⌘Z) anywhere on the dashboard outside a text field: the last add or remove is reversed, again and again while the page is open. A text
// field keeps its own undo. It is a modified key, so it works with the single-key shortcuts turned off.
function onUndoKey(event: KeyboardEvent): void {
    if (
        !(event.ctrlKey || event.metaKey) ||
        event.altKey ||
        event.key.toLowerCase() !== 'z'
    ) {
        return;
    }

    // In Edit Layout Mode the keys step through the layout's own history: Ctrl+Z undoes, Ctrl+Shift+Z redoes.
    if (editing.value) {
        const t = event.target;

        if (
            t instanceof HTMLElement &&
            (t.isContentEditable ||
                ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName))
        ) {
            return;
        }

        event.preventDefault();

        if (event.shiftKey) {
            redoLayout();
        } else {
            undoLayout();
        }

        return;
    }

    if (event.shiftKey) {
        return;
    }

    const target = event.target;

    if (
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
    ) {
        return;
    }

    const entry = [...history.value]
        .reverse()
        .find((candidate) => !candidate.unavailable);

    if (entry === undefined) {
        return;
    }

    event.preventDefault();
    void undoEntry(entry);
}

function startEditing(): void {
    if (dashboard.value === null) {
        return;
    }

    working.value = layoutOf(dashboard.value.items);
    committed.value = { ...working.value };
    past.value = [];
    future.value = [];
    saveError.value = null;
    editing.value = true;
}

function limitsOf(itemId: string) {
    const sizes = dashboard.value?.items.find((i) => i.item_id === itemId)
        ?.block?.sizes;

    return sizes
        ? {
              minWidth: sizes.min_width,
              maxWidth: sizes.max_width,
              minHeight: sizes.min_height,
              maxHeight: sizes.max_height,
          }
        : NO_LIMITS;
}

// A drag or resize: the preview is the layout at the gesture's start with this one item placed and the rest settled around it; the gesture's end
// becomes one history step.
function onGeometry(itemId: string, geometry: Geometry, commit: boolean): void {
    const next = place(committed.value, itemId, geometry, limitsOf(itemId));

    working.value = next;

    if (!commit) {
        return;
    }

    if (!sameLayout(next, committed.value)) {
        past.value.push(committed.value);
        future.value = [];
        committed.value = next;
    }
}

function undoLayout(): void {
    const previous = past.value.pop();

    if (previous === undefined) {
        return;
    }

    future.value.push(committed.value);
    committed.value = previous;
    working.value = { ...previous };
}

function redoLayout(): void {
    const next = future.value.pop();

    if (next === undefined) {
        return;
    }

    past.value.push(committed.value);
    committed.value = next;
    working.value = { ...next };
}

const namesOf = (): Record<string, string> =>
    Object.fromEntries(
        (dashboard.value?.items ?? []).map((i) => [
            i.item_id,
            i.block?.name ?? 'Block',
        ]),
    );

// Pick a Block up with M or Space: its Move button is pressed, arrows move it, Shift with arrows resizes it, Enter drops it and Escape puts it back.
function pickUp(itemId: string): void {
    if (
        !editing.value ||
        picked.value !== null ||
        working.value[itemId] === undefined
    ) {
        return;
    }

    picked.value = { id: itemId, base: { ...committed.value } };
    escArmed = false;
    announce(labels.pickedUp(namesOf()[itemId] ?? 'Block'), 'polite');
}

function dropPicked(): void {
    const current = picked.value;

    if (current === null) {
        return;
    }

    if (!sameLayout(working.value, current.base)) {
        past.value.push(current.base);
        future.value = [];
        committed.value = { ...working.value };
    }

    picked.value = null;
    announce(
        describeMove(working.value, current.base, current.id, namesOf()),
        'polite',
    );
}

function cancelPicked(): void {
    const current = picked.value;

    if (current === null) {
        return;
    }

    working.value = { ...current.base };
    picked.value = null;
    announce(labels.cancelled(namesOf()[current.id] ?? 'Block'), 'polite');
}

function onMoveKey(itemId: string, event: KeyboardEvent): void {
    if (!editing.value) {
        return;
    }

    if (picked.value === null) {
        if (event.key === ' ') {
            event.preventDefault();
            pickUp(itemId);
        }

        return;
    }

    if (picked.value.id !== itemId) {
        return;
    }

    const directions: Record<string, 'left' | 'right' | 'up' | 'down'> = {
        ArrowLeft: 'left',
        ArrowRight: 'right',
        ArrowUp: 'up',
        ArrowDown: 'down',
    };
    const direction = directions[event.key];

    if (direction !== undefined) {
        event.preventDefault();

        const from = working.value[itemId];
        const next = event.shiftKey
            ? resizeStep(from, direction, limitsOf(itemId))
            : step(from, direction);

        working.value = place(
            picked.value.base,
            itemId,
            next,
            limitsOf(itemId),
        );

        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        dropPicked();
    } else if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        cancelPicked();
    }
}

// Esc in the mode: the first press only cancels a picked-up Block; with nothing picked up, one press arms and the next finishes and saves. One press
// never does both, and an open menu or dialog keeps its own Esc.
function onEscape(event: KeyboardEvent): void {
    if (!editing.value) {
        return;
    }

    if (event.key !== 'Escape') {
        escArmed = false;

        return;
    }

    if (document.querySelector('[role=menu], [role=dialog]') !== null) {
        return;
    }

    if (picked.value !== null) {
        event.preventDefault();
        cancelPicked();
        escArmed = false;

        return;
    }

    if (escArmed) {
        escArmed = false;
        event.preventDefault();
        void finishEditing();

        return;
    }

    escArmed = true;
    announce(labels.exitHint, 'polite');
}

// A menu step, in or outside Edit Layout Mode: Move up and Move down, Make wider and Make narrower, by one step with the Layout kernel's rules. On a
// single column it reorders (`SetCompactOrder`); on the desktop grid outside the mode it saves at once with the revision held.
async function onStep(
    itemId: string,
    action: 'up' | 'down' | 'wider' | 'narrower',
): Promise<void> {
    const current = dashboard.value;

    if (props.dashboard_id === null || current === null || saving.value) {
        return;
    }

    const name = namesOf()[itemId] ?? 'Block';

    if (!desktop.value) {
        const ids = (
            compactOrder.value ?? current.items.map((i) => i.item_id)
        ).filter((id) => current.items.some((i) => i.item_id === id));
        const from = ids.indexOf(itemId);
        const to =
            action === 'up' ? from - 1 : action === 'down' ? from + 1 : from;

        if (from === -1 || to < 0 || to >= ids.length || to === from) {
            return;
        }

        const next = [...ids];
        [next[from], next[to]] = [next[to], next[from]];

        try {
            const outcome = await saveCompactOrder(
                props.dashboard_id,
                next,
                revision.value,
            );

            if (outcome.ok) {
                dashboard.value = outcome.dashboard;
                revision.value = outcome.dashboard.revision;
                compactOrder.value = outcome.dashboard.compact_order ?? null;
                announce(labels.reordered(name, to + 1, next.length), 'polite');
                await nextTick();
                titleOf(itemId)?.focus();

                return;
            }

            if (outcome.reason === 'conflict') {
                await load();
            }

            rollback(name);
        } catch (error) {
            if (
                error instanceof DashboardError &&
                (error.status === 401 || error.status === 419)
            ) {
                window.location.assign(SIGN_IN_URL);
            }
        }

        return;
    }

    const base = editing.value ? committed.value : layoutOf(current.items);
    const from = base[itemId];

    if (from === undefined) {
        return;
    }

    const limits = limitsOf(itemId);
    const next =
        action === 'up'
            ? step(from, 'up')
            : action === 'down'
              ? step(from, 'down')
              : resizeStep(from, action === 'wider' ? 'right' : 'left', limits);
    const layout = place(base, itemId, next, limits);

    if (sameLayout(layout, base)) {
        return;
    }

    if (editing.value) {
        past.value.push(committed.value);
        future.value = [];
        committed.value = layout;
        working.value = { ...layout };
        announce(describeMove(layout, base, itemId, namesOf()), 'polite');

        return;
    }

    await persist(layout, describeMove(layout, base, itemId, namesOf()));
}

// Save a layout's changed items with the revision held, without entering the mode.
async function persist(layout: Layout, spoken: string): Promise<void> {
    const current = dashboard.value;

    if (props.dashboard_id === null || current === null) {
        return;
    }

    const saved = layoutOf(current.items);
    const changed = Object.entries(layout)
        .filter(
            ([id, g]) =>
                saved[id] !== undefined &&
                !sameLayout({ [id]: g }, { [id]: saved[id] }),
        )
        .map(([id, g]) => ({ item_id: id, ...g }));

    if (changed.length === 0) {
        return;
    }

    try {
        const outcome = await saveLayout(
            props.dashboard_id,
            changed,
            revision.value,
        );

        if (outcome.ok) {
            dashboard.value = outcome.dashboard;
            revision.value = outcome.dashboard.revision;
            announce(spoken, 'polite');

            return;
        }

        if (outcome.reason === 'conflict') {
            await load();
        }

        toasts.add({ kind: 'rollback', message: t('layout-save-failed') });
    } catch (error) {
        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);
        }
    }
}

// Done: the changed items go in one `MoveResizeItems` with the revision held. On success the server's settled layout replaces ours and the mode
// ends with focus back on Edit layout; on failure the mode stays open with `msg:layout-save-failed` and the local arrangement kept (a stale revision
// offers a reload instead of an overwrite).
async function finishEditing(): Promise<void> {
    const current = dashboard.value;

    if (props.dashboard_id === null || current === null || saving.value) {
        return;
    }

    const saved = layoutOf(current.items);
    const changed = Object.entries(committed.value)
        .filter(
            ([id, g]) =>
                saved[id] === undefined ||
                !sameLayout({ [id]: g }, { [id]: saved[id] }),
        )
        .map(([id, g]) => ({ item_id: id, ...g }));

    if (changed.length === 0) {
        editing.value = false;
        await nextTick();
        focusEditButton();

        return;
    }

    saving.value = true;
    saveError.value = null;

    try {
        const outcome = await saveLayout(
            props.dashboard_id,
            changed,
            revision.value,
        );

        if (outcome.ok) {
            dashboard.value = outcome.dashboard;
            revision.value = outcome.dashboard.revision;
            minimized.value = Object.fromEntries(
                outcome.dashboard.items.map((i) => [i.item_id, i.minimized]),
            );
            editing.value = false;
            announce(labels.layoutSaved);
            await nextTick();
            focusEditButton();

            return;
        }

        saveError.value = outcome.reason === 'conflict' ? 'conflict' : 'failed';
    } catch (error) {
        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        saveError.value = 'failed';
    } finally {
        saving.value = false;
    }
}

// Leave the mode on the server's layout, dropping the local arrangement.
async function reloadLayout(): Promise<void> {
    editing.value = false;
    saveError.value = null;
    await load();
    void loadResults();
}

// The free-space hint: where the next Block of that size would go, from the layout's own `next_free_slot`.
function showHint(block: CatalogueBlock | null): void {
    const current = dashboard.value;

    hint.value =
        block === null || current === null
            ? null
            : {
                  ...current.next_free_slot,
                  width: block.default_size.width,
                  height: block.default_size.height,
              };
}

const stops: Array<() => void> = [];

onMounted(async () => {
    document.addEventListener('keydown', onUndoKey);
    document.addEventListener('keydown', onEscape);
    document.addEventListener('pointerdown', () => (escArmed = false));

    // Single-key shortcuts (Story 4.12): `A` opens the Add-blocks Panel, `/` opens it with search focused and `M` picks up the focused Block's Move
    // button. They obey the Keyboard shortcuts switch; the buttons and menu items do the same with it off.
    stops.push(
        registerShortcut({
            id: 'dashboard-add',
            key: 'a',
            run: () => showPanel(),
        }),
        registerShortcut({
            id: 'dashboard-search',
            key: '/',
            run: (event) => {
                event.preventDefault();
                showPanel();
                void nextTick().then(() =>
                    document
                        .querySelector<HTMLElement>(
                            '[data-slot=add-blocks-search]',
                        )
                        ?.focus(),
                );
            },
        }),
        registerShortcut({
            id: 'dashboard-move',
            key: 'm',
            scope: () =>
                document.activeElement?.closest<HTMLElement>(
                    '[data-slot=move-button]',
                ) ?? null,
            run: () => {
                const id = document.activeElement
                    ?.closest<HTMLElement>('[data-item]')
                    ?.getAttribute('data-item');

                if (id) {
                    pickUp(id);
                }
            },
        }),
    );
    await load();

    if (state.value === 'ready' && items.value.length > 0) {
        await loadResults();
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onUndoKey);
    document.removeEventListener('keydown', onEscape);
    stops.forEach((stop) => stop());
    controller?.abort();
    resultsController?.abort();

    if (resultsTimer !== null) {
        clearTimeout(resultsTimer);
    }
});
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-0 flex-1">
        <div class="flex min-w-0 flex-1 flex-col gap-6 px-4 py-6 sm:px-7">
            <PageHeader :title="title">
                <div
                    v-if="editing"
                    class="flex flex-wrap items-center gap-2"
                    role="toolbar"
                    :aria-label="labels.editToolbar"
                    data-slot="edit-layout-toolbar"
                >
                    <span
                        class="type-caption hidden text-text-secondary lg:inline"
                        data-slot="edit-hint"
                        >{{ labels.editHint }}</span
                    >
                    <Button
                        type="button"
                        variant="secondary"
                        :aria-disabled="canUndoLayout ? undefined : 'true'"
                        data-slot="edit-undo"
                        @click="undoLayout"
                    >
                        {{ labels.editUndo }}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        :aria-disabled="canRedoLayout ? undefined : 'true'"
                        data-slot="edit-redo"
                        @click="redoLayout"
                    >
                        {{ labels.editRedo }}
                    </Button>
                    <Button
                        type="button"
                        :aria-busy="saving ? 'true' : undefined"
                        data-slot="edit-done"
                        @click="finishEditing"
                    >
                        {{ labels.editDone }}
                    </Button>
                </div>
                <Button
                    v-else
                    type="button"
                    data-slot="add-block"
                    :aria-expanded="panelOpen"
                    @click="showPanel"
                >
                    {{ labels.addBlock }}
                </Button>
                <div v-if="!editing" class="hidden items-center gap-2 md:flex">
                    <Button
                        type="button"
                        variant="secondary"
                        aria-disabled="true"
                        data-slot="dashboard-switcher"
                    >
                        {{ labels.switcher }}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        aria-disabled="true"
                        data-slot="date-range"
                    >
                        {{ labels.dateRange }}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        :aria-disabled="items.length === 0 ? 'true' : undefined"
                        data-slot="edit-layout"
                        @click="items.length > 0 && startEditing()"
                    >
                        {{ labels.editLayout }}
                    </Button>
                </div>
            </PageHeader>

            <div
                v-if="saveError !== null"
                role="alert"
                class="flex flex-wrap items-center gap-3 rounded-lg border border-error bg-error-soft p-3 text-error-text"
                data-slot="layout-save-failed"
            >
                <p class="type-body-sm">{{ t('layout-save-failed') }}</p>
                <Button
                    v-if="saveError === 'conflict'"
                    type="button"
                    variant="secondary"
                    size="sm"
                    data-slot="layout-reload"
                    @click="reloadLayout"
                >
                    {{ labels.reloadLayout }}
                </Button>
                <Button
                    v-else
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="finishEditing"
                >
                    {{ labels.retry }}
                </Button>
            </div>

            <div
                v-if="state === 'loading'"
                role="status"
                aria-busy="true"
                class="grid gap-[14px] md:grid-cols-4"
                data-slot="dashboard-loading"
            >
                <span class="sr-only">{{ labels.loading }}</span>
                <Skeleton
                    v-for="n in 4"
                    :key="n"
                    class="h-[134px] rounded-lg"
                />
            </div>

            <div
                v-else-if="state === 'error'"
                role="alert"
                class="flex flex-col items-start gap-3 rounded-lg border border-border-default bg-surface-card p-4"
                data-slot="dashboard-error"
            >
                <p class="type-body text-text-primary">
                    {{ t('dashboard-load-failed') }}
                </p>
                <Button type="button" variant="secondary" @click="load">
                    {{ labels.retry }}
                </Button>
            </div>

            <div
                v-else-if="items.length === 0"
                class="flex flex-col items-start gap-3 rounded-lg border border-border-default bg-surface-card p-6"
                data-slot="dashboard-empty"
            >
                <p class="type-body text-text-primary">
                    {{ t('dashboard-empty') }}
                </p>
                <Button
                    type="button"
                    variant="secondary"
                    data-slot="add-block-empty"
                    @click="showPanel"
                >
                    {{ labels.addBlock }}
                </Button>
            </div>

            <DashboardGrid
                v-else
                :items="items"
                :results="results"
                :minimized="minimized"
                :refreshing="refreshing"
                :limited="limited"
                :admin="admin"
                :profile="profile"
                :just-added="justAdded"
                :hint="hint"
                :editing="editing"
                :picked-up="picked?.id ?? null"
                :compact="!desktop"
                :order="compactOrder"
                @movekey="onMoveKey"
                @step="onStep"
                @geometry="onGeometry"
                @retry="loadResults"
                @refresh="onRefresh"
                @minimize="onMinimize"
                @remove="(blockId, from) => onRemove(blockId, from)"
            />

            <button
                v-if="below"
                type="button"
                class="type-caption fixed bottom-6 left-1/2 z-40 -translate-x-1/2 rounded-full border border-accent bg-accent px-4 py-1.5 font-semibold text-on-accent shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                data-slot="edge-pill"
                @click="showMe(below.itemId)"
            >
                {{ t('edge-pill', { count: below.count }, below.count) }}
            </button>
        </div>

        <aside
            v-if="panelOpen && wide"
            :aria-label="addBlocksLabels.panelName"
            class="sticky top-0 hidden h-screen w-[392px] shrink-0 border-l border-border-default lg:block"
            data-slot="add-blocks-drawer"
        >
            <AddBlocksPanel
                :added-ids="addedIds"
                :busy-ids="busy"
                :can-add="dashboard_id !== null && state === 'ready'"
                @close="hidePanel"
                @skip="skipToDashboard"
                @add="onAdd"
                @remove="(block) => onRemove(block.block_id, 'panel')"
                @hint="showHint"
            />
        </aside>

        <Sheet
            v-if="!wide"
            :open="panelOpen"
            @update:open="(v) => (v ? showPanel() : hidePanel())"
        >
            <SheetContent
                side="right"
                class="w-full max-w-[392px] p-0"
                data-slot="add-blocks-sheet"
            >
                <SheetTitle class="sr-only">{{
                    addBlocksLabels.title
                }}</SheetTitle>
                <SheetDescription class="sr-only">{{
                    addBlocksLabels.subtitle
                }}</SheetDescription>
                <AddBlocksPanel
                    :added-ids="addedIds"
                    :busy-ids="busy"
                    :can-add="dashboard_id !== null && state === 'ready'"
                    @close="hidePanel"
                    @skip="skipToDashboard"
                    @add="onAdd"
                    @remove="(block) => onRemove(block.block_id, 'panel')"
                    @hint="showHint"
                />
            </SheetContent>
        </Sheet>
    </div>
</template>
