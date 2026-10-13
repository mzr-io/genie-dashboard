<script setup lang="ts">
import KpiRenderer from '@block-types/kpi-card/v1/Renderer.vue';
import type { KpiCardPayload } from '@block-types/kpi-card/v1/payload';
import BarRenderer from '@block-types/bar/v1/Renderer.vue';
import KpiChartRenderer from '@block-types/kpi-chart/v1/Renderer.vue';
import LineAreaRenderer from '@block-types/line-area/v1/Renderer.vue';
import PieDonutRenderer from '@block-types/pie-donut/v1/Renderer.vue';
import ActivityFeedRenderer from '@block-types/activity-feed/v1/Renderer.vue';
import CalendarAgendaRenderer from '@block-types/calendar-agenda/v1/Renderer.vue';
import TextStatusRenderer from '@block-types/text-status/v1/Renderer.vue';
import ListRenderer from '@block-types/list/v1/Renderer.vue';
import ProgressListRenderer from '@block-types/progress-list/v1/Renderer.vue';
import TableRenderer from '@block-types/table/v1/Renderer.vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SegmentedControl from '@/components/SegmentedControl.vue';
import PreviewHotspots from '@/components/wizard/PreviewHotspots.vue';
import type { Hotspot, Region } from '@/components/wizard/PreviewHotspots.vue';
import SourceLegend from '@/components/wizard/SourceLegend.vue';
import { createPreviewRunner, fetchPreview } from '@/lib/blockPreview';
import { expandKpiPayload } from '@/lib/textTemplate';
import type { PreviewProblem, PreviewResult } from '@/lib/blockPreview';
import type { ChecklistSlot, RoleField } from '@/lib/blockMapping';
import { WizardError, heightLabel, sizeText } from '@/lib/blockWizard';
import type { DisplayConfig } from '@/lib/blockWizard';
import {
    blockMapLabels,
    blockPreviewLabels,
    blockWizardLabels as labels,
} from '@/locales/labels';

// The live preview (Story 3.4): the kpi-card in its Loading state (there is no data yet), re-rendered on every change from the form's
// state. Desktop, Tablet and Mobile change the canvas width only; the size pill under it reads "3 columns × Small". Under the pane,
// `version-safe` names the version Publishing will create.
// On Map Data (Story 3.14, `live`) the card shows what the Query Plan engine made of the Draft's sample (`POST .../preview`, debounced, the
// newest answer only, the last good one kept when a refresh fails), hotspots over its mapped regions, and the source legend under it. The
// Renderer is not touched: the regions are found by its own `data-test` markers and measured.
const props = withDefaults(
    defineProps<{
        name: string;
        description: string;
        display: DisplayConfig;
        version: string;
        // Map Data is open: render the server's payload.
        live?: boolean;
        draftId?: string | null;
        // Bumped by the page after every mapping write.
        refreshKey?: number;
        sampleRevision?: number | null;
        // "GET /api/v2/finance/revenue", for the sample note.
        endpoint?: string | null;
        slots?: ChecklistSlot[];
        roles?: RoleField[];
        // Why nothing can be changed (read-only, offline, published): hotspots are then not buttons.
        blocked?: string;
        // The Block Type being drawn (Story 5.8c): its Renderer and the regions of its slots.
        typeKey?: string;
        // The comparison is a prior-period request (Story 5.6): the sample has no value for it.
        priorComparison?: boolean;
    }>(),
    {
        live: false,
        draftId: null,
        refreshKey: 0,
        sampleRevision: null,
        endpoint: null,
        slots: () => [],
        roles: () => [],
        blocked: undefined,
        typeKey: 'kpi-card',
        priorComparison: false,
    },
);

const emit = defineEmits<{
    // A hotspot (or the placeholder) was activated: open that slot's picker; Esc returns focus to `opener`.
    (e: 'change', slot: string, opener: HTMLElement): void;
    (e: 'skip'): void;
    // The session ended under a preview request: the page handles it like any other step's.
    (e: 'auth-error', error: unknown): void;
    // The rows before the transform steps and after each of them (Story 5.1), or null while there are none.
    (e: 'rows', rows: { input: number; steps: number[] } | null): void;
}>();

