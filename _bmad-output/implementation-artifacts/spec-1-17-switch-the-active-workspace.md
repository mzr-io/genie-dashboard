---
title: 'Switch the active Workspace'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: '19848f6505f588f8adc3e09d67667bb3aa916db1'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-16-navigate-the-two-area-shell-with-sign-out-and-profile-menu.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-13-sign-in-as-user-or-admin.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/DESIGN.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A person in several Workspaces cannot change the active one, and nothing yet answers a forged, stale or cross-tenant Workspace ID with the right refusal (Story 1.17; FR-3, FR-1, NFR-4, AR-5, AR-6, AR-38, UX-DR-9, 81, 82, 141, 168, 282). The Story 1.17 section of `epics.md` holds the acceptance criteria.

**Approach:** A sidebar switcher card and popover over the Access membership lookup, a server switch that verifies the target membership itself, rotates the session, applies the area rules and audits in the new Workspace, a dialog guarding unsaved work, and a forbidden response for forged or stale IDs.

## Boundaries & Constraints

**Always:** The switcher card (30px rounded-square avatar tile with initials, Workspace name, descriptive label, › chevron; avatar tile only in the icon rail) and popover (rows with avatar tile, name, label, the person's role tag and a check on the current one; a search field only above 7 Workspaces) follow UX-DR-9, 81, 82 and 141 with Story 1.6 tokens and Story 1.8 and 1.9 components; all copy from the catalogue or `labels.ts`. The list comes from the Access `SECURITY DEFINER` membership lookup (usable memberships only, Workspace status active) and is shared on the Inertia `shell` prop. `POST /workspaces/switch` takes a Workspace ID and never trusts it: the server re-reads the person's memberships, requires an active membership in an active Workspace, and otherwise returns HTTP 403 with code `access.workspace_forbidden` (the existing `Access\Contracts\ErrorCode` case), records `access.workspace.forbidden` (add to `AuditAction`) through `Audit::recordSecurityEvent` in the current Workspace (log line when none), and changes nothing. A valid switch regenerates the session ID, sets `workspace_id`, keeps the area when the person holds the same role there (admin area needs an admin membership), otherwise sets the area to `user` and lands on the User Overview with the `workspace-role` message (`{workspace}` filled with the real name), stamps `last_active_at` for the chosen membership only, and records `identity.workspace.switched` in the new Workspace's audit log. Lists, shell navigation and permissions are re-read for the new Workspace on the next request. A person with unsaved form work gets `unsaved-changes` (Save, Discard changes, Keep editing, focus on Keep editing) before the switch; pages report unsaved state through a small `registerUnsavedForm` helper in `resources/js/lib/`. An object ID from another Workspace returns 404 (row-level security already hides it; a Database test proves it for the tenant tables that exist). Workspace `label` is cosmetic descriptive text: this story corrects Story 1.12 by dropping the lower-case-slug rule and the unique index (new migration; `dashflow:workspace:create` accepts any printable label up to 64 characters without control characters; uniqueness is no longer checked), and the switcher shows it verbatim, escaped.

**Never:** Workspace creation or editing in the UI; the switcher showing Workspaces the person has no usable membership in; an ID in the URL path or query for the switch; trusting the session `workspace_id` without the membership check on switch; loading another Workspace's data without a fresh request.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Switcher | Person with several Workspaces | Card and popover with name, label, role tag, check; search only above 7 | N/A |
| Same role | Admin area, admin membership in target | Workspace and session ID change, area kept; `identity.workspace.switched` | N/A |
| Role downgrade | Admin area, only user role in target | Area becomes User; User Overview with `workspace-role` | N/A |
| Unsaved work | Switch chosen with unsaved form | `unsaved-changes` dialog before the switch | Keep editing cancels |
| Forged ID | Unknown or foreign Workspace ID | 403 `access.workspace_forbidden`; security event; no change | N/A |
| Deactivated | Membership suspended or Workspace inactive | Same 403 | N/A |
| Cross-tenant object | Workspace A requests an object ID of B | 404, no data | N/A |
| Label | Descriptive label with spaces and capitals | Accepted by the command; shown verbatim and escaped | Control characters refused |
| Single Workspace | One usable membership | Card shown without switching affordance | N/A |

</frozen-after-approval>

## Code Map

- `app/Http/Navigation/ShellNavigation.php`, `app/Http/Middleware/HandleInertiaRequests.php` -- Story 1.16 `shell` prop (already has the current Workspace name and role); extend with the Workspace list
- `app/Modules/Access/` -- `MembershipLookup`, `UserMembership` (name, label, status, role), `SignInMembershipsAdapter`; `app/Modules/Identity/Application/SignIn.php`, `Http/SignInResponse.php`, `SignOut.php` -- session keys, rotation and audit patterns to reuse; add the switch application service and controller in Identity behind an Access port
- `app/Platform/Audit/AuditAction.php` -- `identity.workspace.switched` exists; add `access.workspace.forbidden`; `app/Modules/Access/Contracts/ErrorCode.php` -- `WorkspaceForbidden`; `error-codes.ts` regenerated by `php artisan dashflow:error-codes`
- `app/Console/Commands/WorkspaceCreateCommand.php`, `database/migrations/2026_10_06_120003_make_workspace_label_unique.php` -- the label rules to relax
- `resources/js/components/AppSidebar.vue`, `NavUser.vue`, `layouts/app/*`, `lib/formDrafts.ts`, `components/UnsavedChangesDialog.vue`, `stores/dialogs.ts` -- shell, drafts, dialogs; `locales/en.ts` keys `workspace-role`, `unsaved-changes`
- `tests/js/shell.test.ts`, `tests/Feature`, `tests/Database` -- existing suites

## Tasks & Acceptance

**Execution:**
- [x] `app/Modules/Identity`, `app/Modules/Access`, `routes/web.php`, `AuditAction`, `ShellNavigation` -- switch service and controller, forbidden response and audit, membership list in `shell`
- [ ] migration, `WorkspaceCreateCommand`, README -- relax the label rules
- [x] `resources/js/components`, `lib`, `layouts`, `labels.ts` -- switcher card, popover, unsaved-work dialog
- [x] `tests/js`, `tests/Feature`, `tests/Database` -- every matrix row

**Acceptance Criteria:**
- Given a person in two Workspaces, when they choose the other one, then the active Workspace and session ID change and the new Workspace's audit log records the switch.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| `identity.workspace.switched` stored in the new Workspace's log carries `from_workspace_id`, so an Admin of the new Workspace learns the other Workspace's UUID | high | patch | `SwitchWorkspace` writes the previous ID into the target Workspace's audit row; AR-5 and NFR-4 keep Workspaces from seeing each other's identifiers. |
| The membership check and the switch are not atomic: a membership suspended or a Workspace deactivated between the read and the write still switches | high | patch | The lookup runs before `transactions->run`; `markActive` does not check that it updated a row. |
| The old session ID stays valid after the switch | medium | patch | `regenerate()` without destroy, unlike `IdleTimeout` and `ResetPassword`. |
| `$&`, `$1` and `$'` in a Workspace name are expanded in the role toast | medium | patch | `String.replace('{workspace}', name)` with a string replacement. |
| Switching to the already-active Workspace rotates the session and writes a "switched" audit row; refusals are unthrottled and always audited; a lookup failure is an unhandled 500 | medium | patch | The Vue component returns early; the endpoint does not. |
| `Profile.vue` Save can hang (no settle on `@finish`, null form, double Save); a failed save abandons the switch silently; a dismissed dialog leaves `pending` set; options still clickable while switching | medium | patch | `settle` only fires on success or error; `aria-disabled` does not block clicks. |
| Shell treats an inactive Workspace as current while the list excludes it; list order not enforced; a 403 leaves the refused Workspace in the list | medium | patch | `membership()` ignores `workspaceStatus`; no sort; no `shell` reload on refusal. |
| Tests missing: Profile registration and settle, `useWorkspaceSwitch` 401, 419 and network branches, double-submit, Escape and focus return | medium | patch | Verification-gap layer. |
| Label accepts U+2028 and U+2029 | low | patch | The control-character rule uses `\p{C}` only. |
| Only Profile registers unsaved work | medium | defer | The other forms do not exist yet; each later form must call `registerUnsavedForm` (or share `formDrafts` dirty state). |
| Migration `down()` fails once labels repeat; database has no label check constraint; operator `SELECT (label)` revoked; duplicate labels indistinguishable | low | rejected | Duplicate labels are now intended by the story; the revoke removes an unused privilege; rollback of a relaxed rule is not a supported path. |
| Session mutation order versus the audit commit; cross-tenant test probes a route registered in the test; spec checklist | low | rejected | The audit abort fails closed by design; row-level security is the control being proven. |

## Design Notes

Story 1.12 made the label a unique lower-case slug at a review request; the Story 1.17 criterion describes it as a descriptive, cosmetic label, so the stricter rule was wrong and is reverted here rather than left to surprise the first customer's Workspace names.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
