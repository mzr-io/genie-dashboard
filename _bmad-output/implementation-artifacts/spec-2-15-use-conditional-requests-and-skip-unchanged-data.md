---
title: 'Use conditional requests and skip unchanged data'
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

**Problem:** Every scheduled success of Story 2.14 stores a new payload and emits `ingestion.payload.changed`, even when the source data did not change, so APIs are re-read in full and downstream work would rerun (Story 2.15; FR-13, NFR-3, NFR-1, AR-12, AR-40, AD-8, UX-DR-282). The Story 2.15 section of `epics.md` holds the acceptance criteria.

**Approach:** `FetchSyncTarget` sends `If-None-Match` or `If-Modified-Since` from the target's stored validators; a 304, or a 200 whose lossless-canonical hash equals `sync_targets.content_hash`, is an unchanged success (`last_success_at` and `last_checked_at` move, nothing is stored, no event). The Admin UI also shows when the data was last checked and since when it is current.

## Boundaries & Constraints

**Always:**
- **Cascade:** if `etag` is stored, send `If-None-Match: {etag}` (verbatim); else if `last_modified` is stored, send `If-Modified-Since: {value}` (verbatim, never parsed); else none. Sent only when the target has `current_payload_id`, and only for an unpaged Data Source (a paged call merges pages, so one validator cannot speak for it: such a target uses the hash only and keeps `etag`/`last_modified` null). Validators are read from the final response of a 200 or 304: exactly one value, at most 512 visible ASCII characters, else not kept (null). A 304 that carries a validator refreshes it.
- **Hash:** every 200 is compared by hash too (a source may ignore the conditional), so equal hash is unchanged even when a validator was sent. `content_hash` on `sync_targets` is now `sha256(hex)` of the canonical form of the body decoded by `LosslessJson` (never `json_decode`): the JCS layout of `Jcs` (sorted keys, no whitespace, string escapes) with each number written as its received lexeme (`1.0` differs from `1`), lists kept in order. `raw_bodies`/`raw_observations.content_hash` stay the sha256 of the exact bytes (content address, table CHECK). A body that cannot be canonicalised is treated as changed, stored, with `content_hash` null.
- **Outcomes** (all under the existing `applied_seq < dispatch_seq` fence; zero rows is `superseded`): `changed` is the 2.14 commit plus `content_hash`, the validators and `payload_changed_at`; `not_modified` (304) and `unchanged` (equal hash) set `applied_seq`, `last_success_at`, `last_checked_at`, `consecutive_failures` 0 and a refreshed validator only: no `raw_bodies`, no `raw_observations`, no `payload_seq` change, no outbox event, `current_payload_id` and `payload_changed_at` kept. The run is `succeeded` with a new `sync_runs.outcome` (`changed|not_modified|unchanged`, null before). Metric `dashflow.ingestion.payload_changed` counts `changed` only; `fetch_not_modified` and `fetch_unchanged` count the rest.
- **Bad 304:** a 304 when the target has no `current_payload_id` (or an unsolicited one: nothing conditional was sent) is a failed run (`fetch-failed`, reason `not_modified_without_payload`); `etag`, `last_modified` and `content_hash` are cleared in the same update, so the next fetch is a full one. Any other failure keeps the validators.
- **Reset:** a new Endpoint or Data Source revision is a new `fetch_key`, hence a new target with empty conditional state (the old one is retired with its state): no code path may copy validators or `content_hash` between targets, and a `moved` result sends nothing.
- **UI:** the Endpoint row keeps "Last success {time}" (a 304 counts) and adds "Checked {time}" and "Data as of {time}" (`payload_changed_at`), via `SyncStatus` and `EndpointResource.sync`, strings in `labels.ts`.

