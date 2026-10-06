<script setup lang="ts">
import type { SwitchRootEmits, SwitchRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { ref, watch } from "vue"
import { SwitchRoot, SwitchThumb, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"
import { controlLabels } from "@/locales/labels"

defineOptions({ inheritAttrs: false })

const props = defineProps<SwitchRootProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<SwitchRootEmits>()

const delegatedProps = reactiveOmit(props, "class")
const forwarded = useForwardPropsEmits(delegatedProps, emits)

// The word follows the switch whether it is controlled or not.
const on = ref(Boolean(props.modelValue ?? props.defaultValue))
watch(() => props.modelValue, (value) => {
  if (value !== undefined) {
    on.value = Boolean(value)
  }
})
function onUpdate(value: boolean) {
  on.value = value
}
</script>

<template>
  <!-- role="switch" with aria-checked; Space toggles; the On/Off word is always visible (UX-DR-34) -->
  <span :class="cn('inline-flex items-center gap-2', props.class)">
    <SwitchRoot
      v-slot="slotProps"
      data-slot="switch"
      v-bind="{ ...$attrs, ...forwarded }"
      @update:model-value="onUpdate"
      class="hit-area peer inline-flex h-[18px] w-8 shrink-0 items-center rounded-full border border-border-control bg-surface-card transition-colors data-[state=checked]:border-accent-ink-strong data-[state=checked]:bg-accent-ink-strong disabled:cursor-not-allowed disabled:opacity-45"
    >
      <SwitchThumb
        data-slot="switch-thumb"
        class="pointer-events-none block size-3.5 rounded-full border border-border-control bg-surface-card transition-transform data-[state=checked]:translate-x-[14px] data-[state=checked]:border-surface-card data-[state=unchecked]:translate-x-px"
      />
      <slot v-bind="slotProps" />
    </SwitchRoot>
    <span
      data-slot="switch-word"
      aria-hidden="true"
      class="type-caption text-text-secondary"
    >{{ on ? controlLabels.on : controlLabels.off }}</span>
  </span>
</template>