const { t } = useI18n();
const device = ref('desktop');

// Canvas widths in px for the three devices; Desktop fills the pane.
const CANVAS: Record<string, string> = {
    desktop: '100%',
    tablet: '480px',
    mobile: '320px',
};
// Card heights, Medium being 360 px.
const HEIGHT_PX: Record<string, number> = {
    small: 180,
    medium: 360,
    large: 540,
};

// Slot key -> the Renderer's marker of its region (the Renderer shows an unavailable headline in its own element).
const REGIONS: Record<string, { selector: string; text?: string }> = {
    headline_value: {
        selector: '[data-test=kpi-headline],[data-test=kpi-unavailable]',
    },
    comparison: {
        selector: '[data-test=kpi-delta]',
        text: '[data-test=kpi-delta-visible]',
    },
    kpi_label: { selector: '[data-test=kpi-label]' },
    secondary_note: { selector: '[data-test=kpi-note]' },
    status_badge: { selector: '[data-test=kpi-badge]' },
};
const BODY = '__body';

// A chart's regions (Story 5.8c): the plot for the axis, the target and the highlight, and the legend entry (else the plot) for each series.
// A chart's regions (Stories 5.8c and 5.11): the plot for every slot of the chart, and the legend entry (else the plot) for each numbered series.
function chartRegions(
    keys: string[],
): Record<string, { selector: string; text?: string }> {
    const out: Record<string, { selector: string; text?: string }> = {};

    for (const key of keys) {
        const numbered = /_(\d+)$/.exec(key);

        out[key] = numbered
            ? {
                  selector:
                      numbered[1] === '1'
                          ? '[data-test=chart-legend] li:nth-child(1), [data-test=chart-plot]'
                          : `[data-test=chart-legend] li:nth-child(${numbered[1]})`,
              }
            : { selector: '[data-test=chart-plot]' };
    }

    return out;
}

const CHART_SLOTS: Record<string, string[]> = {
    'line-area': [
        'x',
        'highlight_x',
        'target_value',
        'series_1',
        'series_2',
        'series_3',
        'series_4',
        'series_5',
        'series_6',
    ],
    'kpi-chart': [
        'x',
        'series_1',
        'series_2',
        'series_3',
        'series_4',
        'series_5',
        'series_6',
    ],
    bar: [
        'category',
        'series_key',
        'values_1',
        'values_2',
        'values_3',
        'values_4',
        'values_5',
        'values_6',
    ],
    'pie-donut': ['pie_category', 'pie_value'],
};
// A table's regions (Story 5.12): the header cell of each column, else the table itself.
const TABLE_REGIONS: Record<string, { selector: string; text?: string }> = {
    row_key: { selector: '[data-test=table-region]' },
    ...Object.fromEntries(
        Array.from({ length: 12 }, (_, n) => [
            `col_${n + 1}`,
            {
                selector: `[data-test=table-region] thead th:nth-child(${n + 1})`,
            },
        ]),
    ),
};
// A list's and a progress list's regions (Story 5.13): the first row's own part, else the whole list.
const LIST_REGIONS: Record<string, { selector: string; text?: string }> = {
    item_title: { selector: '[data-test=list-title]' },
    item_subtitle: { selector: '[data-test=list-subtitle]' },
    item_avatar: { selector: '[data-test=avatar-initials]' },
    item_icon: { selector: '[data-test=list-row]' },
    item_value: { selector: '[data-test=list-value]' },
    item_meta: { selector: '[data-test=list-meta]' },
    item_status: { selector: '[data-test=list-status]' },
    row_key: { selector: '[data-test=list-block]' },
};
const ACTIVITY_REGIONS: Record<string, { selector: string; text?: string }> = {
    actor: { selector: '[data-test=activity-actor]' },
    action: { selector: '[data-test=activity-action]' },
    object: { selector: '[data-test=activity-object]' },
    timestamp: { selector: '[data-test=activity-time]' },
    avatar: { selector: '[data-test=avatar-initials]' },
    row_key: { selector: '[data-test=activity-feed-block]' },
};
const AGENDA_REGIONS: Record<string, { selector: string; text?: string }> = {
    event_date: { selector: '[data-test=agenda-day]' },
    start_time: { selector: '[data-test=agenda-time]' },
    event_title: { selector: '[data-test=agenda-title]' },
    event_subtitle: { selector: '[data-test=agenda-subtitle]' },
    duration: { selector: '[data-test=agenda-subtitle]' },
    category: { selector: '[data-test=agenda-category]' },
    row_key: { selector: '[data-test=calendar-block]' },
};
const TEXT_STATUS_REGIONS: Record<string, { selector: string; text?: string }> =
    {
        status_text: {
            selector: '[data-test=text-body],[data-test=status-pill]',
        },
    };
