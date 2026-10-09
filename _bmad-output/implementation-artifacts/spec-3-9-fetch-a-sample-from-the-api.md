---
title: 'Fetch a sample from the API'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: '462051baf73e2cb3b45bb705e9efa469a388116e'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-10-test-an-endpoint-and-see-the-sample-response.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-8-paste-sample-json-and-inspect-it-with-exact-numbers.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-7-choose-the-data-source-endpoint-parameters-and-refresh-step.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The wizard's Fetch from API button is inert, so an Admin cannot map real data or see why a call fails.

**Approach:** Wire Fetch to the existing Operations/sample-fetch machinery from Epic 2 (Story 2.10: `sample_fetch` OperationKind, `RunSampleFetch`, `SampleFetches` contract, EgressGuard/FetchTransport, worker-connector) and implement Story 3.9 (epics.md "### Story 3.9"; its Given/When/Then ACs are authoritative and are the I/O matrix): a `sample_fetch` Operation for the Draft's chosen Endpoint and parameters with `subject_revision` captured at enqueue; the result reaches the Draft only as an encrypted short-TTL Valkey blob handed to a Blocks `OperationHandler` that writes `draft_samples` (origin fetch) through the same sealed storage, shape and limits as 3.8; `msg:fetch-ok` summary and JSON tree; the fetch-error-card with the five failure messages, Retry, "Paste sample JSON instead", Technical details disclosure and Copy request ID, focus on the card title; never truncating; per-membership rate limit with 429 `retry_after` shown on the button; initiator-only Operations (404 for others); offline disables Fetch with `offline-editing`; re-fetch replaces the sample and increments `sample_revision`; stale-parameters indication when the Draft revision moved; expired/timeout shows expired with Retry and stores nothing.

## Boundaries & Constraints

**Decisions (builder, from repo precedent; do not re-litigate):** (1) Reuse and extend the Epic 2 Operation infrastructure; do not build a parallel one. (2) `sample_revision` is a new integer on `draft_samples` (forward expand-only migration, RLS kept), incremented on every replacement (paste or fetch); the stored sample records the parameter/request key it was fetched with so the client can show "belongs to earlier parameters" when the Draft's current request key differs; fetching clears `sample_out_of_date` only when the keys match. (3) Rate limit and timeout ceiling are `pending_input` settings: unset → Fetch refused with an inline reason naming the setting, never an invented number (reuse any existing 2.10 settings where they apply). (4) `draft_samples` source value stays `fetch` (existing CHECK) for "origin = fetched". (5) The fetch runs with the Endpoint's configured credentials exactly as the 2.10 test does; bound user-context parameters follow the 2.10 fetch-as-user rules, and a Draft needing user context uses the same refusal the 2.10 test uses. (6) Fetch needs the Draft lock claim like other writes (3.6) and an acknowledged personal-data flag is required as for pasted samples before storing. Raw bodies/secrets never appear in logs, audit, Operation rows or error envelopes.

**Always:** Reuse 3.8's vault, shape, limits, SampleSection/SampleTree and labels (`fetch-ok`, `host-not-allowlisted`, `blocked-address`, `response-too-large`, `not-json`, `fetch-failed`, `offline-editing` from the catalogue); polling of the Operation via the existing operations status endpoint; accessible announcements; workspace RLS and prefixes; throttle.

**Never:** No truncation of responses; no preview rendering or mapping (3.10+); no publish checks (3.18); no invented limits; no change to the Data Source test/2.10 behaviour or to pasted-sample behaviour.

## I/O & Edge-Case Matrix

Story 3.9's Given/When/Then ACs in `_bmad-output/planning-artifacts/epics.md` are the matrix; each must be covered by a passing test, plus: unset rate-limit/timeout setting → inline reason, no Operation created; foreign-workspace Draft → 404; write without lock claim → 423; second fetch replaces the first and bumps sample_revision.

</frozen-after-approval>

## Code Map

- Operations: `app/Platform/Operations/*`, `OperationController` (routes/api.php ~144), `app/Modules/Connector/Application/{RunSampleFetch,ReadSample,RunFetchAsUser}.php`, `Contracts/{Samples,SampleFetches}.php`, `Infrastructure/SampleFetchSettings.php`, config `sample_fetch`/limits in `config/dashflow.php`
- Blocks side: `ManageDraftSamples`, `SodiumSampleVault`, `DraftSampleController`, `draft_samples` migrations (3.8), `ManageDrafts` source config (3.7)
- Client: `components/wizard/SampleSection.vue`, `SampleTree.vue`, `DataSourceStep.vue`, `lib/blockSample.ts`, `lib/blockSource.ts`, `BlockWizard.vue`, endpoint test client (`lib/endpoints.ts`, `tests/js/endpoint-test.test.ts`) for the existing fetch-error patterns, `locales/{en,labels}.ts`
- Tests: `tests/Database/DraftSampleTest.php`, Connector sample-fetch tests (2.10), `tests/js/block-wizard-sample.test.ts`

## Tasks & Acceptance

**Execution:**
- [x] server: Draft fetch operation (enqueue with subject_revision, Valkey blob, Blocks OperationHandler writing draft_samples, sample_revision migration, settings guards, initiator-only, rate limit 429)
- [x] client: Fetch wiring, polling, fetch-ok, fetch-error-card, retry/paste instead, offline reason, stale-parameters notice, expired state, labels
- [x] Tests: Database + Vitest covering every Story 3.9 AC and the extra rows

**Acceptance Criteria:**
- Given each Story 3.9 scenario, when exercised, then the outcome holds.
- Given the full suite, when `bin/tools composer ci:check` runs, then it passes.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Late fetch overwrites a pasted sample in the UI | medium | patch | `setMode` now aborts a running fetch; `runFetch` returns if aborted before storing. |
| Read-only viewer test asserts the opposite | medium | patch | Test now mounts with a held lock and asserts no `/sample/fetch` call. |
| Store failure aborts the worker's transaction (no summary) | high | patch | `store_failed` test failed in full suite; `storeFetched` now runs in a savepoint. |
| `too_deep` reported with `limit_bytes` | low | patch | Limit passed only for `too_large`. |
| PHPStan errors in records summary and `$row` type | medium | patch | Simplified records read; `\stdClass` type. |
| POST non-read-only refusal untested | false | rejected | DB CHECK `method = 'GET' OR read_only_query` makes it unreachable; the guard is defence in depth. |
| Rate slot consumed by a throttled hit | false | rejected | Same behaviour as Test endpoint (Story 2.10). |
| `Operations::enqueue` ttl < 1 falls back | low | rejected | Only caller passes a validated ceiling >= 1. |
| Fabricated status 200 / latency 0 | low | rejected | A succeeded run always carries both. |
| Concurrent fetches / stale `sample_revision` after purge / audit event / `fetchBodyOf` + auth-error + 409 tests / dotted binding keys / lock reactivity | maybe-false/low | defer | Recorded in deferred-work.md. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green
