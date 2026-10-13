<script setup lang="ts">
import {
    EllipsisVertical,
    Minus,
    Plus,
    RefreshCw,
    ArrowUpRight,
} from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import ExpandedView from '@/components/ExpandedView.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { iconFor } from '@/lib/blockIcons';
import { NO_LIMITS, canNarrow, canWiden } from '@/lib/layout';
import { rendererFor, tableFor } from '@/lib/blockRenderers';
import { expandKpiPayload, expandTemplate } from '@/lib/textTemplate';
import { kpiPayloadOf } from '@/lib/dashboards';
import type { DashboardItem, ItemResult } from '@/lib/dashboards';
import { resolveFooterLink } from '@/lib/footerAction';
import type { FooterProfile } from '@/lib/footerAction';
import { formatDateTime } from '@/lib/format';
import { addBlocksLabels, dashboardLabels as labels } from '@/locales/labels';

// One Block on a dashboard (Story 4.4 to 4.6; UX-DR-50, 51, 231): a `section` labelled by its `h2` title, on the card surface with a 1px border and the
// large radius. The server decides the state. An item that is not `ok` shows its message and a Remove action and no data. A Block with no result yet
// shows its real header and its own skeleton; one with a result draws it with its Block Type's Renderer, which implements every state and writes each
// slot as literal text. A later result replaces the last one in place. The header carries the controls the Admin allowed (Refresh, Minimize, the ⋯ menu,
// each with its Block's name and a 28 px hit area); Minimize leaves the header only. The body is the focusable region that scrolls when its content
// overflows, never a clipping box (UX-DR-149). Remove is in place and says it is not available yet: the layout commands arrive with Story 4.9.
const props = withDefaults(
    defineProps<{
        item: DashboardItem;
        result?: ItemResult | null;
        // The member's own minimize choice, once made; the layout's value otherwise.
        minimized?: boolean | null;
        // A manual refresh is in flight for this Block.
        refreshing?: boolean;
        // The manual-refresh window is open: Refresh is aria-disabled and says why.
        refreshLimited?: boolean;
        // Admins also see technical details in About this block; a User never does.
        admin?: boolean;
        // Just added to the dashboard: outlined, with "Just added", for a few seconds.
        justAdded?: boolean;
        // Edit Layout Mode (Story 4.11): the whole header is a move handle and a Block that allows resize shows a 24 x 24 handle at its corner.
        editing?: boolean;
        // Picked up with the keyboard (Story 4.12): the Block is shown lifted and its Move button is pressed.
        pickedUp?: boolean;
        // A single-column layout (Story 4.12): the menu reorders (Move up, Move down) and offers no free resize.
        compact?: boolean;
        // Whether Move up and Move down have anywhere to go (the first and the last Block, or the top row), with the reasons the menu shows.
        canMoveUp?: boolean;
        canMoveDown?: boolean;
        // The profile `{user.*}` footer tokens are filled from.
        profile?: FooterProfile;
        // The dashboard Date Range's token (Story 4.15), to say when this Block's own period differs from it.
        dateRange?: string | null;
        // The dashboard this Block is on: the rows of its Expanded view are read through it (Story 5.9).
        dashboardId?: string | null;
    }>(),
    {
        result: null,
        minimized: null,
        refreshing: false,
        refreshLimited: false,
        admin: false,
        justAdded: false,
        editing: false,
        pickedUp: false,
        compact: false,
        canMoveUp: true,
        canMoveDown: true,
        profile: () => ({}),
        dateRange: null,
        dashboardId: null,
    },
);

const emit = defineEmits<{
    retry: [itemId: string];
    refresh: [itemId: string];
    minimize: [itemId: string, minimized: boolean];
    remove: [blockId: string, from: 'menu' | 'card'];
    // A pointer went down on the move handle or the resize handle.
    drag: [itemId: string, kind: 'move' | 'resize', event: PointerEvent];
    // A key went down on the Move button (M or Space picks up; arrows, Enter and Escape act on a picked-up Block).
    movekey: [itemId: string, event: KeyboardEvent];
    // A menu step: reorder or one step of position or width, in or outside Edit Layout Mode.
    step: [itemId: string, action: 'up' | 'down' | 'wider' | 'narrower'];
    // The member chose a period for this Own-selector Block (null: back to its default).
    period: [itemId: string, blockId: string, selection: string | null];
}>();

