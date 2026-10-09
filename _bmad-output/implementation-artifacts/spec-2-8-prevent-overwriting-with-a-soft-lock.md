---
title: 'Prevent overwriting on the Data source form with a soft lock'
type: 'feature'
created: '2026-10-08'
status: 'done'
baseline_commit: 'a8a255096034301f86b7fac4c2a09439da9e47a2'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-3-register-and-edit-a-data-source.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-4-add-authentication-and-write-only-secrets-to-a-data-source.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-15-warn-before-session-expiry-and-keep-forms-safe.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Two Admins can open the same Data Source form and the later save silently overwrites the earlier one's work, and no lock, takeover or lost-lock protection exists (Story 2.8; FR-9, AR-19, AR-42, AR-17, UX-DR-250, 249, 248, 263, 282). The Story 2.8 section of `epics.md` holds the acceptance criteria.

**Approach:** Introduce the `Platform\EditLock` kernel (a TTL lock in the cache, a `lock_epoch` in PostgreSQL, a flush-then-take-over protocol) and apply it to the Data Source form: the first editor holds the lock, others see a read-only form with a take-over option, the holder's valid non-secret fields are saved before a take-over, and a write made with an old epoch is refused with 423. Realtime push is not wired in this codebase, so the protocol runs over polling, which the architecture names as the fallback with the same semantics.

## Boundaries & Constraints

