---
title: 'Live preview from the Query Plan engine with hotspots and the source legend (Story 3.14, UI stage 4)'
type: 'feature'
created: '2026-10-10'
status: 'done'
route: 'dispatch'
baseline_commit: '3e60ff782bb279baa8a2175c6917663a8f33acce'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-10-render-the-sample-through-the-query-plan-engine-live-preview.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-13c-harden-the-mapping-api-for-the-map-data-ui.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-13b-field-picker-ui.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The wizard's live preview is a static Loading card: nothing in the front end calls the preview endpoint (3.10), so mapping changes never show their result, and the preview cannot be used to change a slot. This is UI stage 4 (3.14).

**Approach:** Add a preview client that renders the Draft's sample through `POST .../preview`, feed the real payload to the preview on step 3, overlay hotspot buttons on the mapped regions that open the field picker (3.13b), add the missing-slot placeholder, the source legend with `msg:sample-note`, the preview region and skip link, and keep the Block chrome inert.

## Boundaries & Constraints

**Always:**
- Follow epics.md Story 3.14 ACs, the UX files under `_bmad-output/planning-artifacts/ux-designs/.../DESIGN.md` and `EXPERIENCE.md`, the stage 1 to 3 conventions (`lib/blockMapping.ts`, `MapDataStep.vue`, `SlotChecklist.vue`, `FieldPicker.vue`, wizard save chain and revision adoption, announcements) and the labels catalogue (all copy in `labels.ts`; do not add keys to the closed message catalogue `en.ts`).
- Preview API (built): `POST /api/v1/admin/blocks/drafts/{draft}/preview` with `X-Background: 1`, no body; response `{payload, unavailable, slots: {slot: {value, path, unavailable?}}, sample_revision, draft_revision, plan_stale}`; refusals 422 `mapping.no_plan`, `mapping.no_sample`, `mapping.guard_exceeded`, `mapping.setting_not_configured` (names the setting), 429 with `retry_after` (keep the last good preview and show a quiet notice), 503. Debounce (about 400 ms), cancel or discard stale responses (sequence counter), refresh after every mapping write (confirm, re-run, override, undo, treat as, role or record-path change) and when the sample revision changes; never block the UI; last good payload stays visible on failure with a polite notice.
- `LivePreview.vue`: on step 3 it renders the payload from the server (Renderer from `@block-types/kpi-card/v1`); on steps 1 and 2 it keeps the current Loading behaviour. The Draft's header, subtitle and display config still apply as today. Do NOT modify files under `block-types/` (they are pinned; `check:block-type-pins` and `block-types:check` must stay green): overlay hotspots from the wrapper using the Renderer's existing region markers or by positioning over its rendered regions; if the Renderer exposes no usable markers, report that gap in Implementation Notes and fall back to hotspot buttons laid out beside each region.
- Hotspots (3.14): each mapped region (headline, comparison, kpi label, secondary note, status badge that exists in the Renderer) gets a faint dashed 1 px outline (idle), a solid 1.5 px accent outline on accent wash on pointer hover only, and the standard focus ring on keyboard focus (never the accent outline as the focus indicator); tooltip "Click to change field · {Slot} ← {field}" on hover and focus; each is a button named "{Slot}: {field}, {rendered value}. Change field"; activation (click, Enter, Space) scrolls to the slot row, expands it, opens the field picker with focus in the search, and Esc returns focus to the hotspot. A required unmapped headline shows the hatched `missing-slot-placeholder` button "Headline value, required, not mapped. Choose field" with the text "Headline value required · No Value mapped · click to choose" opening the same picker. Slots without a region (data-as-of, status badge if the Renderer has none) are changed from the checklist only. Chrome (refresh, minimise, ⋯) is inert. Read-only (`blocked`): hotspots are not buttons (no picker); the legend still shows.
- Region and skip link: the preview region is named "Live preview, sample data" and preceded by a skip link "Skip to slot checklist" that moves focus to the checklist. Source legend "WHERE EACH PART COMES FROM" under the Block: rows of role chip, field (mono), "→" and the rendered result (e.g. "Value total → 284680 formatted as the card formats it") from `slots` and the checklist, then `msg:sample-note` (existing catalogue message). The "Sample data" label appears once.
- Unavailable slots render as the server says (never 0); a stale plan (`plan_stale`) shows a quiet notice; `mapping.setting_not_configured` shows an operator-facing line naming the setting; `mapping.no_plan` shows the empty preview with the placeholder.
- Lint, types, colour and renderer lint pass; keyboard operable; no colour-only signals; reduced-motion respected.

