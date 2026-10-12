---
title: 'Presentation rules editor in the Map Data step (Story 3.15b, UI)'
type: 'feature'
created: '2026-10-11'
status: 'done'
route: 'dispatch'
baseline_commit: '6baa18450edb10f965d0c45f6d7d0b91fa6f6977'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-15a-presentation-rules-backend.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-13b-field-picker-ui.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-14-preview-hotspots-and-source-legend.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Story 3.15's backend (3.15a) stores and validates `config.presentation` and the KPI card renders it, but the Map Data step has no editor: an Admin cannot format slots, set emphasis or direction, add threshold bands, set labels or an empty display.

**Approach:** Add a presentation editor to the slot row (one expanded row at a time) that reads `presentation` from `GET .../mapping`, writes through `PUT .../mapping/presentation` with the draft revision, shows server `config.presentation.<path>` errors inline, gates Continue on them, and refreshes the preview from the returned `query_plan`.

## Boundaries & Constraints

**Always:**
- Row expands one at a time; editor has Format, Emphasis, Direction in three columns, one column under 640px.
- Format: number, integer, decimal places, thousands separator, percent, currency (code and symbol), compact, date pattern, relative time, duration, units suffix, with a live example computed through the same descriptor path as the Renderer (`Intl`, workspace/browser locale); stored values hold descriptors only, never formatted strings.
- Emphasis role/size/weight; a second headline is refused with the server reason shown. Direction "Higher is better"/"Lower is better" with the note "Colors the comparison: up = green"; lower-is-better adds "· better"/"· worse" text. Direction applies to the comparison slot only.
- Bands: `+ Add band` rows with order numeral, condition (≥, ≤, between with second value), colour select (Success, Warning, Error, Info, Neutral; swatch plus name; no accent), icon select from `resources/js/lib/blockIcons.ts`, label, remove; stacks under 640px; first match wins top to bottom; a band lacking icon or label is blocked client-side and by the server.
- Label text, prefix, suffix, and an empty display (short text, headline excluded).
- Invalid values show inline errors, block Continue to Preview, and the preview keeps its last valid state. Writes follow the existing lock claim/revision/undo and autosave patterns of the other mapping writes (no-op skip, 409/423 handling).
- Use existing shared controls, design tokens, message catalogue (`lint:colors`, catalogue gates), keyboard and focus conventions.

**Never:** backend or Block Type changes (3.15a), new Block Type versions, accent colour for bands, locale-formatted strings stored, aggregation.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior |
|----------|--------------|----------------------------|
| Currency | headline 284680, USD symbol | example "$284,680"; saved descriptor only |
| Negative places / bad currency | -1 / `XX1` | inline error, Continue blocked, preview unchanged |
| Second headline | role headline on another slot | server reason shown inline |
| Band without icon or label | add band, leave empty | blocked with message |
| Reorder/remove band | remove first | order numerals renumber; first match still wins |
| Lower is better | direction lower | note shows "better"/"worse" text |
| Narrow viewport | <640px | columns and band rows stack |
| Stale revision / lost lock | PUT 409/423 | existing conflict/lock handling, nothing lost |

</frozen-after-approval>

## Code Map

- `resources/js/components/wizard/{MapDataStep,SlotChecklist,SlotRow}.vue`, `resources/js/lib/{blockMapping,blockPreview,blockWizard,blockIcons}.ts`, `resources/js/types/block-types.ts`, `block-types/kpi-card/v1/{display.ts,payload.ts}` (reuse the format descriptor path).
- API: `GET/PUT /api/v1/admin/blocks/drafts/{draft}/mapping(/presentation)` (3.15a), controller `DraftSlotsController`.
- Tests: `tests/js` component/lib tests beside existing wizard tests; keep `AdminAccessTest` unchanged.

## Tasks & Acceptance

- [x] `blockMapping.ts` / types -- presentation read, write, error-path mapping
- [x] new editor components under `components/wizard/` -- format, emphasis, direction, bands, labels, empty display, live example
- [x] `SlotRow.vue` / `MapDataStep.vue` -- expand one at a time, Continue gating, preview refresh
- [x] catalogue messages and `tests/js` -- every matrix row

**Acceptance Criteria:**
- Given a mapped slot, when the Admin sets a currency format and a band, then the example shows the formatted value, the write succeeds and the preview shows the band badge with icon and label.
- Given an invalid presentation, when entered, then an inline error shows, Continue is blocked and the preview keeps its last valid state.

## Verification

- `bin/tools composer ci:check` -- expected: all green (see 3.15a notes on the database timeout).
