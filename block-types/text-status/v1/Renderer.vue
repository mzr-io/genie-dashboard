<script setup lang="ts">
import { Circle } from '@lucide/vue';
import ChartBlockFrame from '@/charts/ChartBlockFrame.vue';
import { iconFor } from '@/lib/blockIcons';
import type { TextStatusPayload } from './payload';

// The text-status v1 renderer (Story 5.14): in Text mode the text, up to three lines and then an ellipsis (the full text is in "About this block");
// in Status mode a pill with the band's fill, text colour, leading icon and the status word. A value that matched no band is neutral text, and the
// word and an icon are always there.
withDefaults(
    defineProps<{
        payload: TextStatusPayload;
        audience?: 'user' | 'admin';
        workspaceLocale?: string | null;
        bare?: boolean;
    }>(),
    { audience: 'user', workspaceLocale: null, bare: false },
);

defineEmits<{ (e: 'retry'): void }>();

const PILL: Record<string, string> = {
    success: 'bg-success-soft text-success-text',
    warning: 'bg-warning-soft text-warning',
    error: 'bg-error-soft text-error-text',
    info: 'bg-info-soft text-info-strong',
    neutral: 'bg-surface-muted text-text-primary',
};
</script>

<template>
    <ChartBlockFrame
        :state="payload.state"
        :title="payload.title"
        :subtitle="null"
        :footer-action="payload.footerAction"
        :partial="payload.partial === true"
        :stale-since="payload.staleSince"
        :error-message="payload.errorMessage"
        :empty-display="payload.emptyDisplay"
        fallback-title="Status"
        field="Text"
        :audience="audience"
        :workspace-locale="workspaceLocale"
        :bare="bare"
        custom
        @retry="$emit('retry')"
    >
        <p
            v-if="payload.mode === 'text'"
            class="type-body line-clamp-3 break-words text-text-primary"
            data-test="text-body"
        >
            {{ payload.text }}
        </p>
        <p v-else class="flex" data-test="status-body">
            <span
                class="type-body-sm inline-flex items-center gap-1.5 rounded-full px-3 py-1 font-semibold"
                :class="PILL[payload.band?.color ?? 'neutral']"
                data-test="status-pill"
                :data-band="payload.band ? payload.band.color : 'none'"
            >
                <component
                    :is="payload.band ? iconFor(payload.band.icon) : Circle"
                    class="size-4 shrink-0"
                    aria-hidden="true"
                />
                <span data-test="status-word">{{
                    payload.band ? payload.band.text : payload.text
                }}</span>
            </span>
        </p>
    </ChartBlockFrame>
</template>
