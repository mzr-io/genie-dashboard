<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BlockCard from '@/components/BlockCard.vue';
import { HEIGHT_UNITS } from '@/lib/dashboards';
import type { DashboardItem, HeightPreset, ItemResult } from '@/lib/dashboards';
import type { FooterProfile } from '@/lib/footerAction';
import { NO_LIMITS, moved, resized } from '@/lib/layout';
import type { Geometry, Limits } from '@/lib/layout';
import { dashboardLabels as labels } from '@/locales/labels';

// The 12-column dashboard grid (Story 4.4; UX-DR-149): 14 px gaps and 60 px rows. From the `md` breakpoint each Block sits where the server clamped it
// (`col`, `row`, width in columns, height preset in 60 px units); below it the Blocks stack in layout order in one column. The grid height is a track
// size, so a Block's content scrolls inside its body instead of being clipped.
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
    // Where the next Block would go, shown while "+ Add" is hovered or focused: a dashed free-space hint.
    hint?: {
        col: number;
        row: number;
        width: number;
        height: HeightPreset;
    } | null;
}>();

const emit = defineEmits<{
    retry: [itemId: string];
    refresh: [itemId: string];
    minimize: [itemId: string, minimized: boolean];
    remove: [blockId: string, from: 'menu' | 'card'];
    // A drag or resize in progress (`commit` false: a preview) or finished (`commit` true), with the geometry the pointer asks for.
    geometry: [itemId: string, geometry: Geometry, commit: boolean];
}>();

const { t } = useI18n();
const grid = ref<HTMLElement | null>(null);

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

onBeforeUnmount(() => finish(null));
const hintText = computed(() => t('free-space-hint'));

const placement = (item: DashboardItem): Record<string, string> => ({
    '--col': String(item.geometry.col + 1),
    '--row': String(item.geometry.row + 1),
    '--width': String(item.geometry.width),
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
    >
        <div
            v-for="item in items"
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
                :editing="editing"
                @drag="(_id, kind, event) => startDrag(item, kind, event)"
                @remove="(blockId, from) => $emit('remove', blockId, from)"
                @retry="$emit('retry', $event)"
                @refresh="$emit('refresh', $event)"
                @minimize="(id, value) => $emit('minimize', id, value)"
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

@media (min-width: 768px) {
    .dashboard-grid {
        grid-template-columns: repeat(12, minmax(0, 1fr));
        grid-auto-rows: 60px;
    }

    .dashboard-grid__cell {
        grid-column: var(--col) / span var(--width);
        grid-row: var(--row) / span var(--units);
        min-height: 0;
    }
}
</style>
