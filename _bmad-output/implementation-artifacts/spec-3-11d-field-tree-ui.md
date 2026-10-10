---
title: 'Searchable field tree and Datasets suggestion in the record-path list (Story 3.11d, UI)'
type: 'feature'
created: '2026-10-10'
status: 'done'
route: 'dispatch'
baseline_commit: 'f35c16d4904c8a5c067fc2f6b7c6aaeceb3ea375'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-11b-map-data-step-roles-ui.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-13b-field-picker-ui.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-11c-datasets-suggestion-catalog.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The rest of Story 3.11 is missing in the Map Data step: a deeply nested scalar cannot be found in a searchable, collapsible field tree with a breadcrumb, a "Use" action and a "Selected:" footer, field types do not show role-family type chips in a tree, and the Datasets suggestion (3.11c) is not shown in the record-path list.

**Approach:** Add an APG tree `FieldTree.vue` in the Roles section area of `MapDataStep.vue` that uses the sample's node list, lets the Admin search and fold branches, and "Use"s a compatible leaf for a chosen slot through the existing override flow; and show the `dataset_suggestion` as a non-applied "Used in an earlier block" hint in `RecordPathList`.

## Boundaries & Constraints

**Always:**
- Follow epics.md Story 3.11 ACs for the tree, the type-chip AC (UX-DR-38) and the Datasets AC, the UX files under `_bmad-output/planning-artifacts/ux-designs/.../DESIGN.md` and `EXPERIENCE.md`, the stage 1 to 4 conventions (`lib/blockMapping.ts`, `MapDataStep.vue` `submit` chain and revision adoption, `FieldPicker.vue` keyboard patterns, blocked and read-only handling, announcements) and the labels catalogue (all copy in `labels.ts`; do not add keys to the closed message catalogue `en.ts`).
- Tree data: the sample's flat pre-order node list already in the wizard (`sample.stored.shape.nodes`: id, parent, depth, key, path, display, type, example, count) plus the mapping read; no new backend calls except the existing slot-fields listing (`GET .../mapping/slots/{slot}/fields`) for per-leaf compatibility and reasons, and the existing override (`PUT .../mapping/slots/{slot}`, via the step's `pickField`).
- APG tree: `role=tree`, `treeitem`, `group` for children, `aria-level`, `aria-expanded` on branches, `aria-setsize`, `aria-posinset`, roving tabindex; keys: Up/Down, Right (expand or first child), Left (collapse or parent), Home/End, Enter on a leaf = Use, type-ahead; search box filters (matching nodes and their ancestors stay, branches auto-expand; "n fields match" announced politely); expand all / collapse all; leaves show a type chip (number blue Measure family, text grey Label, date violet Time, array orange Filter, object neutral; type always also in text) and a mono example; incompatible leaves are `aria-disabled` with the reason shown and in `aria-describedby` (use the server reason; fields inside the records read "Needs aggregation (a later release).").
- Breadcrumb `nav` "Field path" for the focused node; "Use" button per leaf (Enter does the same) applies to the slot chosen in a small "Use for" select defaulting to the headline slot (first required unmapped or the headline), disabled while a write is pending or when blocked; footer "Selected: {display path} · {example} → {formatted}" (formatted as the card would, reuse the card's number formatting helper if one is exported, else show the example only); after Use the row of that slot reads Overridden through the existing flow (note, Undo, announcement, re-read, preview refresh).
- Read-only (`blocked`): the tree renders for browsing, Use is not rendered, reason stated. `sample: 'expired'|'invalid'` or no nodes: the tree is not rendered. The tree collapses by default behind a "Browse all fields" disclosure and opens it automatically when a Required slot is missing or the Admin activates "Choose from the field tree" on a required-missing row.
- Datasets suggestion: when `read.dataset_suggestion` is present and the Draft has no chosen record path, `RecordPathList` marks the matching candidate "Used in an earlier block" (text, not colour only) and, when the suggestion is not among the candidates, shows a one-line note; it is NEVER selected automatically.
- Lint, types, colour and renderer lint pass; keyboard operable; no colour-only signals; reduced motion respected.

**Never:** Backend changes (report a gap); applying a suggestion automatically; new catalogue keys; a new API; hotspot or preview changes.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Open tree | sample with nested nodes | collapsed disclosure; opening shows an APG tree with levels, sizes, chips, examples | no nodes: not rendered |
| Search | query "amount" | matching leaves and ancestors, branches auto-expanded, "n fields match" announced | no match: empty message |
| Keyboard | arrows, Home/End, type-ahead | roving focus per APG; breadcrumb follows | N/A |
| Use a compatible leaf | Enter or Use on `data.summary.kpis.total.amount` | override via the step's flow; row Overridden with Undo; footer shows Selected | 409/423/422 as stage 3 |
| Incompatible leaf | text leaf for headline | aria-disabled with reason; Enter does nothing | N/A |
| Footer | focused leaf | "Selected: path · example → formatted" | N/A |
| Read-only | blocked | browse only, no Use, reason | N/A |
| Dataset suggestion | `dataset_suggestion` present, no chosen path | matching candidate marked "Used in an earlier block"; not selected | N/A |
| Suggestion not a candidate | path absent from candidates | one-line note only | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/components/wizard/{MapDataStep,RolesSection,RecordPathList,FieldPicker,SlotChecklist,SlotRow,SampleTree}.vue` (`SampleTree.vue` is the existing sample tree from 3.8: reuse its node handling and keyboard code where it fits, do not break it), `lib/blockMapping.ts` (`fetchSlotFields`, `putSlotOverride`, role tones, types), `lib/blockSample.ts` (node and shape types), `locales/labels.ts`; block-types card formatting helpers under `block-types/kpi-card/v1/display.ts` (read only).
- Backend shapes (read only): `DraftSlotsController.php` (`dataset_suggestion`, roles `candidates`), `AutoMapper::fieldsFor`.
- Tests: `tests/js/block-wizard-map-*.test.ts` (fetch mocks and helpers); new `tests/js/block-wizard-map-tree.test.ts`.

## Tasks & Acceptance

**Execution:**
- [ ] `lib/blockMapping.ts` (or `lib/fieldTree.ts`) -- tree model: visible rows, search, expand state, type-ahead, compatibility join
- [ ] `components/wizard/FieldTree.vue` -- APG tree, breadcrumb, Use, footer, chips, search
- [ ] `MapDataStep.vue`, `RolesSection.vue`, `SlotRow.vue` -- disclosure, auto-open for required-missing, "Choose from the field tree"
- [ ] `RecordPathList.vue` -- dataset suggestion hint and note
- [ ] `labels.ts` -- all new copy
- [ ] `tests/js/block-wizard-map-tree.test.ts` -- every matrix row, keyboard and announcement behaviour

**Acceptance Criteria:**
- Given a deeply nested numeric leaf, when the Admin focuses it and presses Enter, then the headline is overridden to it and the footer shows the selected path and example.
- Given a dataset suggestion for a record path the Draft has not chosen, when the record-path list renders, then the candidate is marked and no radio is selected.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Stale slot listing used while a new one loads | high | patch | Listing cleared on slot or refresh change; tested. |
| Repeat "Choose from the field tree" ignored after changing "Use for" | medium | patch | Nonce prop; tested. |
| Passive focus marks a leaf selected; footer says "Selected" for a focused leaf | medium | patch | Active vs selected split; footer says "Focused" first; tested. |
| No retry or feedback on a failed, loading or busy listing | medium | patch | Retry button and announcements; tested. |
| Search misses the displayed type word; expansion not restored; focus lost on collapse | low | patch | Fixed; tested. |
| Dead "Choose from the field tree" button and tree shown for an expired sample | medium | patch | Gated on `sample === 'ok'` and nodes; tested. |
| Suggestion hint persists after a path is chosen; badge hard-coded | low | patch | `pathChosen` prop and shared Tag; tested. |
| Relative vs absolute listing paths | low | patch | Normalised in `listingOf`; unit test. Check against live server data. |
| Footer formatter tied to the kpi-card; suggestion `fields` and `updated_at` unused; hint hidden when the list is not rendered | low | defer | Only kpi-card exists; polish. |
| Reopen-when-missing and `*` expand-siblings keys untested | low | defer | Small gaps. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green (retry the audit step alone if Packagist times out; run the Database suite with pest directly if composer's 300s timeout hits)
