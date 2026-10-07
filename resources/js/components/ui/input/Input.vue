<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { useVModel } from "@vueuse/core"
import { cn } from "@/lib/utils"

const props = defineProps<{
  defaultValue?: string | number
  modelValue?: string | number
  class?: HTMLAttributes["class"]
}>()

const emits = defineEmits<{
  (e: "update:modelValue", payload: string | number): void
}>()

const modelValue = useVModel(props, "modelValue", emits, {
  passive: true,
  defaultValue: props.defaultValue,
})
</script>

<template>
  <input
    v-model="modelValue"
    data-slot="input"
    :class="cn(
      'placeholder:text-text-muted selection:bg-brand selection:text-on-accent min-h-9 w-full min-w-0 rounded-md border border-border-control bg-surface-card px-3 py-1 text-sm text-text-primary transition-[color,box-shadow] file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:cursor-not-allowed disabled:opacity-45',
      'focus:border-brand focus:shadow-[0_0_0_3px_var(--df-accent-soft)]',
      'aria-invalid:border-error',
      props.class,
    )"
  >
</template>
