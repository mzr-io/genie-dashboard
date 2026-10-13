---
title: '5-17 Add a Block Type with only a package and versioned contracts'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '6ed0034'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-3-1-register-the-block-type-contract-v1-and-the-kpi-card-package.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Adding a Block Type meant editing a list of Shapers, an autoload map, a Renderer table, the wizard's list, a generated TypeScript file and the preview's per-type maps (Story 5.17; FR-32, FR-33, NFR-12, AR-28, AD-21, AD-25).

**Approach:** A package is found by its place. The Shaper is read from `block-types/{key}/v{N}/Shaper.php`, the Renderer and the table from the package's own files by glob, the Block Type from the `block-type.generated.ts` that the generator writes inside the package, so a new package changes no other file. The shared vocabulary stays one generated file; the PHP value object is generated only for the Block Type the core names (the KPI card).

## Boundaries & Constraints

**Always:** `schema.json` is the single source. A pinned contract directory is never deleted (the pin guard). The pinned shaper contract version is in `compute_ctx`. Every first-party package declares Title, Subtitle, Icon and Footer action, a value type and a cardinality for every slot, and a default aggregation for each numeric per-row chart slot. A Renderer draws every state, itself or through the shared frame. A package imports the shared kit only and makes no request; its Shaper imports nothing of the application.

**Never:** No list of packages in the core. No Shaper named by a path that is not a kebab-case key and a version.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| New package | schema, Shaper, Renderer | found, shaped, generated inside the package | N/A |
| Unknown or unsafe key | `ghost`, `../app` | `UnknownBlockType` | refused |
| Renderer lacks `error` | package lint | fails naming the state | CI |
| Package imports core | `@/lib/blockWizard` | fails naming the file and line | CI |
| Contract dir missing | pinned `(key, v)` | pin guard fails | CI |

</frozen-after-approval>

## Code Map

- `app/Modules/BlockTypes/Infrastructure/PackageShapers.php` (discovery), `app/Support/BlockTypes/BlockTypeGenerator.php` (per-package output, core-only PHP), `resources/js/types/{block-type-shape.ts,block-types.ts}`, `resources/js/lib/{blockRenderers,blockWizard}.ts`, `components/wizard/LivePreview.vue` (generic fallback).
- `scripts/lint-renderers.mjs` (states, isolation), `block-types/*/v1/{schema.json,block-type.generated.ts}`.
- `tests/Unit/BlockTypeExtensibilityTest.php`, `tests/Unit/BlockTypeRegistryTest.php`, `tests/js/block-type-guards.test.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Shapers found by place; unsafe keys refused
- [x] Generator writes each package's TypeScript inside the package; vocabulary file; PHP only for the core type
- [x] Renderers, tables and the wizard list by glob; the preview falls back for a type it has no hotspots for
- [x] Lint: every state, package isolation; conformance and extensibility tests
- [x] Default aggregations on numeric chart rows; Subtitle on the text/status Block

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit and component tests.

## Verification

Pest Unit, Feature, Architecture and Database suites, vitest, `vp check`, `vue-tsc`, `vp build`, colour and renderer lints, pin and generator checks, PHPStan and Pint.

## Implementation Notes

- A new package still needs its `block-type.generated.ts`, which `php artisan dashflow:block-types` writes beside it: the CI check names it when missing.
- Per-type hotspots and a thumbnail of a package the preview does not know are a whole-card region and the generic thumbnail; a package may be listed in the wizard and drawn without further code.
- The upgrade of a Block pinned to `v1` to a `v2` contract creates a Draft: it rests on editing a published Block into a Draft (Story 6.1) and is logged in `deferred-work.md`.
