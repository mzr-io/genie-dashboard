<script setup lang="ts">
import type { SelectItemProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { Check } from "@lucide/vue"
import { reactiveOmit } from "@vueuse/core"
import { useId } from "vue"
import {
  SelectItem,
  SelectItemIndicator,
  SelectItemText,
  useForwardProps,
} from "reka-ui"
import { cn } from "@/lib/utils"

// `reason` is the description of a disabled option (UX-DR-26): it is shown inline at full
// opacity and linked with aria-describedby, so it is never only in a tooltip.
const props = defineProps<SelectItemProps & { class?: HTMLAttributes["class"], reason?: string }>()

const delegatedProps = reactiveOmit(props, "class", "reason")

const forwardedProps = useForwardProps(delegatedProps)
const reasonId = useId()
</script>

<template>
  <SelectItem
    data-slot="select-item"
    v-bind="forwardedProps"
    :aria-describedby="reason ? reasonId : undefined"
    :class="
      cn(
        'focus:bg-accent focus:text-accent-foreground [&_svg:not([class*=\'text-\'])]:text-text-muted relative flex w-full cursor-default items-center gap-2 rounded-sm py-1.5 pr-8 pl-2 text-sm select-none data-[disabled]:pointer-events-none [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*=\'size-\'])]:size-4',
        props.class,
      )
    "
  >
    <span class="absolute right-2 flex size-3.5 items-center justify-center">
      <SelectItemIndicator>
        <slot name="indicator-icon">
          <Check class="size-4" />
        </slot>
      </SelectItemIndicator>
    </span>

    <span class="flex flex-col">
      <SelectItemText :class="disabled ? 'opacity-45' : undefined">
        <slot />
      </SelectItemText>
      <span
        v-if="reason"
        :id="reasonId"
        class="type-caption text-text-secondary"
      >{{ reason }}</span>
    </span>
  </SelectItem>
</template>
