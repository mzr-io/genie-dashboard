---
title: '5-11b Bar and Pie/Donut Block Types'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '84ffefd'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-5-11a-top-n-groups-the-rest-as-other.md', '{project-root}/_bmad-output/implementation-artifacts/spec-5-8b-shared-chart-wrapper.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** There is no way to compare categories or show shares: the only charts are line and area (Story 5.11; FR-24, FR-32, UX-DR-109 to 111).

**Approach:** Add the packages `block-types/bar/v1/` (Category, Values 1 to 6, optional series key; grouped or stacked, vertical or horizontal, value labels, data or value order) and `block-types/pie-donut/v1/` (Category, Value, centre label; pie or donut, centre value), both on the shared chart wrapper extended with bar and pie plots, fill patterns and the chart Block faces. The Top-N "Other" fold of 5.11a makes the ratio-safe rest.

## Boundaries & Constraints

**Always:** Series 3 to 6 and slices from the third use fill patterns, and the legend names them, so nothing is told apart by colour alone. A stack's total is exact and sits above it. Negative bars go below the zero baseline; an all-zero bar chart keeps its categories and legend. A pie draws at most six slices (the five largest and "Other"), largest first clockwise from 12 o'clock, with the shares by exact arithmetic. The plot's label names the categories and the largest value or slices; each step is announced; Esc leaves point mode. "View as table" is offered for both.

**Never:** No float for a text a reader sees; no per-package code in the mapper; no ECharts string formatter.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Grouped | two series | 30% category gap, 2px bar gap, xs top corners, 1px stroke | N/A |
| Stacked | two series | 1px surface divider, exact total above | N/A |
| Long data | category, series key, value | one series per key | N/A |
| Seven slices | top 5 + rest | five and "Other" (sum), largest first | N/A |
| Donut | centre total | exact total, label | N/A |
| Nothing positive | all 0 | Empty | N/A |

</frozen-after-approval>

## Code Map

- `block-types/bar/v1/*`, `block-types/pie-donut/v1/*`, `composer.json`, `block-types/pins.json`, `PackageShapers.php`, generated `BarSlots.php`, `PieDonutSlots.php` and `block-types.ts`.
- `resources/js/charts/{model.ts,useChart.ts,ChartPlot.vue,ChartBlockFrame.vue}`, `resources/js/lib/{blockRenderers,blockWizard,blockPresentation}.ts`, `components/{BlockThumbnail.vue,wizard/PresentationEditor.vue,wizard/LivePreview.vue}`, `app/Modules/Mapping/Contracts/Presentation.php`, `Application/PresentationOptions.php`.
- `tests/Unit/BarPieShaperTest.php`, `tests/js/bar-pie.test.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Bar and pie-donut packages: schema, Shaper, payload, table, Renderer
- [x] Bar and pie plots, patterns, legend, label, announcement, tooltip in the shared model
- [x] Chart settings in the presentation rules and on the category rows
- [x] Registration: autoload, pins, Shapers, Renderers, tables, step 1, thumbnails, preview regions
- [x] Tests: Unit, vitest

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit and component tests.

## Implementation Notes

- A pie's slot keys are `pie_category` and `pie_value` (labelled Category and Value) so the editor can tell the pie's category row from the bar's `category` row.
- The Shaper folds a plain sum into "Other"; a ratio is folded by the Top-N "Other" step, which makes it from the sums.
- Fill patterns are ECharts decals (the `AriaComponent` registers their visual).
- Slice labels are not drawn on the plot: the legend lists name, share and value, which is where collisions go.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour and renderer lint, `block-types:check`, block-type pins.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Closures called as variable functions broke the Shaper purity rule | low | fixed | Private methods; the architecture test passes. |
| Per-point keyboard in a bar is the same model as a line | n/a | accept | `reduceKey` serves every plot. |
