---
title: 'Verify security and load readiness with test suites and a load-test skeleton'
type: 'feature'
created: '2026-10-07'
status: 'done'
baseline_commit: '8b727506c3c4676fb488d1adcc1008e77a480fad'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-10-isolate-each-workspace-with-row-level-security.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-13-sign-in-as-user-or-admin.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-24-deactivate-and-reactivate-users.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The security guarantees of Epic 1 are tested in many places but nothing proves the set is complete or fails the build when a tenant table lacks a leak test, there is no load-test harness, the accessibility claims are unchecked by a tool, and the restore expectation is unstated (Story 1.25; NFR-4, NFR-3, NFR-7, AR-54, AR-55, AR-56, UX-DR-264, 275). The Story 1.25 section of `epics.md` holds the acceptance criteria.

**Approach:** A named security suite with a coverage manifest that maps every required guarantee to its tests and fails on a missing one, a dependency-free Node load-test harness with env-only targets and skipped placeholder scenarios, an axe check over the four named pages in the DOM test environment plus a manual keyboard and reflow checklist, and a restore check that dumps and restores the test database and re-verifies RLS and roles.

## Boundaries & Constraints

**Always:** The security suite is the Pest group `security` (run by `composer ci:check`, and available alone as `composer test:security`) and a manifest test (`tests/Security/CoverageManifestTest.php`) lists each required guarantee (cross-tenant RLS leak per tenant table, CSRF on sign-in, area change and Workspace switch, session rotation on sign-in and Workspace switch and password reset, invitation single-use and permission cap, last-`users.manage`-holder, signed-job tamper, fail-closed missing context) against the named tests that prove it; the manifest fails when a listed test no longer exists, and a Database test fails the build when a tenant table (every table with `workspace_id`, read from the live schema) has no leak seeder in `Cluster` or no entry in the leak test. Existing tests are tagged into the group (via `->group('security')` or directory placement), not duplicated; any guarantee with no test is added. Area change has no route in this epic: the manifest records it as "not applicable until an area-switch route exists" and the test asserts that no such route exists, so adding one fails the build until it gets a CSRF and rotation test. The load-test harness lives in `load/` as plain Node (no new dependency) with `npm run load` and `npm run load:plan`: it signs in through the real sign-in flow with the CSRF cookie, then runs an authenticated page flow against the Compose stack at a configurable concurrency and duration and prints request rate, p50, p95 and error count as JSON; every target number (concurrency, duration, request rate ceiling, p95 target, base URL, credentials) comes from environment variables read through `config/dashflow.php`-style `pending_input` documentation (`DASHFLOW_LOAD_*`), none has a default value, and the harness exits non-zero with a clear message naming each unset variable; it never runs in `ci:check`. Placeholder scenarios (dashboard results fan-in, sync dispatch with hot and cold keys, per-workspace budgets, Reverb fan-out) are files marked "not implemented" that report as skipped and never fail CI. The accessibility check (`tests/js/a11y.test.ts`) mounts Sign in, Reset password, Profile & settings and User configuration in happy-dom with `axe-core` as a dev dependency and fails on any violation of the WCAG 2.1 A and AA tags that axe can evaluate without layout (names, roles, labels, ARIA validity, landmarks, heading order), and asserts the 320 px reflow rules that can be checked statically (no fixed widths wider than the viewport, tokens for target sizes); colour contrast stays covered by the existing token contrast tests, and a manual checklist in `docs/accessibility-checklist.md` lists the keyboard order, focus, zoom and 320 px reflow steps per page, with the statement that real-browser axe runs are deferred. A repository note `docs/backup-restore.md` records that the full restore drill is delivered in Epic 9 and states what this epic verifies. The restore check (`bin/test-restore`, run by `bin/tools` after the Database suite and failing the command when it fails) dumps the migrated test database with `pg_dump`, restores it into a fresh database, and asserts through SQL that every tenant table still has RLS enabled and forced with its policy, that the five roles exist with no BYPASSRLS, that `app` has no DELETE on tenant tables and that the `SECURITY DEFINER` functions are owned by `migrator`. Dev credentials stay development values.

**Never:** Hard-coded load targets or credentials; running the load harness in CI; adding Playwright or a browser download in this story; weakening or deleting an existing security test; claiming real-browser accessibility coverage; a restore drill beyond the RLS and role checks (Epic 9).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Security suite | `composer ci:check` | Group `security` runs and passes | A listed guarantee without a test fails the manifest |
| New tenant table | Table with `workspace_id` and no leak seeder | Build fails naming the table | N/A |
| Area change | A route that switches the area appears | Test fails until CSRF and rotation tests exist | N/A |
| Load harness | All `DASHFLOW_LOAD_*` set, Compose stack up | Sign-in then page flow; JSON with rate, p50, p95, errors | Unset variable: non-zero exit naming it |
| Placeholders | `npm run load:plan` | Four scenarios listed as not implemented and skipped | Never fails CI |
| Axe | The four pages | No A or AA violation axe can evaluate | Names the rule and node |
| Checklist | `docs/accessibility-checklist.md` | Keyboard, focus, zoom and 320 px steps per page | N/A |
| Restore | Dump and restore into a fresh database | RLS enabled and forced, roles, grants and function owners intact | Command fails on any drift |
| Docs | `docs/backup-restore.md` | States Epic 9 owns the full drill | N/A |

</frozen-after-approval>

## Code Map

