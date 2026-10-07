---
title: 'Assign roles and Admin permissions'
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: 'f5be86568dc772ede1a7e8e488fa870d93995abe'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-21-invite-users-to-the-workspace.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-20-list-the-workspaces-users-in-user-configuration.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-19-enforce-admin-only-access-with-area-and-permission-checks.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Roles and permissions are fixed when a member joins; an Admin cannot change them, nothing stops the last person who can manage users from being downgraded, and two Admins can overwrite each other's edits (Story 1.22; FR-6, FR-5, NFR-4, AR-6, AR-38, AD-31, UX-DR-143, 254, 255, 271, 282). The Story 1.22 section of `epics.md` holds the acceptance criteria.

**Approach:** One Admin endpoint that changes a member's role and permissions under optimistic concurrency, the last-`users.manage`-holder and self-edit rules, the granter cap and password re-confirmation, with audit and outbox events in the same transaction, and an inline editor and downgrade dialog on the User configuration page.

## Boundaries & Constraints

**Always:** `PATCH /api/v1/admin/members/{membership}` (route name `api.admin.members.update`, permission `users.manage`, added to `ShellNavigation::ADMIN_API_ROUTES` and the Story 1.19 test matrix) takes `revision` (required), `role` (`user`|`admin`, optional) and `permissions` (array of catalogue values, optional, the complete desired set) and `confirm_password` (required whenever the permission set changes or a role changes to `admin`). `workspace_memberships` gains a non-null integer `revision` (default 1, new migration) incremented by every change; a stale `revision` returns 409 with the current member state (role, permissions, revision) and changes nothing, and the form shows `save-failed` (form wording) with the latest values. The transaction locks the target membership row and, for the last-holder check, the rows of every active membership holding `users.manage` in the Workspace. A change that would leave the Workspace without an active holder of `users.manage` (removing that permission, downgrading the role, or, for Story 1.24 to reuse, deactivating) returns 409 `access.last_users_manage_holder` with an inline explanation and changes nothing. A request targeting the caller's own membership returns 403 `access.self_change_forbidden` (nobody edits their own role or permissions). The granter cap applies in both directions: the Admin may add or remove only permissions the Admin holds, read per request; any other change returns 403 `access.permission_not_held`. A `user` role holds no permissions (changing to `user` clears them; setting permissions on a `user` member is refused 422 and the editor is disabled with a reason in the UI); changing to `admin` starts from the permissions given, possibly none. The password is the caller's current password, validated with throttling keyed on user and IP that a correct guess does not clear. Role changes audit `access.role.changed` and permission changes `access.permission.changed` (before and after as enums and counts, and the permission names as an enum list through the Access serializer, never emails), each with an outbox event of the same type (IDs and enums only), in the same transaction; no change (same role and set) is a 200 no-op without audit. The next request of the affected member reflects the change: nothing is cached (Story 1.19 reads the membership and permissions per request), and a member downgraded to `user` while in the Admin area is denied on the next Admin request. The Users page gets a Roles & permissions editor that expands inline on a row (not a modal): a role menu with chips and one-line descriptions (UX-DR-143, reuse `RoleMenu`), permission checkboxes limited to what the Admin holds and shown disabled with a reason for the rest, the password prompt, `saved` announced politely with focus staying on Save, field and form errors with focus, and an alertdialog for a downgrade from Admin to User naming the member and the impact with initial focus on Cancel (UX-DR-271, reuse `ConfirmDialog`). For a member who lacks `blocks.publish`, the permission lookup returns false and the reason text is `perm-publish` (a test asserts both). All strings come from the catalogue or `labels.ts`.

**Never:** Deactivating or removing members (Story 1.24); groups (Story 1.23); editing one's own row; granting a permission the Admin lacks; caching permission decisions; a modal editor; changing another Workspace's member (404 under row-level security); sending email.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Change role | Member `user` to `admin` (or back) with current revision and password | Role changes, `revision` + 1, `access.role.changed` audit and outbox | Wrong password blocks |
| Grant permission | Admin holds `blocks.publish`, grants it to an Admin member | Granted; `access.permission.changed` audit and outbox | 403 `access.permission_not_held` if not held |
| Revoke permission | Admin holds it, removes it from a member | Removed; audited | Same cap |
| User role | Permissions on a `user` member | Refused 422; editor disabled with a reason | N/A |
| Last holder | Removing `users.manage` from, or downgrading, the only holder | 409 `access.last_users_manage_holder`; nothing changes | Inline explanation |
| Self edit | Caller edits own role or permissions | 403 `access.self_change_forbidden` | N/A |
| Downgrade | Admin to User | alertdialog names member and impact; focus on Cancel | N/A |
| Concurrent edit | Stale `revision` | 409 with current state; `save-failed` with latest values | Nothing changes |
| No-op | Same role and set | 200, no audit, revision unchanged | N/A |
| Next request | Member downgraded while in Admin area | Next Admin request denied | Not cached |
| Cross-Workspace | Another Workspace's membership id | 404 | RLS |
| Publish reason | Member without `blocks.publish` | Lookup false; reason `perm-publish` | N/A |

</frozen-after-approval>

## Code Map

