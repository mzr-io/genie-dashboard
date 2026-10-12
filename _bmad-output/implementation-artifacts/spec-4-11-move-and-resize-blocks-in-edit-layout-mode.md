---
title: '4-11 Move and resize Blocks in Edit Layout Mode'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '205911f'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A dashboard's arrangement cannot be changed once Blocks are on it.

**Approach:** Add Edit Layout Mode: pointer drag and corner resize on a working copy with its own Undo and Redo, settled by the same rules in the browser and the server, saved by one `MoveResizeItems` command with the revision held when the person presses Done.

## Boundaries & Constraints

**Always:** Drag and resize exist only in the mode. Widths snap to the presets and heights to Small, Medium, Large within the Block's limits. Overlapped Blocks are pushed down and nothing compacts upward. The command never creates or deletes an item. A failed save keeps the mode and the arrangement. A stale revision offers a reload, never an overwrite.

**Never:** No keyboard pickup, menu moves or reorder (4.12), no `compact_order` (4.12), no drag from the panel, no new grid dependency.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Enter | "✎ Edit layout" | Undo, Redo, Done, hint, handles | N/A |
| Drag | header | preview with neighbours pushed down | N/A |
| Resize | corner, allowed | snaps to presets in limits | none shown when not allowed |
| Done | changes | one save with revision, focus on Edit layout | failure: mode stays, `msg:layout-save-failed` |
| Stale | old revision | 409, reload offered | no overwrite |
| Bad payload | unknown or repeated item | 422, nothing changes | N/A |
| Undo / Redo | buttons, Ctrl+Z, Ctrl+Shift+Z | each gesture reversed or re-applied | N/A |

</frozen-after-approval>

## Code Map

- `app/Platform/Layout/Grid.php` (`resolve`), `app/Modules/Dashboards/**` (`moveResizeItems`, `InvalidLayout`), `DashboardController::layout`, `routes/api.php`
- `resources/js/lib/layout.ts`, `components/{DashboardGrid,BlockCard}.vue`, `pages/Dashboard.vue`, `lib/dashboards.ts` (`saveLayout`); `tests/Fixtures/layout-resolve.json`

## Tasks & Acceptance

**Execution:**
- [x] `Grid::resolve` and the shared fixture, run by Pest and Vitest
- [x] `MoveResizeItems` with CAS, clamp, settle, event; endpoint and error code
- [x] Pointer drag and resize, working copy, history, toolbar, save, failure and conflict states
- [x] Tests: unit (settling cases), Database (move, push, clamp, refusals), vitest (toolbar, drag, resize, history, failures)

**Acceptance Criteria:**
- Given the I/O matrix, when the layout is edited and saved, then each row holds against PostgreSQL and in the component tests.

## Implementation Notes

- The story names `grid-layout-plus` or `gridstack`; neither is a dependency and installing one was not warranted for fixed 12-column CSS-grid math, so the pointer logic is a few functions in the grid component and `lib/layout.ts`.
- The client and server run one fixture of settling cases, so a preview and a saved layout cannot drift apart.
- Only changed items are sent; items pushed by the client's settling are among them, and the server settles again and returns the layout it kept.
- Narrow-width reflow never writes positions back: below `md` the grid is one column from the saved order and the handles are not drawn.
- The layout response now carries each Block's limits (`block.sizes`) so the browser clamps without a second source of truth.

## Verification

- SQLite suite, Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check` pass.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Edit layout is disabled for an empty dashboard | low | none | There is nothing to arrange; the control says so with `aria-disabled`. |
| Pointer drag has no autoscroll near the viewport edge | low | defer | Layouts are short; the keyboard and menu paths (4.12) cover long ones. |
