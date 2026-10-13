<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { DashboardError, fetchRows } from '@/lib/dashboards';
import type { RowsPage } from '@/lib/dashboards';
import { tableFor } from '@/lib/blockRenderers';
import { formatDateTime, resolveLocale } from '@/lib/format';
import { dashboardLabels as labels } from '@/locales/labels';
import type { Component } from 'vue';

// The Expanded view and View as table (Story 5.9; UX-DR-118). `table` is built from the Block's displayed payload and needs no request: the same
// formats as the chart, th with scope, nothing but the table. `rows` pages the rows behind the Block (before top-N) from the server, 90% of the
// viewport up to 1200 px and the whole screen below 768 px. Focus goes to the title on open; Esc or Close returns to the invoker. A new result
// replaces the values in place and keeps the page; a page that is gone shows the last one and says so. Every cell is text.
const props = defineProps<{
    open: boolean;
    mode: 'table' | 'rows';
    title: string;
    type: string;
    icon: Component;
    dashboardId: string | null;
    itemId: string;
    // The payload the Block displays now (the table's source and the cell formats of a rows page).
    payload: Record<string, unknown> | null;
    dataAsOf: string | null;
    // Changes when a new result arrives: the rows page is read again in place.
    resultEtag: string | null;
    workspaceLocale?: string | null;
}>();

const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const { t } = useI18n();
const PAGE_SIZE = 25;
const locale = computed(() => resolveLocale(props.workspaceLocale ?? null));
const builder = computed(() => tableFor(props.type));
const titleRef = ref<{ $el: HTMLElement } | HTMLElement | null>(null);

type Phase = 'loading' | 'ready' | 'empty' | 'error';
const phase = ref<Phase>('loading');
const page = ref(1);
const data = ref<RowsPage | null>(null);
const announcement = ref('');
let controller: AbortController | null = null;

const asOf = computed(() =>
    props.dataAsOf === null
        ? null
        : formatDateTime(props.dataAsOf, { timeStyle: 'short' }, locale.value),
);

// "View as table": from the displayed payload, no request.
const local = computed(() =>
    props.mode === 'table' && builder.value !== null && props.payload !== null
        ? builder.value.table(props.payload, locale.value)
        : null,
);

// "26–50 of 48"
const range = computed(() => {
    if (data.value === null || data.value.total === 0) {
        return '';
    }

    const from = (data.value.page - 1) * data.value.page_size + 1;
    const to = Math.min(
        data.value.page * data.value.page_size,
        data.value.total,
    );

    return labels.expanded.range(from, to, data.value.total);
});

const columns = computed(() =>
    props.mode === 'rows'
        ? (data.value?.columns ?? [])
        : (local.value?.columns ?? []),
);
const rows = computed<string[][]>(() => {
    if (props.mode === 'table') {
        return local.value?.rows ?? [];
    }

    const keys = data.value?.columns.map((c) => c.key) ?? [];

    return (data.value?.rows ?? []).map((row) =>
        row.map((value, at) =>
            builder.value !== null && props.payload !== null
                ? builder.value.cell(
                      keys[at],
                      value,
                      props.payload,
                      locale.value,
                  )
                : value === null
                  ? '—'
                  : String(value),
        ),
    );
});

async function load(keep = false): Promise<void> {
    if (props.mode !== 'rows') {
        phase.value =
            local.value === null || local.value.rows.length === 0
                ? 'empty'
                : 'ready';

        return;
    }

    if (props.dashboardId === null) {
        phase.value = 'error';

        return;
    }

    controller?.abort();
    controller = new AbortController();

    // A replacement in place keeps what is shown until the new page lands.
    if (!keep || data.value === null) {
        phase.value = 'loading';
    }

    try {
        const next = await fetchRows(
            props.dashboardId,
            props.itemId,
            page.value,
            PAGE_SIZE,
            controller.signal,
        );

        data.value = next;
        page.value = next.page;
        phase.value = next.status === 'empty' ? 'empty' : 'ready';

        if (next.clamped) {
            announcement.value = labels.expanded.lastPage(
                next.page,
                next.pages,
            );
        }
    } catch (error) {
        if (controller?.signal.aborted) {
            return;
        }

        phase.value = error instanceof DashboardError ? 'error' : 'error';
    }
}

function go(next: number): void {
    page.value = next;
    announcement.value = '';
    void load();
}

