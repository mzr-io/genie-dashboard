---
title: 'Warn before session expiry and keep forms safe'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: 'cf6c83c31a8f181ee67c9b1da07a606ab2918847'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-13-sign-in-as-user-or-admin.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-9-build-dialogs-toasts-banners-popovers-and-live-regions.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A session ends silently on the next request, background polling would keep it alive, and a signed-out person loses their place (Story 1.15; FR-2, NFR-4, AR-27, UX-DR-248, 249, 253, 282). The Story 1.15 section of `epics.md` holds the acceptance criteria.

**Approach:** An idle timeout per area tracked from user-initiated requests only, a status endpoint that never extends it, an extend endpoint, a warning dialog with a live announced countdown two minutes before expiry, and a 401 path that returns the person to the page they were on with `session-expired` and a restore hook for forms.

## Boundaries & Constraints

**Always:** The idle limit comes from the existing `pending_input` tunables `sessions.idle_admin` and `sessions.idle_user` (whole minutes, the unit of Laravel's `session.lifetime`), chosen by the session `area`; while unset, the existing `session.lifetime` applies, documented as the starter kit's fallback and not a Dashflow value. A middleware records `last_user_activity` in the session on user-initiated requests only; a request with header `X-Background: 1` never writes it (and so never extends the idle session), and neither does the status endpoint. Past the limit the middleware signs the person out and answers 401 for JSON and Inertia XHR requests, or a redirect to sign-in for page loads, storing the intended URL and a `session_expired` flash. `GET /api/v1/session` returns `{remaining_seconds, area}` without extending; `POST /api/v1/session/extend` (user-initiated, CSRF-protected) extends and returns the new remaining seconds. Both live in the Identity module, are covered by the `auth` guard and the existing Workspace transaction rules, and record `identity.session.extended` (add to `AuditAction`) as a security event only on extend. The warning dialog (reusing Story 1.9's `ConfirmDialog`-style one-deep dialog guard and announcer) opens when two minutes remain with `session-warning` and a live countdown, "Stay signed in" as primary with initial focus and "Sign out"; the countdown is announced at 2:00, 1:00 and 0:30 through the `once` policy; extending closes it and returns focus to the previously focused element; the client polls the status endpoint with `X-Background: 1` at a low rate and when the tab becomes visible. After sign-in following expiry the person lands on the page they were on with `session-expired` shown (toast), through Laravel's intended-URL mechanism in the sign-in response (area Overview remains the default when none exists). A page-level form-draft hook contract `registerFormDraft({id, snapshot, restore})` exists in `resources/js/lib/`: on expiry registered snapshots (never secret-typed fields, password inputs or fields flagged `data-secret`) are stored in `sessionStorage` under a key without personal data, and `restore` is invoked after sign-in if registered; forms that do not register are not restored (data source form, template editor, settings), and the Create-block wizard's own autosave is out of scope. All strings come from the catalogue or `labels.ts`.

**Never:** Wizard autosave (Epic 3); a "remember" extension beyond Story 1.13's tunable; changing sign-in or reset behaviour other than the intended URL and flash; storing form values anywhere but `sessionStorage` for the draft hook; polling without `X-Background: 1`; invented timeout values.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Warning | Two minutes remain | Dialog with `session-warning`, live countdown, focus on "Stay signed in"; announced at 2:00, 1:00, 0:30 | N/A |
| Stay signed in | User activates it | POST extend; dialog closes; focus returns to the prior element | Failure keeps the dialog and shows the error |
| Background call | Request with `X-Background: 1` | Never extends the idle session | N/A |
| Status call | `GET /api/v1/session` | `{remaining_seconds, area}`; not extended | Guests get 401 |
| Expiry | No activity until the limit | Next request 401 or redirect; after sign-in the person lands on their page with `session-expired` | Area Overview when no intended URL |
| Unsaved form | Registered draft and expiry | `restore` called after sign-in; password and secret fields never stored | Unregistered forms not restored |
| Limits | Tunables unset or set per area | Fallback `session.lifetime`, or the area limit | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Identity/` -- Stories 1.12 to 1.14 (`Application`, `Http`, `Contracts`, `Infrastructure`); add the idle-timeout middleware, session controller and routes; `bootstrap/app.php` -- middleware group registration beside `WorkspaceTransaction`; `routes/web.php`, `routes/api.php` -- existing; `config/dashflow.php` -- `sessions.idle_admin`, `idle_user`; `config/session.php` -- `lifetime`
- `app/Modules/Identity/Application/SignIn.php`, `Http/SignInResponse.php` -- sign-in response; use `redirect()->intended()` only when an intended URL exists
- `resources/js/lib/announce.ts`, `stores/dialogs.ts`, `composables/useDialogSlot.ts`, `components/ConfirmDialog.vue`, `stores/toasts.ts` -- Story 1.9 pieces; `layouts/app/AppSidebarLayout.vue`, `AppHeaderLayout.vue` -- mount the session watcher; `locales/en.ts` keys `session-warning`, `session-expired`
- `app/Platform/Audit/AuditAction.php`, `tests/Feature/DashflowTunablesTest.php` -- action and tunables; `tests/Feature`, `tests/Database`, `tests/js` -- existing suites

## Tasks & Acceptance

**Execution:**
- [x] `app/Modules/Identity`, `bootstrap/app.php`, `routes/api.php`, `AuditAction` -- idle middleware, status and extend endpoints, 401 path, intended URL and flash
- [x] `resources/js/lib`, `components`, `layouts`, `labels.ts` -- session watcher, warning dialog, countdown announcements, form-draft hook, toast after sign-in
- [x] `tests/Feature`, `tests/Database`, `tests/js`, `README.md` -- every matrix row

**Acceptance Criteria:**
- Given a session near its idle limit, when two minutes remain, then the warning appears and "Stay signed in" extends it.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Drafts in `sessionStorage` carry no owner, so one person's unsaved values are restored for the next person to sign in on the tab; a failed restore deletes the draft; saving with an empty registry wipes unrestored drafts | high | patch | `formDrafts.ts` stores under a fixed key with no owner tag and overwrites the whole key. |
| Audit write on extend runs inside the request's PostgreSQL transaction; a failed insert aborts it so the commit fails | high | patch | `SessionController::record` swallows `Throwable` but there is no savepoint. |
| A network failure or 5xx at zero ends a live session; a slow status response overwrites an extend | high | patch | `evaluate()` calls `end()` when the confirming call fails; no sequence guard on responses. |
| Announcements at 2:00, 1:00 and 0:30 fire together with false times when joining mid-countdown; limits of 1 to 2 minutes reopen the dialog at once | medium | patch | Every mark with `left <= mark` fires on first evaluation; the warn window is not clamped to the limit. |
| Sign out leaves the ticker running (dialog reopens, drafts re-saved) and has no failure path | medium | patch | `signOut()` does not set `ended`. |
| Secret filter is an unanchored substring match (drops `passenger`, `tokenizer`; misses `cvv`, `card_number`, `otp`); checkbox groups and multi-selects keep one value | medium | patch | `formDrafts.ts`. |
| Intended URL can be a JSON endpoint, a prefetch, the login page, the other area, or a Referer with another scheme or port | medium | patch | `IdleTimeout::intendedUrl` compares host only; `SignInResponse` does not check the area. |
| `POST /api/v1/session/extend` is unthrottled and audits every call; an idle tunable above `session.lifetime` silently loses the warning | medium | patch | No `throttle` middleware; `IdleLimit` does not cap at the lifetime. |
| Layout mounting of the dialog, the Inertia 401 handler and re-sync, and Sign out are untested | medium | patch | Tests mount the dialog directly; removing it from a layout keeps tests green. |
| Dialog refusal while another dialog is open | low | defer | The guard's own contract (Story 1.9). |
| No absolute session cap | medium | defer | Spec defines only an idle timeout; an absolute maximum needs a product decision and a new tunable. |
| Any Inertia 401 ends the session | low | rejected | Spec: the next request returns 401 and the person is sent to sign-in. |
| Remember-me and legacy sessions get a fresh clock; draft TTL and restore notice; toast text imported directly; JSON sign-in path; plain form POST on expiry; extra status requests per visit | low | rejected | A TTL would be an invented value; the rest is polish or outside the story. |

## Design Notes

Laravel rewrites the session's `last_activity` on every request, including background ones, so the idle clock is a separate session key written only by user-initiated requests. The fallback to `session.lifetime` is the starter kit's existing 120 minutes; the idle tunables replace it per area once set.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