- `app/Http/Controllers/Admin/MemberController.php`, `routes/api.php`, `app/Http/Navigation/ShellNavigation.php` (`ADMIN_API_ROUTES`), `tests/Architecture/AdminRoutesTest.php`, `tests/Database/AdminAccessTest.php` -- Stories 1.19 to 1.21 gate, mapping and generated matrix (add the PATCH route to all)
- `app/Modules/Access/` -- `WorkspaceMembership`, `MembershipPermission`, `Permission`, `MembershipPermissions`, `MemberDirectory`, `Application/InviteMembers.php`, `ErrorCode.php` (`LastUsersManageHolder`, `PermissionNotHeld`, `SelfChangeForbidden` exist), `AccessAuditSerializer`; `app/Http/Controllers/Admin/InvitationController.php` -- password confirmation and throttle pattern to reuse
- `app/Platform/Audit/AuditAction.php` (`access.role.changed`, `access.permission.changed` exist or add), `app/Platform/Outbox/Outbox.php` -- audit and outbox in one transaction
- `database/migrations/` -- Stories 1.10 to 1.21 (`workspace_memberships`, `membership_permissions`, `access_workspace_members` view); `tests/Database/Support/Cluster.php`
- `resources/js/pages/admin/Users.vue`, `components/RoleMenu.vue`, `InviteUserForm.vue`, `ConfirmDialog.vue`, `GatedAction.vue`, `lib/members.ts`, `locales/en.ts` (`perm-publish`), `labels.ts` -- UI to extend; `tests/js/`, `tests/Feature/`

## Tasks & Acceptance

**Execution:**
- [ ] migration (`revision`), `app/Modules/Access`, `app/Http`, routes, mapping, audit and outbox -- update service with locks, caps, last-holder and self rules, password confirmation
- [x] `resources/js` (inline editor, permission checkboxes with reasons, downgrade alertdialog, conflict handling), `labels.ts` -- UI
- [x] `tests/Database`, `tests/Feature`, `tests/js`, `tests/Architecture`, `README.md` -- every matrix row

**Acceptance Criteria:**
- Given an Admin with `users.manage`, when they change another member's role or permissions they hold, then the change commits with audit and outbox and the member's next request reflects it.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

- **Review iteration 1 (clarifications, frozen block untouched).** (a) Members whose membership is not `active` cannot be edited here (refused with a reason); changing a deactivated member's access belongs with reactivation in Story 1.24, so access is never restored unreviewed. (b) The editor's own authority (active `admin` membership holding `users.manage`) is re-verified inside the transaction, not only by the gate. (c) Refused attempts (self edit, permission not held, last-holder, wrong password) are recorded as security events outside the rolled-back transaction.
- **KEEP:** `access_set_membership_permissions` as the only removal path, the check order in `ChangeMemberAccess`, the symmetric granter cap and the inline `MemberAccessEditor`.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| An editor demoted or stripped of `users.manage` between the gate check and the transaction can still change roles once | high | patch | The editor's authority is not among the locked rows checked inside `ChangeMemberAccess`. |
| The `UPDATE` of `workspace_memberships` affecting 0 rows still rewrites permissions, writes audit and outbox and returns success; the response re-reads the member and 404s if that fails | high | patch | No row-count check; `MemberController` re-queries after commit. |
| Deactivated members can be edited and their revision bumped, so reactivation (Story 1.24) would restore unreviewed access | medium | patch | `ChangeMemberAccess` reads `$target->status` only for the last-holder check; the Edit button shows for every member row. |
| Refused attempts (self edit, permission not held, last-holder, wrong password) leave no security event | medium | patch | Story 1.19 audits denials; this endpoint audits only success. |
| The server-side password requirement for a permission-only change and for a downgrade that clears permissions has no test; list rows are never checked for real `permissions` and `revision` values; `perm-publish` is asserted only by reading `en.ts` | medium | patch | Dropping `$setChanged ||` or the list join keeps tests green. |
| Editor UI: "refreshed" message is untrue; a 409 does not update the row; the editor ignores prop changes; 403 not_authorized, 404 and 422 permissions fall through to a generic error; focus drops when the password field unmounts; own-row check compares emails and fails open; stale failure banner | medium | patch | `MemberAccessEditor.vue`. |
| `access_set_membership_permissions` trusts a table CHECK for catalogue values and allows permissions on a `user`; a missing password counts as a throttle hit; detail row lacks `aria-controls`; dead and duplicate labels | low | patch | Cheap hardening. |
| The password throttle is stored through the request's database transaction when `CACHE_STORE=database`, so a rolled-back 4xx loses the count | medium | defer | Compose uses Valkey (`valkey-cache`); the same pattern exists in Story 1.21; needs a store check or an out-of-transaction counter. |
| Concurrency (two connections, lock order) is only tested sequentially | medium | defer | Needs a two-connection helper in `Cluster`. |
| The password is checked after the stale-revision and cap checks | low | rejected | The 409 body returns state the editor can already list; the editor holds `users.manage`. |
| Holders gained concurrently while locking; no UI warning for the symmetric cap on downgrade; hard-coded English API messages; `Retry-After` unasserted | low | rejected | READ COMMITTED recount covers it; messages are for developers; polish. |

## Design Notes

The granter cap applies to revocation too, so an Admin who does not hold a permission cannot strip it from someone who does; the story states the grant rule only, and the symmetric reading prevents privilege stripping by lower-privileged Admins. `revision` lives on the membership because the editor changes role and permissions together.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
