---
title: 'Build the shared form controls and status components'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: '47819ffb99339f8ef504af2f5df70a231e6b57dd'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-6-apply-the-dashflow-design-tokens-and-brand-seam.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/DESIGN.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Forms and lists use unstyled starter-kit controls, so buttons, fields, chips and badges differ between screens and are not reliably operable by keyboard and screen reader (Story 1.8; NFR-7, UX-DR-16..24, 26, 29..37, 39, 40, 43, 46, 272..274, 278, 279, 281). The Story 1.8 section of `epics.md` holds the acceptance criteria; the UX-DR lines hold the specification of each component.

**Approach:** Token-only shared components built on reka-ui and the existing `components/ui` primitives, with accessibility behaviour tested in a DOM environment and a static check that a view has one primary button.

## Boundaries & Constraints

**Always:** Components reference tokens and utilities from Story 1.6 only (no hex, rgba or palette classes); the global focus ring is the only focus indicator; the 3px `accent-soft` input halo is allowed. Button variants are exactly `primary` (default), `secondary`, `ghost`, `link`, `destructive-soft`, `destructive`; update every caller of the removed `default` and `outline`. A blocked control uses `aria-disabled="true"`, stays focusable, points `aria-describedby` at an inline reason shown at full opacity, and its activation only focuses the first blocker and announces the reason through a minimal polite live region in `resources/js/lib/announce.ts` (Story 1.9 will extend it). Validation runs on blur and submit, never per keystroke; two or more errors show a focused summary of links that focus their fields, one error focuses the field. Required fields carry `required` and an `aria-hidden` red `*`, with "* Required" once per form. A saved secret never reaches the DOM text, attributes or accessible names. Tooltips show on hover and focus, Esc dismisses without moving focus, stay open while hovered, and never hold the only copy of information. Skeleton shimmer stops after 5 s and shows "Still loading…"; it is static under `prefers-reduced-motion`. Tag, badge and lock badge are not interactive and always include text; status dots and meters are `aria-hidden`; every status colour is paired with an icon, arrow or word. Targets are at least 24px, 28px for chrome, chip and row actions, 44px under `(pointer: coarse)`. All strings come from the catalogue or `labels.ts` (Story 1.7), never inline.

