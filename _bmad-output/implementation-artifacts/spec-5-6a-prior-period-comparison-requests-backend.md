---
title: '5-6a Prior-period comparison requests: backend'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '05ad737'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md', '{project-root}/_bmad-output/implementation-artifacts/spec-2-20-fetch-comparison-data-together-as-a-sync-group.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A comparison can only read a previous value that is in the same response, so it cannot work when the API returns none (Story 5.6; FR-27, AR-11, AD-25, AD-26).

**Approach:** Let a Block's comparison be "prior period": the same Endpoint is called for a prior window computed from the active period, both requests are one sync group (Story 2.20), and compute reads the pair of one complete generation and shows the change of the headline between them with the 5.5 modes. The Admin UI, preview note and step 5 live check are Story 5.6b.

## Boundaries & Constraints

**Always:** The prior window comes only from the kernel's `PriorWindow` (previous period of equal length, same period last year, custom offset). Compute reads a primary and a comparison payload of one complete generation, never a newer primary beside an older comparison. A failed comparison leaves the headline rendered and the comparison slot unavailable; freshness is the older of the two requests. No source is called on the read path. The compute context includes the comparison window.

**Never:** No fabricated comparison value (a missing prior response is unavailable). No change of the plan contract: the window is a presentation rule, the plan is run unchanged on the prior response.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Both fetched | complete generation, headline 284680, prior 249195 | change 14.239852, `ok` | N/A |
| Comparison failed | newest generation incomplete | primary from its payload, comparison unavailable, Stale by the older request | N/A |
| No generation yet | none stored | primary renders, comparison unavailable | N/A |
| Another window | different period or window | another compute context, no old result served | N/A |
| Late run | lower `dispatch_seq` | `superseded` (Story 2.20 race tests) | N/A |
| Preview | no prior response | comparison unavailable, nothing made up | N/A |

</frozen-after-approval>

## Code Map

- `app/Platform/Period/PriorWindow.php` (the three windows), `app/Modules/Mapping/Contracts/Presentation.php` (`source`, `window` of the comparison slot), `Application/QueryPlanEngine.php` (runs the plan on the prior response), `Contracts/QueryPlans.php`.
- `app/Modules/Ingestion/Contracts/{ComparisonTarget,ComputeSources}.php`, `Application/ReadComputeSources.php` (the linked comparison).
- `app/Modules/Results/Application/{ViewBlockResult,ComputeResults}.php` (comparison subscription, window in `compute_ctx`, generation pair, older freshness), `app/Providers/AppServiceProvider.php` (engine binding).

## Tasks & Acceptance

**Execution:**
- [x] `PriorWindow` and the presentation source and window rules
- [x] Engine reads the prior response, unavailable without it
- [x] View subscribes the linked comparison and keys the context by the window
- [x] Compute reads one complete generation, comparison slot and older freshness
- [x] Tests: Unit, Database

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the Unit and Database tests.

## Implementation Notes

- Stored as `config.presentation.slots.comparison.{source: "prior_period", window: {kind, amount?, unit?}, mode?}`; the mode defaults to % change. It is frozen with the version like the other presentation rules.
- The window is half-open like a resolved period; the comparison subscription stores it as inclusive dates.
- A primary has one comparison target (Story 2.20): a second Block with another window on the same primary target is refused by the link and shows the comparison unavailable.
- The result's generation ID is the sync generation and its `payload_seq` the two payload sequences added, so a change on either side is a new result.
- The container did not auto-wire the engine's decimal arithmetic (an optional class parameter), so the Story 5.5 modes would have failed outside tests; the engine is built in `AppServiceProvider` now.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, `pint`, `phpstan`.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Story 5.5 modes failed in the real container (math not wired) | high | fixed | `ViewedResultsTest` runs the engine through the container. |
| A second Block on the same primary with another window is refused | medium | accept | Story 2.20 allows one comparison per primary; the comparison reads unavailable. |
| Admin UI, preview note and step 5 live check | n/a | defer | Story 5.6b. |