const PROGRESS_REGIONS: Record<string, { selector: string; text?: string }> = {
    item_title: { selector: '[data-test=progress-title]' },
    progress_value: { selector: '[data-test=progress-percent]' },
    progress_total: { selector: '[data-test=progress-percent]' },
    item_caption: { selector: '[data-test=progress-caption]' },
    row_key: { selector: '[data-test=progress-list-block]' },
};
const isChart = computed(() => props.typeKey in CHART_SLOTS);
// A KPI & Chart has the card's regions and the chart's (Story 5.10).
const regionMap = computed(() =>
    props.typeKey === 'activity-feed'
        ? ACTIVITY_REGIONS
        : props.typeKey === 'calendar-agenda'
          ? AGENDA_REGIONS
          : props.typeKey === 'text-status'
            ? TEXT_STATUS_REGIONS
            : props.typeKey === 'list'
              ? LIST_REGIONS
              : props.typeKey === 'progress-list'
                ? PROGRESS_REGIONS
                : props.typeKey === 'table'
                  ? TABLE_REGIONS
                  : props.typeKey === 'kpi-chart'
                    ? { ...REGIONS, ...chartRegions(CHART_SLOTS['kpi-chart']) }
                    : isChart.value
                      ? chartRegions(CHART_SLOTS[props.typeKey])
                      : REGIONS,
);
// The slot a Block cannot be drawn without: the headline of a card, the axis (or the categories) of a chart.
const primarySlot = computed(
    () =>
        ({
            'line-area': 'x',
            bar: 'category',
            'pie-donut': 'pie_category',
            table: 'col_1',
            list: 'item_title',
            'activity-feed': 'actor',
            'calendar-agenda': 'event_title',
            'text-status': 'status_text',
            'progress-list': 'progress_value',
        })[props.typeKey] ?? 'headline_value',
);
// Both are imported here, not loaded on demand: the regions are measured as soon as the card is drawn.
const rendererOf = computed(
    () =>
        ({
            'line-area': LineAreaRenderer,
            'kpi-chart': KpiChartRenderer,
            bar: BarRenderer,
            'pie-donut': PieDonutRenderer,
            table: TableRenderer,
            list: ListRenderer,
            'activity-feed': ActivityFeedRenderer,
            'calendar-agenda': CalendarAgendaRenderer,
            'text-status': TextStatusRenderer,
            'progress-list': ProgressListRenderer,
        })[props.typeKey] ?? KpiRenderer,
);

const result = ref<PreviewResult | null>(null);
const problem = ref<PreviewProblem | null>(null);
const regions = ref<Record<string, { rect: Region['rect']; text: string }>>({});
const card = ref<HTMLElement | null>(null);
const scroller = ref<HTMLElement | null>(null);
const scrollable = ref(false);
const bounds = ref({ w: 0, h: 0 });
let observer: ResizeObserver | null = null;

const canvas = ref<HTMLElement | null>(null);
let retryTimer: ReturnType<typeof setTimeout> | null = null;
let retried = false;

function clearRetry(): void {
    if (retryTimer !== null) {
        clearTimeout(retryTimer);
        retryTimer = null;
    }
}

watch(
    () => result.value?.rows ?? null,
    (rows) => emit('rows', rows),
);

const shownPayload = computed(() =>
    expandKpiPayload(payload.value, profile.value),
);

