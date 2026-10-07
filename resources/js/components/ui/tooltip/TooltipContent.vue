<script setup lang="ts">
import type { TooltipContentEmits, TooltipContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TooltipContent, TooltipPortal, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

// Hover and focus open it, Esc closes it without moving focus, and it stays open while the
// pointer is over it (UX-DR-35, WCAG 1.4.13). It never carries the only copy of information.
const props = withDefaults(defineProps<TooltipContentProps & { class?: HTMLAttributes["class"] }>(), {
  sideOffset: 4,
})

const emits = defineEmits<TooltipContentEmits>()

const delegatedProps = reactiveOmit(props, "class")
const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <TooltipPortal>
    <TooltipContent
      data-slot="tooltip-content"
      v-bind="{ ...forwarded, ...$attrs }"
      :class="cn('type-caption bg-surface-inverse text-text-inverse z-50 w-fit max-w-[280px] rounded-sm px-2 py-1 text-balance', props.class)"
    >
      <slot />
    </TooltipContent>
  </TooltipPortal>
</template>
