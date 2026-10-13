---
title: '5-10 KPI & Chart Block Type and the normative revenue example'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: 'cd44ac4'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-5-8c-line-area-block-type.md', '{project-root}/_bmad-output/implementation-artifacts/spec-5-3-calculated-fields-and-ratio-safe-aggregation.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** There is no Block that combines a headline, its comparison and a chart, so the "Revenue overview" mockup (FR-31) cannot be built or verified end to end (Story 5.10; FR-25, FR-27, FR-29, FR-32, AD-33).

**Approach:** Add the package `block-types/kpi-chart/v1/`: the KPI card's slots and the chart's X and series slots, a Shaper that composes the pinned kpi-card and line-area Shapers, and a Renderer that draws the card's Renderer and the chart's side by side or stacked by container width. Verify the FR-31 fixture against a golden payload shared by the engine test and the Renderer test.

## Boundaries & Constraints

**Always:** The KPI part is the payload's top level, the chart is `chart` with its own state: the headline renders over an empty or unavailable chart and no zero is drawn. A calculated field of single values beside the rows (`PCT_CHANGE(total, previous_total)` next to a list of months) is one value; a field it reads that the response lacks leaves it unavailable and breaks nothing else. Rows that are there and empty are an empty chart, not a missing field. The month axis is written by the X slot's pattern (`MMM`).

**Never:** No per-package branches in the mapper or the engine; no duplicate headline element; nothing hidden when the Block narrows.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| FR-31 fixture | total 284680, previous 249195, two months | "$284,680", "↗ 14.2% from last year" in green, Jan and Feb with compact currency | N/A |
| Hidden text | same | "Up 14.2% from last year, favourable", the arrow `aria-hidden`, one headline element | N/A |
| `previous_total` removed | same | comparison unavailable, headline and chart render, no zero | N/A |
| `monthly: []` | same | headline renders, chart says "No data for this period.", no point drawn | N/A |
| Narrow Block | below the container width | one column, every part kept | N/A |

</frozen-after-approval>

## Code Map

- `block-types/kpi-chart/v1/{schema.json,Shaper.php,payload.ts,Renderer.vue}`, `composer.json`, `block-types/pins.json`, `PackageShapers.php`, generated `KpiChartSlots.php` and `block-types.ts`.
- `app/Modules/Mapping/Infrastructure/InProcessExecutor.php` (scalar calculated fields, absent reads, empty lists), `Application/PresentationOptions.php` (`seriesFormat`, `xPattern`), `Contracts/Presentation.php` (month date patterns).
- `resources/js/charts/axis.ts`, `block-types/line-area/v1/{Shaper.php,Renderer.vue,table.ts}`, `resources/js/lib/{blockRenderers,blockWizard,blockPresentation}.ts`, `components/{BlockThumbnail.vue,wizard/LivePreview.vue}`.
- `tests/Fixtures/revenue-overview/{document.json,golden-payload.json}`, `tests/Unit/RevenueOverviewTest.php`, `tests/js/kpi-chart-renderer.test.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Package: schema, composing Shaper, payload, Renderer
- [x] Registration: autoload, pin, Shaper and Renderer maps, generated types, step 1, thumbnail, preview regions, table builder
- [x] Engine: scalar calculated fields, absent reads, empty lists
- [x] Month axis patterns, separate series format
- [x] Golden FR-31 test in PHP and the Renderer test from the same file

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit and component tests.

## Implementation Notes

- The golden payload is what the engine and the Shaper make of the fixture with the story's mapping; preview and compute call the same `QueryPlans::run`, so they cannot differ (the engine test is the proof).
- Static header text is a Text Template with no token, as in Story 5.4.
- Defect fixed on the way: the comparison label and the "· better" word ran into the value ("14.2%from last year") because a leading space inside a template element is dropped by the compiler; the space is now part of the text.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour and renderer lint, `block-types:check`, block-type pins.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Comparison label ran into the delta value | medium | fixed | The Renderer test reads "↗ 14.2% from last year". |
| A missing field in a calculated field broke every column | high | fixed | The absent read leaves only that field unavailable; engine tests cover the fixture. |
| Chart `Other` fold, series beyond six | n/a | accept | Six series slots exist. |
