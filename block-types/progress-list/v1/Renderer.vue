<script setup lang="ts">
import { computed } from 'vue';
import ChartBlockFrame from '@/charts/ChartBlockFrame.vue';
import { iconFor } from '@/lib/blockIcons';
import { resolveLocale } from '@/lib/format';
import type { ProgressListPayload } from './payload';
import { percentText, spoken } from './table';

// The progress-list v1 renderer (Story 5.13): each item with its caption, its percent (value over total, exact) and a 6px track. The percent text is
// the value; the bar is `aria-hidden` and clamps at 100% while the text keeps the true percent. A band's icon and word sit beside the percent and the
// fill takes its colour; a total of 0 reads "—" and the Block is partial. A screen reader hears "Website redesign, 12 of 16 tasks, 75%".
const props = withDefaults(
    defineProps<{
        payload: ProgressListPayload;
        audience?: 'user' | 'admin';
        workspaceLocale?: string | null;
        bare?: boolean;
    }>(),
    { audience: 'user', workspaceLocale: null, bare: false },
);

defineEmits<{ (e: 'retry'): void }>();

const locale = computed(() => resolveLocale(props.workspaceLocale));
const rows = computed(() => props.payload.rows ?? []);

const FILL: Record<string, string> = {
    success: 'bg-success',
    warning: 'bg-warning',
    error: 'bg-error',
    info: 'bg-info',
    neutral: 'bg-text-secondary',
};
const BAND_TEXT: Record<string, string> = {
    success: 'text-success-text',
    warning: 'text-warning',
    error: 'text-error-text',
    info: 'text-info-strong',
    neutral: 'text-text-primary',
};
</script>

<template>
    <ChartBlockFrame
        :state="payload.state"
        :title="payload.title"
        :subtitle="payload.subtitle"
        :footer-action="payload.footerAction"
        :partial="payload.partial === true"
        :stale-since="payload.staleSince"
        :error-message="payload.errorMessage"
        :empty-display="payload.emptyDisplay"
        fallback-title="Progress list"
        field="Progress items"
        :audience="audience"
        :workspace-locale="workspaceLocale"
        :bare="bare"
        custom
        @retry="$emit('retry')"
    >
        <ul class="grid gap-3" data-test="progress-list-block">
            <li
                v-for="row in rows"
                :key="row.key"
                :data-key="row.key"
                class="grid gap-1"
                data-test="progress-row"
            >
                <span class="sr-only" data-test="progress-spoken">{{
                    spoken(row.title, row.caption, row.percent, locale)
                }}</span>
                <span
                    class="flex items-baseline justify-between gap-3"
                    aria-hidden="true"
                >
                    <span
                        class="type-body-sm truncate font-medium text-text-primary"
                        data-test="progress-title"
                        >{{ row.title ?? '—' }}</span
                    >
                    <span
                        class="type-body-sm inline-flex shrink-0 items-center gap-1 font-semibold text-text-primary tabular-nums"
                    >
                        <span
                            v-if="row.band"
                            class="inline-flex items-center gap-1"
                            :class="BAND_TEXT[row.band.color]"
                            data-test="progress-band"
                        >
                            <component
                                :is="iconFor(row.band.icon)"
                                class="size-3.5"
                            />
                            {{ row.band.text }}
                        </span>
                        <span data-test="progress-percent">{{
                            percentText(row.percent, locale)
                        }}</span>
                    </span>
                </span>
                <span
                    v-if="row.caption"
                    class="type-caption text-text-secondary"
                    aria-hidden="true"
                    data-test="progress-caption"
                    >{{ row.caption }}</span
                >
                <span
                    class="h-1.5 w-full overflow-hidden rounded-full bg-surface-muted"
                    aria-hidden="true"
                    data-test="progress-track"
                >
                    <span
                        class="block h-full rounded-full"
                        :class="row.band ? FILL[row.band.color] : 'bg-chart-1'"
                        :style="{ width: `${row.fill ?? '0'}%` }"
                        data-test="progress-fill"
                    />
                </span>
            </li>
        </ul>
    </ChartBlockFrame>
</template>
