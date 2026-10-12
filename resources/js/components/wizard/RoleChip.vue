<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { MAPPING_ROLES, ROLE_TONES } from '@/lib/blockMapping';
import type { MappingRole } from '@/lib/blockMapping';
import { blockMapLabels as labels } from '@/locales/labels';

// The role chip of Map Data (UX-DR-180 to 182): a menu button named "Role for {field}: {role}" (", possible wrong role" when flagged) that opens a
// menu of the six roles as `menuitemradio` items, each with a one-line description that its item points to (`aria-describedby`), so it is there on
// focus and not only on hover. Arrow keys, Home and End move between items, Escape closes and returns focus to the chip, Tab closes. A role is never
// shown as a colour alone: the chip always carries its name, and a flagged chip adds a "!" glyph that is hidden from assistive technology.
const props = defineProps<{
    field: string;
    path: string;
    role: MappingRole;
    flagged?: boolean;
    // Why the chip cannot be used now (read-only, offline); the chip is disabled and `reasonId` points at the text that says why.
    disabled?: boolean;
    reasonId?: string;
}>();

const emit = defineEmits<{ (e: 'change', role: MappingRole): void }>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const button = ref<HTMLButtonElement | null>(null);
const menuId = useId();
const descriptionIds = Object.fromEntries(
    MAPPING_ROLES.map((role) => [role, useId()]),
) as Record<MappingRole, string>;

function items(): HTMLElement[] {
    return Array.from(
        root.value?.querySelectorAll<HTMLElement>('[role="menuitemradio"]') ??
            [],
    );
}

async function show(focus: 'selected' | 'first' | 'last' = 'selected') {
    if (props.disabled) {
        return;
    }

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

// A role the labels do not know (a newer server) shows as its raw name, in the neutral tone.
const nameOf = (role: string): string => labels.roleNames[role] ?? role;
const toneOf = (role: string): string =>
    ROLE_TONES[role as MappingRole] ?? ROLE_TONES.label;

// A chip that becomes disabled (read-only, offline) closes its menu.
watch(
    () => props.disabled,
    (now) => {
        if (now) {
            close();
        }
    },
);

function choose(role: MappingRole) {
    if (props.disabled) {
        close();

        return;
    }

    if (role !== props.role) {
        emit('change', role);
    }

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
        all[index < 0 ? 0 : (index + 1) % all.length]?.focus();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        all[
            index < 0 ? all.length - 1 : (index - 1 + all.length) % all.length
        ]?.focus();
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

defineExpose({ focus: () => button.value?.focus() });
</script>

<template>
    <div ref="root" class="relative w-fit" data-slot="role-chip">
        <button
            ref="button"
            type="button"
            aria-haspopup="menu"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="open ? menuId : undefined"
            :aria-label="labels.chipName(field, nameOf(role), !!flagged)"
            :aria-describedby="disabled ? reasonId : undefined"
            :disabled="disabled"
            :data-field-path="path"
            :data-role="role"
            :data-flagged="flagged ? 'true' : undefined"
            data-test="role-chip"
            class="type-caption inline-flex min-h-6 items-center gap-1 rounded-sm border px-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--df-focus-ring) disabled:cursor-not-allowed disabled:opacity-60"
            :class="toneOf(role)"
            @click="open ? close() : show()"
            @keydown="onButtonKey"
        >
            <span
                v-if="flagged"
                class="font-semibold text-error-text"
                aria-hidden="true"
                data-test="role-flag"
                >!</span
            >
            <span aria-hidden="true">{{ nameOf(role) }}</span>
            <ChevronDown class="size-3" aria-hidden="true" />
        </button>

        <div
            v-if="open"
            :id="menuId"
            role="menu"
            :aria-label="labels.chipMenu(field)"
            data-slot="role-chip-menu"
            class="absolute start-0 z-20 mt-1 flex w-72 max-w-[calc(100vw-2rem)] flex-col gap-1 rounded-lg border border-border-default bg-surface-card p-1 shadow-lg"
            @keydown="onMenuKey"
        >
            <button
                v-for="option in MAPPING_ROLES"
                :key="option"
                type="button"
                role="menuitemradio"
                :aria-checked="role === option ? 'true' : 'false'"
                :aria-describedby="descriptionIds[option]"
                :data-role-option="option"
                tabindex="-1"
                class="flex w-full items-start gap-2 rounded-sm px-2 py-2 text-start hover:bg-surface-sunken focus-visible:bg-(--df-accent-soft) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--df-focus-ring)"
                :class="role === option ? 'listbox-option-selected' : 'ps-6'"
                @click="choose(option)"
            >
                <span class="flex min-w-0 flex-col gap-1">
                    <span
                        class="type-caption w-fit rounded-sm border px-2"
                        :class="ROLE_TONES[option]"
                        >{{ labels.roleNames[option] }}</span
                    >
                    <span
                        :id="descriptionIds[option]"
                        class="type-caption text-text-secondary"
                        >{{ labels.roleDescriptions[option] }}</span
                    >
                </span>
            </button>
        </div>
    </div>
</template>