const { t } = useI18n();
const headingId = useId();
const limitedId = useId();
const aboutOpen = ref(false);
// The panel open over the card (Story 5.9): the chart's data as a table, or the full rows.
const viewOpen = ref<'table' | 'rows' | null>(null);
const menuButton = ref<HTMLElement | null>(null);

// The state to show: the layout's unless the results say the Block has since become unavailable.
const state = computed(() =>
    props.item.state !== 'ok'
        ? props.item.state
        : props.result?.state === 'access_removed' ||
            props.result?.state === 'unpublished'
          ? props.result.state
          : 'ok',
);
const unavailable = computed(() => state.value !== 'ok');
const title = computed(() => props.item.block?.name ?? labels.unavailableBlock);
const message = computed(() =>
    state.value === 'access_removed'
        ? t('block-access-removed')
        : t('block-unpublished'),
);
const chrome = computed(() => props.item.block?.chrome ?? null);
const showHeader = computed(
    () => unavailable.value || chrome.value?.show_header !== false,
);
const ownSelector = computed(
    () => !unavailable.value && props.item.period?.behaviour === 'own_selector',
);
// The period this Block shows: the member's choice, else its Default date range.
const effectivePeriod = computed(
    () => props.item.period?.selection ?? props.item.period?.default ?? null,
);
const periodOptions = computed(() => {
    const options = Object.keys(labels.periods).filter((k) => k !== 'custom');

    return effectivePeriod.value === 'custom'
        ? [...options, 'custom']
        : options;
});
// A Block whose period differs from the dashboard Date Range says so in its subtitle ("Financial performance · This year").
const subtitle = computed(() => {
    if (chrome.value?.show_subtitle === false) {
        return null;
    }

    const base = props.item.block?.subtitle ?? null;
    const differs =
        ownSelector.value &&
        props.dateRange !== null &&
        effectivePeriod.value !== null &&
        effectivePeriod.value !== props.dateRange;

    if (!differs) {
        return base;
    }

    const period = labels.periods[effectivePeriod.value as string];

    return base === null ? period : `${base} · ${period}`;
});

function onPeriodChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;

    emit('period', props.item.item_id, props.item.block_id, value);
}
const icon = computed(() => iconFor(props.item.block?.icon));
const isMinimized = computed(() => props.minimized ?? props.item.minimized);
const canRefresh = computed(
    () => !unavailable.value && chrome.value?.allow_refresh === true,
);
const canMinimize = computed(
    () => !unavailable.value && chrome.value?.allow_minimize === true,
);
const showMenu = computed(() => !unavailable.value);

const renderer = computed(() =>
    props.item.block === null ? null : rendererFor(props.item.block.type),
);
// The server's text is the same for every viewer; a Text Template's numbers, dates and `{user.*}` tokens are filled in here (Story 5.4).
const payload = computed(() => {
    const made =
        props.result === null || props.result === undefined
            ? null
            : kpiPayloadOf(props.result);

    return made === null ? null : expandKpiPayload(made, props.profile);
});

// A chart Block can always be read as a table, whatever its Expanded view setting; the table is built from what it displays.
const canViewAsTable = computed(
    () =>
        !unavailable.value &&
        props.item.block !== null &&
        tableFor(props.item.block.type) !== null &&
        payload.value !== null &&
        payload.value.state !== 'loading',
);
// The Expanded view is the footer action's target: a full-size table of the rows behind the Block.
const expandedFooter = computed(() => {
    const configured = props.item.block?.footer ?? null;

    return configured !== null &&
        configured.target === 'expanded_view' &&
        !unavailable.value &&
        chrome.value?.show_footer_action === true
        ? expandTemplate(configured.label, { profile: props.profile })
        : null;
});

