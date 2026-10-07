<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <!-- The content column: top bar (banner) and page (main) are its children, so it is not a landmark itself. -->
  <div
    data-slot="sidebar-inset"
    :class="cn('bg-surface-canvas relative flex w-full min-w-0 flex-1 flex-col', props.class)"
  >
    <slot />
  </div>
</template>
