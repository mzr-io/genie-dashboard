---
title: '5-8b Shared chart wrapper'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '1671f8d'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-5-8a-series-slot-model-and-engine.md', '{project-root}/_bmad-output/implementation-artifacts/spec-3-1-register-the-block-type-contract-v1-and-the-kpi-card-package.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** No chart exists in the product and no shared, safe way to draw one, so each chart Block Type would invent its own text handling, styles and keyboard model (Story 5.8; AD-21, UX-DR-101 to 109).

**Approach:** One chart wrapper under `resources/js/charts/`: a pure model (styles, legend, generated label, keyboard model, tooltip text, ECharts option), a `useChart` composable that mounts ECharts with the SVG renderer, and a `ChartPlot` component. The Renderer of a package hands it decimal strings and already-formatted text.

## Boundaries & Constraints

**Always:** Tooltip text is built from the library's `encodeHTML` on every piece; no formatter string, `v-html`, `innerHTML` or `eval` in the chart code (the Renderer lint now scans it). A number is a float only for position; every text is formatted by the caller from the decimal string. A gap is a gap, never 0. The plot is one `role="img"` tab stop with a generated label; the focused point is announced politely from a region outside the image; Esc leaves point mode with focus on the plot.

**Never:** No Block Type package (5.8c), no state faces (5.8c), no raw colour (the palette comes from the design tokens).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| One series | 3 points | 2px monotone line, no markers at rest, horizontal gridlines, compact ticks | N/A |
| Several series | 2 to 6 | own dash and marker each, marker at every point up to 24 else at the end, direct label | seventh not drawn |
| Area | kind area | gradient under series 1 only | N/A |
| Label | series, range, target | "Line chart, Revenue, Jan to Mar 2026. Lowest $10.2 in Jan, highest $40.1 in Mar, trend up. Target $35, Goal." | N/A |
| Keys | ←→ ↑↓ Home End Esc | one tab stop, point and series stepped, polite announcement, tip placed above | N/A |
| Hostile data | `<img onerror>` as a name | encoded text in the tooltip | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/charts/{model.ts,useChart.ts,ChartPlot.vue}`, `scripts/lint-renderers.mjs` (scans `resources/js/charts`), `tests/js/chart-model.test.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Model: styles, legend, label, keyboard, announcement, tooltip text, option
- [x] `useChart` and `ChartPlot` (SVG renderer, resize, disposal)
- [x] Renderer lint covers the shared chart code
- [x] Tests: vitest

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the component and model tests, and the real library draws an SVG plot.

## Implementation Notes

- Series beyond the sixth are not drawn (the fold into "Other" is the package's rule, 5.8c); the slot model has at most six anyway.
- Tooltip placement `top` with `confine` keeps the tip off the focused point; the Esc and blur paths hide it.
- The palette is read from `--df-chart-*` and `--df-focus-ring` at draw time, so a brand change is a redraw, and an unset token falls back to the text colour.
- The ECharts `grid.containLabel` needs the `LegacyGridContainLabel` feature in version 6; it is registered.

## Verification

- vitest, `vue-tsc`, `vp check`, colour and renderer lint.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Raw hex fallbacks in the wrapper | low | fixed | Colour lint failed; the palette falls back to `currentColor` and the surface token. |
| Block state faces, hotspots, settings | n/a | defer | Story 5.8c. |
