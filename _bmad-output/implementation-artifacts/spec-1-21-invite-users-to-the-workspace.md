---
title: 'Invite users to the Workspace'
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: '71821bb198cc32ca0632e27eea7a2468afcdfcb9'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-12-provision-a-workspace-and-let-its-first-admin-accept-an-invitation.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-19-enforce-admin-only-access-with-area-and-permission-checks.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-20-list-the-workspaces-users-in-user-configuration.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Only an operator can bring the first Admin into a Workspace; Admins cannot invite anyone, there is no guard on which permissions an inviter may hand out, and the accept flow supports only Admin invitations with every permission (Story 1.21; FR-6, NFR-4, AR-38, AD-31, UX-DR-23, 143, 282). The Story 1.21 section of `epics.md` holds the acceptance criteria.

**Approach:** An Admin with `users.manage` invites by email with a role and, for Admin, permissions capped at their own and confirmed with their password; invitation rows are created, resent and revoked through database functions bound to the active Workspace; acceptance honours the invited role and permissions; the User configuration page gains the invite form and the Resend and Revoke actions.

## Boundaries & Constraints

**Always:** `POST /api/v1/admin/invitations` (create), `POST /api/v1/admin/invitations/{invitation}/resend` and `DELETE /api/v1/admin/invitations/{invitation}` are Admin routes behind the Story 1.19 gate with permission `users.manage` (added to the route-to-permission mapping), and no Workspace Admin permission beyond `users.manage` is required. Role `app` still cannot INSERT or UPDATE `invitations` freely: creation, replacement and revocation go through `SECURITY DEFINER` functions owned by `migrator` and executable by `app` that read `current_setting('app.workspace_id', true)` (they refuse when it is unset and never accept a Workspace id argument), insert `workspace_id` from that setting, the hashed token, role, a `permissions` jsonb (only catalogue values), `created_by` and the expiry; a new nullable `revoked_at` column exists and usable invitations require `used_at` and `revoked_at` null and `expires_at` in the future. The token is 256 bits, only its SHA-256 hash is stored, the plain token exists only in the email, and the expiry uses the existing `invitation_lifetime` tunable (when unset the API answers 422 with a clear "invitations are not configured" error and creates nothing). The form takes email, role `user` or `admin` and, for `admin`, permissions from the closed catalogue: only permissions the inviter holds (read per request) are offered, and a crafted request carrying others returns 403 with `access.permission_not_held` (the existing `Access\Contracts\ErrorCode` case); a `user` invitation carries no permissions. Any Admin invitation that grants a permission requires password re-confirmation: the request carries `confirm_password`, validated against the inviter's current password with the Story 1.14 throttling, and a wrong value blocks the invitation with a field error and creates nothing; the UI prompts for it before sending. An invalid email, an email that is already a member of this Workspace, or one with a pending invitation gives a field error and creates nothing; for a duplicate pending invitation the response says so and offers Resend, which replaces the earlier token (the old link stops working, the row keeps one active token) and re-sends. Revoking sets `revoked_at` so the link shows the Story 1.12 expired response. Creation, resend and revoke each audit in the Workspace (`access.membership.invited` for create and resend, `access.membership.changed` for revoke, with before and after as IDs and enums only through the Access serializer) and emit an outbox event `access.membership.changed` (data: IDs and enums only) in the same transaction; the invitation email is sent after the transaction commits, and a delivery failure leaves the invitation `invited` with a "Resend" action and shows `save-failed` (form wording) with Retry. Acceptance (Story 1.12 flow) now honours the invitation: role `user` creates a `user` membership with no permissions; role `admin` creates an `admin` membership with exactly the invited permissions (the first-Admin operator invitation keeps all permissions); the unsupported-role refusal from Story 1.12 is removed; an invitation granting permissions the inviter no longer holds at acceptance time is capped at what the inviter currently holds. The invite UI expands inline on the User configuration page (not a modal), enables the "Invite user" action, uses the Story 1.8 and 1.9 form components, a role menu where each item shows a role chip and a one-line description (UX-DR-143), permission checkboxes limited to the inviter's own, the password prompt, focus on the first error, `saved` announcement on success, and the new row appears as Invited with Resend and Revoke actions (one-line descriptions and strings from the catalogue or `labels.ts`). The email addresses of invitees are never logged, audited in clear or placed in outbox data (hashes only), and the API never returns a token or its hash.

