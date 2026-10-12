<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { computed, nextTick, ref, watch } from 'vue';
import { announce } from '@/lib/announce';
import {
    addDays,
    addMonths,
    monthKey,
    monthTitle,
    monthWeeks,
    shortDay,
    weekday,
} from '@/lib/dateRange';
import { dashboardLabels as labels } from '@/locales/labels';

// The `date-range-calendar` (UX-DR-116, UX-DR-164): two months side by side, one below 640 px, each month an APG date grid. Arrow keys move by day
// and week, Page Up and Page Down by month, Home and End to the week's ends (Monday to Sunday); Enter or Space sets the start, then the end, and says
// "May 1 to May 31 selected". The value is the two ends as plain days; the first press after a complete range starts a new one.
const model = defineModel<{ from: string | null; to: string | null }>({
    required: true,
});

const wide = useMediaQuery('(min-width: 640px)');
const today = new Date();
const initial =
    model.value.from ??
    `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
const focused = ref(initial);
const anchor = ref(monthKey(initial));
const root = ref<HTMLElement | null>(null);

const months = computed(() => {
    const [y, m] = anchor.value.split('-').map(Number);
    const first = [{ year: y, month: m }];

    if (wide.value) {
        const next = new Date(Date.UTC(y, m, 1));

        first.push({
            year: next.getUTCFullYear(),
            month: next.getUTCMonth() + 1,
        });
    }

    return first.map((v) => ({
        key: `${v.year}-${String(v.month).padStart(2, '0')}`,
        title: monthTitle(`${v.year}-${String(v.month).padStart(2, '0')}-01`),
        weeks: monthWeeks(v.year, v.month),
    }));
});

const visible = (iso: string): boolean =>
    months.value.some((m) => m.key === monthKey(iso));

function moveTo(iso: string): void {
    focused.value = iso;

    if (!visible(iso)) {
        const first = months.value[0].key;
        // Earlier than the first month: it becomes the first; later than the last: it becomes the last (the first, with one month shown).
        anchor.value =
            monthKey(iso) < first
                ? monthKey(iso)
                : months.value.length === 2
                  ? monthKey(addMonths(`${monthKey(iso)}-01`, -1))
                  : monthKey(iso);
    }

    void nextTick(() =>
        root.value?.querySelector<HTMLElement>(`[data-date="${iso}"]`)?.focus(),
    );
}

function shiftMonths(by: number): void {
    anchor.value = monthKey(addMonths(`${anchor.value}-01`, by));
}

function choose(iso: string): void {
    const { from, to } = model.value;

    if (from === null || to !== null) {
        model.value = { from: iso, to: null };
        announce(labels.dateRangeStart(shortDay(iso)));

        return;
    }

    const [a, b] = iso < from ? [iso, from] : [from, iso];

    model.value = { from: a, to: b };
    announce(labels.dateRangeChosen(shortDay(a), shortDay(b)));
}

function onKey(event: KeyboardEvent, iso: string): void {
    const step: Record<string, string> = {
        ArrowLeft: addDays(iso, -1),
        ArrowRight: addDays(iso, 1),
        ArrowUp: addDays(iso, -7),
        ArrowDown: addDays(iso, 7),
        PageUp: addMonths(iso, -1),
        PageDown: addMonths(iso, 1),
        Home: addDays(iso, -weekday(iso)),
        End: addDays(iso, 6 - weekday(iso)),
    };

    if (event.key in step) {
        event.preventDefault();
        moveTo(step[event.key]);

        return;
    }

    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        choose(iso);
    }
}

const state = (iso: string) => {
    const { from, to } = model.value;
    const end = to ?? from;

    return {
        edge: iso === from || iso === to,
        between:
            from !== null &&
            end !== null &&
            iso > (from < end ? from : end) &&
            iso < (from < end ? end : from),
    };
};

watch(
    () => model.value.from,
    (from) => {
        if (from !== null && !visible(from)) {
            anchor.value = monthKey(from);
        }
    },
);

const weekdays = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
const tabbable = computed(() =>
    visible(focused.value) ? focused.value : null,
);
</script>

<template>
    <div
        ref="root"
        class="flex items-start gap-3"
        role="group"
        :aria-label="labels.dateRangeCalendar"
        data-slot="date-range-calendar"
    >
        <button
            type="button"
            class="mt-1 rounded-md border border-border-default px-2 py-1 text-text-secondary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            aria-label="Previous month"
            data-slot="calendar-previous"
            @click="shiftMonths(-1)"
        >
            ‹
        </button>
        <div class="flex flex-wrap gap-4">
            <div v-for="month in months" :key="month.key">
                <p class="type-title-sm mb-1 text-center text-text-primary">
                    {{ month.title }}
                </p>
                <table
                    role="grid"
                    :aria-label="month.title"
                    class="border-collapse"
                >
                    <thead>
                        <tr>
                            <th
                                v-for="(letter, i) in weekdays"
                                :key="i"
                                scope="col"
                                class="type-caption size-7 text-center font-normal text-text-muted"
                            >
                                {{ letter }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(week, w) in month.weeks" :key="w">
                            <td
                                v-for="(day, d) in week"
                                :key="d"
                                role="gridcell"
                                class="p-0 text-center"
                                :class="
                                    day !== null && state(day).between
                                        ? 'bg-accent-soft'
                                        : ''
                                "
                            >
                                <button
                                    v-if="day !== null"
                                    type="button"
                                    class="type-body-sm size-7 rounded-full tabular-nums focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :class="
                                        state(day).edge
                                            ? 'bg-brand text-on-accent'
                                            : 'text-text-primary hover:bg-surface-muted'
                                    "
                                    :tabindex="day === tabbable ? 0 : -1"
                                    :aria-selected="
                                        state(day).edge || state(day).between
                                            ? 'true'
                                            : 'false'
                                    "
                                    :aria-label="`${shortDay(day)}, ${day.slice(0, 4)}`"
                                    :data-date="day"
                                    @click="
                                        focused = day;
                                        choose(day);
                                    "
                                    @keydown="onKey($event, day)"
                                >
                                    {{ Number(day.slice(8)) }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <button
            type="button"
            class="mt-1 rounded-md border border-border-default px-2 py-1 text-text-secondary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            aria-label="Next month"
            data-slot="calendar-next"
            @click="shiftMonths(1)"
        >
            ›
        </button>
    </div>
</template>
