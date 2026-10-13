---
title: '5-3 Calculated fields and ratio-safe aggregation'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '9b69aad'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** An Admin cannot derive a number (a margin, a change, a completion rate) from the fields of a response, and a ratio averaged across groups is wrong.

**Approach:** Add Calculated field steps in a closed expression language (arithmetic, comparisons, `IF`, `ROUND`, `ABS`, `PCT_CHANGE`, date parts), run at the row-calc stage (before group) or the aggregate-calc stage (after aggregate), with a Ratio kind that sums its numerator and denominator before dividing, and let a number slot read one through the Field picker's "Calculated Field".

## Boundaries & Constraints

**Always:** One grammar and one evaluator, decimal arithmetic at the platform scale, tree stored beside the source text. A division by 0 or null, and PCT_CHANGE with a previous value of 0 or null, is null, never 0 or an error. A missing or mistyped field makes the slot unavailable.

**Never:** No eval, no user function, no loop, no call outside the closed list. A client never sends the tree. A ratio is never averaged.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Row-level | `(revenue - cost) / revenue` | one value per row, before the group | syntax error: 422 naming the position |
| Aggregate-level | after SUM | evaluated on the grouped rows | N/A |
| Zero divisor | `a / 0`, previous 0 | null (shown as the empty display) | N/A |
| PCT_CHANGE(284680, 249195) | exact decimals | 14.239852, 14.2 at one decimal | N/A |
| Ratio 600/1000 and 450/500 | grouped | 70, not 75 | missing part or non-number: refused or a fault |
| Unknown function or field | `SUM(a)`, `ghost` | refused, position named, Continue blocked | N/A |
| Picker | number slot | "Calculated Field" with the example, focus in the expression, Esc returns | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Mapping/Application/Expressions/{ExpressionParser,ExpressionResolver,ExpressionEvaluator,ExpressionNumber,ExpressionTypeError}.php`, `Contracts/InvalidExpression.php`.
- `app/Modules/Mapping/Contracts/{Transforms,QueryPlan,AutoMapping,AutoMapResult}.php`, `Application/AutoMapper.php` (resolve, calculated fields as fields, faults, picker entry), `Infrastructure/InProcessExecutor.php` (row calc, aggregate calc, ratio).
- `app/Modules/Blocks/Application/{ManageDraftSlots,DraftStrictCheck}.php`.
- `resources/js/lib/blockTransforms.ts`, `resources/js/components/wizard/{TransformsSection,MapDataStep,SlotRow,SlotChecklist}.vue`, `resources/js/locales/labels.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Grammar, parser with positions, resolver, evaluator
- [x] Row calc and aggregate calc stages, ratio aggregation, the `calc:` column
- [x] Step shape, resolution against the sample, faults and the picker entry
- [x] Editor row, Calculated Field from the picker, refusals with position
- [x] Tests: Unit (expressions, engine), Database (endpoint, mapping), vitest (section, page)

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit, database and component tests.

## Implementation Notes

- A calculated field is read by the stored path `calc:<name>`; its columns come from the stage, never from the document. A slot may be pointed at one by hand (never by auto-map). It is offered as a single value when it is aggregated with no group, or made only of single fields.
- Order is the fixed AD-11 order: filter, row calc, group, aggregate, aggregate calc, sort, top-N.
- `PCT_CHANGE` multiplies by 100 before dividing, so the platform scale applies to the percentage (the renderer's percent format reads percentage numbers). Its example in the story, 14.2403, is 14.239852 exactly.
- Choosing "Calculated Field" adds a local step named after the slot and opens its expression; once the server stores it the slot is pointed at it. Escape drops the unsaved step and returns focus to the picker's opener.
- The picker offers it on number slots only. Text Template and URL template use of the grammar waits for Story 5.4 (deferred entry).

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour lint.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| The story's 14.2403 for PCT_CHANGE is not exact | low | accept | 35485 / 249195 × 100 is 14.239852; one decimal is 14.2 as the story says. |
| Expression text is not highlighted or autocompleted | low | accept | The grammar is small; errors name the position. |
| Text Templates and URL templates share the grammar later | low | defer | Entry in `deferred-work.md`. |
