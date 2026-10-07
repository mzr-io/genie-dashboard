<script setup lang="ts">
import type { RadioGroupItemProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { RadioGroupIndicator, RadioGroupItem, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<RadioGroupItemProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")
const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <RadioGroupItem
    data-slot="radio-group-item"
    v-bind="forwardedProps"
    :class="cn(
      'hit-area peer aspect-square size-4 shrink-0 rounded-full border border-border-control bg-surface-card transition-colors disabled:cursor-not-allowed disabled:opacity-45 aria-invalid:border-error data-[state=checked]:border-accent-ink-strong',
      props.class,
    )"
  >
    <RadioGroupIndicator
      data-slot="radio-group-indicator"
      class="flex items-center justify-center"
    >
      <span class="size-2 rounded-full bg-accent-ink-strong" />
    </RadioGroupIndicator>
  </RadioGroupItem>
</template>
