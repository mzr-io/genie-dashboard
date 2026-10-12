<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DashboardNameDialog from '@/components/DashboardNameDialog.vue';
import DataTable from '@/components/DataTable.vue';
import type { DataTableColumn } from '@/components/DataTable.vue';
import ListStates from '@/components/ListStates.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { announce } from '@/lib/announce';
import {
    DashboardError,
    createDashboard,
    dashboardPageUrl,
    deleteDashboard,
    duplicateDashboard,
    fetchDashboards,
    renameDashboard,
} from '@/lib/dashboards';
import type { DashboardSummary } from '@/lib/dashboards';
import { formatDateTime } from '@/lib/format';
import { SIGN_IN_URL } from '@/lib/session';
import { manageDashboardLabels as labels } from '@/locales/labels';
import { useToasts } from '@/stores/toasts';

// My dashboards (Story 4.16; UX-DR-84, 166): a data table of the member's own dashboards in the active Workspace with Rename, Duplicate and Delete
// in the row, and the generic list states (skeleton rows, a failure row with Retry). The Overview cannot be deleted: its Delete is disabled with
// `msg:overview-delete`, and the server refuses it too. There are no Template entry points here.
const { t } = useI18n();
const toasts = useToasts();

const rows = ref<DashboardSummary[]>([]);
const state = ref<'loading' | 'error' | 'ready'>('loading');
const busy = ref(false);

const naming = ref<{
    mode: 'create' | 'rename';
    row: DashboardSummary | null;
} | null>(null);
const nameOpen = ref(false);
const nameBusy = ref(false);
const nameRefused = ref(false);
const nameInvoker = ref<HTMLElement | null>(null);

const deleting = ref<DashboardSummary | null>(null);
const deleteOpen = ref(false);
const deleteInvoker = ref<HTMLElement | null>(null);

let controller: AbortController | null = null;
let inFlight = false;

const columns: DataTableColumn[] = [
    { key: 'name', label: labels.columns.name, rowHeader: true },
    { key: 'blocks', label: labels.columns.blocks, class: 'tabular-nums' },
    { key: 'updated', label: labels.columns.updated },
    { key: 'actions', label: labels.columns.actions },
];

function signedOut(error: unknown): boolean {
    if (
        error instanceof DashboardError &&
        (error.status === 401 || error.status === 419)
    ) {
        window.location.assign(SIGN_IN_URL);

        return true;
    }

    return false;
}

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const mine = controller;

    if (state.value !== 'ready') {
        state.value = 'loading';
    } else {
        busy.value = true;
    }

    try {
        const result = await fetchDashboards(mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        rows.value = result;
        state.value = 'ready';
        busy.value = false;
    } catch (error) {
        if (mine.signal.aborted || signedOut(error)) {
            return;
        }

        state.value = 'error';
        busy.value = false;
    }
}

function rowElement(id: string): HTMLElement | null {
    return (
        Array.from(
            document.querySelectorAll<HTMLElement>('[data-row-key]'),
        ).find((row) => row.dataset.rowKey === id) ?? null
    );
}

function invokerOf(event: Event): HTMLElement | null {
    return event.currentTarget instanceof HTMLElement
        ? event.currentTarget
        : null;
}

function askCreate(event: Event): void {
    nameInvoker.value = invokerOf(event);
    nameRefused.value = false;
    naming.value = { mode: 'create', row: null };
    nameOpen.value = true;
}

function askRename(row: DashboardSummary, event: Event): void {
    nameInvoker.value = invokerOf(event);
    nameRefused.value = false;
    naming.value = { mode: 'rename', row };
    nameOpen.value = true;
}

async function submitName(name: string): Promise<void> {
    const current = naming.value;

    if (current === null || nameBusy.value) {
        return;
    }

    nameBusy.value = true;

    try {
        const outcome =
            current.mode === 'create'
                ? await createDashboard(name)
                : await renameDashboard(current.row!.dashboard_id, name);

        if (outcome.ok) {
            nameOpen.value = false;

            if (current.mode === 'create') {
                // A new dashboard opens at once, empty.
                announce(labels.created(outcome.dashboard.name), 'polite');
                router.visit(dashboardPageUrl(outcome.dashboard));

                return;
            }

            announce(labels.renamed(outcome.dashboard.name), 'polite');
            await load();

            return;
        }

        if (outcome.invalid) {
            nameRefused.value = true;

            return;
        }

        if (outcome.status === 404) {
            nameOpen.value = false;
            toasts.add({ kind: 'error', message: labels.gone });
            await load();

            return;
        }

        toasts.add({ kind: 'error', message: labels.failed });
    } catch (error) {
        if (!signedOut(error)) {
            toasts.add({ kind: 'error', message: labels.failed });
        }
    } finally {
        nameBusy.value = false;
    }
}

