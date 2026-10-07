<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { nextTick, onBeforeUnmount, onMounted, ref, useId } from 'vue';
import Tag from '@/components/Tag.vue';
import { inviteLabels as labels } from '@/locales/labels';

// The role menu (UX-DR-143, 144): a menu button that opens a 320px popover; each item is a `menuitemradio` showing
// a role chip and a one-line description. The chosen item carries a leading check (`listbox-option-selected`) as well as `aria-checked`. Arrow keys,
// Home and End move between items, Escape closes and returns focus to the button, Tab closes. The id and ARIA
// attributes of the surrounding `FormField` go on the button.
defineOptions({ inheritAttrs: false });

type Role = 'user' | 'admin';

const model = defineModel<Role>({ required: true });
const roles: Role[] = ['user', 'admin'];

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const button = ref<HTMLButtonElement | null>(null);
const menuId = useId();

function items(): HTMLElement[] {
    return Array.from(
        root.value?.querySelectorAll<HTMLElement>('[role="menuitemradio"]') ??
            [],
    );
}

async function show(focus: 'selected' | 'first' | 'last' = 'selected') {
    open.value = true;
    await nextTick();

    const all = items();
    const target =
        focus === 'first'
            ? all[0]
            : focus === 'last'
              ? all[all.length - 1]
              : (all.find(
                    (item) => item.getAttribute('aria-checked') === 'true',
                ) ?? all[0]);

    target?.focus();
}

function close(returnFocus = false) {
    open.value = false;

    if (returnFocus) {
        button.value?.focus();
    }
}

function choose(role: Role) {
    model.value = role;
    close(true);
}

function onButtonKey(event: KeyboardEvent) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        void show('first');
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        void show('last');
    }
}

function onMenuKey(event: KeyboardEvent) {
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

function onOutside(event: Event) {
    if (open.value && !root.value?.contains(event.target as Node)) {
        close();
    }
}

onMounted(() => document.addEventListener('pointerdown', onOutside));
onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside));
</script>

<template>
    <div ref="root" class="relative w-fit" data-slot="role-menu">
        <button
            v-bind="$attrs"
            ref="button"
            type="button"
            aria-haspopup="menu"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="open ? menuId : undefined"
            data-test="role-button"
            class="type-body-sm inline-flex min-h-9 min-w-40 items-center justify-between gap-2 rounded-md border border-border-control bg-surface-card px-3 py-1 text-text-primary focus:border-brand"
            @click="open ? close() : show()"
            @keydown="onButtonKey"
        >
            <span class="sr-only">{{
                labels.roleMenuLabel(labels.roles[model])
            }}</span>
            <span aria-hidden="true">{{ labels.roles[model] }}</span>
            <ChevronDown class="size-4" aria-hidden="true" />
        </button>

        <div
            v-if="open"
            :id="menuId"
            role="menu"
            :aria-label="labels.role"
            data-slot="role-menu-popover"
            class="absolute start-0 z-20 mt-1 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-1 rounded-lg border border-border-default bg-surface-card p-1 shadow-lg"
            @keydown="onMenuKey"
        >
            <button
                v-for="role in roles"
                :key="role"
                type="button"
                role="menuitemradio"
                :aria-checked="model === role ? 'true' : 'false'"
                :data-role="role"
                :tabindex="-1"
                class="flex w-full items-start gap-2 rounded-sm px-2 py-2 text-start hover:bg-surface-sunken focus-visible:bg-(--df-accent-soft) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--df-focus-ring)"
                :class="model === role ? 'listbox-option-selected' : 'ps-6'"
                @click="choose(role)"
            >
                <span class="flex min-w-0 flex-col gap-1">
                    <Tag :tone="role === 'admin' ? 'info' : 'neutral'">{{
                        labels.roles[role]
                    }}</Tag>
                    <span class="type-caption text-text-secondary">{{
                        labels.roleDescriptions[role]
                    }}</span>
                </span>
            </button>
        </div>
    </div>
</template>
