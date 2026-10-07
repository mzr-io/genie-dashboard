---
title: "List the Workspace's users in User configuration"
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: '30ee612b681bb305d56787d145e10d771bb2d582'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-19-enforce-admin-only-access-with-area-and-permission-checks.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-12-provision-a-workspace-and-let-its-first-admin-accept-an-invitation.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** An Admin cannot see who has access to the Workspace: User configuration is a placeholder (Story 1.20; FR-6, FR-5, NFR-4, UX-DR-37, 261, 263, 279, 282). The Story 1.20 section of `epics.md` holds the acceptance criteria.

**Approach:** A server-side list of the active Workspace's members and pending invitations behind the Story 1.19 gate, a cursor-paginated JSON API and an accessible sortable data table page with search and the generic list states.

## Boundaries & Constraints

**Always:** `GET /api/v1/admin/members` (route name `api.admin.members`, permission `users.manage`, added to the one route-to-permission mapping in `ShellNavigation` so the Story 1.19 architecture tests keep passing) and the page route `admin.users.index` (existing) return members of the active Workspace only: name, email, role (`user`|`admin`), status (`active`, `invited`, `deactivated`), groups and last active. Rows come from `workspace_memberships` (row-level security) joined to `users`, plus pending invitations of the active Workspace as `invited` rows (an invitation is pending when unused and not expired; its role comes from the invitation, name empty, last active empty); invitation rows are scoped by the session Workspace inside the database (a `SECURITY DEFINER` function owned by `migrator`, executable by `app`, that reads `current_setting('app.workspace_id', true)` and returns no rows when it is unset), never by a client-supplied ID, so a missing filter cannot leak another Workspace's invitees. Groups do not exist before Story 1.23: the column renders an empty list ("No groups") and the API returns `groups: []`. The list supports sorting by name, email, role, status and last active (a whitelist, descending and ascending, stable tie-break on the row id), a `q` search over name and email (case-insensitive, escaped), and cursor pagination whose page size is capped by the tunable `lists.max_page_size` (`pending_input`, no invented value; while unset the framework's default page size of 15 applies, and a requested size above a set cap is clamped). The data table is a real `<table>` with a caption, sortable header buttons that carry `aria-sort`, `scope` on headers, tabular numerals, status shown with a word and icon (UX-DR-37 tag, UX-DR-273 never colour alone), and last active as a time element formatted with the user's locale and time zone (`lib/format.ts`). States follow UX-DR-263: toolbar plus 5 skeleton rows while loading; "We couldn't load users. Try again." with Retry on failure; `list-empty` when there are no members at all with a primary "Invite user" action; a Workspace with only the first Admin shows that Admin and "Invite user"; `list-no-match` with Clear search when a search matches nothing, and the count "0 of n" announced politely, as are page changes. "Invite user" is a disabled-with-reason link until Story 1.21 (the reason is `perm-denied`-style copy added to `labels.ts`: "Invitations arrive with the next release"). A member of another Workspace requested by ID (`GET /api/v1/admin/members/{membership}`, route `api.admin.members.show`, same gate) returns 404, proven against row-level security. A request without `users.manage` follows the Story 1.19 denial (403, `perm-denied`, audited). The query never selects password hashes, tokens or remember tokens; the API resource lists only the named fields. All strings come from the catalogue or `labels.ts`.

**Never:** Inviting, editing, deactivating or role changes (Stories 1.21 to 1.24); group management; showing members of other Workspaces; sorting or filtering by a client-supplied column outside the whitelist; offset pagination; an invented page-size default.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| List | Admin with `users.manage` | Active Workspace's members and pending invitations in a real table | N/A |
| No permission | Admin without `users.manage`, or User area | Page: 403 `perm-denied`; API: envelope; audited | Story 1.19 |
| Cross-Workspace | Another Workspace's membership ID | 404 | RLS |
| Invited | Pending invitation | Row with status Invited and its role | Used or expired invitations absent |
| Only first Admin | Single member | That Admin and "Invite user" | N/A |
| Empty | No members and no invitations | `list-empty` with "Invite user" | N/A |
| Search | Term matches nothing | `list-no-match` with Clear search; "0 of n" announced politely | N/A |
| Sort | Each sortable column | `aria-sort` updates; order stable | Unknown column ignored |
| Pagination | More rows than the page size | Cursor pages; page change announced | Size clamped to the cap |
| States | Loading and failure | 5 skeleton rows; "We couldn't load users. Try again." with Retry | N/A |
| Isolation | Invitations of another Workspace | Never returned | Unset context returns none |

