<script setup lang="ts">
import { Info, OctagonX, TriangleAlert, X } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { overlayLabels } from '@/locales/labels';

// Full-width banner at the top of content (UX-DR-138): body-sm, an icon and a word, so colour is
// never the only signal. A dismissible banner removes itself and hands focus to a sensible
// element; it never blocks the step it appears on.
const props = withDefaults(
    defineProps<{
        variant?: 'info' | 'warning' | 'error';
        dismissible?: boolean;
        // Where focus goes after Dismiss; defaults to the main content.
        returnFocus?: () => HTMLElement | null | undefined;
    }>(),
    { variant: 'info', dismissible: false },
);

const emit = defineEmits<{ (e: 'dismiss'): void }>();

const hidden = ref(false);

const word = computed(
    () =>
        ({
            info: overlayLabels.bannerInfo,
            warning: overlayLabels.bannerWarning,
            error: overlayLabels.bannerError,
        })[props.variant],
);

const icon = computed(
    () =>
        ({ info: Info, warning: TriangleAlert, error: OctagonX })[
            props.variant
        ],
);

const tone = computed(
    () =>
        ({
            info: 'bg-info-soft text-info-strong',
            warning:
                'bg-warning-soft text-text-primary border-l-[3px] border-warning-bar',
            error: 'bg-error-soft text-error-text',
        })[props.variant],
);

async function dismiss(): Promise<void> {
    hidden.value = true;
    emit('dismiss');
    await nextTick();

    const target =
        props.returnFocus?.() ?? document.getElementById('main-content');

    target?.focus();
}
</script>

<template>
    <div
        v-if="!hidden"
        data-slot="banner"
        :data-variant="variant"
        :class="['type-body-sm flex w-full items-center gap-2 px-4 py-2', tone]"
    >
        <component :is="icon" class="size-4 shrink-0" aria-hidden="true" />
        <p class="min-w-0 flex-1">
            <strong class="font-semibold">{{ word }}:</strong>
            <slot />
        </p>
        <Button
            v-if="dismissible"
            variant="ghost"
            size="icon-sm"
            data-slot="banner-dismiss"
            class="text-current"
            @click="dismiss"
        >
            <X aria-hidden="true" />
            <span class="sr-only">{{ overlayLabels.dismiss }}</span>
        </Button>
    </div>
</template>
