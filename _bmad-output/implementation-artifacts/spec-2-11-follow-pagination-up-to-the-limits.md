---
title: 'Follow pagination up to the limits'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: '35193e08068782af409d4f586a3815f40a94103d'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-3-register-and-edit-a-data-source.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-6-accept-only-json-losslessly-and-within-limits.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-10-test-an-endpoint-and-see-the-sample-response.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** An API that returns its data in pages is read as only its first page, so totals would silently be too small, and a runaway API could be followed forever (Story 2.11; FR-13, NFR-3, AR-9, AR-44, AR-57, AR-8, UX-DR-26, 135, 282). The Story 2.11 section of `epics.md` holds the acceptance criteria.

**Approach:** A pagination setting on the Data Source (style and its parameters), a page loop inside `DirectFetchTransport` that follows pages through the guard until the API signals the end or a limit is hit and merges them into one logical JSON response, strict same-origin handling of next URLs, and a page-aware error for limits and mid-way failures that the sample test shows on the error card.

## Boundaries & Constraints

**Always:** `data_sources` gains expand-only columns `pagination_style` (`none` default, `page`, `offset`, `cursor`, `link_header`, with a CHECK), `pagination_param` (the query parameter that carries the page number, offset or cursor), `pagination_size_param` and `pagination_size` (optional, a positive integer sent with each page request), `pagination_records_path` (where the records array sits in each page; empty means the response root is the array) and `pagination_cursor_path` (where the next cursor sits in the response, for `cursor`), all null unless the style needs them (a CHECK ties them to the style); they are validated on register and update with the Story 2.3 rules, audited in the Connector serializer as enums, counts and keyed hashes (never raw names), included in the resource, the form (a Pagination section in the Limits area of the Data Source form with a style select and only the fields the style needs, field-level errors and labels from `labels.ts`) and the allowlisted before and after of `connector.data_source.updated`. A path is a dotted sequence of object keys and array indexes (`data.items`, `meta.next`) using `[A-Za-z0-9_-]` segments, at most 8 segments; parameter names are query-parameter tokens. Pagination applies to GET and read-only POST Endpoints by query parameters only, and never changes the Endpoint definition. `FetchRequest` moves to version 6 and carries the style, parameter names, size, records path, cursor path and `max_pages` (names and limits only, never values). The page loop lives in `DirectFetchTransport` (the only caller of the egress transport) and works per style: `page` sends the parameter starting at 1 and increasing by 1; `offset` starts at 0 and increases by the number of records received; `cursor` sends the cursor read from the response at the cursor path as an opaque string token appended to the configured parameter (never parsed or followed as a URL; a cursor that is missing, null or empty ends the run; a cursor identical to the previous one is a loop and fails the run); `link_header` follows the `rel="next"` target of the response's `Link` header, resolved against the current request URL; any other style sends one request. Following ends cleanly when the API signals the end: an empty records array for `page` and `offset`, no next cursor for `cursor`, no `rel="next"` link for `link_header`; with style `none` there is exactly one request. The records array of every page is read at `pagination_records_path`; a page whose body is not JSON, whose records path is missing or whose value is not an array fails the run as `not-json` for that page; the merged result is the first page's document with its records array replaced by the concatenation of all pages' records (so every other property is the first page's), emitted as JSON text with every number lexeme preserved (`LosslessJson`, a new tree-to-text emitter in the Platform kernel with a round-trip test; no `json_decode`), and for the `none` style the body is returned untouched. Limits: the page cap is the smaller of the Data Source `max_pages` and the platform `tunables.guards.max_pages` ceiling, each used only when set (neither set means no page cap, documented as an operator duty); a run that would need a page beyond the cap fails with `connector.limit_exceeded` and the new user code `too-many-pages` (a `labels.ts` message that includes the cap, states "Nothing was shown, so no totals are wrong." and tells the Admin to narrow the request or raise the page limit) and stores nothing; the byte limit applies to the combined decompressed bytes of all pages: each page request is given the remaining budget as its limit and exceeding it fails with the existing `connector.limit_exceeded` / `response-too-large` using the cumulative size; nothing is ever truncated or partially returned. Every page request, including each `link_header` target, is run through `EgressGuard` and the same credential handling as the first request; a `link_header` next URL must have exactly the same scheme, host and port as the request that returned it (an https to http downgrade, another origin or another port are refused), a refusal raises `SsrfBlocked` with a new `EgressReason::PaginationRefused`, is recorded as `connector.egress.blocked` through the guard's block recorder and counted toward the SSRF alert, no request is sent to the refused URL, and credentials never leave the original origin (a refusal happens before any send, so no stripping step is reachable); a next URL that is not an absolute http or https URL is resolved against the current URL, and one that cannot be parsed fails the run as `fetch-failed` for that page. Retries are not part of this story (Story 2.17): a page that fails (transport error, timeout, non-2xx, not JSON, block) fails the whole fetch with the page number; the sample test then stores nothing and the failure card names the page. The sample test summary gains `page` (the page that failed or was reached) and `pages` (pages fetched), `RunSampleFetch` maps the new outcomes, the Operation summary and `sync_runs` row (bytes cumulative, one row per attempt) are unchanged in shape, and `RunConnectionTest` keeps one request (pagination does not apply to the connection test). `FetchErrorCard` shows "Page {page}" in its title or details when `page` is present and renders `too-many-pages`. Test values and page tokens never appear in logs, audit, `sync_runs` or summaries. All strings come from the catalogue or `labels.ts`.

