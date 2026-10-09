<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import DataSourceHealth from '@/components/DataSourceHealth.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { useShell } from '@/composables/useShell';
import { announce } from '@/lib/announce';
import { fetchDataSourceHealth } from '@/lib/dataSources';
import type { DataSourceHealthRow } from '@/lib/dataSources';
import { formatDateTime } from '@/lib/format';
import {
    dataSourceLabels,
    platformHealthLabels as labels,
    shellLabels,
    shellPages,
} from '@/locales/labels';
import { edit } from '@/routes/admin/data-sources';

// The Admin overview (Story 2.18; UX-DR-114, 260, 273, 276, 282). The page header and the "+ Create block" action are the old placeholder's
// (without the permission the action is disabled with its reason, not hidden). The "Platform health" panel shows only "Your data sources" (the
// platform services part is a later story) and exists only for `data_sources.manage`: it is not rendered otherwise, never hidden by CSS, and
// nothing is requested. It loads once and is never polled or announced as a routine refresh. Each row is a link to the data source's form
// whose accessible name is "{name}, {word}, last success {time}"; the dot is aria-hidden and never stands without its word.
const { t } = useI18n();
const { items, can } = useShell();

const config = shellPages['admin-overview'];
const createBlock = computed(
    () => items.value.find((item) => item.key === 'create-block') ?? null,
);
const showHealth = computed(() => can.value['data_sources.manage'] === true);

const rows = ref<DataSourceHealthRow[]>([]);
const state = ref<'loading' | 'error' | 'ready'>('loading');
let controller: AbortController | null = null;

function blocked(event: Event): void {
    event.preventDefault();
    announce(t('perm-denied'));
}

function lastSuccess(row: DataSourceHealthRow): string {
    return row.last_successful_call_at
        ? dataSourceLabels.lastSuccess(
              formatDateTime(row.last_successful_call_at),
          )
        : dataSourceLabels.noCallYet;
}

function rowName(row: DataSourceHealthRow): string {
    return labels.row(
        row.name,
        dataSourceLabels.health[row.health] ?? dataSourceLabels.checking,
        row.last_successful_call_at
            ? formatDateTime(row.last_successful_call_at)
            : null,
        dataSourceLabels.noCallYet,
    );
}

async function load(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const mine = controller;
    state.value = 'loading';

    try {
        const result = await fetchDataSourceHealth(mine.signal);

        if (mine.signal.aborted) {
            return;
        }

        rows.value = result;
        state.value = 'ready';
    } catch {
        if (!mine.signal.aborted) {
            state.value = 'error';
        }
    }
}

let started = false;

function start(): void {
    if (showHealth.value && !started) {
        started = true;
        void load();
    }
}

onMounted(start);
// The shell's permissions can arrive after the page mounts: the panel then loads once, when it first becomes allowed.
watch(showHealth, start);

onBeforeUnmount(() => controller?.abort());
</script>

<template>
    <Head :title="config.title" />

    <div class="flex flex-col gap-6 px-4 py-6 sm:px-7">
        <PageHeader :title="config.title" :subtitle="t('page-subtitles.admin')">
            <template v-if="createBlock">
                <Button v-if="createBlock.allowed" as-child>
                    <Link :href="createBlock.href">{{
                        shellLabels.createBlock
                    }}</Link>
                </Button>
                <Button
                    v-else
                    type="button"
                    aria-disabled="true"
                    aria-describedby="create-block-reason"
                    @click="blocked"
                >
                    {{ shellLabels.createBlock }}
                </Button>
                <span
                    v-if="!createBlock.allowed"
                    id="create-block-reason"
                    class="sr-only"
                    >{{ t('perm-denied') }}</span
                >
            </template>
        </PageHeader>

        <section
            v-if="showHealth"
            aria-labelledby="platform-health-title"
            data-slot="platform-health"
            class="flex flex-col gap-4 rounded-lg border border-border-default bg-surface-card p-5"
        >
            <h2
                id="platform-health-title"
                class="type-title-md text-text-primary"
            >
                {{ labels.panel }}
            </h2>

            <section
                aria-labelledby="your-data-sources-title"
                data-test="your-data-sources"
                class="flex flex-col gap-3"
            >
                <h3
                    id="your-data-sources-title"
                    class="type-title-sm text-text-secondary"
                >
                    {{ labels.sourcesTitle }}
                </h3>

                <p
                    v-if="state === 'loading'"
                    class="type-caption text-text-muted"
                    aria-busy="true"
                    data-test="health-loading"
                >
                    {{ labels.loading }}
                </p>

                <div
                    v-else-if="state === 'error'"
                    class="flex flex-wrap items-center gap-3"
                    data-test="health-error"
                >
                    <p role="alert" class="type-body text-text-secondary">
                        {{ labels.failed }}
                    </p>
                    <Button
                        type="button"
                        variant="secondary"
                        data-test="health-retry"
                        @click="load"
                    >
                        {{ labels.retry }}
                    </Button>
                </div>

                <p
                    v-else-if="rows.length === 0"
                    class="type-body text-text-secondary"
                    data-test="health-empty"
                >
                    {{ labels.empty }}
                </p>

                <ul v-else class="flex flex-col divide-y divide-border-default">
                    <li
                        v-for="row in rows"
                        :key="row.data_source_id"
                        data-test="health-row"
                    >
                        <Link
                            :href="edit(row.data_source_id).url"
                            :aria-label="rowName(row)"
                            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-2 hover:text-accent-ink-strong"
                        >
                            <span class="font-medium text-text-primary">{{
                                row.name
                            }}</span>
                            <span class="flex flex-wrap items-center gap-4">
                                <DataSourceHealth :status="row.health" />
                                <span
                                    class="type-caption text-text-muted"
                                    data-test="health-last-success"
                                    >{{ lastSuccess(row) }}</span
                                >
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </section>
    </div>
</template>
