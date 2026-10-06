<script setup lang="ts">
import { CircleCheck, Info, OctagonX, TriangleAlert, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { overlayLabels } from '@/locales/labels';
import { isPersistent, useToasts } from '@/stores/toasts';
import type { ToastItem, ToastKind } from '@/stores/toasts';

// The toast stack is a region named "Notifications" (UX-DR-270). It sits top right of the content
// area; inside an open sheet on mobile it is rendered by the sheet, above its footer.
const props = withDefaults(defineProps<{ placement?: 'content' | 'sheet' }>(), {
    placement: 'content',
});

const toasts = useToasts();

onMounted(() => {
    if (props.placement === 'sheet') {
        toasts.sheetHosts += 1;
    }
});

onBeforeUnmount(() => {
    if (props.placement === 'sheet') {
        toasts.sheetHosts -= 1;
    }
});

// The layout region steps aside while a sheet hosts the stack, so one region is named
// "Notifications" at a time.
const active = computed(
    () => props.placement === 'sheet' || toasts.sheetHosts === 0,
);

const icons: Record<ToastKind, typeof Info> = {
    success: CircleCheck,
    action: CircleCheck,
    info: Info,
    warning: TriangleAlert,
    error: OctagonX,
    rollback: OctagonX,
};

const root = ref<HTMLElement | null>(null);
// The element that had focus before it entered a toast; focus goes back there on removal.
let before: HTMLElement | null = null;

function onFocusIn(toast: ToastItem, event: FocusEvent): void {
    const from = event.relatedTarget as HTMLElement | null;

    if (from && !root.value?.contains(from)) {
        before = from;
    }

    toasts.hold(toast.id, 'focus');
}

// Removing the toast that holds focus must not drop focus to the body: go to the next toast's
// Dismiss, else the previously focused element, else the main content.
function remove(id: number): void {
    const el = root.value?.querySelector(`[data-toast-id="${id}"]`);

    if (el?.contains(document.activeElement)) {
        const index = toasts.items.findIndex((item) => item.id === id);
        const other = toasts.items[index + 1] ?? toasts.items[index - 1];
        const next = other
            ? root.value?.querySelector<HTMLElement>(
                  `[data-toast-id="${other.id}"] [data-slot="toast-dismiss"]`,
              )
            : null;
        const target = next ?? (before?.isConnected ? before : null);

        (target ?? document.getElementById('main-content'))?.focus();
    }

    toasts.dismiss(id);
}

function run(toast: ToastItem, index: number): void {
    try {
        toast.actions[index]?.run();
    } finally {
        remove(toast.id);
    }
}

function onPointerEnter(toast: ToastItem, event: PointerEvent): void {
    // Touch has no hover to release, so only a mouse holds the toast.
    if (event.pointerType === 'mouse') {
        toasts.hold(toast.id, 'hover');
    }
}
</script>

<template>
    <section
        v-if="active"
        ref="root"
        id="notifications"
        tabindex="-1"
        role="region"
        :aria-label="overlayLabels.notifications"
        data-slot="toast-region"
        :data-placement="placement"
        :class="
            placement === 'sheet'
                ? 'flex flex-col gap-2 px-4 empty:hidden'
                : 'pointer-events-none fixed top-[calc(var(--df-topbar-height)+var(--df-space-3))] right-4 z-[60] flex w-[min(24rem,calc(100vw-2rem))] flex-col gap-2 empty:hidden'
        "
    >
        <TransitionGroup name="toast">
            <div
                v-for="toast in toasts.items"
                :key="toast.id"
                data-slot="toast"
                :data-kind="toast.kind"
                :data-toast-id="toast.id"
                :role="isPersistent(toast.kind) ? 'alert' : undefined"
                class="type-body-sm pointer-events-auto flex items-start gap-3 rounded-lg bg-surface-inverse px-4 py-3 text-text-inverse shadow-[0_10px_30px_color-mix(in_srgb,var(--df-shadow-color)_25%,transparent)]"
                @pointerenter="onPointerEnter(toast, $event)"
                @pointerleave="toasts.release(toast.id, 'hover')"
                @focusin="onFocusIn(toast, $event)"
                @focusout="toasts.release(toast.id, 'focus')"
            >
                <component
                    :is="icons[toast.kind]"
                    class="mt-0.5 size-[18px] shrink-0"
                    :data-icon="isPersistent(toast.kind) ? 'error' : 'status'"
                    aria-hidden="true"
                />
                <p class="min-w-0 flex-1">
                    <span v-if="isPersistent(toast.kind)" class="sr-only">
                        {{ overlayLabels.errorToast }}:
                    </span>
                    {{ toast.message }}
                </p>
                <button
                    v-for="(action, index) in toast.actions"
                    :key="index"
                    type="button"
                    data-slot="toast-action"
                    class="min-h-(--df-target-chrome) rounded-sm px-1 font-bold text-accent-ink-inverse underline"
                    @click="run(toast, index)"
                >
                    {{ action.label }}
                </button>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    data-slot="toast-dismiss"
                    class="text-text-inverse hover:bg-surface-inverse"
                    @click="remove(toast.id)"
                >
                    <X aria-hidden="true" />
                    <span class="sr-only">{{
                        overlayLabels.dismissNotification
                    }}</span>
                </Button>
            </div>
        </TransitionGroup>
    </section>
</template>
