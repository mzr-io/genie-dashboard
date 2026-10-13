<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BlockCard from '@/components/BlockCard.vue';
import { HEIGHT_UNITS } from '@/lib/dashboards';
import type { DashboardItem, HeightPreset, ItemResult } from '@/lib/dashboards';
import type { FooterProfile } from '@/lib/footerAction';
import { NO_LIMITS, moved, resized } from '@/lib/layout';
import type { Geometry, Limits } from '@/lib/layout';
import { dashboardLabels as labels } from '@/locales/labels';

// The 12-column dashboard grid (Story 4.4; UX-DR-149): 14 px gaps and 60 px rows. It follows the width of its own content area, not the screen's
// (Story 4.17; UX-DR-150, 151): from 1100 px each Block sits where the server clamped it (`col`, `row`, width in columns, height preset in 60 px
// units); from 720 to 1099 px the grid keeps 12 columns, 3-column Blocks stay four across and wider Blocks take 6 columns, in reading order; below
// 720 px the Blocks stack in the person's order in one column. Only the drawing changes: no position is ever written back. The grid height is a
// track size, so a Block's content scrolls inside its body instead of being clipped.
const props = defineProps<{
    items: DashboardItem[];
    // The latest result of each item by item id; an item with none shows its skeleton.
    results?: Record<string, ItemResult>;
    // The member's own minimize choices by item id, once made.
    minimized?: Record<string, boolean>;
    // Item ids with a manual refresh in flight, and those inside the manual-refresh window.
    refreshing?: string[];
    limited?: string[];
    admin?: boolean;
    profile?: FooterProfile;
    justAdded?: string[];
    // Edit Layout Mode (Story 4.11): the Blocks show their move and resize handles.
    editing?: boolean;
    // The item picked up with the keyboard (Story 4.12), if any.
    pickedUp?: string | null;
    // A single-column layout: Blocks stack in `order` (reading order when null) and the menu reorders instead of resizing.
    compact?: boolean;
    order?: string[] | null;
    // The dashboard Date Range's token, for the Blocks that state a period of their own.
    dateRange?: string | null;
    // The dashboard these Blocks are on (the rows of an Expanded view are read through it).
    dashboardId?: string | null;
    // Where the next Block would go, shown while "+ Add" is hovered or focused: a dashed free-space hint.
    hint?: {
        col: number;
        row: number;
        width: number;
        height: HeightPreset;
    } | null;
}>();

const emit = defineEmits<{
    // The layout mode changed with the content width: `full` (saved positions), `tablet` (display only) or `single` (one column).
    mode: [mode: GridMode];
    retry: [itemId: string];
    refresh: [itemId: string];
    minimize: [itemId: string, minimized: boolean];
    period: [itemId: string, blockId: string, selection: string | null];
    remove: [blockId: string, from: 'menu' | 'card'];
    // A drag or resize in progress (`commit` false: a preview) or finished (`commit` true), with the geometry the pointer asks for.
    geometry: [itemId: string, geometry: Geometry, commit: boolean];
    movekey: [itemId: string, event: KeyboardEvent];
    step: [itemId: string, action: 'up' | 'down' | 'wider' | 'narrower'];
}>();

const { t } = useI18n();
const grid = ref<HTMLElement | null>(null);

export type GridMode = 'full' | 'tablet' | 'single';

// Content widths of UX-DR-150: below 720 px one column, below 1100 px the display-only six-column reflow.
const SINGLE_BELOW = 720;
const FULL_FROM = 1100;
const modeOf = (width: number): GridMode =>
    width < SINGLE_BELOW ? 'single' : width < FULL_FROM ? 'tablet' : 'full';

// Null until the grid has been laid out (and in a test DOM with no layout): the saved positions are drawn.
const width = ref<number | null>(null);
const mode = computed<GridMode>(() =>
    width.value === null ? 'full' : modeOf(width.value),
);
let observer: ResizeObserver | null = null;

onMounted(() => {
    if (grid.value === null) {
        return;
    }

    width.value = grid.value.getBoundingClientRect().width || null;
    emit('mode', mode.value);

    if (typeof ResizeObserver === 'undefined') {
        return;
    }

    observer = new ResizeObserver((entries) => {
        const before = mode.value;

        width.value = entries[0]?.contentRect.width || null;

        if (mode.value !== before) {
            emit('mode', mode.value);
        }
    });
    observer.observe(grid.value);
});

// On a single column the Blocks stack in the person's own order, then reading order for any not named in it.
const ordered = computed(() => {
    if (!props.compact || !props.order) {
        return props.items;
    }

    const byId = new Map(props.items.map((item) => [item.item_id, item]));
    const named = props.order
        .map((id) => byId.get(id))
        .filter((i): i is DashboardItem => i !== undefined);
    const rest = props.items.filter(
        (item) => !props.order!.includes(item.item_id),
    );

    return [...named, ...rest];
});

const canMoveUp = (item: DashboardItem): boolean =>
    props.compact
        ? ordered.value[0]?.item_id !== item.item_id
        : item.geometry.row > 0;
const canMoveDown = (item: DashboardItem): boolean =>
    props.compact
        ? ordered.value[ordered.value.length - 1]?.item_id !== item.item_id
        : true;

// One pointer gesture at a time: it records where it began and turns every move into the geometry the pointer asks for. The page settles the
// layout around it and keeps the history; nothing is written until Done.
let gesture: {
    id: string;
    kind: 'move' | 'resize';
    x: number;
    y: number;
    start: Geometry;
    limits: Limits;
    width: number;
} | null = null;

