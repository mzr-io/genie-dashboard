---
title: 'Enforce architecture and quality gates in CI'
type: 'feature'
created: '2026-10-06'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'ff0b129a76123c82fed11ad4514aa712be8bb0dc'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-1-initialise-the-project-from-the-laravel-vue-starter-kit.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Nothing yet stops a change from breaking module boundaries, tenant isolation, exact-number handling or design-token rules (Story 1.2; AR-3, AR-42, AR-54, NFR-4, UX-DR-15, UX-DR-285).

**Approach:** One local command (`composer ci:check`) runs Pint, Larastan, Pest (unit, feature, architecture), Vitest, a colour lint and dependency audits. No CI service file is added in this story. Architecture rules are Pest tests with fixtures proving each rule fails when broken.

**Decisions (product owner, 2026-10-06):** CI/CD is skipped for now, so no `.github` or `.gitlab-ci.yml` file is created and nothing blocks merges automatically until a later story adds one (the platform question, GitHub versus the GitLab named in AR-51, stays open). `personal_access_tokens` is added to the allowed global tables. The Playwright cross-browser and axe accessibility gates (NFR-8, NFR-9, UX-DR-264) are deferred to a later story.

## Boundaries & Constraints

**Always:** Every gate exits non-zero on failure and names the offending file. Allowed module edges live only in `tests/Architecture/dependencies.php`, taken from the architecture spine's module graph; the kernel `app/Platform/*` may be called by modules and calls none. Rules are no-ops while `app/Modules` is empty, and each has a fixture test proving it fails. Global tables without `workspace_id` are exactly `users`, `sessions`, `password_reset_tokens`, `invitations`, `service_health_samples`, `operator_audit`, `personal_access_tokens` and the framework job and cache tables. `json_decode` is banned in Ingestion, RawStore, Mapping and Results. Raw hex and `rgba()` colours fail outside the token file; `#00D987`, `#FF004A`, `#FFDD1D`, `#CA8A04` fail everywhere. Every gate runs locally through `bin/tools`. Audits deny AGPL, SSPL and BSL licences and fail on high-severity advisories.

**Never:** Any CI/CD file, Playwright, axe, Compose, SBOM, signing or Helm. No product behaviour or UI changes. Do not weaken or delete Story 1.1 tests.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Clean change | Current tree | Every stage passes | N/A |
| Forbidden edge | Fixture class in module A uses module B not in the graph | Architecture test fails | Names file and edge |
| Kernel calls module | Fixture kernel class imports a module class | Fails | Names file |
| Cross-module table | Fixture module runs `DB::table()` on another module's table | Fails | Names file |
| Tenant table | Migration creates a non-global table without `workspace_id` | Guard fails | Names migration and table |
| Global table | Migration creates `users` or another listed table | Guard passes | N/A |
| `json_decode` | Fixture in Ingestion calls it | Fails | Names file |
| Raw colour | Vue or CSS uses `#fff` or `rgba(` outside tokens | Colour lint fails | Names file and line |
| Banned colour | `#CA8A04` anywhere under `resources` | Fails | Names file and line |

</frozen-after-approval>

## Code Map

- `phpstan.neon` -- Larastan level 7 over `app`, `bootstrap/app.php`, `config`, `database`, `routes`; `pint.json` -- Laravel preset
- `composer.json` scripts `ci:check` and `test` -- extend, do not replace; `phpunit.xml` -- one `Feature` suite; `tests/Pest.php` -- `TestCase` for `Feature`
- `package.json` scripts `check`, `types:check`; `vp test` runs Vitest; `vite.config.ts` lint and format ignores
- `database/migrations/*` -- kit tables plus `personal_access_tokens` (2026_10_06_081332)
- `resources/js/pages/Welcome.vue` -- the only file with raw colours (25 hits); allowlist until Stories 1.6 and 1.16
- `bin/tools`, `docker/tools/Dockerfile` -- the tools image every gate runs in

## Tasks & Acceptance

