---
title: '4-3 Judge freshness and keep viewed data hot'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '8dba5a4'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A Block's "Stale" badge and "data as of" must mean one thing everywhere, and data a person is looking at must be refreshed while unwatched data stays cold. Neither exists yet, and Results has no read path.

**Approach:** Add `Results\Contracts\Freshness` (the only place the 2 x refresh interval rule exists, on the database clock) and `ViewedResults::view()`, which resolves the Block's period, subscribes or touches the subscription through Ingestion's `Subscribe` (so the target is hot), reads the stored result and returns it with `stale` / `refreshing` set. A refusal to keep the Block hot never fails the read.

## Boundaries & Constraints

**Always:** Stale when `now > last_success_at + 2 x interval`; `last_success_at` is the target's (a 304 or equal body moves it) and `data_as_of` is the mapped Data-as-of slot, else the payload's first observation. The subscription copies the Block's interval, compute context, resolved period and access time; `hot_until` is `last_access_at + sync.hot_window`. The read path never calls a source.

**Never:** No dashboards or HTTP endpoints (4.4, 4.5); no Reverb (4.13); no new budgets (2.19 has them); no comparison generations (Epic 5); no change to the rule for `live` beyond using the smallest offered interval.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| First view | published version, no payload | subscription created, `pending`, no result | N/A |
| Fresh | last check 100 s ago, interval 60 | not stale | N/A |
| Stale | last check 121 s ago | stale, refreshing while hot, same data | N/A |
| Unchanged source | checked 30 s ago, changed a day ago | not stale, `data_as_of` a day old | N/A |
| Budget refused | cold-key budget exceeded | read answers, `hot_refusal` names it | no failure |
| Bad selection | preset not offered | rejected | 422 |
| Not published | `prepared` | `unpublished`, nothing subscribed | N/A |
| Source down | transport fails | result served, zero calls | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Results/Contracts/{Freshness,ViewRequest,ViewedResult,ViewedResults}.php`, `Application/ViewBlockResult.php`
- `app/Modules/Ingestion/Contracts/{ComputeSources,WatchedTarget,ComputeSubscription}.php` + `ReadComputeSources` -- `watching()` and the subscription's interval and hot time
- `app/Modules/Blocks/Contracts/PublishedConfig.php` + `ReadPublishedConfigs` -- source, interval, period behaviour, default range
- `app/Modules/Results/Application/ComputeResults.php` -- mapped Data-as-of

## Tasks & Acceptance

**Execution:**
- [x] `Freshness` and its architecture test (the rule lives nowhere else)
- [x] `ViewedResults` / `ViewBlockResult` with `Subscribe`, resolver and store
- [x] `ComputeSources::watching()`, frozen source facts on `PublishedConfig`
- [x] Mapped Data-as-of in compute
- [x] Tests: unit (rule, boundaries, olderOf, as-of), architecture, Database (every matrix row)

**Acceptance Criteria:**
- Given the I/O matrix, when a Block is viewed, then each row holds against real PostgreSQL and Valkey.

## Implementation Notes

- `ViewedResults` is the contract Dashboards (4.4, 4.5) calls; there is no HTTP surface in this story.
- `refreshing` is `stale && hot`: a stale Block whose subscription is hot is being refreshed by the dispatcher; one that is not hot only waits.
- The workspace and hot-key budgets live in the dispatcher (Story 2.19) and the cold-key budget in `Subscribe`; this story reports a refusal and never enforces one.
- `live` is mapped to the smallest `refresh_intervals` entry because no seconds value exists for it; Story 4.13 owns the push path.
- `data_as_of` is read from a plan slot called `data_as_of`; kpi-card has none, so it is the payload's observation time. Only ISO dates and date-times are taken from a slot.

## Verification

- Unit, architecture and the Database suite pass; see the commit for counts. `pint` and `phpstan` clean.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| `view()` subscribes on every call for a user-bound Endpoint | low | defer | Subscribe throttles the touch (Story 2.19); the per-view cost is one upsert attempt. |
| `live` uses an interval of the Workspace's choosing | low | defer | No value exists to use; revisit with Story 4.13. |
| Result `state` of a stale block stays its computed state | false | none | Staleness is a flag by design (precedence list has no stale). |
