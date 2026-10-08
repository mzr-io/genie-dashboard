---
title: 'Accept only JSON, losslessly and within limits'
type: 'feature'
created: '2026-10-08'
status: 'done'
baseline_commit: '570d3bab68ca2cd28b8dd6d9f204331e1ad814be'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-5-test-a-data-source-connection.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A source response of any type or size is read into memory and counted, nothing checks that it is JSON, and PHP's own decoder would turn `1.10` into `1.1` and large decimals into floats, so totals could be silently wrong or truncated (Story 2.6; FR-13, NFR-4, AR-40, AR-44, AR-13, AR-57, AD-33, UX-DR-135, 282). The Story 2.6 section of `epics.md` holds the acceptance criteria.

**Approach:** A hand-written, lossless JSON decoder in the Platform kernel (`LosslessJson`, `DecimalLiteral`, a canonical form and hash), a transport that stops reading a response at the size limit on the decompressed stream, a Content-Type and parse check applied to every fetch outcome including Test connection, and the catalogue messages `not-json` and `response-too-large` wired through the error card.

## Boundaries & Constraints

**Always:** `app/Platform/Json` holds `LosslessJson` and `DecimalLiteral`, written by hand with an iterative parser (an explicit stack, so a deeply nested body can never exhaust the PHP stack) and no call to `json_decode` inside it; `DecimalLiteral` is a readonly value keeping the original lexeme string, never a float, with `__toString`, `isInteger()` and equality on the lexeme. The decoder accepts the RFC 8259 grammar only: numbers as `DecimalLiteral` with the exact lexeme as received; strings validated as UTF-8 with escapes and surrogate pairs decoded; objects as an ordered map that preserves key order; a duplicate key within one object, a leading BOM, trailing content, invalid UTF-8, `NaN`/`Infinity`, comments and trailing commas are all parse errors. A depth limit (`dashflow.tunables.guards.depth_limit`, `pending_input`, unset means not checked) is enforced by a pre-scan that counts container depth outside strings before any value is built, and exceeding it raises `JsonDepthExceeded` without parsing. `LosslessJson::canonical()` returns the lossless-canonical bytes: whitespace removed, object keys sorted by byte order of their UTF-8 form, strings re-emitted with one fixed escaping (`"`, `\\` and the C0 controls only, the short forms for `\b \f \n \r \t` and lower-case `\u00xx` for the rest, everything else raw UTF-8, `/` not escaped), numbers as their received lexemes (so `1.10` and `1.1` differ) and a hash is the SHA-256 of those bytes; two bodies that differ only in whitespace, key order or the way a character is escaped canonicalise to identical bytes. A conformance suite (a fixtures directory of accept, reject and number cases checked against `json_decode` as the oracle for valid structures only) and golden vectors (input to canonical bytes to hash) are part of the test suite, plus cases `12345678901234567890.12` and `1.10`. The existing `tests/Architecture/JsonDecodeBanTest` is extended to ban `json_decode` inside `app/Platform/Json` and stays green for Ingestion, RawStore, Mapping and Results, and a regression test confirms the scanner runs over real files. The size limit is the smaller of the Data Source `max_response_bytes` and the platform `tunables.guards.max_bytes` ceiling, each used only when set; when neither is set no cap is applied (documented as an operator duty under the `pending_input` rule). `EgressRequest` carries the limit; the transport asks curl for `gzip` (so the write callback sees the decompressed stream), counts every decompressed byte and aborts the read the moment the count passes the limit, raising `ResponseLimitExceeded` (bytes read, limit) that no retry can use; a gzip bomb is aborted the same way, `connector.limit_exceeded` is added to `Connector\Contracts\ErrorCode`, and nothing partial is ever returned. Fetches send `Accept: application/json` unless the Data Source sets its own. A JSON check runs after any 2xx response (a `FetchResponse::json()` helper or equivalent used by Test connection and by every later fetch): the media type must be `application/json` or `application/*+json` (case-insensitive, an optional `charset` only `utf-8`), and the body must parse; otherwise the error is `connector.not_json` (added to `ErrorCode`) with the catalogue key `not-json`, an empty body or missing Content-Type counts as not JSON, the failure is marked non-retryable, and nothing is stored. Test connection maps the two new outcomes to the user codes `not-json` and `response-too-large`, never retries them, writes the `sync_runs` row with the bytes read and the code, and returns in its summary the actual and allowed sizes (`size_bytes`, `limit_bytes`) for the message placeholders; the error card renders `not-json` and `response-too-large` (the message states "Nothing was shown, so no totals are wrong", sizes formatted for people, focus on its title, Technical details unchanged). Other HTTP failures keep their `fetch-failed` mapping. No secret, body or query string appears in logs, `sync_runs`, the summary or audit. All strings come from the catalogue or `labels.ts`.

