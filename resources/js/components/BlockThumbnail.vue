<script setup lang="ts">
// The 64 x 46 schematic of a Block in the Add-blocks Panel (Story 4.7; UX-DR-69): decorative, so it is hidden from assistive technology. Drawn from
// the Block's type in the chart palette on white; a type with no schematic gets a plain card outline.
defineProps<{ type: string }>();
</script>

<template>
    <svg
        viewBox="0 0 64 46"
        width="64"
        height="46"
        role="presentation"
        aria-hidden="true"
        focusable="false"
        class="shrink-0 rounded-md border border-border-default bg-surface-card"
        data-slot="block-thumbnail"
    >
        <template v-if="type === 'kpi-card'">
            <rect
                x="8"
                y="9"
                width="22"
                height="4"
                rx="2"
                class="fill-text-muted"
                opacity="0.55"
            />
            <rect
                x="8"
                y="19"
                width="40"
                height="9"
                rx="2"
                class="fill-chart-1"
            />
            <rect
                x="8"
                y="34"
                width="26"
                height="4"
                rx="2"
                class="fill-chart-2"
                opacity="0.8"
            />
        </template>
        <template v-else-if="type === 'line-area'">
            <polyline
                points="6,34 18,26 28,30 40,16 58,10"
                fill="none"
                class="stroke-chart-1-stroke"
                stroke-width="2"
                stroke-linejoin="round"
            />
            <polyline
                points="6,38 18,33 28,35 40,28 58,24"
                fill="none"
                class="stroke-chart-2"
                stroke-width="2"
                stroke-dasharray="4 3"
                stroke-linejoin="round"
            />
        </template>
        <template v-else>
            <rect
                x="8"
                y="9"
                width="48"
                height="28"
                rx="3"
                fill="none"
                class="stroke-chart-1"
                stroke-width="2"
            />
        </template>
    </svg>
</template>
