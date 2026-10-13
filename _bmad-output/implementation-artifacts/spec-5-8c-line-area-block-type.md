---
title: '5-8c Line/Area Block Type package'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '437a2c6'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-5-8a-series-slot-model-and-engine.md', '{project-root}/_bmad-output/implementation-artifacts/spec-5-8b-shared-chart-wrapper.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** There is no chart Block Type: with the series slot model (5.8a) and the shared chart wrapper (5.8b) in place, the Admin cannot pick Line/Area and publish a trend (Story 5.8; FR-32, FR-33, AR-28).

**Approach:** Add the package `block-types/line-area/v1/` (`schema.json`, `Shaper.php`, `Renderer.vue`, `payload.ts`) and register it: its Shaper, its Renderer, the type choice in step 1, its thumbnail, its chart settings (line or area, direct labels) and the preview regions of its slots. The wizard and the mapper stay schema-driven.

## Boundaries & Constraints

**Always:** Slots are Title, Subtitle, Icon, Footer action, X axis, Series 1 to 6, Series label 1 to 6 (a text slot, so it takes a Text Template), Highlighted point, Target line value and label. The Shaper is pure; a gap is a null and never 0; every text is literal. States follow the Block faces: skeleton, "No data for this period.", "Stale: last data 10:42", the unavailable message, an error with Retry, and a partial-data badge; none draws a zero line. A series without a label is named by its field, else "Series n". The series share the first series' format.

**Never:** No per-package branches in the mapper or the engine; no raw colour; no formatter string.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Rows | day, revenue, cost | x, Series 1 and 2 auto-mapped, named by their fields | N/A |
| Gap | a row with no value | null, partial badge, line broken | N/A |
| No axis or series | nothing mapped | `unavailable`, no plot | N/A |
| No value anywhere | every row null | `empty` | N/A |
| Highlight | x value equals the highlighted slot | that point drawn at 10px with a 2px ring | N/A |
| Hostile text | `<img onerror>` as a name | literal text in the label and the tooltip | N/A |

</frozen-after-approval>

## Code Map

- `block-types/line-area/v1/{schema.json,Shaper.php,Renderer.vue,payload.ts}`, `composer.json` (autoload), `block-types/pins.json`, `app/Modules/BlockTypes/Infrastructure/PackageShapers.php`, `app/Support/BlockTypes/BlockTypeGenerator.php` (expands `repeat`; an empty object on one line), generated `LineAreaSlots.php` and `block-types.ts`.
- `app/Modules/Mapping/Contracts/Presentation.php` (`chart_kind`, `direct_labels`), `Application/{PresentationOptions,QueryPlanEngine}.php` (series format, chart options, field names).
- `resources/js/lib/{blockRenderers,blockWizard,blockPresentation,textTemplate}.ts`, `components/{BlockThumbnail.vue,wizard/PresentationEditor.vue,wizard/LivePreview.vue}`, `pages/admin/BlockWizard.vue`, `locales/labels.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Package: schema, Shaper, payload type, Renderer with every state
- [x] Registration: autoload, pin, Shaper map, Renderer map, generated types, step 1 choice, thumbnail
- [x] Chart settings (line or area, direct labels) in the presentation rules and the X axis row
- [x] Preview regions and renderer for the chart type
- [x] Tests: Unit, vitest

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit and component tests.

## Implementation Notes

- The series format is the presentation of `series_1`; `chart_kind` and `direct_labels` are top-level presentation settings. A series name falls back from its label slot to the field's last member to "Series n".
- Step 1 lets a Draft change its type; keeping the same-name slots when it does is Story 5.15 and a mapped Draft that changes type must be re-mapped until then.
- A seventh series is not offered (six slots): there is nothing to fold.
- The Y ticks and the label come from the same format descriptor; the label and tooltip show the formatted exact decimal.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour and renderer lint, `block-types:check`, block-type pins.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| The generator's empty object broke the formatter drift check | low | fixed | An empty `default_aggregations` is written `{}`. |
| `expandKpiPayload` assumed a headline | medium | fixed | It reads another type's payload safely. |
| Changing the type of a mapped Draft | medium | defer | Story 5.15 (change type while keeping slots). |
