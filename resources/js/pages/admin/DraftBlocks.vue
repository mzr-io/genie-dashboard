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
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CreateBlockButton from '@/components/CreateBlockButton.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import ListStates from '@/components/ListStates.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { announce, announceDebounced } from '@/lib/announce';
import {
    DraftBlockError,
    discardDraftBlock,
    draftWizardUrl,
    fetchDraftBlocks,
} from '@/lib/draftBlocks';
import type { DraftBlock, DraftBlockList } from '@/lib/draftBlocks';
import { formatDateTime } from '@/lib/format';
import { SIGN_IN_URL } from '@/lib/session';
import { draftBlockLabels as labels } from '@/locales/labels';
import { useToasts } from '@/stores/toasts';

// Draft blocks (Story 3.3; UX-DR-261, 263, 271, 282): a data table of the Drafts the Workspace has started, with search, a count
// caption and the generic list states (5 skeleton rows, a failure row with Retry, `list-empty` with the action, `list-no-match`
// with Clear search, `perm-denied` for a 403). Only Drafts are listed. A row opens the wizard at the step saved in the Draft's UI
// state (the wizard is Story 3.4: the address is a link target only). Discarding asks first; the server hides the Draft at once and
// deletes its rows afterwards.
const { t } = useI18n();
const toasts = useToasts();
const SEARCH_DEBOUNCE_MS = 300;

const search = ref('');
const appliedSearch = ref('');
const drafts = ref<DraftBlock[]>([]);
const meta = ref<DraftBlockList['meta'] | null>(null);
const state = ref<'loading' | 'error' | 'forbidden' | 'ready'>('loading');
const refreshing = ref(false);
const searchId = useId();

// Discard: the Draft awaiting confirmation and the control that opened the dialog.
const discarding = ref<DraftBlock | null>(null);
const discardOpen = ref(false);
const discardInvoker = ref<HTMLElement | null>(null);

let controller: AbortController | null = null;
let timer: ReturnType<typeof setTimeout> | null = null;
let countPending = false;
let discardInFlight = false;

const total = computed(() => meta.value?.total ?? 0);
const matched = computed(() => meta.value?.matched ?? 0);
const searching = computed(() => appliedSearch.value.trim() !== '');
const isEmpty = computed(() => state.value === 'ready' && total.value === 0);
const showToolbar = computed(
    () => !isEmpty.value && state.value !== 'forbidden',
);

const columns: DataTableColumn[] = [
    { key: 'name', label: labels.columns.name, rowHeader: true },
    { key: 'category', label: labels.columns.category },
    { key: 'type', label: labels.columns.type },
    { key: 'version', label: labels.columns.version, class: 'tabular-nums' },
    { key: 'owner', label: labels.columns.owner },
    { key: 'updated', label: labels.columns.updated },
    { key: 'status', label: labels.columns.status },
    { key: 'actions', label: labels.columns.actions },
];

function fail(error: unknown): void {
    if (
        error instanceof DraftBlockError &&
        (error.status === 401 || error.status === 419)
    ) {
        window.location.assign(SIGN_IN_URL);

        return;
    }

    countPending = false;
    state.value =
        error instanceof DraftBlockError && error.status === 403
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
        const result = await fetchDraftBlocks(appliedSearch.value, mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        drafts.value = result.data;
        meta.value = result.meta;
        state.value = 'ready';
        refreshing.value = false;

        if (countPending) {
            countPending = false;
            announceDebounced(
                'draft-blocks-count',
                labels.count(result.meta.matched, result.meta.total),
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
    countPending = true;
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

function typeLabel(key: string): string {
    return labels.types[key] ?? key;
}

function rowElement(key: string): HTMLElement | null {
    // Compared as text, never built into a selector.
    return (
        Array.from(
            document.querySelectorAll<HTMLElement>('[data-row-key]'),
        ).find((row) => row.dataset.rowKey === key) ?? null
    );
}

// ---- Discard ---------------------------------------------------------------------------------------------------

function askDiscard(draft: DraftBlock, event: Event): void {
    discardInvoker.value =
        event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    discarding.value = draft;
    discardOpen.value = true;
}

async function confirmDiscard(): Promise<void> {
    const draft = discarding.value;

    // A second activation while the request is out would hit a Draft the server has already hidden.
    if (draft === null || discardInFlight) {
        return;
    }

    discardInFlight = true;

    try {
        await discardDraftBlock(draft.draft_id);
    } catch (error) {
        if (error instanceof DraftBlockError) {
            if (error.status === 401 || error.status === 419) {
                window.location.assign(SIGN_IN_URL);

                return;
            }

            if (error.status === 404) {
                // Already gone (discarded in another tab): say so and show the list as it is now.
                toasts.add({ kind: 'error', message: labels.gone });
                await load();

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

        return;
    } finally {
        discardInFlight = false;
    }

    announce(labels.discarded(draft.name), 'polite');
    await load();
    await nextTick();
    document.getElementById(searchId)?.focus();
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
            <CreateBlockButton v-if="state === 'ready' && !isEmpty" />
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
            <CreateBlockButton />
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
            :rows="drafts"
            :row-key="(draft: DraftBlock) => draft.draft_id"
            :busy="refreshing"
        >
            <template #cell-name="{ row }">
                <Link
                    :href="draftWizardUrl(row)"
                    :aria-label="labels.open(row.name)"
                    class="font-medium text-text-primary underline underline-offset-4 hover:text-accent-ink-strong"
                    data-test="open-link"
                    >{{ row.name }}</Link
                >
            </template>
            <template #cell-category="{ row }">
                {{ row.category ?? labels.noCategory }}
            </template>
            <template #cell-type="{ row }">
                {{ typeLabel(row.type) }}
            </template>
            <template #cell-version="{ row }">
                {{ row.version }}
            </template>
            <template #cell-owner="{ row }">
                {{ row.owner ?? labels.noOwner }}
            </template>
            <template #cell-updated="{ row }">
                <span data-test="updated">{{
                    formatDateTime(row.updated_at)
                }}</span>
            </template>
            <template #cell-status>
                <Badge variant="draft" data-test="status">{{
                    labels.draft
                }}</Badge>
            </template>
            <template #cell-actions="{ row }">
                <Button
                    type="button"
                    variant="destructive-soft"
                    :aria-label="labels.discardAria(row.name)"
                    data-test="discard"
                    @click="askDiscard(row, $event)"
                >
                    {{ labels.discard }}
                </Button>
            </template>
        </DataTable>

        <ConfirmDialog
            v-if="discarding"
            v-model:open="discardOpen"
            :title="labels.discardTitle(discarding.name)"
            :description="labels.discardImpact"
            :object-name="discarding.name"
            :verb="labels.discard"
            :invoker="discardInvoker"
            :fallback="
                () => (discarding ? rowElement(discarding.draft_id) : null)
            "
            @confirm="confirmDiscard"
        />
    </div>
</template>
