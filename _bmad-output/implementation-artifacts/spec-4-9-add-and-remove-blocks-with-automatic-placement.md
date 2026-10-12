---
title: '4-9 Add and remove Blocks with automatic placement'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '144dbef'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The panel lists Blocks but "+ Add" and "× Remove" do nothing, and nothing can change a dashboard's membership.

**Approach:** Add the `AddItem` and `RemoveItem` commands (revision compare-and-set, `FOR UPDATE`, outbox event, idempotent per Block, Layout-kernel placement that moves nothing) and wire the panel, the ⋯ menu and unavailable cards to them with optimistic UI, a rollback toast, Show me and Undo, focus rules and the free-space hint.

## Boundaries & Constraints

**Always:** Existing items never move or resize on an add. A Block is on a dashboard at most once. A stale revision is a 409 and changes nothing. A Mandatory item cannot be removed (403, `msg:locked`). Removing needs no confirmation. The web role has no DELETE: removal goes through the definer function.

**Never:** No RestoreItem or keyboard undo (4.10), no move or resize (4.11, 4.12), no Template or mandatory application (Epic 7), no multi-add.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Add | visible published Block, current revision | 201, first free slot, revision +1, event | N/A |
| Add again | same Block, any revision | 200, same item, no new row or event | N/A |
| Stale add | old revision | rolled back, toast-rollback, layout reloaded | 409 |
| Hidden / unknown | not visible | nothing placed | 404 |
| Unpublished | no published version | nothing placed | 422 |
| Remove | Block on dashboard | gone at once, revision +1, event | N/A |
| Remove again | not there | no-op | 200 `removed:false` |
| Mandatory | locked item | stays, `msg:locked` | 403 |
| Below view | lands out of view | toast-add-below, edge pill, no scroll | N/A |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_26_100000_add_item_removal_and_mandatory_to_dashboard_items.php`
- `app/Modules/Dashboards/**` (`addItem`, `removeItem`, `ItemChange`, `BlockNotAvailable`, `ItemLocked`), `app/Http/Controllers/DashboardController.php`, `routes/api.php`
- `resources/js/pages/Dashboard.vue`, `components/{AddBlocksPanel,BlockCard,DashboardGrid}.vue`, `lib/dashboards.ts`, `stores/toasts.ts`

## Tasks & Acceptance

**Execution:**
- [x] Migration: `mandatory`, `dashboards_remove_item()`; tests bumped
- [x] `AddItem` / `RemoveItem` with CAS, placement, event; endpoints and error codes
- [x] Optimistic add and remove, toasts, Show me, edge pill, focus rules, free-space hint
- [x] Tests: kernel conformance (unit), Database (every matrix row, DELETE privilege), vitest (flows, rollback, focus, hint)

**Acceptance Criteria:**
- Given the I/O matrix, when Blocks are added and removed, then each row holds against PostgreSQL and in the component tests.

## Implementation Notes

- Add is placed from the rows the server holds under the lock, not from the client's `next_free_slot`, so two devices cannot pick the same slot.
- The temporary item shown while the request is in flight carries the catalogue's facts and no controls; the server's item replaces it.
- Undo of an add sends RemoveItem. Undo of a remove (RestoreItem with remembered geometry) and Ctrl+Z are Story 4.10; the remove toast carries no Undo until then.
- `toast-add`'s announcement names a neighbour and the Ctrl+Z shortcut; with no neighbour it says the Block was added. The shortcut itself arrives in 4.10.
- The ⋯ menu's "Remove from dashboard" and the Remove button on an unavailable card both call RemoveItem by Block id.

## Verification

- SQLite suite, Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour and renderer lints pass.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Remove toast has no Undo until Story 4.10 | low | defer | RestoreItem is 4.10's; the message says "removed" only. |
| `Show me` focus uses a temporary tabindex on the heading | false | none | `tabindex=-1` is the standard way to give a heading programmatic focus. |
| The pending item has no controls | false | none | It is replaced within the request; controls would act on an id the server does not know. |
