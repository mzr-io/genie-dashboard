import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Badge } from "./Badge.vue"

// Badges are never interactive and always carry text (UX-DR-39).
export const badgeVariants = cva(
  "type-caption inline-flex w-fit shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-2 font-semibold [&>svg]:size-3 [&>svg]:pointer-events-none",
  {
    variants: {
      variant: {
        neutral: "bg-surface-muted text-text-secondary",
        draft: "bg-accent-soft text-accent-ink-strong",
        operational: "bg-success-soft text-success-text",
      },
    },
    defaultVariants: {
      variant: "neutral",
    },
  },
)
export type BadgeVariants = VariantProps<typeof badgeVariants>
