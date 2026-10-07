---
title: 'Organise users into groups'
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: '43858400e15f2361d63cbd2fc5dfb5f3686720fc'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-22-assign-roles-and-admin-permissions.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-20-list-the-workspaces-users-in-user-configuration.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-19-enforce-admin-only-access-with-area-and-permission-checks.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Members cannot be organised, so later stories have nothing to grant access by (Story 1.23; FR-6, NFR-4, AR-6, AR-7, UX-DR-261, 263, 282). The Story 1.23 section of `epics.md` holds the acceptance criteria.

**Approach:** Tenant tables `user_groups` and `group_members` with row-level security, an Admin API and a Groups view behind the Story 1.19 gate (create, rename, delete, add and remove members), audit and outbox events in the same transaction, and the Story 1.20 list filled with each member's groups.

## Boundaries & Constraints

**Always:** Tables `user_groups` (UUIDv7 key, non-null `workspace_id`, `name` unique per Workspace case-insensitively, timestamps) and `group_members` (UUIDv7 key, non-null `workspace_id`, `group_id`, `membership_id`, unique pair) are tenant tables with `ENABLE` and `FORCE ROW LEVEL SECURITY` and the standard Workspace policy, created as `migrator` (the architecture ownership list in `tests/Architecture/dependencies.php` uses `groups`; rename it to `user_groups` because `groups` is a reserved word, and update its tests). Role `app` has SELECT, INSERT and UPDATE; removals and deletions go through `SECURITY DEFINER` functions owned by `migrator` and executable by `app` that read `current_setting('app.workspace_id', true)`, take no Workspace argument and refuse when it is unset (`app` has no DELETE on tenant tables). The API lives under `/api/v1/admin/groups` (list with search `q`, create, rename, delete, and `POST` and `DELETE` of `/groups/{group}/members/{membership}`; route names `api.admin.groups.*`), uses permission `users.manage` and is added to `ShellNavigation::ADMIN_API_ROUTES` and the Story 1.19 test matrix; an Admin page route `admin.users.groups` (Groups view of User configuration, permission `users.manage`) is registered in the same mapping and the page links from User configuration. Names are trimmed, 1 to 64 characters without control characters; a duplicate name (case-insensitive, trimmed) returns 422 with a field error. A member added by the ID of a membership in another Workspace returns 404 (row-level security) and so does a group of another Workspace; adding an existing pair is a 200 no-op; removing an absent pair is a 200 no-op. Membership changes take effect on the next request (nothing cached). A deactivated member keeps their group memberships and shows as "Deactivated" in the groups views; adding a deactivated member is allowed. Create, rename, delete and member add or remove each write `access.group.changed` (audit through the Access serializer with group id, member id, enums and counts, never names of people or emails; the group name is audited as a keyed hash like other free text) and an outbox event `access.group.changed` (IDs and enums only) in the same transaction. Deleting a group removes only its memberships in this epic (no access grants exist yet; leave a documented hook for Epic 6's warning); a delete confirms in an alertdialog naming the group and stating the member count (reuse `ConfirmDialog`, initial focus on Cancel). The Story 1.20 list returns each member's `groups` (id and name, ordered by name; invitation rows have none) and the table shows them. The Groups view follows UX-DR-261 and 263: a real data table with sortable headers and `aria-sort`, search, 5 skeleton rows while loading, "We couldn't load groups. Try again." with Retry, `list-empty` with a primary "Create group" action when there are no groups, `list-no-match` with Clear search when a search matches nothing, an inline create form and an inline member editor (add and remove, with member search) rather than modals, and polite announcements of results and changes. A request without `users.manage` follows the Story 1.19 denial (403 `access.not_authorized`, audited). All strings come from the catalogue or `labels.ts`.

**Never:** Access grants or group-based visibility (Epic 6); nested groups; deleting members; bulk import; modals for create or edit; showing groups of other Workspaces; names in outbox data.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Create | Unique name | Group exists; `access.group.changed` audited and emitted | N/A |
| Duplicate | Same name differing by case or spaces | 422 field error; nothing created | N/A |
| Add member | Membership of this Workspace | Added; next request reflects; list shows the group on the member | Existing pair is a no-op |
| Foreign member | Membership or group of another Workspace | 404 | RLS |
| Remove member | Present pair | Removed; audited | Absent pair is a no-op |
| Rename | New unique name | Renamed; audited | Duplicate 422 |
| Delete | Confirmed in alertdialog with member count | Group and its memberships removed; audited | N/A |
| Empty | No groups | `list-empty` with "Create group" | N/A |
| No match | Search matches nothing | `list-no-match` with Clear search | N/A |
| Deactivated | Group containing a deactivated member | Membership kept, shown "Deactivated" | N/A |
| No permission | Admin without `users.manage` | 403 `access.not_authorized`, audited | Story 1.19 |
| Isolation | Workspace A reads Workspace B groups | None returned | RLS |

</frozen-after-approval>

## Code Map

- `tests/Architecture/dependencies.php` (`Access` tables `groups`, `group_members`), `TableOwnershipTest.php`, `MigrationGuardTest.php` -- ownership and global-table lists to update; `tests/Database/Support/Cluster.php` -- tenant table seeders and the per-table leak test generated from live tables
- `app/Http/Controllers/Admin/MemberController.php`, `InvitationController.php`, `routes/api.php`, `routes/web.php`, `app/Http/Navigation/ShellNavigation.php` (`ADMIN_ITEMS`, `ADMIN_API_ROUTES`), `tests/Architecture/AdminRoutesTest.php`, `tests/Database/AdminAccessTest.php` -- gate, mapping and generated matrix to extend
- `app/Modules/Access/` -- `MemberDirectory`, `SqlMemberDirectory` (members list `groups` field), `Application/ChangeMemberAccess.php`, `InviteMembers.php`, `AccessAuditSerializer.php`, `ErrorCode.php`; `app/Platform/Audit/AuditAction.php` (`access.group.changed` exists or add), `app/Platform/Outbox/Outbox.php`
- `database/migrations/` -- Stories 1.10 to 1.22 (`access_workspace_members` view, function patterns); `resources/js/pages/admin/Users.vue`, `components/DataTable.vue`, `ConfirmDialog.vue`, `ListStates.vue`, `lib/members.ts`, `locales/en.ts`, `labels.ts`; `tests/js/`, `tests/Feature/`

## Tasks & Acceptance

**Execution:**
- [ ] migration (tables, RLS, functions), `app/Modules/Access`, `app/Http`, routes, mapping, audit and outbox, ownership lists -- groups API and the members list `groups`
- [x] `resources/js` (Groups view, inline create and member editor, delete alertdialog, groups column), `labels.ts` -- UI
- [x] `tests/Database`, `tests/Feature`, `tests/js`, `tests/Architecture`, `README.md` -- every matrix row

**Acceptance Criteria:**
- Given an Admin with `users.manage`, when they create a group and add members, then the group and memberships exist, are audited and emitted, and the member list shows the groups.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

- **Implementation notes (frozen block untouched).** (a) The Groups view page route is registered in `ShellNavigation::ADMIN_PAGES` (page routes that are not navigation items) and the API routes in `ADMIN_API_ROUTES`; the gate and the generated matrix read both, because adding the page to `ADMIN_ITEMS` would create a sidebar item. (b) The epic's "or from the member's row" way of editing group membership is not built here; membership is edited from the Groups view, and the member list shows each member's groups. A row-level control belongs with a later iteration of User configuration.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| The add-member picker reads only the first page of matches and hides people already in the group client-side, so it can say "No members to add" while non-members exist on later pages | high | patch | `GroupEditor.runSearch` takes one page (15 rows until `lists.max_page_size` is set). |
| Removing a pair whose membership is absent returns 404 although "removing an absent pair is a 200 no-op"; a successful change turns into a 404 when the follow-up `row()` re-read runs after a concurrent delete | high | patch | `ManageGroups::removeMember` calls `member()` first; `GroupController::row()` re-reads outside the transaction. |
| Focus falls to `body` after Add; the rename field is overwritten while typing; `changed()` keeps a stale order; `created()` sets `editing` even when the reload failed | medium | patch | UX-DR-261 and 263 focus rules; the watcher only checks `processing`. |
| The members-list group query relies on RLS alone (no explicit Workspace filter); the database name CHECK allows zero-width and format characters the request rejects; navigation between the two views uses plain links | medium | patch | `SqlMemberDirectory` joins on `membership_id IN (...)`; the migration CHECK uses `[[:cntrl:]]` only. |
| Error branches (429, 401/419, `not_authorized`, member search failure, name over 64 characters, 422 with empty errors) have no component tests; `GroupEditor` focus and announcements untested | medium | patch | `tests/js/groups.test.ts` covers only the happy paths and a few errors. |
| Outbox payload carries `member_count`; unused `memberGone` label; stale `MemberResource` docblock | low | patch | Spec says outbox data is IDs and enums only. |
| Group names in the audit are not confirmed hashed | false | rejected | `AccessAuditSerializer` maps `name` to `AuditField::Hashed`; the create test asserts the name is absent from audit and outbox. |
| Add and remove from the member's row | medium | defer | The acceptance criterion offers both entry points; the Groups view satisfies the behaviour; see the Spec Change Log. |
| List queries return every group with every member; concurrent duplicate-name race (23505) untested; concurrent add race | medium | defer | Load readiness is Story 1.25; the unique index still blocks the duplicate; needs the two-connection helper already deferred. |
| Hard-coded English server message for a duplicate name; `ADMIN_PAGES` instead of the same constant | low | rejected | The client ignores the server text; the gate reads both constants. |

## Design Notes

Groups are a pure grouping in this epic: deleting one removes memberships only, and the hook for Epic 6's "referenced by an access grant" warning is a single documented check the delete function calls when grants exist. Case-insensitive uniqueness uses a functional unique index on `(workspace_id, lower(btrim(name)))`.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
