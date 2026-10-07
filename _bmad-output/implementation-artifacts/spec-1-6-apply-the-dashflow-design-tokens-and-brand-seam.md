---
title: 'Apply the Dashflow design tokens and brand seam'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: '93626723302d6c30ae100eb02c9143633ecacf4c'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/DESIGN.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The app still carries the starter kit's grey palette, Instrument Sans, a dark theme and an Appearance setting, so nothing matches the Dashflow design system and components cannot rely on tokens (Story 1.6; NFR-7, NFR-10, UX-DR-1..14, 268, 283..288; the Story 1.6 section of `epics.md` holds the acceptance criteria).

**Approach:** One light-theme token file generated from DESIGN.md, bound to the shadcn-vue variables, with a single `data-workspace` brand seam, typography and focus rules, a Logo component, and tests that prove token values, contrast ratios and the guardrails.

## Boundaries & Constraints

**Always:** Token values come from DESIGN.md (frontmatter `colors`, `typography`, `rounded`, `spacing`, elevation); the `epics.md` UX-DR lines state the required ratios. Tokens live in `resources/css/tokens.css` (the path `scripts/lint-colors.mjs` already expects) as custom properties on `:root`, with no `-dark` values and a structure where a second theme is additive. Only brand tokens (UX-DR-283 list) are overridable, by a `[data-workspace]` scope; system tokens never are. `on-accent` is derived as whichever of #111827 and #FFFFFF has higher contrast against the accent; an override `accent-ink` below 4.5:1 on white or on its accent-soft, or `accent-ink-inverse` below 4.5:1 on surface-inverse and hero-chip, falls back to the default (a pure function with unit tests). Inter with the specified fallback stack, delivered through the existing `@fonts` mechanism. Figures use tabular numerals; no text below 12px except `label-caps` (11px, as specified). Focus is a 3px solid `focus-ring` with 2px offset on every interactive element; `html` sets `scroll-padding` for sticky layers. Resting cards: 1px `border-default`, no shadow. Components reference tokens only.

**Never:** The Appearance control, theme toggle, `.dark` variant or `dark:` utilities, and the `appearance` route, page, middleware, cookie and composable (remove them; `settings/appearance` returns 404); `-dark` token values; building the shared form controls, dialogs or sign-in hero (Stories 1.8, 1.9, 1.13); migrating `Welcome.vue` (its lint allowlist entry stays until Story 1.16); raw hex or rgba() outside the token file; yellow as text colour or focus indicator.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Default load | No `data-workspace` | Every UX-DR-1..6, 9..11 token resolves; stated contrast ratios hold | Test fails on a missing token or ratio |
| Brand override | `[data-workspace]` sets accent and logo tokens | Brand tokens and logo change; system tokens equal defaults | Test fails if any system token changes |
| Light accent | Accent such as #FACC15 | `on-accent` #111827; dark accent gives #FFFFFF | N/A |
| Weak accent-ink | Override below 4.5:1 on white or on its accent-soft | Default `accent-ink` kept | Never throws |
| Appearance gone | Build and routes | No toggle in DOM, no `dark` variant, no `-dark` token; `GET settings/appearance` is 404 | Test fails on any trace |
| Raw colour | Hex or rgba() in a component | Colour lint fails and names file:line | N/A |

</frozen-after-approval>

## Code Map

- `scripts/lint-colors.mjs` -- token path `resources/css/tokens.css`; remove the `resources/js/app.ts` allowlist entry (progress bar colour at `resources/js/app.ts:34` becomes a token); `Welcome.vue` stays
- `resources/css/app.css` -- replace the starter palette and `.dark` block: import tokens, map shadcn variables (`--primary`, `--ring`, `--border`, `--input`, `--sidebar-*`, `--chart-*`) to DESIGN.md tokens, drop `@custom-variant dark`, add typography and focus layers
- `vite.config.ts`, `resources/views/app.blade.php` -- font family Inter via `fonts`; remove the dark-mode script, inline dark styles and `@class(['dark' ...])`
- `app/Http/Middleware/HandleAppearance.php`, `bootstrap/app.php`, `routes/settings.php` -- remove middleware registration and the `appearance.edit` route
- `resources/js/components/AppearanceTabs.vue`, `pages/settings/Appearance.vue`, `composables/useAppearance.ts`, `layouts/settings/Layout.vue`, `types/ui.ts`, `app.ts`, `routes/appearance/`, `actions/Inertia/Controller.ts` -- delete or edit out; regenerate Wayfinder output
- `resources/js/components/AppLogo.vue`, `AppLogoIcon.vue`, `AppSidebar.vue`, `components/ui/sidebar` -- Logo (28px tile, four marks, wordmark) and the sidebar active-item 3px bar
- `resources/js/lib/` -- new `brand.ts` (derive and fallback) beside existing helpers
- `tests/js/gates.test.ts` -- existing vitest style; add `tests/js/tokens.test.ts`, `contrast.test.ts`, `brand.test.ts`; `tests/Feature/Settings/*` -- 404 check

