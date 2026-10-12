<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import CreateBlockButton from '@/components/CreateBlockButton.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import ListStates from '@/components/ListStates.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { announce } from '@/lib/announce';
import { formatDateTime } from '@/lib/format';
import {
    PublishedBlockError,
    fetchPublishedBlocks,
} from '@/lib/publishedBlocks';
import type { PublishedBlock } from '@/lib/publishedBlocks';
import { SIGN_IN_URL } from '@/lib/session';
import { publishedBlockLabels as labels } from '@/locales/labels';

// Published blocks (Story 3.19; UX-DR-261, 263, 282): a data table of the blocks that are live: name, category, type, version, owner, last
// updated and status "Published". The wizard sends the Admin here with `?published=<block id>` after a publish: that row is highlighted and
// focused and the query is dropped. The generic list states apply (5 skeleton rows, a failure row with Retry, `list-empty`, `perm-denied`).
// Publish impact, restore and unpublish are Epic 6.
const { t } = useI18n();

const blocks = ref<PublishedBlock[]>([]);
const state = ref<'loading' | 'error' | 'forbidden' | 'ready'>('loading');
const highlighted = ref<string | null>(null);
let controller: AbortController | null = null;

const isEmpty = computed(
    () => state.value === 'ready' && blocks.value.length === 0,
);

const columns: DataTableColumn[] = [
    { key: 'name', label: labels.columns.name, rowHeader: true },
    { key: 'category', label: labels.columns.category },
    { key: 'type', label: labels.columns.type },
    { key: 'version', label: labels.columns.version, class: 'tabular-nums' },
    { key: 'owner', label: labels.columns.owner },
    { key: 'updated', label: labels.columns.updated },
    { key: 'status', label: labels.columns.status },
];

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

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const mine = controller;

    state.value = 'loading';

    try {
        const result = await fetchPublishedBlocks(mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        blocks.value = result.data;
        state.value = 'ready';
    } catch (error) {
        if (mine.signal.aborted) {
            return;
        }

        if (
            error instanceof PublishedBlockError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        state.value =
            error instanceof PublishedBlockError && error.status === 403
                ? 'forbidden'
                : 'error';
    }
}

// The wizard sends the person back with the block's id: highlight and focus its row, announce it, drop the query.
async function showPublished(): Promise<void> {
    const id = new URLSearchParams(window.location.search).get('published');

    if (id === null) {
        return;
    }

    window.history.replaceState(
        window.history.state,
        '',
        window.location.pathname,
    );

    await nextTick();

    const block = blocks.value.find((row) => row.block_id === id);

    if (state.value === 'ready' && block !== undefined && rowElement(id)) {
        highlighted.value = id;
        announce(labels.highlighted(block.name), 'polite');
        await nextTick();
        rowElement(id)?.focus();
    }
}

onMounted(async () => {
    await load();
    await showPublished();
});

onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <Head :title="labels.pageTitle" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader :title="labels.pageTitle" :subtitle="labels.pageSubtitle">
            <CreateBlockButton v-if="state === 'ready' && !isEmpty" />
        </PageHeader>

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

        <DataTable
            v-else
            :caption="labels.caption"
            :label="labels.tableRegion"
            :columns="columns"
            :rows="blocks"
            :row-key="(block: PublishedBlock) => block.block_id"
            :highlighted="highlighted"
        >
            <template #cell-name="{ row }">
                <span class="font-medium text-text-primary" data-test="name">{{
                    row.name
                }}</span>
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
                <Badge variant="operational" data-test="status">{{
                    labels.status
                }}</Badge>
            </template>
        </DataTable>
    </div>
</template>
