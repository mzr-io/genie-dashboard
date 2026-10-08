<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { endpointLabels as labels } from '@/locales/labels';
import { edit, endpoints } from '@/routes/admin/data-sources';

// The sections of a saved Data source (Story 2.9): its Settings (the form) and its Endpoints. A navigation of links, the
// current one marked with `aria-current="page"`, a bar and a heavier weight (never colour alone).
defineProps<{
    dataSourceId: string;
    current: 'settings' | 'endpoints';
}>();

const link =
    'type-body-sm -mb-px inline-flex min-h-9 items-center border-b-2 px-3 text-text-secondary hover:text-text-primary';
const active = 'border-brand font-semibold text-text-primary';
</script>

<template>
    <nav
        :aria-label="labels.tabsLabel"
        data-slot="data-source-tabs"
        class="flex gap-1 border-b border-border-default"
    >
        <Link
            :href="edit(dataSourceId).url"
            :aria-current="current === 'settings' ? 'page' : undefined"
            :class="[
                link,
                current === 'settings' ? active : 'border-transparent',
            ]"
            data-test="tab-settings"
            >{{ labels.tabSettings }}</Link
        >
        <Link
            :href="endpoints(dataSourceId).url"
            :aria-current="current === 'endpoints' ? 'page' : undefined"
            :class="[
                link,
                current === 'endpoints' ? active : 'border-transparent',
            ]"
            data-test="tab-endpoints"
            >{{ labels.tabEndpoints }}</Link
        >
    </nav>
</template>
