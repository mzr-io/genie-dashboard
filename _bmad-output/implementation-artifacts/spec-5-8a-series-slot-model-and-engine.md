---
title: '5-8a Series slot model and engine'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: 'a2426f6'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-5-2-group-and-aggregate-transforms-and-aggregate-rows-by-flattening.md', '{project-root}/_bmad-output/implementation-artifacts/spec-3-12a-auto-map-slots-with-confidence.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Every slot of a Block Type is one value, so a chart cannot declare an axis and several series, and the mapper, engine and wizard have no list-valued slot (Story 5.8; AD-21, FR-32).

**Approach:** Add the `rows` cardinality (the value of every row, as a list) and a `repeat` declaration (a slot that may be filled up to 6 times, a chart's series) to the schema vocabulary. The engine reads a rows slot as a list, the auto-mapper offers it the fields of the rows, and the repeated slot becomes `series_1` to `series_N` so the plan keeps one alias per slot.

## Boundaries & Constraints

**Always:** A row without a value of the type is a null beside the others (partial data), never 0; a rows slot with no value at all is unavailable (`missing` or `wrong_type`). A rows slot takes only fields of the rows and a single slot only single fields, in the mapper, the picker and the checklist. The wizard and the mapper stay schema-driven.

**Never:** No Block Type package for the chart yet (Story 5.8c), no chart wrapper (5.8b), no change of the plan shape: `shape` stays `{slot: alias}`.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Repeat 3 | `series`, repeat 3 | slots `series_1` (required) to `series_3` (optional), labelled "Series n" | repeat outside 2 to 6: package refused at boot |
| Rows slot | three rows, one value `"n/a"` | `['10.5','12',null]` | N/A |
| No usable value | every row null or text | slot unavailable, `wrong_type` | N/A |
| Auto-map | rows with day, revenue, cost | x, series_1, series_2 from the rows, series_3 optional-empty | N/A |
| Single value for a series | `$.total` chosen | `type_mismatch`, "takes the value of every row" | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/BlockTypes/Contracts/BlockTypeVocabulary.php`, `Application/LoadBlockTypes.php` (`rows`, `repeat`), `resources/js/types/block-types.ts` (generated).
- `app/Modules/Mapping/Infrastructure/InProcessExecutor.php` (`slotList`), `Contracts/ExecutionResult.php`, `Application/AutoMapper.php` (`rows()`, `takes()`), `app/Modules/Blocks/Application/PreviewDraft.php` (list example).

## Tasks & Acceptance

**Execution:**
- [x] `rows` cardinality and `repeat` in the vocabulary and loader, TypeScript regenerated
- [x] Executor reads a rows slot as a list
- [x] Auto-mapper, picker, checklist and mismatch text for rows slots
- [x] Preview shows a list as its first values and the count
- [x] Tests: Unit

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit tests.

## Implementation Notes

- Decision: the repeated slot is expanded to `series_1`...`series_N` rather than a list-valued `shape: {series: [...]}`. The mapper's one-path-per-slot model, the checklist, the picker and the plan validation all keep working with no change, and the Shaper receives each series by key.
- A rows slot is a field of the rows (`[*]`); after a group or an aggregate the row stages already leave the rows aligned, so a chart can chart grouped data.
- The group of a slot in the checklist is Meta until the chart package declares its own.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, `composer block-types:check`, block-type pins.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Series folding for a seventh series ("Other") | n/a | defer | A chart rule of the package (5.8c): at most 6 series exist as slots. |
| Dates on the axis are text lexemes | low | accept | The Shaper and Renderer format them with `Intl` (5.8c). |
