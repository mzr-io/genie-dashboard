---
title: 'Test a Data Source connection'
type: 'feature'
created: '2026-10-08'
status: 'done'
baseline_commit: '7bba3f978d01f97fe01d14db30227cf44c22c341'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-4-add-authentication-and-write-only-secrets-to-a-data-source.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-2-guard-every-outbound-url-with-egressguard-and-operator-private-range-grants.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-11-record-audit-events-and-publish-outbox-events-in-the-same-tr.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** An Admin cannot tell whether a Data Source's URL, allowlist entry and credentials work until something later fails, and no code yet runs a source call off the web tier (Story 2.5; FR-12, FR-10, NFR-4, NFR-1, AR-8, AR-31, AR-26, AR-35, AR-45, AR-27, AD-28, UX-DR-135, 207, 22, 70, 276, 282). The Story 2.5 section of `epics.md` holds the acceptance criteria.

**Approach:** Introduce the `Platform\Operations` kernel (an initiator-owned asynchronous job with a handler registry), register a Connector `connection_test` kind that runs on `worker-connector` queue `fetch-interactive` through a new `FetchTransport` (`direct` driver over `EgressGuard` and the curl transport), carry unsaved typed secrets as transient rows owned by the Operation, record every attempt in a new partitioned `sync_runs` table, rate-limit per membership and Workspace, and add the Test connection control and error card to the Data Source form.

## Boundaries & Constraints

