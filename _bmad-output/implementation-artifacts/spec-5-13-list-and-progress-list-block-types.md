---
title: '5-13 List and Progress list Block Types'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: 'b144866'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-5-12-table-block-type-with-sort-pagination-and-stable-row-keys.md', '{project-root}/_bmad-output/implementation-artifacts/spec-5-4-text-templates-tokens-and-footer-action-target.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** There is no way to show ranked items, people or task and goal completion as a list (Story 5.13; FR-26, FR-28, FR-29, FR-32, UX-DR-92 to 94).

**Approach:** Add the packages `block-types/list/v1/` (item title, subtitle, avatar name, icon, right value, right meta, status, row key) and `block-types/progress-list/v1/` (item title, progress value, total, caption, row key; colour rule on threshold bands), both on the shared Block frame, and let a Text Template of a `rows` slot be written once for every row.

## Boundaries & Constraints

**Always:** An avatar's initials and one of five tints come from the name alone, so a person has one tint in every Block. A percent is value over total in decimal, exact to two places (value itself with no total); the bar is `aria-hidden` and held to 100% while the text keeps the true percent; a total of 0 or a missing value reads "—" and the Block is partial. A screen reader hears "Website redesign, 12 of 16 tasks, 75%". Grouped rows give the percent of the summed numerators and denominators. Items keep stable keys. Every text is literal.

**Never:** No float for a text a reader sees; no per-package code in the mapper; a template never turns data into markup or a token.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Row template | `{done}/{total} tasks` | "12/16 tasks" per row | N/A |
| Percent | 12 of 16 | "75%" over a 6px track | N/A |
| Band | 25% with `<= 30` At risk | icon and word beside the percent, band fill | N/A |
| Over 100 | 130 of 100 | text 130%, bar full | N/A |
| Total 0 | 3 of 0 | "—", partial | N/A |
| Grouped | (1/2) + (3/14) | 25% | N/A |
| Avatar | "Ada Lovelace" | "AL", the same tint everywhere | N/A |
| No rows | none | Empty | N/A |

</frozen-after-approval>

## Code Map

- `block-types/list/v1/*`, `block-types/progress-list/v1/*`, `composer.json` (`ItemList`, `ProgressList` namespaces), `block-types/pins.json`, `PackageShapers.php`, generated `ListSlots.php`, `ProgressListSlots.php` and `block-types.ts`.
- `InProcessExecutor.php` (a `rows` slot's template per row), `AutoMapper.php` (a template field takes its slot's cardinality), `DecimalValue::percent`, `PresentationOptions.php` (percents, row bands, list formats).
- `resources/js/lib/{blockRenderers,blockWizard,textTemplate}.ts`, `components/{BlockThumbnail.vue,wizard/PresentationEditor.vue,wizard/LivePreview.vue}`.
- `tests/Unit/ListProgressTest.php`, `tests/js/list-progress.test.ts`.

## Tasks & Acceptance

**Execution:**
- [x] List and progress-list packages: schema, Shaper, payload, table, Renderer
- [x] Per-row Text Templates in the executor and the mapper; exact percent; bands on percents
- [x] Registration: autoload, pins, Shapers, Renderers, tables, wizard list, thumbnails, preview regions
- [x] Format on any-typed slots and the colour rule on the progress row in the presentation editor
- [x] Tests: Unit, vitest, registry counts

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit and component tests.

## Verification

Pest Unit, Feature, Architecture and Database suites, vitest, `vp check`, `vue-tsc`, colour and renderer lints, block-type pins and generator checks, PHPStan and Pint.

## Implementation Notes

- The package namespace is `ItemList`, not `List`, which is a PHP keyword.
- The editor's Format section now shows for `any` slots, which also gives a Table column its format (Story 5.12 left it hidden).
- A list's status is a plain label; the colour rule belongs to the progress list. A list-level search or filter does not exist yet, so `msg:list-no-match` has no control to serve; an empty list shows the Block frame's empty state.
