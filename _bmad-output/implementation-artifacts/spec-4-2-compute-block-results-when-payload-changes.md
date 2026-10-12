---
title: '4-2 Compute Block Results when payload changes'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: 'c062a2d'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Dashboards must never wait on a source API, but nothing turns a stored payload into display data. A published Block has no precomputed, versioned, race-safe result to read.

**Approach:** Add the `Results` module. `ingestion.payload.changed` queues a signed, IDs-only compute job on `worker-compute` that runs each watching published version's frozen Query Plan over the target's current payload (lossless) and stores a `BlockResult` in the Valkey `cache` store by Lua compare-and-set on `payload_seq`, under `res:{ws}:{block_version}:{primary_fk}:{compute_ctx}`, always with a TTL. Drift is reported per slot, and a sweep repairs lost events.

## Boundaries & Constraints

**Always:** Measures are decimal strings; no `json_decode` in Results. A missing or mistyped slot is `unavailable` with a reason, never 0/NaN/blank. State precedence access_removed > unpublished > error > pending > unavailable > empty > ok; `partial` derived; `loading` never stored. Cache and queue stores are separate; every cache key has a TTL. Job payload carries IDs only. The compute path never calls a source.

**Never:** No dashboards, reads or freshness (4.3, 4.4, 4.5); no Subscribe calls or viewing (4.3); no comparison/sync-group mixing (Epic 5); no precompute at publish; no incident or notification handling for `slot_failed` (Epic 8).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Changed payload | published version with a primary subscription | `BlockResult` under the contract key with TTL, full DTO, exact decimals | N/A |
| Context differs | another `compute_ctx` | another key; old result never served for it | N/A |
| Out of order | seq 8 finishes after seq 9 | seq 9 kept | CAS returns false |
| Duplicate / flush | same seq again; cache flushed | idempotent; sweep recomputes | N/A |
| Slot missing | mapped path absent or mistyped | slot `unavailable` + reason, event with count | rest of block renders |
| Plan fails | invalid plan or guard exceeded | `error` state stored | other versions still compute |
| Not published | `prepared` or other | nothing computed | N/A |
| TTL unset | `DASHFLOW_RESULT_TTL` unset | job fails naming it, nothing stored | `ResultSettingNotConfigured` |

</frozen-after-approval>

## Code Map

- `app/Modules/Results/**` -- new module (edges and tables already declared in `tests/Architecture/dependencies.php`)
- `app/Modules/Ingestion/{Contracts,Application}/Compute*.php`, `ReadComputeSources.php` -- read of target, payload id/seq and primary subscriptions
- `app/Modules/Blocks/{Contracts,Application}/PublishedConfig*.php`, `ReadPublishedConfigs.php` -- frozen config for compute
- `app/Providers/AppServiceProvider.php` -- bindings and consumer registration; `routes/console.php` -- sweep schedule
- `config/dashflow.php` (`results.ttl`), `compose.test.yaml` (Valkey), `tests/Database/Support/RespClient.php`

## Tasks & Acceptance

**Execution:**
- [x] Results contracts: `BlockResult`, `ResultState`, `ComputeContext`, `ResultKey`, `ResultStore`
- [x] `ValkeyResultStore` with Lua compare-and-set and TTL; `LaravelValkeyCommands`
- [x] `ComputeResults`, `ComputeResultsJob`, `ComputeOnPayloadChanged`, `SweepStaleResults(+Job)`
- [x] Ingestion `ComputeSources` and Blocks `PublishedConfigs` contracts
- [x] `results.ttl` setting, `results.mapping.slot_failed` action, test Valkey and RESP client
- [x] Tests: unit (DTO, context, key, precedence), Database against real Valkey (CAS, races, flush) and PostgreSQL (compute, drift, sweep, consumer)

**Acceptance Criteria:**
- Given the I/O matrix, when payloads change, then each row holds against real PostgreSQL and Valkey.
- Given `app/Modules/Results`, when the architecture tests run, then `json_decode` and cross-module table access are absent.

## Implementation Notes

- Compute follows primary subscriptions (`sync_subscriptions`), not a guessed version-to-target match: a representative target is fetched with the Admin's test dates, so it is not the data of the Block default period. Nothing subscribes until Story 4.3 (viewing), so results appear once 4.3 and 4.5 exist.
- `compute_ctx` is the subscriber's stored string when there is one, else `ComputeContext::of(...)` from the subscription's period, the Workspace tz and `workspace_settings.revision`. Readers must derive the same value.
- The consecutive-failure count is a TTL'd counter in the `cache` store, not `mapping_health_incidents` (Epic 8 owns that table's meaning). It resets when the slot computes; a re-run of the same `payload_seq` does not count.
- Drift is judged by the engine's own per-slot `unavailable` reasons (`missing`, `wrong_type`, `not_single_value`); `config.fields` is not compared a second time.
- `stale`/`refreshing` are stored false and set by the reader (4.3). `generation_id` is the payload id; group generations arrive with comparisons (Epic 5).
- The tools image has no Redis extension; the Lua script is tested through a small RESP client, and the phpredis adapter was smoke-tested once in the app image against the same Valkey.

## Verification

- SQLite suite 2066 passed; Database suite against PostgreSQL 18, PgBouncer and Valkey 9: 1694 passed. `pint`, `phpstan` clean.
- The phpredis adapter was run once in the app image against the test Valkey: write, rejected older write, read back with exact decimals, TTL set.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Nothing subscribes yet, so no result is computed in a running system | low | defer | By design: subscriptions come from viewing (4.3); the sweep and consumer are exercised with real subscriptions. |
| Failure counter in cache can be evicted and restart at 1 | low | defer | It is a signal for Epic 8, not a record; documented in Implementation Notes. |
| Comparison generations and precompute at publish not used | medium | defer | Out of scope for kpi-card (no comparison query); logged in deferred-work.md. |
| `compute_ctx` derived in two places (compute and sweep) | false | none | Both call `ResultContexts::of`. |
