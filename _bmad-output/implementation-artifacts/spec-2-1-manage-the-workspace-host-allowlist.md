---
title: 'Manage the Workspace host allowlist'
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: 'ba6f5409fb067321613ed326ea2edeac86a760b0'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-23-organise-users-into-groups.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-19-enforce-admin-only-access-with-area-and-permission-checks.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Dashflow has no record of which hosts a Workspace approves, so nothing can later decide whether an outbound call is allowed (Story 2.1; FR-10, FR-67 allowlist part, NFR-4, AR-8, AR-25, UX-DR-115, 262, 263, 23, 282). The Story 2.1 section of `epics.md` holds the acceptance criteria.

**Approach:** A tenant table `host_allowlist_entries` with row-level security in a new Connector module, an Admin API and a System settings > Host allowlist page behind the Story 1.19 gate (`settings.manage`), strict server-side host validation, audit in the same transaction, a list-level `revision` for 409 conflicts, and a removal dialog fed by a dependents port.

## Boundaries & Constraints

**Always:** Table `host_allowlist_entries` (UUIDv7 key, non-null `workspace_id`, `host`, `scheme` in `http`/`https`, `port` 1 to 65535, `added_by_membership_id` (no cross-module FK), timestamps) is a tenant table owned by the Connector module with `ENABLE` and `FORCE ROW LEVEL SECURITY` and the standard Workspace policy, created as `migrator`. `app` has SELECT, INSERT and UPDATE; removal goes through a `SECURITY DEFINER` function owned by `migrator` and executable by `app` that reads `current_setting('app.workspace_id', true)`, takes no Workspace argument and refuses when it is unset (as `access_delete_group`). Uniqueness is `(workspace_id, host, port)` where `host` is stored lower-case and `port` is always stored resolved (the scheme default 443 or 80 when omitted), so the same host and port is a duplicate whatever the scheme. A second tenant table `host_allowlist_versions` (`workspace_id` primary key, `revision` integer) carries the list-level revision; every create and removal runs under a row lock on it, compares the request's `revision`, bumps it, and a stale one returns 409 `connector.revision_conflict` with the current list and revision (the page keeps the typed value). Both tables get Cluster leak seeders and ownership entries. The API lives under `/api/v1/admin/host-allowlist` (`GET` list with search `q` and sort, `POST` create, `DELETE {entry}` with the `revision`, `GET {entry}/dependents`; route names `api.admin.host-allowlist.*`; throttled like the groups API), permission `settings.manage`, mapped in `ShellNavigation::ADMIN_API_ROUTES`, with the page route `admin.settings.host-allowlist` in `ADMIN_PAGES` and the Story 1.19 test matrix. A request without `settings.manage` or from the User area follows the Story 1.19 denial (403, audited through `Audit::recordSecurityEvent`, no entries returned); no new denial code is written. Input is a `host` or `host:port` string plus a `scheme` choice defaulting to `https` (plain `http` is allowed in the MVP and shows the persistent "Not encrypted" cue on its row). Validation, all server-side, answers 422 with a field error and writes nothing: empty; any whitespace (nothing is silently trimmed); any of `/ \ ? # @ * %` or a scheme, path, query, userinfo or wildcard; a bare `*`; a non-ASCII name (punycode required); labels that are not letters, digits or inner hyphens, labels over 63 characters or a name over 253; a numeric spelling of an IP other than canonical dotted-quad IPv4 or bracketed IPv6; an IP literal in loopback, link-local, cloud-metadata, private, CGNAT, unspecified, multicast, IPv4-mapped/compatible, NAT64, 6to4, Teredo or ULA space (a public literal is allowed; Story 2.2's classifier supersedes the literal check and must reuse its rule); a port outside 1 to 65535. A duplicate is 422 with an inline message. Create audits `connector.host_allowlist_entry.created` and removal `connector.host_allowlist_entry.removed` (added to `AuditAction`, with a Connector `AuditSerializer`: host, scheme, port and entry id in the clear, no emails) in the same transaction with the actor and the request ID. Removing an entry first asks a `HostAllowlistDependents` port (Connector contract, null implementation returning none until Story 2.3 binds the Data Source one) for the Data Sources that will be blocked; the page shows them in the removal `alertdialog` (initial focus on Cancel, destructive button repeats the host, reuse `ConfirmDialog`); removing an absent entry is a 404 (RLS hides other Workspaces). The page (data-table with sortable headers and `aria-sort`, search, 5 skeleton rows when cold, "We couldn't load {items}. Try again." with Retry, `list-empty` with "+ Add host", an inline add form with `aria-invalid` and `aria-describedby`, focus to the first invalid field, the created row highlighted and focused, `saved` announced politely) is added as `admin.settings.host-allowlist`; the existing `admin.settings.index` placeholder becomes a System settings page linking to it. "Added by" shows the member's display name resolved through an Access read contract, never stored as text. All strings come from the catalogue or `labels.ts`.

