<script setup lang="ts">
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import Tag from '@/components/Tag.vue';
import { visibleNodes } from '@/lib/blockSample';
import type { SampleNode, SampleShape } from '@/lib/blockSample';
import { blockSampleLabels as labels } from '@/locales/labels';

// The structure of an accepted sample (Story 3.8; also "View JSON" in Map Data later): a collapsible tree whose every node shows its display
// path (`data.monthly[].revenue`), its inferred type and an example, and a table view of an array of records. Everything shown was derived by
// the server from the lossless text, so a number appears exactly as it was received; here it is only ever rendered as text. The tree is a
// list of buttons that fold a branch (aria-expanded), so it needs no widget keyboard model of its own.
const props = defineProps<{ shape: SampleShape }>();

const nodes = computed<SampleNode[]>(() => props.shape.nodes ?? []);
const tables = computed(() => props.shape.tables ?? {});
const tableIds = computed(() =>
    Object.keys(tables.value)
        .map(Number)
        .filter((id) => nodes.value[id] !== undefined),
);
const view = ref<'tree' | 'table'>('tree');
const collapsed = ref<Set<number>>(new Set());
const records = computed(() => props.shape.records);
const recordsId = computed(
    () =>
        nodes.value.find(
            (n) => n.path === records.value?.path.replace(/\[\*\]$/, ''),
        )?.id ?? tableIds.value[0],
);
const chosen = ref<number | null>(null);
const tableId = computed(() => chosen.value ?? recordsId.value ?? null);
const table = computed(() =>
    tableId.value === null ? null : (tables.value[tableId.value] ?? null),
);
const parents = computed(
    () => new Set(nodes.value.map((n) => n.parent).filter((p) => p !== null)),
);
const shown = computed(() => visibleNodes(nodes.value, collapsed.value));
const headingId = useId();
const tableSelectId = useId();

function toggle(id: number): void {
    const next = new Set(collapsed.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    collapsed.value = next;
}

const views = computed(() => [
    { value: 'tree', label: labels.viewTree },
    ...(tableIds.value.length > 0
        ? [{ value: 'table', label: labels.viewTable }]
        : []),
]);
</script>

<template>
    <section
        :aria-labelledby="headingId"
        class="grid gap-3"
        data-test="sample-structure"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 :id="headingId" class="type-title-sm text-text-primary">
                {{ labels.structure }}
            </h3>
            <SegmentedControl
                :model-value="view"
                :options="views"
                :label="labels.view"
                data-test="structure-view"
                @update:model-value="(v) => (view = v as 'tree' | 'table')"
            />
        </div>

        <ul
            v-if="view === 'tree'"
            :aria-label="labels.nodes"
            class="m-0 grid list-none gap-0.5 p-0"
            data-test="tree"
        >
            <li
                v-for="node in shown"
                :key="node.id"
                class="flex flex-wrap items-center gap-2 rounded-sm py-0.5"
                :style="{ paddingInlineStart: `${node.depth * 1.25}rem` }"
                data-test="tree-node"
                :data-path="node.path"
            >
                <button
                    v-if="parents.has(node.id)"
                    type="button"
                    class="inline-flex size-6 shrink-0 items-center justify-center rounded-sm text-text-secondary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-expanded="!collapsed.has(node.id)"
                    :aria-label="
                        collapsed.has(node.id)
                            ? labels.expand(node.display)
                            : labels.collapse(node.display)
                    "
                    data-test="tree-toggle"
                    @click="toggle(node.id)"
                >
                    <ChevronRight
                        v-if="collapsed.has(node.id)"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <ChevronDown v-else class="size-4" aria-hidden="true" />
                </button>
                <span v-else class="size-6 shrink-0" aria-hidden="true" />
                <span
                    class="type-body-sm font-mono text-text-primary"
                    data-test="node-path"
                    :title="node.path"
                    >{{ node.display }}</span
                >
                <Tag data-test="node-type">{{ node.type }}</Tag>
                <span
                    v-if="node.count !== null"
                    class="type-caption text-text-muted"
                    data-test="node-count"
                    >{{ labels.records(node.count) }}</span
                >
                <span
                    v-if="node.example !== null"
                    class="type-caption font-mono break-all text-text-muted"
                    data-test="node-example"
                    >{{ node.example }}</span
                >
            </li>
        </ul>

        <div v-else class="grid gap-2" data-test="table-view">
            <div v-if="tableIds.length > 1" class="grid max-w-md gap-1">
                <label
                    :for="tableSelectId"
                    class="type-caption text-text-secondary"
                    >{{ labels.tableOf }}</label
                >
                <NativeSelect
                    :id="tableSelectId"
                    :model-value="String(tableId)"
                    data-test="table-choice"
                    @update:model-value="(v) => (chosen = Number(v))"
                >
                    <option
                        v-for="id in tableIds"
                        :key="id"
                        :value="String(id)"
                    >
                        {{ nodes[id].display }}
                    </option>
                </NativeSelect>
            </div>
            <div
                v-if="table !== null && tableId !== null"
                class="overflow-x-auto"
                role="region"
                tabindex="0"
                :aria-label="labels.tableCaption(nodes[tableId].display)"
            >
                <table class="w-full border-collapse text-left">
                    <caption class="sr-only">
                        {{
                            labels.tableCaption(nodes[tableId].display)
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th
                                v-for="column in table.columns"
                                :key="column.key"
                                scope="col"
                                class="type-caption pe-3 pb-1 font-mono font-semibold text-text-secondary"
                            >
                                {{ column.display }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, index) in table.rows"
                            :key="index"
                            data-test="table-row"
                        >
                            <td
                                v-for="(cell, at) in row"
                                :key="at"
                                class="type-body-sm pe-3 pb-1 font-mono break-all text-text-primary"
                            >
                                {{ cell }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-if="table !== null && table.total > table.rows.length"
                class="type-caption text-text-muted"
                data-test="table-truncated"
            >
                {{ labels.tableTruncated(table.rows.length, table.total) }}
            </p>
            <p
                v-if="tableIds.length === 0"
                class="type-body-sm text-text-muted"
                data-test="no-tables"
            >
                {{ labels.noTables }}
            </p>
        </div>
    </section>
</template>
