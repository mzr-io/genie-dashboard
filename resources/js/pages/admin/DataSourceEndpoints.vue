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
import { useShell } from '@/composables/useShell';
import AddEndpointButton from '@/components/AddEndpointButton.vue';
import DataSourceTabs from '@/components/DataSourceTabs.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import EndpointForm from '@/components/EndpointForm.vue';
import EndpointTestPanel from '@/components/EndpointTestPanel.vue';
import ListStates from '@/components/ListStates.vue';
import PageHeader from '@/components/PageHeader.vue';
import Tag from '@/components/Tag.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { announce, announceDebounced } from '@/lib/announce';
import { DataSourceError, fetchDataSource } from '@/lib/dataSources';
import { EndpointError, fetchEndpoints } from '@/lib/endpoints';
import type { Endpoint, EndpointList } from '@/lib/endpoints';
import { formatDateTime } from '@/lib/format';
import { SIGN_IN_URL } from '@/lib/session';
import { endpointLabels as labels } from '@/locales/labels';
import { index } from '@/routes/admin/data-sources';

// The Endpoints tab of a Data source (Story 2.9; UX-DR-134, 27, 28, 23, 26, 37, 22, 282): a data table of the requests Blocks
// can select, with search, a count caption and the generic list states (5 skeleton rows, a failure row with Retry,
// `list-empty` with the action, `list-no-match` with Clear search). "+ Add endpoint" is the page's one primary button
// (the form takes the page over while it is open and brings its own Save). A save returns to the list, announces
// `saved` politely, and highlights and focuses the saved row. Nothing here sends a request to the data source.
const props = defineProps<{ dataSourceId: string }>();

const { t } = useI18n();
const SEARCH_DEBOUNCE_MS = 300;

type State = 'loading' | 'error' | 'forbidden' | 'missing' | 'ready';

const state = ref<State>('loading');
const sourceName = ref<string | null>(null);
const endpoints = ref<Endpoint[]>([]);
const meta = ref<EndpointList['meta'] | null>(null);
const search = ref('');
const appliedSearch = ref('');
const refreshing = ref(false);
const searchId = useId();
const highlighted = ref<string | null>(null);
// The form: closed, adding, or editing one Endpoint; or the test panel of one Endpoint (Story 2.10).
const mode = ref<
    'list' | 'add' | { edit: Endpoint } | { test: Endpoint; asUser?: boolean }
>('list');
const { can } = useShell();
const mayPreview = computed(() => can.value['data.preview_as_user'] === true);

let controller: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | null = null;
let announceCount = false;

const total = computed(() => meta.value?.total ?? 0);
const matched = computed(() => meta.value?.matched ?? 0);
const searching = computed(() => appliedSearch.value.trim() !== '');
const isEmpty = computed(() => state.value === 'ready' && total.value === 0);
const editing = computed(() =>
    typeof mode.value === 'object' && 'edit' in mode.value
        ? mode.value.edit
        : null,
);
const testing = computed(() =>
    typeof mode.value === 'object' && 'test' in mode.value
        ? mode.value.test
        : null,
);
const testingAsUser = computed(
    () =>
        typeof mode.value === 'object' &&
        'test' in mode.value &&
        mode.value.asUser === true,
);
const formOpen = computed(() => mode.value !== 'list');
const showToolbar = computed(
    () => !isEmpty.value && state.value === 'ready' && !formOpen.value,
);

const columns: DataTableColumn[] = [
    { key: 'method', label: labels.columns.method },
    { key: 'path', label: labels.columns.path, rowHeader: true },
    { key: 'revision', label: labels.columns.revision, class: 'tabular-nums' },
    { key: 'data', label: labels.dataColumn },
    { key: 'updated', label: labels.columns.updated },
    { key: 'actions', label: labels.testActions },
];

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

