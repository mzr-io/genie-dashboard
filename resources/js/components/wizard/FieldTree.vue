<script setup lang="ts">
import { formatMeasure } from '@block-types/kpi-card/v1/display';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    provide,
    ref,
    useId,
    watch,
} from 'vue';
import BlockedReason from '@/components/BlockedReason.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import FieldTreeItem from '@/components/wizard/FieldTreeItem.vue';
import { Button } from '@/components/ui/button';
import { announce, announceDebounced } from '@/lib/announce';
import type { PickerField } from '@/lib/blockMapping';
import type { SampleNode } from '@/lib/blockSample';
import {
    TREE_CONTEXT,
    branchIds,
    buildTree,
    defaultSlot,
    fitOf,
    listingOf,
    searchTree,
    trail,
    typeAhead,
    typeWord,
    rowText,
} from '@/lib/fieldTree';
import type { TreeContext, TreeModel } from '@/lib/fieldTree';
import { currentFormatting } from '@/lib/format';
import { blockMapLabels as labels } from '@/locales/labels';

// "Browse all fields" (Story 3.11d; UX-DR-38): the sample's nodes as an APG tree. The Admin searches, folds branches and "Use"s a leaf for the slot
// chosen in "Use for" (the headline by default). Which leaves fit comes from the slot's field listing, so the reason a field cannot be used is the
// server's own; the write itself is the step's override. Keys: Up/Down, Right (open or first child), Left (close or parent), Home/End, Enter on a
// leaf = Use, type-ahead. One tab stop (roving tabindex); the buttons inside a row are for the pointer.
const props = defineProps<{
    nodes: SampleNode[];
    slots: { slot: string; label: string; required: boolean; state: string }[];
    // A slot the Admin asked to fill ("Choose from the field tree"); null leaves the default.
    preferred: string | null;
    // Counts each "Choose from the field tree" request, so asking again for the same slot works.
    requestKey: number;
    // The effective record path, to join a listing that names fields relative to it.
    recordPath: string | null;
    loadFields: (slot: string) => Promise<PickerField[]>;
    // Changes when a write lands: the listing is read again.
    refreshKey: number;
    // Why nothing can be changed (read-only, offline); undefined when it can.
    blocked?: string;
    // A write is in flight.
    busy: boolean;
}>();

const emit = defineEmits<{ (e: 'use', slot: string, path: string): void }>();

const id = useId();
const query = ref('');
const expanded = ref<Set<number>>(new Set());
const active = ref<number | null>(null);
const selected = ref<number | null>(null);
const chosenSlot = ref<string | null>(null);
const listing = ref<Map<string, PickerField> | null>(null);
const listState = ref<'loading' | 'ready' | 'error'>('loading');
const root = ref<HTMLElement | null>(null);
const searchBox = ref<HTMLInputElement | null>(null);
let ticket = 0;
let typed = '';
let typedTimer: ReturnType<typeof setTimeout> | null = null;

const editable = computed(() => props.blocked === undefined);
const search = computed(() =>
    query.value.trim() === '' ? null : searchTree(props.nodes, query.value),
);
const view = computed(() =>
    buildTree(props.nodes, expanded.value, search.value?.keep ?? null),
);
const slot = computed(
    () =>
        [chosenSlot.value, props.preferred, defaultSlot(props.slots)].find(
            (key) =>
                key !== null && props.slots.some((entry) => entry.slot === key),
        ) ?? null,
);
const slotLabel = computed(
    () => props.slots.find((entry) => entry.slot === slot.value)?.label ?? '',
);
const tabbable = computed(() => {
    const flat = view.value.flat;

    return flat.some((row) => row.node.id === active.value)
        ? active.value
        : (flat[0]?.node.id ?? null);
});
const crumbs = computed(() =>
    active.value === null ? [] : trail(props.nodes, active.value),
);
const picked = computed(
    () => props.nodes.find((node) => node.id === selected.value) ?? null,
);
// "Selected: path · example → formatted": a number is formatted as the card would show it; anything else shows the example alone.
const branchSet = computed(() => new Set(branchIds(props.nodes)));
// The focused leaf, shown under "Focused:" until one is selected (Enter or Use).
const looking = computed(() => {
    const node = props.nodes.find((n) => n.id === active.value) ?? null;

    return node !== null && !branchSet.value.has(node.id) ? node : null;
});
const footer = computed(() => {
    const node = picked.value ?? looking.value;

    if (node === null) {
        return labels.treeNothingSelected;
    }

    let formatted: string | null = null;

    if (node.example !== null && typeWord(node.type) === 'number') {
        const text = formatMeasure(
            node.example,
            {
                kind: 'number',
                decimalPlaces: null,
                thousandsSeparator: true,
                currencyCode: null,
                currencyDisplay: 'symbol',
                datePattern: 'medium',
                unitsSuffix: null,
            },
            currentFormatting().locale,
        );

        formatted = text === '' || text === node.example ? null : text;
    }

    return (picked.value === null ? labels.treeFocused : labels.treeSelected)(
        node.display,
        node.example,
        formatted,
    );
});
const status = computed(() => {
    if (!editable.value) {
        return null;
    }

    return listState.value === 'loading'
        ? labels.treeLoading
        : listState.value === 'error'
          ? labels.treeFailed
          : null;
});

