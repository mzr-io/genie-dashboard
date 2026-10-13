---
title: '5-7 Colour, threshold and direction rules'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '974294d'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-3-15a-presentation-rules-backend.md', '{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Direction, threshold bands and the status colours exist since Story 3.15, but a band can only match the headline number, the card draws ▲ and ▼ where the product's arrows are ↗ and ↘, and nothing proves each band colour on its soft fill in CI.

**Approach:** Add the value-match band (`equals`, on the status text) beside the numeric bands with one top-to-bottom order, use the ↗ and ↘ arrows, and test every band colour pairing from the Renderer's own classes.

## Boundaries & Constraints

**Always:** Numeric bands compare exact decimals on the headline; a text band compares the status badge slot's text, ignoring case and surrounding space. A subject with no value matches none of its bands and renders neutral, never an error. A band keeps an icon and a label (colour is never the only signal) and never the accent colour. The arrow glyph is `aria-hidden`; the hidden text names direction, value, label and judgement.

**Never:** No change of the contract's slots; no float comparison.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Rises, higher is better | +12.5 | ↗ in the success colour | N/A |
| Falls, lower is better | -5% | ↘ in the success colour, "· better", hidden text names both | N/A |
| Text match | status "At risk", band equals "At risk" | the band's fill, icon, colour and word | N/A |
| Order | equals band above a ≥ band, both match | the first | N/A |
| No match or no value | none | neutral, no error | N/A |
| Boundary | ≥ 80 and 80 | the band matches | N/A |
| Text band without a word | equals, blank | refused, inline | 422 `config.presentation.bands.N.value` |

</frozen-after-approval>

## Code Map

- `app/Modules/Mapping/Contracts/Presentation.php` (`equals`), `Application/PresentationOptions.php` (`firstBand`), `block-types/kpi-card/v1/Renderer.vue` (arrows, band classes).
- `resources/js/lib/blockPresentation.ts`, `resources/js/components/wizard/BandsEditor.vue`, `resources/js/locales/labels.ts`, `resources/css/app.css`, `tests/js/contrast.test.ts`.

## Tasks & Acceptance

**Execution:**
- [x] `equals` band: validation, one-order evaluation on headline and status text
- [x] Condition and word in the bands editor
- [x] ↗ and ↘ arrows
- [x] Band colour contrast test from the Renderer's classes; the warning band's text class and `text-warning-text` fixed
- [x] Tests: Unit, vitest

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit and component tests.

## Implementation Notes

- The warning band used `text-warning-text`, a class with no token behind it, so its word drew in the inherited colour. The Renderer uses `text-warning` (4.52:1 on the soft fill) and the theme now maps `warning-text` to the same token for the other places that name it.
- The text band's subject is the Status badge slot; with no band matching, the slot's own text still shows as before.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour and renderer lint, block-type pins.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Warning band text had no colour class behind it | medium | fixed | The new contrast test reads the Renderer's classes and resolves each against the tokens. |
| Text bands live in the headline's band list | low | accept | One ordered list is the Story's model; the editor says the word matches the Status badge slot. |
