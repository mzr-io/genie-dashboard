---
title: 'Slot checklist, confidence and Continue gating UI (Story 3.12b, UI stage 2)'
type: 'feature'
created: '2026-10-10'
status: 'done'
route: 'dispatch'
baseline_commit: '6c56584e8f8f988aab808ecb31ded504e142cda5'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-11b-map-data-step-roles-ui.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-12a-auto-map-slots-with-confidence.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-13c-harden-the-mapping-api-for-the-map-data-ui.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Step 3 shows roles but has no "2 · Review block slots" checklist, so an Admin cannot see which field feeds each slot, how confident the mapping is, confirm it, re-run auto-map, or be blocked from continuing while required slots are missing. The backend (3.12a, 3.13c) serves all of it. This is UI stage 2; the field picker, undo, Treat as and API-change messages (3.13b) and hotspots (3.14) follow.

**Approach:** Fill the "2 · Review block slots" placeholder of `MapDataStep.vue` with a slot checklist built from the mapping read, add Confirm and Re-run auto-map actions, a Needs-review filter, a status bar with the "Sample data" badge, and a "Continue to Preview →" gate; wire the wizard's step 3 to step 4 navigation.

## Boundaries & Constraints

**Always:**
- Follow epics.md Story 3.12 ACs (UI parts), UX files under `_bmad-output/planning-artifacts/ux-designs/.../DESIGN.md` and `EXPERIENCE.md`, the stage-1 conventions (`lib/blockMapping.ts`, wizard save chain, revision adoption, lock claim, read-only and blocked reasons, announcements) and the labels catalogue (all copy in `labels.ts`).
- API (already built): `GET .../mapping` (`checklist` with groups HEADER/KPI/META, slots with state, confidence, reason, suggestion, path, display, example; `counts`; `blockers`; `api_changed`; baseline), `POST .../mapping/auto-map`, `POST .../mapping/slots/{slot}/confirm`; writes send `draft_revision` and the lock claim; adopt the returned revision, `query_plan` and refresh the live preview after each write (the existing preview refresh used by steps 1 and 2).
- Checklist: label-caps group headers HEADER, KPI, META; required slots show a red `*`; each row is a `group` labelled "{Slot}, {required|optional}, {state}, {confidence} confidence"; all eight states (Auto-mapped High/Medium/Low, Confirmed, Overridden, Optional-empty, Required-missing, Type-mismatch) render with the confidence word and an `aria-hidden` meter/dot, the badge reading "Confidence: Medium", and a reason caption for Medium and Low; Low rows are pinned first and order is computed only on load and on Re-run; Auto-mapped High counts as Confirmed; Confirm on Medium/Low shows "Confirmed by you" (visible, not hover-only) and is never bound to Enter on the row; a Required-missing row with a suggestion shows "Use {field} as headline" with the error left rule and soft error background (use existing tokens); baseline field is shown to the Admin but not mapped.
- Radiogroup filter "All (n) · Needs review (n) · Missing (n)"; confirming under Needs review keeps the row while focus is in the list and announces "{Slot} confirmed. {n} slot(s) still need review." politely; status bar "Auto-mapped N of M · K confirmed · J required missing" (stage-1 bar, now from the checklist) with the "Sample data" badge once, announced only when needs-review or required-missing changes.
- "↻ Re-run auto-map" button: calls auto-map; confirmed and overridden rows are never changed; re-sorts.
- Continue: "Continue to Preview →" is `aria-disabled` (not `disabled`) while `blockers` is non-empty, with the reason inline listing the blockers (`msg:required-slot-missing` style from the catalogue, e.g. "Map Headline value to continue. Optional slots can stay empty."), focus moves to the first blocking row on activation, several blockers add an error summary of links that is focused; a Medium optional slot does not block. When clear it goes to wizard step 4 (the existing later-step placeholder until 3.16) and marks step 3 completed using the wizard's existing completion/persistence rules.
- Read-only viewers see the checklist but Confirm, Re-run and Continue-write actions are blocked with the reason; `sample: 'expired'|'invalid'` already shows the recovery message.
- Lint, types, colour and renderer lint pass; keyboard operable; no colour-only signals.

