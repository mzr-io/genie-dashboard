---
title: 'Deactivate and reactivate users'
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: '8e95dd060489c7d17e4e622d9d14d3931b585ff6'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-22-assign-roles-and-admin-permissions.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-23-organise-users-into-groups.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-13-sign-in-as-user-or-admin.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-19-enforce-admin-only-access-with-area-and-permission-checks.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Former staff keep access: an Admin cannot remove someone's access to the Workspace without deleting history, and nothing ends their open sessions (Story 1.24; FR-6, FR-3, NFR-4, AR-6, AR-38, UX-DR-263, 271, 282). The Story 1.24 section of `epics.md` holds the acceptance criteria.

**Approach:** Deactivate and reactivate endpoints that change only the membership status (keeping role, permissions and groups) under the same rules as role changes, revoke the member's sessions for that Workspace, make every request fail closed for a deactivated membership, and add the confirm dialog, row highlight and rollback behaviour to the User configuration page.

## Boundaries & Constraints

**Always:** `POST /api/v1/admin/members/{membership}/deactivate` and `/reactivate` (route names `api.admin.members.deactivate` and `api.admin.members.reactivate`, permission `users.manage`, added to `ShellNavigation::ADMIN_API_ROUTES` and the Story 1.19 test matrix) take the current `revision` (stale returns 409 with the current state, as in Story 1.22), lock the target row, bump the `revision`, set `status` to `deactivated` or back to `active`, and change nothing else: role, permissions and group memberships are kept and come back on reactivation. Deactivating the caller's own membership returns 403 `access.self_change_forbidden`; deactivating the only active holder of `users.manage` returns 409 `access.last_users_manage_holder` (the recount runs under the locks, shared with Story 1.22's rule); deactivating an already deactivated or reactivating an already active membership is a 200 no-op without audit; a membership of another Workspace is 404. Deactivation audits `access.membership.deactivated` and reactivation `access.membership.reactivated` (IDs and enums only, no emails) with an outbox event of the same type in the same transaction; refused attempts are recorded as in Story 1.22. A deactivated member's sessions for this Workspace are revoked in the same request: the application reads the user's rows in `sessions`, decodes each payload without instantiating classes (`unserialize` with `allowed_classes => false`), and deletes those whose stored `workspace_id` is this Workspace (the `app` role may delete `sessions` rows); sessions that cannot be decoded are left alone and covered by the next rule. Independently of that deletion, every authenticated request re-checks that the session's Workspace membership is active (a middleware beside `RequireAdminAccess` on the web and API groups): when it is not, the Workspace and area keys are dropped, the response is 401 for JSON, API and Inertia XHR requests and a redirect to sign-in for page loads, so the member's next request fails and no work in progress continues. Sign-in into a Workspace where the membership is deactivated is refused with the `signin-failed` wording (Story 1.13 already requires a usable membership; add tests), the Workspace switcher never offers it, and a person who belongs to another Workspace can still sign in to that one. A pending invitation is "deactivated" by the existing revoke action (Story 1.21); its row keeps Resend and Revoke. The User configuration page offers Deactivate on active members and Reactivate on deactivated ones, never on the Admin's own row; Deactivate opens an alertdialog naming the member and the impact with initial focus on Cancel (UX-DR-271, reuse `ConfirmDialog`); on success the list refreshes, the affected row is highlighted (accent-soft fill with a leading bar), focused and `saved` is announced politely; on failure the row rolls back to its previous state and an error toast with `toast-rollback` is shown and not auto-dismissed, and 409 and 403 reasons show inline. Data owned by a deactivated user is untouched; this story writes no deletes outside `sessions`. All strings come from the catalogue or `labels.ts`.