**Always:** Kernel `Platform\Operations` (`app/Platform/Operations`, calls no module): table `operations` (tenant: UUIDv7 key, non-null `workspace_id`, `kind`, `requester_membership_id`, `subject_type`, `subject_id` nullable, `subject_revision` nullable, `status` in `queued`/`running`/`succeeded`/`failed`/`stale`/`expired`, `expires_at`, `request_id`, `result` jsonb holding only a small summary (never a body, secret, ciphertext or URL query), timestamps) with `ENABLE` and `FORCE ROW LEVEL SECURITY`; an `OperationHandler` interface and registry that modules register kinds in (a kind declares its queue); `Operations::enqueue`, `status` and `complete`; a signed workspace-scoped job (`RunsInWorkspace`, queue from the kind) that runs the handler, stores the summary, deletes the Operation's transient secrets in a `finally`, and emits `platform.operation.completed` (IDs and enums only) through the Outbox in the same transaction as the status change. Results are visible only to the requesting membership: `GET /api/v1/operations/{operation}` returns 404 with no body to anyone else (another member or Workspace) and the summary to the requester. Tables `operations` (Platform) and `sync_runs` are in the ownership list; `sync_runs` is owned by Connector for now (move it from the Ingestion entry) and is the first partitioned table: `PARTITION BY RANGE (started_at)` monthly with a `DEFAULT` partition, primary key including the partition key, non-null `workspace_id`, columns for kind, sanitised URL template (no query, userinfo or fragment), status, HTTP status, latency ms, bytes, error code, `request_id` and Data Source id, never a body or secret; RLS enabled and forced with the standard policy on the parent and on every partition; `app` SELECT, INSERT and UPDATE; a documented, idempotent partition-ensure step (command plus the migration creating the current and next months) so later stories and Epic 9 can schedule it; the leak, restore and migration-guard tests are extended for partitions. The Connector kind `connection_test` runs only on `worker-connector`, queue `fetch-interactive`, as the requester's Workspace. `FetchTransport` (Connector contract, `direct` driver `DirectFetchTransport`) takes a `FetchRequest` (secret refs and a credential scheme only), resolves each secret at egress through the new `SecretVault::resolve(SecretRef, SecretContext)` (reads the row, checks the key version or fingerprint against the mounted key and raises a distinct `KeyringMismatch`, then opens it with the context check), applies the credential scheme, and sends through `EgressGuard` and `CurlEgressTransport`; no code outside it builds an outbound request (the egress-bypass architecture test stays green). The test request is a `GET` to the Data Source Base URL with its default headers and credentials, success is HTTP 2xx, the response body is read only for its byte count and discarded, and JSON checks are not part of this story. `POST /api/v1/admin/data-sources/test-connection` (permission `data_sources.manage`, in the Story 1.19 map and matrix, `202` with the Operation id) accepts the form fields (the same validation as register and update minus the unique-name rule and the revision) and optional `data_source_id`; with a saved id, stored secrets are used for any slot not supplied, and typed secret values are accepted (this route does not use `RejectsSecretValues`). Typed secrets are sealed through the vault into transient `secrets` rows (expand-only migration: `data_source_id` nullable, new `operation_id`, `ephemeral` and `expires_at` columns, a unique `(operation_id, slot)` for ephemeral rows, a `SECURITY DEFINER` removal function for an Operation's rows, expiry enforced on read and a purge command for expired rows) that are deleted at completion and never autosaved, never written to `data_sources`. The request must pass `EgressGuard` for every hop; a block is audited as `connector.egress.blocked` and counts toward the `connector.ssrf_blocked` alert (the guard already does both). Errors collapse to the user codes `host-not-allowlisted`, `blocked-address` and `fetch-failed` (timeout, TLS failure, 401/403/5xx, transport error); detail (status, request ID, host, reason code) goes to the requester's summary and operator logs only, never the resolved address. Every attempt, success or failure, writes one `sync_runs` row (kind `connection_test`) and the Operation summary carries `{ok, status, latency_ms, code, host, request_id}`. Rate limits are per membership and per Workspace from settings flagged `pending_input` (outside the AR-57 tunable list, no defaults, documented in `.env.example` and README; unset means not limited); over a limit the API answers 429 with `retry_after` in the error envelope and enqueues nothing. A request without the permission is 403 with a security event. Saving never requires a test. The form gets a Test connection button (gated and `aria-disabled` with its reason while a test runs or after a 429), a polite "Testing…" status, polling of the Operation until it ends, `test-ok` ("Connected · 200 · 184 ms" with real values) on success, and a `fetch-error-card` on failure with the user message, a "Technical details" `aria-expanded` disclosure (status, request ID, host, collapsed reason code), Retry and "Copy request ID", and focus moved to its title. All strings come from the catalogue or `labels.ts`.

**Never:** Storing bodies, secrets or query strings in `operations`, `sync_runs`, logs or audit; the resolved address in any Admin-visible text; running a test on `web`; JSON parsing or Endpoint calls (Stories 2.6 and 2.9); OAuth token fetching (Story 2.7); autosaving typed secrets; showing an Operation to anyone but its requester; inventing default limit values.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Success | Valid form, 2xx | `test-ok` with real status and latency; `platform.operation.completed`; `sync_runs` row | N/A |
| Unsaved with secrets | Typed secret, no id | Transient row used, deleted at completion; nothing autosaved | Expired row is ignored and purged |
| Saved source | Stored secrets, id given | Stored secrets used for untyped slots | N/A |
| Not allowlisted | Host absent | Card with `host-not-allowlisted`; `connector.egress.blocked` audited; counted for the alert | N/A |
| Blocked address | Resolves to a blocked class | Card with `blocked-address`; audited | No address shown |
| Failure | Timeout, TLS, 401, 403, 5xx | Card with `fetch-failed` and technical details | Detail only in summary and operator logs |
| Rate limit | Over membership or Workspace limit | 429 with `retry_after`; nothing enqueued; button `aria-disabled` with reason | N/A |
| No permission | No `data_sources.manage` | 403; security event | Story 1.19 |
| Other member | Different member asks for my Operation | 404, no body | N/A |
| Skip test | Save without testing | Save succeeds | N/A |
| Attempt record | Any attempt | One `sync_runs` row with sanitised template and no body or secret | N/A |
| Key problem | Key unset or mismatched on the worker | `fetch-failed` for the user; reason code in details | Operator log |

</frozen-after-approval>

## Code Map

- `app/Modules/Connector/Contracts/{EgressTransport,EgressRequest,EgressResponse,EgressGuard,EgressReason,FetchRequest,SecretRef,SecretContext,SecretVault,CredentialScheme,ErrorCode}.php`, `Infrastructure/{CurlEgressTransport,NativeCurlClient,LocalSecretVault}.php`, `Application/{GuardEgressUrl,RecordEgressBlock,ManageDataSources,ValidateDataSourceInput}.php` -- the transport, guard, vault and validation to build `FetchTransport` and the test on; `tests/Architecture/EgressBypassTest.php` -- stays green
- `app/Platform/Tenancy/{WorkspaceTransaction,WorkspaceScopedJob,RunsInWorkspace}.php`, `app/Platform/Outbox/Outbox.php`, `app/Platform/Audit/{Audit,AuditAction}.php`, `app/Support/Queue/{JobSigner,SignedRedisQueue}.php`, `tests/Database/WorkspaceJobTest.php`, `tests/Database/Fixtures/TenantJob.php` -- job, outbox and audit patterns for the Operation job and event
- `config/horizon.php` (`supervisor-connector`, `fetch-interactive`), `docker/entrypoint.sh`, `compose.yaml` (`worker-connector`), `config/dashflow.php` (settings outside `tunables`: `egress`, `secrets`), `app/Modules/Connector/Infrastructure/AlertRate.php` -- queue, settings and rate patterns
- `database/migrations/2026_10_07_180000_create_secrets_and_data_source_auth.php`, `docker/postgres/initdb.sh`, `bin/test-restore` (relkind `r`,`p`), `tests/Database/{RowLevelSecurityTest,TenantLeakCoverageTest}.php`, `Support/Cluster.php` (`seedTenantRow`), `tests/Architecture/{dependencies,TableOwnershipTest,MigrationGuardTest}.php` -- expand-only migrations, the first partitioned table, seeders and ownership (`operations` is Platform, `sync_runs` moves to Connector)
- `routes/api.php`, `app/Http/Navigation/ShellNavigation.php`, `app/Http/Controllers/Admin/DataSourceController.php`, `app/Http/Requests/Admin/DataSourceRequest.php`, `tests/Database/AdminAccessTest.php`, `tests/Security/CoverageManifestTest.php` -- route, gate and manifest
- `resources/js/pages/admin/DataSourceForm.vue`, `lib/dataSources.ts`, `components/{TechnicalDetails,GatedAction,BlockedReason,SecretField}.vue`, `lib/{announce,clipboard}.ts`, `locales/{en,labels}.ts` (`test-ok`, `fetch-failed`, `host-not-allowlisted`, `blocked-address`) -- UI; `tests/js/technical-details.test.ts`, `data-sources.test.ts`

## Tasks & Acceptance

**Execution:**
- [x] migrations (`operations`, `sync_runs` partitioned with RLS on every partition, transient `secrets` columns and removal function), kernel `Platform\Operations` (registry, handler, enqueue, status, signed job, completion event), ownership, seeders and partition-aware tests -- Operations and `sync_runs`
- [x] `FetchTransport` and `DirectFetchTransport`, `SecretVault::resolve` with key-version check, the `connection_test` handler, transient secret handling and purge, `sync_runs` recording, user-code collapsing, rate limits and settings -- connection test
- [x] controller, request, routes, `ShellNavigation`, `GET /api/v1/operations/{operation}`, error codes -- API and gate
- [x] `resources/js` (Test connection button and states, polling, `fetch-error-card`, technical details extension), `labels.ts` -- UI
- [x] `tests/Unit`, `tests/Database`, `tests/Feature`, `tests/Architecture`, `tests/Security`, `tests/js`, `README.md` -- every matrix row, canary scan over `operations` and `sync_runs`, partition leak test

**Acceptance Criteria:**
- Given an Admin with `data_sources.manage`, when they test a saved or unsaved form, then a `connection_test` Operation runs on `worker-connector` through the guard, only they can read its summary, a `sync_runs` row records the attempt without body or secret, and typed secrets are deleted at completion.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

- Job input: the unsaved form's non-secret fields (Base URL, auth type, API key name and placement, default headers with secret ones as names only, timeout, saved Data Source id) travel in the signed `RunOperation` payload, because `operations` has no input column and a typed secret must not travel there. Secrets are transient `secrets` rows sealed for the Operation (the Operation ID is the envelope's context owner).
- Operation status maps the verdict: a passing test is `succeeded`, a failing one `failed` (summary `ok` false); the Operation and its single transaction are one unit, so `running` is never visible to a poller.
- `FetchRequest` is now version 2 (plain default headers, API key name and placement, timeout, method; `dataSourceId` and `endpointId` nullable for a form that is not saved and a call that has no Endpoint).
- Rate limits live under `dashflow.connection_test` (`DASHFLOW_CONNECTION_TEST_MEMBERSHIP_LIMIT`, `_WORKSPACE_LIMIT`, `_WINDOW`); no window means nothing is limited.
- `dashflow:secrets:purge-expired` (every five minutes) and `dashflow:partitions:ensure` (daily) are scheduled in `routes/console.php`.

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| A handler DB error aborts the single Workspace transaction, so cleanup and the final status fail and the Operation stays queued; a `sync_runs` insert failure discards the real result | high | patch | `Operations::run` calls the handler with no savepoint; `RunConnectionTest::handle` writes `sync_runs` unguarded. |
| Dispatch failure and unknown-kind paths leave typed secrets sealed; the purge and partition-ensure commands are never scheduled, so expiry never deletes and later months fall into the DEFAULT partition | high | patch | `Operations::dispatch` completes `failed` without cleanup; both commands are noted as "not scheduled yet"; the ensure function only warns and skips a month whose rows are in DEFAULT. |
| Rate-limit check and hit are separate, so a burst exceeds the limit | medium | patch | `StartConnectionTest::assertWithinLimits` calls `tooManyAttempts` before `hit`. |
| Route-throttle 429 shows as a failed connection; poll 429 aborts; poll 404 flips the form to missing; no polling deadline; a stale result appears after an edit | medium | patch | `testConnection()` handles 429 only with `retry_after`; `pollOperation` retries to the 10-minute lifetime; the snapshot watch skips clearing while testing. |
| `secret: false` header treated as secret in `FetchRequest::for`; empty typed secret passes `isset`; worker context request ID overrides the stored one; `down()` fails with ephemeral rows | medium | patch | `isset` versus `=== true`; `StartConnectionTest` uses `isset`; `RunConnectionTest` prefers `context->requestId()`; `down()` sets NOT NULL on a nullable column holding nulls. |
| Source timeout cap (`CURLOPT_TIMEOUT` min) and the dispatch-failure path have no test | medium | patch | Changing `min` to `max` or removing the dispatch catch leaves every test passing. |
| Handler and network call run inside the Workspace transaction, so `running` is never observable and a row lock is held for the call | medium | defer | Inherent in the Epic 1 job pattern (`RunsInWorkspace` wraps the job); committing `running` first and running the call outside a transaction is a larger kernel change for a later Operations story. |
| `connector_purge_expired_secrets()` is executable by `app` across Workspaces; `app` has UPDATE on append-only `sync_runs`; no sweep for old `operations` rows | medium | defer | Needs the `maintenance` role path and retention policy (Epic 9); scheduling uses `app` until then. |
| No response-size cap and no timeout when neither is configured | medium | defer | The size ceiling belongs to Story 2.6 (already deferred); timeouts are `pending_input` by rule. |
| Partition guard is textual; concurrent ensure race; expired queued Operation emits no completed event; `to_char` timezone; resolve before key lookup order; `FetchErrorCard` glyph regex; `stale` and `subject_revision` unused; URL parse outside try; partition ensure message | low | rejected | Cosmetic or unlikely; the base URL is validated before enqueue; `stale` is reserved for Stories that pin a revision. |

## Design Notes

Why the Operation stores a small summary: AD-28 forbids bodies in PostgreSQL but a result of `{ok, status, latency, code, host}` is not a body, and it spares a handler hand-off through Valkey that the first kind does not need; the registry still lets later kinds (Fetch sample, Validate) use the encrypted-blob route. `sync_runs` is created here because the first outbound attempt is a connection test; it goes to Connector because Ingestion does not exist yet and Connector writes it, and the partition-ensure step is idempotent so a later maintenance story only schedules it. Unset rate-limit settings mean unlimited rather than an invented number, matching the rule for every other `pending_input` setting.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite and the restore check
- `bin/tools npm run build` -- expected: exit 0
