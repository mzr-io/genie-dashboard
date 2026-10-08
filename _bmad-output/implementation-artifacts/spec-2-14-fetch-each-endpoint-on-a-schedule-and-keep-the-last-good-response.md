---
title: 'Fetch each Endpoint on a schedule and keep the last good response'
type: 'feature'
created: '2026-10-08'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-10-test-an-endpoint-and-see-the-sample-response.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-13-bind-endpoint-parameters-and-headers-to-user-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Endpoints are only called when an Admin tests them. Nothing computes a Fetch Key, schedules a call, or keeps the latest good response, so data would vanish whenever the source API is slow or down (Story 2.14; FR-13, NFR-1, NFR-3, NFR-5, NFR-12, NFR-13, AR-2, AR-5, AR-10, AR-13, AR-14, AR-33, AR-36, AR-43, AR-31, AD-7, AD-9, AD-26, AD-29). The Story 2.14 section of `epics.md` holds the acceptance criteria.

**Approach:** The new Ingestion and RawStore modules: a `FetchKeyResolver` (the only owner of `fk1:` keys), one representative sync target per shared-context Endpoint revision, a `system`-role dispatcher that fences each run with `dispatch_seq`, a `fetch-scheduled` job on `worker-connector` that stores each good body as exact bytes plus an immutable observation, and the "Last success" line in the Admin UI.

## Boundaries & Constraints