function fail(error: unknown): void {
    if (leaveForSignIn(error)) {
        return;
    }

    const status =
        error instanceof EndpointError || error instanceof DataSourceError
            ? error.status
            : 0;

    state.value =
        status === 403 ? 'forbidden' : status === 404 ? 'missing' : 'error';
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
        // The Data source's name for the subtitle; the list is what the page is for.
        const [list, source] = await Promise.all([
            fetchEndpoints(
                props.dataSourceId,
                appliedSearch.value,
                mine.signal,
            ),
            sourceName.value === null
                ? fetchDataSource(props.dataSourceId, mine.signal)
                : Promise.resolve(null),
        ]);

        if (mine.signal.aborted) {
            return;
        }

        if (source) {
            sourceName.value = source.data.name;
        }

        endpoints.value = list.data;
        meta.value = list.meta;
        state.value = 'ready';
        refreshing.value = false;

        if (announceCount) {
            announceCount = false;
            announceDebounced(
                'endpoints-count',
                labels.count(list.meta.matched, list.meta.total),
            );
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
    announceCount = true;
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

function clearHighlight(key: string): void {
    if (highlighted.value === key) {
        highlighted.value = null;
    }
}

function rowElement(key: string): HTMLElement | null {
    // Compared as text, never built into a selector.
    return (
        Array.from(
            document.querySelectorAll<HTMLElement>('[data-row-key]'),
        ).find((row) => row.dataset.rowKey === key) ?? null
    );
}

function openAdd(): void {
    highlighted.value = null;
    mode.value = 'add';
    void focusForm();
}

function openEdit(endpoint: Endpoint): void {
    highlighted.value = null;
    mode.value = { edit: endpoint };
    void focusForm();
}

function openTest(endpoint: Endpoint, asUser = false): void {
    // Fetch as user is `aria-disabled` without the permission: the click does nothing (the server refuses it too).
    if (asUser && !mayPreview.value) {
        return;
    }

    highlighted.value = null;
    mode.value = { test: endpoint, asUser };
    void nextTick(() =>
        document
            .querySelector<HTMLElement>('[data-test="test-title"]')
            ?.focus(),
    );
}

// The Endpoint is gone (a 404 on the test): back to the list, reloaded.
async function onTestGone(): Promise<void> {
    mode.value = 'list';
    await load();
}

async function closeTest(focusKey: string): Promise<void> {
    mode.value = 'list';
    await nextTick();
    document
        .querySelector<HTMLElement>(
            `[data-test-focus="${CSS.escape(focusKey)}"]`,
        )
        ?.focus();
}

async function focusForm(): Promise<void> {
    await nextTick();
    document.querySelector<HTMLElement>('[data-test="path"]')?.focus();
}

async function closeForm(focusKey: string | null = null): Promise<void> {
    mode.value = 'list';
    await nextTick();

    if (focusKey !== null) {
        rowElement(focusKey)?.focus();
    } else {
        document
            .querySelector<HTMLElement>('[data-test="add-endpoint"]')
            ?.focus();
    }
}

// The form saved: back to the list, which is reloaded; the saved row is highlighted and focused, and the save is announced.
async function onSaved(saved: Endpoint): Promise<void> {
    mode.value = 'list';
    appliedSearch.value = '';
    search.value = '';
    await load();
    await nextTick();

    if (state.value === 'ready' && rowElement(saved.endpoint_id) !== null) {
        highlighted.value = saved.endpoint_id;
        announce(t('saved'), 'polite');
        await nextTick();
        rowElement(saved.endpoint_id)?.focus();
    }
}

onMounted(load);

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
        <PageHeader
            :title="labels.pageTitle"
            :subtitle="labels.pageSubtitle(sourceName ?? labels.sourceFallback)"
        >
            <Link
                :href="index().url"
                class="type-body-sm text-text-secondary underline underline-offset-4 hover:text-text-primary"
                data-test="back-to-list"
                >{{ labels.back }}</Link
            >
            <AddEndpointButton
                v-if="state === 'ready' && !isEmpty && !formOpen"
                @click="openAdd"
            />
        </PageHeader>

        <DataSourceTabs :data-source-id="dataSourceId" current="endpoints" />

        <p
            v-if="state === 'forbidden'"
            role="alert"
            data-slot="perm-denied"
            class="type-body text-text-secondary"
        >
            {{ t('perm-denied') }}
        </p>

        <p
            v-else-if="state === 'missing'"
            role="alert"
            data-test="missing"
            class="type-body text-text-secondary"
        >
            {{ labels.missing }}
        </p>

        <template v-else-if="testing && state === 'ready'">
            <div class="grid gap-1">
                <h2
                    tabindex="-1"
                    class="type-title-md text-text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    data-test="test-title"
                >
                    {{
                        testingAsUser
                            ? labels.fetchAsUserTitle
                            : labels.testTitle
                    }}:
                    <span class="font-mono"
                        >{{ testing.method }} {{ testing.path }}</span
                    >
                </h2>
                <p class="type-body-sm text-text-secondary">
                    {{
                        testingAsUser
                            ? labels.fetchAsUserSubtitle
                            : labels.testSubtitle
                    }}
                </p>
            </div>
            <EndpointTestPanel
                :key="`${testing.endpoint_id}:${testingAsUser}`"
                :as-user="testingAsUser"
                :data-source-id="dataSourceId"
                :endpoint="testing"
                :source-name="sourceName ?? labels.sourceFallback"
                @gone="onTestGone"
            />
            <div>
                <Button
                    type="button"
                    variant="secondary"
                    data-test="test-back"
                    @click="closeTest(testing.endpoint_id)"
                >
                    {{ labels.testBack }}
                </Button>
            </div>
        </template>

        <template v-else-if="formOpen && state === 'ready'">
            <h2 class="type-title-md text-text-primary" data-test="form-title">
                {{ editing ? labels.editTitle : labels.addTitle }}
            </h2>
            <EndpointForm
                :key="editing?.endpoint_id ?? 'new'"
                :data-source-id="dataSourceId"
                :endpoint="editing"
                @saved="onSaved"
                @cancel="closeForm()"
            />
        </template>

        <template v-else>
            <div
                v-if="showToolbar"
                role="search"
                :aria-label="labels.toolbar"
                data-slot="toolbar"
                class="flex flex-wrap items-center gap-3"
            >
                <label :for="searchId" class="sr-only">{{
                    labels.search
                }}</label>
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
                <AddEndpointButton @click="openAdd" />
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
                :rows="endpoints"
                :row-key="(row: Endpoint) => row.endpoint_id"
                :busy="refreshing"
                :highlighted="highlighted"
                @row-blur="clearHighlight"
            >
                <template #cell-method="{ row }">
                    <Tag data-test="method">{{ row.method }}</Tag>
                </template>
                <template #cell-path="{ row }">
                    <button
                        type="button"
                        :aria-label="labels.edit(row.method, row.path)"
                        class="font-mono text-text-primary underline underline-offset-4 hover:text-accent-ink-strong"
                        data-test="edit-endpoint"
                        @click="openEdit(row)"
                    >
                        {{ row.path }}
                    </button>
                </template>
                <template #cell-revision="{ row }">
                    <span data-test="revision">{{
                        labels.revisionValue(row.revision)
                    }}</span>
                </template>
                <template #cell-data="{ row }">
                    <Tag
                        v-if="row.requires_user_context"
                        tone="info"
                        data-test="user-context-tag"
                        >{{ labels.userContextTag }}</Tag
                    >
                    <span v-else data-test="shared-data">
                        <Tag>{{ labels.sharedData }}</Tag>
                        <span class="sr-only"
                            >. {{ labels.sharedDataHint }}</span
                        >
                    </span>
                </template>
                <template #cell-updated="{ row }">
                    <span data-test="updated">{{
                        formatDateTime(row.updated_at)
                    }}</span>
                </template>
                <template #cell-actions="{ row }">
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :aria-label="labels.testAction(row.method, row.path)"
                        :data-test-focus="row.endpoint_id"
                        data-test="test-endpoint"
                        @click="openTest(row)"
                    >
                        {{ labels.testEndpoint }}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        class="ms-2"
                        :blocked="!mayPreview"
                        :blocked-reason="t('perm-denied')"
                        :aria-label="
                            labels.fetchAsUserAction(row.method, row.path)
                        "
                        data-test="fetch-as-user"
                        @click="openTest(row, true)"
                    >
                        {{ labels.fetchAsUser }}
                    </Button>
                    <span
                        v-if="!mayPreview"
                        class="type-caption ms-2 text-text-muted"
                        data-test="fetch-as-user-denied"
                        >{{ t('perm-denied') }}</span
                    >
                </template>
            </DataTable>
        </template>
    </div>
</template>
