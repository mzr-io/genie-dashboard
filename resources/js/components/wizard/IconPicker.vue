<script setup lang="ts">
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { BLOCK_ICONS, iconFor } from '@/lib/blockIcons';
import { blockWizardLabels as labels } from '@/locales/labels';

// The Icon field (Story 3.4): the icon in use, "Change" opening a 320 px popover with a search and a six-column grid of 28 px tiles, and
// "Use category icon" to drop the block's own choice. `modelValue` is the icon key chosen for the block, or null for the Category's.
const props = defineProps<{
    modelValue: string | null;
    // The icon key the Category supplies, and its name.
    categoryKey: string;
    categoryName: string;
    id: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | null): void;
}>();

const open = ref(false);
const query = ref('');
const search = ref<InstanceType<typeof Input> | null>(null);

const shownKey = computed(() => props.modelValue ?? props.categoryKey);
const Shown = computed(() => iconFor(shownKey.value));
const source = computed(() =>
    props.modelValue === null
        ? labels.iconFromCategory(props.categoryName)
        : labels.iconChosen,
);

const matches = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return Object.entries(BLOCK_ICONS).filter(
        ([key, { label }]) =>
            needle === '' ||
            key.includes(needle) ||
            label.toLowerCase().includes(needle),
    );
});

function choose(key: string): void {
    emit('update:modelValue', key);
    open.value = false;
}

async function onOpen(value: boolean): Promise<void> {
    open.value = value;

    if (value) {
        query.value = '';
        await nextTick();
        (search.value?.$el as HTMLElement | undefined)?.focus();
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-3" data-slot="icon-picker">
        <span
            aria-hidden="true"
            class="inline-flex size-9 items-center justify-center rounded-md border border-border-default bg-accent-soft text-accent-ink-strong"
            data-test="icon-current"
            ><component :is="Shown" class="size-5"
        /></span>
        <div class="grid">
            <span class="type-body-sm text-text-primary">{{
                BLOCK_ICONS[shownKey]?.label ?? shownKey
            }}</span>
            <span class="type-caption text-text-muted">{{ source }}</span>
        </div>
        <PopoverRoot :open="open" @update:open="onOpen">
            <PopoverTrigger as-child>
                <Button
                    :id="id"
                    type="button"
                    variant="secondary"
                    size="sm"
                    data-test="icon-change"
                    >{{ labels.iconChange }}</Button
                >
            </PopoverTrigger>
            <PopoverPortal>
                <PopoverContent
                    side="bottom"
                    align="start"
                    :side-offset="6"
                    :aria-label="labels.iconPopover"
                    data-slot="icon-popover"
                    class="z-50 w-80 rounded-lg border border-border-default bg-surface-card p-3 shadow-md"
                >
                    <label class="sr-only" :for="`${id}-search`">{{
                        labels.iconSearch
                    }}</label>
                    <Input
                        :id="`${id}-search`"
                        ref="search"
                        v-model="query"
                        type="search"
                        autocomplete="off"
                        :placeholder="labels.iconSearch"
                        data-test="icon-search"
                    />
                    <ul
                        v-if="matches.length > 0"
                        class="mt-3 grid grid-cols-6 gap-1"
                        data-test="icon-grid"
                    >
                        <li v-for="[key, entry] in matches" :key="key">
                            <button
                                type="button"
                                :aria-label="labels.iconTile(entry.label)"
                                :aria-pressed="modelValue === key"
                                :title="entry.label"
                                class="inline-flex size-7 items-center justify-center rounded-md border border-transparent text-text-secondary hover:bg-surface-sunken aria-pressed:border-accent-ink-strong aria-pressed:bg-accent-soft aria-pressed:text-accent-ink-strong"
                                data-test="icon-tile"
                                @click="choose(key)"
                            >
                                <component
                                    :is="entry.icon"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </button>
                        </li>
                    </ul>
                    <p
                        v-else
                        class="type-caption mt-3 text-text-muted"
                        data-test="icon-none"
                    >
                        {{ labels.iconNone }}
                    </p>
                </PopoverContent>
            </PopoverPortal>
        </PopoverRoot>
        <Button
            v-if="modelValue !== null"
            type="button"
            variant="link"
            data-test="icon-reset"
            @click="emit('update:modelValue', null)"
            >{{ labels.iconUseCategory }}</Button
        >
    </div>
</template>
