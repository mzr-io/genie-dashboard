---
title: 'Map Data step shell and Roles section UI (Story 3.11b, UI stage 1)'
type: 'feature'
created: '2026-10-10'
status: 'done'
route: 'dispatch'
baseline_commit: '839d5ebd3a4ae0da9a09d6cc8f0eac49e518fbcb'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-11a-infer-field-roles-and-record-path-candidates.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-13c-harden-the-mapping-api-for-the-map-data-ui.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Wizard step 3 (Map Data) is a placeholder, so an Admin cannot review inferred field roles or choose the record path, although the backend (3.11a, 3.13c) serves both. This is stage 1 of the Map Data UI (3.11b); later stages add the slot checklist (3.12b), field picker and undo (3.13b), hotspots and legend (3.14).

**Approach:** Replace the step-3 placeholder with `MapDataStep.vue`: left column (step 1 and 2 summaries, sticky status bar, "1 · Roles", a "2 · Review block slots" placeholder, a collapsed "3 · Transforms" placeholder), keeping the existing sticky Live preview on the right. Add a typed API client for the mapping read and the record-path and field-role writes.

## Boundaries & Constraints

**Always:**
- Follow the epics.md Story 3.11 ACs for the parts listed here, the UX design files under `_bmad-output/planning-artifacts/ux-designs` (DESIGN.md and EXPERIENCE.md; cite the UX-DR numbers in code comments sparingly), the existing wizard conventions (`BlockWizard.vue`, `DataSourceStep.vue`, `SampleSection.vue`, `lib/blockSource.ts`, shared components, DashFlow tokens, `lib/announce.ts` live regions) and the canonical message catalogue (`resources/js/locales/labels.ts`; all copy there, no literals in components).
- API: `GET /api/v1/admin/blocks/drafts/{draft}/mapping` (use ETag / 304, `X-Background: 1` for background refreshes), `PUT .../mapping/record-path`, `PUT .../mapping/field-roles`; every write sends `draft_revision` and the edit-lock claim like the other wizard writes, adopts the returned `draft_revision` into the wizard's revision, and shares the wizard's save chain so it never races autosave; handle 409 `blocks.revision_conflict`, 423 lock lost and read-only mode like existing steps. Response fields: `checklist`, `roles` (candidates, `needs_choice`, `record_path`, `fields` with role, type, example, confidence, overridden, suggestion), `slot_map`, `query_plan`, `draft_revision`, `sample_revision`, `plan_stale`, `sample` ('ok'|'expired'|'invalid').
- Roles section (AC of 3.11): `scalar-card` per scalar (mono key, type chip with the type also in text, example, role chip with qualifier), rows of at most 4 (one per row under 640px); a `roles-table` of at most 5 sample rows with a role chip above each column (a real table, `th scope`, focusable scrollable region "Sample rows, scrolls sideways"); six roles, a chip never colour-only; chip = menu button "Role for {field}: {role}" with `menuitemradio` items, one-line description on focus, polite announcement after a change, focus returns to the chip, ", possible wrong role" when flagged; `suggestion-row` "✦ {field} looks like a Measure — use as series?" with "Set as Measure" (applies, focus moves to the chip) and "Keep as {role}" (dismisses, in memory); inline Undo after a role change (PUT the previous role back); record-path `radiogroup` when `needs_choice` with rows and columns counts, "Suggested", "looks like pagination, not data"; roles accepted collapses to the `roles-summary-line` with "Edit roles" (announce "Roles accepted. Roles table collapsed." only if focus was in the table), re-expands when a role is flagged without moving focus; status bar "Auto-mapped N of M · K confirmed · J required missing" from `checklist.counts` (aria-hidden meter, announced politely only when needs-review or required-missing changes); `msg:map-small-screen` once under 640px, never after Dismiss (localStorage in try/catch).
- Read-only viewers see everything but chips, suggestions and record-path radios are disabled with the reason; `sample: 'expired'|'invalid'` shows a recovery message pointing back to step 2; an empty or loading state exists.
- Lint, types and renderer lint rules pass; reuse components; keyboard operable; no colour-only signals.

