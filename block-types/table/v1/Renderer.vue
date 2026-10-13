<script setup lang="ts">
import { ArrowDown, ArrowUp, ArrowUpDown, TriangleAlert } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import ChartBlockFrame from '@/charts/ChartBlockFrame.vue';
import { iconFor } from '@/lib/blockIcons';
import { resolveLocale } from '@/lib/format';
import { compareCells, cellText, isDecimal } from './table';
import type { TablePayload, TableRow } from './payload';

// The table v1 renderer (Story 5.12): a real `table` with a hidden caption (the Block's title), `th scope="col"`, numeric cells right-aligned and
// tabular, sort (session only: ascending, descending, data order), pages with a polite announcement, rows kept by stable keys so a refresh keeps the
// focus, the sort and the page, the highlight band's icon and word in the first cell, "—" for a missing value, and a focusable scroll region with
// the first column pinned and the header row kept visible.
const props = withDefaults(
    defineProps<{
        payload: TablePayload;
        audience?: 'user' | 'admin';
        workspaceLocale?: string | null;
        bare?: boolean;
    }>(),
    { audience: 'user', workspaceLocale: null, bare: false },
);

defineEmits<{ (e: 'retry'): void }>();

const locale = computed(() => resolveLocale(props.workspaceLocale));
const regionId = useId();
const columns = computed(() => props.payload.columns ?? []);
const rows = computed<TableRow[]>(() => props.payload.rows ?? []);
const size = computed(() => Math.max(props.payload.pageSize ?? 10, 1));

// Sorting is the reader's, for this session: nothing is saved.
const sortKey = ref<string | null>(null);
const sortDir = ref<'asc' | 'desc'>('asc');
const page = ref(1);
const announcement = ref('');

const BAND_CLASS: Record<string, string> = {
    success: 'bg-success-soft text-success-text',
    warning: 'bg-warning-soft text-warning',
    error: 'bg-error-soft text-error-text',
    info: 'bg-info-soft text-info-strong',
    neutral: 'bg-surface-muted text-text-primary',
};

const ordered = computed<TableRow[]>(() => {
    const key = sortKey.value;
    const at = columns.value.findIndex((c) => c.key === key);

    if (key === null || at === -1) {
        return rows.value;
    }

    const sign = sortDir.value === 'asc' ? 1 : -1;

    // A stable sort: equal cells keep the order the data came in.
    return rows.value
        .map((row, index) => ({ row, index }))
        .sort(
            (a, b) =>
                sign *
                    compareCells(
                        a.row.cells[at],
                        b.row.cells[at],
                        locale.value,
                    ) || a.index - b.index,
        )
        .map(({ row }) => row);
});

const pages = computed(() =>
    Math.max(Math.ceil(ordered.value.length / size.value), 1),
);
const shown = computed(() =>
    ordered.value.slice((page.value - 1) * size.value, page.value * size.value),
);
const range = computed(() => {
    const total = ordered.value.length;

    return total === 0
        ? ''
        : `${(page.value - 1) * size.value + 1}–${Math.min(page.value * size.value, total)} of ${total}`;
});

const numeric = computed(() =>
    columns.value.map(
        (column, at) =>
            column.align === 'right' ||
            (rows.value.length > 0 &&
                rows.value.every(
                    (row) =>
                        row.cells[at] === null ||
                        isDecimal(row.cells[at] as string),
                )),
    ),
);

function sortBy(key: string, header: string): void {
    // Ascending, then descending, then the data order.
    if (sortKey.value !== key) {
        sortKey.value = key;
        sortDir.value = 'asc';
    } else if (sortDir.value === 'asc') {
        sortDir.value = 'desc';
    } else {
        sortKey.value = null;
    }

    page.value = 1;
    announcement.value =
        sortKey.value === null
            ? 'Data order'
            : `Sorted by ${header}, ${sortDir.value === 'asc' ? 'ascending' : 'descending'}`;
}

function go(next: number): void {
    if (next < 1 || next > pages.value) {
        return;
    }

    page.value = next;
    announcement.value = `${range.value}`;
}

// A refresh that left the current page without rows shows the last page and says so; a new period starts again at the first.
watch(pages, (count) => {
    if (page.value > count) {
        page.value = count;
        announcement.value = `Showing the last page, ${count} of ${count}. ${range.value}`;
    }
});
watch(
    () => props.payload.periodKey,
    () => {
        page.value = 1;
    },
);

