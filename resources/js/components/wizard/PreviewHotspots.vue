<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { blockPreviewLabels as labels } from '@/locales/labels';

// The hotspots over the live preview (Story 3.14, UI stage 4). Each mapped region of the card gets a faint dashed outline; the pointer's hover
// is an accent outline on an accent wash (never the focus indicator), keyboard focus shows the standard focus ring, and both show the tooltip
// "Click to change field · {Slot} ← {field}". A hotspot is a button named "{Slot}: {field}, {value}. Change field"; a required slot with nothing
// mapped is the hatched placeholder button. Read-only (`blocked`): nothing here is a button, the outlines are decoration. The page puts this
// component over the card, inside a positioned box; the rectangles are measured by the preview, so the Renderer stays untouched.
export type Region = {
    slot: string;
    label: string;
    // Where it sits inside the box, in px.
    rect: { x: number; y: number; w: number; h: number };
};

export type Hotspot = Region & {
    field: string;
    // What the card shows there, as it shows it.
    value: string;
    // The preview is being refreshed for a changed field: shown, but not offered as a button until the new value lands.
    pending?: boolean;
};

const props = defineProps<{
    hotspots: Hotspot[];
    // The size of the positioned box, so a tooltip near its bottom or right edge opens the other way and is never clipped.
    bounds?: { w: number; h: number };
    // The required slot with nothing mapped, when there is one.
    missing: Region | null;
    // Read-only: no buttons, no picker.
    blocked?: boolean;
    // The label of the role the placeholder names ("Value").
    missingRole: string;
}>();

const emit = defineEmits<{
    (e: 'change', slot: string, opener: HTMLElement): void;
}>();

const prefix = useId();
// The hotspot the pointer or focus is on, and the one whose tooltip Escape dismissed (WCAG 1.4.13: dismissible, hoverable, persistent).
const current = ref<string | null>(null);
const dismissed = ref<string | null>(null);

function enter(slot: string): void {
    current.value = slot;
}

function leave(slot: string): void {
    if (current.value === slot) {
        current.value = null;
    }

    if (dismissed.value === slot) {
        dismissed.value = null;
    }
}

function onEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape' && current.value !== null) {
        dismissed.value = current.value;
    }
}

onMounted(() => window.addEventListener('keydown', onEscape));
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));

function tipClass(hotspot: Hotspot): string {
    const bottom =
        props.bounds &&
        props.bounds.h > 0 &&
        hotspot.rect.y + hotspot.rect.h + 44 > props.bounds.h;
    const right =
        props.bounds &&
        props.bounds.w > 0 &&
        hotspot.rect.x + 260 > props.bounds.w;

    return [
        bottom ? 'bottom-full pb-1' : 'top-full pt-1',
        right ? 'end-0' : 'start-0',
        dismissed.value === hotspot.slot ? '!hidden' : '',
    ].join(' ');
}

function place(region: Region): Record<string, string> {
    return {
        left: `${region.rect.x}px`,
        top: `${region.rect.y}px`,
        width: `${region.rect.w}px`,
        height: `${region.rect.h}px`,
    };
}

function open(slot: string, event: Event): void {
    emit('change', slot, event.currentTarget as HTMLElement);
}
</script>

<template>
    <div
        class="pointer-events-none absolute inset-0"
        data-test="preview-hotspots"
    >
        <div
            v-for="hotspot in hotspots"
            :key="hotspot.slot"
            class="group pointer-events-auto absolute"
            :style="place(hotspot)"
            :data-slot-hotspot="hotspot.slot"
            @pointerenter="enter(hotspot.slot)"
            @pointerleave="leave(hotspot.slot)"
            @focusin="enter(hotspot.slot)"
            @focusout="leave(hotspot.slot)"
        >
            <button
                v-if="!blocked && !hotspot.pending"
                type="button"
                class="absolute inset-0 size-full cursor-pointer rounded-sm border border-dashed border-border-default focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none [@media(hover:hover)]:hover:border-[1.5px] [@media(hover:hover)]:hover:border-solid [@media(hover:hover)]:hover:border-accent [@media(hover:hover)]:hover:bg-accent-wash"
                :aria-label="
                    labels.hotspotName(
                        hotspot.label,
                        hotspot.field,
                        hotspot.value,
                    )
                "
                :aria-describedby="`${prefix}-${hotspot.slot}`"
                data-test="preview-hotspot"
                @click="open(hotspot.slot, $event)"
            />
            <span
                v-else
                class="absolute inset-0 rounded-sm border border-dashed border-border-default"
                aria-hidden="true"
                data-test="preview-hotspot-static"
            />
            <span
                v-if="!blocked && !hotspot.pending"
                :id="`${prefix}-${hotspot.slot}`"
                role="tooltip"
                class="absolute z-30 hidden w-max max-w-64 group-focus-within:block group-hover:block"
                :class="tipClass(hotspot)"
                data-test="hotspot-tip"
                ><span
                    class="type-caption block rounded-md bg-surface-inverse px-2 py-1 text-text-inverse"
                    >{{ labels.hotspotTip(hotspot.label, hotspot.field) }}</span
                ></span
            >
        </div>

        <div
            v-if="missing"
            class="pointer-events-auto absolute"
            :style="place(missing)"
            :data-slot-hotspot="missing.slot"
        >
            <button
                v-if="!blocked"
                type="button"
                class="type-caption absolute inset-0 flex size-full cursor-pointer items-center justify-center rounded-sm border border-dashed border-border-default bg-[repeating-linear-gradient(135deg,transparent_0_6px,var(--df-border-default)_6px_7px)] px-2 text-center font-semibold text-text-primary focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none [@media(hover:hover)]:hover:border-[1.5px] [@media(hover:hover)]:hover:border-solid [@media(hover:hover)]:hover:border-accent"
                :aria-label="labels.missingName(missing.label)"
                data-test="missing-slot-placeholder"
                @click="open(missing.slot, $event)"
            >
                <span class="rounded-sm bg-surface-card px-1">{{
                    labels.missingText(missing.label, missingRole)
                }}</span>
            </button>
            <div
                v-else
                class="type-caption absolute inset-0 flex size-full items-center justify-center rounded-sm border border-dashed border-border-default bg-[repeating-linear-gradient(135deg,transparent_0_6px,var(--df-border-default)_6px_7px)] px-2 text-center font-semibold text-text-primary"
                data-test="missing-slot-placeholder-static"
            >
                <span class="rounded-sm bg-surface-card px-1">{{
                    labels.missingText(missing.label, missingRole)
                }}</span>
            </div>
        </div>
    </div>
</template>
