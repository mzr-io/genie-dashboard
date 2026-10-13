<script setup lang="ts">
import { computed } from 'vue';
import ChartBlockFrame from '@/charts/ChartBlockFrame.vue';
import { iconFor } from '@/lib/blockIcons';
import { resolveLocale } from '@/lib/format';
import { cellText } from '../../table/v1/table';
import type { ListPayload } from './payload';

// The list v1 renderer (Story 5.13): a real list of items, each with its avatar or icon, title, subtitle, right value (tabular) and right meta,
// separated by a 1px surface-muted divider. Every text is literal. A row's key is stable, so a refresh keeps the screen reader's place.
const props = withDefaults(
    defineProps<{
        payload: ListPayload;
        audience?: 'user' | 'admin';
        workspaceLocale?: string | null;
        bare?: boolean;
    }>(),
    { audience: 'user', workspaceLocale: null, bare: false },
);

defineEmits<{ (e: 'retry'): void }>();

const locale = computed(() => resolveLocale(props.workspaceLocale));
const rows = computed(() => props.payload.rows ?? []);

// Five tints, each a soft background with its strong text, so the initials never rest on colour alone.
const TINTS = [
    'bg-info-soft text-info-strong',
    'bg-success-soft text-success-text',
    'bg-warning-soft text-warning',
    'bg-error-soft text-error-text',
    'bg-surface-muted text-text-primary',
];
</script>

<template>
    <ChartBlockFrame
        :state="payload.state"
        :title="payload.title"
        :subtitle="payload.subtitle"
        :footer-action="payload.footerAction"
        :partial="payload.partial === true"
        :stale-since="payload.staleSince"
        :error-message="payload.errorMessage"
        :empty-display="payload.emptyDisplay"
        fallback-title="List"
        field="List items"
        :audience="audience"
        :workspace-locale="workspaceLocale"
        :bare="bare"
        custom
        @retry="$emit('retry')"
    >
        <ul class="grid" data-test="list-block">
            <li
                v-for="row in rows"
                :key="row.key"
                :data-key="row.key"
                class="flex items-center gap-3 border-b border-surface-muted py-2 last:border-b-0"
                data-test="list-row"
            >
                <span
                    v-if="row.avatar"
                    class="type-caption inline-flex size-8 shrink-0 items-center justify-center rounded-full font-semibold"
                    :class="TINTS[row.avatar.tint % TINTS.length]"
                    aria-hidden="true"
                    data-test="avatar-initials"
                    :data-tint="row.avatar.tint"
                    >{{ row.avatar.initials }}</span
                >
                <component
                    :is="iconFor(row.icon)"
                    v-else-if="row.icon"
                    class="size-5 shrink-0 text-text-secondary"
                    aria-hidden="true"
                />
                <span class="grid min-w-0 flex-1">
                    <span
                        class="type-body-sm truncate font-medium text-text-primary"
                        data-test="list-title"
                        >{{ row.title ?? '—' }}</span
                    >
                    <span
                        v-if="row.subtitle"
                        class="type-caption truncate text-text-secondary"
                        data-test="list-subtitle"
                        >{{ row.subtitle }}</span
                    >
                </span>
                <span
                    v-if="row.status"
                    class="type-caption shrink-0 rounded-sm bg-surface-muted px-2 py-0.5 text-text-primary"
                    data-test="list-status"
                    >{{ row.status }}</span
                >
                <span
                    v-if="row.value !== null || row.meta !== null"
                    class="grid shrink-0 justify-items-end"
                >
                    <span
                        v-if="row.value !== null"
                        class="type-body-sm font-semibold text-text-primary tabular-nums"
                        data-test="list-value"
                        >{{
                            cellText(row.value, payload.valueFormat, locale)
                        }}</span
                    >
                    <span
                        v-if="row.meta !== null"
                        class="type-caption text-text-secondary tabular-nums"
                        data-test="list-meta"
                        >{{
                            cellText(row.meta, payload.metaFormat, locale)
                        }}</span
                    >
                </span>
            </li>
        </ul>
    </ChartBlockFrame>
</template>