const runner = createPreviewRunner({
    load: (signal) =>
        props.draftId === null
            ? Promise.reject(new WizardError(0))
            : fetchPreview(props.draftId, signal),
    onStart() {
        problem.value = null;
    },
    onResult(next) {
        result.value = next;
        problem.value = null;
    },
    onProblem(next) {
        problem.value = next;

        // Nothing usable to show (no plan, no sample, a guard or an unset setting): the card must not contradict the notice.
        if (['no_plan', 'no_sample', 'guard', 'setting'].includes(next.kind)) {
            result.value = null;
        }

        if (next.kind === 'session') {
            emit('auth-error', new WizardError(next.status ?? 401));
        }

        // A throttled request is tried once more when the server says the window is over.
        if (next.kind === 'throttled' && next.retryAfter && !retried) {
            retried = true;
            clearRetry();
            retryTimer = setTimeout(() => {
                retryTimer = null;
                runner.request(true);
            }, next.retryAfter * 1000);
        }
    },
});

// Every ask that is not the retry itself starts the once-only retry over and drops a waiting one.
function ask(immediate = false): void {
    if (props.draftId === null) {
        return;
    }

    clearRetry();
    retried = false;
    runner.request(immediate);
}

const active = computed(() => props.live && props.draftId !== null);

watch(
    active,
    (on) => {
        if (on) {
            ask(true);
        } else {
            clearRetry();
            runner.cancel();
            result.value = null;
            problem.value = null;
        }
    },
    { immediate: true },
);
watch(
    () => props.draftId,
    (id, prev) => {
        if (active.value && id !== null && prev !== null) {
            ask(true);
        }
    },
);
watch(
    () => props.refreshKey,
    () => {
        if (active.value) {
            ask();
        }
    },
);
// A new sample (not the first read of it) re-renders the preview.
watch(
    () => props.sampleRevision,
    (next, prev) => {
        if (active.value && prev !== null && next !== prev) {
            ask();
        }
    },
);

// A preview that failed with nothing to show reads Unavailable (never an endless Loading card); no plan reads the same.
const empty = computed(
    () => active.value && result.value === null && problem.value !== null,
);

// Text Templates reach the browser in their wire form: the signed-in Admin's own name and email are what `{user.*}` shows in the preview.
const page = usePage();
const profile = computed(() => {
    const user = (
        page.props.auth as
            | { user?: { name?: string; email?: string } }
            | undefined
    )?.user;

    return { name: user?.name ?? null, email: user?.email ?? null };
});

const payload = computed<KpiCardPayload>(() => {
    const frame = {
        title: props.display.show_header ? props.name.trim() || null : null,
        subtitle: props.display.show_subtitle
            ? props.description.trim() || null
            : null,
        icon: null,
    };

    if (active.value && result.value !== null) {
        return { ...result.value.payload, ...frame };
    }

    // No plan yet: the card reads Unavailable, and the placeholder over it invites the first mapping.
    if (empty.value) {
        return {
            state: 'unavailable',
            ...frame,
            footerAction: null,
            kpiLabel: null,
            headline: { available: false, field: 'headline_value' },
            comparison: null,
            comparisonLabel: null,
            secondaryNote: null,
            statusBadge: null,
            staleSince: null,
            errorMessage: null,
        };
    }

    return {
        state: 'loading',
        ...frame,
        footerAction: null,
        kpiLabel: null,
        headline: { available: false, field: 'headline_value' },
        comparison: null,
        comparisonLabel: null,
        secondaryNote: null,
        statusBadge: null,
        staleSince: null,
        errorMessage: null,
    };
});

const deviceLabel = computed(
    () =>
        labels.deviceOptions.find((option) => option.value === device.value)
            ?.label ?? '',
);
const pill = computed(() => sizeText(props.display));
const canvasStyle = computed(() => ({
    width: CANVAS[device.value],
    maxWidth: '100%',
}));
const cardStyle = computed(() => ({
    height: `${HEIGHT_PX[props.display.height] ?? 180}px`,
}));

// ---- Regions, hotspots, notices ----------------------------------------------------------------------------------------

