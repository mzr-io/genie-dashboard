---
title: '4-7 Browse the Add-blocks Panel'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '8e7a9f0'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A member cannot see which Blocks they could put on a dashboard, and "+ Add block" does nothing.

**Approach:** Add a User-safe list endpoint of the published Blocks the member can see, and the Add-blocks Panel that opens from "+ Add block": a non-modal drawer that pushes the dashboard on wide screens and a modal sheet below 1024 px, with search, chips, filter, an announced count, row status and every list state.

## Boundaries & Constraints

**Always:** Every Workspace member sees every published Block (group access is Epic 6, and can only narrow the list). The list names no Endpoint, Query Plan or sample. Browsing creates no target and no subscription. Names, descriptions and tags are literal text, highlights included. State is never carried by colour alone.

**Never:** No add or remove (4.9), no inline Preview with sample values (4.8), no multi-add, no drag from the drawer, no "Request a block", no Mandatory group (Templates are Epic 7).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Open | "+ Add block" | complementary "Add blocks", focus on search, dashboard usable | N/A |
| List | published Blocks | rows with thumbnail, name, description, category, New | N/A |
| Search | text | matches names, descriptions, tags, highlighted; "n of m" | none: `msg:drawer-empty-search` + Clear |
| None | nothing published | `msg:drawer-none` | N/A |
| Load fails | API error | `msg:drawer-load-failed` + Retry, search and chips disabled | N/A |
| On dashboard | Block already added | `✓ Added` status text + `× Remove`, list not reordered | N/A |
| Narrow | < 1024 px | modal sheet, focus trapped | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Blocks/{Contracts,Application}/BlockCatalogue.php`, `CatalogueBlock.php`, `ReadBlockCatalogue.php`; `app/Http/Controllers/BlockCatalogueController.php`; `routes/api.php`; `config/dashflow.php` (`drawer.new_window`)
- `resources/js/components/{AddBlocksPanel,BlockThumbnail}.vue`, `lib/blockCatalogue.ts`, `composables/useAddBlocksPanel.ts`, `AppShell.vue`, `pages/Dashboard.vue`

## Tasks & Acceptance

**Execution:**
- [x] Catalogue contract, query, access filter, endpoint, "New" window setting
- [x] Panel: search, chips, filter, count, rows, states, footer, skip link
- [x] Push on wide screens with the sidebar rail, sheet on narrow
- [x] Tests: Database (list, access, New rule), vitest (every state, search, row status, helpers, page integration)

**Acceptance Criteria:**
- Given the I/O matrix, when the panel is used, then each row holds against PostgreSQL and in the component tests.

## Implementation Notes

- The thumbnail is a per-type schematic. "Generated from the version's field snapshot and presentation" is limited to the Block Type today because only `kpi-card` exists; richer faces arrive with the Block Types of Epic 5.
- The disclosure button opens the row's description and meta line; the inline sample preview is Story 4.8.
- `addedIds` comes from the layout the page already holds, so the panel adds no request and cannot disagree with the grid.
- The count's first value is on screen without an announcement; only a change is spoken, debounced about 500 ms through the shared announcer.
- Open state is a shared ref so the shell can collapse the sidebar; tests reset it.

## Verification

- SQLite suite, Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, renderer and colour lints pass.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| No Mandatory "On your dashboard" group | low | defer | Mandatory items come from Templates (Epic 7); nothing marks a Block mandatory yet. |
| Thumbnails do not vary beyond the Block Type | low | defer | Only `kpi-card` exists; per-type faces arrive with Epic 5. |
| Sidebar collapse uses a shared module ref | false | none | It is the smallest shared state between the page and the shell; reset on close. |
