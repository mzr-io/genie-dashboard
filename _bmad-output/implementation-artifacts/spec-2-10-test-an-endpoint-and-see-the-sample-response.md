---
title: 'Test an Endpoint and see the Sample Response'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: '453f93c3954ef9addf07a626b903f97e8db1b7a8'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-5-test-a-data-source-connection.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-6-accept-only-json-losslessly-and-within-limits.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-9-register-endpoints-on-a-data-source.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** An Admin can describe an Endpoint but cannot see what it returns, so nobody knows whether it works before Blocks are built on it, and no code builds a real request from an Endpoint's path, parameters, headers and body (Story 2.10; FR-12, FR-11, FR-13, NFR-4, NFR-1, AR-35, AR-45, AR-46, AR-13, AR-40, AD-28, UX-DR-135, 25, 26, 70, 276, 279, 282). The Story 2.10 section of `epics.md` holds the acceptance criteria.

**Approach:** A `sample_fetch` Operation (registered by the Connector module, because no Ingestion module exists yet) that renders the Endpoint's current revision with the Admin's test values, sends it through `FetchTransport` and the guard, and hands the body to the requester as an encrypted, short-lived Valkey blob that is never written to PostgreSQL; the Endpoints page gets a Test endpoint action, a test-values form, a sample viewer and the shared error card.

## Boundaries & Constraints

**Always:** `POST /api/v1/admin/data-sources/{dataSource}/endpoints/{endpoint}/test` (permission `data_sources.manage`, in `ShellNavigation::ADMIN_API_ROUTES` and the Story 1.19 matrix, named `api.admin.data-sources.endpoints.test`, `202` with the Operation id and the Endpoint revision tested) takes `values` (a map of parameter name to test value) and starts a `sample_fetch` Operation on `worker-connector` queue `fetch-interactive` with `subject_type endpoint`, the Endpoint id and the Endpoint `revision` as `subject_revision`. The server, not the client, renders the request from the Endpoint's current revision: every declared parameter needs a value (a fixed parameter's stored fixed value is the default; a date range or period parameter needs an ISO `YYYY-MM-DD` date), an empty required path parameter is refused before anything is queued with a 422 that names the parameter (and the UI shows the Test action `aria-disabled` with that reason), and each value is checked with the same rules as save (`EndpointPath::valueAllowed` for path values, visible ASCII without CR or LF for header values, the Story 2.9 typed JSON positions for a body). `EndpointPath::render` builds the path, query parameters go through one query builder that percent-encodes names and values, header bindings are applied after the Data Source defaults, and a body template is rendered by replacing each whole-value `{"$param": name}` with the typed value; the rendered request is resolved only against the Data Source base URL. `FetchRequest` moves to version 5 and carries the method, the sanitised URL template, parameter names, query pairs, the rendered header pairs and the rendered body (these hold test values and are kept out of every log, audit row, `sync_runs` row and Operation summary: only the template and names are recorded); `DirectFetchTransport` sends the query and body, adds `Accept: application/json` and, for POST, an `Idempotency-Key` header equal to the Operation id, and never retries a POST automatically, including the OAuth 401 refresh-and-retry (a 401 on POST is reported as `auth-failed` without a second send; the rule is documented in the README and resolves the deferred note from Story 2.7). Only `GET` and a `POST` whose current revision has `read_only_query` true are ever sent. The handler `RunSampleFetch` (registered next to the connection test kind with `OperationKinds`) reuses the Story 2.5 to 2.7 error ladder (host not allowlisted, blocked address, not JSON, response too large with `connector.limit_exceeded` and no truncated sample, auth failed, fetch failed) and writes one `sync_runs` row of kind `sample_fetch` per attempt (sanitised template, status, latency, bytes, code, request ID); on success the body is validated as JSON with `LosslessJson` (numbers keep their lexemes) and stored as the sample blob. The blob store `SampleBlobStore` seals the raw body text with libsodium `secretbox` under the 32-byte `data` key from the `key-data` mount (new setting `dashflow.secrets.data_key_path`, default `/run/secrets/key-data`, base64), with the Workspace, Operation, requester membership and Endpoint revision inside the sealed payload and verified on read, in the Valkey cache through `TenantCache` under `sample:{operation_id}` with a TTL equal to the Operation's lifetime; unlike the token cache it fails closed: when the key is unavailable the test fails with the user code `fetch-failed` and reason `blob_unavailable` and nothing is stored. The body is never written to PostgreSQL, `operations`, `sync_runs`, logs, audit or `raw_bodies`. `GET /api/v1/admin/data-sources/{dataSource}/endpoints/{endpoint}/samples/{operation}` (same permission and gate) returns `{status, latency_ms, body, expires_at}` only to the requesting membership of an Operation of this Endpoint that succeeded and has not expired and is not stale, reads and decrypts the blob (the `web` role holds `key-data`), sends `Cache-Control: no-store, private`, and answers a bare 404 with no body to anyone else, another Workspace, an expired, failed or stale Operation and a missing blob. Stale handling: the kernel lets a handler outcome carry the `stale` status; at completion `RunSampleFetch` compares the Endpoint's current `revision` with the Operation's `subject_revision`, and when it moved it stores nothing, deletes any blob and completes the Operation as `stale` with the new revision in the summary; the UI shows it as not current with a Retry. Rate limits for Fetch sample are per membership and per Workspace from `pending_input` settings (`DASHFLOW_SAMPLE_FETCH_MEMBERSHIP_LIMIT`, `DASHFLOW_SAMPLE_FETCH_WORKSPACE_LIMIT`, `DASHFLOW_SAMPLE_FETCH_WINDOW_SECONDS`, outside the AR-57 list, no defaults, unset means not limited, documented in `.env.example`, README and `compose.yaml`), counted before queuing with the same rule as Test connection; over a limit the API answers 429 with `retry_after` in the envelope and a `Retry-After` header and enqueues nothing, and the button is `aria-disabled` with the reason. The test is audited as `connector.endpoint.tested` (new `AuditAction` case and serializer fields: `endpoint_id`, `method`, `endpoint_revision`, never values, path or body) in the same transaction as the enqueue. The Operation summary carries `{ok, status, latency_ms, code, reason, size_bytes, limit_bytes, host, request_id, endpoint_revision}` and the kernel emits `platform.operation.completed` as before. The Endpoints page gets a Test endpoint action per Endpoint, a test-values panel (one labelled input per parameter, with bound date and period parameters taking a date), a polite "Testing…" status, polling of the Operation with the existing helpers, and on success a `JsonSampleViewer` (a mono `<pre>` in a focusable `role="region"` with an accessible name, the status and latency shown, the body exactly as received, a Copy action) and a polite announcement built from a `labels.ts` string "✓ {status} · {ms} ms" (the catalogue `fetch-ok` text needs a record count and path that exist only after Mapping); on failure the shared `FetchErrorCard` with its technical details, Copy request ID, Retry and focus on its title. Admins without the permission and other Workspaces receive no data. All strings come from the catalogue or `labels.ts`.

