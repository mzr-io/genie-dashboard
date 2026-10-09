---
title: 'See Data Source health'
type: 'feature'
created: '2026-10-09'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-14-fetch-each-endpoint-on-a-schedule-and-keep-the-last-good-response.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-17-retry-rate-limit-and-break-the-circuit-on-failing-sources.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The Data sources list shows a hard-coded "Checking…" and no failure is visible anywhere, so an Admin learns of a broken API from users (Story 2.18; FR-12, NFR-13, NFR-1, AR-46, AR-36, AR-57, UX-DR-114/115/260/261/273/276/282). The Story 2.18 section of `epics.md` holds the acceptance criteria.

**Approach:** Ingestion keeps a per-Data-Source health read model (`data_source_health`) fed by a save-time probe (a Connector `SourceProbe` run by a signed job), by final `sync_runs` outcomes and by breaker transitions. A pure evaluator maps them to `checking|healthy|degraded|unreachable` under `pending_input` thresholds, emits and audits `ingestion.source_health.changed` on a change, and the list and a new Admin overview "Platform health" panel show dot, word and last success.

## Boundaries & Constraints

**Always:**
- **Status rules, first match wins** (`Ingestion\Application\EvaluateSourceHealth`, no I/O inside the decision): (1) no probe result and no final run yet = `checking`, never Healthy; (2) breaker `open` or `half_open` = `unreachable`; (3) trailing consecutive failed runs (since the last succeeded run) >= `health.threshold_unreachable` = `unreachable`; (4) runs in the last `health.window` seconds, succeeded / (succeeded + failed) in whole percent: >= `threshold_healthy` = `healthy`, >= `threshold_degraded` = `degraded`, else `unreachable`; (5) latest probe: ok = `healthy`, failed = `unreachable`. Only `succeeded` and `failed` `scheduled_fetch` runs count (a `succeeded` 304/`not_modified` is a success; `retrying`, `skipped`, `superseded` and probe runs never count). Rule 3 needs `threshold_unreachable` a positive whole number; rule 4 needs `window` a positive whole number of seconds and `threshold_healthy` > `threshold_degraded`, both whole 1-100; a rule whose settings are unset or malformed is skipped (inert, no invented number); an empty window skips rule 4. New `tunables.health.window` (`DASHFLOW_HEALTH_WINDOW`, `pending_input`).
- **Probe:** `Connector\Contracts\SourceProbe` makes one GET to the base URL, or `base_url` + the optional `health_path`, with the default headers and credentials, through `FetchTransport` and EgressGuard, reusing the request building of `RunConnectionTest` (extract, no behaviour change). Ok = 2xx or 304; no JSON is required and the body is dropped. `data_sources.health_path` is a new optional relative path (starts `/`, no query, fragment or `..`, <= 255) validated like Endpoint paths, part of the Data Source form and its revision. A probe honours an open breaker (no call, previous result kept), consumes no token, never retries and records a `sync_runs` row of kind `health_probe`. Run by `ProbeDataSourceJob` (signed `WorkspaceScopedJob`, queue `fetch-scheduled`, tries 1, `referencedIds` `data_sources => [id]`).
- **Triggers:** Connector emits `connector.data_source.created` (IDs and revision only; `updated` exists). An Ingestion `OutboxConsumer` upserts the health row (`checking` on create, status kept on update) and queues the probe. When `health.probe_interval` is a valid positive whole number of seconds, a `maintenance` scheduler job (`system` role, `FOR UPDATE SKIP LOCKED`, fixed batch, column-limited grants like 2.14) re-probes rows with `next_probe_at` due and no current sync target; unset = save-time probe only. Evaluation runs after a probe, after the final run of `FetchSyncTarget` and when `SourceGovernor::record` returns `OPENED`/`CLOSED`, in that transaction.
- **Last success:** the newest `last_success_at` of the source's current sync targets; a source without targets uses its last ok probe time. The list's `health` and `last_successful_call_at` fields come from `Ingestion\Contracts\SourceHealths::forDataSources(ws, ids)`; the controller merges them.
- **Transitions:** `status`, `status_since`, `status_seq` change in one guarded `UPDATE ... WHERE status IS DISTINCT FROM`; a change emits `ingestion.source_health.changed` through the outbox (`{data_source_id, from, to}`, IDs and enums only; subject `data_source_health:{id}`) and writes the same-named audit event (system actor) in the same transaction. No event for `checking` as `to` or for `checking` to `healthy`. Notification delivery is not built.
- **Read and UI:** `GET api/admin/data-source-health` (name, id, health, last success per source) needs `data_sources.manage` (403 and audit via the `admin` middleware, mapped in `ShellNavigation::ADMIN_API_ROUTES`). `admin.overview` renders a new `Overview` page; the "Platform health" panel with "Your data sources" is rendered only for `data_sources.manage`, not hidden by CSS. Each row: `StatusDot` (`aria-hidden`) + word Healthy/Degraded/Unreachable/Checking… + "Last success {time}" or the no-call text, as a link to the form, accessible name "{name}, {word}, last success {time}". The dot never appears without its word; tones success, warning, error, neutral. Copy lives in `labels.ts`/`en.ts`. Loaded once, never announced as a routine refresh.
- **Breaker read:** `SourceGovernor::state(ws, ds)` is read-only (`closed|open|half_open`), reads Lua-written keys, and returns `closed` when the breaker is inert or Valkey fails.
- Tenant table with RLS, UUID ids from the app, no secret, query or header in any run, log, event or metric; metrics `dashflow.connector.health_probe`, `dashflow.ingestion.health_changed` (workspace label only).

