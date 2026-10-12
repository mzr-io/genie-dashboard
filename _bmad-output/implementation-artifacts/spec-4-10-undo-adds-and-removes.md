---
title: '4-10 Undo adds and removes'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '4140c70'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A wrong add or remove costs the person their placement, and only the add toast can reverse anything.

**Approach:** Add the `RestoreItem` command (remembered geometry and minimized state, Layout-kernel placement) and a per-page history of adds and removes, reversed by the toast's Undo or by Ctrl+Z (⌘Z) outside text fields, with one retry on a stale revision.

## Boundaries & Constraints

**Always:** A reversal is never a new history entry. A remembered spot is used only when free; no other item moves. A text field keeps its own undo. Ctrl+Z needs no single-key shortcut setting.

**Never:** No server-side history, no time limit, no period-selection storage before 4.15, no undo of moves (4.11, 4.12).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Undo add | toast or Ctrl+Z | RemoveItem, entry gone | N/A |
| Undo remove | toast or Ctrl+Z | RestoreItem at the remembered spot, focus on its title | N/A |
| Spot taken | remembered spot occupied | first free spot at that size | N/A |
| Repeated Ctrl+Z | history of several | walks back one entry each press | N/A |
| In a text field | focus in input | ignored | N/A |
| Stale revision | 409 | layout reloaded, retried once | second failure: rollback toast |
| Block gone | unpublished or access lost | entry marked unavailable | `msg:toast-rollback` |

</frozen-after-approval>

## Code Map

- `app/Modules/Dashboards/**` (`restoreItem`, shared `place`), `app/Platform/Layout/Grid.php` (`fits`), `DashboardController::restoreBlock`, `routes/api.php`
- `resources/js/pages/Dashboard.vue` (history, `restoreEntry`, `undoEntry`, `onUndoKey`), `lib/dashboards.ts` (`restoreBlock`)

## Tasks & Acceptance

**Execution:**
- [x] `RestoreItem` sharing the add placement path, `Grid::fits`
- [x] History, toast Undo for both, Ctrl+Z, focus, retry once, unavailable entries
- [x] Tests: unit (fits), Database (remembered spot, taken spot, clamp, refusals, idempotence), vitest (restore body, history walk, text field, 409 retry)

**Acceptance Criteria:**
- Given the I/O matrix, when changes are undone, then each row holds against PostgreSQL and in the component tests.

## Implementation Notes

- AddItem and RestoreItem share one `place()`: they differ only in the remembered geometry, the minimized flag and the event's `command`.
- The history lives in the page, not the server (the story says it lasts while the dashboard is open); a reload starts it empty.
- The remove toast now carries Undo (`msg:toast-remove`); an undone add uses the quieter "removed" toast without a second Undo.

## Verification

- SQLite suite, Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check` pass.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| History is lost on reload | low | none | Required by the story: it lasts while the dashboard is open. |
| An entry's remembered geometry can be stale after another device moved things | false | none | The spot is used only when free at restore time, else the first free one. |
