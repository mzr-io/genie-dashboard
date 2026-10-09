---
title: 'Refresh only what is being watched, within budgets'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: 'f58b2179dae768e63b10776e2b0509dc5ecf58de'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-14-fetch-each-endpoint-on-a-schedule-and-keep-the-last-good-response.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-17-retry-rate-limit-and-break-the-circuit-on-failing-sources.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-18-see-data-source-health.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Every registered sync target is fetched on the smallest refresh interval whether or not anyone is watching it, and no Workspace budget bounds hot keys or new cold keys (Story 2.19; FR-13, NFR-1, NFR-3, NFR-13, AR-11, AR-44, AR-57, AD-7, UX-DR-26/282). The Story 2.19 section of `epics.md` holds the acceptance criteria.

**Approach:** Ingestion gains `Contracts\Subscribe` and a `sync_subscriptions` table (tenant, RLS). A subscription makes a target hot until `last_access_at + hot_window`; the dispatcher schedules a target at the minimum interval of its hot subscriptions and a target with none gets only the health probe. Budgets widen the effective interval and mark items budget-limited instead of queueing without limit, and a purge job removes cold per-user targets after `cold_purge_after`.

## Boundaries & Constraints

**Always:** Every setting stays `pending_input`: an unset or malformed setting turns its rule off and no number is invented. `Subscribe` returns `access.context_missing` and creates no target when a bound attribute is missing. The interval is copied at subscribe time and touches are throttled. Metrics `dashflow.ingestion.hot_targets`, `.cold_targets`, `.budget_limited` carry `workspace_id` and `request_id`. Callers use only `Ingestion\Contracts`; the `system` role gets only the column grants the dispatcher needs.

**Decided:** Scope is the demand core only: Live refusal (`msg:live-not-supported`), the scheduler clamp on losing `live_capable`, the impact list and its notification event are deferred. A budget widens an effective interval to the next larger entry of `refresh_intervals` (no new number; with none larger the item stays at its interval and is still marked budget-limited). `block_version_id` is an opaque UUID and compute context an opaque string, no foreign keys.

**Never:** Live refusal and the impact list (deferred), sync groups (2.20), a Blocks or dashboards module, a number invented for the hot window, budgets or the Live interval, JSON decoding in Ingestion, changing the 2.17 governor or 2.18 health semantics.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Subscribe | New subscription on a target | Row upserted, `hot_until = last_access_at + hot_window` | N/A |
| Touch | Same subscription again | `last_access_at` moves only past the throttle | N/A |
| Two hot | Intervals 60 s and 300 s | Dispatched every 60 s | N/A |
| Idle | No hot subscription | No scheduled fetch; health probe only | N/A |
| Over budget | Hot keys above max | Interval widens, item budget-limited, metric emitted | Nothing queued without limit |
| Missing attribute | Bound attribute absent | No target, `access.context_missing` | N/A |
| Cold purge | Cold per-user target past `cold_purge_after` | Target and payloads purged | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Ingestion/Application/{DispatchDueSyncs,RegisterSyncTargets,SweepRawHistory,ResolveFetchKey}.php`, `Infrastructure/SyncSettings.php` -- dispatcher (`system` role, `next_due_at`, `refresh_interval_seconds`), target registration, cold purge (today only retired targets), `FetchKeyResult::missing()` for `access.context_missing`
- `database/migrations/2026_10_10_100000_create_sync_targets_and_raw_tier.php` -- pattern and grants for `sync_subscriptions` and any new `system` columns
- `config/dashflow.php`, `tests/Feature/DashflowTunablesTest.php` -- `tunables.sync.hot_window`, `.cold_purge_after`, `.live_budget`, `tunables.budgets.max_hot_keys_per_workspace`, `.max_new_cold_keys_per_membership_per_hour` (no readers yet except `cold_purge_after`)
- `app/Support/Observability/MetricEmitter.php` -- `increment` only (no gauge)
- `tests/Architecture/dependencies.php` -- Ingestion already owns `sync_subscriptions`; Ingestion may call only Connector and RawStore Contracts

## Tasks & Acceptance

**Execution:**
- [x] migration, `dependencies.php`, `config/dashflow.php`, `AuditAction` -- `sync_subscriptions`, grants, readers for `hot_window` and the budgets
- [x] `Ingestion\Contracts\Subscribe` and `Application` -- upsert, throttled touch, `access.context_missing`
- [x] `DispatchDueSyncs`, `SyncSettings` -- minimum hot interval, probe-only when idle, budget widening
- [x] purge job and metrics -- cold per-user targets, the three metrics
- [x] tests -- every matrix row, RLS, inert settings

**Acceptance Criteria:**
- Given a hot subscription, when the dispatcher runs, then the target is fetched at the minimum hot interval and an idle target is not fetched.
- Given an exceeded budget, when scheduling, then the item is budget-limited, a metric is emitted and nothing is queued without limit.
- Given `bin/tools composer ci:check`, when run, then it passes including the `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Periodic probe skipped for a source with only hot user-scoped targets, which are never fetched | medium | patch (done) | `RecordProbeResult` now counts only a hot target with `next_due_at`; test added with idle, hot and per-user sources. |
| Periodic-probe narrowing untested; null `hot_until` purge untested | medium | patch (done) | Added to `DataSourceHealthTest` and `DemandRefreshTest`. |
| No index on `sync_subscriptions(sync_target_id)` | low | patch (done) | Sweep, probe check and the cascade filter by target alone; index added to the new migration. |
| Fair-share cap in the hot dispatch path untested | low | defer | Verified gap; the bindings are the old path's pattern. |
| `scopeByCaller` Endpoint with no user-bound param becomes a scheduled shared target | maybe-false | defer | Would settle: whether `Endpoint::requiresUserContext` already covers `scopeByCaller`; if not, medium. |
| `hot_targets`/`cold_targets` are counters carrying levels; `budget_limited` has two meanings | low | defer | `MetricEmitter` has no gauge; needs an emitter change. |
| Shared per-period targets never purged | low | defer | The spec purges only cold per-user targets. |
| Hot window shorter than a subscription interval goes cold between touches | low | rejected | Follows the spec's `hot_until = last_access_at + hot_window` and a throttled touch; both are Admin settings. |
| Stale subscription period or interval on conflict | false | rejected | Period is part of the target key and the interval of the block version. |
| Metric throwing after commit; stale `budget_limited` flag; widened() null still limited; purge race; cold-key budget race; retired target with the same key; input bounds; `app()` locator; hot_window set later | low | rejected | Not reachable in everyday use or documented, and each fix adds guards or parameters. |
| Hard-coded rollback step counts; timing-based assertions | low | rejected | The repo's existing pattern. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
