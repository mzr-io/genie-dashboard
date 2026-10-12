<script setup lang="ts">
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { computed, inject } from 'vue';
import { Button } from '@/components/ui/button';
import { TREE_CONTEXT, rowText, typeTone, typeWord } from '@/lib/fieldTree';
import type { TreeModel } from '@/lib/fieldTree';
import { blockMapLabels as labels } from '@/locales/labels';

// One row of the field tree (Story 3.11d) and, recursively, the `group` of its shown children. The row is the APG `treeitem`: it carries the
// level, size and position, `aria-expanded` on a branch and `aria-disabled` (with its reason in `aria-describedby`) on a leaf the chosen slot
// cannot take. Keys are handled once, by the tree.
defineOptions({ name: 'FieldTreeItem' });

const props = defineProps<{ model: TreeModel }>();

const ctx = inject(TREE_CONTEXT)!;
const node = computed(() => props.model.node);
const expanded = computed(() => ctx.isExpanded(node.value.id));
const fit = computed(() => ctx.fit(node.value));
const leaf = computed(() => !props.model.branch);
const disabled = computed(() => leaf.value && fit.value.ok === false);
const type = computed(() => typeWord(fit.value.type ?? node.value.type));
const tabbable = computed(() => ctx.tabbableId() === node.value.id);
</script>

<template>
    <li
        :id="ctx.elementId(node.id)"
        role="treeitem"
        :tabindex="tabbable ? 0 : -1"
        :aria-level="model.level"
        :aria-setsize="model.setSize"
        :aria-posinset="model.pos"
        :aria-expanded="
            model.branch ? (expanded ? 'true' : 'false') : undefined
        "
        :aria-selected="leaf ? ctx.selectedId() === node.id : undefined"
        :aria-disabled="disabled ? 'true' : undefined"
        :aria-label="labels.treeItemName(node.display, type)"
        :aria-describedby="disabled ? ctx.reasonId(node.id) : undefined"
        :data-id="node.id"
        :data-path="node.path"
        :data-compatible="leaf && fit.ok !== null ? String(fit.ok) : undefined"
        class="m-0 list-none outline-none"
        data-test="tree-item"
        @focus="ctx.focused(model)"
    >
        <div
            class="flex min-h-8 cursor-default flex-wrap items-center gap-x-2 gap-y-0.5 rounded-sm px-1 py-0.5 hover:bg-surface-muted [li:focus-visible>&]:ring-2 [li:focus-visible>&]:ring-accent-ink-strong [li[aria-selected=true]>&]:bg-accent-soft"
            :class="disabled ? 'text-text-muted' : ''"
            :style="{
                paddingInlineStart: `${(model.level - 1) * 1.25 + 0.25}rem`,
            }"
            data-test="tree-row"
            @click="ctx.activate(model)"
        >
            <span
                class="inline-flex size-5 shrink-0 items-center justify-center text-text-secondary"
                aria-hidden="true"
            >
                <template v-if="model.branch">
                    <ChevronDown v-if="expanded" class="size-4" />
                    <ChevronRight v-else class="size-4" />
                </template>
            </span>
            <span
                class="type-body-sm font-mono break-all"
                :class="disabled ? '' : 'text-text-primary'"
                data-test="tree-key"
                >{{ rowText(node) }}</span
            >
            <span
                class="type-caption shrink-0 rounded-sm border px-1.5"
                :class="typeTone(fit.type ?? node.type)"
                data-test="tree-type"
                >{{ type }}</span
            >
            <span
                v-if="leaf && node.example !== null"
                class="type-caption font-mono break-all text-text-muted"
                data-test="tree-example"
                >{{ node.example }}</span
            >
            <Button
                v-if="leaf && ctx.canUse() && fit.ok === true"
                type="button"
                variant="secondary"
                size="sm"
                tabindex="-1"
                :blocked="ctx.busy()"
                :aria-busy="ctx.busy() ? 'true' : undefined"
                :aria-label="labels.treeUseAria(node.display, ctx.slotLabel())"
                class="ms-auto"
                data-test="tree-use"
                @click.stop="ctx.use(model)"
                >{{ labels.treeUse }}</Button
            >
            <span
                v-if="disabled && fit.reason"
                :id="ctx.reasonId(node.id)"
                class="type-caption w-full ps-7 text-text-muted"
                data-test="tree-reason"
                >{{ fit.reason }}</span
            >
        </div>

        <ul
            v-if="model.children.length > 0"
            role="group"
            class="m-0 list-none p-0"
        >
            <FieldTreeItem
                v-for="child in model.children"
                :key="child.node.id"
                :model="child"
            />
        </ul>
    </li>
</template>