**Never:** Editing `block-types/`; the Query Plan engine or any backend (report a gap); presentation rules (3.15); device/state preview (3.16); a second preview endpoint.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Open step 3 | plan and sample exist | preview renders server payload; hotspots on mapped regions; legend rows | loading shows the existing Loading card |
| Mapping write | confirm / override / rerun | preview refreshes (debounced); last good stays until the new one arrives | failure keeps last good + notice |
| Hotspot | click / Enter / Space | scrolls to slot row, opens picker with focus in search; Esc returns focus to hotspot | N/A |
| Missing headline | no mapping | hatched placeholder button opens the picker | N/A |
| Throttled | 429 | last good preview, quiet notice with retry time | N/A |
| Guard / settings | 422 guard_exceeded / setting_not_configured | notice naming the cause; no partial render | N/A |
| Unavailable slot | field missing | Unavailable per the payload, never 0; legend says so | N/A |
| Stale plan | `plan_stale` | quiet notice; preview still shown | N/A |
| Read-only | blocked | regions not interactive; legend shown | N/A |
| Skip link | keyboard | focus moves to the checklist | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/components/wizard/LivePreview.vue` (static Loading payload today, device switch, size pill, version-safe), `resources/js/pages/admin/BlockWizard.vue` (renders LivePreview around line 1639, step state, revision and save chain), `MapDataStep.vue`, `SlotChecklist.vue`, `SlotRow.vue`, `FieldPicker.vue`, `lib/blockMapping.ts`, `lib/blockWizard.ts`, `locales/labels.ts`.
- `block-types/kpi-card/v1/{Renderer.vue,payload.ts,display.ts}` -- read only; `resources/js/lib/announce.ts`.
- Backend shape: `app/Http/Controllers/Admin/DraftPreviewController.php`, `app/Modules/Blocks/Application/PreviewDraft.php`, `app/Modules/Mapping/Contracts/PlanResult.php`.
- Tests: `tests/js/block-wizard-map-*.test.ts` (fetch mocks), `tests/js` live preview tests; new `tests/js/block-wizard-map-preview.test.ts`.

## Tasks & Acceptance

**Execution:**
- [ ] `lib/blockPreview.ts` -- typed client, debounce and sequence helpers, error mapping
- [ ] `LivePreview.vue`, `BlockWizard.vue` -- server payload on step 3, notices, skip link, region name
- [ ] `components/wizard/PreviewHotspots.vue` (+ `SourceLegend.vue`) -- hotspots, placeholder, legend, tooltips, inert chrome
- [ ] `MapDataStep.vue`, `SlotChecklist.vue`, `SlotRow.vue` -- open and scroll a slot row's picker from a hotspot, return focus
- [ ] `labels.ts` -- all new copy
- [ ] `tests/js/block-wizard-map-preview.test.ts` -- every matrix row

**Acceptance Criteria:**
- Given a mapped headline, when the Admin overrides it, then the preview shows the new value and the hotspot name and legend row show the new field and rendered result.
- Given the Admin activates a hotspot with the keyboard, when the picker opens and Esc is pressed, then focus returns to that hotspot.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| No refetch on `draftId` change; throttled preview never retries; stale problem lingers | medium | patch | Watcher, one retry after `retry_after`, problem cleared per request; tested. |
| `no_sample`/guard/setting keep the old card beside a contradicting notice; endless Loading | medium | patch | Result cleared, Unavailable state; tested. |
| 401/419 shown as a transient failure; malformed body unvalidated | medium | patch | `auth-error` emitted; body shape checked; tested. |
| Missing-slot placeholder only after `no_plan`; hotspot name can be empty or pair new field with old value | medium | patch | Placeholder whenever headline is unmapped; names fall back to the server value; stale hotspots non-interactive; tested. |
| Tooltip clipped and not dismissible; scroll region not focusable; read-only label unused; blocked mismatch | medium | patch | Fixed; tested. |
| Hotspot activation silently does nothing; skip link target missing; picker return focus overwritten | medium | patch | Fixed (announce, `#slot-checklist`, `returnTo` guard). |
| X-Background header, sample-revision refresh, unmount cancel, geometry untested | medium | patch | Tests added. |
| Icon dropped from the payload; split localisation; id sanitising | low | defer | Polish. |
| No test for the `cannotChange` announcement or the `returnTo` guard | low | defer | Small gaps. |
| Hotspot geometry and tooltip clipping never seen in a real browser | medium | defer | happy-dom reports zero-size rects; needs a manual browser check. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green (retry the audit step alone if Packagist times out; run the Database suite with pest directly if composer's 300s timeout hits)
