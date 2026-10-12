---
title: 'Autosave continuously and recover from expiry, offline and save failure'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: 'ba279cbc965f6dd943ff60cf0d25f3e14602e753'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-4-configure-block-step-1-with-a-live-preview.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-3-start-save-and-discard-a-draft-block.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The wizard only saves when the Admin presses Save draft, so a session expiry, a dropped connection or a failed save can lose work, and 409/423 handling is a generic toast.

**Approach:** Add debounced compare-and-set autosave to the wizard (background header, `draft_ui_state` for step/acknowledged warnings/slot statuses), flush hooks for session-expiry, sessionStorage replay after sign-in, offline behaviour, inline save-failed with Retry, a `blocks.draft_published` stop, and unsaved-changes handling on leave.

## Boundaries & Constraints

**Always:** Debounce is a `pending_input` tunable (`tunables.timeouts.autosave_debounce`, env `DASHFLOW_AUTOSAVE_DEBOUNCE`, documented in .env.example, shared to the client); unset means autosave is inert (manual Save draft still works) — no invented value. Autosave sends one CAS save with `expected_revision` and `lock_epoch` through `/api/v1` with `X-Background: 1` (so it does not extend the idle session); manual Save draft/Continue must NOT send it. `save` accepts `ui_state` (step, acknowledged warnings, slot statuses) stored in `draft_ui_state.state`, secret-refused, never part of version config or diff/audit. Session expiry: add a flush registry to `useSessionExpiry` called when the warning opens and again shortly before expiry (a few seconds, not an invented large value — use a named constant); unsaved secret fields are never autosaved or put in sessionStorage. On a 401 save, keep the pending delta (non-secret, owner-hashed) in sessionStorage; after sign-in replay it with CAS, reopen at the stored step, and show `session-expired` only if the flush was acknowledged; a replay revision conflict shows both versions and lets the Admin choose, never silently overwriting. New server code `blocks.draft_published` (409): save returns it when the version is no longer a Draft (discarded stays 404); the wizard stops saving, says the Draft was published and links to Published blocks. Offline: `offline-editing` banner, editing continues, Save draft, Fetch, Test connection and Publish are `aria-disabled` with that reason inline until reconnect, then pending changes save in order. Other save failure: `save-failed` inline beside Save draft with Retry, every change kept, focus moves to the message. Leaving with unsaved changes (Back or navigation): `unsaved-changes` dialog with Save draft, Discard changes, Keep editing (focus Keep editing); no changes returns directly (register with `unsavedForms`). Split 409 on code (`blocks.revision_conflict` vs `blocks.draft_published`). Regenerate error codes; add labels to `blockWizardLabels`.

**Never:** No lock/heartbeat/takeover (3.6), no publish state or CHECK change (3.19), no secrets in sessionStorage or autosave, no invented debounce number, no change to Data Source form behaviour.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Autosave | edit, debounce elapses | one CAS save, background header | failure → save-failed inline |
| Debounce unset | no tunable | no autosave; manual save works | N/A |
| Expiry warning | warning opens / just before expiry | pending changes flushed | secrets excluded |
| 401 on save | session expired | delta kept in sessionStorage; after sign-in replayed with CAS, step restored, `session-expired` only if acknowledged | replay conflict → choose between versions |
| draft_published | save → 409 `blocks.draft_published` | saving stops, message + Published blocks link | N/A |
| Offline | connection lost | banner; gated actions aria-disabled with reason; edits continue; reconnect saves in order | N/A |
| Other failure | 5xx | inline `save-failed` + Retry, focus there, changes kept | N/A |
| Leave dirty | Back/navigate | unsaved-changes dialog, Keep editing focused | N/A |
| Leave clean | Back | returns directly | N/A |
| ui_state with secret | secret-valued key | 422, nothing stored | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/pages/admin/BlockWizard.vue` (`persist()`), `lib/blockWizard.ts` (`call`, `saveWizardDraft`, `SaveBody`), `components/wizard/*` -- wizard to extend; 409 currently one generic message
- `resources/js/composables/{useSessionExpiry,useConnection,useOfflineAction,useBlockedAction}.ts`, `components/{ConnectionBanner,UnsavedChangesDialog,SessionExpiryDialog}.vue` -- add flush registry; reuse offline/unsaved pieces
- `resources/js/lib/{session,formDrafts,unsavedForms,editLock}.ts` -- `X-Background` precedent, `scrubSecrets`/owner hashing, `registerUnsavedForm`
- `app/Modules/Identity/Http/IdleTimeout.php` -- server already skips idle clock for `X-Background: 1`
- `app/Modules/Blocks/{Contracts/ErrorCode.php,Contracts/Drafts.php,Application/ManageDrafts.php}`, `app/Http/Controllers/Admin/DraftController.php`, `DraftRequest`, `DraftResource` -- ui_state, draft_published; `draft_ui_state.state` jsonb exists
- `config/dashflow.php`, `.env.example` -- tunable convention (unset = inert, e.g. `edit_lock_ttl`); `HandleInertiaRequests` for sharing
- `resources/js/locales/{en.ts,labels.ts}` -- `session-expired`, `offline-editing`, `save-failed`, `unsaved-changes` exist; add draft-published/conflict-chooser labels
- `tests/js/{block-wizard,session-expiry,edit-lock}.test.ts`, `tests/Database/{BlockWizardTest,DraftBlocksTest}.php` -- fake timers, `setConnectionForTest`, sessionStorage leak scans

## Tasks & Acceptance

**Execution:**
- [x] server: `ui_state` on save, `blocks.draft_published` code/exception/409 mapping, tunable + share, regenerate error codes
- [x] client: autosave scheduler (CAS, background header), flush registry in `useSessionExpiry`, sessionStorage delta + replay + conflict chooser, offline gating, inline save-failed, draft-published stop, unsaved-changes guard
- [x] labels/locale additions; `.env.example`
- [x] Tests: Vitest for each matrix row (fake timers, offline, 401 replay, secrets never stored), Database tests for ui_state/secret refusal/draft_published

**Acceptance Criteria:**
- Given the I/O matrix rows, when exercised, then each outcome holds.
- Given the full suite, when `bin/tools composer ci:check` runs, then it passes.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| sessionEnded redirect blocked by own beforeunload prompt | medium | patch | `leaving = true` set before the sign-in redirect |
| Both expiry flush branches fire in the same tick | low | patch | late flag now set when the warning opens within the lead window |
| Flush failing online never exercised the stash branch | medium | patch | added online-failure flush test |
| ui_state secret refusal not visible in diff | false | rejected | existing Database test asserts 422 SecretValuesRefused and passes in ci:check |
| Reconnect save with autosave inert | false | rejected | spec: unset debounce means autosave inert, manual save still works |
| slot_statuses empty encodes as list on decode, single PENDING slot, replay re-401 base revision, non-GET leave replay, double-save, autosave retry/backoff, 404 dirty drop, beforeunload test, owner-drop test, chooser fields, X-Background server side, Published URL, notADraft any non-discarded | low/maybe-false | defer | edge cases beyond the spec; recorded in deferred-work.md |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green
