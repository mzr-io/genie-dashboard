<script setup lang="ts" generic="Row extends Record<string, any>">
import { ArrowDown, ArrowUp, ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import { controlLabels } from '@/locales/labels';

// The accessible data table of every Admin list (UX-DR-261, 279, 282): a real `table` with a caption,
// `scope` on its headers and, for a sortable column, a button inside the `th` and `aria-sort` on it. The
// page owns the data and the sorting (the server sorts); this component shows the order and reports the
// click. Cells render through the `cell-{key}` slot, defaulting to the row's text for that key.
export type DataTableColumn = {
    key: string;
    label: string;
    sortable?: boolean;
    // The column that names the row: its cells are row headers.
    rowHeader?: boolean;
    class?: string;
};

const props = defineProps<{
    caption: string;
    // The name of the scrollable region around the table (defaults to the caption).
    label?: string;
    columns: DataTableColumn[];
    rows: Row[];
    rowKey: (row: Row) => string;
    sortKey?: string | null;
    sortDirection?: 'asc' | 'desc';
    // A new page is on its way: the old rows stay, marked busy.
    busy?: boolean;
    // The key of the row whose `detail` slot is expanded inline, in a row of its own under it.
    expanded?: string | null;
    // The key of the row to highlight after a save (accent-soft fill, leading bar). The row is focusable so the page can focus it.
    highlighted?: string | null;
}>();

const emit = defineEmits<{ sort: [key: string]; rowBlur: [key: string] }>();

function ariaSort(
    column: DataTableColumn,
): 'ascending' | 'descending' | 'none' | undefined {
    if (!column.sortable) {
        return undefined;
    }

    if (props.sortKey !== column.key) {
        return 'none';
    }

    return props.sortDirection === 'desc' ? 'descending' : 'ascending';
}

const columnsWithState = computed(() =>
    props.columns.map((column) => ({
        column,
        sort: ariaSort(column),
    })),
);
</script>

<template>
    <div
        data-slot="data-table"
        role="region"
        tabindex="0"
        :aria-label="label ?? caption"
        class="overflow-x-auto rounded-lg border border-border-default bg-surface-card"
    >
        <table
            class="w-full border-collapse text-left"
            :aria-busy="busy ? 'true' : undefined"
        >
            <caption class="sr-only">
                {{
                    caption
                }}
            </caption>
            <thead class="bg-surface-muted">
                <tr>
                    <th
                        v-for="{ column, sort } in columnsWithState"
                        :key="column.key"
                        scope="col"
                        :aria-sort="sort"
                        class="type-label-caps type-caption border-b border-border-default px-4 py-2 font-semibold text-text-secondary"
                        :class="column.class"
                    >
                        <button
                            v-if="column.sortable"
                            type="button"
                            data-slot="sort-button"
                            :data-column="column.key"
                            :aria-label="controlLabels.sortBy(column.label)"
                            class="-mx-1 inline-flex items-center gap-1 rounded-sm px-1 py-0.5 uppercase hover:text-text-primary"
                            @click="emit('sort', column.key)"
                        >
                            {{ column.label }}
                            <ArrowUp
                                v-if="sort === 'ascending'"
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            <ArrowDown
                                v-else-if="sort === 'descending'"
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            <ChevronsUpDown
                                v-else
                                class="size-3.5 opacity-60"
                                aria-hidden="true"
                            />
                        </button>
                        <template v-else>{{ column.label }}</template>
                    </th>
                </tr>
            </thead>
            <tbody>
                <template v-for="row in rows" :key="rowKey(row)">
                    <tr
                        data-slot="data-row"
                        :data-row-key="rowKey(row)"
                        @blur="emit('rowBlur', rowKey(row))"
                        :data-highlighted="
                            highlighted != null && highlighted === rowKey(row)
                                ? 'true'
                                : undefined
                        "
                        :tabindex="
                            highlighted != null && highlighted === rowKey(row)
                                ? -1
                                : undefined
                        "
                        class="border-b border-border-default last:border-b-0 focus:outline-2 focus:-outline-offset-2 focus:outline-(--df-focus-ring) data-[highlighted=true]:bg-accent-soft data-[highlighted=true]:*:first:shadow-[inset_4px_0_0_var(--color-accent-ink-strong)]"
                    >
                        <template v-for="column in columns" :key="column.key">
                            <component
                                :is="column.rowHeader ? 'th' : 'td'"
                                :scope="column.rowHeader ? 'row' : undefined"
                                class="type-body-sm px-4 py-3 align-middle font-normal text-text-primary"
                                :class="column.class"
                            >
                                <slot
                                    :name="`cell-${column.key}`"
                                    :row="row"
                                    :value="row[column.key]"
                                >
                                    {{ row[column.key] }}
                                </slot>
                            </component>
                        </template>
                    </tr>
                    <tr
                        v-if="expanded != null && expanded === rowKey(row)"
                        :id="`detail-${rowKey(row)}`"
                        data-slot="detail-row"
                        class="border-b border-border-default bg-surface-sunken last:border-b-0"
                    >
                        <td :colspan="columns.length" class="px-4 py-4">
                            <slot name="detail" :row="row" />
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
