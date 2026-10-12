<script setup lang="ts">
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import type { DashboardItem } from '@/lib/dashboards';
import { dashboardLabels as labels } from '@/locales/labels';

// One Block on a dashboard (Story 4.4; UX-DR-50): a `section` labelled by its `h2` title, on the card surface with a 1px border and the large radius.
// The server decides the state. An item that is not `ok` shows its message and a Remove action and no data; its values arrive with Story 4.5 and its
// header controls with Story 4.6. Remove is in place and says it is not available yet: the layout commands arrive with Story 4.9, and a control that
// hid the Block without saving would be a lie. The body is the focusable region that scrolls when its content overflows, never a clipping box (UX-DR-149).
const props = defineProps<{ item: DashboardItem }>();

const { t } = useI18n();
const headingId = useId();
const reasonId = useId();

const unavailable = computed(() => props.item.state !== 'ok');
const title = computed(() => props.item.block?.name ?? labels.unavailableBlock);
const message = computed(() =>
    props.item.state === 'access_removed'
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
</script>

<template>
    <section
        data-slot="block-card"
        :data-state="item.state"
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
                v-if="unavailable"
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
