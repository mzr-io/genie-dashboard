<script setup lang="ts">
import { Clock, OctagonX, TriangleAlert } from '@lucide/vue';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import ChartPlot from '@/charts/ChartPlot.vue';
import type { ChartInput } from '@/charts/model';
import { axisLabel } from '@/charts/axis';
import { Skeleton } from '@/components/ui/skeleton';
import {
    formatCurrency,
    formatDate,
    formatDateTime,
    formatNumber,
    resolveLocale,
} from '@/lib/format';
import { lineAreaLabels, shellLabels } from '@/locales/labels';
import type { LineAreaFormat, LineAreaPayload } from './payload';

// The line-area v1 renderer (Story 5.8c): Normal, Loading, Empty, Stale, Unavailable, Error and the partial-data badge, around the shared chart wrapper.
// Every slot is interpolated as literal text; numbers and dates are formatted in the browser from the payload's decimal strings, and a state never
// draws a zero line.
const props = withDefaults(
    defineProps<{
        payload: LineAreaPayload;
        audience?: 'user' | 'admin';
        workspaceLocale?: string | null;
        bare?: boolean;
    }>(),
    { audience: 'user', workspaceLocale: null, bare: false },
);

const emit = defineEmits<{ (e: 'retry'): void }>();

const { t } = useI18n();
const titleId = useId();
const locale = computed(() => resolveLocale(props.workspaceLocale));
const FALLBACK_FORMAT: LineAreaFormat = {
    kind: 'number',
    decimalPlaces: null,
    thousandsSeparator: true,
    currencyCode: null,
    currencyDisplay: 'symbol',
};
const heading = computed(
    () => props.payload.title ?? lineAreaLabels.fallbackTitle,
);

function places(format: LineAreaFormat): Intl.NumberFormatOptions {
    return format.decimalPlaces === null
        ? {}
        : {
              minimumFractionDigits: format.decimalPlaces,
              maximumFractionDigits: format.decimalPlaces,
          };
}

// The exact formatted decimal (tooltip, announcement, label): the digits are never rounded through a float unless the descriptor says so.
function exact(value: string): string {
    const format = props.payload.format ?? FALLBACK_FORMAT;
    const grouping = { useGrouping: format.thousandsSeparator };

    if (format.kind === 'currency' && format.currencyCode !== null) {
        return formatCurrency(
            value,
            format.currencyCode,
            {
                ...places(format),
                ...grouping,
                currencyDisplay: format.currencyDisplay,
            },
            locale.value,
        );
    }

    return formatNumber(
        value,
        {
            ...places(format),
            ...grouping,
            style: format.kind === 'percent' ? 'percent' : 'decimal',
        },
        locale.value,
    );
}

// The Y tick: compact ("$40k"), from the same descriptor; a float only here, for the axis position the library hands over.
function compact(value: string): string {
    const format = props.payload.format ?? FALLBACK_FORMAT;

    return formatNumber(
        value,
        {
            notation: 'compact',
            maximumFractionDigits: 1,
            ...(format.kind === 'currency' && format.currencyCode !== null
                ? { style: 'currency', currency: format.currencyCode }
                : {}),
        },
        locale.value,
    );
}

// The axis labels: by the X slot's pattern in the viewer's locale (Story 5.10).
function axis(value: string): string {
    return axisLabel(value, locale.value, props.payload.xPattern ?? null);
}

// A state with no data (Loading, Unavailable, Error) arrives without a chart.
const rows = computed(() => props.payload.x ?? []);
const lines = computed(() => props.payload.series ?? []);

const input = computed<ChartInput>(() => {
    const labels = rows.value.map(axis);

    return {
        kind: props.payload.kind === 'area' ? 'area' : 'line',
        x: labels,
        series: lines.value,
        range:
            labels.length === 0
                ? ''
                : labels.length === 1
                  ? labels[0]
                  : `${labels[0]} to ${labels[labels.length - 1]}`,
        format: exact,
        compact,
        target: props.payload.target
            ? {
                  value: props.payload.target.value,
                  label: props.payload.target.label ?? '',
              }
            : null,
        highlight: props.payload.highlight ?? null,
        directLabels: props.payload.directLabels !== false,
    };
});