watch(
    () => props.open,
    async (open) => {
        if (!open) {
            controller?.abort();

            return;
        }

        page.value = 1;
        data.value = null;
        announcement.value = '';
        await load();
    },
    { immediate: true },
);

// A new result: the values are replaced in place, the page is kept.
watch(
    () => props.resultEtag,
    () => {
        if (props.open && props.mode === 'rows') {
            void load(true);
        }
    },
);
watch(
    () => props.payload,
    () => {
        if (props.open && props.mode === 'table') {
            void load();
        }
    },
);

// The title takes focus when the panel opens.
async function focusTitle(event: Event): Promise<void> {
    event.preventDefault();
    await nextTick();
    const target = titleRef.value;
    const element =
        target !== null && '$el' in target
            ? target.$el
            : (target as HTMLElement | null);

    element?.focus();
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent
            class="max-h-[90vh] grid-rows-[auto_minmax(0,1fr)_auto] overflow-hidden max-md:h-dvh max-md:max-h-dvh max-md:w-screen max-md:max-w-none max-md:rounded-none sm:max-w-[min(90vw,1200px)]"
            data-slot="expanded-view"
            :data-mode="mode"
            @open-auto-focus="focusTitle"
        >
            <DialogHeader class="flex-row items-start gap-3 text-left">
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-md bg-surface-muted text-text-secondary"
                    aria-hidden="true"
                    data-slot="expanded-icon"
                >
                    <component :is="icon" class="size-5" />
                </span>
                <div class="min-w-0">
                    <DialogTitle
                        ref="titleRef"
                        tabindex="-1"
                        class="type-title-sm break-words focus:outline-none"
                        data-slot="expanded-title"
                    >
                        {{
                            mode === 'table'
                                ? labels.expanded.tableTitle(title)
                                : title
                        }}
                    </DialogTitle>
                    <DialogDescription
                        class="type-caption text-text-muted"
                        data-slot="expanded-asof"
                    >
                        {{
                            asOf === null
                                ? labels.expanded.noAsOf
                                : labels.expanded.asOf(asOf)
                        }}
                    </DialogDescription>
                </div>
            </DialogHeader>

            <div class="min-h-0 overflow-auto" data-slot="expanded-body">
                <div
                    v-if="phase === 'loading'"
                    role="status"
                    aria-busy="true"
                    class="grid gap-2"
                    data-test="expanded-loading"
                >
                    <span class="sr-only">{{ labels.expanded.loading }}</span>
                    <Skeleton
                        v-for="n in 6"
                        :key="n"
                        class="h-6 w-full"
                        :caption="false"
                    />
                </div>

                <div
                    v-else-if="phase === 'error'"
                    class="flex flex-wrap items-center gap-3"
                    data-test="expanded-error"
                >
                    <p class="type-body-sm text-error-text" role="alert">
                        {{ t('expanded-error') }}
                    </p>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        data-test="expanded-retry"
                        @click="load()"
                    >
                        {{ labels.expanded.retry }}
                    </Button>
                </div>

                <p
                    v-else-if="phase === 'empty'"
                    class="type-body-sm py-6 text-center text-text-muted"
                    data-test="expanded-empty"
                >
                    {{ t('expanded-empty') }}
                </p>

                <table
                    v-else
                    class="type-body-sm w-full border-collapse text-left"
                    data-test="expanded-table"
                >
                    <caption class="sr-only">
                        {{
                            title
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                scope="col"
                                class="border-b border-border-default px-3 py-2 font-semibold text-text-secondary"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, at) in rows" :key="at">
                            <td
                                v-for="(value, column) in row"
                                :key="column"
                                class="border-b border-border-default px-3 py-2 break-words text-text-primary tabular-nums"
                            >
                                {{ value }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="mode === 'rows' && phase === 'ready' && data !== null"
                class="flex flex-wrap items-center justify-between gap-2"
                data-test="expanded-pager"
            >
                <p class="type-caption text-text-secondary">{{ range }}</p>
                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="data.page <= 1"
                        data-test="expanded-prev"
                        @click="go(data.page - 1)"
                    >
                        {{ labels.expanded.previous }}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        :disabled="data.page >= data.pages"
                        data-test="expanded-next"
                        @click="go(data.page + 1)"
                    >
                        {{ labels.expanded.next }}
                    </Button>
                </div>
            </div>

            <p class="sr-only" aria-live="polite" data-test="expanded-announce">
                {{ announcement }}
            </p>
        </DialogContent>
    </Dialog>
</template>
