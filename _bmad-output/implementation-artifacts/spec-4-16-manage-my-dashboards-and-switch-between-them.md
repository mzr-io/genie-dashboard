---
title: '4-16 Manage my dashboards and switch between them'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: 'c5d68a5'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A member has only an Overview; they cannot keep different views for different jobs, nor switch between them.

**Approach:** Add `CreateDashboard`, `RenameDashboard`, `DuplicateDashboard` and `DeleteDashboard` for the member's own dashboards, a list read, a header switcher popover and a "My dashboards" page with a data table. Template entry points ("Reset to template", "Create from Template") are out of scope.

## Boundaries & Constraints

**Always:** Every command is scoped to the member in the active Workspace; another member's or Workspace's dashboard is a 404. A name is trimmed, 1 to 120 characters, with no control characters (422 with a field error). A duplicate gets revision 0, its own item ids and `compact_order`, and keeps positions, sizes, minimized, Mandatory, Date Range and Own-selector choices. Items whose Block the member can no longer use are omitted and counted. Block viewers stay right: a duplicate emits an `add_item` and a delete a `remove_item` event per item.

**Never:** The web role gets no DELETE: a delete goes through a SECURITY DEFINER function. Overview is never deleted (422 `dashboards.overview_undeletable`, also direct) and a member has one Overview. No Template entry points.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| List | member with Overview and others | Overview first, then by creation, with item counts | N/A |
| Create | valid name | empty dashboard, revision 0, opened with `msg:dashboard-empty` | empty or over-long: 422 `dashboards.invalid_name`, field `name` |
| Rename | valid name | saved, shown in the switcher | invalid: 422 |
| Duplicate | dashboard with items | new dashboard "{name} (copy)", same layout and range; `omitted` count when items were unusable | N/A |
| Delete | non-Overview | removed with its items, user lands on Overview | Overview: 422; Delete disabled with `msg:overview-delete` |
| Another member's or Workspace's dashboard | any command | 404 | N/A |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_30_100000_add_dashboards_delete.php`: `dashboards_delete(dashboard, membership)` SECURITY DEFINER, `GRANT EXECUTE TO app`.
- `app/Modules/Dashboards/{Contracts/Dashboards,Contracts/DashboardSummary,Contracts/InvalidDashboardName,Contracts/OverviewUndeletable,Contracts/ErrorCode,Application/ManageDashboards}.php`
- `app/Http/Controllers/DashboardController.php`, `routes/api.php`, `routes/web.php` (`/dashboards`, `/dashboards/{dashboard}`), `app/Platform/Audit/AuditAction.php` if a new action is needed.
- `resources/js/lib/dashboards.ts`, `resources/js/components/DashboardSwitcher.vue`, `resources/js/pages/MyDashboards.vue`, `resources/js/pages/Dashboard.vue`, `resources/js/locales/labels.ts`, `resources/js/types/error-codes.ts` (generated).

## Tasks & Acceptance

**Execution:**
- [x] Migration, commands, list read, endpoints and 422/404 rules
- [x] Switcher popover, My dashboards page, in-place switch that closes the Add-blocks Panel
- [x] Tests: Database (each command, scoping, viewers, Overview), vitest (client, switcher, page)

**Acceptance Criteria:**
- Given the I/O matrix, each row holds against PostgreSQL and in the component tests.

## Implementation Notes

- Rename does not bump the revision (layout commands in another tab keep working); it emits `dashboards.dashboard.changed` with `command: rename`. Overview may be renamed.
- Duplicate names are allowed. No cap on the number of dashboards is specified, so none is enforced.

## Verification

- Pest (Unit, Architecture, Feature), Database suite (PostgreSQL), vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour lint.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Switcher trigger ref was a component instance, so focus return had no element | medium | fixed | `trigger?.$el` is passed as the invoker. |
| Validation details are hidden outside the admin area | medium | fixed | The commands answer their own envelope with `error.fields.name`. |
| Migration rollback tests count steps | low | fixed | Seven tests bumped by one. |
| No cap on the number of dashboards | low | accept | None specified; a later product decision. |
