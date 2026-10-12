---
title: 'Preview on every device and state, with warning acknowledgements (Story 3.16, wizard step 4)'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '1b57a10732b823ca1e99df9eb1398391d8a2370a'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-14-preview-hotspots-and-source-legend.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-15b-presentation-rules-editor-ui.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-15a-presentation-rules-backend.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Step 4 of the Create block wizard does not exist: after Map Data the Admin cannot see the Block full size on Desktop, Tablet and Mobile, inside a sample dashboard grid, or in each state, and cannot review or acknowledge validation warnings.

**Approach:** Add Step 4 (Preview) to the wizard. It renders the Draft's payload from the existing preview endpoint through the production Shaper output and kpi-card Renderer, with device toggles, a 12-column/14px-gap sample grid at the configured size, a state switcher, a warnings list with Acknowledge checkboxes persisted in `draft_ui_state`, and a gated Continue.

## Boundaries & Constraints

**Always:**
- Same Renderer and payload path as production (no second renderer). States Normal, Loading, Empty, Stale, Unavailable, Minimized come from the Renderer's own state handling; Unavailable shows the catalogue `unavailable-user` message, never "$0".
- Desktop, Tablet, Mobile toggles; Mobile renders at mobile width with no horizontal page scroll and the Block's content scrolls inside the Block when it overflows. The Block also shows inside a sample dashboard grid (12 columns, 14px gap) at its configured size.
- A "Sample data" label whenever the data is a sample (fetched or pasted).
- Warnings (e.g. an optional low-confidence slot cleared, a null in the sample for an optional slot) are derived from the existing mapping checklist/preview data; each has an Acknowledge checkbox; Continue stays `aria-disabled` with the reason until every warning is resolved or acknowledged. Acknowledgements are stored in `draft_ui_state` (existing column and autosave path) and cleared when the underlying warning changes (key an acknowledgement by a fingerprint of the warning).
- Returning to Step 4 after a mapping change refreshes the preview and resets acknowledgements of changed warnings.
- Follow existing wizard conventions: lock claim, autosave, stepper, catalogue/labels files, design tokens, accessibility (labels, focus, live regions).

**Never:** Step 5 validation or publish (3.18, 3.19), preview-as-user (3.17), a new renderer, backend contract changes beyond what `draft_ui_state` already accepts.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected |
|----------|--------------|----------|
| Continue from Map Data | Step 3 complete | Step 4 opens with Desktop preview and grid |
| Mobile toggle | select Mobile | mobile width, no page horizontal scroll, content scrolls in Block |
| State switch | Unavailable | shows unavailable message, no "$0" |
| Warning present | cleared optional slot | listed with Acknowledge; Continue aria-disabled with reason |
| Acknowledge all | tick every box | Continue enabled; stored in draft_ui_state |
| Mapping changed | go back, change, return | preview updated; changed warning's acknowledgement reset |
| Pasted or fetched sample | either | "Sample data" label shown |

</frozen-after-approval>

## Code Map

- `resources/js/pages/admin/BlockWizard.vue`, `lib/blockWizard.ts` (steps, `draft_ui_state`), `components/wizard/{WizardStepper,LivePreview,MapDataStep}.vue`, `lib/blockPreview.ts`, `block-types/kpi-card/v1/Renderer.vue`; `app/Modules/Blocks/Application/ManageDrafts.php` for `ui_state` limits.
- Tests: `tests/js` beside existing wizard tests.

## Tasks & Acceptance

- [x] Step 4 component, device and state switchers, sample grid, Sample data label
- [x] Warnings derivation, acknowledgements in `draft_ui_state`, Continue gating
- [x] Wire into wizard and stepper; tests for every matrix row

**Acceptance Criteria:**
- Given Map Data is complete, when Step 4 opens, then the Block shows full size, in the 12-column grid, with device and state toggles and the Sample data label.
- Given unresolved warnings, when none is acknowledged, then Continue is aria-disabled with the reason; after acknowledging all it enables and the choice survives reload.

## Verification

- `bin/tools composer ci:check` -- expected: all green (PHP suites only if PHP changes).
