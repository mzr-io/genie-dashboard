---
title: '5-5 Comparisons from a mapped previous value, with comparison modes'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: 'aa6d6ee'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A KPI's comparison slot can only show a number the response already holds as a change, so an Admin cannot show "↗ 14.2% from last year" from a headline and a previous value.

**Approach:** Let the comparison slot's mapped field be the previous value and let the Admin choose how the change is expressed (% change, absolute delta, percentage-point delta). The change is calculated on the server, exact, from the headline and the previous value.

## Boundaries & Constraints

**Always:** Exact decimal arithmetic at the platform scale, never a float. A missing, null or non-numeric previous value, or a previous value of 0 for % change, gives no change: the slot's empty display, never 0 or infinity, and the headline still renders. Percentage points are offered only for a ratio headline (a ratio calculated field or a percent format) and refused by the server otherwise.

**Never:** No change of the Block Type contract or slots. No prior-period request (Story 5.6).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| % change | 284680 vs 249195 | 14.239852 shown as percent, one place: "▲ 14.2%" | N/A |
| Absolute delta | 90 vs 100 | -10, arrow down, "-10" | N/A |
| Points | ratio 72 vs 70 | 2, "▲ 2.0 pp" | non-ratio headline: mode refused, not saved |
| Equal | current = previous | flat, no arrow | N/A |
| Missing or zero | previous null, text, or 0 for % | comparison empty, headline kept | reason `missing` |

</frozen-after-approval>

## Code Map

- `app/Modules/Mapping/Application/ComparisonChange.php` (arithmetic), `QueryPlanEngine.php` (applies the mode to the slot values and options), `Contracts/Presentation.php` (`mode` of the comparison slot).
- `app/Modules/Blocks/Application/ManageDraftSlots.php` (ratio check on save), `block-types/kpi-card/v1/{Shaper.php,Renderer.vue,payload.ts}` (`signed`).
- `resources/js/lib/blockPresentation.ts` (mode, example change), `resources/js/components/wizard/{PresentationEditor,SlotRow,SlotChecklist,MapDataStep}.vue`, `resources/js/locales/labels.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Comparison `mode` in the presentation rules, validated on the comparison slot only
- [x] Exact change calculation, applied by the engine, empty display for no change
- [x] Percentage points refused unless the headline is a ratio
- [x] Source and mode controls with the live example caption; signed absolute and point changes in the card
- [x] Tests: Unit, Database, vitest

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit, database and component tests.

## Implementation Notes

- The mode is stored as `config.presentation.slots.comparison.mode`; no new endpoint. Without a mode the slot behaves as before (the mapped value is the change).
- Default formats when the Admin set none: % change `percent` with one place, points a number with one place and `pp`, absolute the headline's format.
- The source control offers "Prior-period request" but only explains that it arrives with Story 5.6.
- Percentage figures: a ratio calculated field is already ×100, so points are the plain difference.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour and renderer lint, block-type pins.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Spec shows "↘ -10"; the card carries direction with the arrow only | medium | fixed | `signed` flag on the comparison payload, set for absolute and point modes; % change keeps the arrow only. |
| "Comparison (% change) is paused too" names the slot by its mode | low | defer | The existing API-changed notice names the slot by its checklist label; recorded in `deferred-work.md`. |
| Comparison slot label still reads "Comparison value or change %" | low | defer | Changing it alters the Block Type contract; recorded in `deferred-work.md`. |