**Never:** Writing a body, sample or test value to PostgreSQL, logs, audit, `sync_runs`, `raw_bodies` or a summary; caching a sample in clear or letting `web` write a blob it did not request; sending PUT, PATCH or DELETE or a POST that is not read-only; auto-retrying a POST; truncating a sample; showing a sample to anyone but the requester; inventing a default for a limit; a record count or path in the success message (Mapping, Epic 3).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Success | GET Endpoint, all values, 2xx JSON | Status, latency, body in the viewer; polite announcement; blob in Valkey only | N/A |
| Numbers | Body with `12345678901234567890.12` and `1.10` | Shown with exact lexemes | N/A |
| Missing value | Empty required path parameter | Test `aria-disabled` naming it; server 422; nothing sent | N/A |
| Bad value | Path value with `/`, `.`, `..`; header with CR/LF; bad date | 422 naming the parameter | Nothing queued |
| Not allowed | Host not allowlisted or blocked address | Card with the matching message; guard audits | N/A |
| Not JSON / too large | HTML body; over the limit | Card `not-json` or `response-too-large`; nothing stored; no truncated sample | N/A |
| Auth | 401 (GET with OAuth refreshes once) | `auth-failed` after the allowed retry | N/A |
| POST | Read-only POST | `Idempotency-Key` = Operation id; one send; a 401 or ambiguous failure is not retried | N/A |
| Not read-only | POST without the flag | Refused | N/A |
| Stale | Endpoint revision moved while running | Operation `stale`, nothing stored, shown as not current | N/A |
| Blob read | Requester, within TTL | Sample returned, `no-store` | Other member, expired, failed, stale, other Workspace: bare 404 |
| No key | `key-data` unavailable | `fetch-failed` with `blob_unavailable`; nothing stored | N/A |
| Rate limit | Over membership or Workspace limit | 429 with `retry_after`; nothing enqueued; button `aria-disabled` with reason | Unset limits: not limited |
| No permission | No `data_sources.manage` | 403; no data | Story 1.19 |
| Audit | Any test | `connector.endpoint.tested` with method, Endpoint id and revision only | N/A |
| Canary | Canary in a test value and in the response | Absent from logs, audit, `sync_runs`, summaries, Postgres | N/A |

</frozen-after-approval>

## Code Map

