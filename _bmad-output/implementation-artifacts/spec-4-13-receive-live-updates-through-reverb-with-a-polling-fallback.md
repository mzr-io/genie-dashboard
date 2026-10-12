---
title: '4-13 Receive live updates through Reverb with a polling fallback'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '2304f5e'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A dashboard shows what it loaded; fresh data arrives only on reload.

**Approach:** Relay each new result to the right private channel as an ID-and-ETag event (only while the Block is viewed), authorise channels against the database, let the page refetch only what changed, and fall back to polling at a `pending_input` interval when the socket is not there, with an honest offline state.

## Boundaries & Constraints

**Always:** An event carries IDs and the ETag, never a value. Channel authorisation re-checks the database. Background calls never extend the idle session. No offline editing: layout commands are refused with the reason. A Block never shows results of two generations mixed.

**Never:** No pause (4.14), no value on the socket, no forced socket teardown on an access change (see notes), no new broadcast library.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| New result, shared | viewed Block | event on the Block channel | none if nobody views it |
| New result, own data | member's target | event on the member's channel | N/A |
| Event | ETag not held | that item refetched in place | same ETag: nothing |
| Channel auth | member with access | 200 | other Workspace, member or hidden Block: 403 |
| No socket | no config or never connected | polls at the interval | interval unset: no polling |
| Socket lost | was up | `msg:reconnecting` once, commands refused, last values kept | `msg:back-online` once and a refresh |
| Viewers | add / restore / remove | counted, released at zero | N/A |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_28_100000_create_block_viewers.php`; `app/Modules/Results/{Application,Contracts}/TrackBlockViewers.php`, `BroadcastResultUpdated.php`, `ResultUpdated.php`; `ComputeResults::announce`
- `routes/channels.php`, `bootstrap/app.php` (channels), `DashboardController` (`live`, `items[]`), `app/Platform/Outbox/EventData.php`, `config/dashflow.php` (`live.poll_interval`)
- `resources/js/lib/realtime.ts`, `composables/useConnection.ts`, `pages/Dashboard.vue`, `lib/dashboards.ts`

## Tasks & Acceptance

**Execution:**
- [x] `block_viewers` read model and its consumer
- [x] `results.result.updated`, relay on private channels, channel authorisation
- [x] `live` config in the layout response; `items[]` refetch; background header
- [x] Realtime client, polling fallback, reconnecting and offline handling
- [x] Tests: Database (viewers, event, relay, auth, refetch, live config), vitest (client, page: subscribe, refetch, poll, reconnect)

**Acceptance Criteria:**
- Given the I/O matrix, when results change or the connection drops, then each row holds against PostgreSQL and in the component tests.

## Implementation Notes

- The Reverb server and its Valkey scaling are deployment concerns of the `realtime` role; the tests cover everything up to the broadcast event and the browser client against a fake socket. No end-to-end socket test runs here.
- "Forced re-authorise on access change" is not built: Reverb has no per-socket revoke in this stack. A revoked member is refused at the next subscription (the database is re-checked), results re-check access per item on every read, and a loss shows as `access_removed` at the next refetch.
- An event payload's `result_etag` is a SHA-256 digest; `EventData` allows exactly that one key to be 64 hexadecimal characters.
- A bound-data result goes to the owner of the target only if that member is viewing the Block; an unviewed Block costs no broadcast.
- Polls and event refetches send `X-Background: 1`; the first load and an explicit Retry do not.

## Verification

- SQLite suite, Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check` pass.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| No end-to-end test against a real Reverb | medium | defer | The socket is outside the test harness; the protocol client and the broadcast event are covered separately. |
| Member-scope event path has no Database test | low | defer | Needs a member attribute fixture; the branch is a single condition on the target owner. |
| Access change does not tear down an open socket | low | defer | Documented above; the data re-check on every read bounds the exposure. |
