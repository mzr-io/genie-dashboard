<script setup lang="ts">
import { Clock, OctagonX, TriangleAlert } from '@lucide/vue';
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { Skeleton } from '@/components/ui/skeleton';
import { iconFor } from '@/lib/blockIcons';
import { formatDate, formatDateTime, resolveLocale } from '@/lib/format';
import { kpiCardLabels, shellLabels } from '@/locales/labels';
import { formatMeasure, magnitude } from './display';
import type { BandColor, KpiCardPayload } from './payload';

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

// The presentation rules' affixes go around the formatted value, as literal text.
const headlineShown = computed(
    () =>
        `${props.payload.prefix ?? ''}${headlineText.value}${props.payload.suffix ?? ''}`,
);

// The headline's emphasis: the card's own size and weight unless the rules choose another. Whole class names, so the build finds them.
const SIZE_CLASS = { sm: 'text-xl', md: '', lg: 'text-4xl' } as const;
const WEIGHT_CLASS = {
    regular: 'font-normal',
    medium: 'font-medium',
    bold: 'font-bold',
} as const;

const headlineClass = computed(() => {
    const { headline } = props.payload;
    const emphasis = headline.available ? headline.emphasis : undefined;

    return emphasis === undefined
        ? ''
        : `${emphasis.size === undefined ? '' : SIZE_CLASS[emphasis.size]} ${emphasis.weight === undefined ? '' : WEIGHT_CLASS[emphasis.weight]}`.trim();
});

const BAND_CLASS: Record<BandColor, string> = {
    success: 'bg-success-soft text-success-text',
    warning: 'bg-warning-soft text-warning-text',
    error: 'bg-error-soft text-error-text',
    info: 'bg-info-soft text-info-strong',
    neutral: 'bg-surface-muted text-text-primary',
};

// A matched band: its icon AND its label say it; the colour only agrees.
const band = computed(() => {
    const badge = props.payload.statusBadge;

    if (badge === null || typeof badge === 'string') {
        return null;
    }

    return {
        text: badge.text,
        icon: iconFor(badge.icon),
        class: BAND_CLASS[badge.color] ?? BAND_CLASS.neutral,
    };
});

const statusText = computed(() => {
    const badge = props.payload.statusBadge;

    return typeof badge === 'string' && badge !== '' ? badge : null;
});

// The empty display stands in for a mapped slot with no value, never for the headline.
const emptyFor = (slot: string): string | null => {
    const shown = props.payload.emptyDisplay ?? null;

    return shown !== null && (props.payload.emptySlots ?? []).includes(slot)
        ? shown
        : null;
};

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
                    :class="[
                        payload.state === 'stale'
                            ? 'text-text-muted'
                            : 'text-text-primary',
                        headlineClass,
                    ]"
                    :data-emphasis="
                        payload.headline.available
                            ? payload.headline.emphasis?.role
                            : undefined
                    "
                    data-test="kpi-headline"
                >
                    {{ headlineShown }}
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
                    v-if="!unavailable && !delta && emptyFor('comparison')"
                    class="type-body-sm text-text-muted"
                    data-test="kpi-delta-empty"
                >
                    {{ emptyFor('comparison') }}
                </p>

                <p
                    v-if="payload.secondaryNote"
                    class="type-caption break-words text-text-secondary"
                    data-test="kpi-note"
                >
                    {{ payload.secondaryNote }}
                </p>
                <p
                    v-else-if="emptyFor('secondary_note')"
                    class="type-caption break-words text-text-muted"
                    data-test="kpi-note-empty"
                >
                    {{ emptyFor('secondary_note') }}
                </p>
                <p
                    v-if="band"
                    class="type-caption inline-flex w-fit items-center gap-1 rounded-sm px-2 py-0.5 break-words"
                    :class="band.class"
                    data-test="kpi-band"
                >
                    <component
                        :is="band.icon"
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                        data-test="kpi-band-icon"
                    />
                    <span data-test="kpi-band-label">{{ band.text }}</span>
                </p>
                <p
                    v-else-if="statusText"
                    class="type-caption w-fit rounded-sm bg-surface-muted px-2 py-0.5 break-words text-text-primary"
                    data-test="kpi-badge"
                >
                    {{ statusText }}
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
