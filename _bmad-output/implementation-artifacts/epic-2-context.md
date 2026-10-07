# Epic 2 Context: Connect Your APIs

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Admins register REST/JSON Data Sources and Endpoints, test them, bind per-user data safely and see source health, with every outbound call guarded against SSRF. Dashboards never wait on source APIs: only `worker-connector` calls them, and the latest good response is kept as last-known-good. This epic also defines the user attributes that Epic 3 and later use for user-context binding.

## Stories

- Story 2.1: Manage the Workspace host allowlist
- Story 2.2: Guard every outbound URL with EgressGuard and operator private-range grants
- Story 2.3: Register and edit a Data Source
- Story 2.4: Add authentication and write-only secrets to a Data Source
- Story 2.5: Test a Data Source connection
- Story 2.6: Accept only JSON, losslessly and within limits
- Story 2.7: Use OAuth2 client credentials
- Story 2.8: Prevent overwriting on the Data source form with a soft lock
- Story 2.9: Register Endpoints on a Data Source
- Story 2.10: Test an Endpoint and see the Sample Response
- Story 2.11: Follow pagination up to the limits
- Story 2.12: Define user attributes for user-context binding
- Story 2.13: Bind Endpoint parameters and headers to user context
- Story 2.14: Fetch each Endpoint on a schedule and keep the last good response
- Story 2.15: Use conditional requests and skip unchanged data
- Story 2.16: Choose how much raw history a Data Source keeps
- Story 2.17: Retry, rate-limit and break the circuit on failing sources
- Story 2.18: See Data Source health
- Story 2.19: Refresh only what is being watched, within budgets
- Story 2.20: Fetch comparison data together as a sync group

## Requirements & Constraints

- A Data Source has name, base URL, auth type (none, API key, bearer, basic, OAuth2 client credentials), default headers, timeout, and max response size and pages. Credentials are encrypted, write-only, and never shown again or sent to the browser.
- All calls are server-side. The base URL must match the Workspace host allowlist. Loopback, link-local and cloud-metadata addresses are always blocked. Private ranges are allowed only by an operator grant for that Workspace, and each grant is audited. Redirects to non-allowlisted hosts are refused. A blocked attempt is rejected and audited.
- MVP exception: plain http to source APIs is allowed. Such sources carry a persistent "Not encrypted" badge for Admins and creation is audited. A `require_https` setting enforces TLS later without code changes. Browser-to-platform traffic is always TLS.
- Only GET is allowed, plus POST for Endpoints flagged read-only. That needs `data_sources.manage`, a risk confirmation and an audit entry, and POST is excluded from Live.
- Parameters are fixed, bound to the period (`period.start`/`period.end`), or bound to user context (user ID, email, group, or an Admin-defined attribute). End users can never change bound values. The source API does the filtering. Missing user context fails closed (`access.context_missing`).
- Pagination (page, offset, cursor, link header) is followed up to `max_pages` and `max_bytes` (decompressed). On a limit, an Admin-visible `connector.limit_exceeded` error is raised and nothing is stored. Data is never silently truncated.
- Identical requests (same endpoint, resolved parameters and user-context values) within a refresh interval share one fetch. Fetches are rate-limited per Data Source. Health is healthy, degraded or unreachable, with the last-success time. Live (~30 s) applies only to `live_capable` sources within budget.
- Workspace settings here cover the host allowlist and the attributes available for binding. Many tunables (timeouts, retry, circuit breaker, health thresholds, guards, budgets, TTLs) are env-driven settings flagged `pending_input` with no invented values.
- Source-agnostic core: no domain logic, and new source types arrive as modules.

## Technical Decisions

