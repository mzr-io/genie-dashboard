---
title: 'Fetch comparison data together as a sync group'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: 'a04e7eab476a721154f5fe2a5d0f4397f236b6b7'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-14-fetch-each-endpoint-on-a-schedule-and-keep-the-last-good-response.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-17-retry-rate-limit-and-break-the-circuit-on-failing-sources.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-19-refresh-only-what-is-being-watched-within-budgets.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A sync group is always one target, so a comparison Block's primary and prior-period requests are fetched at different moments and compute can mix them (Story 2.20; FR-13, NFR-1, AR-33, AR-11, AR-36, AD-26). The Story 2.20 section of `epics.md` holds the acceptance criteria.

**Approach:** A comparison subscription links a comparison target to its primary through `sync_targets.group_primary_target_id` and gives it the primary's `sync_group_id`. One `FetchJob` fetches both under one fence, the primary's `dispatch_seq`/`applied_seq`. Both payloads and one `sync_generations` row commit in one transaction, `ingestion.payload.changed` carries the generation, and a failed comparison side leaves the primary stored and the generation marked incomplete for that side. A maintenance sweep re-emits the event for a target whose `payload_seq` is ahead of what consumers applied.

## Boundaries & Constraints

**Always:** The dispatcher bumps only the primary's `dispatch_seq`; a lower `dispatch_seq` commit is `superseded`. A group is dispatched once per tick, hot when any member has a hot subscription, at the minimum hot interval. A generation is complete only when both sides succeeded; compute-facing reads return only complete generations, and a comparison whose side failed is `unavailable`. The generation row and event data hold IDs, ints and enums only, no body or value. Tenant table with RLS and `workspace_id`, UUIDs from the app, no cross-module foreign keys, no JSON decoding in Ingestion, column-limited `system` grants. Retries re-run the whole group (2.17).

**Decided:** Scope is the group fetch core. The Data Source detail "one run with both sides' outcomes" view is deferred: no sync-runs read path or UI exists, and `sync_runs` is Connector-owned. A comparison target belongs to one group (`group_primary_target_id`); a second group gets its own target row. Consumers do not exist yet, so the sweep re-emits against `outbox_consumptions` for subject `sync_target:{id}`.

**Never:** The sync-runs view, a Results module or consumer, changing the 2.17 governor or 2.18 health semantics, a comparison across Data Sources' budgets beyond the existing per-source governor, storing a body or value in a generation.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Link | Comparison subscription names a primary target | Comparison target shares the primary's group and stores its primary | Unknown primary: refused with a reason |
| Group fetch | Group due | One `FetchJob` for the group, one fence | N/A |
| Both succeed | Both payloads stored | One generation row, complete; event carries the generation | N/A |
| Comparison fails | Comparison fetch fails | Primary stored; generation written with the comparison side failed; comparison `unavailable` | Event still emitted |
| Incomplete read | Primary new, comparison old | Only the last complete generation is read | Never a mixed pair |
| Late run | Lower `dispatch_seq` commits | `superseded`, nothing written | N/A |
| Sweep | `payload_seq` ahead of consumed | Event re-emitted | Once per tick per target |

</frozen-after-approval>

## Code Map

- `app/Modules/Ingestion/Application/{FetchSyncTarget,FetchJob,DispatchDueSyncs,RegisterSubscription}.php` -- per-target loop and fence (`commitSuccess`, `commitFailure`, `applied_seq`), the job, `dispatchHot` and `run`, subscription link
- `app/Modules/Ingestion/Contracts/{SubscribeInput,SubscribeResult}.php` -- add the primary link and its refusal reason
- `database/migrations/2026_10_10_100000_create_sync_targets_and_raw_tier.php`, `2026_10_15_100000_create_sync_subscriptions.php` -- patterns; new migration for `sync_generations` and `group_primary_target_id`, `system` grants
- `app/Platform/Audit/AuditAction.php` (`IngestionPayloadChanged`), `app/Platform/Outbox/Outbox.php` (`emit` needs a Workspace transaction), `outbox_consumptions` -- event data and the sweep's consumed-seq read
- `app/Modules/Ingestion/Application/SweepRawHistory.php`, `routes/console.php` -- maintenance sweep pattern
- `tests/Architecture/dependencies.php` -- Ingestion already owns `sync_generations`; `tests/Database/{ScheduledFetchTest,DemandRefreshTest}.php` -- fence and dispatcher patterns

