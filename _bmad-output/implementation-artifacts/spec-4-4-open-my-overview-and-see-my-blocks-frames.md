---
title: '4-4 Open my Overview and see my Blocks'' frames'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '5b2e130'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** There is no personal dashboard: the User Overview is a placeholder, nothing stores a layout and no item has a state.

**Approach:** Add the Dashboards module: `dashboards` and `dashboard_items` (RLS, expand-only), the member's Overview created on first open, `GET /api/v1/dashboards/{id}` returning clamped geometry, item state, a User-safe Block card and a `next_free_slot` hint from a pure `Platform\Layout\Grid`, and the `Dashboard` page that renders each Block as a labelled `section` on the 12-column grid with the empty, load-failed, unpublished and access-removed states.

## Boundaries & Constraints

**Always:** The Inertia page carries `{dashboard_id, revision}` only. Another member's or Workspace's dashboard is a 404 that discloses nothing. Access is re-checked on every layout read. A state that is not `ok` carries no Block name and no data. No layout is shown when the load fails and nothing is saved.

**Never:** No layout commands, add or remove (4.9 on), no results or values in the cards (4.5), no header controls (4.6), no dashboard switcher or Date Range behaviour (4.15, 4.16), no Template seeding, no group access modes (Epic 6).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| First open | member with no dashboard | Overview created, revision 0, empty | N/A |
| Reopen / other device | same member | same dashboard and layout | N/A |
| Layout | items stored wider than the Block allows | geometry clamped in the response, row untouched | N/A |
| No published version | Block without a current version | `unpublished`, no name | N/A |
| Hidden Block | access subject not visible | `access_removed`, no name | N/A |
| Foreign dashboard | other member or Workspace | 404 | nothing disclosed |
| Load fails | API error | `msg:dashboard-load-failed` + Retry, no layout | nothing saved |
| Empty | no items | `msg:dashboard-empty` + Add block | N/A |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_25_100000_create_dashboards_and_items.php`, `app/Modules/Dashboards/**`, `app/Platform/Layout/Grid.php`
- `app/Modules/Blocks/{Contracts,Application}/BlockCard*.php`, `ReadBlockCards.php`; `app/Modules/Access/{Contracts,Application}/SubjectAccess.php`, `ReadSubjectAccess.php`
- `app/Http/Controllers/DashboardController.php`, `Concerns/ResolvesMembership.php`, `routes/web.php`, `routes/api.php`
- `resources/js/pages/Dashboard.vue`, `components/{DashboardGrid,BlockCard}.vue`, `lib/dashboards.ts`, `locales/labels.ts`

## Tasks & Acceptance

**Execution:**
- [x] Migration with RLS, constraints and tenant-leak seeders
- [x] `Platform\Layout\Grid` (clamp, next free slot) and unit tests
- [x] `Dashboards`, `BlockCards`, `SubjectAccess` contracts and implementations
- [x] Page controller and layout API; `Dashboard` page and components
- [x] Tests: Database (every matrix row, constraints), vitest (page states, structure, XSS), unit (grid)

**Acceptance Criteria:**
- Given the I/O matrix, when the page and API are used, then each row holds against PostgreSQL and in the component tests.

## Implementation Notes

- Geometry is `col` (0 to 11), `row` (60 px units), `width` (a preset) and `height` (a preset). Heights are 2, 6 and 9 units; rows are 60 px with a 14 px gap, so a preset's rendered height is its units x 60 + gaps, not exactly 120, 360 and 540 px. Revisit with 4.11 if the exact numbers matter.
- A person with no active membership still gets the page, with `dashboard_id` null and the empty state (previously a placeholder rendered for anyone signed in).
- `SubjectAccess` fails closed for any mode other than `all`; group modes arrive with Epic 6 and can only hide Blocks until then.
- The Remove, + Add block, switcher, Date Range and Edit layout controls are present and `aria-disabled`; Remove carries a visible "later update" reason rather than hiding the Block without saving.
- Seven Database tests that roll migrations back by step count were bumped by one.

## Verification

- SQLite suite, Database suite (PostgreSQL 18, PgBouncer, Valkey), vitest, `pint`, `phpstan`, `vue-tsc` and `vp check` pass.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Rendered heights are not exactly 120/360/540 px | low | defer | Row-gap adds 14 px per extra unit; content scrolls inside the body either way. Noted above. |
| GET creates a row (the Overview) | low | none | Intended by the story; the insert is idempotent (`ON CONFLICT`) under concurrent first opens. |
| `ReadSubjectAccess` ignores the membership | false | none | Accepted for group modes later; today mode `all` needs no membership. |
