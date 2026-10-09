<script setup lang="ts">
import { computed } from 'vue';
import StatusDot from '@/components/StatusDot.vue';
import { HEALTH_TONES } from '@/lib/dataSources';
import type { HealthStatus } from '@/lib/dataSources';
import { dataSourceLabels } from '@/locales/labels';

// A Data Source's health (Story 2.18; UX-DR-114, 115): the dot AND its word, always together. The dot is decorative (`aria-hidden`);
// assistive technology reads the word. An unknown status reads as Checking…, never as Healthy.
const props = defineProps<{ status: HealthStatus | string }>();

const known = computed<HealthStatus>(() =>
    props.status in HEALTH_TONES ? (props.status as HealthStatus) : 'checking',
);
</script>

<template>
    <span class="inline-flex items-center gap-2" data-test="health">
        <StatusDot :tone="HEALTH_TONES[known]" />
        <span data-test="health-word">{{
            dataSourceLabels.health[known]
        }}</span>
    </span>
</template>