**Never:** Editing members, roles or permissions of existing members (Story 1.22); groups (Story 1.23); deactivation (Story 1.24); a modal invite form; sending mail inside the database transaction; accepting an invitation by anything but the emailed token; permission grants the inviter does not hold.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Invite user | Email, role `user` | Hashed invitation, email after commit, row shows Invited, audit `access.membership.invited`, outbox `access.membership.changed` | N/A |
| Invite admin | Role `admin` with held permissions and correct password | Same, with permissions stored | Wrong password blocks, nothing created |
| Over-grant | Permission the inviter lacks | 403 `access.permission_not_held` | Nothing created |
| Bad email | Invalid, existing member or pending | Field error; pending offers Resend | Nothing created |
| Resend | Pending invitation | New token replaces the old; old link expired; email re-sent | N/A |
| Revoke | Pending invitation | `revoked_at` set; link shows the expired response | N/A |
| Delivery failure | Mailer throws | `save-failed` with Retry; invitation stays Invited with Resend | Logged without the address |
| Accept user | Link for a `user` invitation | `user` membership, no permissions, Active | N/A |
| Accept admin | Link for an `admin` invitation | `admin` membership with exactly the invited permissions | Capped to the inviter's current permissions |
| Unset lifetime | `invitation_lifetime` unset | 422 "not configured"; nothing created | N/A |
| Cross-Workspace | Function called with no Workspace setting, or another Workspace's invitation id | Refused or 404 | N/A |
| Secret hygiene | Logs, audit, outbox, API | No token, hash or clear email | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Identity/Application/IssueInvitation.php`, `AcceptInvitation.php`, `InvitationToken.php`, `Contracts/InvitationIssuer.php`, `Infrastructure/InvitationMail.php`, `Http/InvitationController.php` -- Story 1.12 invitation issue and accept (operator path inserts as `operator`; accept refuses non-admin roles at `AcceptInvitation.php`)
- `app/Modules/Access/` -- `AdminMembershipGranter`, `Permission`, `MembershipPermissions`, `MemberDirectory`, `AccessAuditSerializer`, `ErrorCode`; add the invite application service in Access (Access may call Identity Contracts)
- `app/Http/Controllers/Admin/MemberController.php`, `routes/api.php`, `app/Http/Navigation/ShellNavigation.php` (`ADMIN_API_ROUTES`), `tests/Architecture/AdminRoutesTest.php`, `tests/Database/AdminAccessTest.php` -- Stories 1.19 and 1.20 gate, mapping and matrix; the new routes must be added to all of them
- `app/Platform/Audit/AuditAction.php` (`AccessMembershipInvited`, `AccessMembershipChanged` exist), `app/Platform/Outbox/Outbox.php`, `EventData.php` -- audit and outbox; `database/migrations/` (Stories 1.12, 1.20 functions) -- new migration with `permissions`, `revoked_at` and the functions
- `resources/js/pages/admin/Users.vue`, `components/InviteUserLink.vue`, `DataTable.vue`, `lib/members.ts`, `locales/en.ts`, `labels.ts` -- Story 1.20 page to extend; `GatedAction`, `FormField`, `useBlurValidation`
- `tests/Database/`, `tests/Feature/`, `tests/js/` -- existing suites

## Tasks & Acceptance

**Execution:**
- [ ] migration (`permissions`, `revoked_at`, create, replace and revoke functions), `app/Modules/Access`, `app/Modules/Identity`, `app/Http`, routes, mapping, audit and outbox -- invite, resend, revoke, caps, password confirmation, post-commit mail
- [x] `AcceptInvitation`, `AdminMembershipGranter` -- honour role and permissions at acceptance
- [x] `resources/js` (inline invite form, role menu, password prompt, row actions), `labels.ts` -- UI
- [x] `tests/Database`, `tests/Feature`, `tests/js`, `tests/Architecture`, `README.md` -- every matrix row

**Acceptance Criteria:**
- Given an Admin with `users.manage`, when they invite a person with a role and permissions they hold, then a hashed single-use invitation is emailed, the list shows Invited and the invitee can accept with exactly that access.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

- **Review iteration 1 (clarifications, frozen block untouched).** (a) The inviter of record is the Admin who last created or resent the invitation: a resend replaces the token and makes the resender the inviter (the resender must hold what the invitation grants), and the audit records both the previous and the new inviter. (b) An invitation whose inviter has since lost the active `admin` membership no longer creates a membership: acceptance is refused with the neutral expired response and a security event (reason `inviter_gone`); only the operator's first-Admin invitation (created_by `operator:<user>`) is uncapped, and that is explicit. (c) Password re-confirmation is required for every `admin` invitation, not only when permissions are ticked, because the Admin role itself is a privilege. (d) An address that has a membership of any status in the Workspace is refused at invite time.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| A missing, demoted or deactivated inviter still yields an Admin membership with zero permissions; any non-UUID `created_by` means "uncapped" and `access_create_invitation` never checks it is a membership of the Workspace | high | patch | `AdminMembershipGranter::capped()` returns the full list for null or non-UUID; the function accepts any varchar. |
| Acceptance guard for an existing member (a `user` invitation never touches an Admin; promote with exactly the invited permissions) and the resender-as-inviter rule have no test | high | patch | Existing tests only exercise the "create" outcome; removing `created_by = p_created_by` keeps tests green. |
| An address with an inactive or deactivated membership can be invited and is only refused at acceptance | medium | patch | `isMember` ignores membership status. |
| Password confirmation is skipped for an `admin` invitation with no ticked permissions; its throttle key is per user and cleared on success; Retry after a failed save clears the password; two competing retry paths after a delivery failure; 429 shown with no password field; Revoke has no throttle | medium | patch | `InvitationController::confirmPassword`; `InviteUserForm.vue`. |
| After-commit mail flushes on any status below 400 and its order before `WorkspaceTransaction` is only a comment | medium | patch | `SendInvitationsAfterCommit`; no architecture test pins the order or fails loudly when no flush runs. |
| A resend refused for permissions shows a generic message and strands the row; the permission catalogue is copied into five places | medium | patch | Needs a specific message; a test comparing the enum, the CHECK and `labels.ts`. |
| `AcceptInvitation` lost its explicit role guard; `reason` and `permission_count` audit fields are loosely typed | low | patch | Only the database CHECK guards the role now. |
| Mail is sent synchronously after commit; email probing by `users.manage`; expired unused invitations cannot be resent; member added between check and lock; invitation email wording; RoleMenu voice-control and typeahead | low | rejected | The spec asks for `save-failed` with Retry on delivery failure; Admins already see the member list; expired rows are hidden by design. |

## Design Notes

Invitations stay a global table without row-level security (Story 1.12), so every write the app performs goes through functions bound to the transaction's Workspace setting; that keeps one Admin from creating or revoking another Workspace's invitations even with a forged id. The email is sent after commit because mail inside the transaction would leave a link to nothing if the commit failed, which Story 1.12 recorded as a known weakness for the operator command.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