function limitsOf(item: DashboardItem): Limits {
    const sizes = item.block?.sizes;

    return sizes
        ? {
              minWidth: sizes.min_width,
              maxWidth: sizes.max_width,
              minHeight: sizes.min_height,
              maxHeight: sizes.max_height,
          }
        : NO_LIMITS;
}

function onPointerMove(event: PointerEvent): void {
    if (gesture === null) {
        return;
    }

    const dx = event.clientX - gesture.x;
    const dy = event.clientY - gesture.y;
    const next =
        gesture.kind === 'move'
            ? moved(gesture.start, dx, dy, gesture.width)
            : resized(gesture.start, dx, dy, gesture.width, gesture.limits);

    emit('geometry', gesture.id, next, false);
}

function finish(event: PointerEvent | null): void {
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('pointercancel', onPointerCancel);

    if (gesture === null) {
        return;
    }

    const done = gesture;
    gesture = null;

    if (event !== null) {
        const dx = event.clientX - done.x;
        const dy = event.clientY - done.y;
        const next =
            done.kind === 'move'
                ? moved(done.start, dx, dy, done.width)
                : resized(done.start, dx, dy, done.width, done.limits);

        emit('geometry', done.id, next, true);
    } else {
        // Cancelled: back to where it began, with nothing recorded.
        emit('geometry', done.id, done.start, true);
    }
}

function onPointerUp(event: PointerEvent): void {
    finish(event);
}

function onPointerCancel(): void {
    finish(null);
}

function startDrag(
    item: DashboardItem,
    kind: 'move' | 'resize',
    event: PointerEvent,
): void {
    if (!props.editing || grid.value === null) {
        return;
    }

    gesture = {
        id: item.item_id,
        kind,
        x: event.clientX,
        y: event.clientY,
        start: { ...item.geometry },
        limits: limitsOf(item),
        width: grid.value.getBoundingClientRect().width,
    };

    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerCancel);
}

onBeforeUnmount(() => {
    observer?.disconnect();
    finish(null);
});
const hintText = computed(() => t('free-space-hint'));

const placement = (item: DashboardItem): Record<string, string> => ({
    '--col': String(item.geometry.col + 1),
    '--row': String(item.geometry.row + 1),
    '--width': String(item.geometry.width),
    // The reflow of the display-only mode: 3-column Blocks stay four across, anything wider takes half the row.
    '--span': String(item.geometry.width <= 3 ? 3 : 6),
    '--units': String(HEIGHT_UNITS[item.geometry.height]),
});
</script>

<template>
    <div
        ref="grid"
        data-slot="dashboard-grid"
        role="group"
        :aria-label="labels.grid"
        class="dashboard-grid"
        :data-mode="mode"
    >
        <div
            v-for="item in ordered"
            :key="item.item_id"
            class="dashboard-grid__cell"
            :style="placement(item)"
            :data-item="item.item_id"
        >
            <BlockCard
                :item="item"
                :result="results?.[item.item_id] ?? null"
                :minimized="minimized?.[item.item_id] ?? null"
                :refreshing="refreshing?.includes(item.item_id) ?? false"
                :refresh-limited="limited?.includes(item.item_id) ?? false"
                :admin="admin"
                :profile="profile"
                :just-added="justAdded?.includes(item.item_id) ?? false"
                :editing="editing && !compact"
                :picked-up="pickedUp === item.item_id"
                :compact="compact"
                :date-range="dateRange ?? null"
                :dashboard-id="dashboardId ?? null"
                :can-move-up="canMoveUp(item)"
                :can-move-down="canMoveDown(item)"
                @movekey="(id, event) => $emit('movekey', id, event)"
                @step="(id, action) => $emit('step', id, action)"
                @drag="(_id, kind, event) => startDrag(item, kind, event)"
                @remove="(blockId, from) => $emit('remove', blockId, from)"
                @retry="$emit('retry', $event)"
                @refresh="$emit('refresh', $event)"
                @minimize="(id, value) => $emit('minimize', id, value)"
                @period="
                    (id, blockId, value) => $emit('period', id, blockId, value)
                "
            />
        </div>
        <div
            v-if="hint"
            class="dashboard-grid__cell dashboard-grid__hint"
            :style="
                placement({
                    geometry: hint,
                } as unknown as DashboardItem)
            "
            data-slot="free-space-hint"
            aria-hidden="true"
        >
            <p class="type-caption text-accent-ink">
                {{ hintText }}
            </p>
        </div>
    </div>
</template>

<style scoped>
.dashboard-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 14px;
}

.dashboard-grid__cell {
    display: flex;
    min-height: calc(var(--units) * 60px);
}

.dashboard-grid__cell > * {
    flex: 1 1 auto;
}

.dashboard-grid__hint {
    align-items: center;
    justify-content: center;
    border: 2px dashed var(--df-accent-border, currentColor);
    border-radius: var(--radius-lg, 12px);
    background: var(--df-accent-wash, transparent);
    pointer-events: none;
}

/* Content width, not the screen's: the grid itself is measured (UX-DR-150, 151). */
.dashboard-grid[data-mode='tablet'],
.dashboard-grid[data-mode='full'] {
    grid-template-columns: repeat(12, minmax(0, 1fr));
    grid-auto-rows: 60px;
}

.dashboard-grid[data-mode='tablet'] .dashboard-grid__cell {
    grid-column: span var(--span);
    grid-row: span var(--units);
    min-height: 0;
}

.dashboard-grid[data-mode='full'] .dashboard-grid__cell {
    grid-column: var(--col) / span var(--width);
    grid-row: var(--row) / span var(--units);
    min-height: 0;
}

.dashboard-grid[data-mode='tablet'] .dashboard-grid__hint,
.dashboard-grid[data-mode='single'] .dashboard-grid__hint {
    display: none;
}
</style>