function closeView(open: boolean): void {
    if (!open) {
        viewOpen.value = null;
        // The panel returns focus to what opened it: the menu button, which the menu item cannot keep.
        void Promise.resolve().then(() => menuButton.value?.focus());
    }
}

const footer = computed(() => {
    const configured = props.item.block?.footer ?? null;

    if (
        configured === null ||
        unavailable.value ||
        chrome.value?.show_footer_action !== true
    ) {
        return null;
    }

    // The Expanded view target is a button of its own (`expandedFooter`), not a link.
    if (
        configured.target !== 'external' ||
        configured.url === null ||
        configured.url === undefined
    ) {
        return null;
    }

    const link = resolveFooterLink(configured.url, props.profile);
    const label = expandTemplate(configured.label, { profile: props.profile });

    return link === null ? null : { ...link, label };
});

// "About this block": the description, the Data Source, the interval in words, when the data is from (full date and zone), the version and how the
// period is followed. Technical details (why a slot is unavailable) are for Admins only.
function intervalText(value: number | 'live' | null): string {
    if (value === 'live') {
        return labels.about.live;
    }

    if (value === null) {
        return labels.about.none;
    }

    for (const [size, unit] of [
        [3600, 'hour'],
        [60, 'minute'],
    ] as const) {
        if (value % size === 0) {
            return labels.about.every(value / size, unit);
        }
    }

    return labels.about.every(value, 'second');
}

const aboutRows = computed(() => {
    const about = props.item.block?.about;

    if (!about) {
        return [];
    }

    const asOf = props.result?.data_as_of ?? null;

    // A text Block clamps its text to three lines; the whole of it is here (Story 5.14).
    const shown = payload.value as unknown as {
        mode?: unknown;
        text?: unknown;
    } | null;
    const full: Array<[string, string]> =
        shown !== null &&
        shown.mode === 'text' &&
        typeof shown.text === 'string'
            ? [[labels.about.fullText, shown.text]]
            : [];

    return [
        ...full,
        [labels.about.description, about.description ?? labels.about.none],
        [labels.about.source, about.source_name ?? labels.about.none],
        [labels.about.interval, intervalText(about.refresh_interval)],
        [
            labels.about.asOf,
            asOf === null
                ? labels.about.none
                : // `dateStyle` and `timeStyle` cannot be combined with `timeZoneName`, so the parts are named.
                  formatDateTime(asOf, {
                      day: 'numeric',
                      month: 'long',
                      year: 'numeric',
                      hour: '2-digit',
                      minute: '2-digit',
                      timeZoneName: 'short',
                  }),
        ],
        [labels.about.version, about.version],
        [
            labels.about.period,
            about.period === null
                ? labels.about.none
                : labels.about.periods[about.period],
        ],
    ] as Array<[string, string]>;
});

const technical = computed(() =>
    props.admin
        ? Object.entries(props.result?.slot_states ?? {})
              .filter(([, slot]) => slot.state === 'unavailable')
              .map(([key, slot]) => `${key}: ${slot.reason ?? 'unavailable'}`)
        : [],
);

const widthLimits = computed(() => {
    const sizes = props.item.block?.sizes;

    return sizes
        ? {
              minWidth: sizes.min_width,
              maxWidth: sizes.max_width,
              minHeight: sizes.min_height,
              maxHeight: sizes.max_height,
          }
        : NO_LIMITS;
});
const widerAllowed = computed(
    () =>
        chrome.value?.allow_resize === true &&
        canWiden(props.item.geometry, widthLimits.value),
);
const narrowerAllowed = computed(
    () =>
        chrome.value?.allow_resize === true &&
        canNarrow(props.item.geometry, widthLimits.value),
);
const moveDescribedBy = useId();

const canResize = computed(
    () => !unavailable.value && chrome.value?.allow_resize === true,
);

