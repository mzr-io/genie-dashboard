---
title: '4-5 See live Block values without waiting on the source'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: 'ec97cb0'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The dashboard shows Block frames but no values, and nothing returns stored results to the browser.

**Approach:** Add `GET /api/v1/dashboards/{id}/results` that answers from stored results through `ViewedResults` (subscribing the Block's data on the way), re-checks access per item, queues a compute on a miss with a payload, and never contacts a source. The page loads results after the layout, shows each Block's skeleton until its first result, then draws it with its Block Type's Renderer with exact decimals and every state, keeping the last values until a different result arrives.

## Boundaries & Constraints

**Always:** The results call never contacts a source and never waits for compute. Measures stay decimal strings and are formatted in the browser. Slots are literal text. A value that cannot be produced is Unavailable, never 0, NaN or blank. A user-bound Endpoint's values come from server-side membership data only.

**Never:** No Reverb or pause (4.13, 4.14), no Block chrome actions (4.6), no manual Refresh, no per-item inline compute, no interactive sync Operation beyond making the target hot, no Date Range UI (4.15).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Hit | result stored | state, etag, data_as_of, last_success_at, stale_at at once | N/A |
| Miss with payload | no result | `pending`, one compute queued | N/A |
| Miss, no payload | no payload yet | `pending`, target made hot | N/A |
| Source down | transport fails | stored result served, zero calls | N/A |
| Access removed | hidden after layout | `access_removed`, no data | N/A |
| Stale | last check > 2 intervals | values kept, Stale text with time | N/A |
| User-bound, no value | attribute missing | `unavailable`, nothing fetched | N/A |
| Bad range | not offered / a date | rejected | 422 |
| Hostile text | markup in slots | literal text | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Results/Application/ViewBlockResult.php` -- user-bound resolution, compute on miss
- `app/Modules/Ingestion/{Contracts,Application}/EndpointUserBindings.php`, `ReadEndpointUserBindings.php`
- `app/Modules/Dashboards/**` (`results()`), `app/Http/Controllers/DashboardController.php`, `routes/api.php`
- `resources/js/{pages/Dashboard.vue,components/BlockCard.vue,lib/dashboards.ts,lib/blockRenderers.ts}`, `block-types/kpi-card/v1/Renderer.vue` (`bare`)

## Tasks & Acceptance

**Execution:**
- [x] Results endpoint, per-item access and states, range as a token
- [x] User-bound values through Ingestion and `UserContext`; `access.context_missing` is Unavailable
- [x] Compute on a miss with a payload, debounced per target
- [x] Page: results load, skeleton, Renderer, stale and error, retry, polling, aggregated announcement
- [x] Tests: Database (matrix), vitest (states, retry, XSS, in-place update)

**Acceptance Criteria:**
- Given the I/O matrix, when results are read, then each row holds against PostgreSQL and Valkey and in the component tests.

## Implementation Notes

- A miss with no payload relies on the dispatcher: the subscription `view()` just touched makes the target hot and Story 2.19's demand rule fetches it. There is no separate interactive-sync call in the codebase to invoke.
- The per-item inline compute under a single-flight lock is optional in the story and is not built; the debounce lock only prevents a flood of queued jobs.
- The dashboard `range` passes to the resolver with no Block-enabled preset list (none exists until 4.15), so only a Block's own default is accepted today.
- The viewer's compute context is derived by the same `ComputeContext::of` the compute uses, so a read and a compute agree on the key.
- The page's stale announcement uses the shared aggregated announcer (2 s debounce) and `msg:stale-aggregate`.
- `LaravelValkeyCommands` opens its connection on first use, so resolving the read path does not need Valkey.

## Verification

- SQLite suite, Database suite (PostgreSQL 18, PgBouncer, Valkey), vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, renderer and colour lints pass.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Polling every 3 s for up to a minute per open dashboard | low | defer | Bounded, and 4.13 replaces it with push plus a fallback. |
| `view()` per item runs a Subscribe upsert per request | low | defer | Subscribe throttles the touch; cost is one read per item. |
| Renderer hidden title in `bare` mode loses its h3 | false | none | The card's h2 names the section and the body region carries `aria-label`. |
