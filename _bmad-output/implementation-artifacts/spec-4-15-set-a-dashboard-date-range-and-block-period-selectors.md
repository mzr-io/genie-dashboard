---
title: '4-15 Set a dashboard Date Range and Block period selectors'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '1d87ee1'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A dashboard shows each Block's default period; a person cannot choose the window they care about, nor have it remembered across devices.

**Approach:** Store a Date Range per dashboard and a period choice per Own-selector item on the server, resolve every Block's period through `PeriodResolver`, and give the page a Date Range popover with a calendar and a compact period dropdown on Own-selector Blocks.

## Boundaries & Constraints

**Always:** Commands hold the dashboard `FOR UPDATE`, check `expected_revision`, bump it and emit `dashboards.dashboard.changed`. Only `PeriodResolver` decides a period. A refused value stores nothing. A Block keeps its last values while a new period computes; nothing moves.

**Never:** No client date reaches the resolver or the results endpoint; the only absolute dates are the validated `custom` range of `SetDateRange`. Not-date-filtered Blocks show no selector and ignore every range. The dashboard Date Range never resets a Block's own choice.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Preset range | `this_month` | stored, revision +1, Follow-dashboard Blocks refetch | N/A |
| Custom range | two real days, from <= to | stored with dates | otherwise 422 |
| Preset with dates, unknown token, raw date | any | 422 `dashboards.invalid_period` | nothing stored |
| Clear | `range: null` | back to each Block's default | N/A |
| Own selector | catalogue preset | stored per item; survives a range change | non-Own Block or unknown preset: 422 |
| Block not on the dashboard, or another member's dashboard | any | 404 | N/A |
| Stale revision | any | 409 with the current revision | the page reloads and retries once |
| Custom with one date | popover | Apply aria-disabled with the reason | N/A |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_29_100000_add_date_range_and_period_selection.php`
- `app/Modules/Dashboards/{Application/ManageDashboards,Contracts/Dashboards,Contracts/DashboardView,Contracts/ErrorCode}.php`, `app/Http/Controllers/DashboardController.php`, `routes/api.php`
- `app/Platform/Period/PeriodResolver.php` (`dashboardCustom`), `app/Modules/Results/{Contracts/ViewRequest,Application/ViewBlockResult}.php`, `app/Modules/Blocks/{Contracts/BlockCard,Application/ReadBlockCards}.php` (`defaultPeriod`)
- `resources/js/components/{DateRangePopover,DateRangeCalendar,BlockCard,DashboardGrid}.vue`, `resources/js/lib/{dateRange,dashboards}.ts`, `resources/js/pages/Dashboard.vue`

## Tasks & Acceptance

**Execution:**
- [x] Columns, commands, endpoints and the 422 rules
- [x] Results resolve the stored range and the member's selection through `PeriodResolver`
- [x] Popover, APG calendar, Block dropdown and the period subtitle
- [x] Tests: Database (store, validate, restore, survive, access), Unit (resolver), vitest (helpers, calendar keys, popover, page)

**Acceptance Criteria:**
- Given the I/O matrix, each row holds against PostgreSQL and in the component tests.

## Implementation Notes

- Epics 4.1 and 4.15 both say a raw client date is a 422 and that Custom takes two dates. They are reconciled as above: `custom` is a stored, validated dashboard value the server passes to the resolver (`dashboardCustom`); no request to results or to a Block selection carries a date.
- The data model has no per-Block "enabled presets" list, so the Own selector offers the whole catalogue (13 presets); the Block's Default date range is its initial value. A Block whose default is `custom` shows that value, disabled.
- The "differs from the dashboard Date Range" subtitle appears for Own-selector Blocks when a dashboard range is set and the Block's effective period differs. About this block already states the period behaviour in words (Story 4.6).
- The Date Range button shows the spinner while the save and the results re-read run; the Block cards keep their values (a pending item never replaces a held one).
- The popover sits in the desktop header group; the narrow layout arrives with Story 4.17.

## Verification

- Pest (SQLite, Unit, Database), vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour lint.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Custom's dates conflict with "raw date is 422" | medium | resolved | Only `SetDateRange` takes dates, validated as real days; see notes. |
| No per-Block enabled-preset list | low | accept | Whole catalogue offered; a Block-level list is a later authoring change. |