**Never:** The field picker, "Change field", override, undo, Treat as, `api_changed` messages beyond showing the Required-missing state (3.13b); hotspots and legend (3.14); backend changes (report a gap instead); step 4 content.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Open | mapping with Medium headline | rows grouped; Medium shows reason caption; counts in status bar | loading/failure like stage 1 |
| Confirm | Medium row | row shows "Confirmed by you"; announcement; counts update; preview refreshes | 409/423/session as stage 1 |
| Filter | Needs review | only Low/Medium unconfirmed rows; row stays after confirm while focus is in list | N/A |
| Re-run | confirmed + overridden rows | unchanged; others recomputed; order recomputed | as above |
| Required missing | no headline | red-ruled row with suggestion; Continue aria-disabled with reason; activation focuses row | N/A |
| Several blockers | missing + unconfirmed Low | error summary of links, focused | N/A |
| Medium optional | only Medium optional unconfirmed | Continue allowed | N/A |
| Read-only | lock held elsewhere | controls blocked with reason | N/A |
| Continue ok | no blockers | goes to step 4; step 3 completed | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/components/wizard/MapDataStep.vue` (placeholder for "2 · Review block slots", status bar, `submit` / write helpers, announcements, `load`), `RolesSection.vue`, `RoleChip.vue`; `resources/js/lib/blockMapping.ts` (client, types, tones); `resources/js/pages/admin/BlockWizard.vue` (step 3 render, Back button, step completion, preview refresh, `runInChain`); `LivePreview.vue`; `resources/js/locales/labels.ts`.
- Backend shapes: `app/Modules/Mapping/Contracts/{AutoMapResult,SlotState}.php`, `app/Modules/Mapping/Application/AutoMapper.php`, `app/Http/Controllers/Admin/DraftSlotsController.php`, `app/Modules/Blocks/Application/ManageDraftSlots.php`.
- Tests: `tests/js/block-wizard-map-data.test.ts` (fetch mock and helpers); new `tests/js/block-wizard-map-slots.test.ts`.

## Tasks & Acceptance

**Execution:**
- [ ] `lib/blockMapping.ts` -- checklist types, confirm and re-run calls, ordering and blocker helpers
- [ ] `components/wizard/SlotChecklist.vue` (+ `SlotRow.vue`, `ConfidenceMeter.vue`) -- groups, rows, states, filter, actions, error summary
- [ ] `MapDataStep.vue`, `BlockWizard.vue` -- mount the checklist, status bar from checklist counts, "Sample data" badge, Continue gating and step 3 completion, preview refresh after writes
- [ ] `labels.ts` -- all new copy
- [ ] `tests/js/block-wizard-map-slots.test.ts` -- every matrix row, keyboard and announcement behaviour

**Acceptance Criteria:**
- Given a mapping with a Medium headline, when the Admin confirms it, then the row reads Confirmed by you, counts update, the preview refreshes and the announcement fires.
- Given a required slot is missing, when the Admin activates Continue, then it stays on step 3, states the reason and focuses the first blocking row.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Blockers for unlisted slots dropped; Continue enabled with no checklist; unknown reason empty | high | patch | Gated on `checklist.blockers`; generic labels; tested. |
| `attempted` never resets; summary link to a filtered-out row; `first-blocker` read after nextTick | medium | patch | Fixed; tested. |
| Kept rows linger; confirm pins before success; no busy state | medium | patch | Pin only on success, clear on focus-out and Re-run, `aria-busy`; tested. |
| `adopt` null still announces; grammar "still need review"; lower-cased slot label; Confirmed rows show High confidence | low | patch | Fixed; tested. |
| Added a key to the closed message catalogue | medium | patch | Removed; announcement is a function in `labels.ts`. |
| Second Continue button bypass | false | rejected | Only a Back button exists on step 3; test added. |
| Required, type-mismatch and api_changed rows offer no resolving control | low | defer | Needs the field picker (3.13b). |
| Live preview does not refresh after writes | medium | defer | The front end has no preview client; built before the hotspots stage. |
| Two confidence meters; `li role=group` list semantics; unfilled-segment contrast; Sample badge tooltip | low | defer | Recorded in deferred-work.md. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green (retry the audit step alone if Packagist times out; run the Database suite with pest directly if composer's 300s timeout hits)
