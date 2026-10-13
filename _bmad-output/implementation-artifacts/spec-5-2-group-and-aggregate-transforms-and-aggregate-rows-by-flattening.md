---
title: '5-2 Group and aggregate transforms and "Aggregate rows by..." flattening'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '5ae91c3'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** An Admin can keep, order and cut rows (Story 5.1) but cannot summarise them, such as revenue by region.

**Approach:** Add Group and Aggregate steps (SUM, COUNT, AVG, MIN, MAX) to the Draft's transforms, run as the `group` and `aggregate` stages between filter and sort with exact decimal arithmetic and a group-count guard, and show them in the Transforms section with the row counts.

## Boundaries & Constraints

**Always:** The stage order is filter, group, aggregate, sort, top-N. Sums are exact (BcMath at the one decimal scale) and leave as decimal strings. A missing or mistyped field makes the slots that read the rows unavailable, never 0. A guard aborts and never truncates.

**Never:** No float arithmetic. No script or expression. A field is never both grouped and aggregated, and a Sort after a group may only use a field that is left.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Group, sum, sort, top 5 | region, SUM revenue, desc, 5 | grouped, summed, ordered, cut; counts "36 → 12 rows" | N/A |
| SUM of a text field | aggregate sum on text | "Can't SUM a text field", Continue blocked | slots unavailable at run time |
| 17-digit measures | 12345678901234567.89 | exact decimal string | N/A |
| More groups than the limit | `max_groups` passed | guard error, nothing truncated | N/A |
| SUM before the decimal scale is set | scale unset | refused naming `DASHFLOW_DECIMAL_SCALE` | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Mapping/Contracts/{Transforms,QueryPlan,PlanGuards,GuardExceeded}.php`, `Application/AutoMapper.php` (faults), `Infrastructure/{InProcessExecutor,PreviewSettings}.php` (table of columns, group, aggregate), `app/Providers/AppServiceProvider.php`, `config/dashflow.php`, `.env.example` (`DASHFLOW_PREVIEW_MAX_GROUPS`).
- `app/Modules/Blocks/Application/DraftStrictCheck.php`, `Contracts/DraftTransforms.php`.
- `resources/js/lib/blockTransforms.ts`, `resources/js/components/wizard/{TransformsSection,MapDataStep}.vue`, `resources/js/locales/labels.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Group and aggregate stages, exact arithmetic, group guard, faults
- [x] Group and Aggregate editors, sentences, counts and the "Can't SUM a text field" error
- [x] Tests: Unit (engine, transforms, strict check), Database (endpoint), vitest (section)
- [ ] The "Aggregate rows by..." flatten control and per-row chart slot rules: deferred (see below)

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit, database and component tests.

## Implementation Notes

- The executor now works on a table of columns by stored path. After a group or aggregate only the grouped and aggregated fields are columns; a slot whose alias is not one is unavailable. Without a group, an aggregate is over the whole table (one row).
- AVG is the exact sum divided at the platform scale (truncated by BcMath, as every platform division is). SUM and AVG are refused while `DASHFLOW_DECIMAL_SCALE` is unset; results are written without the zeros the scale padded.
- `max_groups` is optional: unset, the extracted-values guard bounds the groups.
- The KPI card's slots take one value and the mapper only offers fields outside the rows, so a KPI slot cannot yet read an aggregated field; the chart Block Types (5.8 onwards) read the columns.
- Deferred, with the reason in `deferred-work.md`: the flatten "Aggregate rows by..." control above the sample table, the Continue block for a flattened array used by a per-row chart slot with no aggregation, and a chart slot's declared default aggregation. They need Block Types that do not exist yet.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour lint.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Flatten control and per-row chart slot rules need chart Block Types | medium | defer | Two entries in `deferred-work.md`; the steps they write exist. |
| `QueryExecutor` binding built `InProcessExecutor` without the arithmetic | medium | fixed | The provider passes `DecimalMath` explicitly. |
| `max_groups` as a new required setting would refuse every preview | low | fixed | It is optional and falls back to the extracted-values guard. |
