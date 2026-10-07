<script setup lang="ts">
import type { PrimitiveProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import type { ButtonVariants } from "."
import { Primitive } from "reka-ui"
import { useBlockedAction } from "@/composables/useBlockedAction"
import { cn } from "@/lib/utils"
import { buttonVariants } from "."

interface Props extends PrimitiveProps {
  variant?: ButtonVariants["variant"]
  size?: ButtonVariants["size"]
  class?: HTMLAttributes["class"]
  // A blocked button uses aria-disabled, stays focusable and runs nothing (UX-DR-22, 272).
  // Point aria-describedby at the inline BlockedReason that explains why.
  blocked?: boolean
  blockedReason?: string
  firstBlocker?: () => HTMLElement | null | undefined
}

const props = withDefaults(defineProps<Props>(), {
  as: "button",
  blocked: false,
})

const emit = defineEmits<{ (e: "click", event: MouseEvent): void }>()

const { guard } = useBlockedAction({
  blocked: () => props.blocked,
  reason: () => props.blockedReason,
  firstBlocker: () => props.firstBlocker?.(),
})

function onClick(event: MouseEvent) {
  if (guard(event)) {
    emit("click", event)
  }
}
</script>

<template>
  <Primitive
    data-slot="button"
    :data-variant="variant ?? 'primary'"
    :data-size="size"
    :as="as"
    :as-child="asChild"
    :aria-disabled="blocked ? 'true' : undefined"
    :class="cn(buttonVariants({ variant, size }), props.class)"
    @click="onClick"
  >
    <slot />
  </Primitive>
</template>