const ariaSort = (
    key: string,
): 'ascending' | 'descending' | 'none' | undefined =>
    sortKey.value === key
        ? sortDir.value === 'asc'
            ? 'ascending'
            : 'descending'
        : undefined;
</script>

<template>
    <ChartBlockFrame
        :state="payload.state"
        :title="payload.title"
        :subtitle="payload.subtitle"
        :footer-action="payload.footerAction"
        :partial="payload.partial === true"
        :stale-since="payload.staleSince"
        :error-message="payload.errorMessage"
        :empty-display="payload.emptyDisplay"
        fallback-title="Table"
        field="Table columns"
        :audience="audience"
        :workspace-locale="workspaceLocale"
        :bare="bare"
        custom
        @retry="$emit('retry')"
    >
        <div class="grid gap-2" data-test="table-block">
            <div
                :id="regionId"
                role="region"
                tabindex="0"
                :aria-label="payload.title ?? 'Table'"
                class="max-h-96 overflow-auto rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                data-test="table-region"
            >
                <table class="type-body-sm w-full border-collapse text-left">
                    <caption class="sr-only">
                        {{
                            payload.title ?? 'Table'
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th
                                v-for="(column, at) in columns"
                                :key="column.key"
                                scope="col"
                                :aria-sort="ariaSort(column.key)"
                                class="sticky top-0 z-10 border-b border-border-default bg-surface-card px-3 py-2 font-semibold text-text-secondary"
                                :class="[
                                    at === 0 ? 'left-0 z-20' : '',
                                    numeric[at] ? 'text-right' : 'text-left',
                                ]"
                                :style="
                                    column.width === null
                                        ? undefined
                                        : { minWidth: `${column.width}px` }
                                "
                            >
                                <button
                                    v-if="column.sortable"
                                    type="button"
                                    class="inline-flex items-center gap-1 font-semibold focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :data-test="`sort-${column.key}`"
                                    @click="sortBy(column.key, column.header)"
                                >
                                    {{ column.header }}
                                    <ArrowUp
                                        v-if="
                                            ariaSort(column.key) === 'ascending'
                                        "
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <ArrowDown
                                        v-else-if="
                                            ariaSort(column.key) ===
                                            'descending'
                                        "
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3.5 text-text-muted"
                                        aria-hidden="true"
                                    />
                                </button>
                                <template v-else>{{ column.header }}</template>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in shown"
                            :key="row.key"
                            :data-key="row.key"
                            :class="
                                row.highlight
                                    ? BAND_CLASS[row.highlight.color]
                                    : ''
                            "
                        >
                            <td
                                v-for="(cell, at) in row.cells"
                                :key="columns[at]?.key ?? at"
                                class="border-b border-border-default px-3 py-2 break-words tabular-nums"
                                :class="[
                                    at === 0
                                        ? 'sticky left-0 z-[5] bg-surface-card'
                                        : '',
                                    numeric[at] ? 'text-right' : 'text-left',
                                ]"
                            >
                                <span
                                    v-if="at === 0 && row.highlight"
                                    class="mr-2 inline-flex items-center gap-1 font-semibold"
                                    data-test="row-band"
                                >
                                    <component
                                        :is="iconFor(row.highlight.icon)"
                                        class="size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {{ row.highlight.text }}
                                </span>
                                {{
                                    cellText(
                                        cell,
                                        columns[at]?.format ?? null,
                                        locale,
                                    )
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="ordered.length > size"
                class="flex flex-wrap items-center justify-between gap-2"
                data-test="table-pager"
            >
                <p
                    class="type-caption text-text-secondary"
                    data-test="table-range"
                >
                    {{ range }}
                </p>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="type-caption rounded-md border border-border-control px-3 py-1 aria-disabled:opacity-50"
                        :aria-disabled="page <= 1 ? 'true' : undefined"
                        data-test="table-prev"
                        @click="go(page - 1)"
                    >
                        Previous
                    </button>
                    <button
                        type="button"
                        class="type-caption rounded-md border border-border-control px-3 py-1 aria-disabled:opacity-50"
                        :aria-disabled="page >= pages ? 'true' : undefined"
                        data-test="table-next"
                        @click="go(page + 1)"
                    >
                        Next
                    </button>
                </div>
            </div>

            <p class="sr-only" aria-live="polite" data-test="table-announce">
                {{ announcement }}
            </p>
        </div>
    </ChartBlockFrame>
</template>