// ---- The slot's field listing ---------------------------------------------------------------------------------------------

async function readListing(): Promise<void> {
    const mine = (ticket += 1);

    // The last slot's answer must never decide this one: nothing fits until the new listing arrives.
    listing.value = null;

    if (!editable.value || slot.value === null) {
        listing.value = null;
        listState.value = 'ready';

        return;
    }

    listState.value = 'loading';

    try {
        const fields = await props.loadFields(slot.value);

        if (mine === ticket) {
            listing.value = listingOf(fields, props.recordPath);
            listState.value = 'ready';
        }
    } catch {
        if (mine === ticket) {
            listing.value = null;
            listState.value = 'error';
        }
    }
}

watch(() => [slot.value, props.refreshKey, editable.value], readListing, {
    immediate: true,
});

// ---- Search and folding ---------------------------------------------------------------------------------------------------

// A slot asked for from its row wins over an earlier choice in "Use for".
watch(
    () => props.requestKey,
    () => {
        if (props.preferred !== null) {
            chosenSlot.value = props.preferred;
        }
    },
    { immediate: true },
);

// What was open before a search opened branches, put back when the search is cleared.
let beforeSearch: Set<number> | null = null;

watch(query, () => {
    const found = search.value;

    if (found !== null) {
        beforeSearch ??= new Set(expanded.value);
        expanded.value = new Set([...expanded.value, ...found.open]);
    } else if (beforeSearch !== null) {
        expanded.value = beforeSearch;
        beforeSearch = null;
    }

    announceDebounced(
        'field-tree',
        labels.fieldsMatch(found === null ? leafCount.value : found.fields),
    );
});

const leafCount = computed(
    () => props.nodes.length - branchIds(props.nodes).length,
);

function setExpanded(idOf: number, open: boolean): void {
    const next = new Set(expanded.value);

    if (open) {
        next.add(idOf);
    } else {
        next.delete(idOf);
    }

    expanded.value = next;
}

function expandAll(): void {
    expanded.value = new Set(branchIds(props.nodes));
}

function collapseAll(): void {
    expanded.value = new Set();
}

// ---- Focus ------------------------------------------------------------------------------------------------------------------

function elementOf(nodeId: number): HTMLElement | null {
    return (
        root.value?.querySelector<HTMLElement>(`[data-id="${nodeId}"]`) ?? null
    );
}

async function focusNode(nodeId: number): Promise<void> {
    active.value = nodeId;
    await nextTick();
    elementOf(nodeId)?.focus();
}

function focused(model: TreeModel): void {
    active.value = model.node.id;
}

