---
title: 'Initialise the project from the Laravel Vue starter kit'
type: 'feature'
created: '2026-10-06'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'b0ba8975e13d32d1e7f9a37b3a883211b8a2221e'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The repository holds no application. Every later story needs the agreed stack, with sign-in working from a fresh clone (Story 1.1, AR-1, NFR-4, NFR-8).

**Approach:** Scaffold the official Laravel Vue starter kit at the repository root on PHP 8.5, remove the features the MVP excludes with the kit's own Chisel script, and add the required packages. PHP and Node run in a documented Docker tools image because the host has PHP 8.3.

## Boundaries & Constraints

**Always:** PHP `^8.5` with `ext-bcmath`, `ext-uv` and `ext-opentelemetry` required in `composer.json`. Laravel 13, Inertia 3, Vue 3.5, TypeScript 5, Tailwind 4, reka-ui 2, Fortify, Wayfinder, Vite 8, Node 22. Pest 5 replaces PHPUnit. npm is the package manager. Sanctum is added with `php artisan install:api`, and API routes live under `/api/v1`. Email and password sign-in only (FR-2); password reset and password confirmation stay. SQLite in memory is used for tests only. `APP_NAME` is Dashflow.

**Never:** Public registration, email verification, Fortify two-factor, passkeys or the Teams scaffold. No Compose, CI, row-level security, audit or UI work (Stories 1.2, 1.3, 1.10 and later). Do not commit `vendor/`, `node_modules/`, `.env` or the SQLite file. Do not use `laravel/vue-starter-kit` from Packagist (it is the old Laravel 12 kit).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Sign in | Seeded user, valid email and password | Signed in, session established | N/A |
| Wrong password | Seeded user, wrong password | HTTP 422, no session | Validation error on `email` |
| Registration | `GET`/`POST /register` | HTTP 404 | N/A |
| API with session | After sign-in, `GET /api/v1/ping` with `X-XSRF-TOKEN` | HTTP 200 JSON | N/A |
| API without CSRF | Stateful request without `X-XSRF-TOKEN` | HTTP 419 | N/A |
| API unauthenticated | `GET /api/v1/ping`, no session | HTTP 401 | N/A |

</frozen-after-approval>

## Code Map

