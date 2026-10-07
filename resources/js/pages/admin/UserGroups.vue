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
import CreateGroupButton from '@/components/CreateGroupButton.vue';
import CreateGroupForm from '@/components/CreateGroupForm.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import GroupEditor from '@/components/GroupEditor.vue';
import ListStates from '@/components/ListStates.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { announce, announceDebounced } from '@/lib/announce';
import { formatDateTime } from '@/lib/format';
import { fetchGroups, GroupRequestError } from '@/lib/groups';
import type { Group, GroupSortKey, GroupsMeta } from '@/lib/groups';
import { index as usersIndex } from '@/routes/admin/users';
import { SIGN_IN_URL } from '@/lib/session';
import { groupLabels as labels } from '@/locales/labels';
import { useToasts } from '@/stores/toasts';

// The Groups view of User configuration (Story 1.23; UX-DR-261, 263): a data table of the Workspace's groups with
// sortable headers, search and the generic list states (5 skeleton rows, a failure row with Retry, `list-empty` with
// "Create group", `list-no-match` with Clear search). Creating a group and managing one's members are inline (never a
// modal): "Create group" expands a form, "Manage" expands the group's editor under its row.
const { t } = useI18n();
const toasts = useToasts();
const SEARCH_DEBOUNCE_MS = 300;

type Pending = { kind: 'count' } | { kind: 'sort'; text: string } | null;

const search = ref('');
const appliedSearch = ref('');
const sortKey = ref<GroupSortKey>('name');
const direction = ref<'asc' | 'desc'>('asc');

const groups = ref<Group[]>([]);
const meta = ref<GroupsMeta | null>(null);
const state = ref<'loading' | 'error' | 'forbidden' | 'ready'>('loading');
const refreshing = ref(false);
const searchId = useId();
const createId = useId();
const editing = ref<string | null>(null);

const creating = ref(false);

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
    {
        key: 'members',
        label: labels.columns.members,
        sortable: true,
        class: 'tabular-nums',
    },
    {
        key: 'created',
        label: labels.columns.created,
        sortable: true,
        class: 'tabular-nums',
    },
    { key: 'actions', label: labels.actions },
];

function fail(error: unknown): void {
    if (
        error instanceof GroupRequestError &&
        (error.status === 401 || error.status === 419)
    ) {
        window.location.assign(SIGN_IN_URL);

        return;
    }

    pending = null;
    state.value =
        error instanceof GroupRequestError && error.status === 403
            ? 'forbidden'
            : 'error';
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
        const result = await fetchGroups(
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

        groups.value = result.data;
        meta.value = result.meta;
        state.value = 'ready';
        refreshing.value = false;

        // An editor open on a group that no longer exists closes.
        if (
            editing.value !== null &&
            !result.data.some((group) => group.group_id === editing.value)
        ) {
            editing.value = null;
        }

        const done = pending;
        pending = null;

        if (done?.kind === 'count') {
            announceDebounced(
                'group-list-count',
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
    const next = key as GroupSortKey;

    if (sortKey.value === next) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = next;
        direction.value = 'asc';
    }

    pending = {
        kind: 'sort',
        text: labels.sorted(labels.columns[next], direction.value === 'desc'),
    };
    void load();
}

// ---- Create ----------------------------------------------------------------------------------------------------

function toggleCreate(): void {
    creating.value = !creating.value;
}

function closeCreate(): void {
    creating.value = false;
    void nextTick(() =>
        document
            .querySelector<HTMLElement>('[data-test="create-group"]')
            ?.focus(),
    );
}

// The group exists: show the list afresh (no search) with the new group's editor open on it.
async function created(group: Group): Promise<void> {
    announce(`${t('saved')} ${labels.created(group.name)}`, 'polite');
    creating.value = false;
    appliedSearch.value = '';
    search.value = '';
    await load();

    // The editor opens on the new group only when the reload brought it; otherwise the list shows the failure.
    if (
        state.value === 'ready' &&
        groups.value.some((row) => row.group_id === group.group_id)
    ) {
        editing.value = group.group_id;
    } else if (state.value === 'ready') {
        toasts.add({ kind: 'error', message: t('save-failed.form') });
    }
}

// ---- Rows ------------------------------------------------------------------------------------------------------

function toggleEdit(group: Group): void {
    editing.value = editing.value === group.group_id ? null : group.group_id;
}

function closeEditor(group: Group): void {
    editing.value = null;
    void nextTick(() =>
        document
            .querySelector<HTMLElement>(
                `[data-test="manage-group"][data-group="${group.group_id}"]`,
            )
            ?.focus(),
    );
}

// The server's new state of a group: the row shows it at once and the editor stays open on it.
function changed(updated: Group): void {
    groups.value = groups.value.map((group) =>
        group.group_id === updated.group_id ? updated : group,
    );
    // A rename or a count change can move the row: the server applies the sort and the search again.
    pending = null;
    void load();
}

function deleted(): void {
    editing.value = null;
    pending = null;
    void load().then(() => {
        document
            .querySelector<HTMLElement>('[data-test="create-group"]')
            ?.focus();
    });
}

function gone(): void {
    editing.value = null;
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
        <PageHeader :title="labels.pageTitle">
            <Link
                :href="usersIndex().url"
                class="type-body-sm text-text-secondary underline underline-offset-4 hover:text-text-primary"
                data-test="back-to-users"
                >{{ labels.back }}</Link
            >
            <CreateGroupButton
                v-if="!isEmpty && state === 'ready'"
                :expanded="creating"
                :controls="createId"
                @open="toggleCreate"
            />
        </PageHeader>

        <div v-if="creating" :id="createId">
            <CreateGroupForm @created="created" @close="closeCreate" />
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
            <CreateGroupButton
                :expanded="creating"
                :controls="createId"
                @open="toggleCreate"
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
            :rows="groups"
            :row-key="(group: Group) => group.group_id"
            :sort-key="sortKey"
            :sort-direction="direction"
            :busy="refreshing"
            :expanded="editing"
            @sort="sortBy"
        >
            <template #detail="{ row }">
                <GroupEditor
                    :key="row.group_id"
                    :group="row"
                    @changed="changed"
                    @deleted="deleted"
                    @gone="gone"
                    @close="closeEditor(row)"
                />
            </template>
            <template #cell-members="{ row }">
                {{ row.member_count }}
            </template>
            <template #cell-created="{ row }">
                <time :datetime="row.created_at">{{
                    formatDateTime(row.created_at)
                }}</time>
            </template>
            <template #cell-actions="{ row }">
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    :aria-expanded="editing === row.group_id ? 'true' : 'false'"
                    :aria-controls="
                        editing === row.group_id
                            ? `detail-${row.group_id}`
                            : undefined
                    "
                    :aria-label="
                        editing === row.group_id
                            ? labels.closeFor(row.name)
                            : labels.manageFor(row.name)
                    "
                    :data-group="row.group_id"
                    data-test="manage-group"
                    @click="toggleEdit(row)"
                >
                    {{ labels.manage }}
                </Button>
            </template>
        </DataTable>
    </div>
</template>