async function duplicate(row: DashboardSummary): Promise<void> {
    if (inFlight) {
        return;
    }

    inFlight = true;

    try {
        const outcome = await duplicateDashboard(row.dashboard_id);

        if (!outcome.ok) {
            toasts.add({
                kind: 'error',
                message: outcome.status === 404 ? labels.gone : labels.failed,
            });

            if (outcome.status === 404) {
                await load();
            }

            return;
        }

        const message =
            outcome.omitted > 0
                ? labels.duplicatedOmitted(
                      outcome.dashboard.name,
                      outcome.omitted,
                  )
                : labels.duplicated(outcome.dashboard.name);

        announce(message, 'polite');
        toasts.add({ kind: 'success', message });
        await load();
    } catch (error) {
        if (!signedOut(error)) {
            toasts.add({ kind: 'error', message: labels.failed });
        }
    } finally {
        inFlight = false;
    }
}

function askDelete(row: DashboardSummary, event: Event): void {
    if (row.is_overview) {
        announce(t('overview-delete', { dashboard: row.name }), 'polite');

        return;
    }

    deleteInvoker.value = invokerOf(event);
    deleting.value = row;
    deleteOpen.value = true;
}

async function confirmDelete(): Promise<void> {
    const row = deleting.value;

    if (row === null || inFlight) {
        return;
    }

    inFlight = true;

    try {
        const outcome = await deleteDashboard(row.dashboard_id);

        if (!outcome.ok && !outcome.overview && outcome.status !== 404) {
            toasts.add({ kind: 'error', message: labels.failed });

            return;
        }

        if (outcome.ok) {
            announce(labels.deleted(row.name), 'polite');
        } else {
            toasts.add({ kind: 'error', message: labels.gone });
        }

        await load();
        await nextTick();
        document.querySelector<HTMLElement>('[data-test=create]')?.focus();
    } catch (error) {
        if (!signedOut(error)) {
            toasts.add({ kind: 'error', message: labels.failed });
        }
    } finally {
        inFlight = false;
    }
}

onMounted(() => {
    void load();
});

onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <Head :title="labels.pageTitle" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader :title="labels.pageTitle" :subtitle="labels.pageSubtitle">
            <Button
                v-if="state === 'ready'"
                type="button"
                data-test="create"
                @click="askCreate"
            >
                {{ labels.newDashboard }}
            </Button>
        </PageHeader>

        <ListStates
            v-if="state === 'loading'"
            state="loading"
            :items="labels.items"
            :action="labels.action"
            :toolbar="false"
        />

        <ListStates
            v-else-if="state === 'error'"
            state="error"
            :items="labels.items"
            :action="labels.action"
            @retry="load"
        />

        <ListStates
            v-else-if="rows.length === 0"
            state="empty"
            :items="labels.items"
            :action="labels.action"
        />

        <DataTable
            v-else
            :caption="labels.caption"
            :label="labels.tableRegion"
            :columns="columns"
            :rows="rows"
            :row-key="(row: DashboardSummary) => row.dashboard_id"
            :busy="busy"
        >
            <template #cell-name="{ row }">
                <Link
                    :href="dashboardPageUrl(row)"
                    :aria-label="labels.open(row.name)"
                    class="font-medium text-text-primary underline underline-offset-4 hover:text-accent-ink-strong"
                    data-test="open-link"
                    >{{ row.name }}</Link
                >
            </template>
            <template #cell-blocks="{ row }">
                {{ labels.blocks(row.item_count) }}
            </template>
            <template #cell-updated="{ row }">
                {{ formatDateTime(row.updated_at) }}
            </template>
            <template #cell-actions="{ row }">
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :aria-label="labels.renameAria(row.name)"
                        data-test="rename"
                        @click="askRename(row, $event)"
                    >
                        {{ labels.rename }}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :aria-label="labels.duplicateAria(row.name)"
                        data-test="duplicate"
                        @click="duplicate(row)"
                    >
                        {{ labels.duplicate }}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive-soft"
                        size="sm"
                        :aria-label="labels.deleteAria(row.name)"
                        :aria-disabled="row.is_overview ? 'true' : undefined"
                        :title="
                            row.is_overview
                                ? t('overview-delete', { dashboard: row.name })
                                : undefined
                        "
                        data-test="delete"
                        @click="askDelete(row, $event)"
                    >
                        {{ labels.delete }}
                    </Button>
                </div>
            </template>
        </DataTable>

        <DashboardNameDialog
            v-if="naming"
            v-model:open="nameOpen"
            :mode="naming.mode"
            :name="naming.row?.name"
            :busy="nameBusy"
            :refused="nameRefused"
            :invoker="nameInvoker"
            @submit="submitName"
        />

        <ConfirmDialog
            v-if="deleting"
            v-model:open="deleteOpen"
            :title="labels.deleteTitle(deleting.name)"
            :description="labels.deleteImpact"
            :object-name="deleting.name"
            :verb="labels.delete"
            :invoker="deleteInvoker"
            :fallback="
                () => (deleting ? rowElement(deleting.dashboard_id) : null)
            "
            @confirm="confirmDelete"
        />
    </div>
</template>