**Always:** Kernel `app/Platform/EditLock` (calls no module; generic over a resource `type` and `id`) keeps one cache entry per resource at `edit_lock:{type}:{id}` through `TenantCache` (the Workspace prefix is added there), holding the holder's membership id, a random per-tab lock token, a display name and `since` supplied by the caller (the controller resolves the name; the kernel never reads Access), the epoch it was granted at, and any pending flush request. Operations: `acquire` (free or expired lock is granted, the same membership re-acquiring with a new tab replaces its own lock; a held lock returns the holder and `since` read-only), `heartbeat` (token must match; renews the TTL), `release` (token must match; frees it), `requestTakeover` (marks the lock `flush_requested`, emits the Outbox event `platform.edit_lock.flush_requested`, IDs and enums only, in the same transaction as the request, and answers `waiting`), `acknowledgeFlush` (the holder confirms its flush; the take-over completes at once), and `complete` (a take-over completes immediately on acknowledgement, or when the configured flush timeout passes without one, or when the lock has expired; completion increments the resource's `lock_epoch` in PostgreSQL, hands the lock to the taker with the new epoch and records for the former holder a one-shot `taken_over` notice that says whether its flush was acknowledged). The TTL is `dashflow.tunables.timeouts.edit_lock_ttl` (`pending_input`, unset means the soft lock is disabled: `acquire` answers `{enabled: false}`, the form behaves as it does today with only the `revision` check, and the README and compose pass-through document it); the flush timeout is a new `pending_input` setting `DASHFLOW_EDIT_LOCK_FLUSH_TIMEOUT_SECONDS` outside the closed AR-57 list (unset means a take-over does not wait and completes at once, discarding the holder's unsaved changes with a notice). `data_sources` gains `lock_epoch` (integer, default 1, never decreasing; expand-only migration). Every Data Source update that carries a `lock_epoch` is compare-and-set on `revision` and on `lock_epoch` in the same locked transaction: a stale epoch returns `423` `platform.edit_lock_lost` (added to `Platform\Contracts\ErrorCode` and the generated TS enum) with the current state and writes nothing, and a stale `revision` returns 409 as before; when the lock is enabled the update request must carry `lock_epoch` and the lock token, and the server also checks the token still holds the lock, so a holder whose lock was lost never saves (and its unsaved secret values are never stored: the 423 is raised before any secret is sealed or written). API (under `/api/v1/admin/data-sources/{dataSource}/lock`, permission `data_sources.manage`, throttled, all in `ShellNavigation::ADMIN_API_ROUTES` and the Story 1.19 matrix, route names `api.admin.data-sources.lock.*`): `POST` acquire, `PUT` heartbeat, `POST release` (accepts the token and the CSRF token in the body so it works with `navigator.sendBeacon`), `POST takeover`, `GET` takeover status and `POST flush` acknowledge; heartbeat and status calls send `X-Background: 1` so they never extend the auth session; none of these routes accepts secret values. A lock never grants access: all calls re-check the permission and the Workspace (a Data Source of another Workspace is 404). The Data Source edit form acquires the lock on load; with the lock it is editable and heartbeats on a client interval; without it the form is fully read-only, shows the `draft-locked` banner (holder name and since) with "Take over editing" and Close, and polls only after Take over; on Take over it waits showing a polite status until the take-over completes, then reloads the Data Source (the post-flush revision) and becomes editable; the holder's heartbeat response reports a pending `flush_requested`, on which the form saves its valid non-secret fields through the normal update (never any secret value, never invalid fields), then acknowledges, and afterwards shows the `draft-taken-over` notice and goes read-only; a holder whose flush was not acknowledged sees the notice without the claim that changes were saved (a `labels.ts` variant, since the catalogue is pinned). Closing the tab releases the lock (beacon) and an idle holder past the TTL loses it, so the next reload edits normally. The lock is only taken on the edit form of a saved Data Source (the create form has nothing to protect). The form never restores unsaved fields after a session expires (it registers no form draft and keeps secrets out of any stored state), and the existing session-warning dialog and `session-expired` flow from Story 1.15 are reused unchanged and covered by a test on this form. Two writers racing on `revision` still produce one success and one 409 with the current state. Audit: none is added; no secret or token appears in logs, audit, responses or props. All strings come from the catalogue or `labels.ts`.

**Never:** Building Reverb channel auth, an Echo client or any websocket transport in this story; autosaving a form or any secret value; saving the holder's secrets on a take-over; trusting the client's claim of holding the lock; storing a lock in PostgreSQL other than the epoch; letting a heartbeat extend the auth session; an invented default for the TTL or flush timeout.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Acquire | Free lock, edit form opened | Granted with epoch and token; form editable | N/A |
| Locked | Another Admin holds it | Read-only form, `draft-locked` banner with holder and since, Take over and Close | N/A |
| Take over, holder online | B requests; A's heartbeat sees the flush request | A saves valid non-secret fields, acknowledges; epoch + 1; B edits the new revision; A sees `draft-taken-over` | N/A |
| Take over, holder gone | No acknowledgement within the timeout, or timeout unset | Take-over completes; A's later save gets 423; A's changes discarded with a notice | N/A |
| Stale epoch | A saves with the old epoch | 423 `platform.edit_lock_lost` with current state; nothing written, no secret stored | N/A |
| Expired | Holder idle past the TTL or tab closed | Lock released; B reloads and edits normally | N/A |
| Disabled | `edit_lock_ttl` unset | `acquire` answers `{enabled: false}`; form works with `revision` only | N/A |
| Revision race | Two writers, same revision | One succeeds, the other gets 409 with current state | N/A |
| Session | Warning two minutes before expiry; expiry | Existing dialog; after sign-in the page returns with `session-expired` and no restored fields | N/A |
| Heartbeat | Background poll | `X-Background: 1`; the auth session is not extended | N/A |
| No permission | No `data_sources.manage` | 403; no lock state | Story 1.19 |
| Other Workspace | Foreign Data Source id | 404 | RLS |

</frozen-after-approval>

## Code Map

- `app/Platform/Tenancy/TenantCache.php` (`get`, `put`, `forget`, `lock`), `app/Platform/Outbox/Outbox.php` (emit a string event type in the same transaction), `app/Platform/Contracts/ErrorCode.php` + `ErrorCodesCommand` (`platform.edit_lock_lost`), `config/dashflow.php:74` (`tunables.timeouts.edit_lock_ttl`, `DASHFLOW_EDIT_LOCK_TTL`), `tests/Feature/DashflowTunablesTest.php` -- kernel plumbing; no EditLock code exists yet
- `app/Modules/Connector/Application/ManageDataSources.php` (`update` ~143 with `fetch(..., true)` and the revision compare ~148), `Contracts/{DataSource,DataSources,DataSourceRevisionConflict}.php`, `app/Http/Controllers/Admin/DataSourceController.php` (`show` 68, `update` 92, the 409 envelope), `Requests/Admin/DataSourceRequest.php` (`revision()` 63), `Resources/DataSourceResource.php`, `app/Http/Responses/AdminApiError.php` -- where the epoch check and the 423 go
- `routes/api.php:59-66`, `app/Http/Navigation/ShellNavigation.php` (`ADMIN_API_ROUTES` 90-95), `tests/Database/AdminAccessTest.php` (route count at 130, status `match` at 165-182), `tests/Security/CoverageManifestTest.php`, `tests/Architecture/AdminRoutesTest.php` -- new routes and the gate matrix
- `app/Modules/Identity/Http/IdleTimeout.php:23` (`BACKGROUND_HEADER`), `resources/js/lib/session.ts` (`X-Background`), `composables/useSessionExpiry.ts`, `components/SessionExpiryDialog.vue`, `lib/{formDrafts,unsavedForms}.ts` -- session reuse; `resources/js/pages/admin/DataSourceForm.vue` (`load` 326, `fill` 182, `submit` 812, `refused` 905, `registerUnsavedForm` 1183, `onBeforeUnload` 1159), `lib/dataSources.ts` (`DataSourceError`, `updateDataSource` 272), `locales/{en,labels}.ts` (`draft-locked`, `draft-taken-over`, `session-warning`, `session-expired` at en.ts:155-165), `components/{ConfirmDialog,BlockedReason}.vue` -- UI
- `tests/Database/DataSourcesTest.php` (`dsAdmin`, `dsMember`, `DS_HEADERS`), `tests/Pest.php` (Database test list), `phpunit.xml` (`CACHE_STORE=array`), `tests/js/{data-sources,session-expiry}.test.ts` -- test patterns

## Tasks & Acceptance

**Execution:**
- [x] migration (`data_sources.lock_epoch`), kernel `app/Platform/EditLock` (acquire, heartbeat, release, take-over, flush acknowledgement, completion, outbox event), settings (flush timeout), error code -- the lock
- [x] Data Source epoch and token compare-and-set in `ManageDataSources::update` and the controller, the 423 envelope, lock routes, gate mapping, README and compose -- API
- [x] `resources/js` (lock acquire and heartbeat, read-only banner form, take-over wait, flush on request, taken-over notice, beacon release), `lib/dataSources.ts`, `labels.ts`, generated error codes -- UI
- [x] `tests/Unit`, `tests/Database`, `tests/Architecture`, `tests/Security`, `tests/js` -- every matrix row, including the session-expiry and no-restore check on the form

**Acceptance Criteria:**
- Given two Admins on the same Data Source, when the second takes over, then the first's valid non-secret fields are saved, the epoch rises, the first's later save gets 423 without storing any secret, and the second edits the post-flush revision.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| The take-over status poll grants a free lock using a client-chosen or empty token, and the token travels in the query string | high | patch | `EditLock::takeoverStatus` grants on a free lock; `pollTakeover` sends `?token=`; access logs record it. |
| `LockTimeoutException` from the cache lock is uncaught and returns a generic 500 | medium | patch | `exclusive()` blocks up to 5 s; the controller has no catch. |
| A same-membership re-acquire drops a third person's pending take-over; a taker retrying its own request gets `held` | medium | patch | `grant()` sets `flush = null`; `requestTakeover` returns `held` for any pending flush. |
| A completed take-over leaves no outbox or audit trail | medium | patch | Only `platform.edit_lock.flush_requested` is emitted; the epoch rise and the acknowledged-or-discarded outcome are not recorded. |
| A failed acquire leaves an editable form that ends in a 423 that wipes secrets; bfcache restore leaves a tokenless form; hidden tabs can lose the lock; whole-second heartbeat floor; a 409 during flush loses the holder's work and the baseline is not updated | medium | patch | `acquire()` catch leaves `mode: off`; no `pageshow` or `visibilitychange` handler; `flushSave()` gives up on any non-422 error. |
| Unmounting the form without `pagehide` is never shown to release the lock | medium | patch | The two release tests fire `pagehide` first; deleting `releaseNow()` from `onBeforeUnmount` leaves them green. |
| A malformed TTL or flush timeout silently disables the lock; no guidance ties the flush timeout to the heartbeat interval; one shared throttle bucket | medium | patch | `EditLockSettings` returns null for any unparsable value; the README is silent; the lock routes share a bucket with 1 s polls. |
| Cache write inside the DB transaction can disagree with a rolled-back request, and an epoch committed before a cache failure leaves the old holder believing it holds the lock; a cache eviction turns the holder's next save into a 423 | medium | defer | Needs a transactional or DB-backed lock record; the epoch and token checks still prevent any overwrite, so the risk is lost typed work, not lost data. |
| A completed take-over can hand the lock to a taker whose tab died; the epoch is not raised when a lock merely expires; `holds()` reads without the exclusive lock; 422 can outrank 423 for a lost holder; no gate-matrix rows named for the lock routes | low | rejected | The TTL clears a dead taker's lock, expiry already invalidates the old token, the epoch compare serialises under the row lock, validation order is a minor UX point and the gate matrix is generated from routes. |

## Design Notes

The architecture sends the flush request to the holder as a realtime push with polling as the same-semantics fallback; no realtime client or channel auth exists here, so polling is the only transport and the Outbox event keeps the push path open for a later consumer. With the TTL unset the lock is off rather than invented, matching every other `pending_input` setting, and `revision` still protects against overwrites. The epoch lives in PostgreSQL because the cache may evict or lose a lock, and a write check must never rely on it.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