// A drag starts anywhere on the header except its controls.
function onHeaderDown(event: PointerEvent): void {
    if (
        !props.editing ||
        event.button !== 0 ||
        (event.target instanceof Element &&
            event.target.closest('button, a, [role=menuitem]') !== null)
    ) {
        return;
    }

    event.preventDefault();
    emit('drag', props.item.item_id, 'move', event);
}

function refresh(): void {
    if (props.refreshLimited || props.refreshing) {
        return;
    }

    emit('refresh', props.item.item_id);
}
</script>

<template>
    <section
        data-slot="block-card"
        :data-state="state"
        :data-minimized="isMinimized ? 'true' : undefined"
        :data-just-added="justAdded ? 'true' : undefined"
        :aria-labelledby="headingId"
        class="relative flex min-h-0 flex-col overflow-hidden rounded-lg border bg-surface-card"
        :class="
            justAdded
                ? 'border-accent bg-success-soft outline outline-2 outline-accent'
                : 'border-border-default'
        "
    >
        <header
            class="flex items-start justify-between gap-2 px-4 py-3"
            :class="[
                showHeader || editing ? '' : 'sr-only',
                editing ? 'cursor-move touch-none select-none' : '',
            ]"
            :data-slot="editing ? 'move-handle' : undefined"
            @pointerdown="onHeaderDown"
        >
            <div class="flex min-w-0 items-start gap-2">
                <span
                    v-if="!unavailable"
                    class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md bg-surface-muted text-text-secondary"
                    aria-hidden="true"
                    data-slot="block-icon"
                >
                    <component
                        :is="refreshing ? RefreshCw : icon"
                        class="size-4"
                        :class="refreshing ? 'animate-spin' : ''"
                    />
                </span>
                <div class="min-w-0">
                    <h2
                        :id="headingId"
                        class="type-title-sm truncate text-text-primary"
                    >
                        {{ title }}
                    </h2>
                    <p v-if="subtitle" class="type-caption text-text-muted">
                        {{ subtitle }}
                    </p>
                    <p
                        v-if="justAdded"
                        class="type-caption font-semibold text-success-text"
                        data-slot="just-added"
                    >
                        {{ addBlocksLabels.justAdded }}
                    </p>
                </div>
            </div>

            <button
                v-if="editing && !unavailable"
                type="button"
                class="type-caption rounded-md border border-border-default px-2 py-1 text-text-secondary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="pickedUp ? 'bg-accent-soft text-accent-ink' : ''"
                :aria-label="labels.moveButton(title)"
                :aria-describedby="moveDescribedBy"
                :aria-pressed="pickedUp ? 'true' : 'false'"
                data-slot="move-button"
                @keydown="emit('movekey', item.item_id, $event)"
            >
                {{ labels.moveVerb }}
            </button>
            <span :id="moveDescribedBy" class="sr-only">{{
                labels.movePickup
            }}</span>

            <div
                v-if="canRefresh || canMinimize || showMenu"
                class="flex shrink-0 items-center gap-0.5"
                data-slot="block-controls"
            >
                <button
                    v-if="canRefresh"
                    type="button"
                    class="flex size-7 items-center justify-center rounded-md text-text-secondary hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="labels.refresh(title)"
                    :aria-disabled="refreshLimited ? 'true' : undefined"
                    :aria-describedby="refreshLimited ? limitedId : undefined"
                    :aria-busy="refreshing ? 'true' : undefined"
                    data-slot="block-refresh"
                    @click="refresh"
                >
                    <RefreshCw class="size-4" aria-hidden="true" />
                </button>
                <span v-if="refreshLimited" :id="limitedId" class="sr-only">{{
                    t('refresh-limited')
                }}</span>
                <button
                    v-if="canMinimize"
                    type="button"
                    class="flex size-7 items-center justify-center rounded-md text-text-secondary hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="
                        isMinimized
                            ? labels.expand(title)
                            : labels.minimize(title)
                    "
                    :aria-expanded="isMinimized ? 'false' : 'true'"
                    data-slot="block-minimize"
                    @click="emit('minimize', item.item_id, !isMinimized)"
                >
                    <Plus
                        v-if="isMinimized"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <Minus v-else class="size-4" aria-hidden="true" />
                </button>
                <DropdownMenu v-if="showMenu">
                    <DropdownMenuTrigger as-child>
                        <button
                            ref="menuButton"
                            type="button"
                            class="flex size-7 items-center justify-center rounded-md text-text-secondary hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="labels.moreActions(title)"
                            data-slot="block-menu"
                        >
                            <EllipsisVertical
                                class="size-4"
                                aria-hidden="true"
                            />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            v-if="canRefresh"
                            :disabled="refreshLimited"
                            @select="refresh"
                        >
                            {{ labels.menuRefresh }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="!canMoveUp"
                            data-slot="menu-move-up"
                            @select="emit('step', item.item_id, 'up')"
                        >
                            {{ labels.moveUp }}
                            <span
                                v-if="!canMoveUp"
                                class="type-caption text-text-muted"
                            >
                                · {{ labels.atTop }}
                            </span>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="!canMoveDown"
                            data-slot="menu-move-down"
                            @select="emit('step', item.item_id, 'down')"
                        >
                            {{ labels.moveDown }}
                            <span
                                v-if="!canMoveDown"
                                class="type-caption text-text-muted"
                            >
                                · {{ labels.atBottom }}
                            </span>
                        </DropdownMenuItem>
                        <template v-if="!compact && chrome?.allow_resize">
                            <DropdownMenuItem
                                :disabled="!widerAllowed"
                                data-slot="menu-wider"
                                @select="emit('step', item.item_id, 'wider')"
                            >
                                {{ labels.wider }}
                                <span
                                    v-if="!widerAllowed"
                                    class="type-caption text-text-muted"
                                >
                                    · {{ labels.atWidest }}
                                </span>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                :disabled="!narrowerAllowed"
                                data-slot="menu-narrower"
                                @select="emit('step', item.item_id, 'narrower')"
                            >
                                {{ labels.narrower }}
                                <span
                                    v-if="!narrowerAllowed"
                                    class="type-caption text-text-muted"
                                >
                                    · {{ labels.atNarrowest }}
                                </span>
                            </DropdownMenuItem>
                        </template>
                        <DropdownMenuItem
                            v-if="canViewAsTable"
                            data-slot="menu-view-as-table"
                            @select="viewOpen = 'table'"
                        >
                            {{ labels.menuViewAsTable }}
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="aboutOpen = true">
                            {{ labels.menuAbout }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            @select="emit('remove', item.block_id, 'menu')"
                        >
                            {{ labels.menuRemove }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>

        <div
            v-if="!isMinimized"
            :aria-label="labels.bodyRegion(title)"
            role="region"
            tabindex="0"
            class="min-h-0 flex-1 overflow-auto px-4 pb-4"
            data-slot="block-body"
        >
            <div
                v-if="ownSelector"
                class="mb-2 flex justify-end"
                data-slot="period-selector"
            >
                <select
                    class="type-caption rounded-md border border-border-control bg-surface-card px-2 py-1 text-text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="labels.periodSelector(title)"
                    :value="effectivePeriod ?? ''"
                    @change="onPeriodChange"
                >
                    <option v-if="effectivePeriod === null" value="" disabled>
                        {{ labels.dateRange }}
                    </option>
                    <option
                        v-for="preset in periodOptions"
                        :key="preset"
                        :value="preset"
                        :disabled="preset === 'custom'"
                    >
                        {{ labels.periods[preset] }}
                    </option>
                </select>
            </div>
            <div
                v-if="!unavailable && payload !== null && renderer !== null"
                data-slot="block-result"
                :data-etag="result?.result_etag ?? undefined"
            >
                <component
                    :is="renderer"
                    :payload="payload"
                    :audience="admin ? 'admin' : 'user'"
                    bare
                    @retry="$emit('retry', item.item_id)"
                />
            </div>
            <div
                v-else-if="!unavailable"
                role="status"
                aria-busy="true"
                class="grid gap-2"
                data-slot="block-skeleton"
            >
                <span class="sr-only">{{ labels.loadingBlock(title) }}</span>
                <Skeleton class="h-3 w-1/2" :caption="false" />
                <Skeleton class="h-8 w-3/4" :caption="false" />
                <Skeleton class="h-3 w-1/3" />
            </div>
            <div
                v-else
                class="flex flex-col items-start gap-3"
                data-slot="block-unavailable"
            >
                <p class="type-body text-text-secondary">{{ message }}</p>
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    :aria-label="labels.removeBlock(title)"
                    @click="emit('remove', item.block_id, 'card')"
                >
                    {{ labels.remove }}
                </Button>
            </div>
        </div>

        <button
            v-if="editing && canResize"
            type="button"
            class="absolute right-0 bottom-0 flex size-6 cursor-nwse-resize touch-none items-end justify-end rounded-br-lg text-text-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-label="labels.resize(title)"
            data-slot="resize-handle"
            @pointerdown.stop.prevent="
                emit('drag', item.item_id, 'resize', $event)
            "
        >
            <span
                class="m-1 block size-2.5 border-r-2 border-b-2 border-current"
                aria-hidden="true"
            />
        </button>

        <footer
            v-if="expandedFooter !== null && !isMinimized"
            class="px-4 pb-3 text-center"
            data-slot="block-footer-expanded"
        >
            <button
                type="button"
                class="type-caption inline-flex items-center gap-1 font-semibold text-accent-ink hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                data-slot="footer-expanded"
                @click="viewOpen = 'rows'"
            >
                {{ expandedFooter }}
            </button>
        </footer>

        <ExpandedView
            v-if="viewOpen !== null && item.block !== null"
            :open="viewOpen !== null"
            :mode="viewOpen"
            :title="title"
            :type="item.block.type"
            :icon="icon"
            :dashboard-id="dashboardId"
            :item-id="item.item_id"
            :payload="payload as Record<string, unknown> | null"
            :data-as-of="result?.data_as_of ?? null"
            :result-etag="result?.result_etag ?? null"
            @update:open="closeView"
        />

        <footer
            v-if="footer && !isMinimized"
            class="px-4 pb-3 text-center"
            data-slot="block-footer"
        >
            <a
                :href="footer.href"
                class="type-caption inline-flex items-center gap-1 font-semibold text-accent-ink hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :target="footer.external ? '_blank' : undefined"
                :rel="footer.external ? 'noopener noreferrer' : undefined"
                :aria-label="
                    footer.external
                        ? labels.footerLinkExternal(footer.label)
                        : undefined
                "
            >
                {{ footer.label }}
                <ArrowUpRight
                    v-if="footer.external"
                    class="size-3.5"
                    aria-hidden="true"
                />
            </a>
        </footer>

        <Dialog v-model:open="aboutOpen">
            <DialogContent data-slot="about-dialog">
                <DialogHeader>
                    <DialogTitle>{{ labels.about.title }}</DialogTitle>
                    <DialogDescription>{{ title }}</DialogDescription>
                </DialogHeader>
                <dl class="grid gap-3">
                    <div
                        v-for="[term, value] in aboutRows"
                        :key="term"
                        class="grid gap-0.5"
                    >
                        <dt class="type-caption text-text-muted">{{ term }}</dt>
                        <dd class="type-body-sm break-words text-text-primary">
                            {{ value }}
                        </dd>
                    </div>
                </dl>
                <div
                    v-if="technical.length > 0"
                    class="grid gap-1"
                    data-slot="about-technical"
                >
                    <h3 class="type-caption text-text-muted">
                        {{ labels.about.technical }}
                    </h3>
                    <ul class="type-body-sm text-text-primary">
                        <li v-for="line in technical" :key="line">
                            {{ line }}
                        </li>
                    </ul>
                </div>
            </DialogContent>
        </Dialog>
    </section>
</template>