**Never:** Using `json_decode`, `JSON_BIGINT_AS_STRING` or any float conversion on a payload; returning or storing a truncated body; counting compressed bytes toward the limit; recursing in the parser; inventing a default depth or size limit; retrying a not-JSON or too-large response; storing a body in PostgreSQL, a log or a summary.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Valid JSON | `application/json`, parseable body within limits | Decoded losslessly; Test connection succeeds | N/A |
| Wrong type | HTML or `text/plain`, missing Content-Type, empty body | `connector.not_json`, card `not-json`, no retry, nothing stored | N/A |
| Bad body | Right type, parse fails (trailing comma, BOM, duplicate key, bad UTF-8) | Same as above | N/A |
| Too large | Decompressed size over the limit | Read stops at the limit; `connector.limit_exceeded`; card `response-too-large` with actual and allowed sizes; nothing stored | N/A |
| Gzip bomb | Small compressed, huge decompressed | Aborted as soon as the decompressed count passes the limit | N/A |
| Too deep | Depth over the setting | Rejected before parsing | Unset setting: not checked |
| Numbers | `12345678901234567890.12`, `1.10` | `DecimalLiteral` with the exact lexeme; never float | N/A |
| Canonical | Whitespace, key-order or escape differences only | Identical canonical bytes and hash (golden vectors) | N/A |
| Number lexemes | `1.10` versus `1.1` | Different canonical bytes | N/A |
| Ban | `json_decode` in Ingestion, RawStore, Mapping, Results or `app/Platform/Json` | Architecture test fails | N/A |
| Error card | `response-too-large` | States nothing was shown; focus on title | N/A |
| No limits set | Neither size limit configured | No cap applied | Documented |

</frozen-after-approval>

## Code Map

- `app/Modules/Connector/Infrastructure/{NativeCurlClient,CurlEgressTransport}.php` (write callback and abort point, `options()`), `Contracts/{EgressRequest,EgressResponse,EgressTransportFailed,FetchResponse,ErrorCode,CurlClient,CurlResult}.php`, `Infrastructure/DirectFetchTransport.php:70` -- where the limit and `Accept-Encoding` enter and where the abort surfaces; `tests/Unit/Support/FakeCurl.php`, `tests/Database/EgressTransportTest.php` (local `php -S` pattern) -- tests
- `app/Modules/Connector/Application/RunConnectionTest.php`, `Contracts/ConnectionTestCode.php`, `Application/RecordSyncRun.php`, `Application/StartConnectionTest.php` -- outcome mapping, summary and `sync_runs`; `Contracts/DataSource*.php` (`maxResponseBytes`), `Infrastructure/DataSourceSettings.php` (ceilings, `whole()`), `config/dashflow.php:62-68` (`guards`) -- limits
- `tests/Architecture/{JsonDecodeBanTest,dependencies}.php`, `Support/Scanner.php` (`jsonDecodeViolations`, path-based), `Fixtures/json-decode*` -- the ban to extend; kernel files may not call modules and the decoder must be reachable by every module, so it lives in `app/Platform/Json`
- `resources/js/components/FetchErrorCard.vue` (`code` prop, title focus), `pages/admin/DataSourceForm.vue` (~44, 124, 133, 882, 932, 981, 1704), `locales/{en,labels}.ts` (`not-json`, `response-too-large` exist at en.ts:111-114), `tests/js/{connection-test,catalogue}.test.ts` -- UI
- `tests/Unit/ErrorCodeGeneratorTest.php`, `app/Console/Commands/ErrorCodesCommand.php` -- regenerate `resources/js/types/error-codes.ts` after adding codes