const unavailableText = computed(() =>
    props.audience === 'admin'
        ? t('unavailable-admin', { field: lineAreaLabels.chartField })
        : t('unavailable-user'),
);

const staleMessage = computed(() => {
    const since = props.payload.staleSince;

    if (since === null || formatDate(since, {}, locale.value) === '') {
        return lineAreaLabels.staleGeneric;
    }

    const time = formatDateTime(since, { timeStyle: 'short' }, locale.value);
    const day = formatDate(since, { dateStyle: 'medium' }, locale.value);
    const today = formatDate(new Date(), { dateStyle: 'medium' }, locale.value);

    return day === today
        ? t('stale.today', { time })
        : t('stale.earlier', { day, time });
});

const plotted = computed(
    () =>
        (props.payload.state === 'normal' || props.payload.state === 'stale') &&
        lines.value.length > 0 &&
        rows.value.length > 0,
);
</script>

<template>
    <article
        class="line-area flex min-h-0 flex-col gap-2"
        :class="
            bare
                ? ''
                : 'rounded-md border border-border-default bg-surface-card p-4'
        "
        :data-state="payload.state"
        data-test="line-area"
    >
        <header v-if="!bare" class="grid gap-0.5">
            <h3
                :id="titleId"
                class="type-title-sm break-words text-text-primary"
                data-test="chart-title"
            >
                {{ heading }}
            </h3>
            <p
                v-if="payload.subtitle"
                class="type-caption break-words text-text-secondary"
                data-test="chart-subtitle"
            >
                {{ payload.subtitle }}
            </p>
        </header>

        <div class="grid min-h-0 gap-2" data-test="chart-body">
            <template v-if="payload.state === 'loading'">
                <div
                    class="flex h-40 items-end gap-2"
                    role="status"
                    data-test="chart-loading"
                >
                    <Skeleton class="h-1/3 w-full" :caption="false" />
                    <Skeleton class="h-2/3 w-full" :caption="false" />
                    <Skeleton class="h-1/2 w-full" :caption="false" />
                    <Skeleton class="h-5/6 w-full" />
                </div>
            </template>

            <template v-else-if="payload.state === 'error'">
                <div
                    class="flex flex-wrap items-center gap-2 text-error-text"
                    data-test="chart-error"
                >
                    <OctagonX class="size-4 shrink-0" aria-hidden="true" />
                    <p class="type-body-sm break-words" role="alert">
                        {{
                            payload.errorMessage ?? lineAreaLabels.errorMessage
                        }}
                    </p>
                    <button
                        type="button"
                        class="type-body-sm text-accent-ink underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        data-test="chart-retry"
                        @click="emit('retry')"
                    >
                        {{ shellLabels.retry }}
                    </button>
                </div>
            </template>

            <template v-else-if="payload.state === 'empty'">
                <p
                    class="type-body-sm py-4 text-center text-text-muted"
                    data-test="chart-empty"
                >
                    {{ payload.emptyDisplay ?? t('block-empty') }}
                </p>
            </template>

            <template v-else-if="payload.state === 'unavailable' || !plotted">
                <p
                    class="type-body-sm flex items-start gap-2 text-warning"
                    data-test="chart-unavailable"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span>{{ unavailableText }}</span>
                </p>
            </template>

            <template v-else>
                <ChartPlot :input="input" data-test="chart-plot-frame" />
                <p
                    v-if="payload.partial === true"
                    class="type-caption w-fit rounded-sm bg-surface-muted px-2 py-0.5 text-text-primary"
                    data-test="chart-partial"
                >
                    {{ lineAreaLabels.partial }}
                </p>
                <p
                    v-if="payload.state === 'stale'"
                    class="type-caption flex items-center gap-1 text-warning"
                    data-test="chart-stale"
                >
                    <Clock class="size-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ staleMessage }}</span>
                </p>
            </template>

            <p
                v-if="payload.footerAction"
                class="type-caption break-words text-text-secondary"
                data-test="chart-footer"
            >
                {{ payload.footerAction }}
            </p>
        </div>
    </article>
</template>
