---
title: '4-14 Pause live updates and see the oldest Data-as-of'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '24487b7'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Numbers change under a reader who is reading or presenting, and the dashboard does not say how fresh it is.

**Approach:** A header toggle pauses every automatic fetch (poll, push-triggered, reconnect) and the freshness line shows the oldest Data-as-of Time of the Live Blocks; user-initiated fetches still work and Resume refreshes at once.

## Boundaries & Constraints

**Always:** Pause is page state only: leaving the dashboard ends it. Routine refreshes are not announced and do not animate. No toggle when no Block is Live.

**Never:** No server change, no persistence of the pause, no change to what a Block fetches.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| No Live Block | all intervals fixed | no toggle, no line | N/A |
| Live Blocks | differing as-of | oldest shown as "Data as of {time}" | Stale counts with its last data |
| Pause | polls, events, reconnect | no fetch, values fixed, `msg:paused` | N/A |
| Refresh, layout command | while paused | still fetch | N/A |
| Resume | any | one results fetch, line returns | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/pages/Dashboard.vue` (`paused`, `liveItems`, `oldestAsOf`, `freshnessLine`, `togglePause`; gates in `startPolling`, `onSocketState`, `onLiveEvent`, the offline watcher), `resources/js/locales/labels.ts`, `tests/js/dashboard.test.ts`

## Tasks & Acceptance

**Execution:**
- [x] Toggle and freshness line in the header, shown only with a Live Block
- [x] Gate poll, push and reconnect refetches while paused; Resume refreshes
- [x] Tests: no toggle without Live; pause stops polls and keeps values; resume fetches once

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the component tests.

## Implementation Notes

- The oldest Data-as-of is computed in the page from the results already held (each item's `data_as_of`, falling back to `last_success_at`), not from a separate response header field: the response has no aggregate and one would repeat what the items carry.
- `is_live` was already derived from the Block's refresh interval in Story 4.4. That a Live interval is offered only for a Data Source marked as supporting it is a publish-time rule (Epic 3), not re-derived here.
- The toggle sits in the desktop header group with Date Range; the narrow layout arrives with Story 4.17.

## Verification

- vitest (1451), `vue-tsc`, `vp check`, colour lint pass. No PHP changed.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Oldest as-of is not a server header field | low | accept | Derived from per-item fields; no information is lost. |