// The focused row went (Collapse all, a search): the roving stop and, when focus was lost with it, focus move to the nearest row still shown.
watch(
    () => view.value.flat,
    (flat) => {
        if (
            active.value === null ||
            flat.length === 0 ||
            flat.some((row) => row.node.id === active.value)
        ) {
            return;
        }

        const shown = new Set(flat.map((row) => row.node.id));
        const byId = new Map(props.nodes.map((n) => [n.id, n]));
        let at = byId.get(active.value)?.parent ?? null;

        while (at !== null && !shown.has(at)) {
            at = byId.get(at)?.parent ?? null;
        }

        const next = at ?? flat[0].node.id;
        const here = document.activeElement;
        const lost =
            here === null ||
            here === document.body ||
            here.closest('[role="tree"]') !== null;

        active.value = next;

        if (lost) {
            void nextTick(() => elementOf(next)?.focus());
        }
    },
    { flush: 'post' },
);

function activate(model: TreeModel): void {
    void focusNode(model.node.id);

    if (model.branch) {
        setExpanded(model.node.id, !expanded.value.has(model.node.id));
    }
}

function use(model: TreeModel): void {
    const target = slot.value;
    const fit = fitOf(model.node, listing.value, labels.treeNotAField);

    if (model.branch || target === null || !editable.value) {
        return;
    }

    selected.value = model.node.id;

    if (listState.value !== 'ready' || fit.ok === null) {
        announce(
            listState.value === 'error'
                ? labels.treeFailed
                : labels.treeStillLoading,
            'polite',
        );

        return;
    }

    if (fit.ok === false) {
        announce(fit.reason ?? labels.treeNotAField, 'polite');

        return;
    }

    if (props.busy) {
        announce(labels.treeBusyNow, 'polite');

        return;
    }

    emit('use', target, model.node.path);
}

// ---- Keyboard (APG tree) --------------------------------------------------------------------------------------------------

function onKey(event: KeyboardEvent): void {
    const target = event.target as HTMLElement;
    const item = target.closest<HTMLElement>('[role="treeitem"]');

    if (
        item === null ||
        item !== target ||
        event.ctrlKey ||
        event.metaKey ||
        event.altKey
    ) {
        return;
    }

    const flat = view.value.flat;
    const at = flat.findIndex((row) => row.node.id === Number(item.dataset.id));
    const model = flat[at];

    if (model === undefined) {
        return;
    }

    const go = (row: TreeModel | undefined): void => {
        event.preventDefault();

        if (row !== undefined) {
            void focusNode(row.node.id);
        }
    };
    const open = expanded.value.has(model.node.id);

    switch (event.key) {
        case 'ArrowDown':
            return go(flat[at + 1]);
        case 'ArrowUp':
            return go(flat[at - 1]);
        case 'Home':
            return go(flat[0]);
        case 'End':
            return go(flat[flat.length - 1]);
        case 'ArrowRight':
            if (model.branch && !open) {
                event.preventDefault();
                setExpanded(model.node.id, true);
            } else if (model.branch) {
                go(flat[at + 1]);
            }

            return;
        case 'ArrowLeft':
            if (model.branch && open) {
                event.preventDefault();
                setExpanded(model.node.id, false);
            } else {
                go(flat.find((row) => row.node.id === model.node.parent));
            }

            return;
        case 'Enter':
        case ' ':
            event.preventDefault();

            if (model.branch) {
                setExpanded(model.node.id, !open);
            } else if (event.key === 'Enter') {
                use(model);
            }

            return;
        default:
    }

    if (event.key.length === 1 && event.key !== ' ') {
        typed += event.key;

        if (typedTimer !== null) {
            clearTimeout(typedTimer);
        }

        typedTimer = setTimeout(() => (typed = ''), 600);
        go(typeAhead(flat, model.node.id, typed) ?? undefined);
    }
}

// Down arrow from the search box goes into the tree.
function onSearchKey(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' && tabbable.value !== null) {
        event.preventDefault();
        void focusNode(tabbable.value);
    }
}

onBeforeUnmount(() => {
    ticket += 1;

    if (typedTimer !== null) {
        clearTimeout(typedTimer);
    }
});

// "Choose from the field tree": the search box takes focus.
defineExpose({ focus: () => searchBox.value?.focus() });

