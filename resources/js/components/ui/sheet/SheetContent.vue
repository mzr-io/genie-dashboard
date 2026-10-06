<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { X } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import {
  DialogClose,
  DialogContent,
  DialogPortal,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"
import { overlayLabels } from "@/locales/labels"
import ToastRegion from "@/components/ToastRegion.vue"
import SheetOverlay from "./SheetOverlay.vue"

interface SheetContentProps extends DialogContentProps {
  class?: HTMLAttributes["class"]
  side?: "top" | "right" | "bottom" | "left"
}

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(defineProps<SheetContentProps>(), {
  side: "right",
})
const emits = defineEmits<DialogContentEmits>()

const delegatedProps = reactiveOmit(props, "class", "side")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <DialogPortal>
    <SheetOverlay />
    <DialogContent
      data-slot="sheet-content"
      :class="cn(
        'bg-surface-card text-text-primary motion-safe:data-[state=open]:animate-in motion-safe:data-[state=closed]:animate-out fixed z-50 flex flex-col gap-4 shadow-[0_12px_40px_color-mix(in_srgb,var(--df-shadow-color)_12%,transparent)] motion-safe:transition ease-in-out motion-safe:data-[state=closed]:duration-300 motion-safe:data-[state=open]:duration-500',
        side === 'right'
          && 'motion-safe:data-[state=closed]:slide-out-to-right motion-safe:data-[state=open]:slide-in-from-right inset-y-0 right-0 h-full w-3/4 rounded-s-[var(--df-radius-sheet)] border-l border-border-default sm:max-w-sm',
        side === 'left'
          && 'motion-safe:data-[state=closed]:slide-out-to-left motion-safe:data-[state=open]:slide-in-from-left inset-y-0 left-0 h-full w-3/4 rounded-e-[var(--df-radius-sheet)] border-r border-border-default sm:max-w-sm',
        side === 'top'
          && 'motion-safe:data-[state=closed]:slide-out-to-top motion-safe:data-[state=open]:slide-in-from-top inset-x-0 top-0 h-auto border-b',
        side === 'bottom'
          && 'motion-safe:data-[state=closed]:slide-out-to-bottom motion-safe:data-[state=open]:slide-in-from-bottom inset-x-0 bottom-0 h-auto border-t',
        props.class)"
      v-bind="{ ...$attrs, ...forwarded }"
    >
      <slot />
      <!-- The footer is order-last, so the stack shows above it (UX-DR-70). -->
      <ToastRegion placement="sheet" />

      <DialogClose
        data-slot="sheet-close"
        class="absolute top-2 right-2 inline-flex size-(--df-target-touch) items-center justify-center rounded-md text-text-secondary hover:bg-surface-sunken disabled:pointer-events-none"
      >
        <X class="size-5" aria-hidden="true" />
        <span class="sr-only">{{ overlayLabels.close }}</span>
      </DialogClose>
    </DialogContent>
  </DialogPortal>
</template>
