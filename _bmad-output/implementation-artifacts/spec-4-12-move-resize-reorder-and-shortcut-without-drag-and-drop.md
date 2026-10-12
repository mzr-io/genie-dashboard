---
title: '4-12 Move, resize, reorder and shortcut without drag-and-drop'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '13643a1'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Every layout change needs a pointer, and a phone has no free layout to edit at all.

**Approach:** Give every drag action a keyboard and a menu path with the same Layout-kernel rules, announce moves, reorder a single column through a stored `compact_order` that never touches the desktop positions, and route single-key shortcuts through the registry that owns the Keyboard shortcuts setting.

## Boundaries & Constraints

**Always:** Keyboard and menu steps use the same settling as drag. One Esc never cancels and exits. Single-key shortcuts obey the setting; buttons and menu items never depend on it. A reorder never changes saved desktop positions. New items are appended to `compact_order` when one exists.

**Never:** No free resize on a single column, no drag from the panel, no server-side shortcut state.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Pick up | M or Space on "Move {Block}" | Block lifted, announced | N/A |
| Arrows | picked up | one column / Small row; Shift resizes within limits | N/A |
| Drop / cancel | Enter / Esc | committed, announced against neighbours / restored | N/A |
| Menu step | Move up/down, wider/narrower | one step, saved at once outside the mode | wider disabled with reason at the maximum |
| Esc Esc | nothing picked up | first only arms, second exits and saves | N/A |
| Single column | menu | reorder only, `SetCompactOrder` | stale revision reloads |
| Shortcuts off | `/`, A, M | nothing; buttons and menu still work | N/A |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_27_100000_add_compact_order_to_dashboards.php`, `app/Modules/Dashboards/**` (`setCompactOrder`), `DashboardController::compactOrder`, `routes/api.php`
- `resources/js/lib/layout.ts` (`step`, `resizeStep`, `describeMove`), `components/{BlockCard,DashboardGrid}.vue`, `pages/Dashboard.vue`, `lib/dashboards.ts` (`saveCompactOrder`), `lib/shortcuts.ts` (registry)

## Tasks & Acceptance

**Execution:**
- [x] `compact_order` column, command, endpoint; appended on add and restore, dropped on remove
- [x] Move button, keyboard pickup, announcements, Esc semantics
- [x] Menu steps in and outside the mode; single-column reorder
- [x] Shortcut registrations for `/`, A and M
- [x] Tests: unit (steps, describeMove), Database (order storage, validation, append and drop), vitest (keyboard path, menu, Esc, narrow reorder, shortcuts)

**Acceptance Criteria:**
- Given the I/O matrix, when the layout is changed without a pointer, then each row holds against PostgreSQL and in the component tests.

## Implementation Notes

- One arrow press is one column or one Small row (two 60 px units); a menu step is the same.
- The Keyboard shortcuts switch is the registry's existing one (Story 1.18): `A`, `/` and `M` are registered like any other single-key shortcut, and `M` is scoped to a focused Move button.
- On narrow screens the Edit layout control is not shown and "free resize" is not offered; the ⋯ menu's Move up and Move down are the reorder.
- A menu step outside the mode is one `MoveResizeItems` with the changed items, so a stale revision reloads the layout and says `msg:layout-save-failed`.

## Verification

- SQLite suite, Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check` pass.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Pickup announcement is polite, not assertive | false | none | The story asks for a polite live region. |
| Reorder swaps neighbours by one place only | low | none | That is what Move up and Move down mean; drag reorder on a column is outside this story. |