**Never:** The slot checklist, field picker, hotspots, source legend, the APG field tree, the Datasets suggestion upsert, any backend change (report a backend gap instead of working around it).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Open step 3 | Draft with sample | Roles load; cards and table render; status bar shows counts | loading state |
| Change a role | pick Measure on a field | PUT field-roles; chip updates; announcement; Undo shown | 409/423 handled like other steps |
| Suspect role | field has a suggestion | suggestion-row; Set as Measure applies and focuses chip; Keep dismisses | N/A |
| Several arrays | `needs_choice` | radiogroup with Suggested and pagination-like note; choice PUT | 409/423 |
| Roles accepted | no flagged roles / "Looks right" | collapses to summary line; Edit roles re-expands | N/A |
| Read-only | lock held elsewhere | controls disabled with reason | N/A |
| No sample | `sample: 'expired'` | recovery message to step 2 | N/A |
| Small screen | width under 640px | one column, `msg:map-small-screen` once | storage failure ignored |

</frozen-after-approval>

## Code Map

- `resources/js/pages/admin/BlockWizard.vue` -- step 3 currently renders the "later-step" placeholder; wizard revision, save chain, lock claim and read-only state live here; `WizardStepper.vue`, `LivePreview.vue` (sticky preview, steps 1 to 3), `DataSourceStep.vue` and `SampleSection.vue` for patterns.
- `resources/js/lib/{blockWizard,blockSample,blockSource,draftBlocks,editLock,announce}.ts` -- API/error conventions (`WizardError`), claim handling; add `lib/blockMapping.ts`; `resources/js/locales/labels.ts`; `resources/js/types/error-codes.ts`.
- Backend responses: `app/Http/Controllers/Admin/{DraftSlotsController,DraftRolesController}.php`, `app/Modules/Mapping/Application/FieldRoleInference.php` (field and candidate shapes).
- Tests: `tests/js/block-wizard-*.test.ts` (mount helpers, fetch mocks); add `tests/js/block-wizard-map-data.test.ts`.

## Tasks & Acceptance

**Execution:**
- [ ] `resources/js/lib/blockMapping.ts` -- typed client and state helpers
- [ ] `resources/js/components/wizard/MapDataStep.vue` (+ small child components under `components/wizard/`) -- layout, status bar, roles, record path, placeholders
- [ ] `resources/js/pages/admin/BlockWizard.vue` -- render step 3, share revision, save chain, lock and read-only
- [ ] `resources/js/locales/labels.ts` -- all new copy
- [ ] `tests/js/block-wizard-map-data.test.ts` -- every matrix row, keyboard and announcement behaviour

**Acceptance Criteria:**
- Given a Draft with an inferred sample, when step 3 opens, then roles, record path and counts render from one mapping read and a role change persists with the returned revision.
- Given a read-only viewer, when step 3 opens, then nothing can be changed and the reason is stated.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Undo stores an override instead of clearing it | medium | patch | Sends null for an inferred role; optimistic state restored; tested. |
| Silent or unreported write failures | medium | patch | Visible, announced "not saved" message; tested. |
| RoleChip acts while disabled; unknown role; ArrowUp from no focus | medium | patch | Fixed; tested. |
| Role and status announcements overwrite each other | low | patch | Status text queued behind the role message; tested. |
| Record-path choice hidden when the roles collapse | medium | patch | List renders whenever `needs_choice`; tested. |
| Blank body when roles or checklist are null; no load when `draftId` arrives late | low | patch | Retry card, hidden status bar, watcher; tested. |
| Older read overwrites a newer optimistic patch | medium | patch | `seq` bumped on writes; tested. |
| In-records field without a table column has no control; Looks right not blocked; accepted announcement scope | low | patch | Fixed; tested. |
| Refresh failure, offline and conflict states untested | medium | patch | Tests added. |
| Autosave-chain race test; role-chip type-ahead; heading structure and focus on step open; column matching by display; glyphs and plurals in labels.ts | low | defer | Recorded in deferred-work.md. |
| No Continue on step 3 | low | defer | Arrives with the checklist in stage 2 (3.12b). |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green (retry the audit step alone if Packagist times out; run the Database suite with pest directly if composer's 300s timeout hits)
