<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import type { BadgeVariants } from "."
import { cn } from "@/lib/utils"
import { badgeVariants } from "."

// A plain span: no `as`, no click handling, no focus. The word is the slot; `dot` adds the
// decorative status dot that precedes it (UX-DR-40, 43).
const props = defineProps<{
  variant?: BadgeVariants["variant"]
  dot?: boolean
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <span
    data-slot="badge"
    :data-variant="variant ?? 'neutral'"
    :class="cn(badgeVariants({ variant }), props.class)"
  >
    <span
      v-if="dot"
      aria-hidden="true"
      data-slot="status-dot"
      class="size-1.5 shrink-0 rounded-full bg-current"
    />
    <slot />
  </span>
</template>