**Never:** Deleting memberships, users or any data; sending email; hiding deactivated members from the list; changing a deactivated member's role or permissions (Story 1.22 refuses it); deleting sessions of other Workspaces; trusting the client for the member's status.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Deactivate | Active member, current revision, confirmed | Status `deactivated`, revision + 1, sessions for this Workspace deleted, audit and outbox | N/A |
| Next request | Deactivated member's open session | 401 (JSON) or redirect to sign-in; keys dropped | Fail closed |
| Sign-in | Deactivated membership | `signin-failed` wording; other Workspace still works; not in the switcher | N/A |
| Reactivate | Deactivated member | Active again with previous role, permissions and groups; `access.membership.reactivated` | N/A |
| Self | Caller's own membership | 403 `access.self_change_forbidden` | N/A |
| Last holder | Only active `users.manage` holder | 409 `access.last_users_manage_holder`; nothing changes | Inline explanation |
| Stale revision | Old `revision` | 409 with current state | Nothing changes |
| Repeat | Already in the target status | 200 no-op, no audit | N/A |
| Pending invitation | Revoke from the row | Link shows the expired response | Story 1.21 |
| UI success | After a save | Row highlighted and focused; `saved` announced | N/A |
| UI failure | Request fails | Row rolls back; `toast-rollback` not auto-dismissed | N/A |
| Cross-Workspace | Another Workspace's membership id | 404 | RLS |
| Other sessions | Sessions of the user for other Workspaces | Untouched | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Access/Application/ChangeMemberAccess.php`, `Contracts/MemberAccess.php`, `ChangeMemberAccess` locks and last-holder recount, `revision` handling, refused-attempt audit -- reuse the lock helper and exceptions; `app/Http/Controllers/Admin/MemberController.php` (`update`) and `UpdateMemberRequest` -- pattern for the two new actions
- `app/Http/Middleware/RequireAdminAccess.php`, `IdleTimeout.php`, `bootstrap/app.php`, `app/Http/Navigation/ShellNavigation.php` (`ADMIN_API_ROUTES`) -- per-request middleware and the gate mapping; `app/Modules/Identity/Application/SignIn.php`, `SwitchWorkspace.php`, `SignInMemberships` -- usable-membership rules; `sessions` table (global, `app` may DELETE)
- `app/Modules/Access/Infrastructure/SqlMemberDirectory.php`, `access_workspace_members` view (maps non-active to `deactivated`), `MemberResource` -- list status
- `app/Platform/Audit/AuditAction.php` (`access.membership.deactivated`, `reactivated` exist), `app/Platform/Outbox/Outbox.php`
- `resources/js/pages/admin/Users.vue`, `components/MemberAccessEditor.vue`, `ConfirmDialog.vue`, `DataTable.vue`, `lib/members.ts`, `stores/toasts.ts`, `lib/announce.ts`, `locales/en.ts` (`toast-rollback`, `saved`), `labels.ts`; `tests/Database/`, `tests/js/`

## Tasks & Acceptance

**Execution:**
- [x] `app/Modules/Access`, `app/Http`, routes, mapping, audit and outbox, session revocation, membership-active middleware -- deactivate and reactivate
- [x] `resources/js` (Deactivate and Reactivate actions, alertdialog, row highlight and focus, rollback toast), `labels.ts` -- UI
- [x] `tests/Database`, `tests/Feature`, `tests/js`, `tests/Architecture`, `README.md` -- every matrix row

**Acceptance Criteria:**
- Given an Admin with `users.manage`, when they confirm deactivating a member, then the member's sessions for the Workspace end, their next request fails and sign-in into it is refused, and reactivation restores their previous access.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

- **Review iteration 1 (clarifications, frozen block untouched).** (a) "Active" is the only status that lets a session continue: the per-request middleware refuses `deactivated` and every other non-`active` status, and a session whose Workspace has no membership for the person at all, with 401 or a sign-in redirect. (b) A transient failure of the membership read answers 503 and keeps the session (fail closed without destroying state). (c) Ending the login (web guard logout and session regeneration) rather than only dropping the Workspace keys is intended: the person signs in again and reaches any other Workspace they still belong to. (d) A repeated deactivation is a 200 no-op that still runs session revocation, so leftover sessions are cleaned up.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| `RequireActiveMembership` refuses only the literal status `deactivated`, and lets through a session whose Workspace has no membership for the person; `ChangeMemberStatus` and the README treat any non-`active` status as deactivated | high | patch | A `suspended` or removed member keeps a live User-area session; the Admin gate does not cover User pages. |
| A transient error in the membership read logs the person out, regenerates the session and drops the Workspace keys | high | patch | `isActive` returns false on any `Throwable`; one database blip ends every active session. |
| Session revocation fails silently (encrypted payloads, non-database driver, undecodable rows) and a repeated deactivation skips revocation | medium | patch | `DatabaseSessionRevocation` returns quietly; the no-op path never revokes. |
| The under-lock `EditorNotAuthorized` path, the 429, a `suspended` status through the middleware, reactivation restoring sign-in, and the 401, 419 and 404 branches of `changeStatus` have no tests; last-holder predicate duplicated and tested by reflection | medium | patch | Verification-gap layer; the "requires users.manage" test is stopped by the route gate. |
| Row rollback restores a stale snapshot; a failed refresh leaves the optimistic status; success announcement is the generic `saved`; the highlight never clears; a click with no id or revision does nothing; 429 shows the generic rollback toast | medium | patch | `Users.vue` `changeStatus`; `DataTable` highlight. |
| README places the Story 1.24 text as one long bullet under the wrong section; refusal order differs between README and docblock | low | patch | Docs. |
| An extra membership read on every authenticated request | low | rejected | Load readiness is Story 1.25; the read is the fail-closed control. |
| `AccessChange` carries a nullable `status`; reactivate treats `suspended` as deactivated; the last-holder 409 is unreachable over HTTP | low | rejected | One shape for both stories; `suspended` is not a user-facing state yet; the recount is defence in depth, as in Story 1.22. |

## Design Notes

The `sessions` table has no Workspace column (the active Workspace lives in the serialized payload), so deleting "this Workspace's sessions" needs a decode; the per-request membership check is the guarantee, the deletion is hygiene that also frees the session rows. Reactivation keeps role, permissions and groups because deactivation only changes the status.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
