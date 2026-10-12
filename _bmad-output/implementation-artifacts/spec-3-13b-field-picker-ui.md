---
title: 'Field picker, override with Undo, Treat as and API-change messages UI (Story 3.13b, UI stage 3)'
type: 'feature'
created: '2026-10-10'
status: 'done'
route: 'dispatch'
baseline_commit: 'b675517a1b9e7527669398eacddcf3c55096cd1c'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-12b-slot-checklist-ui.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-13a-override-pick-field-and-fix-mismatches.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-13c-harden-the-mapping-api-for-the-map-data-ui.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A slot row in the Map Data checklist cannot be changed: there is no "Change field" picker, no override with Undo, no "Treat as", and no message when the API response changed under a mapping, although the backend (3.13a, 3.13c) serves all of it. This is UI stage 3 (3.13b); hotspots, the preview client and the legend (3.14) follow.

**Approach:** Add a field-picker popover to each slot row, an override flow that shows the role-change note with Undo, the Type-mismatch pill with compatible fields and "Treat as", and the `msg:api-changed` and `msg:api-match-found` messages driven by `checklist.api_changed`.

## Boundaries & Constraints

**Always:**
- Follow epics.md Story 3.13 ACs (UI parts) and the UX files under `_bmad-output/planning-artifacts/ux-designs/.../DESIGN.md` and `EXPERIENCE.md`, the stage 1 and 2 conventions (`lib/blockMapping.ts`, `MapDataStep.vue` `submit` chain, revision adoption, blocked reasons, announcements, `SlotChecklist.vue`, `SlotRow.vue`) and the labels catalogue (all copy in `labels.ts`; the message catalogue is closed at its canonical keys: do not add keys to `en.ts`).
- API (built): `GET .../mapping/slots/{slot}/fields` (fields with path, display, type, effective type, role, example, `compatible`, `reason`; compatible first), `PUT .../mapping/slots/{slot}` {path|null, draft_revision} returning `role_change` and `undo`, `POST .../mapping/undo` {undo payload, draft_revision}, `PUT .../mapping/field-types` {path, type|null}; 409 `blocks.slot_changed` for a stale undo; refusals are 422 `slot.<key>` with `compatible`. All writes use the lock claim, send `draft_revision`, adopt the returned revision, checklist and `query_plan`.
- Picker: "Change field ⌄" on every mappable row opens a 320px popover with a search box at the top, a listbox of fields (indent guides from the path depth, type chips with the type also in text, mono example values), compatible fields first, "n fields match" announced politely, incompatible fields `aria-disabled` with the reason in `aria-describedby` and shown as a muted caption, the current field as the active option, full keyboard operation (arrows, Home/End, type-ahead, Enter picks, Esc closes and returns focus to the opener), focus back on the slot row when it closes after a pick. Do not offer "Static text" (the backend cannot store it) nor Text Template or Calculated Field; report that gap in Implementation Notes.
- After an override: the row reads Overridden and shows "{field} is now a {Role} (was {Old role}). Undo" when `role_change` is present; Undo posts the returned payload, restores the row, announces politely; a stale undo (409 `blocks.slot_changed`) shows a clear message and refreshes; Undo disappears after another write to that slot.
- Type mismatch: the row shows a `slot-pill-error` "Type mismatch", the server reason, the compatible fields as buttons that open the picker pre-filtered or pick directly, and a "Treat as …" menu (text, number, boolean, date) that PUTs the effective type then re-reads; Continue stays blocked (already by blockers).
- API change: for each `checklist.api_changed` entry show `msg:api-changed` ("API response changed: field {path} no longer present → {Slot}: Unavailable") on the row or a banner above the checklist, and when `match` is present `msg:api-match-found` with a "Use {match}" button that overrides (never applied silently).
- Read-only (`blocked`): the picker, Treat as, Undo and "Use" buttons are not rendered; a read-only view of the mapping remains. `blocks.edit` is implied by the page; no extra permission logic.
- Lint, types, colour and renderer lint pass; keyboard operable; no colour-only signals; no focus trap leaks.