**Never:** Dialogs, toasts, banners, popovers and live-region infrastructure (Story 1.9); wiring screens or rewriting starter-kit pages beyond caller updates; a route or page for the gallery; a dark theme; changing the token file.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Variants | Each button variant in the gallery | Token colours, 34px (28px for destructive-soft), radius, focus ring | Test fails on a mismatch |
| Two primaries | A view file with two primary buttons | Static check fails, naming the file | N/A |
| Blocked control | Activated while blocked | `aria-disabled`, focusable, reason linked; focus moves to the first blocker and the reason is announced; no action runs | N/A |
| Field error | Blur or submit with an invalid value | Error border, icon, hidden "Error:", `aria-invalid`, `aria-describedby`; nothing on keystroke | N/A |
| Two errors | Submit with two invalid fields | Summary of links focused; each link focuses its field | N/A |
| One error | Submit with one invalid field | That field is focused, no summary | N/A |
| Saved secret | Secret set on 2 Oct 2026 | Masked text, "Secret set on 2 Oct 2026", no reveal; Replace clears and requires a new value | Secret absent from the DOM |
| Keyboard | Radiogroup, segmented control, chips, switch | Arrow keys move, one tab stop, Space toggles, visible "On"/"Off" word | N/A |
| Tooltip | Hover, focus, Esc | Appears, persists on hover, Esc hides, focus stays | N/A |
| Skeleton | 5 s elapsed, or reduced motion | Shimmer stops and "Still loading…" shows; static under reduced motion | N/A |
| Lock badge | Rendered | Text "Locked" or the `locked` message; announced "required by your admin and cannot be removed" | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/components/ui/` -- shadcn primitives already customised in Story 1.6 (`button`, `input`, `checkbox`, `select`, `badge`, `skeleton`, `tooltip`, `label`); change these in place, add `textarea`, `radio-group`, `switch`
- `resources/js/components/` -- new composed components: `FormField`, `FormErrorSummary`, `RequiredNote`, `SecretField`, `SegmentedControl`, `CategoryChip`, `BlockedReason`, `StatusDot`, `Tag`, `LockBadge`, `ComponentGallery`
- `resources/js/lib/announce.ts`, `resources/js/composables/` -- announcer and `useBlockedAction`, `useBlurValidation`, `useStillLoading`
- `resources/js/locales/labels.ts`, `locales/en.ts` -- labels ("Required", "Error:", "Replace token", "On", "Off", "Still loading…", secret and lock text); `locked` message exists
- `resources/css/app.css` -- `.cue-*` and `.type-*` classes from Story 1.6; touch-target media rule
- `package.json`, `vite.config.ts` -- add `happy-dom` and `@vue/test-utils` as dev dependencies; DOM tests opt in with a `@vitest-environment happy-dom` docblock so existing node tests stay unchanged
- `tests/js/` -- existing vitest style; also add the mounted test for `TechnicalDetails` deferred from Story 1.7
- `resources/js/pages`, `layouts`, `components/*.vue` -- callers of the old button variants, and the scan target for the one-primary check

## Tasks & Acceptance

**Execution:**
- [x] `components/ui/button`, callers -- six variants, blocked-action behaviour, one-primary static check
- [x] `components/ui/input`, `textarea`, `select`, `FormField`, `FormErrorSummary`, `RequiredNote`, composables -- field states, blur validation, summary focus
- [x] `SecretField`, `SegmentedControl`, `CategoryChip`, `radio-group`, `checkbox`, `switch` -- semantics, tokens, keyboard
- [x] `tooltip`, `skeleton`, `badge`, `Tag`, `LockBadge`, `StatusDot`, `lib/announce.ts` -- status pieces, timers, reduced motion
- [x] `ComponentGallery`, `tests/js/*.test.ts`, `package.json` -- every matrix row in a DOM environment, plus the `TechnicalDetails` mounted test

**Acceptance Criteria:**
- Given the component gallery renders, when tests run, then each Story 1.8 criterion passes in a DOM environment.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the licence audit for new dev dependencies.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Saved secret group has no accessible name; `aria-describedby`/`aria-invalid` dropped; `v-model:replacing` not a declared prop so it leaks onto the input; fresh `Date` identity resets an in-progress replacement | medium | patch | `SecretField.vue` forwards only `id` to a `div role="group"` and emits `replacing` without accepting it; the `savedAt` watcher compares identity. |
| `formatDay` throws on an unparsable date | medium | patch | `Intl.DateTimeFormat.format` raises `RangeError` for an invalid date, so `SecretField` fails to render. |
| Every `Skeleton` adds its own "Still loading…" paragraph, which breaks the sidebar menu skeleton row | medium | patch | `SidebarMenuSkeleton` renders two bars in an `h-8` row; after 5 s each adds a block caption. |
| Disabled select option `reason` and `aria-describedby` untested | medium | patch | The select is never opened in a test; dropping the link or the span keeps tests green. |
| `SegmentedControl` and chip group can render without an accessible name | medium | patch | `label` is optional; UX-DR-274 requires names. |
| `useBlurValidation`: unknown field name throws, summary keeps fixed errors, async submit can run twice | medium | patch | `onBlur` dereferences a missing rule; `showSummary` is not re-derived after a blur; no in-flight guard. |
| 44px coarse-pointer rule hits the inline `link` variant, which is deliberately 24px | low | patch | The rule matches every `[data-slot='button']`. |
| `galleryLabels` sits in the production labels module | low | patch | Spec says nothing demo-only ships; move it beside the gallery. |
| Live region created and filled in the same tick may go unspoken | medium | defer | Live-region infrastructure is Story 1.9's scope (spec Never list). |
| Space on the switch and arrow keys unverified in a real browser | low | defer | happy-dom does not synthesise Space as a click; needs a browser-level test. |
| Field errors lack `role="alert"`; no cancel after Replace; empty Replace with `novalidate`; indeterminate checkbox; Badge error/warning/info variants; `text-sm` inputs; blocked-button hover; no-reason blocked button | low | rejected | Not in the Story 1.8 criteria or left to the consumer's validation and later stories; fixes add behaviour beyond the spec. |
| One-primary check misses dynamic or single-quoted variants; tests assert class names, not computed styles; hardcoded strings in tests; `Badge` duplicates the dot markup | low | rejected | Prettier normalises quotes; computed styles need a real browser; duplication is cosmetic. |

## Design Notes

The gallery is a Vue component rendered by tests, not a route, so nothing demo-only ships. Dev dependencies are added now because keyboard behaviour cannot be tested in the node environment; this also closes the `TechnicalDetails` gap deferred in Story 1.7.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0
- `bin/tools npm run build` -- expected: exit 0
