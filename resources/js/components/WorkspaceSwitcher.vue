<script setup lang="ts">
import { ChevronRight, Check } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';
import Tag from '@/components/Tag.vue';
import UnsavedChangesDialog from '@/components/UnsavedChangesDialog.vue';
import { Input } from '@/components/ui/input';
import { useSidebar } from '@/components/ui/sidebar';
import { useInitials } from '@/composables/useInitials';
import { useShell } from '@/composables/useShell';
import { useWorkspaceSwitch } from '@/composables/useWorkspaceSwitch';
import { cn } from '@/lib/utils';
import { hasUnsavedWork, saveUnsavedForms } from '@/lib/unsavedForms';
import { shellLabels } from '@/locales/labels';
import { useToasts } from '@/stores/toasts';

// The sidebar's Workspace switcher (UX-DR-9, 81, 82, 141): a card with the avatar tile, name, descriptive
// label and a › chevron (the avatar tile only in the icon rail) that opens a popover of the Workspaces the
// person can use. The server verifies the target; this list is only a convenience. A person with one
// Workspace sees the card without the switching affordance.
const SEARCH_ABOVE = 7;

const { shell, roleLabel } = useShell();
const { getInitials } = useInitials();
const { isMobile, state, setOpenMobile } = useSidebar();

const workspace = computed(() => shell.value?.workspace ?? null);
const workspaces = computed(() => shell.value?.workspaces ?? []);
const canSwitch = computed(() => workspaces.value.length > 1);
const searchable = computed(() => workspaces.value.length > SEARCH_ABOVE);

const open = ref(false);
const query = ref('');
const pending = ref<string | null>(null);
const confirmOpen = ref(false);
const trigger = ref<HTMLElement | null>(null);

const visible = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return needle === ''
        ? workspaces.value
        : workspaces.value.filter((w) =>
              `${w.name} ${w.label ?? ''}`.toLowerCase().includes(needle),
          );
});

const { switchTo, switching } = useWorkspaceSwitch(
    () => shell.value?.switch_href ?? '/workspaces/switch',
    () => setOpenMobile(false),
);

function role(value: string): string {
    return value === 'admin' ? shellLabels.roleAdmin : shellLabels.roleUser;
}

function choose(id: string): void {
    open.value = false;
    query.value = '';

    if (id === workspace.value?.id) {
        return;
    }

    if (hasUnsavedWork()) {
        pending.value = id;
        confirmOpen.value = true;

        return;
    }

    switchTo(id);
}

function discard(): void {
    const id = pending.value;
    pending.value = null;

    if (id) {
        switchTo(id);
    }
}

async function save(): Promise<void> {
    const id = pending.value;
    pending.value = null;

    if (!id) {
        return;
    }

    if (await saveUnsavedForms()) {
        switchTo(id);
    } else {
        useToasts().add({ kind: 'error', message: shellLabels.switchFailed });
    }
}

// Any way of closing the dialog (Esc included) drops the pending choice.
watch(confirmOpen, (value) => {
    if (!value) {
        pending.value = null;
    }
});

function keep(): void {
    pending.value = null;
}

const tile =
    'type-caption inline-flex size-[30px] shrink-0 items-center justify-center rounded-md bg-brand font-bold text-on-accent';
const card =
    'flex w-full items-center gap-2 rounded-lg border border-border-default bg-surface-sunken p-2 text-left group-data-[collapsible=icon]:mx-auto group-data-[collapsible=icon]:size-10 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:p-1';
</script>