</frozen-after-approval>

## Code Map

- `app/Http/Navigation/ShellNavigation.php` -- `ADMIN_ITEMS` and the route-to-permission mapping the gate and tests read; `app/Http/Middleware/RequireAdminAccess.php`, `tests/Architecture/AdminRoutesTest.php`, `tests/Database/AdminAccessTest.php` -- Story 1.19 gate and generated matrix (new routes must be covered)
- `routes/web.php` (`admin.users.index`), `routes/api.php` (`/api/v1/admin` group with probes), `resources/js/pages/Placeholder.vue` -- placeholder to replace for `user-configuration`
- `app/Modules/Access/` (`WorkspaceMembership`, `MembershipLookup`, Contracts) and `app/Modules/Identity/` (`invitations`, `Application/IssueInvitation`) -- the Access module may call Identity Contracts (edge exists); add the list query in Access behind a Contracts port
- `database/migrations/` -- Stories 1.10 to 1.18 (`workspace_memberships`, `invitations`, `membership_permissions`); `tests/Database/Support/Cluster.php`, `tests/Database/` -- Docker suite helpers
- `resources/js/components/` (`ListStates`, `Tag`, `StatusDot`, `ui/skeleton`), `lib/format.ts`, `lib/announce.ts`, `locales/en.ts` (`list-empty`, `list-no-match`), `labels.ts`, `config/dashflow.php` -- existing pieces; add `DataTable.vue`
- `tests/js/`, `tests/Feature/` -- existing suites

## Tasks & Acceptance

**Execution:**
- [ ] migration (invitation function), `app/Modules/Access`, `app/Http`, routes, `ShellNavigation` mapping, `config/dashflow.php` -- list query, API, tunable
- [x] `resources/js` (`DataTable`, the User configuration page, states, announcements), `labels.ts` -- table, search, sort, pagination, states
- [x] `tests/Database`, `tests/Feature`, `tests/js`, `tests/Architecture`, `README.md` -- every matrix row

**Acceptance Criteria:**
- Given an Admin with `users.manage`, when User configuration opens, then the Workspace's members and invitations appear in a sortable accessible table and no other Workspace's rows appear.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| A NUL byte in `q` or in a hand-crafted cursor key makes PostgreSQL reject the value, so the request returns a 500 instead of a result or a 422 | high | patch | `SqlMemberDirectory` binds the search term and decoded cursor key unchecked. |
| The empty state shows only text: the "Invite user" primary action the criteria require is missing from `list-empty`; a failed or loading state removes the toolbar and search box; Space on the disabled Invite link scrolls the page; a sort is announced before it succeeds; a page that empties after removals shows an empty table | medium | patch | `Users.vue` renders `ListStates` text only and replaces the toolbar on error. |
| Invited rows carry an ID that `members/{membership}` cannot resolve and nothing tells a client which kind a row is | medium | patch | Stories 1.21 to 1.24 must act on both kinds of row. |
| Member list is returned without `Cache-Control: no-store`; the `SECURITY DEFINER` owner is claimed but unasserted; 401 and 419 on the list are shown as a generic failure | medium | patch | The response lists every member's name and email; no test reads `pg_proc.proowner`. |
| Cap parsing (`0`, `abc`, negative, overlong), `per_page` validation, cursor direction binding, and the client cursor reset on sort or search from page 2 are untested; the `last_active` ordering assertion is partly self-referential; paging does not prove the concatenated pages equal the full sorted list | medium | patch | Verification-gap layer; deleting the guards keeps tests green. |
| Scrollable table has no `tabindex`, `role="region"` or label; counts read "1 users"; direction words are English literals in `Users.vue` | low | patch | UX-DR-275 asks for labelled focusable scroll regions; spec says copy comes from `labels.ts`. |
| `total` and `matched` can disagree with the page under concurrent writes; the query is unindexed and counts per request; the status mapping collapses every non-active value to Deactivated; `last_active_at` is labelled UTC | low | rejected | Small-tenant scale; `suspended` is the only other status today; `timestamp` is written in UTC by the app (`app.timezone` UTC). |
| Invalid `sort` or `direction` is silently ignored; expired and used invitations vanish; no audit for viewing the list; no rate limit on search | low | rejected | The spec says an unknown column is ignored and lists pending invitations only. |

## Design Notes

Invitations are a global table without row-level security (Story 1.12), so the pending-invitation read goes through a `SECURITY DEFINER` function bound to the transaction's Workspace setting; that keeps tenant scoping in the database, as for the membership lookup. The groups column is a deliberate empty shell until Story 1.23.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
