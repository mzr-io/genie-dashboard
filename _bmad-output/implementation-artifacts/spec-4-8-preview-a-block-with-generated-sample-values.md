---
title: '4-8 Preview a Block with generated sample values'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '8e7a9f0'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A member choosing a Block cannot see what it looks like without adding it, and adding it would wake a data source.

**Approach:** Generate the preview server-side from the published version's field snapshot and presentation alone: a synthetic document with one deterministic value per extracted path goes through the real Query Plan engine and Shaper, and the panel draws it with the real Renderer in an inline accordion, inert, with `msg:sample-drawer` under it.

## Boundaries & Constraints

**Always:** No source, payload or result is read and nothing is stored, subscribed or fetched. The same version always gives the same values. A sample that cannot be made is a labelled placeholder, never 0. The preview is inert. One preview is open at a time.

**Never:** No real data, no add (4.9), no per-user values.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Open | row body or disclosure | accordion "Preview of {Block} with sample data", renderer, `msg:sample-drawer` | N/A |
| Twice | same version | identical values | N/A |
| No sample | engine refuses / missing setting | labelled placeholder | 404 from the API |
| Not visible | hidden or unpublished | 404 | nothing disclosed |
| Browsing | any number of previews | no target, no subscription, no source call | N/A |
| Esc | preview open | collapses; second Esc closes the drawer | N/A |
| + Add | in the preview | `aria-disabled`, "Adding is not enabled yet" | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Blocks/{Contracts,Application}/BlockSamples.php`, `GenerateBlockSample.php`; `app/Http/Controllers/BlockCatalogueController.php` (`sample`); `routes/api.php`
- `resources/js/components/AddBlocksPanel.vue`, `lib/blockCatalogue.ts` (`fetchSample`)

## Tasks & Acceptance

**Execution:**
- [x] Sample generator over the frozen plan and snapshot, run through the real engine
- [x] `GET /api/v1/blocks/{block}/sample`, access-checked
- [x] Accordion preview with renderer, note, placeholder, Esc, Close preview
- [x] Tests: Database (determinism, per-version, no source or subscription, 404s), vitest (open, one at a time, placeholder, Esc)

**Acceptance Criteria:**
- Given the I/O matrix, when previews are opened, then each row holds against PostgreSQL (with Ingestion and the egress client made to fail) and in the component tests.

## Implementation Notes

- Values are `100.00` to `9999.99` for numbers, a fixed 2026 date for dates, `Sample {alias}` for text and a seeded boolean: the engine and Shaper apply the version's own formats, so a currency or percent Block previews as one.
- The preview needs the engine's `preview.*` settings (`pending_input`); while any is unset the sample is a 404 and the panel shows the placeholder.
- The generator lives in Blocks (it owns the snapshot and presentation) and calls Mapping's `QueryPlans` contract, as the wizard preview does.

## Verification

- SQLite suite, Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check` and the colour and renderer lints pass.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| The sample is a 404 while preview settings are unset | low | defer | Same `pending_input` rule as the wizard preview; the panel degrades to its placeholder. |
| Values do not follow any real distribution | false | none | They are labelled sample data and only need to be stable and plausible. |
