---
title: '4-6 Use Block chrome: refresh, minimize, About this block, footer'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '6ae567e'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A Block on a dashboard has no controls: it cannot be refreshed, collapsed or explained.

**Approach:** Give each card its header controls as configured: Refresh (this Block only, rate limited by a `pending_input` window, never calling a source), Minimize through the first layout command `SetMinimized` with `expected_revision`, the ⋯ menu with About this block, and a footer link resolved safely in the browser.

## Boundaries & Constraints

**Always:** Controls the Admin disabled are absent. Controls are named by their Block and have 28 px hit areas. A refresh is limited with `429` and does nothing; no source is called. Minimize persists per item on the server with `expected_revision`; a stale one is `409` and the client reloads and retries once. Users never see technical details.

**Never:** No removal (4.9), no expanded view or View as table (their Blocks and the footer's configuration are Epic 5), no live updates or pause (4.13, 4.14), no footer configuration (5.4).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Controls | all allowed / some off | named controls / absent ones | N/A |
| Refresh | outside window | target brought forward, spinner, focus kept | N/A |
| Refresh limited | inside window | 429, `aria-disabled`, `msg:refresh-limited` | nothing requested |
| Minimize | current revision | header only, Expand, persisted, revision +1 | N/A |
| Stale revision | old `expected_revision` | 409 with revision; reload and retry once | second refusal restores |
| About | menu | facts, data as of with zone, no technical for a User | N/A |
| Footer URL | template with tokens | encoded, new tab with ↗ | `javascript:` never a link |

</frozen-after-approval>

## Code Map

- `app/Modules/Dashboards/**` (`setMinimized`, `refresh`, `ErrorCode`), `app/Http/Controllers/DashboardController.php`, `routes/api.php`
- `app/Modules/Results/{Contracts,Application}/ManualRefresh.php`, `RefreshBlock.php`; `app/Modules/Ingestion/{Contracts,Application}/RefreshNow.php`, `BringTargetForward.php`
- `app/Modules/Blocks/{Contracts,Application}/BlockCard*.php`, `ReadBlockCards.php` (About facts)
- `resources/js/components/BlockCard.vue`, `lib/footerAction.ts`, `lib/dashboards.ts`, `pages/Dashboard.vue`

## Tasks & Acceptance

**Execution:**
- [x] `SetMinimized` command with revision, outbox event, 409 body
- [x] Manual refresh with window and `RefreshNow`; `subscribedTarget`
- [x] About facts on the card; Admin technical details; footer link util
- [x] Header controls, menu, About dialog, minimize and refresh flows in the page
- [x] Tests: Database (minimize, revision, refresh window, About), vitest (controls, flows, About, footer, util)

**Acceptance Criteria:**
- Given the I/O matrix, when a User uses the controls, then each row holds against PostgreSQL and in the component tests.

## Implementation Notes

- The manual-refresh window is a per-(member, target) timestamp in the `cache` store with a TTL equal to the window; the Valkey entry is the only state.
- A refresh brings the target's `next_due_at` forward; the dispatcher's own demand and budget rules still decide the fetch, so a refresh can never exceed them.
- `dateStyle` and `timeStyle` cannot be combined with `timeZoneName` in `Intl.DateTimeFormat`; About names the parts instead.
- The footer's target has no source of configuration until Story 5.4; the rendering and the URL resolution are in place and tested, and `block.footer` is null today.
- "Expanded view", "View as table" and Mandatory-lock are not built: their Blocks or their data (chart Blocks, tables, Templates) do not exist yet.

## Verification

- SQLite suite, Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check` and the renderer lint pass.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Expanded view, View as table and the Mandatory lock are not in this story | medium | defer | Their prerequisites (table and chart Blocks, footer configuration, Templates) are Epic 5 and 7; logged in deferred-work.md. |
| `Remove from dashboard` is disabled | low | defer | The command is Story 4.9's; the control says so. |
| Refresh while the target has no payload is accepted | false | none | It brings the target forward, which is what the person asked for. |
