<script setup lang="ts">
import Renderer from '@block-types/kpi-card/v1/Renderer.vue';
import type { KpiCardPayload } from '@block-types/kpi-card/v1/payload';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { heightLabel, sizeText } from '@/lib/blockWizard';
import type { DisplayConfig } from '@/lib/blockWizard';
import { blockWizardLabels as labels } from '@/locales/labels';

// The live preview (Story 3.4): the kpi-card in its Loading state (there is no data yet), re-rendered on every change from the form's
// state. Desktop, Tablet and Mobile change the canvas width only; the size pill under it reads "3 columns × Small". Under the pane,
// `version-safe` names the version Publishing will create.
const props = defineProps<{
    name: string;
    description: string;
    display: DisplayConfig;
    version: string;
}>();

const { t } = useI18n();
const device = ref('desktop');

// Canvas widths in px for the three devices; Desktop fills the pane.
const CANVAS: Record<string, string> = {
    desktop: '100%',
    tablet: '480px',
    mobile: '320px',
};
// Card heights, Medium being 360 px.
const HEIGHT_PX: Record<string, number> = {
    small: 180,
    medium: 360,
    large: 540,
};

const payload = computed<KpiCardPayload>(() => ({
    state: 'loading',
    title: props.display.show_header ? props.name.trim() || null : null,
    subtitle: props.display.show_subtitle
        ? props.description.trim() || null
        : null,
    icon: null,
    footerAction: null,
    kpiLabel: null,
    headline: { available: false, field: 'headline_value' },
    comparison: null,
    comparisonLabel: null,
    secondaryNote: null,
    statusBadge: null,
    staleSince: null,
    errorMessage: null,
}));

const deviceLabel = computed(
    () =>
        labels.deviceOptions.find((option) => option.value === device.value)
            ?.label ?? '',
);
const pill = computed(() => sizeText(props.display));
const canvasStyle = computed(() => ({
    width: CANVAS[device.value],
    maxWidth: '100%',
}));
const cardStyle = computed(() => ({
    height: `${HEIGHT_PX[props.display.height] ?? 180}px`,
}));
</script>

<template>
    <aside
        aria-labelledby="wizard-preview-title"
        data-slot="live-preview"
        class="flex flex-col gap-3 rounded-lg border border-border-default bg-surface-card p-4"
    >
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2
                    id="wizard-preview-title"
                    class="type-title-sm text-text-primary"
                >
                    {{ labels.previewTitle }}
                </h2>
                <p class="type-caption text-text-muted">
                    {{ labels.previewCaption }}
                </p>
            </div>
            <SegmentedControl
                v-model="device"
                :options="labels.deviceOptions"
                :label="labels.devices"
            />
        </header>
        <div class="overflow-x-auto rounded-md bg-surface-sunken p-3">
            <div
                role="group"
                :aria-label="labels.canvas(deviceLabel)"
                :data-device="device"
                data-test="preview-canvas"
                class="mx-auto"
                :style="canvasStyle"
            >
                <div
                    class="overflow-hidden rounded-lg border border-border-default bg-surface-card"
                    :style="cardStyle"
                    :data-height="display.height"
                    :title="heightLabel(display.height)"
                >
                    <Renderer :payload="payload" audience="admin" />
                </div>
            </div>
        </div>
        <p
            class="type-caption text-center font-semibold text-text-secondary"
            data-test="size-pill"
        >
            <span class="rounded-full bg-surface-muted px-2 py-0.5">{{
                pill
            }}</span>
        </p>
        <p class="type-caption text-text-muted" data-test="version-safe">
            {{ t('version-safe', { version }) }}
        </p>
    </aside>
</template>