**Never:** Calling, resolving or connecting to any host in this story (Story 2.2); wildcard or suffix entries; storing or auditing emails; a delete grant on tenant tables to `app`; any Data Source code; inventing a cap on entries.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Add | `api.example.com` or `api.example.com:8443`, current revision | Row created with resolved port, highlighted, focused, `saved` announced; created event audited | N/A |
| Invalid value | Scheme or path, `*`, blocked IP literal, whitespace, empty | 422 with field error, focus on the field, nothing written | N/A |
| Duplicate | Same host and port | 422 inline duplicate message; no second row | N/A |
| Remove | Confirmed in dialog | Entry deleted; removed event audited; dialog listed dependents | Absent entry is 404 |
| Stale list | Old `revision` | 409 with current list and revision; typed value kept | Nothing changes |
| No permission | No `settings.manage` or User area | 403, denial recorded, no entries returned | Story 1.19 |
| Isolation | Another Workspace or no context | Zero rows | RLS |
| Empty and failure | No entries; list load fails | `list-empty` with "+ Add host"; error with Retry | N/A |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_07_140000_create_user_groups_and_group_members.php` -- the pattern to copy: RLS policy, CHECKs, `SECURITY DEFINER` delete with `search_path`, `REVOKE` then `GRANT EXECUTE` to `app`; `2026_10_07_100001_create_workspace_settings_table.php` -- a `revision` column precedent
- `app/Modules/Access/Application/ManageGroups.php`, `Contracts/RevisionConflict.php`, `ErrorCode.php`, `Infrastructure/SqlGroupDirectory.php`, `AccessAuditSerializer.php` -- structure to mirror in a new `app/Modules/Connector/{Application,Contracts,Infrastructure}` (the module has no edge to Access: pass primitives, resolve names in the controller layer); `app/Providers/AppServiceProvider.php:81-104` -- bindings and serializer registration
- `app/Http/Controllers/Admin/GroupController.php`, `MemberController.php:136` (409 pattern) and `:225` (refusal), `app/Http/Requests/Admin/GroupNameRequest.php`, `app/Http/Resources/GroupResource.php` -- controller, request and resource patterns; `routes/web.php` (`admin.settings.index` placeholder), `routes/api.php` -- routes
- `app/Http/Navigation/ShellNavigation.php` (`ADMIN_ITEMS` 42, `ADMIN_PAGES` 60, `ADMIN_API_ROUTES` 68), `app/Http/Middleware/RequireAdminAccess.php` -- gate mapping; `app/Platform/Audit/AuditAction.php` -- add two cases
- `tests/Database/Support/Cluster.php:218` (`seedTenantRow`), `tests/Database/AdminAccessTest.php` (route count assertion, `{entry}` placeholder), `tests/Architecture/dependencies.php` (Connector already owns `host_allowlist_entries`; add `host_allowlist_versions`), `AdminRoutesTest.php`, `tests/Security/CoverageManifestTest.php`, `tests/js/groups.test.ts` -- test patterns
- `resources/js/pages/admin/UserGroups.vue`, `components/{DataTable,ListStates,ConfirmDialog,FormField,FormErrorSummary,PageHeader}.vue`, `lib/groups.ts`, `locales/labels.ts` (`groupLabels`), `types/error-codes.ts` (generated by `composer error-codes`) -- UI patterns and generated codes

## Tasks & Acceptance

**Execution:**
- [x] migrations (two tables, RLS, grants, removal function), `app/Modules/Connector`, `AuditAction`, serializer, bindings, ownership list -- allowlist module and persistence
- [x] host validation, controller, requests, resource, routes, `ShellNavigation`, Access name-lookup contract -- API and gate
- [x] `resources/js` (Host allowlist page, add form, removal dialog, System settings page), `labels.ts`, generated error codes -- UI
- [x] `tests/Database`, `tests/Feature`, `tests/Architecture`, `tests/Security`, `tests/js`, `README.md` -- every matrix row, validation table, leak and gate tests

**Acceptance Criteria:**
- Given an Admin with `settings.manage`, when they add and remove hosts, then the entries are stored under RLS, audited in the same transaction, and a stale list returns 409 with the fresh state.
- Given `bin/tools composer ci:check` and `bin/tools npm run build`, when run, then both pass, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| `list()` reads the revision after the counts and rows, so a concurrent add can return old rows with the new revision and let the next write pass the check | medium | patch | `ManageHostAllowlist::list` calls `revision()` last, as a separate READ COMMITTED statement; reading it first only risks a spurious 409. |
| After a 409 the page applies the default-query `current` while keeping its own sort, direction and search; an in-flight load can overwrite it | medium | patch | `stale()` and the 409 branch of `confirmRemove` call `apply(error.current)` without resetting `sortKey`, `direction`, `appliedSearch`. |
| `scheme: ""` is converted to null and repaired to `https`; `direction=DESC` silently sorts ascending | low | patch | `ConvertEmptyStringsToNull` and the `=== 'desc'` comparison; both fixes are one-line validations. |
| Sort keys `scheme` and `added`, `host` descending, the `_` and `!` search escapes and stale-revision-plus-duplicate ordering have no test | medium | patch | Changing the key mapping or dropping an escape leaves every listed test passing. |
| Version-row lock only tested sequentially | medium | defer | Needs the two-connection helper already deferred in Story 1.23; the unique index still blocks duplicates. |
| `BlockedAddress` omits documentation and benchmark ranges (192.0.2.0/24, 198.18.0.0/15, 2001:db8::/32) | low | defer | The spec lists the classes to block and these are not among them; Story 2.2 owns the full classifier and is told to review the list. |
| Host names such as `localhost`, `*.internal`, single-label names are accepted | low | rejected | The spec blocks IP literals only; name-based loopback and metadata hosts are decided at resolution time by Story 2.2's guard. |
| Unbounded list and no per-Workspace cap | low | rejected | The spec's Never list forbids inventing a cap; the list is an Admin-only settings table. |
| No outbox event for allowlist changes | low | rejected | Decided in Design Notes; no acceptance criterion needs one and the guard reads the table live. |
| Removal does not re-check dependents server-side | false | rejected | The criterion says the entry is deleted and the dialog lists the Data Sources; no blocking is specified, and no Data Source exists until Story 2.3. |
| Scheme error wiring and focus, 403 inside the add form, trailing-dot message, redundant labels and index, `actor()` extra query, `bootstrap/app.php` `Request` import, audit-table CHECK in rollback tests, no 429 test, server English strings | low | rejected | Cosmetic or unreachable in practice (scheme only by tampering; `Request` is imported at `bootstrap/app.php:16`; the middleware has already resolved the membership); each fix adds guards or branches. |
| Tests task names `tests/Feature` and `tests/Security` but neither changed | low | rejected | The leak coverage is generated from the live schema and the gate matrix lives in `tests/Database`; no manifest guarantee is new. |

## Design Notes

The epic lists a scheme column but its examples carry none and a scheme in the value is invalid, so the scheme is a separate field. Storing the resolved port keeps uniqueness simple. A list-level revision (not per entry) is what makes "Admin B's add after Admin A changed the list" conflict; the version row doubles as the lock that serialises changes. No outbox event is emitted because no acceptance criterion needs one.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
