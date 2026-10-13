<script setup lang="ts">
import { computed } from 'vue';
import ChartBlockFrame from '@/charts/ChartBlockFrame.vue';
import { currentFormatting, resolveLocale } from '@/lib/format';
import type { AgendaPayload, AgendaRow } from './payload';
import { dayName, spoken, weekOf } from './table';

// The calendar-agenda v1 renderer (Story 5.14): an optional week strip (a list of seven days, today in a 28px accent circle with a dot and
// `aria-current="date"`) over the agenda grouped by day. Each row has its time, a 3px category rule from the chart palette, its title, subtitle and
// duration; the category is also written, so colour is never the only signal. Dates are calendar days and times are the wall clock as written.
const props = withDefaults(
    defineProps<{
        payload: AgendaPayload;
        audience?: 'user' | 'admin';
        workspaceLocale?: string | null;
        bare?: boolean;
        // The day to treat as today (tests, previews); otherwise the day in the viewer's time zone.
        today?: string | null;
    }>(),
    { audience: 'user', workspaceLocale: null, bare: false, today: null },
);

defineEmits<{ (e: 'retry'): void }>();

const locale = computed(() => resolveLocale(props.workspaceLocale));
const rows = computed(() => props.payload.rows ?? []);

const todayIso = computed(() => {
    if (props.today !== null) {
        return props.today;
    }

    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: currentFormatting().timeZone,
        }).format(new Date());
    } catch {
        return new Date().toISOString().slice(0, 10);
    }
});

const strip = computed(() => {
    const busy = new Set(rows.value.map((row) => row.date));

    return weekOf(todayIso.value).map((day) => ({
        day,
        today: day === todayIso.value,
        busy: busy.has(day),
        number: Number(day.slice(8, 10)),
        name:
            dayName(day, locale.value) +
            (day === todayIso.value ? ', today' : ''),
    }));
});

const days = computed(() => {
    const groups: { date: string | null; rows: AgendaRow[] }[] = [];

    for (const row of rows.value) {
        const last = groups[groups.length - 1];

        if (last !== undefined && last.date === row.date) {
            last.rows.push(row);
        } else {
            groups.push({ date: row.date, rows: [row] });
        }
    }

    return groups;
});

const RULE = [
    'bg-chart-1',
    'bg-chart-2',
    'bg-chart-3',
    'bg-chart-4',
    'bg-chart-5',
    'bg-chart-6',
];
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
        fallback-title="Agenda"
        field="Agenda events"
        :audience="audience"
        :workspace-locale="workspaceLocale"
        :bare="bare"
        custom
        @retry="$emit('retry')"
    >
        <div class="grid gap-3" data-test="calendar-block">
            <ol
                v-if="payload.weekStrip"
                class="flex justify-between gap-1"
                aria-label="This week"
                data-test="calendar-week-strip"
            >
                <li
                    v-for="d in strip"
                    :key="d.day"
                    class="grid justify-items-center gap-0.5"
                    :aria-current="d.today ? 'date' : undefined"
                    :aria-label="d.name"
                    data-test="week-day"
                >
                    <span
                        class="type-caption inline-flex size-7 items-center justify-center rounded-full tabular-nums"
                        :class="
                            d.today
                                ? 'bg-accent-strong font-semibold text-primary-foreground'
                                : 'text-text-primary'
                        "
                        aria-hidden="true"
                        >{{ d.number }}</span
                    >
                    <span
                        class="size-1 rounded-full"
                        :class="d.busy ? 'bg-text-secondary' : 'bg-transparent'"
                        aria-hidden="true"
                    />
                </li>
            </ol>

            <section
                v-for="group in days"
                :key="group.date ?? 'undated'"
                class="grid gap-1"
            >
                <h4
                    class="type-label-caps text-text-muted"
                    data-test="agenda-day"
                >
                    {{
                        group.date === null ? '—' : dayName(group.date, locale)
                    }}
                </h4>
                <ul class="grid">
                    <li
                        v-for="row in group.rows"
                        :key="row.key"
                        :data-key="row.key"
                        class="flex items-stretch gap-3 py-1.5"
                        data-test="agenda-row"
                    >
                        <span class="sr-only">{{ spoken(row) }}</span>
                        <span
                            class="type-body-sm w-12 shrink-0 text-text-secondary tabular-nums"
                            aria-hidden="true"
                            data-test="agenda-time"
                            >{{ row.time ?? '—' }}</span
                        >
                        <span
                            class="w-[3px] shrink-0 rounded-full"
                            :class="
                                row.tint === null
                                    ? 'bg-border-default'
                                    : RULE[row.tint % RULE.length]
                            "
                            aria-hidden="true"
                            data-test="agenda-rule"
                        />
                        <span class="grid min-w-0 flex-1" aria-hidden="true">
                            <span
                                class="type-body-sm truncate font-medium text-text-primary"
                                data-test="agenda-title"
                                >{{ row.title ?? '—' }}</span
                            >
                            <span
                                v-if="row.subtitle || row.duration"
                                class="type-caption truncate text-text-secondary"
                                data-test="agenda-subtitle"
                                >{{
                                    [
                                        row.subtitle,
                                        row.duration === null
                                            ? null
                                            : `${row.duration} min`,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}</span
                            >
                            <span
                                v-if="row.category"
                                class="type-caption text-text-muted"
                                data-test="agenda-category"
                                >{{ row.category }}</span
                            >
                        </span>
                    </li>
                </ul>
            </section>
        </div>
    </ChartBlockFrame>
</template>
