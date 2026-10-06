<script setup lang="ts">
import type { SelectTriggerProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { ChevronDown } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import { SelectIcon, SelectTrigger, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = withDefaults(
  defineProps<SelectTriggerProps & { class?: HTMLAttributes["class"], size?: "sm" | "default" }>(),
  { size: "default" },
)

const delegatedProps = reactiveOmit(props, "class", "size")
const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <SelectTrigger
    data-slot="select-trigger"
    :data-size="size"
    v-bind="forwardedProps"
    :class="cn(
      'data-[placeholder]:text-text-muted focus:border-brand focus:shadow-[0_0_0_3px_var(--df-accent-soft)] aria-invalid:border-error flex w-fit items-center justify-between gap-2 rounded-md border border-border-control bg-surface-card px-3 py-2 text-sm text-text-primary whitespace-nowrap transition-[color,box-shadow] disabled:cursor-not-allowed disabled:opacity-45 data-[size=default]:min-h-9 data-[size=sm]:min-h-(--df-target-chrome) *:data-[slot=select-value]:line-clamp-1 *:data-[slot=select-value]:flex *:data-[slot=select-value]:items-center *:data-[slot=select-value]:gap-2 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4',
      props.class,
    )"
  >
    <slot />
    <SelectIcon as-child>
      <ChevronDown class="size-4 text-text-muted" />
    </SelectIcon>
  </SelectTrigger>
</template>