- Repository root has only `README.md`, `_bmad/`, `_bmad-output/` and `.claude/`. The kit is copied to the root and must not touch those.
- `/tmp/claude-1001/-home-bs01729-Projects-rmg-dashboard-platform/1f277b54-5367-4028-a898-f4763b0d3452/scratchpad/spike/app` -- validated scaffold from the spike (vendor and node_modules included): reuse it instead of re-downloading (Composer dist downloads hit GitHub rate limits; use source installs).
- `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `routes/settings.php`, `tests/Feature/Settings/SecurityTest.php` -- Chisel edits these; only password reset and confirmation remain.
- `app/Models/User.php` -- add `HasApiTokens`. `bootstrap/app.php` -- `statefulApi()` and the `/api/v1` prefix. `routes/api.php` -- the `ping` route.
- `package.json` -- scripts run through `vp` (vite-plus): `build`, `check`, `types:check`.

## Tasks & Acceptance

**Execution:**
- [x] `docker/tools/Dockerfile`, `bin/tools` -- PHP 8.5 CLI (bcmath, intl, zip, pdo_pgsql, pdo_sqlite, pcntl, mbstring, uv, opentelemetry), Composer, Node 22; wrapper maps the host uid and persists the Composer cache -- documented install path on a PHP 8.3 host
- [x] repository root -- copy the kit from `dev-main`, excluding `.git`; keep `_bmad*`, `.claude` and `.git` -- starting tree
- [x] `composer.json`, `composer.lock` -- set PHP and extensions; remove `phpunit/phpunit`, `laravel/chisel` and `laravel/sail`; require Pest 5 with `pest-plugin-laravel` 5, Horizon 5, Reverb 1, Sanctum 4, `opis/json-schema` 2.6, `open-telemetry/sdk` 1.15 and `opentelemetry-auto-laravel` 1.9; dev `symfony/json-path` 8.1 -- AR-1 manifest
- [x] Chisel -- `php artisan install:features --no-interaction --answers='{"auth_features":["password-confirmation"]}'`, then delete leftover `chisel*.php` and `InstallFeaturesCommand.php` -- remove registration, verification, 2FA and passkeys
- [x] `package.json`, `package-lock.json` -- add `pinia` 4, `vue-i18n` 11, `echarts` 6.1 and `vue-echarts` 8.3 -- AR-1 manifest
- [x] Sanctum files -- `install:api`, `HasApiTokens`, stateful domains in `.env.example`, `routes/api.php` `GET /api/v1/ping` behind `auth:sanctum` -- same-origin SPA auth
- [x] `tests/Feature/Auth/*`, `tests/Feature/Api/PingTest.php`, `tests/Feature/ManifestTest.php` -- Pest tests for the matrix rows and the manifest (versions and extensions); delete the skipped 2FA-only tests -- AC coverage
- [x] `.env.example`, `README.md` -- `APP_NAME=Dashflow`; README "Getting started" with the `bin/tools` commands -- documented install and build

**Acceptance Criteria:**
- Given a fresh clone, when the documented commands run (`bin/tools composer install`, `bin/tools npm ci`, `bin/tools npm run build`), then the app builds without errors on PHP 8.5.
- Given `composer.json`, then it requires the manifest above and the kit's PHPUnit and Teams scaffold are absent.
- Given the running app, when `/register` is requested, then 404, and `php artisan route:list` shows no register, two-factor or passkey routes.
- Given the matrix, when the Pest suite runs, then all sign-in and `/api/v1` scenarios pass.

## Implementation Notes

- Scaffold reused from the validated spike (kit `dev-main`, `install:features` and `install:api` already applied) and synced into the repo root. `.git`, `.env`, `.github` (CI is Story 1.2), `_bmad*` and `.claude` excluded.
- `docker/tools/Dockerfile` and `bin/tools` give a PHP 8.5 + Node 22 toolchain; caches live in `~/.cache/dashflow-tools`.
- `laravel/chisel` and `laravel/sail` removed; package renamed `dashflow/dashboard-platform`. Leftover 2FA code, the `verified` middleware and 2FA tests removed.
- Added `POST /api/v1/ping` beside the spec's `GET`: CSRF does not apply to GET, so the 419 row needs a state-changing route.
- `phpunit.xml` carries a fixed test `APP_KEY`. A fresh clone still needs `cp .env.example .env` (documented in the README).
- Review fixes (step 3): `.gitignore` no longer ignores `/.claude`, `/AGENTS.md`, `/CLAUDE.md`; `vite.config.ts` lint and format ignore `_bmad*` and `.claude` so `npm run check` passes; two-factor docblock and hidden columns removed from `User`; the CSRF test now uses the real `/sanctum/csrf-cookie` and `X-XSRF-TOKEN` round trip, with a forged-token 419 case.
- Left for later stories: PostgreSQL and Compose (1.3), CI (1.2), appearance and dark-mode controls (1.6, 1.16), UUID keys (1.10). `personal_access_tokens` is unused (session auth only).

## Spec Change Log

## Review Triage Log

| ID | Verdict | Route | Evidence |
|----|---------|-------|----------|
| BH-1 leftover kit branding (Welcome links, GitHub links, "Laravel" title fallback) | low | defer | Real but UI work the spec excludes; Stories 1.6 and 1.16 own the shell |
| BH-2 boilerplate ExampleTest files | low | rejected | Negligible harm; deleting adds nothing |
| BH-3 / EC-1 / EC-2 seeder: no first user, known account in production | medium | patch | `DatabaseSeeder` seeds test@example.com / password with no guard; README never seeds, so a fresh clone cannot sign in |
| BH-4 Horizon, Reverb, OTel installed but not configured | false | none | AR-1 is manifest-only; config arrives in Stories 1.4 and 1.5; unconfigured packages are inert |
| BH-5 hard ext requirements, no CI, `.gitattributes` workflow, "no lockfiles" | false | none | Extensions are mandated; CI is Story 1.2; both lockfiles exist (excluded from the review diff by filter) |
| BH-6a Dockerfile `\| tail -2` masks failed extension builds | low | patch | The pipe returns tail's exit code, so a failed build still succeeds |
| BH-6b unpinned base images / EC-11 | low | defer | Dev tools image only; Story 1.3 builds the pinned image |
| BH-6c no rebuild flag, no docker check, `--network host` (also EC-9), pdo_pgsql unused | low | rejected | Linux dev tool, clear errors; pdo_pgsql is for Story 1.3 |
| BH-6d no redis extension in tools image | medium | defer | Real, but Horizon and Valkey run in Stories 1.3 and 1.5 |
| BH-7 / EC-13 / EC-14 optionalDependencies pins, pnpm-workspace.yaml, `.npmrc`, vueuse and vue-tsc versions | low | defer | Byte-identical in the pristine kit, so pre-existing; review in Story 1.2 |
| BH-8 / EC-7 / EC-8 / VG-other brittle or host-dependent ManifestTest | low | rejected | Tests encode the story's literal AC; fix adds complexity |
| BH-9a throttle on password reset and confirm | medium | defer | Story 1.14 AC covers throttling |
| BH-9b session security env, APP_DEBUG example, lax non-production passwords / EC-12 / VG-2 | low | defer | Kit defaults; Epic 9 hardening |
| BH-9c no sign-out CSRF or session-fixation test | low | rejected | Framework behaviour |
| BH-10a PingTest never restores `env` (EC-6) | false | none | Laravel boots a fresh application for every test |
| BH-10b / VG-1 `api/*` JSON branch untested | medium | patch | Verified: a plain request returns 401 JSON today, but no test pins it |
| BH-10c hardcoded Referer, no non-stateful 401 test, no JSON shape tests, md5 throttle key in kit test | low | rejected | Kit test internals or speculative |
| BH-11a `artisan dev` missing | false | none | `php artisan list` shows `dev` and `dev:list` |
| BH-11b phpstan excludes tests, `composer test` vs README | low | rejected | Kit configuration |
| EC-3 sole user can delete their own account | medium | defer | PRD has no self-deletion; Stories 1.18 and 1.24 settle profile and user lifecycle |
| EC-4 Sanctum stateful hosts limited to localhost:8000 | low | rejected | Documented env setting; behaviour by design |
| EC-5 wrong password returns 302 for non-JSON requests, not 422 | low | rejected | 422 holds for JSON clients; Inertia forms get redirect-with-errors by framework design; no user-visible defect |
| EC-15 migrate fails without a TTY | false | none | The fresh-clone run created the SQLite file and migrated non-interactively |
| EC-16 Teams scaffold absence untested | low | patch | The story AC lists it; a one-line test pins it |
| VG-other stateful tests rely on a local `.env` value | low | patch | `phpunit.xml` does not set `SANCTUM_STATEFUL_DOMAINS` |
| VG-other password update throttle untested | low | rejected | Kit route middleware |

Review inputs: three layers (blind, edge-case, verification-gap) over a 7,491-line diff that excluded lockfiles, vendored `components/ui`, `public`, `storage` and planning files. No intent_gap or bad_spec entries, so no loopback.

## Design Notes

Validated sequence (spike): `composer create-project laravel/vue-starter-kit:dev-main --prefer-source`, edit `composer.json`, `install:features --answers` (also runs `composer lint`, Wayfinder generation and the asset build). Pest 5 needs `pest-plugin-laravel` 5 and Laravel 13.23 or later. `opentelemetry-auto-laravel` requires `ext-opentelemetry`, which builds on PHP 8.5.

## Verification

**Commands:**
- `bin/tools composer validate` -- expected: valid
- `bin/tools vendor/bin/pest` -- expected: all tests pass
- `bin/tools npm run build` and `bin/tools npm run types:check` -- expected: exit 0
- `bin/tools php artisan route:list` -- expected: no register, two-factor or passkey routes
