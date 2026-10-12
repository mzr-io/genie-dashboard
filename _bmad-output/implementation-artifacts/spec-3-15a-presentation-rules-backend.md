---
title: 'Presentation rules for the KPI card: config, engine bands and package (Story 3.15a, backend core)'
type: 'feature'
created: '2026-10-10'
status: 'done'
route: 'dispatch'
baseline_commit: '9783cf863beca0904112f3a67b5cd96f5ea3f8de'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-10-render-the-sample-through-the-query-plan-engine-live-preview.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-13c-harden-the-mapping-api-for-the-map-data-ui.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Story 3.15 needs per-slot presentation rules (format, emphasis, direction, threshold bands, labels, empty display) that the preview and the published block render. The kpi-card package supports only format descriptors and `lowerIsBetter`; nothing stores presentation in the Draft, validates it, evaluates bands, or renders emphasis, prefix and suffix, band badges or an empty display. This is 3.15a (backend and package); the editor UI is 3.15b.

**Approach:** Store `config.presentation` in the Draft (server-owned, written through a mapping-scoped endpoint), validate it, have the Query Plan engine evaluate threshold bands on exact decimal strings, pass the result to the Shaper as options, and extend the kpi-card v1 package (Shaper, payload, Renderer, schema) to render it.

## Boundaries & Constraints

**Always:**
- Decision recorded: amend kpi-card **v1 in place**. No block version can be published before Story 3.19 (`block_versions.state` allows only draft and discarded), so no published v1 exists to stay compatible with; after 3.19 this would need a v2. Update the package pin the repo's way (see `check:block-type-pins` and `dashflow:block-types --check`, regenerate pins with the documented command) and keep Shaper purity (no I/O, clock, `json_decode`, `eval`, floats) and the existing payload fields unchanged for existing options.
- Presentation (`config.presentation`, strict validated shape): `slots` map of slot key to `{format: FormatDescriptor, emphasis: {role: headline|primary|secondary|badge, size: sm|md|lg, weight: regular|medium|bold}, direction: higher_is_better|lower_is_better (comparison only), label: {text, prefix, suffix}}`, `bands` (array, first match wins top to bottom; each `{op: '>='|'<='|'between', value: decimal string, to: decimal string (between), color: success|warning|error|info|neutral, icon: icon key, label: non-empty text}`; a band without icon or label is refused; the accent colour is refused), `empty_display` (short text, default none). Format kinds are the existing ones (number, integer via 0 places, decimals, percent, currency with code and symbol or code display, compact, date with pattern, relative-time, duration, units suffix); invalid values (negative places, places over 20, unknown currency code not matching ISO shape or unsupported by `Intl`-compatible ISO list you validate against, unknown kinds, text over limits) are refused with `config.presentation.<path>` errors under 422 `blocks.invalid_config`; stored values hold NO locale-formatted strings (descriptors only).
- One headline per Block: at most one slot with emphasis role `headline`; a second is refused with the reason (`config.presentation.slots.<slot>.emphasis`).
- Server ownership like the other mapping keys: generic Draft save refuses `presentation` ("set through the mapping endpoints"), kept when omitted; add `PUT /api/v1/admin/blocks/drafts/{draft}/mapping/presentation` {presentation, draft_revision} through `ManageDraftSlots::write()` (lock claim, revision rules, no-op skip, adopts like other writes) returning the validated presentation, `draft_revision`, `query_plan`; `GET .../mapping` returns `presentation`. Read-only viewers can read; writes need the lock. `AdminAccessTest` and `ShellNavigation::ADMIN_API_ROUTES` updated.
- Engine: `QueryPlanEngine` evaluates bands against the headline value with exact decimal comparison (BcMath via the existing `DecimalMath` or `BcMath\Number`, never float; decimal-string and lexeme forms like `1E3` compare by value); a null or unavailable headline matches no band; the first matching band becomes the Shaper's `statusBand` option. The preview endpoint passes the Draft's presentation to the Shaper (`headlineFormat`, `comparisonFormat`, `lowerIsBetter` from `direction`, emphasis, prefix and suffix, `emptyDisplay`, `statusBand`) and returns them in the payload. A null **headline** shows Unavailable, never the empty display or 0; a null non-headline slot shows the empty display when configured.
- Package: Shaper maps options to payload (`headline.emphasis`, `prefix`, `suffix`, `statusBadge` from a band as `{text, color, icon}` plus any text status from the status_badge slot taking precedence only when no band matched, `emptyDisplay`); `payload.ts`, `Renderer.vue` and `schema.json` updated; Renderer shows band badge with its icon AND label (colour never the only signal), emphasis size/weight, prefix/suffix, empty display; existing Renderer/Shaper tests stay green and new ones cover the additions. The Renderer formats with `Intl` through the existing descriptor path and the Workspace/browser locale.
- Respect `tests/Architecture` (ShaperPurityTest, json_decode ban), no sample text in errors, no new catalogue keys.

