<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DashboardGrid from '@/components/DashboardGrid.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { DashboardError, fetchDashboard } from '@/lib/dashboards';
import type { Dashboard } from '@/lib/dashboards';
import { SIGN_IN_URL } from '@/lib/session';
import { dashboardLabels as labels, shellPages } from '@/locales/labels';

// A member's dashboard (Story 4.4; UX-DR-236, 237): the shell appears at once with `{dashboard_id, revision}` and the layout is fetched from
// `GET /api/v1/dashboards/{id}`. A failed load shows `msg:dashboard-load-failed` with Retry and no partial or default layout, and saves nothing.
// The switcher, Date Range and Edit layout controls are in place; their behaviour arrives with Stories 4.11, 4.15 and 4.16.
// `dashboard_id` is null for a person with no active membership: there is nothing to load and the empty state shows.
const props = defineProps<{ dashboard_id: string | null; revision: number }>();

const { t } = useI18n();

const dashboard = ref<Dashboard | null>(null);
const state = ref<'loading' | 'ready' | 'error'>('loading');
let controller: AbortController | null = null;

const title = computed(
    () => dashboard.value?.name ?? shellPages.overview.title,
);
const items = computed(() => dashboard.value?.items ?? []);

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const mine = controller;

    if (props.dashboard_id === null) {
        dashboard.value = null;
        state.value = 'ready';

        return;
    }

    state.value = 'loading';

    try {
        const result = await fetchDashboard(props.dashboard_id, mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        dashboard.value = result;
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

        dashboard.value = null;
        state.value = 'error';
    }
}

onMounted(load);
onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader :title="title">
            <div class="hidden items-center gap-2 md:flex">
                <Button
                    type="button"
                    variant="secondary"
                    aria-disabled="true"
                    data-slot="dashboard-switcher"
                >
                    {{ labels.switcher }}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    aria-disabled="true"
                    data-slot="date-range"
                >
                    {{ labels.dateRange }}
                </Button>
                <Button
                    type="button"
                    variant="secondary"
                    aria-disabled="true"
                    data-slot="edit-layout"
                >
                    {{ labels.editLayout }}
                </Button>
            </div>
        </PageHeader>

        <div
            v-if="state === 'loading'"
            role="status"
            aria-busy="true"
            class="grid gap-[14px] md:grid-cols-4"
            data-slot="dashboard-loading"
        >
            <span class="sr-only">{{ labels.loading }}</span>
            <Skeleton v-for="n in 4" :key="n" class="h-[134px] rounded-lg" />
        </div>

        <div
            v-else-if="state === 'error'"
            role="alert"
            class="flex flex-col items-start gap-3 rounded-lg border border-border-default bg-surface-card p-4"
            data-slot="dashboard-error"
        >
            <p class="type-body text-text-primary">
                {{ t('dashboard-load-failed') }}
            </p>
            <Button type="button" variant="secondary" @click="load">
                {{ labels.retry }}
            </Button>
        </div>

        <div
            v-else-if="items.length === 0"
            class="flex flex-col items-start gap-3 rounded-lg border border-border-default bg-surface-card p-6"
            data-slot="dashboard-empty"
        >
            <p class="type-body text-text-primary">
                {{ t('dashboard-empty') }}
            </p>
            <Button type="button" aria-disabled="true">
                {{ labels.addBlock }}
            </Button>
        </div>

        <DashboardGrid v-else :items="items" />
    </div>
</template>