- `app/Platform/Operations/{Operations,OperationHandler,OperationOutcome,OperationKind,RunOperation}.php`, `app/Providers/AppServiceProvider.php:182` (kind registration), `app/Http/Controllers/OperationController.php`, `routes/api.php:87` -- add the `stale` outcome and register `sample_fetch`; the generic Operation endpoint never returns a body
- `app/Modules/Connector/Application/{StartConnectionTest,RunConnectionTest,RecordSyncRun,ManageEndpoints,EndpointBodyTemplate,ValidateEndpointInput}.php`, `Infrastructure/{ConnectionTestSettings,DirectFetchTransport,OAuthTokenCache,SecretSettings}.php`, `Contracts/{FetchRequest,FetchResponse,EgressRequest,EndpointPath,Endpoint,Endpoints,ConnectionTests}.php` -- patterns to mirror (rate limiter with `TenantKey::cache`, error ladder, sealed Valkey cache) and the request builder to extend; `tests/Unit/FetchRequestTest.php` pins the shape
- `app/Http/Controllers/Admin/{DataSourceController,EndpointController}.php` (`testConnection` 144 for the 202/429 envelope), `routes/api.php:71-74`, `app/Http/Navigation/ShellNavigation.php`, `app/Platform/Audit/AuditAction.php:53-55`, `Infrastructure/ConnectorAuditSerializer.php`, `config/dashflow.php` (`connection_test`, `secrets`), `compose.yaml` (`key-data` mounted on web, worker-connector, worker-compute), `docker/dev-keys/data.placeholder`, `tests/Architecture/{ComposeKeysTest,ModuleBoundariesTest}.php` -- API, settings and key plumbing
- `resources/js/pages/admin/DataSourceEndpoints.vue` (`openEdit` ~210, rows ~414), `components/{EndpointForm,FetchErrorCard,TechnicalDetails,GatedAction,BlockedReason}.vue`, `lib/{endpoints,dataSources,announce,clipboard,format}.ts` (`pollOperation`, `OperationSummary`), `locales/{en,labels}.ts` -- UI; no JSON viewer exists yet
- `tests/Database/{ConnectionTestTest,OperationsTest,EndpointsTest}.php`, `tests/Unit/{DirectFetchTransportTest,FetchRequestTest}.php`, `tests/Unit/Support/FakeCurl.php`, `tests/js/endpoints.test.ts`, `tests/js/connection-test.test.ts` -- test patterns

## Tasks & Acceptance

**Execution:**
- [x] kernel `stale` outcome, `sample_fetch` kind and `RunSampleFetch`, `SampleBlobStore` (key-data secretbox, fail closed), request rendering (`FetchRequest` v5, query builder, body renderer, `Idempotency-Key`, no POST retry), `sync_runs` and audit, settings and compose -- runtime
- [x] test and sample-read endpoints, requests, gate mapping, rate limits, error codes -- API
- [x] `resources/js` (Test endpoint action, test-values panel, `JsonSampleViewer`, polling, stale and error states), `labels.ts` -- UI
- [x] `tests/Unit`, `tests/Database`, `tests/Architecture`, `tests/Security`, `tests/js`, `README.md` -- every matrix row including a canary scan over Postgres, logs, audit and summaries

**Acceptance Criteria:**
- Given an Endpoint with parameters, when an Admin supplies test values and runs it, then the guard-checked request is rendered from the current revision, the body is visible only to them as an encrypted short-lived blob with exact number lexemes, nothing is written to Postgres, and failures show the matching error card.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| `RunSampleFetch::cleanup()` is empty, so a sealed body survives a failed, killed or expired Operation until its TTL; the revision is re-checked only before `put`, and not on the non-2xx branch | high | patch | The docblock says the blob is dropped on failure; only the stale paths call `forget`. |
| An over-limit request is enqueued when `availableIn()` returns 0 as the window rolls over; the README says refused requests are not counted but no test pins it | medium | patch | `retry` stays 0 and the guard treats that as not throttled. |
| The renderer and the handler's credential, OAuth and transport branches are untested: dropping `secretRefs`, the scheme or the key placement leaves every test green; no tamper, timeout, `auth-failed` or POST-once-on-error test; route throttles unverified | high | patch | All sample_fetch DB tests use scheme none; the failure matrix covers only guard, JSON and HTTP 500. |
| The Story 2.9 `walk()` by-reference fix has no regression test | medium | patch | Changing it back leaves the suite green. |
| `values` has no bounds; a non-field 422 shows as a fetch-failed card; Retry during a throttle wait does nothing silently | medium | patch | `TestEndpointRequest` is `nullable|array`; `showFieldErrors` handles only `values.{key}`. |
| A POST with an expired cached OAuth token fails `auth-failed` on the first send because a POST is never retried | medium | defer | Fetching a fresh token before the first POST send needs a decision on token freshness and an idempotent token path. |
| Reads of a sample are not audited and may repeat until the Operation expires | low | defer | No acceptance criterion; recorded for the audit review in Epic 9. |
| `Authorization` duplicated via an Endpoint header | false | rejected | Story 2.9 refuses credential header names on Endpoints through `ReservedHeaders`. |
| Body parameters on a GET silently dropped; field id collisions; textual architecture test; no large-response handling in the viewer; rate limits off by default; hard-coded 429 copy | low | rejected | Unlikely or cosmetic; limits are `pending_input` by rule; the behavioural canary tests already cover the same ground. |

## Design Notes

The epic assigns `sample_fetch` to Ingestion but Ingestion does not exist; the Operations registry is module-agnostic, so Connector registers it and a later story can move the handler. The blob holds the raw JSON text because that is the only form that keeps number lexemes exactly; the read route returns it as text for the viewer. `web` holds `key-data` (AR-50), which is what lets it decrypt a requester's own sample without the worker-only `cred` or `token` keys. The success message is built in `labels.ts` because the catalogue's `fetch-ok` reports a record count and path that need Mapping.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
