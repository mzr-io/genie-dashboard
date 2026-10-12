<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, ref, useId, watch } from 'vue';
import DateRangeCalendar from '@/components/DateRangeCalendar.vue';
import { Button } from '@/components/ui/button';
import { DASHBOARD_PRESETS, rangeText } from '@/lib/dateRange';
import type { DateRangeValue } from '@/lib/dateRange';
import { dashboardLabels as labels } from '@/locales/labels';

// The Date Range control (Story 4.15; UX-DR-84, 164): a button that shows the current range and opens a popover with Today, This week, This month,
// Current year and Custom (with the calendar) and Apply. Apply is disabled, with the reason, until a Custom range has both ends. Escape closes it and
// puts focus back on the button (the popover's own behaviour). Choosing never fetches by itself: Apply emits and the page sends `SetDateRange`.
const props = defineProps<{
    value: DateRangeValue | null;
    busy?: boolean;
    disabled?: boolean;
}>();

const emit = defineEmits<{ apply: [value: DateRangeValue] }>();

const open = ref(false);
const choice = ref<string>('this_month');
const dates = ref<{ from: string | null; to: string | null }>({
    from: null,
    to: null,
});
const reasonId = useId();

watch(open, (isOpen) => {
    if (isOpen) {
        choice.value = props.value?.range ?? 'this_month';
        dates.value = {
            from: props.value?.from ?? null,
            to: props.value?.to ?? null,
        };
    }
});

const label = computed(() => {
    const v = props.value;

    if (v === null) {
        return labels.dateRange;
    }

    if (v.range === 'custom' && v.from !== null && v.to !== null) {
        return rangeText(v.from, v.to);
    }

    return labels.periods[v.range] ?? labels.dateRange;
});

const custom = computed(() => choice.value === 'custom');
const complete = computed(
    () =>
        !custom.value || (dates.value.from !== null && dates.value.to !== null),
);

function apply(): void {
    if (!complete.value) {
        return;
    }

    emit('apply', {
        range: choice.value,
        from: custom.value ? dates.value.from : null,
        to: custom.value ? dates.value.to : null,
    });
    open.value = false;
}
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="secondary"
                :aria-disabled="disabled ? 'true' : undefined"
                :aria-busy="busy ? 'true' : undefined"
                :aria-label="`${labels.dateRangeTitle}: ${label}`"
                data-slot="date-range"
                @click="(e: Event) => disabled && e.preventDefault()"
            >
                <LoaderCircle
                    v-if="busy"
                    class="size-4 animate-spin"
                    aria-hidden="true"
                />
                {{ label }}
                <span class="sr-only">{{
                    busy ? labels.dateRangeBusy : ''
                }}</span>
            </Button>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="6"
                class="z-50 flex max-w-[calc(100vw-2rem)] flex-col gap-3 rounded-lg border border-border-default bg-surface-card p-3 shadow-lg"
                data-slot="date-range-popover"
            >
                <div class="flex flex-wrap items-start gap-4">
                    <ul
                        role="listbox"
                        :aria-label="labels.dateRangeTitle"
                        class="flex min-w-32 flex-col gap-0.5"
                    >
                        <li
                            v-for="preset in [...DASHBOARD_PRESETS, 'custom']"
                            :key="preset"
                            role="option"
                            :aria-selected="
                                choice === preset ? 'true' : 'false'
                            "
                        >
                            <button
                                type="button"
                                class="type-body-sm w-full rounded-md px-3 py-1.5 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :class="
                                    choice === preset
                                        ? 'bg-accent-soft font-semibold text-accent-ink'
                                        : 'text-text-primary hover:bg-surface-muted'
                                "
                                :data-preset="preset"
                                @click="choice = preset"
                            >
                                {{ labels.periods[preset] }}
                            </button>
                        </li>
                    </ul>
                    <DateRangeCalendar v-if="custom" v-model="dates" />
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p
                        :id="reasonId"
                        class="type-caption text-text-secondary"
                        data-slot="date-range-summary"
                    >
                        <template v-if="!complete">{{
                            labels.dateRangeNeedsBoth
                        }}</template>
                        <template
                            v-else-if="custom && dates.from && dates.to"
                            >{{ rangeText(dates.from, dates.to) }}</template
                        >
                    </p>
                    <div class="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            @click="open = false"
                        >
                            {{ labels.dateRangeCancel }}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            :aria-disabled="complete ? undefined : 'true'"
                            :aria-describedby="complete ? undefined : reasonId"
                            data-slot="date-range-apply"
                            @click="apply"
                        >
                            {{ labels.dateRangeApply }}
                        </Button>
                    </div>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
