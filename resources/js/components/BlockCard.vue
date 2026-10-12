<script setup lang="ts">
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { rendererFor } from '@/lib/blockRenderers';
import { kpiPayloadOf } from '@/lib/dashboards';
import type { DashboardItem, ItemResult } from '@/lib/dashboards';
import { dashboardLabels as labels } from '@/locales/labels';

// One Block on a dashboard (Story 4.4, 4.5; UX-DR-50): a `section` labelled by its `h2` title, on the card surface with a 1px border and the large
// radius. The server decides the state. An item that is not `ok` shows its message and a Remove action and no data. A Block with no result yet shows
// its real header and its own skeleton; one with a result draws it with its Block Type's Renderer, which implements every state and writes each slot
// as literal text. A later result replaces the last one in place: a skeleton shows only while no result has ever arrived. The body is the focusable
// region that scrolls when its content overflows, never a clipping box (UX-DR-149). Remove is in place and says it is not available yet: the layout
// commands arrive with Story 4.9, and a control that hid the Block without saving would be a lie.
const props = defineProps<{
    item: DashboardItem;
    result?: ItemResult | null;
}>();

defineEmits<{ retry: [itemId: string] }>();

const { t } = useI18n();
const headingId = useId();
const reasonId = useId();

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
const showHeader = computed(
    () => unavailable.value || props.item.block?.chrome.show_header !== false,
);
const subtitle = computed(() =>
    props.item.block?.chrome.show_subtitle === false
        ? null
        : (props.item.block?.subtitle ?? null),
);

const renderer = computed(() =>
    props.item.block === null ? null : rendererFor(props.item.block.type),
);
const payload = computed(() =>
    props.result === null || props.result === undefined
        ? null
        : kpiPayloadOf(props.result),
);
</script>

<template>
    <section
        data-slot="block-card"
        :data-state="state"
        :aria-labelledby="headingId"
        class="flex min-h-0 flex-col overflow-hidden rounded-lg border border-border-default bg-surface-card"
    >
        <header
            class="flex items-start justify-between gap-2 px-4 py-3"
            :class="showHeader ? '' : 'sr-only'"
        >
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
            </div>
            <span
                v-if="item.is_live"
                class="type-caption text-text-muted"
                data-slot="block-live"
                >{{ labels.live }}</span
            >
        </header>

        <div
            v-if="!item.minimized"
            :aria-label="labels.bodyRegion(title)"
            role="region"
            tabindex="0"
            class="min-h-0 flex-1 overflow-auto px-4 pb-4"
            data-slot="block-body"
        >
            <div
                v-if="!unavailable && payload !== null && renderer !== null"
                data-slot="block-result"
                :data-etag="result?.result_etag ?? undefined"
            >
                <component
                    :is="renderer"
                    :payload="payload"
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
                    aria-disabled="true"
                    :aria-label="labels.removeBlock(title)"
                    :aria-describedby="reasonId"
                    @click.prevent
                >
                    {{ labels.remove }}
                </Button>
                <span :id="reasonId" class="type-caption text-text-muted">{{
                    labels.laterStory
                }}</span>
            </div>
        </div>
    </section>
</template>
