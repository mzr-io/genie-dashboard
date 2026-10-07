---
title: 'Build dialogs, toasts, banners, popovers and live regions'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: 'd71482cd5b0f7cbc944208bda755003adc39d683'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-8-build-the-shared-form-controls-and-status-components.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/DESIGN.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Confirmations, notices and connection changes have no shared surface, so overlays differ between screens, focus can be lost and screen readers are not told what changed (Story 1.9; NFR-7, UX-DR-70, 75..78, 122, 138, 144, 251..253, 267, 268, 270, 271, 276, 277, 280). The Story 1.9 section of `epics.md` holds the acceptance criteria and the UX-DR lines the component specifications.

**Approach:** Token-only dialog, sheet, toast, banner and listbox-option components on reka-ui, a toast store with its own "Notifications" region replacing `vue-sonner`, one shared announcer that enforces the live-region policy, a connection-status composable, and a skip link in every layout.

## Boundaries & Constraints

**Always:** Components use Story 1.6 tokens and Story 1.8 controls; copy comes from the catalogue or `labels.ts` (buttons such as Cancel, Save, Discard changes, Keep editing, Dismiss, Undo, Show me live in `labels.ts`). The shared announcer in `resources/js/lib/announce.ts` is created before first use and sets text on a later tick, and offers four policies: polite, once (`role="status"`, one message per key until reset), assertive (`role="alert"`) and never; debounce helpers cover the ~500 ms and ~2 s cases. Destructive dialogs are `role="alertdialog"` with `aria-labelledby`, `aria-describedby`, initial focus on Cancel, Esc cancels, the destructive button repeats the object name, and focus returns to the invoker or, when it is gone, to a given fallback (its row). Unsaved-changes dialogs offer Save, Discard changes and Keep editing with focus on Keep editing; other dialogs focus the title or the safe action; at most one dialog is open (a second open is refused). Action toasts stay at least 10 s, pause on hover and while focus is inside, and are announced politely naming the undo route; error and rollback toasts use `role="alert"`, an error icon, never auto-dismiss, and every toast has a 28px "Dismiss notification" button. The toast stack is a region named "Notifications", top right of the content area, and inside the open sheet above its footer on mobile. The overlay sheet has a 45% scrim, a visible 44px close button and a trapped modal dialog. Banners are full width at the top of content, body-sm, with an icon and a word; the five variants of UX-DR-138 render (Reconnecting, Offline while editing, API changed, Live shape mismatch, dismissible small-screen with a 28px "Dismiss" that returns focus to a sensible element and never blocks the step). When offline, `msg:offline-editing` shows, editing continues, and controls using the offline helper become `aria-disabled` with that reason; `msg:reconnecting` then `msg:back-online` are each announced once. "Skip to content" is the first focusable element on every page, and "Go to notifications" appears while a toast shows. Under `prefers-reduced-motion` toasts do not slide and dialogs and sheets do not animate. Listbox options use `listbox-option-active` and `listbox-option-selected`, distinct from each other.