**Always:**
- **Key:** `fetch_key = "fk1:" + hex(sha256(JCS({v:1, workspace_id, endpoint_revision_id, data_source_revision, params, ctx})))`, computed only by `Ingestion\Contracts\FetchKeyResolver` (RFC 8785 over strings, objects and the integer `v`, so no float is ever hashed). `params` is `{name: {t, v}}` with `t` in `string|number|bool|date|datetime` and `v` always a JSON string (a number is its lexeme; a bool is `true`/`false`; a date is workspace-local `YYYY-MM-DD`; a datetime is UTC `...Z`); an absent binding is omitted; a header is named `header:{name}`. `ctx` is `"shared"`, or `hex(HMAC-SHA256(digest_key_ws_v, "bound|"+JCS(attrs)))` where `attrs` maps each user-binding reference to its value and gains `membership_id` when `scope_by_caller` is set; `digest_key_ws_v` is HKDF-SHA256 of the `digest` key with info `fkctx|{workspace_id}|v1`, behind a port so tests inject a key. A missing, null, empty or non-string bound value, or an unusable `digest` key, produces no key and the reason `access.context_missing` (never a value in the result); the caller sends nothing.
- **Registration:** the Connector emits outbox events `connector.endpoint.created`, `connector.endpoint.revised` and `connector.data_source.updated` (IDs and revision numbers only) in the transaction of the save. An Ingestion `OutboxConsumer` registers, idempotently, one target per current Endpoint revision of the Data Source revision for every Endpoint with `requires_user_context` false, and retires the targets of earlier revisions (`next_due_at` null, `retired_at` set; their payloads stay). An Endpoint with `requires_user_context` has no representative target (a per-member target belongs to Epic 3's subscribe); the resolver still supports the bound `ctx`.
- **Test values:** a revision may carry `test_values` (parameter name to ISO `YYYY-MM-DD`), accepted only for date-range and period-bound parameters and checked with the rules of `RenderEndpointRequest`; a fixed parameter keeps its stored value; a user-bound name is a 422 `test_values.{name}`. A target is registered only when every non-user parameter and header has a value; otherwise there is no key, no target, and the Admin sees why.
- **Targets and fence:** `sync_targets` (tenant table, RLS, UUIDv7): fetch key (unique per Workspace), endpoint and revision ids, `data_source_revision`, typed `params`, `sync_group_id` (the target's own id until Story 2.20), `refresh_interval_seconds`, `etag`, `last_modified`, `content_hash`, `current_payload_id`, `payload_seq`, `dispatch_seq`, `applied_seq`, `last_success_at`, `last_checked_at`, `consecutive_failures`, `next_due_at`, `retired_at`. The interval is the smallest value of `tunables.sync.refresh_intervals` (whole seconds, comma-separated); unset or malformed leaves `next_due_at` null (registered, never scheduled), and no number is invented. A new target is due at once.
- **Dispatcher:** the `scheduler` fires `DispatchDueSyncsJob` every `dispatch_tick` (nearest Laravel sub-minute step; unset uses 5 s; `onOneServer`), queued on `maintenance` so it runs on `worker-compute`, the only holder of `DB_SYSTEM_*` (the compose test stays as it is). Under the `system` connection, in one transaction, it selects due unretired targets with `FOR UPDATE SKIP LOCKED` (fixed batch), sets `dispatch_seq = dispatch_seq + 1` and `next_due_at = now() + interval`, and after the commit enqueues `FetchJob(workspace_id, sync_group_id, dispatch_seq)` on `fetch-scheduled`. The role gets column-limited `SELECT` (the dispatch columns) and `UPDATE (dispatch_seq, next_due_at)` with `TO system` policies, never `BYPASSRLS`. A lost job is re-dispatched at the next `next_due_at`.
- **Job:** `FetchJob` is a signed `WorkspaceScopedJob` (`tries` 1; `referencedIds` is `sync_targets => [sync_group_id]`) on `worker-connector`: it re-enters the Workspace and re-reads every ID under RLS, and a mismatch is a hard failure plus a security event (the existing `RunsInWorkspace` behaviour). It asks Connector for the fetch through a new `Connector\Contracts\EndpointFetcher` (render from the target's revision and `params`, `FetchTransport`, guard, pagination and the Story 2.10 error ladder, shared with `RunSampleFetch` by extraction with no behaviour change) and never decodes JSON outside the lossless parser. POST is allowed only for read-only Endpoints; the Idempotency-Key is the run id.
- **Commit:** a good 2xx JSON body commits in one transaction: a guarded `UPDATE sync_targets ... WHERE id = ? AND applied_seq < :dispatch_seq` (zero rows is `superseded`: nothing else changes), then `raw_bodies` (RawStore; `bytea`, lz4, content-addressed `(workspace_id, sync_target_id, content_hash)` with `content_hash` the sha256 of the exact stored bytes, never `jsonb`; a paged call stores the merged document the transport returns), an immutable `raw_observations` row (`seq` = the new `payload_seq`), `current_payload_id`, `payload_seq`, `last_success_at`, `last_checked_at`, `consecutive_failures` 0, and the outbox event `ingestion.payload.changed` (IDs and sequences only; new `AuditAction` case). A failed run (any ladder code, a non-JSON body, a limit) changes only `last_checked_at`, `consecutive_failures` and `applied_seq` under the same guard: the last good payload stays and nothing is truncated. Story 2.15 later skips the event for unchanged bodies; here every success is a change.
- **Runs:** every attempt is one `sync_runs` row (kind `scheduled_fetch`, status `succeeded|failed|superseded`, the sanitised URL template, the parameter names, HTTP status, latency, bytes, error code, request ID, target id, `dispatch_seq`); never a query string, value, header or secret. `sync_runs` stays owned by Connector; Ingestion writes through a new Connector contract. Spans, logs and the `dashflow.ingestion.*` metrics carry `request_id` and `workspace_id` only.
- **Raw tier:** `raw_observations` is partitioned by month like `sync_runs` (ensure function, the `dashflow:partitions:ensure` command extended); `app` gets `SELECT`/`INSERT` only on both raw tables; only `RawStore` writes them, only from a scheduled run of an existing target (a pasted or draft sample has no target and can never enter).
- **UI:** the Endpoint row on the Data Source detail shows "Last success {time}" (via `Ingestion\Contracts\SyncStatuses`, composed in the controller), "No successful call yet", or "Not scheduled" with the reason; the form takes test values for date-bound rows. All strings come from the catalogue or `labels.ts`.

**Never:** Conditional requests or hash skipping (2.15), retention and sweeps (2.16), retry, rate limit or circuit breaker (2.17), health (2.18), hot/cold demand and budgets (2.19), sync groups and generations (2.20), `sync_subscriptions`, `PeriodResolver`, per-member targets, the Block or results side, any new tunable beyond reading `refresh_intervals`, a cross-module foreign key, and changing `FetchTransport`, the sample blob path, `endpoint_usage` or the Data Source soft lock.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Register | Shared Endpoint saved, values complete | One target with the `fk1:` key, due now | N/A |
| Revise | Endpoint or Data Source revised | New key and target; old retired, payload kept | N/A |
| Incomplete | A date-bound parameter has no test value | No key, no target; UI says why | No error raised |
| User-bound | `requires_user_context` true | No representative target | N/A |
| Golden vectors | Typed params, absent bindings, key order | Fixed `fk1:` and `ctx` vectors match | CI fails on drift |
| Bound, missing | Bound attribute null, empty or invalid | No key, no fetch | `access.context_missing` |
| Scope flag | `scope_by_caller` with two members, same attributes | Different `ctx` | N/A |
| Tick | Target due | `dispatch_seq`+1, `next_due_at` advanced, `FetchJob` queued once | N/A |
| Success | 2xx JSON | Body, observation, pointer, `payload_seq`, event, "Last success" | N/A |
| Late run | `dispatch_seq` below `applied_seq` | Run `superseded`, nothing changes | N/A |
| Duplicate run | Same `dispatch_seq` twice | One commit, one `superseded` | N/A |
| Lost job | Job never ran | Next due time dispatches again | N/A |
| Source down | Timeout, 5xx, non-JSON, limit exceeded | Failed run, last good payload kept, failures counted | Ladder code in the run |
| Mismatch | Job IDs not visible under RLS | Hard failure, no request | Security event |
| No interval | `refresh_intervals` unset | Target registered, never due | N/A |
| Sample | Pasted sample stored | Never in `raw_bodies` or `raw_observations` | Architecture test |
| Canary | Canary in a value, header or secret | Absent from `sync_runs`, logs, audit, outbox | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Connector/Application/{RunSampleFetch,RenderEndpointRequest,RecordSyncRun,ManageEndpoints,ManageDataSources,ValidateEndpointInput}.php`, `Contracts/{Endpoint,EndpointInput,FetchRequest,FetchResponse,FetchTransport,DataSource}.php`, `Infrastructure/{DirectFetchTransport,ConnectorAuditSerializer}.php` -- render, fetch, error ladder (extract it into the shared `EndpointFetcher`), run log, revision saves (add the outbox emits and `test_values`)
- `database/migrations/2026_10_08_100001_create_sync_runs_partitioned.php`, `2026_10_06_110000_create_audit_and_outbox_tables.php` (the `system` grant pattern), `2026_10_08_130000_create_endpoints.php` -- partition, role and immutability patterns; a new migration adds `sync_targets`, `raw_bodies`, `raw_observations`, the `sync_runs` columns (`sync_target_id`, `dispatch_seq`, `parameter_names`, status `superseded`) and `endpoint_revisions.test_values`
- `app/Platform/{Outbox,Operations,Tenancy,Audit}/`, `app/Platform/Audit/AuditAction.php`, `app/Console/Commands/EnsurePartitionsCommand.php`, `routes/console.php`, `config/{dashflow,horizon}.php`, `app/Providers/AppServiceProvider.php` (`OutboxConsumers::register`), `compose.yaml`, `docker/postgres/initdb.sh` -- consumer, signed job, schedule, queues, role; do not change `WorkspaceTransaction`, the relay or the roles' compose test
- `app/Modules/Access/Infrastructure/SodiumAttributeVault.php` (key-file reading), `Contracts/{UserContext,AttributeVault}` -- the `digest` key source; `scope_by_caller` is read here for the first time; no Access change
- `tests/Architecture/{dependencies.php,TableOwnershipTest,ComposeSystemRoleTest}.php` -- `Ingestion => [Connector, RawStore]` already exists; declare the new tables, keep `json_decode` banned in both modules
- `app/Http/{Controllers/Admin/EndpointController,Resources/EndpointResource}.php`, `resources/js/{components/EndpointForm.vue,pages/admin/DataSourceEndpoints.vue,lib/endpoints.ts,locales/labels.ts}`, `tests/{Unit,Database,Security,js}/` -- API, UI and tests
- New: `app/Modules/Ingestion/{Contracts,Application,Infrastructure}`, `app/Modules/RawStore/{Contracts,Application,Infrastructure}`

## Tasks & Acceptance

**Execution:**
- [x] migration, `dependencies.php` tables, role grants, partition function and `EnsurePartitionsCommand` -- `sync_targets`, raw tables, run columns, `test_values`
- [x] `RawStore` contract and implementation (`put`, `get`) -- exact bytes, content address, immutable observations, partitions
- [x] `FetchKeyResolver`, JCS canonicaliser, `ContextDigest` port and key-file adapter, golden vectors -- the single key owner
- [x] Connector: `EndpointFetcher` (extract from `RunSampleFetch`), `SyncRunLog` contract, `test_values` on `EndpointInput`/`ValidateEndpointInput`/`ManageEndpoints`, outbox emits, audit and outbox actions
- [x] Ingestion: registration consumer, `DispatchDueSyncsJob`, `FetchJob` and handler (fenced commit, failure path, supersede), `SyncStatuses`, `AuditAction::IngestionPayloadChanged`, schedule, config and compose entries
- [x] controller, `EndpointResource`, UI (Last success line, test values, states), `labels.ts`, `README.md`
- [x] `tests/Unit` (vectors, resolver), `tests/Database` (every matrix row, race tests with two connections, lost job, RLS leak, mismatch, role grants), `tests/Architecture`, `tests/Security` (canary scan, no raw write from samples), `tests/js`

**Acceptance Criteria:**
- Given a shared Endpoint with a configured interval and a source that answers, when the scheduler ticks and the worker runs, then `raw_bodies`, `raw_observations`, `current_payload_id`, `payload_seq`, `ingestion.payload.changed` and a `succeeded` run exist, and the Admin sees "Last success {time}".
- Given the source then fails, when later runs happen, then the last good payload still serves as `current_payload_id`, failed runs are recorded and nothing in them holds a query string or secret.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Interval frozen at registration; target never scheduled after tunable set | medium | patch (fixed) | Registration now fills interval/next_due_at on later events; targets still wait for the next Endpoint or Data Source save |
| DispatchDueSyncsJob uniqueness halved the tick | medium | patch (fixed) | Uniqueness dropped; SKIP LOCKED prevents double dispatch |
| Malformed param poisoned the outbox consumer | medium | patch (fixed) | Caught per Endpoint; test added |
| request_id as metric label | low | patch (fixed) | Counters carry workspace_id only |
| Raw tier DELETE/TRUNCATE for app | false | rejected | Already absent via default privileges; assertion added |
| Null error_code on failed run | low | patch (fixed) | Falls back to fetch-failed |
| Late failure, non-read-only POST, store_failed untested | medium | patch (fixed) | Tests added |
| Fetch runs inside open workspace transaction | maybe-false | defer | Same RunsInWorkspace pattern as earlier jobs; needs measuring |
| FetchJob timeout / failed() handler | medium | defer | Killed run leaves no sync_runs row |
| Failures invisible in Admin UI | low | defer | Spec shows last success only; health is a later story |
| Targets of deleted or disabled Endpoints keep dispatching; permanent failures (non-read-only POST) re-run every tick | medium | defer | No retire event exists yet |
| No unique active target per Endpoint; data_source.updated may see partial Endpoint list | maybe-false | defer | Unverified race and paging |
| Raw body copies (hex) use several times body size | low | defer | Memory cost at the response limit |
| Enqueue failure after commit skips an interval | false | rejected | Epic specifies next_due_at advance at dispatch |
| Fixed params typed as string; static test dates; sub-minute tick arms untested; dispatch_tick int<=0; other minor | low | rejected | Negligible or spec-chosen |

## Design Notes

Decided here, not in the epic: **Registration by outbox consumer.** Connector cannot call Ingestion (the graph has no reverse edge), so the targets are a read model fed by events, and a target appears when the relay delivers (within its one-minute schedule). Data Source and Endpoint revisions are both in the key (AD-7), so either change makes a new target and retires the old one. **User-bound Endpoints get no representative target** because "shared context" cannot fetch them; the resolver's bound path is built and vector-tested for Epic 3. **"Test values for fixed params"** is read as values for the date-range and period parameters, which have no stored value; a fixed parameter keeps its own. **Interval:** until Story 2.19 adds demand, every shared target is always due, at the smallest allowed refresh interval; with that pending input unset, nothing runs. **Dispatcher placement:** the scheduler only triggers a job on `worker-compute`, following the outbox relay, so the system credentials stay in one service. `sync_group_id` equals the target id until 2.20, and `params` are stored plain because they are Admin configuration already kept in the revision; encrypted bound context belongs with per-member targets. `sync_runs` stays in Connector (Ingestion writes through a contract) to avoid a table-ownership move. `content_hash` is over exact bytes now; Story 2.15 may redefine it as the canonical hash. No backfill: revisions saved before this story get a target on their next save.

```
fk1:<sha256 hex of JCS({"ctx":"shared","data_source_revision":3,"endpoint_revision_id":"…","params":{"from":{"t":"date","v":"2026-10-01"}},"v":1,"workspace_id":"…"})>
```

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
