<script setup lang="ts">
import { Clock, OctagonX, TriangleAlert } from '@lucide/vue';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDate, formatDateTime, resolveLocale } from '@/lib/format';
import { kpiCardLabels, shellLabels } from '@/locales/labels';
import { formatMeasure, magnitude } from './display';
import type { KpiCardPayload } from './payload';

// The kpi-card v1 renderer (Story 3.2): Normal, Loading, Empty, Stale, Unavailable and Error. Every slot is interpolated as literal text
// (no raw HTML, ever); numbers and dates are formatted in the browser from the payload's decimal strings and format descriptors; colour
// comes from tokens and is never the only signal. The body is a focusable scroll region named by the block title, so nothing is clipped.
const props = withDefaults(
    defineProps<{
        payload: KpiCardPayload;
        // `admin` names the missing field when the headline is unavailable; a User sees the plain message.
        audience?: 'user' | 'admin';
        // Used when the person has not saved a locale of their own.
        workspaceLocale?: string | null;
    }>(),
    { audience: 'user', workspaceLocale: null },
);

const emit = defineEmits<{ (e: 'retry'): void }>();

const { t } = useI18n();
const titleId = useId();
const locale = computed(() => resolveLocale(props.workspaceLocale));
const heading = computed(
    () =>
        props.payload.title ??
        props.payload.kpiLabel ??
        kpiCardLabels.fallbackTitle,
);

const headlineText = computed(() => {
    const { headline } = props.payload;

    return headline.available
        ? formatMeasure(headline.value, headline.format, locale.value)
        : '';
});

// A headline that cannot be formatted is as unavailable as a missing one: never "0", "NaN" or a dash.
const unavailable = computed(
    () =>
        props.payload.state === 'unavailable' ||
        (props.payload.state === 'normal' && headlineText.value === '') ||
        (props.payload.state === 'stale' && headlineText.value === ''),
);

const unavailableText = computed(() =>
    props.audience === 'admin'
        ? t('unavailable-admin', {
              field: props.payload.headline.available
                  ? kpiCardLabels.headlineField
                  : props.payload.headline.field,
          })
        : t('unavailable-user'),
);

const delta = computed(() => {
    const { comparison } = props.payload;

    if (comparison === null) {
        return null;
    }

    const { lowerIsBetter } = comparison;
    const size = magnitude(comparison.value);
    let direction = comparison.direction;
    let value = '';

    if (direction !== 'flat') {
        value = formatMeasure(size, comparison.format, locale.value);

        // A change that shows as zero is no change; one that cannot be shown is omitted, never a bare arrow.
        if (value === '') {
            return null;
        }

        if (!/[1-9]/.test(formatMeasure(size, comparison.format, 'en'))) {
            direction = 'flat';
            value = '';
        }
    }

    const good =
        direction === 'flat' ? null : (direction === 'down') === lowerIsBetter;
    const judged = lowerIsBetter && good !== null;
    const word = {
        up: kpiCardLabels.up,
        down: kpiCardLabels.down,
        flat: kpiCardLabels.flat,
    }[direction];

    return {
        direction,
        value,
        judged,
        good,
        arrow: direction === 'up' ? '▲' : direction === 'down' ? '▼' : '',
        colour:
            good === null
                ? 'text-text-secondary'
                : good
                  ? 'text-success-text'
                  : 'text-error-text',
        spoken: kpiCardLabels.deltaSpoken(
            word,
            value,
            props.payload.comparisonLabel,
            good === null
                ? null
                : lowerIsBetter
                  ? good
                      ? kpiCardLabels.betterSpoken
                      : kpiCardLabels.worseSpoken
                  : good
                    ? kpiCardLabels.favourableSpoken
                    : kpiCardLabels.unfavourableSpoken,
        ),
    };
});

const staleMessage = computed(() => {
    const since = props.payload.staleSince;

    if (since === null || formatDate(since, {}, locale.value) === '') {
        return kpiCardLabels.staleGeneric;
    }

    const time = formatDateTime(since, { timeStyle: 'short' }, locale.value);
    const day = formatDate(since, { dateStyle: 'medium' }, locale.value);
    const today = formatDate(new Date(), { dateStyle: 'medium' }, locale.value);

    return day === today
        ? t('stale.today', { time })
        : t('stale.earlier', { day, time });
});
</script>

