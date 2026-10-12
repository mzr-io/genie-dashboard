---
title: 'Register the Block Type contract v1 and the kpi-card package'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: '51e6051ba26a65e4da44327302778a35f6958b86'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Server, browser and wizard have no shared definition of a Block Type's slots and limits, so later Epic 3 stories have nothing to build on.

**Approach:** Add a versioned package `block-types/kpi-card/v1/` (schema.json, pure Shaper.php, Renderer.vue stub), a code-defined `BlockTypes` module registry that resolves pinned `(key, contractVersion)` pairs, `GET /api/v1/block-types` for Admins with `blocks.edit`, generated TS type and PHP value object from schema.json, and guards (drift, pinned-dir, Shaper purity, Renderer lint).

## Boundaries & Constraints

**Always:** Module is `app/Modules/BlockTypes` (already in dependencies.php; no tables). The registry interface and generated value object live in its `Contracts`. schema.json is the single source: slots Title, Subtitle, Icon, Footer action, KPI label*, Headline value*, comparison value or change %, comparison label, secondary note, status badge; value types number|text|datetime|boolean; cardinality `single`; accepted Field Roles; size limits; default width 3 columns, default height Small; default aggregations. Registry validates all packages at boot and registers none if any is invalid, naming package and slot. Only `kpi-card` is listed. The endpoint reuses `admin:blocks.edit` middleware so the User-area 403 and `access.admin.denied` audit come for free. Shapers are pure: no I/O, no clock, no `json_decode`. Checks run through `bin/tools composer ci:check`.

**Never:** No "latest" resolution. No workflow files (no CI service exists). No other ten Block Type keys. No Shaper logic or Renderer states beyond a minimal stub (Story 3.2). No DB tables. Do not add BlockTypes to the JsonDecodeBan module list (its test asserts exact four).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| List | Admin with `blocks.edit` GET /api/v1/block-types | 200 `data` lists `kpi-card`, contract version 1, slots, size limits, default aggregations | N/A |
| Wrong area | User-area session | 403 `access.not_authorized` | audited by middleware |
| Resolve pinned | `('kpi-card', 1)` | package returned | unknown pair throws, never falls back |
| Bad slot | unknown value type or missing cardinality | boot fails, message names package and slot | nothing registered |
| Drift | generated TS/PHP differs from schema.json | check fails "stale" | non-zero exit |
| Pinned dir removed/renamed | pinned `(key, v)` directory missing | check fails naming it | non-zero exit |
| Shaper impurity | Shaper uses I/O or `json_decode` | architecture test fails | N/A |
| Renderer unsafe | `v-html`, `innerHTML`, formatter string | lint fails | non-zero exit |

</frozen-after-approval>

## Code Map

- `app/Modules/BlockTypes/{Contracts,Application,Infrastructure}` -- new module: `BlockTypeRegistry` contract, loader/validator, generated `KpiCardSlots` VO
- `app/Providers/AppServiceProvider.php` -- bind registry; boot-time validation
- `routes/api.php`, `app/Http/Controllers/Admin/DataSourceHealthController.php` -- route and controller pattern (invokable, no-store JSON)
- `app/Http/Navigation/ShellNavigation.php` -- ADMIN_API_ROUTES permission mapping
- `app/Console/Commands/ErrorCodesCommand.php`, `app/Support/ErrorCodes/ErrorCodeGenerator.php` -- model for `dashflow:block-types {--check}` codegen
- `tests/Architecture/{dependencies.php,Support/Scanner.php,JsonDecodeBanTest.php,Fixtures}` -- model for ShaperPurityTest; Scanner only sees app/, so add a block-types scan
- `scripts/lint-colors.mjs`, `tests/js/gates.test.ts` -- model for renderer lint and pin check scripts
- `composer.json` (autoload, ci:check), `package.json`, `tsconfig.json`, `vite.config.ts`, `phpstan.neon` -- autoload Shaper, add `@block-types` alias, analyse `block-types/`

## Tasks & Acceptance

**Execution:**
- [ ] `block-types/kpi-card/v1/{schema.json,Shaper.php,Renderer.vue}` -- package; Shaper a pure stub, Renderer a safe minimal stub
- [ ] `app/Modules/BlockTypes/**` + AppServiceProvider -- registry, validation, pinned resolution, generated VO
- [ ] `app/Console/Commands/BlockTypesCommand.php` + generator -- emit PHP VO and `resources/js/types/block-types.ts`, `--check`
- [ ] `routes/api.php`, `BlockTypeController`, ShellNavigation -- endpoint
- [ ] `block-types/pins.json` + `scripts/check-block-type-pins.mjs` -- pinned-dir guard
- [ ] `scripts/lint-renderers.mjs` + package.json -- Renderer lint
- [ ] `tests/Architecture/ShaperPurityTest.php` (+fixtures, Pest.php) -- Shaper purity
- [ ] `composer.json`, `tsconfig.json`, `vite.config.ts`, `phpstan.neon` -- wiring and ci:check steps
- [ ] Tests: Pest unit (registry, boot failure, drift), Feature (endpoint 200/403), Database (audited denial), Vitest (scripts)

**Acceptance Criteria:**
- Given the registry, when an Admin with `blocks.edit` lists block types, then only `kpi-card` v1 appears with slots, limits, aggregations.
- Given a User-area session, when listing, then 403 and an audit row.
- Given an invalid package, when the registry boots, then it fails readably and registers nothing.
- Given drift, a removed pinned dir, an impure Shaper or an unsafe Renderer, when checks run, then each fails.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route / evidence |
|---|---|---|
| Boot validation bricks every artisan command incl. `dashflow:block-types` | medium | patch: skip eager boot validation while that command runs |
| Registry glob `*/v*` matches non-version dirs (`views/`) | low | patch: filter to `v[1-9][0-9]*` |
| Key format unvalidated, generator emits it raw | medium | patch: kebab-case key regex in loader |
| Generator `discover()` unvalidated; TypeError escapes command | medium | patch: command validates via loader first |
| Command ignores `file_put_contents` failure / missing PHP dir | low | patch (direct correction) |
| `pins.json` malformed gives stack trace | low | patch |
| Renderer lint scans only `Renderer.vue`; vacuous pass if dir missing; `Function(` w/o new | medium | patch |
| ShaperScanner misses include/require/eval/variable calls | medium | patch |
| Boot guard, `--check` command, lint eval/outerHTML rules, missing `pins.json` untested (verification-gap) | medium | patch: add tests |
| Shaper autoload/namespace unverified | low | defer to 3.2 (Shaper is a stub) |
| Orphan generated files undetected by `--check` | low | reject: needs new guard, unlikely |
| Role/valueType compatibility, max width bound, caching/ETag, README, a11y stub | low | reject: spec silent, no demonstrated harm |
| Empty-slots TS union `never` | false | loader requires required slots, so unreachable |
| Failed-load not cached; scanner line-number brittle; Pest.php untouched | false | no bad outcome shown |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green
