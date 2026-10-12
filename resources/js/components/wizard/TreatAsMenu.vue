<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { Button } from '@/components/ui/button';
import { FIELD_TYPES } from '@/lib/blockMapping';
import type { FieldType } from '@/lib/blockMapping';
import { blockMapLabels as labels } from '@/locales/labels';

// "Treat as …" (Story 3.13b): a menu button of the four types a field can be read as (text, number, boolean, date). Arrow keys, Home and End
// move between items, Enter or Space picks, Escape closes and returns focus to the button, Tab closes.
const props = defineProps<{
    field: string;
    // The field's effective type now (marked in the menu); null when unknown.
    current?: string | null;
}>();
const emit = defineEmits<{ (e: 'pick', type: FieldType | null): void }>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const button = ref<InstanceType<typeof Button> | null>(null);
const menuId = useId();

function items(): HTMLElement[] {
    return Array.from(
        root.value?.querySelectorAll<HTMLElement>('[role="menuitemradio"]') ??
            [],
    );
}

async function show(last = false): Promise<void> {
    open.value = true;
    await nextTick();

    const all = items();

    (last ? all[all.length - 1] : all[0])?.focus();
}

function close(returnFocus = false): void {
    open.value = false;

    if (returnFocus) {
        (button.value?.$el as HTMLElement | undefined)?.focus();
    }
}

function choose(type: FieldType | null): void {
    close(true);
    emit('pick', type);
}

function onButtonKey(event: KeyboardEvent): void {
    if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        event.stopPropagation();
        close(true);
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        void show();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        void show(true);
    }
}

function onMenuKey(event: KeyboardEvent): void {
    const all = items();
    const index = all.indexOf(document.activeElement as HTMLElement);

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        all[(index + 1) % all.length]?.focus();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        all[(index - 1 + all.length) % all.length]?.focus();
    } else if (event.key === 'Home') {
        event.preventDefault();
        all[0]?.focus();
    } else if (event.key === 'End') {
        event.preventDefault();
        all[all.length - 1]?.focus();
    } else if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close(true);
    } else if (event.key === 'Tab') {
        close();
    }
}

// Focus moving to something outside the menu closes it.
function onFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget as Node | null;

    if (open.value && next !== null && !root.value?.contains(next)) {
        close();
    }
}

function onOutside(event: Event): void {
    if (open.value && !root.value?.contains(event.target as Node)) {
        close();
    }
}

onMounted(() => document.addEventListener('pointerdown', onOutside));
onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside));
</script>

<template>
    <div
        ref="root"
        class="relative w-fit"
        data-test="treat-as"
        @focusout="onFocusOut"
    >
        <Button
            ref="button"
            type="button"
            variant="secondary"
            size="sm"
            aria-haspopup="menu"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="open ? menuId : undefined"
            :aria-label="labels.treatAsFor(props.field)"
            data-test="treat-as-button"
            @click="open ? close() : show()"
            @keydown="onButtonKey"
        >
            {{ labels.treatAs }}
            <ChevronDown class="size-3" aria-hidden="true" />
        </Button>

        <div
            v-if="open"
            :id="menuId"
            role="menu"
            :aria-label="labels.treatAsMenu(props.field)"
            class="absolute start-0 z-20 mt-1 flex w-48 flex-col gap-0.5 rounded-lg border border-border-default bg-surface-card p-1 shadow-lg"
            data-test="treat-as-menu"
            @keydown="onMenuKey"
        >
            <button
                v-for="type in [...FIELD_TYPES, null]"
                :key="type ?? 'auto'"
                type="button"
                role="menuitemradio"
                tabindex="-1"
                :aria-checked="
                    (type === null ? false : type === current)
                        ? 'true'
                        : 'false'
                "
                :data-type="type ?? 'auto'"
                class="type-body-sm w-full rounded-sm px-2 py-2 text-start hover:bg-surface-sunken focus-visible:bg-(--df-accent-soft) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--df-focus-ring)"
                :class="
                    type !== null && type === current
                        ? 'listbox-option-selected'
                        : 'ps-6'
                "
                @click="choose(type)"
            >
                {{
                    type === null
                        ? labels.typeAutomatic
                        : labels.typeNames[type]
                }}
            </button>
        </div>
    </div>
</template>