**Never:** The editor UI (3.15b); a v2 contract; accent colour for bands; locale-formatted strings stored; aggregation (Epic 5).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Currency format | headline 284680, USD symbol | payload descriptor currency; Renderer shows $284,680 | N/A |
| Invalid format | decimalPlaces -1, currency `XX1` | write refused with the path | 422 `blocks.invalid_config` |
| Two headlines | two slots with emphasis headline | second refused with reason | 422 |
| Band match | headline 120, bands [>=100 success, >=50 warning] | statusBadge success with label and icon | N/A |
| Band order | headline 120, bands [>=50 warning, >=100 success] | first match wins: warning | N/A |
| Between / lexeme | headline `1E2`, between 50 and 150 | matches by value | N/A |
| Missing icon or label | band without either | refused | 422 |
| Accent colour | color accent | refused | 422 |
| Null headline | headline null | Unavailable; no band; no empty display | N/A |
| Null secondary | secondary note null, empty_display "—" | shows "—" | N/A |
| Direction | lower_is_better | lowerIsBetter true in payload | N/A |
| Generic save | config carries presentation | refused naming the endpoint | 422 |
| Stale revision / lost lock | PUT with old revision | nothing written | 409 / 423 |

</frozen-after-approval>

## Code Map

- `block-types/kpi-card/v1/{Shaper.php,payload.ts,Renderer.vue,display.ts,schema.json}` and their tests (`tests/` for block types, `tests/Architecture/ShaperPurityTest.php`), the pin check scripts (`npm run check:block-type-pins`, `composer block-types:check`), `app/Modules/BlockTypes/**`.
- `app/Modules/Mapping/Application/{QueryPlanEngine,DecimalMath}.php`, `Contracts/{PlanResult,ExecutionResult}.php`, `app/Modules/Blocks/Application/{PreviewDraft,ManageDraftSlots,DraftConfig}.php`, `app/Http/Controllers/Admin/{DraftSlotsController,DraftPreviewController}.php`, `Requests/Admin/DraftSlotRequest.php`, `routes/api.php`, `ShellNavigation.php`, `bootstrap/app.php` (empty-string handling for mapping PUTs).
- Tests: `tests/Unit/QueryPlanEngineTest.php`, `tests/Database/{DraftSlotsTest,DraftPreviewTest,AdminAccessTest}.php`, `tests/js` Renderer tests.

## Tasks & Acceptance

**Execution:**
- [x] `block-types/kpi-card/v1/**` + pins -- Shaper, payload, Renderer, schema extensions and tests
- [x] `app/Modules/Mapping/**` -- band evaluation in the engine, presentation validation type
- [x] `DraftConfig.php`, `ManageDraftSlots.php`, controller, request, routes, `ShellNavigation.php` -- `presentation` key, write endpoint, read
- [x] `PreviewDraft.php` -- pass presentation to the Shaper
- [x] `tests/**` -- every matrix row; Renderer tests; `AdminAccessTest`

**Acceptance Criteria:**
- Given a saved presentation with bands and a currency format, when the preview is requested, then the payload carries the format descriptor, emphasis, the matching band as a badge with icon and label, and the Renderer shows them with `Intl` formatting.
- Given an invalid presentation, when saved, then nothing is stored and the refusal names each invalid path.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route |
|---|---|---|
| Partial emphasis forced size md / weight bold | medium | patch (omitted parts, Renderer keeps defaults) |
| Integer headline matched no band | medium | patch |
| `between` limits not pinned at boundaries | medium | patch (tests added) |
| Unconsumed per-slot settings; schema.json vocabulary duplicated; icon not checked against icon set; emptySlots broader than Renderer; huge exponent; clear needs sample | low/unverified | defer |
| Whitespace, key echo, hard-coded path in message, Renderer test gaps | low | rejected |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green (retry the audit step alone if Packagist times out; run the Database suite with pest directly if composer's 300s timeout hits)
