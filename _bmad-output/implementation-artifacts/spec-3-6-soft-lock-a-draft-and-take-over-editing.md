---
title: 'Soft-lock a Draft and take over editing'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: 'dcffad385c7ea9d59c5fb348db359409dcbb955d'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-8-prevent-overwriting-with-a-soft-lock.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-5-autosave-continuously-and-recover-from-expiry-offline-and-save-failure.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Two Admins (or two tabs) can edit the same Draft; the epoch check only catches a stale write after the fact, with a generic toast and no read-only view, banner, heartbeat or take-over.

**Approach:** Reuse the generic server edit-lock kernel (Story 2.8, `app/Platform/EditLock`) for Drafts via Draft lock routes, enforce the lock token on Draft save, and give the wizard the same client behaviour the Data Source form has: acquire on open, heartbeat on user activity, read-only banner `draft-locked` with Take over editing / Close, flush on take-over request (autosave flush from 3.5), `draft-taken-over` only if the flush was acknowledged, release on leave (sendBeacon, token in body).

## Boundaries & Constraints

**Always:** Keep the registered lock type `draft_block` (`Drafts::LOCK_TYPE`, `DraftLockEpochs`); the AC wording `block_draft` is not renamed. Add routes `blocks/drafts/{draft}/lock` (acquire, heartbeat, release, takeover, takeover status, flush) mirroring `DataSourceLockController` (generalise into a shared base/trait or a thin Draft controller; Draft looked up via `Drafts::find` so another workspace is 404), same middleware plus a throttle bucket, `Permission::BlocksEdit` mapping in `ShellNavigation::ADMIN_API_ROUTES` and the coverage manifest. `DraftController::update` must check the `lock_token` like `DataSourceController::lockClaim` (missing/non-holding token or stale epoch → 423 `platform.edit_lock_lost`, nothing written); `DraftRequest` gains `lock_token`. When the tunable lock is disabled (`edit_lock_ttl` unset) behaviour is unchanged (epoch check only). Client: generalise `lib/editLock.ts` (base URL parameter) and extract the lock state from `DataSourceForm.vue` into a `useEditLock` composable plus a shared banner component, used by both the Data Source form (behaviour unchanged, its tests still pass) and `BlockWizard.vue`. Read-only view for B: fields and Save/Continue/Publish are inert, banner `draft-locked` with Take over editing and Close; every B write is refused server-side. The holder's take-over flush is the wizard's autosave flush; A sees `draft-taken-over` only when the flush was acknowledged, otherwise the lost-lock notice with no claim of saving; later writes with the old epoch get 423. Two tabs of the same Admin are two editors. Autosave/Save send `lock_token` and `lock_epoch`; the 3.5 423 handling shows the lost-lock state instead of a bare toast. Take-over and flush audit events already exist in the kernel; lock keys/channel are workspace-prefixed there. Add labels (draft-specific wording for lost/read-only/unavailable) to the label catalogue.

**Never:** No change to lock kernel semantics, TTL tunable or its default, no invented timing numbers (use existing tunables/constants), no publish-state change (3.19), no secrets in lock payloads or logs, no Data Source form behaviour change.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Open free Draft | no holder | lock granted, editable, heartbeat on activity | lock disabled → editable as before |
| Open held Draft | A holds, B opens | B read-only, `draft-locked` banner, Take over / Close | B write → 423 `platform.edit_lock_lost`, nothing changed |
| Holder idle/closed | no activity for TTL, or release beacon | lock released, B can open for editing | N/A |
| Take over | B chooses, A acknowledges flush | epoch incremented, B loads post-flush revision, A sees `draft-taken-over` | A's old-epoch write → 423 |
| Take over, A offline | flush timeout | B proceeds; A sees lost-lock notice on reconnect, no "saved" claim | N/A |
| Same admin, two tabs | second tab opens | treated as another editor, same banner | N/A |
| Take-over completes | audit | audit event recorded, keys workspace-prefixed | N/A |
| Other workspace | foreign draft id | 404 | N/A |

</frozen-after-approval>

## Code Map

- Server kernel (reuse): `app/Platform/EditLock/*`, `app/Modules/Blocks/Infrastructure/DraftLockEpochs.php`, registered in `AppServiceProvider.php`(~276, ~257 limiter); `config/dashflow.php` (`edit_lock_ttl`, `edit_lock.flush_timeout_seconds`)
- Precedent to generalise: `app/Http/Controllers/Admin/DataSourceLockController.php`, `DataSourceController::lockClaim`, `routes/api.php`(108-115 lock routes; 78-84 drafts routes), `app/Http/Navigation/ShellNavigation.php`(ADMIN_API_ROUTES), `tests/Security/CoverageManifestTest.php`
- Draft side: `DraftController::update`, `app/Http/Requests/Admin/DraftRequest.php`, `ManageDrafts::save` (`DraftLockLost`), `DraftResource`
- Client: `resources/js/lib/editLock.ts` (lockUrl is Data-Source-specific), lock state inline in `pages/admin/DataSourceForm.vue`(~1308+), `pages/admin/BlockWizard.vue`(423 handling ~545, autosave flush from 3.5, `lib/flushRegistry.ts`), `lib/blockWizard.ts`, `locales/{en,labels}.ts` (`draft-locked`, `draft-taken-over`, `editLockLabels`)
- Tests: `tests/Database/EditLockTest.php` (patterns), `tests/Database/DraftBlocksTest.php` helpers, `tests/js/edit-lock.test.ts` (fetch mock, beacon mock), `tests/js/block-wizard*.test.ts`

## Tasks & Acceptance

**Execution:**
- [x] server: Draft lock routes/controller (shared with Data Source), throttle bucket, permission mapping, lock-token check in `DraftController::update`/`DraftRequest`
- [x] client: generalise `editLock.ts`; extract `useEditLock` + banner; keep Data Source form working; wire BlockWizard (acquire, heartbeat, read-only, take over with 3.5 flush, taken-over/lost notices, release beacon); labels
- [x] Tests: Database tests for each server matrix row on Drafts; Vitest for wizard locked/holder/take-over/offline rows; existing edit-lock tests still pass

**Acceptance Criteria:**
- Given the I/O matrix rows, when exercised, then each outcome holds.
- Given the full suite, when `bin/tools composer ci:check` runs, then it passes.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| First save after create sent without token when acquire failed | medium | patch | save() now returns when read-only; create-path tests added |
| lock_token secret-filter exemption applied to every route | medium | patch | scoped to the blocks profile; negative unit rows added |
| Discard not lock-checked server-side | medium | patch | destroy enforces lock claim; client retries with token; tests added |
| Failed post-take-over reload swallowed | medium | patch | reload returns success; lock released on failure |
| handover flush lacked flush recovery (stash/session-ended) | medium | patch | handover treated like flush in failed() |
| Replay gating and idle-resume untested | medium | patch | tests added |
| Stale docblocks in ResourceLockController | low | patch | made resource-neutral |
| Idle holder never sees flush request, heartbeat blocked during slow flush, ttl_seconds guard, a11y landmark/focus, steps 2-5 read-only, limiter/route duplication, DataSourceError coupling, release vs in-flight flush, minor test gaps | low/maybe-false | defer | recorded in deferred-work.md |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green
