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
import DataSourceHealth from '@/components/DataSourceHealth.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import ListStates from '@/components/ListStates.vue';
import PageHeader from '@/components/PageHeader.vue';
import RegisterDataSourceButton from '@/components/RegisterDataSourceButton.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { announce, announceDebounced } from '@/lib/announce';
import { DataSourceError, fetchDataSources } from '@/lib/dataSources';
import type {
    DataSource,
    DataSourceList,
    DataSourceListMeta,
    DataSourceSortKey,
} from '@/lib/dataSources';
import { formatDateTime } from '@/lib/format';
import { SIGN_IN_URL } from '@/lib/session';
import { dataSourceLabels as labels } from '@/locales/labels';
import { edit } from '@/routes/admin/data-sources';

// Data sources (Story 2.3; UX-DR-207, 261, 263, 115, 282): a data table of the APIs the workspace reads, with sortable
// headers, search, a count caption and the generic list states (5 skeleton rows, a failure row with Retry,
// `list-empty` with the action, `list-no-match` with Clear search). The register button is the only primary
// button. Health (a dot and its word, Story 2.18) and the last successful call come from the server; Blocks using it is a placeholder until Epic 3.
// After a save the form returns here with `?created=<id>` (or `?updated=<id>`): that row is highlighted and focused.
const { t } = useI18n();
const SEARCH_DEBOUNCE_MS = 300;

type Pending = { kind: 'count' } | { kind: 'sort'; text: string } | null;

const search = ref('');
const appliedSearch = ref('');
const sortKey = ref<DataSourceSortKey>('name');
const direction = ref<'asc' | 'desc'>('asc');

const sources = ref<DataSource[]>([]);
const meta = ref<DataSourceListMeta | null>(null);
const state = ref<'loading' | 'error' | 'forbidden' | 'ready'>('loading');
const refreshing = ref(false);
const searchId = useId();
const highlighted = ref<string | null>(null);

let controller: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | null = null;
let pending: Pending = null;

const total = computed(() => meta.value?.total ?? 0);
const matched = computed(() => meta.value?.matched ?? 0);
const searching = computed(() => appliedSearch.value.trim() !== '');
const isEmpty = computed(() => state.value === 'ready' && total.value === 0);
const showToolbar = computed(
    () => !isEmpty.value && state.value !== 'forbidden',
);

const columns: DataTableColumn[] = [
    {
        key: 'name',
        label: labels.columns.name,
        sortable: true,
        rowHeader: true,
    },
    { key: 'host', label: labels.columns.host, sortable: true },
    { key: 'auth_type', label: labels.columns.auth_type, sortable: true },
    { key: 'health', label: labels.columns.health },
    { key: 'last_success', label: labels.columns.last_success },
    {
        key: 'blocks',
        label: labels.columns.blocks,
        class: 'tabular-nums',
    },
];

function fail(error: unknown): void {
    if (
        error instanceof DataSourceError &&
        (error.status === 401 || error.status === 419)
    ) {
        window.location.assign(SIGN_IN_URL);

        return;
    }

    pending = null;
    state.value =
        error instanceof DataSourceError && error.status === 403
            ? 'forbidden'
            : 'error';
    refreshing.value = false;
}

function apply(list: DataSourceList): void {
    sources.value = list.data;
    meta.value = list.meta;
    state.value = 'ready';
    refreshing.value = false;
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
        const result = await fetchDataSources(
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
                'data-sources-count',
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
    const next = key as DataSourceSortKey;

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
    // Compared as text, never built into a selector: the key comes from the address bar.
    return (
        Array.from(
            document.querySelectorAll<HTMLElement>('[data-row-key]'),
        ).find((row) => row.dataset.rowKey === key) ?? null
    );
}

// Not the standard port of its scheme: shown after the host.
function hostLabel(source: DataSource): string {
    const standard = source.scheme === 'https' ? 443 : 80;

    return source.port === standard
        ? source.host
        : `${source.host}:${source.port}`;
}

// The form sends the person back with the row's id: highlight and focus it, announce the save, drop the query.
async function showSaved(): Promise<void> {
    const params = new URLSearchParams(window.location.search);
    const id = params.get('created') ?? params.get('updated');

    if (id === null) {
        return;
    }

    window.history.replaceState(
        window.history.state,
        '',
        window.location.pathname,
    );

    await nextTick();

    if (state.value === 'ready' && rowElement(id) !== null) {
        highlighted.value = id;
        announce(t('saved'), 'polite');
        await nextTick();
        rowElement(id)?.focus();
    }
}

onMounted(async () => {
    await load();
    await showSaved();
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
            <RegisterDataSourceButton v-if="!isEmpty && state === 'ready'" />
        </PageHeader>

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
            <RegisterDataSourceButton />
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
            :rows="sources"
            :row-key="(source: DataSource) => source.data_source_id"
            :sort-key="sortKey"
            :sort-direction="direction"
            :busy="refreshing"
            :highlighted="highlighted"
            @row-blur="clearHighlight"
            @sort="sortBy"
        >
            <template #cell-name="{ row }">
                <Link
                    :href="edit(row.data_source_id).url"
                    :aria-label="labels.edit(row.name)"
                    class="font-medium text-text-primary underline underline-offset-4 hover:text-accent-ink-strong"
                    data-test="edit-link"
                    >{{ row.name }}</Link
                >
            </template>
            <template #cell-host="{ row }">
                <span class="inline-flex flex-wrap items-center gap-2">
                    <span>{{ hostLabel(row) }}</span>
                    <Badge
                        v-if="row.scheme === 'http'"
                        data-test="not-encrypted"
                        :title="labels.notEncryptedNote"
                        >{{ labels.notEncrypted }}</Badge
                    >
                </span>
            </template>
            <template #cell-auth_type="{ row }">
                {{ labels.authTypes[row.auth_type] ?? row.auth_type }}
            </template>
            <template #cell-health="{ row }">
                <DataSourceHealth :status="row.health" />
            </template>
            <template #cell-last_success="{ row }">
                <span data-test="last-success">{{
                    row.last_successful_call_at
                        ? labels.lastSuccess(
                              formatDateTime(row.last_successful_call_at),
                          )
                        : labels.noCall
                }}</span>
            </template>
            <template #cell-blocks="{ row }">
                <span data-test="blocks-using">{{ row.blocks_using }}</span>
            </template>
        </DataTable>
    </div>
</template>