**Never:** Retention or hash history (2.16), retry/backoff/circuit breaker (2.17), health or 304-rate display (2.18), demand or budgets (2.19), sync groups (2.20), changing `FetchTransport`, `RawStore::put`, the Data Source soft lock or the sample path, a new tunable, validators or hashes in logs, audit, outbox or `sync_runs`, `json_decode` in Ingestion or RawStore.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| ETag, 304 | Stored ETag, source answers 304 | `If-None-Match` sent; `outcome` not_modified; payload, `payload_seq`, `payload_changed_at` kept; no event | N/A |
| Last-Modified, 304 | No ETag, stored Last-Modified | `If-Modified-Since` sent; same outcome | N/A |
| Neither, equal | No validator, 200 equal after whitespace or key-order change | `unchanged`; nothing stored; no event | N/A |
| Number lexeme | `1.0` becomes `1` | Different hash: `changed`, stored | N/A |
| Changed | 200 with new content | Payload, observation, event, new hash and validators, `payload_changed_at` | N/A |
| Ignored conditional | Validator sent, 200 equal body | `unchanged`; validators refreshed | N/A |
| Revision moves | Endpoint or Data Source revised | New target, empty state, no conditional header; old retired | N/A |
| 304, no payload | Target has no `current_payload_id`, or nothing conditional was sent | Failed run; validators and hash cleared; next fetch full | `fetch-failed` |
| Paged source | Pagination on | No conditional header, no stored validator; hash decides | N/A |
| Bad validator | Several or over-long `ETag` | Not kept; hash decides | N/A |
| Failure | 5xx, non-JSON, limit | As 2.14; validators kept | Ladder code |
| Late 304 | `dispatch_seq` below `applied_seq` | `superseded`; nothing changes | N/A |
| Canary | Canary in a validator or body | Absent from `sync_runs`, logs, audit, outbox | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Ingestion/Application/FetchSyncTarget.php` -- reads/clears validators and hash, branches on the result, three commits under the fence; `RegisterSyncTargets.php` -- unchanged (new key means reset)
- `app/Modules/Connector/Application/FetchEndpoint.php`, `Contracts/{EndpointFetchSpec,EndpointFetchResult,FetchRequest}.php` -- spec gains the two validators, result gains `notModified`, `etag`, `lastModified`; the conditional headers go in as Endpoint headers (the transport already passes them on, and a 3xx that is not a redirect comes back as a response; verify with a test), 304 is no longer `http_304`; `EndpointFetchLadder` untouched
- `app/Modules/Ingestion/Application/Jcs.php`, `app/Platform/Json/{LosslessJson,DecimalLiteral,JsonObject}.php` -- the canonical writer extends `Jcs` for lexemes; new `CanonicalBodyHash` in Ingestion (no `json_decode`)
- `app/Modules/RawStore/` -- no change (put is skipped on unchanged)
- `database/migrations/2026_10_10_100000_create_sync_targets_and_raw_tier.php` (`etag`, `last_modified`, `content_hash`, `last_checked_at` exist) -- a new migration adds `sync_targets.payload_changed_at` (backfilled from `last_success_at` where a payload exists), nulls old byte-hash `content_hash`, and adds `sync_runs.outcome`; the `app` grants stay column-compatible
- `app/Modules/Ingestion/Application/ReadSyncStatuses.php`, `Contracts/SyncStatus.php`, `app/Http/Resources/EndpointResource.php` -- `last_checked_at`, `payload_changed_at`
- `resources/js/pages/admin/DataSourceEndpoints.vue`, `lib/endpoints.ts`, `locales/labels.ts` -- Checked and Data as of lines
- `Connector` `SyncRunLog` and `SyncRunTokenLog` -- accept `outcome`
- `tests/{Unit,Database,Security,js,Architecture}/` -- hash vectors, matrix, canary, table ownership

## Tasks & Acceptance

**Execution:**
- [x] new migration and `dependencies.php` -- `payload_changed_at`, `sync_runs.outcome`, null old `content_hash`
- [x] `CanonicalBodyHash` and `Jcs` lexeme support -- the whitespace, key-order and lexeme vectors
- [x] Connector fetcher, spec, result, `SyncRunLog` -- conditional headers, 304 result, validator capture
- [x] `FetchSyncTarget` -- cascade, three outcomes, bad 304, metrics
- [x] `ReadSyncStatuses`, `SyncStatus`, `EndpointResource`, UI, `labels.ts` -- Checked and Data as of
- [x] tests for every matrix row (two-connection race for the fence, revision reset, canary)

**Acceptance Criteria:**
- Given a target that has fetched once, when the source then answers 304 or an equal body, then `raw_bodies`, `raw_observations` and the outbox have no new row and `last_success_at` and `last_checked_at` have moved.
- Given a changed body, when it arrives, then a payload, an observation and `ingestion.payload.changed` exist as in 2.14.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Endpoint's own If-None-Match/If-Modified-Since sent alongside platform's | medium | patch (fixed) | Same-named Endpoint headers dropped; unit test |
| Migration backfill / hash nulling untested | medium | patch (fixed) | Rollback-pattern database test added |
| Jcs ordering of integer-like member names | false | rejected | Test confirms UTF-16 order is already correct |
| Validator header lookup case sensitivity | false | rejected | NativeCurlClient lowercases names; test added |
| Uncanonicalisable body always `changed` | low | defer | Spec-chosen; churn only for odd bodies |
| Bad-304 reason not persisted; canonicalisation under row lock; validators across credential rotation | maybe-false | defer | Unverified or low impact |
| Unchanged 200 without validators clears stored ones | false | rejected | Source stopped sending them; clearing is correct |
| Number-lexeme churn, test timing, UI shape nits, fragile architecture assertions, other minor | low | rejected | By design or negligible |

## Design Notes

Decided here, not in the epic: **Two hashes.** The table CHECK makes `raw_bodies.content_hash` the byte hash and it is the content address, so the canonical hash lives only in `sync_targets.content_hash` (the field the epic names); old byte hashes are nulled, so the first run after deploy is one `changed` run. **Data as of** needs its own column because a 304 moves `last_success_at`. **Hash always compared** on a 200, so a source that ignores `If-None-Match` still skips. **Paged sources hash only**, since the transport reports first-page headers. **Reset is structural:** 2.14 keys targets by both revisions, so the epic's reset needs no code, only tests. Validators are opaque and verbatim (weak `W/` tags included). A 3xx that is not a redirect must reach `FetchEndpoint` as a status; if `CurlEgressTransport` fails it, fix it there with a test and no other change.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
