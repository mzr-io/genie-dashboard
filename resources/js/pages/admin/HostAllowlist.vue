<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
} from 'vue';
import { useI18n } from 'vue-i18n';
import AddHostButton from '@/components/AddHostButton.vue';
import AddHostForm from '@/components/AddHostForm.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import ListStates from '@/components/ListStates.vue';
import PageHeader from '@/components/PageHeader.vue';
import Tag from '@/components/Tag.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { announce, announceDebounced } from '@/lib/announce';
import { formatDateTime } from '@/lib/format';
import {
    fetchDependents,
    fetchHostAllowlist,
    HostAllowlistError,
    removeHost,
} from '@/lib/hostAllowlist';
import type {
    AddedHost,
    DependentSource,
    HostAllowlistMeta,
    HostAllowlistPage,
    HostEntry,
    HostSortKey,
} from '@/lib/hostAllowlist';
import { SIGN_IN_URL } from '@/lib/session';
import { hostAllowlistLabels as labels } from '@/locales/labels';
import { index as settingsIndex } from '@/routes/admin/settings';
import { useToasts } from '@/stores/toasts';

// System settings > Host allowlist (Story 2.1; UX-DR-115, 262, 263, 23): a data table of the hosts the workspace may
// call, with sortable headers, search and the generic list states (5 skeleton rows, a failure row with Retry,
// `list-empty` with "+ Add host", `list-no-match` with Clear search). Adding is an inline form (never a modal);
// removing asks an alertdialog (focus on Cancel, the destructive button repeats the host) that lists the Data Sources
// the removal would block. Every change carries the list's revision: a stale one shows the fresh list and keeps what
// was typed. Plain http rows carry a persistent "Not encrypted" cue.
const { t } = useI18n();
const toasts = useToasts();
const SEARCH_DEBOUNCE_MS = 300;

type Pending = { kind: 'count' } | { kind: 'sort'; text: string } | null;

const search = ref('');
const appliedSearch = ref('');
const sortKey = ref<HostSortKey>('host');
const direction = ref<'asc' | 'desc'>('asc');

const entries = ref<HostEntry[]>([]);
const meta = ref<HostAllowlistMeta | null>(null);
const state = ref<'loading' | 'error' | 'forbidden' | 'ready'>('loading');
const refreshing = ref(false);
const searchId = useId();
const addId = useId();
const adding = ref(false);
const highlighted = ref<string | null>(null);

// Removal: the entry awaiting confirmation, what removing it would block and the control that opened the dialog.
const removing = ref<HostEntry | null>(null);
const removeOpen = ref(false);
const removeInvoker = ref<HTMLElement | null>(null);
const dependents = ref<DependentSource[] | null>(null);
const busy = ref<string | null>(null);

let controller: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | null = null;
let pending: Pending = null;

const total = computed(() => meta.value?.total ?? 0);
const matched = computed(() => meta.value?.matched ?? 0);
const revision = computed(() => meta.value?.revision ?? 0);
const searching = computed(() => appliedSearch.value.trim() !== '');
const isEmpty = computed(() => state.value === 'ready' && total.value === 0);
const showToolbar = computed(
    () => !isEmpty.value && state.value !== 'forbidden',
);

const columns: DataTableColumn[] = [
    {
        key: 'host',
        label: labels.columns.host,
        sortable: true,
        rowHeader: true,
    },
    { key: 'scheme', label: labels.columns.scheme, sortable: true },
    {
        key: 'port',
        label: labels.columns.port,
        sortable: true,
        class: 'tabular-nums',
    },
    { key: 'added_by', label: labels.columns.added_by },
    {
        key: 'added',
        label: labels.columns.added,
        sortable: true,
        class: 'tabular-nums',
    },
    { key: 'actions', label: labels.actions },
];

function fail(error: unknown): void {
    if (
        error instanceof HostAllowlistError &&
        (error.status === 401 || error.status === 419)
    ) {
        window.location.assign(SIGN_IN_URL);

        return;
    }

    pending = null;
    state.value =
        error instanceof HostAllowlistError && error.status === 403
            ? 'forbidden'
            : 'error';
    refreshing.value = false;
}

// The server's list replaces the page's copy.
function apply(page: HostAllowlistPage): void {
    entries.value = page.data;
    meta.value = page.meta;
    state.value = 'ready';
    refreshing.value = false;
}

// The server's `current` list of a 409 is always the default query (host ascending, no search): the page takes that
// query too, so headers, `aria-sort` and the toolbar match the rows, and no load still in flight can overwrite it.
function showCurrent(page: HostAllowlistPage): void {
    controller?.abort();
    pending = null;
    sortKey.value = 'host';
    direction.value = 'asc';
    search.value = '';
    appliedSearch.value = '';
    highlighted.value = null;
    apply(page);
}

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const mine = controller;

    if (meta.value === null || state.value !== 'ready') {
        state.value = 'loading';
    } else {
        refreshing.value = true;
    }

    try {
        const result = await fetchHostAllowlist(
            {
                q: appliedSearch.value,
                sort: sortKey.value,
                direction: direction.value,
            },
            mine.signal,
        );

        if (mine.signal.aborted) {
            return;
        }

        apply(result);

        const done = pending;
        pending = null;

        if (done?.kind === 'count') {
            announceDebounced(
                'host-allowlist-count',
                labels.count(result.meta.matched, result.meta.total),
            );
        } else if (done?.kind === 'sort') {
            announce(done.text);
        }
    } catch (error) {
        if (mine.signal.aborted) {
            return;
        }

        fail(error);
    }
}