**Execution:**
- [x] `tests/Architecture/dependencies.php`, `ModuleBoundariesTest.php`, `TableOwnershipTest.php`, `JsonDecodeBanTest.php`, `Fixtures/` -- edges, kernel rule, table ownership, ban
- [x] `tests/Architecture/MigrationGuardTest.php` -- global-table list and `workspace_id` rule
- [x] `scripts/lint-colors.mjs`, `package.json` -- colour lint, banned values, `Welcome.vue` allowlisted with a removal note
- [x] Vitest config and one sample test -- runs under `vp test`
- [x] `composer.json`, `package.json`, `scripts/license-audit.mjs` -- `composer audit`, `npm audit --audit-level=high`, licence deny-list; extend `ci:check`
- [x] `phpunit.xml`, `README.md` -- `Architecture` suite; document each gate with `bin/tools`

**Acceptance Criteria:**
- Given each matrix fixture, when the suites run, then the expected rule fails and names the file, and the clean tree passes everything.
- Given `composer ci:check`, when run locally through `bin/tools`, then it runs Pint, Larastan, Pest, Vitest, the colour lint and the audits, and any failing gate makes it exit non-zero.

## Implementation Notes

- No CI file was created (scope change by the product owner mid-run); `.github` and `.gitlab-ci.yml` do not exist. The CI workflow is in `deferred-work.md`.
- `tests/Architecture/dependencies.php` holds the module edges (matching the spine's graph), kernel, table ownership, global tables and the `json_decode` ban list; `Support/Scanner.php` does the scanning and every violation names `file:line`. Fixtures are `*.php.stub` so Composer, Pint and PHPStan ignore them.
- Extra rule beyond the spec: an allowed edge must go through the other module's `Contracts` namespace.
- `resources/js/app.ts` also holds one raw colour (`#4B5563`, progress bar) that the investigation missed; it is allowlisted next to `Welcome.vue` with a removal note (Story 1.6). `resources/css/tokens.css` does not exist yet; Story 1.6 creates it at that path.
- Larastan needed `--memory-limit=1G`; `config/sanctum.php` got a `(string)` cast for `env()`.
- An npm audit found a critical advisory in `tinypool` (via `vite-plus` 0.3.0). Bumping `vite-plus` broke `vp build`, so `package.json` overrides `tinypool` to `^2.1.2` instead.
- `composer audit` and `npm audit` need network access; an offline run fails those stages by design.
- Verified by the reviewer: full `bin/tools composer ci:check` passes (59 Pest tests with 17 architecture tests, 13 Vitest tests, colour lint, three audits). Planted violations on the real tree (cross-edge, cross-table, `json_decode`, kernel call, tenant table, raw and banned colours) were each caught and named; the tree was restored.
- Review patches applied: boundary scanner now rejects group imports, collapses doubled backslashes and requires an exact `Contracts` segment; SPDX `OR` licences are denied only when every branch is denied; added fixtures and tests for join, raw-SQL, migration segment boundary, raw `create table`, graph cycles, duplicate table owners, `\json_decode`, `lintTree` and the licence audit. Full `ci:check` after patches: 70 Pest tests, 20 Vitest tests, all gates green.
- Action for review: add `personal_access_tokens` to the global-table list in the architecture spine (AD-3).

## Spec Change Log

## Review Triage Log

| ID | Verdict | Route | Evidence |
|----|---------|-------|----------|
| BH-1 `ci:check` omits Pint and Larastan | false | none | The `test` script runs `@lint:check` and `@types:check`; the reviewer's own `ci:check` run showed Pint PASS and Larastan OK |
| BH-2 nothing runs `ci:check` in CI | low | rejected | Product owner excluded CI/CD for now; README gets one clarifying sentence (patch below) |
| BH-3 / EC-16 dual-licence wrongly denied | medium | patch | Verified: `isDenied('(MIT OR AGPL-3.0-only)')` returns true, so a package usable under MIT fails the audit |
| BH-4 / EC-17 licence audit misses UNLICENSED, missing licence, nested `node_modules` | low | defer | Real gap, no current impact (audit passes today); needs a policy for unlicensed packages |
| BH-5 `(composer.lock)` wording wrong | low | patch | `composer licenses` reads `vendor/`; wording-only fix |
| BH-5b missing composer binary stack trace, "node_modules missing" counted as denied, null licence, malformed package.json (EC-18) | low | rejected | Unlikely in everyday use; fix adds guards |
| BH-6 colour lint misses `rgb()`, `hsl()`, `oklch()`, named colours, `.svg`, `public/` | low | rejected | UX-DR-285 and the spec name raw hex and `rgba()`; widening is a policy change |
| BH-7 / EC-13 hex lint flags `#bad`, `#face` style anchors | low | rejected | Unlikely in everyday use; a context-aware fix adds complexity |
| BH-8 / EC-1 group imports and doubled-backslash class names evade the boundary rule | medium | patch | The scanner regex needs a single backslash after `Modules`; `use App\Modules\{...}` is legal PHP and invisible to it |
| BH-8b / EC-2 `ContractsInternal` accepted as `Contracts` | low | patch | `str_starts_with($namespace, 'Contracts')` accepts any prefix match |
| BH-8c / EC-4 code under `app/` outside Modules and Platform (controllers, providers) is not scanned | medium | defer | No module controllers exist yet; add the rule with the first module in Story 1.12 |
| BH-9 / EC-5 table ownership only fixtured for `DB::table` | medium | patch | `->join`, `->from` and raw SQL branches have no fixture; deleting them keeps tests green |
| BH-9b / EC-6 Eloquent `$table`, variable names, unlisted tables | low | defer | Cross-module model use is caught by the boundary rule; revisit with the first module models |
| BH-10 / EC-12 graph tests catch only 2-cycles and not duplicate table owners | low | patch | Tests only; the current graph is acyclic (verified) but future edits are unguarded |
| BH-11 / EC-8 / EC-9 / EC-10 migration guard edge cases (substring `workspace_id`, later `Schema::table`, schema-qualified raw SQL, variable table names) | low | rejected | Unlikely in everyday use; fixes add guards |
| VG-1 migration guard segment boundary and raw-SQL branch untested | medium | patch | Verified: no fixture has a bad table before a good one, none uses raw SQL |
| BH-12 / EC-7 `json_decode` ban bypassed by `->json()` and the Http client | medium | defer | Real, lossy decode through another door; extend the ban when the Ingestion and Mapping modules exist |
| BH-14 / VG-4 colour lint walker, SKIP list, extensions and exit code untested | medium | patch | Verified: no test imports `lintTree` or checks the exit code |
| VG-3 licence audit tests miss `BSL-1.1`, SPDX expressions, `npmFailures` | medium | patch | Verified `isDenied('BSL-1.1')` is true but untested |
| VG-5 `json_decode` ban fixtured only for Ingestion; no `\json_decode` case | low | patch | Ban list can lose a module with no test failing |
| BH-13 README gaps, BH-15 unexplained overrides, BH-16 brittle tests | low | rejected | Overrides are explained in Implementation Notes; hard-coded lists intentionally lock the contract |
| EC-3 backslash path separators on Windows | low | rejected | Tooling runs on Linux in Docker |
| EC-11 `workspaces` tenant root would fail the migration guard | low | defer | Story 1.10 treats `workspaces` as global; add it to the list then |
| EC-14 `resources/` missing makes the lint throw | low | rejected | Directory always exists in this project |
| EC-15 SKIP directories exempt banned colours | false | none | The skipped folders hold generated, gitignored Wayfinder code |
| VG-other real-tree tests pass vacuously while `app/Modules` is empty | false | none | By design (spec: no-ops while empty); the fixtures prove each rule fails |

Review inputs: three layers (blind, edge-case, verification-gap) over a 1,072-line diff that excluded lockfiles and planning files. No intent_gap or bad_spec entries, so no loopback.

## Design Notes

A later CI story can reuse the Docker tools image so CI matches local runs. The architecture spine's global-table list grows by `personal_access_tokens`; record it in the architecture documents when this story is reviewed.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: Pint, Larastan, Pest, Vitest, colour lint and audits pass
- `bin/tools vendor/bin/pest --testsuite=Architecture` -- expected: all pass
- `bin/tools node scripts/lint-colors.mjs` -- expected: exit 0
