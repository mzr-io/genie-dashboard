import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

// Six variants (UX-DR-16..21). Focus is the global 3px ring only; a blocked control keeps
// aria-disabled and is dimmed on the control alone (UX-DR-22).
export const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap text-[length:var(--df-type-title-sm-size)] font-semibold transition-colors disabled:pointer-events-none disabled:opacity-45 aria-disabled:opacity-45 aria-disabled:cursor-not-allowed [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0",
  {
    variants: {
      variant: {
        "primary":
          "rounded-md bg-brand text-on-accent hover:bg-accent-strong aria-disabled:hover:bg-brand",
        "secondary":
          "rounded-md border border-border-strong bg-surface-card text-text-primary hover:bg-surface-sunken aria-disabled:hover:bg-surface-card",
        "ghost":
          "rounded-md bg-transparent text-text-secondary hover:bg-surface-sunken aria-disabled:hover:bg-transparent",
        "link":
          "rounded-sm text-accent-ink text-[length:var(--df-type-caption-size)] underline-offset-4 hover:underline",
        "destructive-soft":
          "rounded-sm border border-error-border bg-error-soft text-error-text hover:bg-error-soft/70",
        "destructive":
          "rounded-md bg-error text-on-status hover:bg-error/90 aria-disabled:hover:bg-error",
      },
      size: {
        "default": "min-h-[34px] px-[14px] has-[>svg]:px-3",
        "sm": "min-h-(--df-target-chrome) px-3 has-[>svg]:px-2.5",
        "lg": "min-h-10 px-6 has-[>svg]:px-4",
        "icon": "size-9",
        "icon-sm": "size-(--df-target-chrome)",
        "icon-lg": "size-10",
      },
    },
    compoundVariants: [
      // Row actions use the 28px chrome target (UX-DR-20).
      { variant: "destructive-soft", size: "default", class: "min-h-(--df-target-chrome) px-3" },
      // Links are inline text; they keep the 24px minimum target.
      { variant: "link", size: "default", class: "min-h-(--df-target-min) px-1" },
    ],
    defaultVariants: {
      variant: "primary",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