function applySearch(): void {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }

    if (search.value.trim() === appliedSearch.value.trim()) {
        return;
    }

    appliedSearch.value = search.value;
    highlighted.value = null;
    pending = { kind: 'count' };
    void load();
}

function onSearchInput(): void {
    if (timer !== null) {
        clearTimeout(timer);
    }

    timer = setTimeout(applySearch, SEARCH_DEBOUNCE_MS);
}

function clearSearch(): void {
    search.value = '';
    applySearch();
    document.getElementById(searchId)?.focus();
}

function sortBy(key: string): void {
    const next = key as HostSortKey;

    if (sortKey.value === next) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = next;
        direction.value = 'asc';
    }

    highlighted.value = null;
    pending = {
        kind: 'sort',
        text: labels.sorted(labels.columns[next], direction.value === 'desc'),
    };
    void load();
}

function clearHighlight(key: string): void {
    if (highlighted.value === key) {
        highlighted.value = null;
    }
}

function rowElement(key: string): HTMLElement | null {
    return document.querySelector<HTMLElement>(`[data-row-key="${key}"]`);
}

// ---- Add -------------------------------------------------------------------------------------------------------

function toggleAdd(): void {
    adding.value = !adding.value;
}

function closeAdd(): void {
    adding.value = false;
    void nextTick(() =>
        document.querySelector<HTMLElement>('[data-test="add-host"]')?.focus(),
    );
}

// The host is on the list: show the list afresh (no search) and highlight and focus the new row.
async function added(result: AddedHost): Promise<void> {
    announce(`${t('saved')} ${labels.added(result.entry.host)}`, 'polite');
    adding.value = false;
    appliedSearch.value = '';
    search.value = '';
    highlighted.value = null;
    pending = null;
    await load();
    await nextTick();

    const key = result.entry.entry_id;

    // Highlight and focus only a row that is still listed after the refresh.
    if (state.value === 'ready' && rowElement(key) !== null) {
        highlighted.value = key;
        await nextTick();
        rowElement(key)?.focus();
    }
}

// Someone else changed the list: the fresh list is shown, the form keeps what was typed.
function stale(current: HostAllowlistPage): void {
    showCurrent(current);
    announce(labels.conflict, 'polite');
}

// ---- Remove ----------------------------------------------------------------------------------------------------