## Tasks & Acceptance

**Execution:**
- [x] `app/Platform/Json` (`LosslessJson`, `DecimalLiteral`, errors, canonical form and hash), depth pre-scan, conformance fixtures and golden vectors, architecture ban extension -- the decoder
- [x] transport limit and gzip abort (`EgressRequest`, `NativeCurlClient`, `CurlEgressTransport`), `FetchResponse` JSON check, `ErrorCode` cases, `Accept` header, `RunConnectionTest` and `ConnectionTestCode` mapping, `sync_runs` bytes, settings docs -- limits and JSON check
- [x] `FetchErrorCard` codes and size placeholders, form wiring, `labels.ts`, generated error codes -- UI
- [x] `tests/Unit`, `tests/Database`, `tests/Architecture`, `tests/js`, `README.md` -- every matrix row

**Acceptance Criteria:**
- Given a source that returns HTML, malformed JSON, an oversized or gzip-bomb body, or valid JSON, when Test connection runs, then the first four are rejected with the right message and nothing is stored, and the last succeeds with numbers kept as exact lexemes.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| The size limit applies to every hop body, so an oversized 4xx, 5xx or redirect page reports `response-too-large` (or aborts the redirect) instead of the real failure | high | patch | `NativeCurlClient` counts all bytes; `RunConnectionTest` maps `ResponseLimitExceeded` regardless of status. |
| A malformed platform ceiling or depth limit is treated as unset and silently removes the cap | high | patch | `ResponseLimit::effective` and the depth reader return null for any unparsable value. |
| Test connection fully materialises the decoded body only to validate it | medium | patch | `FetchResponse::json()` builds the tree; PHP arrays multiply the body size. |
| Repeated Content-Type headers: only the first is judged | medium | patch | `FetchResponse::json()` reads `content-type[0]`. |
| `formatBytes` rounds to 1,024 KB; a test depends on a helper defined in another file; the exact-limit boundary and the CRLF fixture are unprotected | medium | patch | No test at N versus N+1; `ljDepth()` lives in `LosslessJsonTest.php`; git warns it will normalise `y_ws.json`. |
| Unset size and depth limits leave a deeply nested or huge body able to crash a worker (the README admits a segfault at depth 300,000 on release) | medium | defer | The story makes both limits `pending_input` with no invented value; whether to add a built-in ceiling the setting can only lower is a product and operations decision, recorded for the human. |
| 204 and empty 2xx counted as not JSON; `charset=utf8` alias refused; the `not-json` message mentions HTML for every cause; `size_bytes` is the count at abort; `br`/`deflate` not advertised; v2 `FetchRequest` readers; operator log line for rejections; scanner realpath; `Accept` merge by credential key; missing-size fallback | low | rejected | The story states an empty body is not JSON and the catalogue wording is fixed by Story 1.7; the rest are documented, unlikely or cosmetic and each fix adds branches. |
| `DirectFetchTransport::fetch` does not call `json()` | false | rejected | The criterion applies the check where a fetch result is consumed; Test connection does, and later fetch stories use `FetchResponse::json()`. |

## Design Notes

The decoder lives in the kernel because Connector, Ingestion, Mapping and Results all need it and the module edges forbid sideways calls; the ban on `json_decode` applies to the four modules the architecture names plus the decoder itself so a "lossless" parser cannot quietly delegate. Reading stops at the limit instead of after the body arrives, which is the only way a gzip bomb cannot exhaust memory. With neither size limit configured nothing is capped, consistent with every other `pending_input` setting; operators are told in the README. Duplicate keys are rejected rather than resolved because last-wins silently changes data, which this story exists to prevent.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