- `tests/Database/RowLevelSecurityTest.php`, `tests/Database/Support/Cluster.php` (`tenantTables()`, seeders), `RolePrivilegesTest.php`, `PoolingTest.php` -- leak and role tests built from the live schema; `tests/Database/SignInTest.php`, `WorkspaceSwitchTest.php`, `WorkspaceProvisioningTest.php`, `InviteUsersTest.php`, `MemberAccessTest.php`, `MemberStatusTest.php`, `AdminAccessTest.php`, `tests/Feature/Queue/SignedJobTest.php`, `tests/Feature/Auth/*` -- the existing security tests to tag and map
- `tests/Pest.php`, `phpunit.xml`, `phpunit.database.xml`, `composer.json` (`test`, `test:database`, `ci:check`), `bin/tools`, `bin/test-db`, `compose.test.yaml` -- suites and the Docker-backed harness around them
- `routes/web.php`, `routes/api.php` -- routes to assert (no area-switch route); `package.json` (`vitest`, `happy-dom` present), `tests/js/`, `resources/js/pages/auth/Login.vue`, `ResetPassword.vue`, `pages/settings/Profile.vue`, `pages/admin/Users.vue` -- pages for the axe check
- `config/dashflow.php`, `.env.example`, `README.md` -- documentation of the `DASHFLOW_LOAD_*` variables and the new commands; `compose.yaml` -- the stack the harness targets

## Tasks & Acceptance

**Execution:**
- [x] `tests/Security`, group tags, `composer.json`, tenant-table guard -- security suite, coverage manifest, new-table guard, area-route guard
- [x] `load/`, `package.json`, `config/dashflow.php`, `.env.example` -- load harness, plan command, skipped placeholders, env-only targets
- [x] `tests/js/a11y.test.ts`, `package.json`, `docs/accessibility-checklist.md` -- axe check and manual checklist
- [x] `bin/test-restore`, `bin/tools`, `docs/backup-restore.md`, `README.md` -- restore verification and note

**Acceptance Criteria:**
- Given `bin/tools composer ci:check`, when run, then the security suite, the axe check and the restore check run and pass, and a tenant table without a leak test fails the build.
- Given the load harness with all `DASHFLOW_LOAD_*` variables set against the Compose stack, when run, then it reports request rate and p95; with any unset it exits non-zero naming the variable.

## Implementation Notes

## Spec Change Log

- **Review iteration 1 (clarifications, frozen block untouched).** (a) The new-tenant-table guard fails the build when a table with `workspace_id` has no seeder in `Cluster::seedTenantRow()`, which the generated leak tests iterate; it does not check a per-table entry in a hand-kept list. (b) PostgreSQL roles are cluster-global, so after restoring into the same cluster the role assertions confirm the cluster setup the restored database depends on, not that the dump carried roles; the check's value for the restore is the table-level RLS flags, policy expressions, grants and function owners, which it now compares against the source. A real cluster-restore drill is Epic 9. (c) The restore check runs from `bin/tools` (host wrapper), not from `composer ci:check` alone, because the PHP tools container has no Docker or `pg_dump`.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| The load harness can report a clean result for a run that never signed in or ignored its ceiling: sign-in success is not verified, page-response cookies are not stored, failed requests' latencies enter p50 and p95, there is no fetch timeout, pacing can overshoot the duration, the email is used untrimmed, concurrency is unbounded, and it targets any host | high | patch | `load/page-flow.mjs`, `load/config.mjs`; the unit tests never run `runPageFlow` or `CookieJar`. |
| The coverage manifest is satisfied by a skipped, `todo`, commented-out or multi-line-title test; the leak-coverage guard reads source text (a regex over `Cluster.php`, a 900-character window) | high | patch | `securityTestTitles()` matches substrings on lines starting with `it(` or `test(`. |
| `bin/test-restore` compares no policy expression, copies through a pipe without `pipefail`, registers its trap after the first DROP, uses a fixed restore name, and scrapes `dependencies.php` by indentation | high | patch | A weakened policy or a truncated dump still passes. |
| `LoadSettingsTest` fails for a developer whose `.env` sets `DASHFLOW_LOAD_*`, as the docs instruct | medium | patch | `phpunit.xml` neutralises only `DASHFLOW_INVITATION_LIFETIME`. |
| `test:security` runs the restore check even when the Database suite did not run; CSRF tests leak `app()['env']`, accept a 422 for a throttled sign-in, and cover only two routes; the area-route guard is name-based and bypassable | medium | patch | `bin/tools`; `CsrfTest.php`; `AreaChangeRouteTest.php`. |
| The load harness exits 0 when the p95 target is missed; the page list is hard-coded and 404s look like load errors; axe uses the `best-practice` tag and a narrow width scan; the licence note for `axe-core` is missing | medium | patch | No `--strict`; no preflight; README lacks the licence decision. |
| Restore drift detection is never shown to fail; real-browser axe and error states | medium | defer | Needs a scratch-database negative run and a browser; both belong with Epic 9 and a later accessibility pass. |
| Mocked `useForm` in the axe mounts; per-page latency breakdown and warm-up; the manual checklist text was not browser-checked | low | rejected | Structure rules only, as the checklist states; harness skeleton scope. |

## Design Notes

Real-browser axe (contrast, layout and reflow) needs a browser in the tools image; that is a larger dependency than this story justifies, so CI runs axe where it is reliable (structure, names, roles) and the checklist plus the token contrast tests carry the rest, stated plainly rather than implied. The restore check runs on the host in the same wrapper that already starts the test containers, because the PHP tools container has no Docker or `pg_dump`.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the security suite, the axe check and the restore check
- `bin/tools npm run build` -- expected: exit 0
- `DASHFLOW_LOAD_*=… npm run load` against `docker compose up` -- expected: JSON report with rate and p95
