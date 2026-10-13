<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ChartBlockFrame from '@/charts/ChartBlockFrame.vue';
import { resolveLocale } from '@/lib/format';
import type { ActivityPayload } from './payload';
import { ago } from './table';

// The activity-feed v1 renderer (Story 5.14): each event is the actor (600), the action in secondary text and, beneath, how long ago it was in the
// viewer's locale. The clock ticks every 30 seconds so "8 min ago" stays true without a reload. A time still to come shows its date; one that is not a
// date shows "—" (the Block is partial). Every text is literal.
const props = withDefaults(
    defineProps<{
        payload: ActivityPayload;
        audience?: 'user' | 'admin';
        workspaceLocale?: string | null;
        bare?: boolean;
    }>(),
    { audience: 'user', workspaceLocale: null, bare: false },
);

defineEmits<{ (e: 'retry'): void }>();

const locale = computed(() => resolveLocale(props.workspaceLocale));
const rows = computed(() => props.payload.rows ?? []);
const now = ref(new Date());
let timer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    timer = setInterval(() => {
        now.value = new Date();
    }, 30000);
});
onBeforeUnmount(() => {
    if (timer !== null) {
        clearInterval(timer);
    }
});

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
        fallback-title="Activity"
        field="Activity events"
        :audience="audience"
        :workspace-locale="workspaceLocale"
        :bare="bare"
        custom
        @retry="$emit('retry')"
    >
        <ul class="grid" data-test="activity-feed-block">
            <li
                v-for="row in rows"
                :key="row.key"
                :data-key="row.key"
                class="flex items-start gap-3 border-b border-surface-muted py-2 last:border-b-0"
                data-test="activity-row"
            >
                <span
                    v-if="row.avatar"
                    class="type-caption inline-flex size-8 shrink-0 items-center justify-center rounded-full font-semibold"
                    :class="TINTS[row.avatar.tint % TINTS.length]"
                    aria-hidden="true"
                    data-test="avatar-initials"
                    >{{ row.avatar.initials }}</span
                >
                <span class="grid min-w-0 flex-1">
                    <span class="type-body-sm text-text-secondary">
                        <span
                            class="font-semibold text-text-primary"
                            data-test="activity-actor"
                            >{{ row.actor ?? '—' }}</span
                        >
                        <span data-test="activity-action">
                            {{ row.action ?? '—' }}</span
                        >
                        <span
                            v-if="row.object"
                            class="text-text-primary"
                            data-test="activity-object"
                        >
                            {{ row.object }}</span
                        >
                    </span>
                    <time
                        class="type-caption text-text-muted"
                        :datetime="row.timestamp ?? undefined"
                        data-test="activity-time"
                        >{{ ago(row.timestamp, now, locale) }}</time
                    >
                </span>
            </li>
        </ul>
    </ChartBlockFrame>
</template>