**Never:** The platform-services half of the panel, the overall Operational/Degraded/Outage badge, in-app or email notification delivery, a polling or live-updating panel, hot/cold demand and budgets (2.19), sync groups (2.20), a number invented for the window, thresholds, probe interval or timeouts, JSON decoding in Ingestion, a probe from the web tier, changing 2.17 breaker semantics.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Save | Data Source created | Row `checking`, list "Checking…" until the probe result; probe queued | N/A |
| No Endpoints | Probe ok / probe fails | `healthy` / `unreachable`, last success = probe time / none | Probe run row with code |
| Healthy mix | Window 9 succeeded (two 304) + 1 failed, healthy 80 | `healthy` | N/A |
| Degraded | 60% success, healthy 80, degraded 50 | `degraded`; event `healthy` to `degraded` | N/A |
| Breaker open | 2.17 breaker opens | `unreachable` and event; closing re-evaluates | N/A |
| Recovery | Success after unreachable | `healthy` per rules, "Last success" moves, event once | N/A |
| Thresholds unset | Window/thresholds unset | Rules 3-4 skipped; probe decides; no run label | N/A |
| No data | No probe result, no runs | `checking`, never `healthy` | N/A |
| Open breaker at probe | Breaker open | No call; prior result kept | N/A |
| Transition race | Two evaluators, same result | One row change, one event | Guarded UPDATE |
| Unauthorised | Lacks `data_sources.manage` | API 403 (audited); panel absent | 403 |
| Canary | Secret in header/health path/response | Absent from runs, events, audit, logs, metrics | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Ingestion/Application/{FetchSyncTarget,RegisterSyncTargets,ReadSyncStatuses}.php`, `Infrastructure/SyncSettings.php`, `Contracts/SyncStatuses.php` -- evaluation hook after a final run and breaker `OPENED`/`CLOSED`; consumer pattern; settings-reader pattern; last-success source (`sync_targets.last_success_at`)
- New `Ingestion\Application\{EvaluateSourceHealth,ProbeDataSourceJob,RecordProbeResult,DispatchDueProbesJob,RegisterSourceHealth}`, `Contracts/{SourceHealths,SourceHealth}`, `Infrastructure/HealthSettings` -- evaluator, probe job, consumer, due-probe tick, read contract
- `app/Modules/Connector/Application/{RunConnectionTest,ManageDataSources,RecordSyncRun,ValidateDataSourceInput}.php`, `Contracts/{SourceGovernor,DataSource,DataSourceInput,SyncRunLog}.php`, `Infrastructure/ValkeySourceGovernor.php` -- request building to extract, `created` event beside the existing `updated` emit, `health_path`, `state()` (hash keys `state`, `open_until`, `probe_until`); new `Contracts/{SourceProbe,SyncRunHistory}` (counts: succeeded, failed, trailing failures since a time) and their implementations
- `database/migrations/2026_10_08_100001_create_sync_runs_partitioned.php`, `2026_10_10_100000_create_sync_targets_and_raw_tier.php` -- patterns for a new migration: `data_source_health`, `data_sources.health_path`, `sync_runs` kind check, system-role grants
- `config/dashflow.php` (`tunables.health`), `routes/{web,api,console}.php`, `app/Http/Navigation/ShellNavigation.php`, `app/Providers/AppServiceProvider.php`, `app/Platform/Audit/AuditAction.php`, `app/Support/Observability/MetricEmitter.php`, `tests/Architecture/dependencies.php` (Ingestion tables) -- settings, route and permission map, bindings, audit action, metrics
- `app/Http/Controllers/Admin/DataSourceController.php`, `app/Http/Resources/DataSourceResource.php` (currently `HEALTH_PENDING`, null), `resources/js/pages/admin/{DataSources,DataSourceForm}.vue`, `resources/js/lib/dataSources.ts`, `resources/js/components/StatusDot.vue`, `resources/js/locales/{labels,en}.ts` -- list cell, form field, merge, tones, copy; new `resources/js/pages/admin/Overview.vue` replaces the `admin-overview` Placeholder
- `tests/{Unit,Database,Security,Architecture,js}/`, `tests/Database/ScheduledFetchTest.php` -- evaluator table tests, matrix rows, canary, RLS, JS list and panel tests

## Tasks & Acceptance

**Execution:**
- [x] migration, `dependencies.php`, `config/dashflow.php`, `AuditAction` -- `data_source_health`, `health_path`, `health.window`, event string
- [x] Connector: `SourceProbe`, `SyncRunHistory`, `SourceGovernor::state`, extracted request factory, `created` event, `health_path` validation and form field
- [x] Ingestion: settings, `EvaluateSourceHealth`, consumer, `ProbeDataSourceJob`, due-probe tick, hooks in `FetchSyncTarget`, `SourceHealths`, metrics
- [x] controller, resource, route and permission map, list cell, `Overview` page and panel, `labels.ts`/`en.ts`
- [x] tests: every matrix row, evaluator rules and inert settings, 304 as success, two-evaluator race, canary scan, role grants, a11y (dot `aria-hidden`, full status name)

**Acceptance Criteria:**
- Given a new Data Source, when saved and the relay delivers, then the list shows "Checking…" until the probe result, then Healthy or Unreachable with its word.
- Given a failing source and valid settings, when its runs or breaker cross a threshold, then the status changes once, one audited `ingestion.source_health.changed` exists, and a later success returns it to Healthy with "Last success" updated.
- Given an Admin without `data_sources.manage`, when they open the overview or call the API, then no panel renders and the API answers 403.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Valkey `state()` never run by a test | medium | patch (done) | Only `FakeGovernor` covered it; added a mocked-Valkey case to `RetryAndGovernorTest` (reply codes, inert limits, store down). |
| Oversize 2xx probe untested | low | patch (done) | Added a 2xx/500 `ResponseLimitExceeded` case to `DataSourceHealthTest`. |
| README omits the Degraded rule | low | patch (done) | `decide()` returns Degraded when the latest final run failed; README status paragraph now says so. |
| Controller docblock names `api/admin/...` | low | patch (done) | Route is `api/v1/admin/data-source-health`. |
| `seeds next_probe_at` test failed | medium | patch (done) | `Queue::fake()` leaked into the second half so the probe job never ran; the real queue is restored before it. |
| AuthFailed/SsrfBlocked probe codes, stored-credential probe path untested | low | defer | Verified gap; the mapping mirrors the connection test, which is covered. |
| No time-based re-evaluation | false | rejected | Sources with targets re-evaluate on every scheduled run, and sources without targets are probed periodically. |
| No backfill, probe on every save, per-Workspace fairness, thresholds as decided | false | rejected | Decided in the spec's Design Notes. |
| Evaluation failure only logged; 3xx/429 probe coarse; local config error shows Unreachable | low | rejected | Cosmetic; the fix adds branches. |
| Breaker CLOSED evaluates before the run is recorded; evaluate on retrying/skipped | false | rejected | `fetchTarget` evaluates again after the run is recorded. |
| Valkey down reads as closed | low | rejected | Fail-open is the governor's stated design (2.17). |
| Orphan health rows, null `next_probe_at` when interval set later, unbounded overview, duplicate probes, unused label reasons, CHECK vs validator | low | rejected | Unlikely in everyday use and each fix needs new guards or parameters. |

## Design Notes

Decided here, not in the epic (the epic and PRD leave the thresholds TBD): **Health lives in Ingestion** as a read model, because only Ingestion sees runs, targets and may read Connector contracts; Connector never calls back (events in, contract reads out). **Threshold meaning**: `threshold_healthy`/`threshold_degraded` are success percentages over `health.window`, `threshold_unreachable` is a count of trailing failed runs; each is inert alone, and with all unset the status comes from the probe and the breaker only, so nothing is invented. A probe is fresher evidence only when no run exists; once runs exist they outrank it. **Probe load**: periodic probes cover only sources without a current target, since scheduled fetches already prove the rest. **Last success** for a source with targets ignores the probe so a passing base URL cannot mask failing data. **No backfill**: sources created before this story read as Checking… until their next save, as in 2.14. The relay's one-minute lag applies to the first probe.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0

**Manual checks (if no CLI):**
- Data sources list and Admin overview with a failing and a healthy source: word beside every dot, "Last success" shown, screen reader reads "{name}, Unreachable, last success 10:42".