**Never:** Following a cursor as a URL; following a next URL of another origin, port or scheme; truncating or partially returning a paged result; retrying a page; paging in the connection test; sending credentials to any origin other than the first request's; using `json_decode` on a page; inventing a default for `max_pages`; a record count or path in the success message (Mapping, Epic 3).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| None | Style `none` | One request; body unchanged | N/A |
| Page | Style `page`, three pages then an empty one | Pages 1 to 4 requested; one merged response of all records | Empty page ends cleanly |
| Offset | Style `offset` | Offset advances by records received; ends on an empty page | N/A |
| Cursor | Cursor in each response | Cursor sent as a parameter token; missing or empty ends the run | Repeated cursor fails as a loop |
| Cursor as URL | Cursor value `https://evil.example/x` | Sent as a plain token to the configured request; never requested as a URL | N/A |
| Link header | `rel="next"` same origin | Followed through the guard | Other origin, port, downgrade or not allowlisted: `connector.ssrf_blocked`, audited, nothing stored |
| Page limit | More pages than the cap | `connector.limit_exceeded`, `too-many-pages`, nothing stored | N/A |
| Byte limit | Combined decompressed bytes over the limit | `response-too-large` with the cumulative size; nothing stored | N/A |
| Mid-way failure | Page 3 fails | Whole fetch fails; nothing stored; card names page 3 | No retry |
| Not JSON | Page 2 is HTML | Fails as `not-json` naming page 2 | N/A |
| Merge | Numbers `12345678901234567890.12`, `1.10`, extra properties on page 1 | Lexemes exact; first page's other properties kept | N/A |
| Credentials | Next URL changes origin | Refused before any request | N/A |
| Settings | Style needing a parameter without it | 422 field error on register and update | N/A |
| Unset caps | No page or byte cap configured | No cap applied | Documented |

</frozen-after-approval>

## Code Map

- `app/Modules/Connector/Infrastructure/{DirectFetchTransport,CurlEgressTransport,NativeCurlClient}.php`, `Contracts/{FetchRequest,FetchResponse,EgressRequest,EgressResponse,EgressOrigin,EgressReason,SsrfBlocked,ResponseLimit,ResponseLimitExceeded,ErrorCode,ConnectionTestCode}.php`, `Application/{GuardEgressUrl,RecordEgressBlock,RenderEndpointRequest,RunSampleFetch,RunConnectionTest}.php` (the page loop sits around the send call at DirectFetchTransport:99-103; redirect same-origin and refusal pattern at CurlEgressTransport:72-97 and `target()` 179) -- transport, limits and error ladder
- `app/Modules/Connector/Contracts/{DataSource,DataSourceInput,DataSourceCeilings}.php`, `Application/{ManageDataSources,ValidateDataSourceInput}.php` (COLUMNS 47, insert ~116, update ~172, `limit()` helper), `Infrastructure/{ConnectorAuditSerializer,DataSourceSettings}.php`, `app/Http/{Controllers/Admin/DataSourceController,Requests/Admin/DataSourceRequest,Resources/DataSourceResource}.php`, `database/migrations/2026_10_07_170001_create_data_sources.php` (CHECK pattern), `config/dashflow.php:63-64` (`guards.max_pages`, `max_bytes`) -- persistence and validation
- `app/Platform/Json/LosslessJson.php` (`decode`, `validate`, `canonical`) -- add the tree-to-text emitter; `tests/Architecture/JsonDecodeBanTest.php`
- `resources/js/pages/admin/DataSourceForm.vue` (Limits fieldset ~2410-2455, defaults ~108-119, load ~249, payload ~611, label map ~674), `components/{EndpointTestPanel,FetchErrorCard}.vue` (failure object ~164-256, props ~29-30), `lib/dataSources.ts:361-370`, `locales/labels.ts` (`limits` ~738, card labels ~902-944), `locales/en.ts:111` (closed catalogue; new copy in `labels.ts`) -- UI
- `tests/Unit/DirectFetchTransportTest.php` (`dftEgress` records only the last request: add a scripted queue variant), `tests/Unit/Support/FakeCurl.php`, `tests/Database/SampleFetchTest.php` (helpers `sf*`, the too-large test ~390), `tests/Unit/FetchRequestTest.php` (pins the shape), `tests/js/{endpoint-test,data-sources}.test.ts` -- test patterns

