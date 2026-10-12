<script setup lang="ts">
import BlockCard from '@/components/BlockCard.vue';
import { HEIGHT_UNITS } from '@/lib/dashboards';
import type { DashboardItem, ItemResult } from '@/lib/dashboards';
import { dashboardLabels as labels } from '@/locales/labels';

// The 12-column dashboard grid (Story 4.4; UX-DR-149): 14 px gaps and 60 px rows. From the `md` breakpoint each Block sits where the server clamped it
// (`col`, `row`, width in columns, height preset in 60 px units); below it the Blocks stack in layout order in one column. The grid height is a track
// size, so a Block's content scrolls inside its body instead of being clipped.
defineProps<{
    items: DashboardItem[];
    // The latest result of each item by item id; an item with none shows its skeleton.
    results?: Record<string, ItemResult>;
}>();

defineEmits<{ retry: [itemId: string] }>();

const placement = (item: DashboardItem): Record<string, string> => ({
    '--col': String(item.geometry.col + 1),
    '--row': String(item.geometry.row + 1),
    '--width': String(item.geometry.width),
    '--units': String(HEIGHT_UNITS[item.geometry.height]),
});
</script>

<template>
    <div
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
                @retry="$emit('retry', $event)"
            />
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
