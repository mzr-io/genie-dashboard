---
title: '5-9 View as table, Expanded view and the paginated rowset endpoint'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '4059945'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-5-8c-line-area-block-type.md', '{project-root}/_bmad-output/implementation-artifacts/spec-4-6-use-block-chrome-refresh-minimize-about-this-block-footer.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A chart shows a summary only: a reader cannot see its exact values as a table, and the rows behind it (before top-N) cannot be browsed; the footer's Expanded view target draws nothing (Story 5.9; FR-26, FR-32, AR-32).

**Approach:** Results keeps the rows behind a Block's rows slots (before top-N) in the cache, named by the result's `rowset_ref`; `GET /api/v1/dashboards/{d}/items/{i}/rows` pages them with the member's access judged on every request and no source called. A chart Block's menu gets "View as table" (built from the displayed payload) and its footer's Expanded view target opens a panel that pages the rows.

## Boundaries & Constraints

**Always:** Only mapped fields, measures as decimal strings. Access to the published Block is re-evaluated per request (403 with no rows when lost). A set that left the cache is a retryable 503 and queues one compute; the source is never called. A page past the last is the last page, announced. Every cell is escaped text. The panel's focus goes to the title; Esc or Close returns to the invoker.

**Never:** No row set in the render payload or the result's ETag; no source call on this path; no per-package code in Dashboards or Results.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Page 2 of 3 | `page=2&page_size=10` over 30 rows | 10 rows, total 30, pages 3 | N/A |
| Past the end | `page=9` | the last page, `clamped: true`, announced | N/A |
| Before top-N | top-N 5 on 30 rows | the card shows 5, the rows say 30 | N/A |
| Set expired | cache flushed | 503 `dashboards.rowset_expired`, `Retry-After`, a compute queued, no source call | msg:expanded-error with Retry |
| Access lost | subject removed | 403 `dashboards.rows_unavailable`, no rows | N/A |
| Block with no rows slot | KPI card | `empty` | msg:expanded-empty |
| New result | etag changes | values replaced in place, page kept | N/A |
| Hostile cell | `<img onerror>` | literal text | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Mapping/Infrastructure/InProcessExecutor.php` (the table before top-N), `Contracts/{ExecutionResult,PlanResult}.php`, `Application/QueryPlanEngine.php`.
- `app/Modules/Results/Contracts/{RowsetStore,RowsPage,RowsetReads,BlockResult}.php`, `Infrastructure/CacheRowsetStore.php`, `Application/{ReadRowset,ComputeResults}.php`.
- `app/Modules/Dashboards/Application/ManageDashboards.php` (`rows`), `app/Http/Controllers/DashboardController.php`, `routes/api.php`, `Contracts/ErrorCode.php`.
- `resources/js/components/{ExpandedView,BlockCard,DashboardGrid}.vue`, `resources/js/lib/{dashboards,blockRenderers}.ts`, `block-types/line-area/v1/table.ts`, `locales/labels.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Executor keeps the rows before top-N for each rows slot
- [x] Row set store, reader and the `rowset_ref` of the result
- [x] Rows endpoint with pagination, access per request, expired and unavailable
- [x] ExpandedView panel (table and rows), card menu item and footer button
- [x] Tests: Unit, Database, vitest

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit, database and component tests.

## Implementation Notes

- The set is column-major `{columns, values}` under `rows:{rowset_ref}` in the tenant cache with the results' TTL; `rowset_ref` is a hash of the result key, generation and payload sequence, so a new result names a new set and the old one expires on its own.
- A chart type opts in to "View as table" by giving `tableFor(type)` a builder (`block-types/{key}/v{n}/table.ts`); the Expanded view formats the cells of a page with the same builder.
- Sorting a page of rows is not part of this story.
- Page size is 25 in the panel, at most 100 on the endpoint.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour and renderer lint, error-code generation.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Sort of the rows page | low | defer | The acceptance names keeping the sort; there is no sort control yet. Recorded in `deferred-work.md`. |
| The footer's Expanded view link was a deferred item of Story 5.4 and 4.6 | n/a | fixed | A button on the card opens the panel. |