## Tasks & Acceptance

**Execution:**
- [x] `resources/css/tokens.css` -- all brand, system, status, hero, data-role, chart, radius, spacing, sizing, elevation and focus tokens; brand seam scope; no `-dark`
- [x] `resources/css/app.css`, `vite.config.ts`, `resources/views/app.blade.php` -- bind shadcn variables, Inter, typography styles (UX-DR-7/8), focus ring and `scroll-padding`, tabular numerals, selected-state cue classes (UX-DR-14)
- [ ] Appearance files, middleware and route -- remove every trace of the Appearance control and dark theme
- [x] `resources/js/lib/brand.ts`, `AppLogo.vue`, `AppLogoIcon.vue`, `AppSidebar.vue`, `app.ts`, `scripts/lint-colors.mjs` -- derivation and fallback, Logo on the seam, sidebar bar, progress colour token
- [x] `tests/js/*.test.ts`, `tests/Feature/Settings/*` -- every matrix row; token manifest, contrast pairs of UX-DR-1..6 and 13, typography values, no `-dark`, no toggle in source, 404

**Acceptance Criteria:**
- Given the light theme loads, when tokens are read, then every UX-DR-1..6 token resolves and the stated contrast ratios are verified by tests.
- Given `bin/tools composer ci:check`, when run, then it passes, including colour lint without the `app.ts` allowlist entry.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Button `link` variant is yellow text (`text-primary` = accent) | high | patch | `ui/button/index.ts:21`; accent #FACC15 on white is about 1.6:1 and the spec bans yellow text. |
| Focus indicator doubled: shadcn ring/outline utilities plus the global 3px outline | medium | patch | Components keep `focus-visible:ring-[3px] ring-ring/50`, `ring-2`, `ring-4`, `outline-none`; the unlayered rule overrides `outline-none`. |
| Palette utilities remain (`bg-black`, `red-*`, `zinc-900`, `neutral-*`, `text-white`), trailing spaces from the `dark:` strip | medium | patch | "Components reference tokens only"; colour lint matches only hex and rgba(). |
| `AppLogoIcon` fixed `size-7` conflicts with callers' `size-9`; auth logo link has no accessible name | medium | patch | Plain class array decides by CSS order; icon is now `aria-hidden` inside a link with no text. |
| `chart-1-stroke` contrast pair reads `accent-ink`; `info-strong` threshold fudge `7.15 - 0.2` | low | patch | Tests would pass if `chart-1-stroke` were repointed; documented 7.15 is 7.1492 actual, so tolerance should be 0.01. |
| Welcome.vue loads Inter from rsms.me | low | defer | Pre-existing starter kit code, allowlisted until Story 1.16. |
| Brand seam has no runtime caller of `resolveBrand` | low | rejected | MVP ships no overrides (UX-DR-283); the CSS scope and pure functions are what the spec requires. |
| Guards beyond `accent-ink`/`accent-ink-inverse`, colour formats, SSR `readDefaults`, `deriveOnAccent` with empty defaults | low | rejected | Spec names exactly two guards; nothing calls these paths yet. |
| `chart-1-fill` not following accent | false | rejected | UX-DR-283 lists only `chart-1` and `chart-1-stroke`. |
| Global `scroll-padding-left`, `tabindex=-1` focus exclusion | low | rejected | Excluding `tabindex=-1` would drop roving-tabindex controls; padding over-reach is cosmetic. |
| Narrowed Tailwind `@source`, hardcoded wordmark, duplicate cue bar, cookie/localStorage cleanup, brittle no-trace scan, `text-subtle` guard | low | rejected | No pagination or PHP-built classes in use; wordmark moves with Story 1.7; fixes add machinery for unlikely cases. |

## Design Notes

shadcn mapping: `--primary` = accent with `--primary-foreground` = on-accent; `--ring` = focus-ring; `--input` = border-control; `--border` = border-default; `--destructive` = error. Font delivery stays on the build's `@fonts` path (files served from the app origin).

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0
- `bin/tools npm run build` -- expected: exit 0, no `.dark` rules in the built CSS
