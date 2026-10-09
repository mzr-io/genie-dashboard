---
title: 'Retry, rate-limit and break the circuit on failing sources'
type: 'feature'
created: '2026-10-08'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-14-fetch-each-endpoint-on-a-schedule-and-keep-the-last-good-response.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A scheduled fetch (2.14/2.15) makes one attempt per interval and never slows down: a throttling or failing API gets hit again at full rate, a transient blip costs a whole interval, and a killed `FetchJob` leaves no `sync_runs` row (Story 2.17; FR-13, NFR-1, NFR-3, NFR-13, AR-45, AR-43, AR-57, AR-31, AD-26). The Story 2.17 section of `epics.md` holds the acceptance criteria.

**Approach:** Connector classifies each failed fetch (`FailureClass`, plus a parsed `Retry-After`); `FetchSyncTarget` asks a Valkey-backed `SourceGovernor` for admission (per-Data-Source breaker, penalty, token bucket, concurrency), retries by re-queuing the same fenced `FetchJob` with a delay inside the dispatch's interval, and records every attempt and skip in `sync_runs`.

## Boundaries & Constraints

**Always:**
- **Classes** (`Connector\Contracts\FailureClass`, set by `EndpointFetchLadder`/`FetchEndpoint`, judged on the cause for a `PageFailed`): `transient` = cURL timeout (28), connect/DNS/reset/other transport errors, HTTP 5xx except a 503 with a valid `Retry-After`, 408; `throttled` = 429, or 503 with `Retry-After`; `configuration` = every other 4xx, `AuthFailed`, TLS errors, `SsrfBlocked`, secret/keyring/values/`endpoint_gone`/`not_read_only` failures; `data` = not JSON, `ResponseLimitExceeded`, `PageLimitExceeded`, pagination failures; `ambiguous` = a POST that failed after the request may have been sent (every transport error except DNS 6, connect 7, TLS-connect 35, and any 5xx). Only `transient` and `throttled` retry; a POST retries only on `throttled` or a pre-send error (it is `transient` then). `configuration` and `data` never retry, keep the last good payload, and the run records `error_code` `config-error` (a 4xx or any `configuration`, with `http_status`) or the existing code.
- **Retry:** inert unless `tunables.retry.base`, `cap` and `max_attempts` are all valid positive whole numbers (seconds; `max_attempts` counts the first call). Delay = `Retry-After` clamped to `cap` for `throttled` (delta-seconds or HTTP-date; invalid or absent falls back), else full jitter `random(0, min(cap, base·2^(attempt-1)))` via an injectable `Jitter` port. A retry is a new `FetchJob(workspace_id, sync_group_id, dispatch_seq, attempt)` queued with that delay, only if `now + delay < next_due_at` of the target (the dispatcher already set it to dispatch time + interval) and `attempt < max_attempts`; no sleeping in a job. A retryable attempt that will be retried is a run with status `retrying` and changes nothing on the target (no `applied_seq`, no failure count). The final failure takes the 2.14 failure path (`consecutive_failures` +1 under the fence). The fence is unchanged: a retry whose `dispatch_seq` is not newer than `applied_seq` is `superseded` before any call.
- **Penalty and bucket:** a `throttled` answer sets a per-Data-Source penalty until `now + min(cap, Retry-After)` (needs a valid `cap`, else ignored) and empties the token bucket. `budgets.max_fetch_rate_per_data_source` is whole calls per minute (capacity = that number, refill rate/60 per second); unset or malformed = no bucket. Every call (first attempt or retry) takes one token before sending. A denied call (penalty, empty bucket, or concurrency) is requeued with the wait the governor reports (concurrency waits `retry.base`) if it fits before `next_due_at` and `retry` is valid, else recorded `skipped` with `error_code` `rate-limited`; it never counts as an attempt or a failure.
- **Concurrency and fairness:** new settings outside `tunables` (`dashflow.fetch.*`, `pending_input`, unset = off): `data_source_concurrency` (max in-flight calls per Data Source, a Valkey counter with a lease TTL of `guards.platform_timeout_ceiling` when set, else released in `finally` only) and `workspace_fair_share` (max targets one dispatch tick takes per Workspace, applied in the `DispatchDueSyncs` select by `row_number() over (partition by workspace_id order by next_due_at, id)`, inside the existing `BATCH`), so one Workspace cannot fill a tick.
- **Breaker:** per `(workspace_id, data_source_id)`, state in the Valkey `queue` store (noeviction) behind `Connector\Contracts\SourceGovernor`, changed only by atomic Lua. Counts consecutive call outcomes (attempts, not runs): `transient` and `ambiguous` add one; a 2xx/304, `configuration` or `data` answer (the source responded) resets to 0; `throttled` neither counts nor resets. At `circuit_breaker.failure_count` it opens (both `failure_count` and `cool_down` must be valid whole numbers, else the breaker is inert). While open every call is skipped: run `skipped`, `error_code` `circuit-open`, target changed only by `applied_seq` under the fence (no failure count, no `last_checked_at`). After `cool_down`, one caller wins the half-open probe (a lease expiring after `cool_down`); the probe is one call with no retries: success or a responded answer closes, `transient`/`ambiguous` reopens for another cool-down. Valkey errors fail open (admit, log `connector.governor.unavailable`, no value in it); Postgres state is unaffected.
- **Job:** `FetchJob` gains `attempt` (default 1, in the signed payload, `referencedIds` unchanged) and a `failed()` handler that, under the fence, records a `failed` run (`job-failed`, the request ID) and counts one failure, so a killed or exceptional job is visible. Calls run as today (inside the workspace transaction); no new timeout number is invented.
- **Runs and metrics:** `sync_runs` gains `attempt smallint` and statuses `retrying`, `skipped`; one row per attempt or skip, never a URL query, value, header or secret. Metrics (workspace label only): `dashflow.connector.fetch_retried`, `fetch_skipped` (also per reason `circuit_open`, `rate_limited`), `circuit_opened`, `circuit_closed`, `throttled`, plus the existing `dashflow.ingestion.fetch_*`.