const context: TreeContext = {
    isExpanded: (nodeId) => expanded.value.has(nodeId),
    tabbableId: () => tabbable.value,
    selectedId: () => selected.value,
    fit: (node) => fitOf(node, listing.value, labels.treeNotAField),
    canUse: () => editable.value,
    busy: () => props.busy,
    slotLabel: () => slotLabel.value,
    activate,
    focused,
    use,
    reasonId: (nodeId) => `${id}-reason-${nodeId}`,
    elementId: (nodeId) => `${id}-item-${nodeId}`,
};

provide(TREE_CONTEXT, context);
</script>

<template>
    <div
        ref="root"
        class="grid gap-3 rounded-md border border-border-default bg-surface-card p-3"
        data-test="field-tree"
    >
        <BlockedReason
            v-if="blocked"
            :id="`${id}-blocked`"
            data-test="tree-blocked"
            >{{ blocked }}</BlockedReason
        >

        <div class="flex flex-wrap items-end gap-3">
            <div class="grid min-w-48 flex-1 gap-1">
                <label
                    :for="`${id}-search`"
                    class="type-caption text-text-secondary"
                    >{{ labels.treeSearch }}</label
                >
                <input
                    :id="`${id}-search`"
                    ref="searchBox"
                    v-model="query"
                    type="search"
                    :placeholder="labels.treeSearchHint"
                    autocomplete="off"
                    class="type-body-sm min-h-9 w-full rounded-md border border-border-control bg-surface-card px-3 text-text-primary placeholder:text-text-muted focus:border-brand focus:outline-2 focus:outline-offset-2 focus:outline-(--df-focus-ring)"
                    data-test="tree-search"
                    @keydown="onSearchKey"
                />
            </div>
            <div v-if="editable && slots.length > 0" class="grid gap-1">
                <label
                    :for="`${id}-slot`"
                    class="type-caption text-text-secondary"
                    >{{ labels.treeUseFor }}</label
                >
                <NativeSelect
                    :id="`${id}-slot`"
                    :model-value="slot ?? ''"
                    data-test="tree-slot"
                    @update:model-value="
                        (value) => (chosenSlot = value ?? null)
                    "
                >
                    <option
                        v-for="entry in slots"
                        :key="entry.slot"
                        :value="entry.slot"
                    >
                        {{ entry.label }}
                    </option>
                </NativeSelect>
            </div>
            <div class="flex gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    data-test="tree-expand-all"
                    @click="expandAll"
                    >{{ labels.treeExpandAll }}</Button
                >
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    data-test="tree-collapse-all"
                    @click="collapseAll"
                    >{{ labels.treeCollapseAll }}</Button
                >
            </div>
        </div>

        <p
            v-if="status"
            class="type-caption text-text-secondary"
            :role="listState === 'error' ? 'alert' : 'status'"
            data-test="tree-status"
        >
            {{ status }}
            <Button
                v-if="listState === 'error'"
                type="button"
                variant="secondary"
                size="sm"
                data-test="tree-retry"
                @click="readListing"
                >{{ labels.retry }}</Button
            >
        </p>

        <nav :aria-label="labels.treeBreadcrumb" data-test="tree-breadcrumb">
            <ol class="m-0 flex list-none flex-wrap items-center gap-1 p-0">
                <li
                    v-for="(crumb, index) in crumbs"
                    :key="crumb.id"
                    class="type-caption flex items-center gap-1 font-mono text-text-secondary"
                    :aria-current="
                        index === crumbs.length - 1 ? 'location' : undefined
                    "
                    data-test="crumb"
                >
                    <span v-if="index > 0" aria-hidden="true">›</span>
                    {{ rowText(crumb) }}
                </li>
            </ol>
        </nav>

        <ul
            v-if="view.roots.length > 0"
            role="tree"
            :aria-label="labels.treeLabel"
            class="m-0 max-h-96 list-none overflow-y-auto p-0"
            data-test="tree"
            @keydown="onKey"
        >
            <FieldTreeItem
                v-for="model in view.roots"
                :key="model.node.id"
                :model="model"
            />
        </ul>
        <p
            v-else
            class="type-body-sm text-text-secondary"
            data-test="tree-empty"
        >
            {{ labels.treeEmpty }}
        </p>

        <p
            class="type-body-sm font-mono break-all text-text-primary"
            data-test="tree-footer"
        >
            {{ footer }}
        </p>
    </div>
</template>
