---
title: '5-1 Filter, sort and top-N transforms in the Map Data step'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '5ad41be'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A Block can only read the rows of a response as they come; an Admin cannot keep the rows they care about or put them in the order they choose.

**Approach:** Add Filter, Sort and Top-N steps to the Draft, stored as `config.transforms` and carried into Query Plan v1 as the `filter`, `sort` and `top_n` stages that the one engine runs in the fixed order, with a "3 · Transforms" section in Map Data that shows each step as a sentence with its row counts.

## Boundaries & Constraints

**Always:** The stage order is filter, sort, top-N whatever order steps were added in. The same engine runs preview, validation and runtime. A missing or mistyped field makes the slots that read the rows unavailable, never 0. A guard aborts and never truncates. The steps are server-owned like the mapping keys.

**Never:** No script, expression or free-form code field. No drag reorder. A Draft save never carries the steps.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Filter | status is Approved on 36 rows | stored, plan `filter`, row counts "36 → N rows" | N/A |
| Sort then Top-N | revenue 10,50,30,70,20,60,40 desc, top 5 | 70,60,50,40,30; ties keep source order | N/A |
| Any order added | top_n, sort, filter | stored and run as filter, sort, top_n | N/A |
| Field not in the response | filter on a gone field | step shows the error state, Continue blocked with the reason, focus moves to it | slots unavailable at run time |
| Numeric comparison, non-number value or field | gt "abc" | inline message, never sent, Continue and Publish validation blocked | server stores it as a fault |
| Over the guards | too many extracted values | guard error, no truncation | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Mapping/Contracts/{Transforms,QueryPlan,ExecutionResult,PlanResult,AutoMapResult,AutoMapping}.php`; `app/Modules/Mapping/Application/{AutoMapper,QueryPlanEngine}.php`; `app/Modules/Mapping/Infrastructure/InProcessExecutor.php` (row stages).
- `app/Modules/Blocks/{Application/ManageDraftSlots,Application/DraftConfig,Application/DraftStrictCheck,Application/PreviewDraft}.php`, `Contracts/{DraftSlots,DraftMapping,DraftTransforms,DraftPreview}.php`; `app/Http/Controllers/Admin/{DraftSlotsController,DraftPreviewController}.php`, `app/Http/Requests/Admin/DraftSlotRequest.php`, `app/Http/Navigation/ShellNavigation.php`, `routes/api.php` (`PUT .../mapping/transforms`).
- `resources/js/lib/blockTransforms.ts`, `resources/js/components/wizard/{TransformsSection,MapDataStep,LivePreview}.vue`, `resources/js/pages/admin/BlockWizard.vue`, `resources/js/locales/labels.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Plan stages, executor row model and the one-engine counts
- [x] `config.transforms`, the write endpoint, plan merge, faults, strict check and preview counts
- [x] "3 · Transforms" section, Continue gating and focus
- [x] Tests: Unit (engine, transforms, strict check), Database (endpoint), vitest (section, page)

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit, database and component tests.

## Implementation Notes

- **Row model (decision):** the longest list any path (a slot's or a stage field's) selects sets the row count; a list of that length is a column of the same rows (the n-th value of each is one row). A shorter list, such as a single value, is left as it is. This keeps `v` 1 and the existing scalar slots working and gives Story 5.2's aggregate and 5.3's calculated fields their input; a slot still reads one value, so it stays `not_single_value` while several rows are left.
- A plan is kept when something is mapped or transform steps exist, so the row counts show before a slot reads a value.
- Numeric comparisons are exact decimal comparisons (no float); sort is stable; empty values sort last; mixed kinds are unsortable.
- Limits: 5 filters, 3 sorts, one top-N of 1 to 1000; values up to 200 characters.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour lint.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Rows are index-aligned lists, not a typed row set | medium | accept | Fits plan v1 and the stored-path grammar; 5.2 builds on it. |
| The write endpoint needed a permission entry | low | fixed | `ShellNavigation` lists the route under `blocks.edit`. |
| Faults against the sample are reported, not rejected | low | accept | The step is kept and blocks Continue; Publish validation judges what can be known without the sample. |