const normal = (text: string | null | undefined): string =>
    (text ?? '').replace(/\s+/g, ' ').trim();

// Finds each marked region in the rendered card and measures it against the canvas (the hotspots' positioned box).
function measure(): void {
    const box = canvas.value;

    if (box === null || !active.value) {
        regions.value = {};

        return;
    }

    const base = box.getBoundingClientRect();

    bounds.value = { w: base.width, h: base.height };
    scrollable.value =
        scroller.value !== null &&
        scroller.value.scrollWidth > scroller.value.clientWidth;

    const found: typeof regions.value = {};
    const put = (key: string, el: Element | null, text?: string): void => {
        if (el === null) {
            return;
        }

        const at = el.getBoundingClientRect();

        found[key] = {
            rect: {
                x: at.left - base.left,
                y: at.top - base.top,
                w: at.width,
                h: at.height,
            },
            text: normal(text ?? el.textContent),
        };
    };

    for (const [slot, region] of Object.entries(regionMap.value)) {
        const el = box.querySelector(region.selector);

        put(
            slot,
            el,
            region.text && el
                ? (el.querySelector(region.text)?.textContent ?? undefined)
                : undefined,
        );
    }

    put(BODY, box.querySelector('[data-test=kpi-body],[data-test=chart-body]'));
    regions.value = found;
}

async function remeasure(): Promise<void> {
    await nextTick();
    measure();
}

watch([payload, device, () => props.display, active], () => void remeasure(), {
    deep: true,
});

onMounted(() => {
    void remeasure();

    if (typeof ResizeObserver !== 'undefined' && canvas.value) {
        observer = new ResizeObserver(() => measure());
        observer.observe(canvas.value);

        if (card.value) {
            observer.observe(card.value);
        }
    }

    // A web font that arrives late moves the regions.
    void document.fonts?.ready?.then(() => measure());

    window.addEventListener('resize', measure);
});

onBeforeUnmount(() => {
    clearRetry();
    runner.cancel();
    observer?.disconnect();
    window.removeEventListener('resize', measure);
});

const slotOf = (key: string): ChecklistSlot | undefined =>
    props.slots.find((slot) => slot.slot === key);

const hotspots = computed<Hotspot[]>(() => {
    const found = result.value;

    if (!active.value || found === null) {
        return [];
    }

    return Object.keys(regionMap.value).flatMap((key) => {
        const item = slotOf(key);
        const region = regions.value[key];

        if (!item || !item.path || !item.display || !region) {
            return [];
        }

        const server = found.slots[key];

        return [
            {
                slot: key,
                label: item.label,
                field: item.display,
                // What the card shows, else the engine's value for the slot, never an empty name.
                value:
                    region.text ||
                    server?.value ||
                    blockPreviewLabels.unavailable,
                rect: region.rect,
                // A refresh is pending: this result was made for another field, so the pair is not offered until it lands.
                pending: server?.path !== item.path,
            },
        ];
    });
});

// A required headline with nothing mapped: the placeholder sits over the headline's region (or the card's body when the card shows none).
const missing = computed<Region | null>(() => {
    const item = slotOf(primarySlot.value);

    if (!active.value || !item || item.path || !item.required) {
        return null;
    }

    if (item.state !== 'required_missing') {
        return null;
    }

    const at = regions.value[primarySlot.value] ?? regions.value[BODY];

    return at ? { slot: item.slot, label: item.label, rect: at.rect } : null;
});

const rendered = computed<Record<string, string>>(() =>
    Object.fromEntries(
        Object.entries(regions.value).map(([key, value]) => [key, value.text]),
    ),
);

// Quiet notices under the card; a refresh that fails never takes the last good preview away.
const notices = computed<string[]>(() => {
    if (!active.value) {
        return [];
    }

    const out: string[] = [];
    const found = problem.value;

    if (result.value?.plan_stale) {
        out.push(blockPreviewLabels.stale);
    }

    if (found?.kind === 'throttled') {
        out.push(blockPreviewLabels.throttled(found.retryAfter ?? null));
    } else if (found?.kind === 'guard') {
        out.push(blockPreviewLabels.guard);
    } else if (found?.kind === 'setting') {
        out.push(blockPreviewLabels.setting(found.setting ?? null));
    } else if (found?.kind === 'no_sample') {
        out.push(blockPreviewLabels.noSample);
    } else if (found?.kind === 'unavailable' || found?.kind === 'failed') {
        out.push(
            result.value
                ? blockPreviewLabels.failed
                : blockPreviewLabels.failedEmpty,
        );
    }

    return out;
});
</script>

