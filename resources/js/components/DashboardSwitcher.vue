<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, ChevronDown, LoaderCircle } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { onBeforeUnmount, ref, watch } from 'vue';
import DashboardNameDialog from '@/components/DashboardNameDialog.vue';
import { Button } from '@/components/ui/button';
import { announce } from '@/lib/announce';
import {
    DashboardError,
    createDashboard,
    fetchDashboards,
} from '@/lib/dashboards';
import type { DashboardSummary } from '@/lib/dashboards';
import { SIGN_IN_URL } from '@/lib/session';
import { manageDashboardLabels as labels } from '@/locales/labels';
import { useToasts } from '@/stores/toasts';

// The dashboard switcher (Story 4.16; UX-DR-84, 163): a button with the current dashboard's name that opens a popover listing Overview first, then
// My dashboards in order with a check on the current one, "+ New dashboard" and "Manage dashboards". Choosing one tells the page to load it in
// place (the page closes the Add-blocks Panel). The list is read when the popover opens, so it is never older than that.
const props = defineProps<{
    currentId: string | null;
    currentName: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{ switch: [dashboard: DashboardSummary] }>();

const toasts = useToasts();
const open = ref(false);
const state = ref<'idle' | 'loading' | 'ready' | 'error'>('idle');
const list = ref<DashboardSummary[]>([]);
const creating = ref(false);
const createBusy = ref(false);
const createRefused = ref(false);
const trigger = ref<{ $el?: HTMLElement } | null>(null);
let controller: AbortController | null = null;

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const mine = controller;
    state.value = 'loading';

    try {
        const result = await fetchDashboards(mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        list.value = result;
        state.value = 'ready';
    } catch (error) {
        if (mine.signal.aborted) {
            return;
        }

        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        state.value = 'error';
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        void load();
    }
});

function choose(dashboard: DashboardSummary): void {
    open.value = false;

    if (dashboard.dashboard_id === props.currentId) {
        return;
    }

    emit('switch', dashboard);
}

function startCreate(): void {
    createRefused.value = false;
    open.value = false;
    creating.value = true;
}

async function submitCreate(name: string): Promise<void> {
    if (createBusy.value) {
        return;
    }

    createBusy.value = true;

    try {
        const outcome = await createDashboard(name);

        if (outcome.ok) {
            creating.value = false;
            announce(labels.created(outcome.dashboard.name), 'polite');
            emit('switch', outcome.dashboard);

            return;
        }

        if (outcome.invalid) {
            createRefused.value = true;

            return;
        }

        toasts.add({ kind: 'error', message: labels.failed });
    } catch (error) {
        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }

        toasts.add({ kind: 'error', message: labels.failed });
    } finally {
        createBusy.value = false;
    }
}

onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                ref="trigger"
                type="button"
                variant="secondary"
                :aria-disabled="disabled ? 'true' : undefined"
                :aria-label="labels.switcher(currentName)"
                data-slot="dashboard-switcher"
                @click="(e: Event) => disabled && e.preventDefault()"
            >
                <span class="max-w-40 truncate">{{ currentName }}</span>
                <ChevronDown class="size-4" aria-hidden="true" />
            </Button>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="6"
                class="z-50 flex w-72 max-w-[calc(100vw-2rem)] flex-col gap-1 rounded-lg border border-border-default bg-surface-card p-2 shadow-lg"
                data-slot="dashboard-switcher-popover"
            >
                <p
                    v-if="state === 'loading'"
                    class="type-body-sm flex items-center gap-2 px-3 py-2 text-text-secondary"
                    role="status"
                >
                    <LoaderCircle
                        class="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    {{ labels.switcherTitle }}
                </p>
                <div
                    v-else-if="state === 'error'"
                    class="flex flex-col gap-2 px-3 py-2"
                    role="alert"
                    data-slot="switcher-failed"
                >
                    <p class="type-body-sm text-text-secondary">
                        {{ labels.loadFailed }}
                    </p>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        @click="load"
                    >
                        {{ labels.retry }}
                    </Button>
                </div>
                <ul
                    v-else
                    :aria-label="labels.switcherTitle"
                    class="flex flex-col gap-0.5"
                    data-slot="switcher-list"
                >
                    <li v-for="dashboard in list" :key="dashboard.dashboard_id">
                        <button
                            type="button"
                            class="type-body-sm flex w-full items-center gap-2 rounded-md px-3 py-1.5 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="
                                dashboard.dashboard_id === currentId
                                    ? 'bg-accent-soft font-semibold text-accent-ink'
                                    : 'text-text-primary hover:bg-surface-muted'
                            "
                            :aria-current="
                                dashboard.dashboard_id === currentId
                                    ? 'true'
                                    : undefined
                            "
                            :data-dashboard="dashboard.dashboard_id"
                            @click="choose(dashboard)"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                dashboard.name
                            }}</span>
                            <Check
                                v-if="dashboard.dashboard_id === currentId"
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span
                                v-if="dashboard.dashboard_id === currentId"
                                class="sr-only"
                                >{{ labels.current }}</span
                            >
                        </button>
                    </li>
                </ul>
                <div
                    class="mt-1 flex flex-col gap-0.5 border-t border-border-default pt-1"
                >
                    <button
                        type="button"
                        class="type-body-sm w-full rounded-md px-3 py-1.5 text-left text-text-primary hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        data-slot="new-dashboard"
                        @click="startCreate"
                    >
                        {{ labels.newDashboard }}
                    </button>
                    <Link
                        href="/dashboards"
                        class="type-body-sm w-full rounded-md px-3 py-1.5 text-left text-text-primary hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        data-slot="manage-dashboards"
                        @click="open = false"
                    >
                        {{ labels.manage }}
                    </Link>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>

    <DashboardNameDialog
        v-model:open="creating"
        mode="create"
        :busy="createBusy"
        :refused="createRefused"
        :invoker="trigger?.$el ?? null"
        @submit="submitCreate"
    />
</template>