**Never:** Hotspots, the preview client, the source legend (3.14); backend changes (report a gap); static text mapping; adding catalogue keys.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Open picker | headline row | search focused; compatible fields first; "n fields match" announced | loading and failure state inside the popover |
| Pick compatible | field with a different role | override PUT; Overridden row with role-change note and Undo; counts update | 409/423/session as stages 1-2 |
| Pick incompatible | text field for number slot | option aria-disabled with reason; Enter does nothing | N/A |
| Undo | click Undo | undo POST; row and role restored; announced | 409 slot_changed message |
| Type mismatch | mismatch row | pill, reason, compatible fields, Treat as | PUT failure message |
| Treat as number | string "284680" | field-types PUT; re-read; row compatible | N/A |
| API changed | `api_changed` entry with match | api-changed message and "Use {match}" button | N/A |
| API changed, no match | entry, match null | message only | N/A |
| Esc / pick | popover open | closes; focus to opener / slot row | N/A |
| Read-only | blocked | no picker, no actions | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/components/wizard/{SlotChecklist,SlotRow,MapDataStep}.vue`, `RoleChip.vue` (menu keyboard pattern), `SampleTree.vue` and the existing combobox/listbox/popover components under `resources/js/components` (reuse, do not reinvent); `resources/js/lib/blockMapping.ts`; `labels.ts`.
- Backend: `DraftSlotsController.php`, `ManageDraftSlots.php` (`override`, `undo`, `fields`), `AutoMapper.php` (`fieldsFor`, reasons), `DraftSlotRequest.php`.
- Tests: `tests/js/block-wizard-map-slots.test.ts` (fetch mock and helpers); new `tests/js/block-wizard-map-picker.test.ts`.

## Tasks & Acceptance

**Execution:**
- [ ] `lib/blockMapping.ts` -- picker, override, undo, field-type calls and types
- [ ] `components/wizard/FieldPicker.vue` (+ small children) -- popover, search, listbox, keyboard, announcements
- [ ] `SlotRow.vue`, `SlotChecklist.vue`, `MapDataStep.vue` -- Change field, role-change note and Undo, mismatch UI, Treat as, api-change messages, read-only
- [ ] `labels.ts` -- all new copy (no `en.ts` keys)
- [ ] `tests/js/block-wizard-map-picker.test.ts` -- every matrix row

**Acceptance Criteria:**
- Given a headline row, when the Admin picks a compatible field with another role, then the row is Overridden, the role-change note with Undo shows, and Undo restores the slot and role.
- Given a text field is offered for the headline, when the Admin tries to pick it, then it is aria-disabled with the reason and nothing is sent.

## Implementation Notes

- Gap: "Static text" is not offered. The picker listing returns a `static_text` entry for text slots, but the override endpoint cannot store it, so the client drops it. Text Template and Calculated Field are not offered (Epic 5).
- Gap: the 422 refusal's `compatible` list is not exposed by `wizardCall` (it keeps only `reason`, `check`, `setting`), so a Type-mismatch row reads its fitting fields from the picker listing instead.
- Gap: `msg:api-match-found` needs a `{value}` and the API gives only `{path, display}`; the value comes from the roles' example for the match, and the catalogue's "[Use {field}]" tail is replaced by the row's own button.
- A stale undo (409 `blocks.slot_changed` carries `current`) and a refused pick/Treat as (422) are handled in `MapDataStep` so the page's revision chooser does not open.

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Every 422 treated as a slot refusal; wrong Treat as fallback text | medium | patch | Only `slot.` keys are refusals; Treat as has its own message; tested. |
| Stale Undo notes survive other writes | medium | patch | One Undo note, cleared by Re-run, Confirm, Treat as; tested. |
| Failed or skipped re-read after a write | medium | patch | `reread()` with the refresh-failed note; tested. |
| api-match text built by regex surgery | medium | patch | Built from a label function; tested with and without an example. |
| Fits watcher race; empty-string blocked; buttons not disabled while pending | medium | patch | Fixed; tested. |
| Picker keyboard with an empty list; disabled-option feedback; window blur; current-field tracking | medium | patch | Fixed; tested. |
| Treat as menu: no focus-out close, no current type, no reset | low | patch | Fixed; tested. |
| No way to clear a mapped optional slot; "Static text" not offered | low | defer | Backend cannot store static text; clear needs a "None" entry. |
| Reopen the popover after a refused pick; only 5 fitting fields listed; one GET per mismatch row | low | defer | Polish. |
| Weak test for a 422 without a `slot.` key | low | defer | Assertion is negative only. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green (retry the audit step alone if Packagist times out; run the Database suite with pest directly if composer's 300s timeout hits)
