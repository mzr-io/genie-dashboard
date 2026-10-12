<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DashboardGrid from '@/components/DashboardGrid.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { announce, announceAggregated } from '@/lib/announce';
import {
    DashboardError,
    fetchDashboard,
    fetchResults,
    refreshItem,
    setMinimized,
} from '@/lib/dashboards';
import type { Dashboard, ItemResult } from '@/lib/dashboards';
import { formatDateTime } from '@/lib/format';
import { SIGN_IN_URL } from '@/lib/session';
import { dashboardLabels as labels, shellPages } from '@/locales/labels';

// A member's dashboard (Story 4.4; UX-DR-236, 237): the shell appears at once with `{dashboard_id, revision}` and the layout is fetched from
// `GET /api/v1/dashboards/{id}`. A failed load shows `msg:dashboard-load-failed` with Retry and no partial or default layout, and saves nothing.
// The switcher, Date Range and Edit layout controls are in place; their behaviour arrives with Stories 4.11, 4.15 and 4.16.
// `dashboard_id` is null for a person with no active membership: there is nothing to load and the empty state shows.
const props = defineProps<{ dashboard_id: string | null; revision: number }>();

const { t } = useI18n();
const page = usePage();

// Admins also see technical details in About this block; the footer's `{user.*}` tokens come from the signed-in profile.
const admin = computed(
    () =>
        (page.props.shell as { role?: string | null } | null)?.role === 'admin',
);
const profile = computed(() => {
    const user = (
        page.props.auth as
            | { user?: { name?: string; email?: string } }
            | undefined
    )?.user;

    return { name: user?.name ?? null, email: user?.email ?? null };
});

const dashboard = ref<Dashboard | null>(null);
// The member's own minimize choices and the revision every layout command carries (AD-14), the Blocks being refreshed and those in their window.
const minimized = ref<Record<string, boolean>>({});
const revision = ref(props.revision);
const refreshing = ref<string[]>([]);
const limited = ref<string[]>([]);
// The latest result of each item, kept in memory by item id and replaced only when its `result_etag` changes; a pending answer never replaces data.
const results = ref<Record<string, ItemResult>>({});
let resultsTimer: ReturnType<typeof setTimeout> | null = null;
let resultsController: AbortController | null = null;
let polls = 0;
let announcedStale = 0;

// Pending results are asked for again, a bounded number of times, until they arrive; live updates (Story 4.13) replace this.
const POLL_MS = 3000;
const MAX_POLLS = 20;
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
        revision.value = result.revision;
        minimized.value = Object.fromEntries(
            result.items.map((item) => [item.item_id, item.minimized]),
        );
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

function applyResults(items: ItemResult[]): boolean {
    let pending = false;
    const next = { ...results.value };

    for (const item of items) {
        const known = next[item.item_id];

        if (item.state === 'pending') {
            pending = true;

            if (known === undefined) {
                next[item.item_id] = item;
            }

            continue;
        }

        if (
            known === undefined ||
            known.result_etag !== item.result_etag ||
            known.state !== item.state ||
            known.stale !== item.stale
        ) {
            next[item.item_id] = item;
        }
    }

    results.value = next;

    return pending;
}

// Stale blocks are announced once, together, about two seconds after the change; recovery says so once.
function announceStale(): void {
    const stale = Object.values(results.value).filter((r) => r.stale);

    if (stale.length === announcedStale) {
        return;
    }

    if (stale.length === 0) {
        announce(t('stale-aggregate.recovered'));
    } else {
        const latest = stale
            .map((r) => r.last_success_at)
            .filter((at): at is string => at !== null)
            .sort()
            .at(-1);

        announceAggregated(
            'stale-aggregate',
            t(
                'stale-aggregate.announce',
                {
                    count: stale.length,
                    time:
                        latest === undefined
                            ? ''
                            : formatDateTime(latest, { timeStyle: 'short' }),
                },
                stale.length,
            ),
        );
    }

    announcedStale = stale.length;
}

async function loadResults(): Promise<void> {
    if (props.dashboard_id === null) {
        return;
    }

    resultsController?.abort();
    resultsController = new AbortController();
    const mine = resultsController;

    if (resultsTimer !== null) {
        clearTimeout(resultsTimer);
        resultsTimer = null;
    }

    try {
        const items = await fetchResults(props.dashboard_id, null, mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        const pending = applyResults(items);
        announceStale();
        polls = pending ? polls + 1 : 0;

        if (pending && polls < MAX_POLLS) {
            resultsTimer = setTimeout(loadResults, POLL_MS);
        }
    } catch (error) {
        if (mine.signal.aborted) {
            return;
        }

        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);
        }

        // Any other failure leaves what is shown as it is: a Block with a result keeps it, one without keeps its skeleton.
    }
}

// Minimize or Expand: `SetMinimized` with the revision held. A stale revision reloads the layout and tries once more; a second refusal leaves the
// Block as it was. The change is shown at once and undone when it fails.
async function onMinimize(itemId: string, value: boolean): Promise<void> {
    if (props.dashboard_id === null) {
        return;
    }

    const before = minimized.value[itemId];
    minimized.value = { ...minimized.value, [itemId]: value };

    try {
        let outcome = await setMinimized(
            props.dashboard_id,
            itemId,
            value,
            revision.value,
        );

        if (!outcome.ok && outcome.conflict) {
            await load();

            outcome = await setMinimized(
                props.dashboard_id,
                itemId,
                value,
                revision.value,
            );
        }

        if (outcome.ok) {
            revision.value = outcome.revision;
            minimized.value = { ...minimized.value, [itemId]: value };

            return;
        }
    } catch (error) {
        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);

            return;
        }
    }

    minimized.value = { ...minimized.value, [itemId]: before ?? !value };
}

// Refresh this Block only: it asks for its data to be fetched at the next tick and re-reads the results. Inside the window the control is
// `aria-disabled` and described by `msg:refresh-limited` until it ends.
async function onRefresh(itemId: string): Promise<void> {
    if (props.dashboard_id === null || refreshing.value.includes(itemId)) {
        return;
    }

    refreshing.value = [...refreshing.value, itemId];

    try {
        const outcome = await refreshItem(props.dashboard_id, itemId);

        if (outcome.status === 'limited') {
            limited.value = [...limited.value, itemId];
            setTimeout(() => {
                limited.value = limited.value.filter((id) => id !== itemId);
            }, outcome.retryAfter * 1000);
        } else if (outcome.status === 'requested') {
            polls = 0;
            await loadResults();
        }
    } catch (error) {
        if (
            error instanceof DashboardError &&
            (error.status === 401 || error.status === 419)
        ) {
            window.location.assign(SIGN_IN_URL);
        }
    } finally {
        refreshing.value = refreshing.value.filter((id) => id !== itemId);
    }
}

onMounted(async () => {
    await load();

    if (state.value === 'ready' && items.value.length > 0) {
        await loadResults();
    }
});

onBeforeUnmount(() => {
    controller?.abort();
    resultsController?.abort();

    if (resultsTimer !== null) {
        clearTimeout(resultsTimer);
    }
});
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

        <DashboardGrid
            v-else
            :items="items"
            :results="results"
            :minimized="minimized"
            :refreshing="refreshing"
            :limited="limited"
            :admin="admin"
            :profile="profile"
            @retry="loadResults"
            @refresh="onRefresh"
            @minimize="onMinimize"
        />
    </div>
</template>