**Never:** Health status, probe, thresholds or Admin UI for failures (2.18), hot/cold demand, hot-key or cold-key budgets and Live (2.19), sync groups (2.20), changing `FetchTransport`, `CurlEgressTransport`, the Endpoint test/Fetch-as-user paths or the 401 OAuth refresh, retiring targets (deferred), a retry for an Endpoint test, a Postgres lock held across a call, `json_decode` in Ingestion, any invented default for a retry, breaker, bucket, concurrency or fairness number.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Transient GET | Timeout/5xx/408, attempts left, delay fits | `retrying` run, delayed `FetchJob` attempt+1, target untouched; a later 2xx commits as 2.14 | N/A |
| Exhausted | Last attempt fails, or delay passes `next_due_at` | `failed` run, `consecutive_failures`+1, last good payload kept | Run code |
| Throttled | 429/503 + `Retry-After: 9999`, cap 60 | Delay and penalty 60 s, bucket emptied, other targets of the source wait | N/A |
| Other 4xx | 404 | No retry; `failed`, `config-error`, status 404; no breaker count | N/A |
| Bad body | Non-JSON, over limit, too deep | No retry; payload kept; breaker reset | Existing code |
| POST timeout | Read-only POST, timeout after send | `failed`, not retried; counts for the breaker | `ambiguous` |
| POST connect error | DNS/connect failure | Retried like a GET | N/A |
| Breaker open | `failure_count` reached | Calls to that source `skipped` `circuit-open` until cool-down | Run row |
| Probe | Cool-down over, two jobs race | One probe; success closes, failure reopens; other `skipped` | N/A |
| Rate limited | Bucket empty, fits before due | Requeued with wait; else `skipped` `rate-limited` | N/A |
| Fair share | A Workspace has more due targets than `workspace_fair_share` | Tick takes the cap per Workspace; others still dispatch | N/A |
| Late retry | Newer dispatch already applied | `superseded`, no call | N/A |
| Killed job | Worker killed or job throws | `failed()` records `failed`/`job-failed` | Run row |
| Unset tunables | Retry, breaker, bucket, concurrency unset | Behaves exactly as 2.15 | N/A |
| Valkey down | Governor errors | Calls admitted, log line, no crash | Fail open |
| Canary | Secret in header/value/`Retry-After` | Absent from runs, logs, metrics | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Ingestion/Application/{FetchSyncTarget,FetchJob,DispatchDueSyncs}.php`, `Infrastructure/SyncSettings.php` -- admission, retry requeue, skip/failure paths under the fence; `attempt` and `failed()`; fair-share select; retry/breaker/bucket settings readers
- `app/Modules/Connector/Application/{FetchEndpoint,EndpointFetchLadder,FetchFailure}.php`, `Contracts/{EndpointFetchResult,ConnectionTestCode,SyncRunLog}.php`, `Infrastructure/{RecordSyncRun,CurlEgressTransport(read only)}.php` -- classification (`FailureClass`, `retryAfterSeconds` from `response->headers['retry-after']`), `attempt` on runs; errno table is in the ladder
- New `Connector\Contracts\{FailureClass,SourceGovernor,Admission,Jitter}` and `Infrastructure/{ValkeySourceGovernor,Jitter}` (Lua scripts) -- `admit`, `record(outcome)`, `penalize`
- `database/migrations/2026_10_10_100000_create_sync_targets_and_raw_tier.php`, `2026_10_11_100000_add_conditional_state_to_sync_targets.php` -- pattern for a new migration: `sync_runs.attempt`, status CHECK (`retrying`, `skipped`)
- `config/dashflow.php` (`tunables.retry`, `circuit_breaker`, `budgets`, `guards` already exist; new `fetch` block like `egress`), `config/horizon.php`, `app/Providers/AppServiceProvider.php`, `app/Support/Observability/MetricEmitter.php` -- settings, bindings, metrics
- `tests/{Unit,Database,Security,Architecture}/`, `tests/Database/ScheduledFetchTest.php` -- ladder classes, governor Lua, matrix rows, canary, `dependencies.php` (Ingestion -> Connector contract only)

## Tasks & Acceptance

**Execution:**
- [x] migration, `dependencies.php` -- `sync_runs.attempt`, statuses `retrying`/`skipped`
- [x] `FailureClass`, ladder and `FetchEndpoint`, `EndpointFetchResult` -- classes, POST ambiguity, `Retry-After` parsing
- [x] `SourceGovernor` contract and Valkey adapter with Lua (breaker, probe lease, penalty, bucket, concurrency), `Jitter`, config and `SyncSettings` readers -- inert when unset
- [x] `FetchSyncTarget`, `FetchJob`, `DispatchDueSyncs` -- admission, retry, skip, `failed()`, fair share, metrics
- [x] tests: every matrix row (two-job probe race, fence with a retry, seeded jitter bounds, `Retry-After` forms, Valkey-down), canary scan

**Acceptance Criteria:**
- Given retry, breaker and bucket settings and a source that fails twice then answers, when a target is due, then three runs exist (`retrying`, `retrying`, `succeeded`) within one interval and the payload commits once.
- Given a failing source and `failure_count` reached, when targets of that Data Source are due, then no call is made until the cool-down, `skipped` runs record it, and one probe decides.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Final failure lost when requeue fails (run id reused) | high | patch (fixed) | Retrying row gets its own id; test added |
| Late non-probe success closed an open breaker | high | patch (fixed) | Only the probe closes; Lua and fake aligned; test |
| Concurrency lease re-extended on every admit | medium | patch (fixed) | Lease set only when key has no TTL |
| Probe lease not returned when fetcher throws | medium | patch (fixed) | Given back in fetchTarget; test |
| FetchJob attempt readonly breaks pre-deploy queued jobs | medium | patch (fixed) | Default of 1; unserialize test |
| POST 408 and errno 35 contradicted the spec | medium | patch (fixed) | Aligned code and tests |
| Retry-After 0 gave immediate retry and no penalty | low | patch (fixed) | Minimum of 1 second |
| Denial requeues counted as retries | low | patch (fixed) | Separate fetch_requeued metric |
| Test built EgressTransportFailed with errno null | low | patch (fixed) | Real errno passed |
| Real Lua scripts never run in the suite | medium | defer | Test image has no Valkey/phpredis; scripts hand-verified against Valkey 9 |
| Killed job leaves slot or probe lease until lease TTL | medium | defer | Covered by TTLs; release in jobFailed would double-release |
| Retries re-run the whole sync group | low | defer | Group equals one target until 2.20 |
| Skipped runs unbounded while breaker open; governor active when only retry.cap set | low | defer | Health and budgets are 2.18/2.19 |
| Migration down leaves NOT VALID constraint; fair-share window counts locked rows; stale breaker state; denial-without-run-row; other minor | low | rejected | Negligible or spec-chosen |

## Design Notes

Decided here: **Retries are requeued jobs, not sleeps**, so a worker is never parked and 2.14's fence needs no change; the deadline is the `next_due_at` the dispatcher already wrote. **Governor in Valkey, not Postgres**: a row lock would be held for a whole call because `FetchJob` runs inside the workspace transaction (deferred item); state loss only re-closes the breaker, and AD-26 already treats Valkey as an optimisation. **Breaker counts calls** so a failing source sees fewer calls; a 429 is the source asking us to slow down, not failing, so it feeds the penalty, not the breaker. **Bucket unit** (calls per minute) reuses the existing `budgets.max_fetch_rate_per_data_source`; 2.19 may add hot/cold budgets beside it. Concurrency and fair-share are new `pending_input` settings outside the closed AR-57 list, as `egress` is. Permanent failures re-running each interval stay deferred (no retire event).

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
