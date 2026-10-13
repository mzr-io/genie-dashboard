---
title: '5-15 Change Block Type while keeping same-name Slots'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: 'db9a495'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-3-12a-auto-map-slots-with-confidence.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Changing a Draft's Block Type saved the type but left the stored Query Plan written for the old one, so the preview and the checklist disagreed until some other mapping write (Story 5.15; FR-23, FR-30, FR-32, UX-DR-180, 203, 204, 199).

**Approach:** A save that changes the type re-reads the mapping at once, in the same request, with the existing re-run: slots with the same name keep their mapping, presentation rules and Confirmed or Overridden state; the plan is written for the new type; a slot with no counterpart reads unmapped, a required one as Required-missing, an unfit one as Type-mismatch. The decisions of slots the new type lacks stay stored, so the way back restores them.

## Boundaries & Constraints

**Always:** Title, Subtitle, Icon and Footer action are kept. Transforms, Calculated fields and Templates are kept and judged against the new type's slots as they are today. A draft without a sample only saves the type. The change is refused with the usual 423 when the lock was taken, and a conflicting revision is the usual 409. The type change is announced politely.

**Never:** The re-read never alters a Confirmed or Overridden decision, never drops a stored decision of another type's slot, and never changes a published version.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Switch | KPI card to Line/Area | plan has no `headline_value`; `slot_map` unchanged | N/A |
| Back | Line/Area to KPI card | plan has `headline_value`; still Confirmed | N/A |
| No sample | type change only | saved; nothing re-read | N/A |
| Lock lost | another holder | 423, nothing written | existing |

</frozen-after-approval>

## Code Map

- `app/Http/Controllers/Admin/DraftController.php` (the re-run after a type change).
- `resources/js/components/wizard/ConfigureBlockStep.vue`, `resources/js/locales/labels.ts` (type names, announcement).
- `tests/Database/DraftSlotsTest.php`.

## Tasks & Acceptance

**Execution:**
- [x] Re-read the mapping in the save that changes the type
- [x] Polite announcement and names of all eleven types
- [x] Tests: Database (switch, back, no sample)

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the Database tests.

## Verification

Pest Unit, Feature, Architecture and Database suites, vitest, `vp check`, `vue-tsc`, PHPStan and Pint.

## Implementation Notes

- "Editing a published Block creates a new Draft only" belongs to Story 6.1 (backlog): a Block keeps its type when a Draft is created from it, and a Draft is the only thing a save writes, so the published version is never touched here.
- The required-slot message, the Type-mismatch reasons and "Treat as" are the existing checklist's.
