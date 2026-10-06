<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { useMediaQuery } from "@vueuse/core"
import { computed } from "vue"
import { useStillLoading } from "@/composables/useStillLoading"
import { cn } from "@/lib/utils"
import { controlLabels } from "@/locales/labels"

defineOptions({ inheritAttrs: false })

// A bar shaped like its content. Shimmer stops after 5 s and a "Still loading…" caption
// appears; under prefers-reduced-motion it is static from the start (UX-DR-36). Use
// `caption=false` on all but one bar of a group.
const props = withDefaults(defineProps<{
  class?: HTMLAttributes["class"]
  loading?: boolean
  caption?: boolean
}>(), {
  loading: true,
  caption: true,
})

const reducedMotion = useMediaQuery("(prefers-reduced-motion: reduce)")
const { stalled } = useStillLoading(() => props.loading)
const shimmering = computed(() => props.loading && !stalled.value && !reducedMotion.value)
</script>

<template>
  <div
    data-slot="skeleton-group"
    class="contents"
  >
    <div
      data-slot="skeleton"
      v-bind="$attrs"
      aria-hidden="true"
      :data-shimmer="shimmering ? 'true' : 'false'"
      :class="cn('skeleton rounded-sm bg-surface-muted', props.class)"
    />
    <p
      v-if="caption && stalled"
      data-slot="skeleton-caption"
      class="type-caption mt-1 text-text-muted"
    >
      {{ controlLabels.stillLoading }}
    </p>
  </div>
</template>