<template>
    <article
        class="kpi-card flex min-h-0 flex-col gap-2 rounded-md border border-border-default bg-surface-card p-4"
        :data-state="payload.state"
        data-test="kpi-card"
    >
        <header class="grid gap-0.5">
            <h3
                :id="titleId"
                class="type-title-sm break-words text-text-primary"
                data-test="kpi-title"
            >
                {{ heading }}
            </h3>
            <p
                v-if="payload.subtitle"
                class="type-caption break-words text-text-secondary"
                data-test="kpi-subtitle"
            >
                {{ payload.subtitle }}
            </p>
        </header>

        <div
            role="region"
            tabindex="0"
            :aria-labelledby="titleId"
            class="grid max-h-full min-h-0 gap-2 overflow-auto focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            data-test="kpi-body"
        >
            <template v-if="payload.state === 'loading'">
                <div class="grid gap-2" role="status" data-test="kpi-loading">
                    <Skeleton class="h-3 w-1/2" :caption="false" />
                    <Skeleton class="h-8 w-3/4" :caption="false" />
                    <Skeleton class="h-3 w-1/3" />
                </div>
            </template>

            <template v-else-if="payload.state === 'error'">
                <div
                    class="flex flex-wrap items-center gap-2 text-error-text"
                    data-test="kpi-error"
                >
                    <OctagonX class="size-4 shrink-0" aria-hidden="true" />
                    <p class="type-body-sm break-words" role="alert">
                        {{ payload.errorMessage ?? kpiCardLabels.errorMessage }}
                    </p>
                    <button
                        type="button"
                        class="type-body-sm text-accent-ink underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        data-test="kpi-retry"
                        @click="emit('retry')"
                    >
                        {{ shellLabels.retry }}
                    </button>
                </div>
            </template>

            <template v-else-if="payload.state === 'empty'">
                <p
                    class="type-body-sm py-4 text-center text-text-muted"
                    data-test="kpi-empty"
                >
                    {{ kpiCardLabels.empty }}
                </p>
            </template>

            <template v-else>
                <p
                    v-if="payload.icon"
                    class="type-caption break-words text-text-secondary"
                    data-test="kpi-icon"
                >
                    {{ payload.icon }}
                </p>
                <p
                    v-if="payload.kpiLabel"
                    class="type-body-sm break-words text-text-secondary"
                    data-test="kpi-label"
                >
                    {{ payload.kpiLabel }}
                </p>

                <p
                    v-if="unavailable"
                    class="type-body-sm flex items-start gap-2 text-warning"
                    data-test="kpi-unavailable"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span>{{ unavailableText }}</span>
                </p>
                <p
                    v-else
                    class="type-kpi-headline break-words"
                    :class="
                        payload.state === 'stale'
                            ? 'text-text-muted'
                            : 'text-text-primary'
                    "
                    data-test="kpi-headline"
                >
                    {{ headlineText }}
                </p>

                <p
                    v-if="delta && !unavailable"
                    class="type-body-sm"
                    :class="delta.colour"
                    data-test="kpi-delta"
                >
                    <span aria-hidden="true" data-test="kpi-delta-visible">
                        <template v-if="delta.direction === 'flat'">{{
                            kpiCardLabels.noChange
                        }}</template>
                        <template v-else>
                            <span data-test="kpi-delta-arrow">{{
                                delta.arrow
                            }}</span>
                            {{ delta.value }}
                        </template>
                        <template v-if="payload.comparisonLabel">
                            {{ payload.comparisonLabel }}</template
                        >
                        <template v-if="delta.judged">
                            {{
                                delta.good
                                    ? kpiCardLabels.better
                                    : kpiCardLabels.worse
                            }}</template
                        >
                    </span>
                    <span class="sr-only" data-test="kpi-delta-spoken">{{
                        delta.spoken
                    }}</span>
                </p>

                <p
                    v-if="payload.secondaryNote"
                    class="type-caption break-words text-text-secondary"
                    data-test="kpi-note"
                >
                    {{ payload.secondaryNote }}
                </p>
                <p
                    v-if="payload.statusBadge"
                    class="type-caption w-fit rounded-sm bg-surface-muted px-2 py-0.5 break-words text-text-primary"
                    data-test="kpi-badge"
                >
                    {{ payload.statusBadge }}
                </p>
                <p
                    v-if="payload.state === 'stale'"
                    class="type-caption flex items-center gap-1 text-warning"
                    data-test="kpi-stale"
                >
                    <Clock class="size-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ staleMessage }}</span>
                </p>
                <p
                    v-if="payload.footerAction"
                    class="type-caption break-words text-text-secondary"
                    data-test="kpi-footer"
                >
                    {{ payload.footerAction }}
                </p>
            </template>
        </div>
    </article>
</template>
