---
title: '4-17 Use the dashboard on any screen size'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: 'c23ca49'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The dashboard changes layout at a fixed screen width, so a narrow content area (an open Add-blocks Panel, a tablet) is not served, and a phone hides the switcher, Date Range and live-update controls.

**Approach:** Make the grid follow the width of its own content area with three modes, show the header controls on every width with 44px targets on a phone, and keep the Add-blocks Panel's phone behaviour (always-visible Remove, no free-space hint).

## Boundaries & Constraints

**Always:** Positions are drawn, never rewritten, by a reflow. A single column uses the member's own order. The Add-blocks Panel still places in the first free slot.

**Never:** No saved position changes because of a width. No hover-only affordance on a phone.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Content 1100px or wider | any | saved positions, Edit layout available | N/A |
| Content 720 to 1099px | any | 12 columns, 3-column Blocks four across, wider ones six, reading order, Edit layout hidden | N/A |
| Content under 720px | any | one column in the member's order, menu reorders | N/A |
| Phone | header | switcher, Date Range, Pause shown, 44px targets, no free-space hint | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/components/DashboardGrid.vue`: measures itself (`ResizeObserver`), `data-mode`, `--span`, emits `mode`.
- `resources/js/pages/Dashboard.vue`: `gridMode`, `singleColumn`, `canEditLayout`, header controls on every width, no hint on a phone.
- `resources/js/components/AddBlocksPanel.vue`: 44px targets on a phone. Sheet Close is already 44px.
- Existing and unchanged: shell breakpoints (`AppShell.vue`, Story 1.16), top bar as the only sticky layer with `scroll-padding` (`resources/css/app.css`), the panel as a sheet below 1024px with Done.

## Tasks & Acceptance

**Execution:**
- [x] Grid modes from the content width
- [x] Header controls and 44px targets on a phone, no hint
- [x] Tests: vitest (tablet mode, single column, header controls)

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the component tests.

## Implementation Notes

- Until the grid has been laid out (and in a test DOM) it draws the saved positions.
- The cross-browser smoke suite (latest two Chrome, Edge, Firefox and Safari, desktop and mobile) needs a browser matrix this environment does not have and was **not run**.

## Verification

- vitest, `vue-tsc`, `vp check`, colour lint.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Cross-browser smoke suite not run | medium | accept | No browser matrix here; to be run in CI or by hand. |
| Edit layout hidden on a tablet content width | low | accept | The reflow is display only; menus still move and resize at full width. |
