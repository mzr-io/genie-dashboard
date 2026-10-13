---
title: '5-11a Top-N groups the rest as Other'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '01804ac'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-5-1-filter-sort-and-top-n-transforms-in-the-map-data-step.md', '{project-root}/_bmad-output/implementation-artifacts/spec-5-3-calculated-fields-and-ratio-safe-aggregation.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A Top-N cuts the groups beyond its count away, so a pie or a bar chart of the top categories loses the rest of the total, and a ratio of the rest cannot be made from the parts (Story 5.11; FR-24, FR-32).

**Approach:** A Top-N step can group the rest as "Other": the groups it leaves out become one row whose aggregates are made again over all their source rows and whose ratios are the ratio of the sums, never an average of ratios.

## Boundaries & Constraints

**Always:** Only with a Group step. The Other row is not in the rows behind the Block (those are before top-N). A Sort may use a calculated field, so "by value" works for a ratio. Nothing outside the count means no Other row.

**Never:** No average of averages or of ratios; no Other row when the stage is broken.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Sum | A 100, B 50, C 35, D 20 and Top 2 | A, B, Other 55 | N/A |
| Ratio | C 600/1000, D 5/10 left out | Other 605/1010 = 59.9% | N/A |
| Count not exceeded | 4 groups, Top 10 | unchanged | N/A |
| No group | Top-N with Other and no Group | refused, `config.transforms.N.other` | 422 |

</frozen-after-approval>

## Code Map

- `app/Modules/Mapping/Contracts/Transforms.php` (`other`, `other_label`, cross-check, calculated fields as sortable), `app/Modules/Mapping/Infrastructure/InProcessExecutor.php` (`otherRow`, sort positions, calc sort fields).
- `resources/js/lib/blockTransforms.ts`, `components/wizard/TransformsSection.vue`, `locales/labels.ts`.

## Tasks & Acceptance

**Execution:**
- [x] `other` and `other_label` on the Top-N step, judged and applied to the plan
- [x] The Other row from the union of the left-out groups' rows
- [x] A Sort by a calculated field passes plan validation and runs
- [x] Editor: checkbox and label on the Top-N row
- [x] Tests: Unit, vitest

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit tests.

## Implementation Notes

- Defect fixed: sorting by a calculated (ratio) field was refused at plan validation and, past it, read as a document path. Both are fixed; a Sort "by value" of a ratio now works.
- The Other row is made by `otherRow()` from the groups' member rows, so the aggregate functions and the ratio come out as if the left-out groups had been one.

## Verification

- Pest (Unit, Architecture, Feature), vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`; Database suite with Story 5.11.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Sort by a ratio failed validation and execution | high | fixed | Unit test sorts by `calc:rate`. |
