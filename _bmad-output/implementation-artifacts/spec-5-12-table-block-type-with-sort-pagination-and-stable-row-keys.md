---
title: '5-12 Table Block Type with sort, pagination and stable row keys'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '5de49ae'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-5-11b-bar-and-pie-donut-block-types.md', '{project-root}/_bmad-output/implementation-artifacts/spec-5-9-view-as-table-expanded-view-and-the-rowset-endpoint.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A dashboard cannot show structured records a reader can scan, sort and page (Story 5.12; FR-29, FR-30, FR-32, AR-28, AD-21, UX-DR-44, UX-DR-100).

**Approach:** Add the package `block-types/table/v1/` (up to 12 columns of any value type, optional row key; per column header, format, alignment, width, sortable; page size; a row highlight rule on threshold bands) with a Renderer that is a real `table` on the shared chart Block frame.

## Boundaries & Constraints

**Always:** A real `table` with a visually hidden caption equal to the title, `th scope="col"`, numeric cells right-aligned. A sort cycles ascending, descending, data order, is announced, is the session's only, and is stable. Pages show "1–10 of 48", Previous/Next `aria-disabled` at the ends; a change of sort or period starts at page 1; a vanished page falls back to the last, announced. Rows are keyed by the mapped key, else a hash of the cells made unique by occurrence, never by position. A missing cell is "—" with the partial-data badge; a missing column is unavailable, never zero. The band's icon and word lead the row's first cell.

**Never:** No float for a text a reader sees; no per-package code in the mapper; no formatting in the Shaper.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Page | 48 rows, size 10 | "1–10 of 48" | N/A |
| Sort | header button x3 | asc, desc, data order | N/A |
| Refresh | rows shrink under page | last page, announced | N/A |
| Duplicates | identical rows | keys unique, order kept | N/A |
| Gap | null cell | "—", partial | N/A |
| No column | nothing mapped | Unavailable | N/A |

</frozen-after-approval>

## Code Map

- `block-types/table/v1/*`, `composer.json`, `block-types/pins.json`, `PackageShapers.php`, generated `TableSlots.php` and `block-types.ts` (the generator wraps long role lists as the formatter does).
- Value type `any` and up to 12 repeats in `BlockTypeVocabulary.php`, `AutoMapper.php`, `InProcessExecutor.php`.
- Column rules, page size and highlight column in `Presentation.php`, `PresentationOptions.php`; period into the options in `QueryPlanEngine.php`.
- `resources/js/charts/ChartBlockFrame.vue` (custom body), `lib/{blockRenderers,blockWizard,blockPresentation}.ts`, `components/{BlockThumbnail.vue,wizard/PresentationEditor.vue,wizard/LivePreview.vue}`, `locales/labels.ts`.
- `tests/Unit/TableShaperTest.php`, `tests/js/table-block.test.ts`, `tests/Unit/PresentationTest.php`.

## Tasks & Acceptance

**Execution:**
- [x] Table package: schema, Shaper, payload, table, Renderer
- [x] `any` value type, repeat up to 12, column rules, page size, highlight column, period option
- [x] Registration: autoload, pins, Shapers, Renderers, tables, wizard list, thumbnail, preview regions
- [x] Column editor, page size and highlight column in the presentation editor
- [x] Tests: Unit, vitest, registry counts

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit and component tests.

## Verification

Pest Unit, Feature, Architecture and Database suites, vitest, `vp check`, `vue-tsc`, colour and renderer lints, block-type pins and generator checks, PHPStan and Pint all pass.

## Implementation Notes

- The pie-donut package is not in the wizard's type list (a Story 5.11 gap, left unchanged here).