## Tasks & Acceptance

**Execution:**
- [x] migration, `dependencies.php`, `AuditAction` -- `sync_generations`, `group_primary_target_id`, grants
- [x] `Subscribe` and `RegisterSubscription` -- link a comparison target to its primary
- [x] `DispatchDueSyncs` -- one dispatch per group on the primary's seq
- [x] `FetchSyncTarget` -- fetch both sides, commit payloads and one generation together, comparison-failed outcome, `superseded`
- [x] sweep -- re-emit when `payload_seq` is ahead of consumers
- [x] tests -- every matrix row, the race, RLS, inert paths

**Acceptance Criteria:**
- Given a linked group that is due, when the dispatcher runs, then one `FetchJob` is queued for it and both targets are fetched under one fence.
- Given the comparison fetch fails, when the run commits, then the primary payload is stored, the generation marks the comparison failed and only the last complete generation is readable.
- Given `bin/tools composer ci:check`, when run, then it passes including the `Database` suite.

## Implementation Notes

- A comparison target stores `group_primary_target_id` and takes the primary's `sync_group_id`; one comparison per primary (unique index). Its `applied_seq` restarts at 0 on link and follows the primary's fence (the `applied_seq <= dispatch_seq` check is relaxed where `sync_group_id <> id`).
- A generation is written when a payload changed or the (primary_ok, comparison_ok) pair differs from the latest generation's, never when both sides failed; a failed side keeps no payload id. Compute reads through `SyncGenerations::latestComplete` and `comparisonUnavailable`.
- The sweep is `ReemitPayloadEvents` (every minute): it skips a target with an outbox event younger than 300 s and takes at most 100 targets per Workspace per run. Until a consumer records `outbox_consumptions`, every stored target is re-emitted about every 5 minutes.
- `subject_seq` and `payload_seq` are different counters (re-emits and generation-only events raise the first); the sweep compares them as the spec says.

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| A retired comparison blocks the primary from taking a new one | medium | patch (done) | The unique index and `link()`'s `taken` query ignored `retired_at`; both now count live rows only; test added. |
| A retired primary leaves its comparison linked and never dispatched | high | patch (done) | `RegisterSyncTargets` now makes such a comparison a target of its own; test added. |
| `down()` fails after a group has run | low | patch (done) | `applied_seq` is clamped to `dispatch_seq` before the strict check returns. |
| Relaxed seq check keyed on `sync_group_id <> id`, not on being linked | low | patch (done) | Now `group_primary_target_id IS NOT NULL`; existing check test still passes. |
| Group governor/retry/moved-revision paths, re-emit exclusions, superseded-run rows untested | medium | defer | Verified gap; needs retry and governor tunables in `SyncGroupTest`. |
| Re-emit compares `payload_seq` with `subject_seq`; floods until a consumer exists; job can starve late Workspaces | medium | defer | The spec says to compare against `outbox_consumptions`; settle when the first consumer exists (Epic 3/4). |
| Both sides failing writes no generation, so a failing group looks like a stale one | low | defer | The implementer's rule; revisit with the compute read. |
| Link does not check comparable targets; generations name payloads retention may purge and are never pruned | low | defer | No Block or consumer exists yet. |
| Superseded comparison recorded `failed`; store failure coded as denied; uncalled comparison charged on rollback | low | rejected | Cosmetic run codes on rare paths; fixes add statuses or branches. |
| Whole group re-runs when the comparison waits or retries; group counts once in dispatch budgets; refused conflict leaves the target; lock order; absorbing a shared target | low | rejected | Retry of the whole group is the 2.17 rule and the single-group target is decided in the spec; the rest are unlikely in everyday use. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