<template>
    <div
        v-if="workspace"
        data-slot="workspace-slot"
        class="mx-2 mt-3 group-data-[collapsible=icon]:mx-auto"
    >
        <PopoverRoot v-if="canSwitch" v-model:open="open">
            <PopoverTrigger as-child>
                <button
                    ref="trigger"
                    type="button"
                    data-slot="workspace-switcher"
                    data-test="workspace-switcher"
                    :aria-label="
                        shellLabels.switchWorkspaceCard(workspace.name)
                    "
                    aria-haspopup="dialog"
                    :disabled="switching"
                    :class="cn(card, 'cursor-pointer')"
                >
                    <span aria-hidden="true" :class="tile">{{
                        getInitials(workspace.name)
                    }}</span>
                    <span
                        class="grid min-w-0 flex-1 leading-tight group-data-[collapsible=icon]:hidden"
                    >
                        <span
                            class="type-title-sm truncate text-text-primary"
                            data-slot="workspace-name"
                            >{{ workspace.name }}</span
                        >
                        <span
                            v-if="workspace.label"
                            class="type-caption truncate text-text-muted"
                            data-slot="workspace-label"
                            >{{ workspace.label }}</span
                        >
                    </span>
                    <ChevronRight
                        class="size-4 shrink-0 text-text-muted group-data-[collapsible=icon]:hidden"
                        aria-hidden="true"
                    />
                </button>
            </PopoverTrigger>
            <PopoverPortal>
                <PopoverContent
                    data-slot="workspace-popover"
                    :side="
                        isMobile
                            ? 'bottom'
                            : state === 'collapsed'
                              ? 'right'
                              : 'bottom'
                    "
                    align="start"
                    :side-offset="6"
                    :aria-label="shellLabels.workspaceList"
                    class="z-50 w-72 max-w-[calc(100vw-2rem)] rounded-lg border border-border-default bg-surface-card p-2 text-text-primary shadow-[0_12px_40px_color-mix(in_srgb,var(--df-shadow-color)_12%,transparent)]"
                >
                    <Input
                        v-if="searchable"
                        v-model="query"
                        type="search"
                        class="mb-2"
                        data-test="workspace-search"
                        :aria-label="shellLabels.searchWorkspaces"
                        :placeholder="shellLabels.searchWorkspaces"
                    />
                    <ul
                        class="max-h-72 overflow-y-auto"
                        :aria-label="shellLabels.workspaceList"
                    >
                        <li v-for="item in visible" :key="item.id">
                            <button
                                type="button"
                                :disabled="switching"
                                data-test="workspace-option"
                                :aria-current="
                                    item.id === workspace.id
                                        ? 'true'
                                        : undefined
                                "
                                :class="
                                    cn(
                                        'type-body-sm flex min-h-(--df-target-chrome) w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1 text-left hover:bg-surface-sunken',
                                        item.id === workspace.id &&
                                            'listbox-option-selected',
                                    )
                                "
                                @click="choose(item.id)"
                            >
                                <span aria-hidden="true" :class="tile">{{
                                    getInitials(item.name)
                                }}</span>
                                <span class="grid min-w-0 flex-1 leading-tight">
                                    <span
                                        class="type-title-sm truncate text-text-primary"
                                        >{{ item.name }}</span
                                    >
                                    <span
                                        v-if="item.label"
                                        class="type-caption truncate text-text-muted"
                                        >{{ item.label }}</span
                                    >
                                </span>
                                <Tag>{{ role(item.role) }}</Tag>
                                <Check
                                    v-if="item.id === workspace.id"
                                    class="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span
                                    v-if="item.id === workspace.id"
                                    class="sr-only"
                                    >{{ shellLabels.currentWorkspace }}</span
                                >
                            </button>
                        </li>
                    </ul>
                    <p
                        v-if="visible.length === 0"
                        class="type-body-sm px-2 py-2 text-text-muted"
                        role="status"
                    >
                        {{ shellLabels.noWorkspaceMatch }}
                    </p>
                </PopoverContent>
            </PopoverPortal>
        </PopoverRoot>

        <div v-else data-slot="workspace-switcher" :class="card">
            <span aria-hidden="true" :class="tile">{{
                getInitials(workspace.name)
            }}</span>
            <span
                class="grid min-w-0 flex-1 leading-tight group-data-[collapsible=icon]:sr-only"
            >
                <span
                    class="type-title-sm truncate text-text-primary"
                    data-slot="workspace-name"
                    >{{ workspace.name }}</span
                >
                <span
                    v-if="workspace.label"
                    class="type-caption truncate text-text-muted"
                    data-slot="workspace-label"
                    >{{ workspace.label }}</span
                >
            </span>
        </div>

        <UnsavedChangesDialog
            v-model:open="confirmOpen"
            :save-label="shellLabels.switchSave"
            :invoker="trigger"
            @save="save"
            @discard="discard"
            @keep="keep"
        />
    </div>
</template>