async function askRemove(entry: HostEntry, event: Event): Promise<void> {
    if (busy.value !== null) {
        return;
    }

    busy.value = entry.entry_id;
    removeInvoker.value =
        event.currentTarget instanceof HTMLElement ? event.currentTarget : null;

    try {
        dependents.value = await fetchDependents(entry.entry_id);
    } catch (error) {
        if (
            error instanceof HostAllowlistError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        if (error instanceof HostAllowlistError && error.status === 404) {
            busy.value = null;
            gone();

            return;
        }

        // The dialog still opens: it says the check failed rather than claiming nothing depends on the host.
        dependents.value = null;
    }

    removing.value = entry;
    removeOpen.value = true;
    busy.value = null;
}

const removeDescription = computed(() => {
    const entry = removing.value;

    if (entry === null) {
        return '';
    }

    const impact = labels.removeImpact(entry.host);

    if (dependents.value === null) {
        return `${impact} ${labels.removeDependentsFailed}`;
    }

    return dependents.value.length === 0
        ? `${impact} ${labels.removeNoDependents}`
        : `${impact} ${labels.removeDependents(dependents.value.map((source) => source.name))}`;
});

async function confirmRemove(): Promise<void> {
    const entry = removing.value;

    if (entry === null) {
        return;
    }

    try {
        const next = await removeHost(entry.entry_id, revision.value);

        if (meta.value) {
            meta.value = { ...meta.value, revision: next };
        }

        announce(`${t('saved')} ${labels.removed(entry.host)}`, 'polite');
        highlighted.value = null;
        pending = null;
        await load();
        await nextTick();
        document.querySelector<HTMLElement>('[data-test="add-host"]')?.focus();
    } catch (error) {
        if (error instanceof HostAllowlistError) {
            if (error.status === 401 || error.status === 419) {
                window.location.assign(SIGN_IN_URL);

                return;
            }

            if (error.status === 409 && error.current) {
                showCurrent(error.current);
                toasts.add({ kind: 'error', message: labels.conflictRemove });

                return;
            }

            if (error.status === 404) {
                gone();

                return;
            }

            toasts.add({
                kind: 'error',
                message:
                    error.status === 429
                        ? t('throttled')
                        : t('save-failed.form'),
            });

            return;
        }

        toasts.add({ kind: 'error', message: t('save-failed.form') });
    }
}

function gone(): void {
    toasts.add({ kind: 'error', message: labels.gone });
    void load();
}

onMounted(() => {
    void load();
});

onBeforeUnmount(() => {
    controller?.abort();

    if (timer !== null) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <Head :title="labels.pageTitle" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader :title="labels.pageTitle" :subtitle="labels.pageSubtitle">
            <Link
                :href="settingsIndex().url"
                class="type-body-sm text-text-secondary underline underline-offset-4 hover:text-text-primary"
                data-test="back-to-settings"
                >{{ labels.back }}</Link
            >
            <AddHostButton
                v-if="!isEmpty && state === 'ready'"
                :expanded="adding"
                :controls="addId"
                @open="toggleAdd"
            />
        </PageHeader>

        <div v-if="adding" :id="addId">
            <AddHostForm
                :revision="revision"
                @added="added"
                @stale="stale"
                @close="closeAdd"
            />
        </div>

        <div
            v-if="showToolbar"
            role="search"
            :aria-label="labels.toolbar"
            data-slot="toolbar"
            class="flex flex-wrap items-center gap-3"
        >
            <label :for="searchId" class="sr-only">{{ labels.search }}</label>
            <Input
                :id="searchId"
                v-model="search"
                type="search"
                maxlength="100"
                autocomplete="off"
                class="max-w-xs"
                :placeholder="labels.searchPlaceholder"
                data-test="search"
                @input="onSearchInput"
                @keydown.enter.prevent="applySearch"
            />
            <p
                v-if="searching && meta"
                class="type-caption text-text-muted tabular-nums"
                data-test="count"
            >
                {{ labels.count(matched, total) }}
            </p>
        </div>

        <ListStates
            v-if="state === 'loading'"
            state="loading"
            :items="labels.items"
            :action="labels.action"
            :toolbar="false"
        />

        <p
            v-else-if="state === 'forbidden'"
            role="alert"
            data-slot="perm-denied"
            class="type-body text-text-secondary"
        >
            {{ t('perm-denied') }}
        </p>

        <ListStates
            v-else-if="state === 'error'"
            state="error"
            :items="labels.items"
            :action="labels.action"
            @retry="load"
        />

        <ListStates
            v-else-if="isEmpty"
            state="empty"
            :items="labels.items"
            :action="labels.action"
        >
            <AddHostButton
                :expanded="adding"
                :controls="addId"
                @open="toggleAdd"
            />
        </ListStates>

        <section
            v-else-if="searching && matched === 0"
            data-slot="list-no-match"
            class="flex flex-col items-center gap-4 rounded-lg border border-border-default bg-surface-card p-6"
        >
            <p class="type-body-md text-center text-text-secondary">
                {{
                    t('list-no-match', {
                        items: labels.items,
                        query: appliedSearch.trim(),
                    })
                }}
            </p>
            <Button
                type="button"
                variant="secondary"
                data-test="clear-search"
                @click="clearSearch"
            >
                {{ labels.clearSearch }}
            </Button>
        </section>

        <DataTable
            v-else
            :caption="labels.caption"
            :label="labels.tableRegion"
            :columns="columns"
            :rows="entries"
            :row-key="(entry: HostEntry) => entry.entry_id"
            :sort-key="sortKey"
            :sort-direction="direction"
            :busy="refreshing"
            :highlighted="highlighted"
            @row-blur="clearHighlight"
            @sort="sortBy"
        >
            <template #cell-scheme="{ row }">
                <span class="inline-flex flex-wrap items-center gap-2">
                    <span>{{ row.scheme }}</span>
                    <Tag
                        v-if="row.scheme === 'http'"
                        data-test="not-encrypted"
                        :title="labels.notEncryptedNote"
                        >{{ labels.notEncrypted }}</Tag
                    >
                </span>
            </template>
            <template #cell-added_by="{ row }">
                {{ row.added_by ?? labels.unknownMember }}
            </template>
            <template #cell-added="{ row }">
                <time :datetime="row.added_at">{{
                    formatDateTime(row.added_at)
                }}</time>
            </template>
            <template #cell-actions="{ row }">
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    :aria-label="labels.removeFor(row.host)"
                    :aria-disabled="busy === row.entry_id ? 'true' : undefined"
                    :data-entry="row.entry_id"
                    data-test="remove-host"
                    @click="askRemove(row, $event)"
                >
                    {{ labels.remove }}
                </Button>
            </template>
        </DataTable>

        <ConfirmDialog
            v-if="removing"
            v-model:open="removeOpen"
            :title="labels.removeTitle(removing.host)"
            :description="removeDescription"
            :object-name="removing.host"
            :verb="labels.removeVerb"
            :invoker="removeInvoker"
            :fallback="() => (removing ? rowElement(removing.entry_id) : null)"
            @confirm="confirmRemove"
        />
    </div>
</template>