**Never:** Wiring the dashboard drawer, wizard or any screen beyond the layouts; the skip links "Back to dashboard" and "Skip to slot checklist"; Show-me and Undo business logic (toasts take action callbacks); a second toast library; stacking dialogs.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Destructive confirm | Open from a row action | alertdialog, focus on Cancel, Esc cancels, button names the object; focus back on the invoker or the row | N/A |
| Unsaved changes | Open with `unsaved-changes` | Save, Discard changes, Keep editing, focus on Keep editing | N/A |
| Second dialog | Open while one is open | Refused | Existing dialog stays |
| Action toast | Added with Undo | Stays at least 10 s, paused on hover and focus, announced politely | N/A |
| Error toast | Rollback or error | `role="alert"`, error icon, stays until dismissed | N/A |
| Mobile sheet | Open on a narrow layout | Scrim 45%, 44px close, focus trapped; toast above the footer inside it | N/A |
| Offline in a form | `offline` event | Banner `offline-editing`; Save, Fetch, Test, Publish `aria-disabled` with that reason | Back online restores them |
| Dashboard connection | Drop then return | `reconnecting` announced once, then `back-online` once | No repeats on flaps within a key |
| Dismissible banner | Activate Dismiss | Removed, focus moves to a sensible element | N/A |
| Skip link | First Tab on any page | "Skip to content" focuses `main` | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/lib/announce.ts` -- Story 1.8 minimal announcer; extend (and fix its first-use timing, deferred from Story 1.8)
- `resources/js/lib/flashToast.ts`, `components/ui/sonner/`, `resources/js/types/ui.ts` -- current `vue-sonner` path; replace with the new toast store and remove the `vue-sonner` dependency and the `ui/sonner` primitive
- `resources/js/components/ui/dialog/`, `ui/sheet/`, `ui/dropdown-menu/` -- shadcn primitives to restyle with tokens; add `alert-dialog` and `listbox` primitives as needed
- `resources/js/layouts/AppLayout.vue`, `AuthLayout.vue`, `components/AppContent.vue`, `pages/Welcome.vue` -- landmarks, skip link, toast region, banner slot
- `resources/js/composables/`, `resources/js/stores/` -- connection status, offline helper, dialog guard, toast store (Pinia is installed)
- `resources/js/components/` -- new `ConfirmDialog`, `UnsavedChangesDialog`, `ToastRegion`, `Banner` and its presets, `SkipLink`, `ListOption`
- `resources/js/locales/labels.ts`, `locales/en.ts` -- labels; catalogue messages `reconnecting`, `back-online`, `offline-editing`, `api-changed`, `map-small-screen`, `toast-*`, `unsaved-changes` exist
- `resources/css/app.css` -- `.cue-*` option classes and reduced-motion rules from Stories 1.6 and 1.8
- `tests/js/` -- DOM tests with happy-dom and `@vue/test-utils` (added in Story 1.8); a test-only gallery under `tests/js/fixtures/`

## Tasks & Acceptance

**Execution:**
- [x] `lib/announce.ts`, `composables/useConnection*`, `stores/toasts`, `lib/flashToast.ts`, `package.json` -- announcer policies, connection helper, toast store replacing `vue-sonner`
- [x] `components/ui/dialog`, `ui/sheet`, `ConfirmDialog`, `UnsavedChangesDialog` -- dialogs, one-deep guard, focus return
- [x] `ToastRegion`, `Banner` presets, `ListOption`, `SkipLink`, layouts, `Welcome.vue`, `app.css` -- notices, landmarks, skip link, reduced motion
- [x] `tests/js/*.test.ts` -- every matrix row in a DOM environment

**Acceptance Criteria:**
- Given the layouts render, when tests run, then each Story 1.9 criterion passes.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including audits after removing `vue-sonner`.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| `ConfirmDialog` also emits `cancel` when the destructive button is clicked | high | patch | `AlertDialogAction` closes through `update:open(false)`, which `onOpenChange` turns into `cancel` after `confirm()`; callers would log or undo on a confirmed delete. |
| Connection flaps repeat `reconnecting` and `back-online`; the flap test never changes state | medium | patch | Each message re-arms the other, so offline, online, offline, online speaks both twice, against the matrix row "No repeats on flaps". |
| Focus drops to `body` when a toast holding focus is dismissed or its action runs; `restoreFocus` has no last fallback | medium | patch | UX-DR-267: removal never drops focus to body; `Banner` already falls back to `#main-content`. |
| Touch tap leaves the toast held by `mouseenter` forever | medium | patch | `mouseleave` never fires on touch, so the tapped toast never auto-dismisses. |
| Toast action keyed by label; a throwing action leaves the toast on screen | low | patch | Duplicate keys break the render; `run()` is not wrapped. |
| `SkipLink` focuses the first `main` but links to `#main-content`; "Skip links" label hardcoded | low | patch | `querySelector('main')` can pick another landmark; the label bypasses `labels.ts`. |
| Sheet primitive does not host the toast region on mobile | medium | patch | Only a caller placing `placement="sheet"` gets toasts inside the sheet; the criterion needs it for the overlay sheet itself. |
| Flash-toast bridge and the real layouts (skip link, `#main-content`, "Notifications", announcer) untested | medium | patch | Tests build their own gallery fixture with its own Pinia; removing `SkipLink` or `ToastRegion` from a layout keeps tests green. |
| Reduced-motion rules verified by reading CSS text | low | defer | happy-dom does not evaluate media queries; needs a browser test. |
| Open modals hide the announcer regions | false | rejected | `aria-hidden`'s `hideOthers` keeps `[aria-live]` nodes (`node_modules/aria-hidden`), so the live regions stay available. |
| `vue-sonner` still in the lockfile | false | rejected | `grep` finds no match in `package.json` or `package-lock.json`. |
| Button order Discard, Keep editing, Save | false | rejected | UX-DR-76 puts the primary action last. |
| Toast cap and de-duplication, `warning` duration, region anchored to the viewport, dialog slot SSR and same-tick chaining, popover primitives and `ListOption` wiring, announcer same-tick queue, initial-offline announcement, `Banner` re-show | low | rejected | Not in the story criteria or would change the spec's "error toasts never dismiss" rule; fixes add machinery for unlikely cases. |

## Design Notes

Why replace `vue-sonner`: it cannot hold a toast while focus is inside, cannot sit inside a sheet or give the stack its own named region, so one small store and region cover the policy without a second library. Non-action success toasts (`saved`) stay at least 5 s (the spec gives no figure, and they carry no action).

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0
- `bin/tools npm run build` -- expected: exit 0