- **Connector boundary:** only `worker-connector` calls source APIs, OAuth token URLs and pagination URLs, through `FetchTransport` (`direct` in the MVP, `agent` later). `FetchRequest` carries `secret_ref`s and the credential scheme, never values. Interactive calls (tests) run as Operations.
- **EgressGuard on every URL:** allowlist match, then resolve all A/AAAA records. Deny by parsed binary address: loopback, link-local, metadata, IPv4-mapped/compatible, NAT64, 6to4, Teredo, ULA, CGNAT, unspecified, multicast. Private ranges need an operator grant. The deployment's own CIDRs are never allowed. Pin the IP with `CURLOPT_RESOLVE`, use the curl handler only, and allow http(s) only. The test matrix covers each class and DNS rebinding.
- **Redirects and pagination:** the next URL must share the Endpoint's origin. Cross-origin redirects are refused, credentials are stripped on an origin change, and an https-to-http downgrade is always refused.
- **Auth details:** API key goes in a header (query allowed with a warning). The OAuth token URL goes through EgressGuard with no redirects, JSON only under a size cap. Tokens are cached as `oauth:{ws}:{ds}:{secret_version}` until `expires_in` minus skew, and refreshed once on 401. Bound header values reject CR/LF and non-visible ASCII. Path segments are percent-encoded and `/`, `.`, `..` are rejected. Templates are parsed once into a URL AST.
- **JSON only:** both Content-Type and a successful parse are required, with depth and byte limits applied before parsing. Numbers are lossless.
- **IDs and records:** UUIDv7 generated in the app (`HasUuids`, `Str::uuid7()`). Source record identity is the optional record-key path value, stored as text namespaced by `dataset_id`, never an FK. Without a key it is the position index.
- **Audit:** audited commands write the audit event in the same transaction, with `connector.{noun}.{past_verb}` types from the `AuditAction` enum. Egress blocks and denials use `Audit::recordSecurityEvent` (autonomous transaction). Header and attribute values and samples are stored as hashes only. The Connector module registers an allowlist `AuditSerializer`.
- **Observability:** every span, log, sync run and error carries `request_id`/`trace_id` and `workspace_id`. A scrubbing processor strips query strings and fragments and drops headers outside an allowlist (canary-secret tests). `sync_runs` store a sanitized URL template and parameter names only. Metrics are named `dashflow.<module>.<measure>`.
- **Tenancy and modules:** new tables (for example `host_allowlist_entries`) carry non-null `workspace_id` under RLS. Modules are Connector and Ingestion, and visibility uses `AccessEvaluator`. The Epic 1 edit-lock kernel serves the Data Source soft lock.
- **Security CI suite:** SSRF/EgressGuard matrix, secret non-disclosure (API, logs, audit, browser payloads), RLS leak tests per tenant table, and the fail-closed user-context test.

## UX & Interaction Patterns

- Admin area screens use the shared `data-table` with five skeleton rows on cold load, an error state with Retry, and `msg:list-empty` with a primary "+ Add" action. Saves announce `msg:saved`.
- Server validation returns 422 with inline field errors (`aria-invalid`, `aria-describedby`) and focus on the first invalid field.
- Destructive removals use an `alertdialog` with initial focus on Cancel and the item name repeated on the destructive button. They list the dependents that will be affected.
- A permission denial returns 403 `perm-denied` and is audited. Secrets are write-only fields. Endpoint tests show status, latency and the Sample Response in a JSON viewer region.

## Cross-Story Dependencies

- 2.1 (allowlist) and 2.2 (EgressGuard) precede every story that makes an outbound call, from 2.5 through 2.20.
- 2.3 and 2.4 (Data Source and secrets) precede 2.5 (connection test), 2.7 (OAuth2) and 2.9 (Endpoints). 2.6 (JSON and limits) underpins 2.5, 2.10 and 2.11.
- 2.9 precedes 2.10 (Endpoint test), 2.11 (pagination) and 2.13 (user-context binding). 2.12 (attributes) precedes 2.13.
- 2.14 (scheduled fetch and last-known-good) precedes 2.15 to 2.20 (conditional requests, history, retry and circuit breaker, health, demand-driven refresh and budgets, sync groups).
- Depends on Epic 1: RLS and `WorkspaceTransaction`, audit and outbox kernels, Access (`AccessEvaluator`, `settings.manage`, `data_sources.manage`), the `worker-connector` role, the Valkey `queue` store, the shared UI components and the message catalogue.
- Epic 3 and later (Blocks, Datasets, mapping) consume Endpoints, Sample Responses, last-known-good data and the user-attribute catalog.