<template>
    <aside
        aria-labelledby="wizard-preview-title"
        data-slot="live-preview"
        class="flex flex-col gap-3 rounded-lg border border-border-default bg-surface-card p-4"
    >
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2
                    id="wizard-preview-title"
                    class="type-title-sm text-text-primary"
                >
                    {{ labels.previewTitle }}
                </h2>
                <p class="type-caption text-text-muted">
                    {{ labels.previewCaption }}
                </p>
                <span
                    v-if="live"
                    class="type-caption mt-1 inline-block rounded-sm bg-info-soft px-2 text-info-strong"
                    data-test="preview-sample-label"
                    >{{ blockPreviewLabels.sampleLabel }}</span
                >
            </div>
            <SegmentedControl
                v-model="device"
                :options="labels.deviceOptions"
                :label="labels.devices"
            />
        </header>
        <a
            v-if="live"
            href="#slot-checklist"
            class="type-body-sm sr-only w-fit rounded-sm text-accent-ink underline focus:not-sr-only focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
            data-test="skip-to-checklist"
            @click.prevent="emit('skip')"
            >{{ blockPreviewLabels.skipToChecklist }}</a
        >
        <div
            ref="scroller"
            class="overflow-x-auto rounded-md bg-surface-sunken p-3 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
            :tabindex="live && scrollable ? 0 : undefined"
            :role="live ? 'region' : undefined"
            :aria-label="live ? blockPreviewLabels.region : undefined"
            data-test="preview-region"
        >
            <div
                ref="canvas"
                role="group"
                :aria-label="labels.canvas(deviceLabel)"
                :data-device="device"
                data-test="preview-canvas"
                class="relative mx-auto"
                :style="canvasStyle"
            >
                <div
                    ref="card"
                    class="overflow-hidden rounded-lg border border-border-default bg-surface-card"
                    :style="cardStyle"
                    :data-height="display.height"
                    :title="heightLabel(display.height)"
                >
                    <component
                        :is="rendererOf"
                        :payload="shownPayload"
                        audience="admin"
                    />
                </div>
                <PreviewHotspots
                    v-if="live"
                    :hotspots="hotspots"
                    :missing="missing"
                    :blocked="!!blocked"
                    :bounds="bounds"
                    :missing-role="blockMapLabels.roleNames.value"
                    @change="(slot, opener) => emit('change', slot, opener)"
                />
            </div>
        </div>
        <p
            v-if="live && blocked"
            class="type-caption text-text-muted"
            data-test="preview-readonly"
        >
            {{ blockPreviewLabels.readOnlyRegions }}
        </p>
        <p
            class="type-caption text-center font-semibold text-text-secondary"
            data-test="size-pill"
        >
            <span class="rounded-full bg-surface-muted px-2 py-0.5">{{
                pill
            }}</span>
        </p>
        <div
            v-if="live"
            class="flex flex-col gap-1"
            role="status"
            data-test="preview-notices"
        >
            <p
                v-for="notice in notices"
                :key="notice"
                class="type-caption text-text-secondary"
                data-test="preview-notice"
            >
                {{ notice }}
            </p>
        </div>
        <SourceLegend
            v-if="live"
            :slots="slots"
            :roles="roles"
            :result="result"
            :rendered="rendered"
            :endpoint="endpoint"
        />
        <p
            v-if="live && priorComparison"
            class="type-caption text-text-secondary"
            data-test="prior-checked"
        >
            {{ blockPreviewLabels.priorChecked }}
        </p>
        <p class="type-caption text-text-muted" data-test="version-safe">
            {{ t('version-safe', { version }) }}
        </p>
    </aside>
</template>