## Tasks & Acceptance

**Execution:**
- [x] migration (pagination columns and CHECKs), Data Source contracts, validation, audit fields, resource and controller, `FetchRequest` v6 -- configuration
- [x] `LosslessJson` emitter, page loop and merge in `DirectFetchTransport`, cumulative byte budget, page cap, `EgressReason::PaginationRefused`, `RunSampleFetch` mapping and `page` in the summary, `too-many-pages` code -- runtime
- [x] `resources/js` (Pagination section, error card page display and `too-many-pages`), `labels.ts`, README and settings docs -- UI and docs
- [x] `tests/Unit`, `tests/Database`, `tests/Architecture`, `tests/js` -- every matrix row, emitter round-trip vectors, a canary scan

**Acceptance Criteria:**
- Given a Data Source with a pagination style, when an Endpoint test runs, then pages are followed through the guard until the end or a limit, merged losslessly into one response, and a limit, a refused next URL or a failing page fails the whole fetch with nothing stored and the page number shown.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| The page cap fails a `page` or `offset` API with exactly `cap` pages, because the empty terminator is requested as page cap+1 | high | patch | `paged()` throws on `$page > $cap` before the terminator is read; three reviewers found it; no boundary test. |
| An API that ignores the page or offset parameter repeats the same records until a cap | medium | patch | Only `cursor` and `link_header` have loop detection. |
| A `Link` next URL echoing the API key gets the key twice; paged POST pages share one `Idempotency-Key`; relative targets resolve against the requested URL, not the answering URL | medium | patch | `withKey()` appends; the header is the Operation id on every page; `nextLink` uses `$url`. |
| The pagination parameter can collide with its size parameter, an Endpoint query parameter or the API-key name; a size field is offered for `link_header`; the DB CHECK matrix and paged OAuth2 and flush paths are untested | medium | patch | No validation or render-time dedupe; the size pair is added only on page 1 for `link_header`; no OAuth2 or invalid-pagination flush test. |
| Failed pages lack latency and limit sizes in the summary; README and `PageFailed` docblock misdescribe non-2xx; path syntax and `has_more` limits undocumented | low | patch | Direct corrections and documentation. |
| No wall-clock deadline or merged-size bound for a paged run | medium | defer | Needs a run deadline carried from the Operation into `FetchRequest` and a merged-size ceiling; documented as an operator duty when caps are unset. |
| Same-origin check does not limit the path; cursor numeric lexemes; quote-aware Link parsing; path syntax too narrow for OData; broad `Throwable` wrap; no progress text; trailing zero byte budget | low | rejected | The spec and architecture require same origin only; the rest are edge cases with documented limits where it matters. |

## Design Notes

The architecture puts pagination on the Data Source and leaves the merge rule open; merging records at a configured path keeps every other property of the first page and is the simplest rule that gives "one logical response" for the usual envelope shapes. Retries wait for Story 2.17 because it owns the backoff settings. The next-URL rule is enforced before sending, so "strip credentials on origin change" cannot be reached: a different origin is a refusal, which is stricter than the criterion and is the architecture's own rule for next URLs.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
