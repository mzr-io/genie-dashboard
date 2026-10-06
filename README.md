# Dashflow

Laravel 13 + Inertia 3 + Vue 3.5 dashboard platform, scaffolded from the official Laravel Vue starter kit.

Stack: PHP 8.5 (`ext-bcmath`, `ext-uv`, `ext-opentelemetry`), Node 22, Vite 8, Tailwind 4, reka-ui 2, Fortify (email and password sign-in only, password reset and confirmation), Wayfinder, Sanctum (same-origin SPA cookie plus CSRF for `/api/v1`), Pest 5, Horizon, Reverb, Pinia, vue-i18n, ECharts.

## Getting started

PHP and Node run in a Docker tools image (PHP 8.5 CLI, Composer, Node 22), so the host needs only Docker. `bin/tools` builds the image on first use, maps your host uid and keeps the Composer and npm caches in `~/.cache/dashflow-tools`.

```sh
cp .env.example .env
bin/tools composer install
bin/tools php artisan key:generate
bin/tools npm ci
bin/tools npm run build
bin/tools php artisan migrate     # creates database/database.sqlite for local use
bin/tools php artisan db:seed
```

`db:seed` creates the local-only user test@example.com / password (registration is disabled); it is skipped in production.

Everyday commands:

```sh
bin/tools vendor/bin/pest            # test suite (SQLite in memory)
bin/tools npm run types:check        # vue-tsc
bin/tools npm run check              # vp check (lint and format)
bin/tools php artisan route:list
bin/tools php artisan serve --host=0.0.0.0   # http://localhost:8000
```

If your host already has PHP 8.5 with the extensions above, you can run the same commands without the `bin/tools` prefix.

## Quality gates

`bin/tools composer ci:check` runs every gate below in order and stops at the first failure; each failing gate exits non-zero and names the offending file. No CI pipeline runs `ci:check` yet; it is run by hand.

| Gate                                                                                         | Command                                                       |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| Pint (code style)                                                                            | `bin/tools composer lint:check`                               |
| Larastan level 7                                                                             | `bin/tools composer types:check`                              |
| Pest: unit, feature and architecture suites                                                  | `bin/tools vendor/bin/pest`                                   |
| Architecture rules only                                                                      | `bin/tools vendor/bin/pest --testsuite=Architecture`          |
| Lint, format and type check (Vue, TypeScript)                                                | `bin/tools npm run check` and `bin/tools npm run types:check` |
| Vitest                                                                                       | `bin/tools npm test`                                          |
| Colour lint (no raw hex or `rgba()` outside the token file; banned brand colours everywhere) | `bin/tools node scripts/lint-colors.mjs`                      |
| Composer advisories (high severity and above)                                                | `bin/tools composer audit:php`                                |
| npm advisories (high severity and above)                                                     | `bin/tools npm run audit:deps`                                |
| Licence deny-list (AGPL, SSPL, BSL) for Composer and npm                                     | `bin/tools node scripts/license-audit.mjs`                    |

The advisory audits need network access. Architecture rules live in `tests/Architecture`: allowed module edges, table ownership and the global-table list are in `tests/Architecture/dependencies.php` (the only place to change them), and each rule has a fixture under `tests/Architecture/Fixtures` proving it fails when broken. `resources/js/pages/Welcome.vue` and `resources/js/app.ts` are temporarily allowlisted in the colour lint (Stories 1.6 and 1.16). The table-ownership and migration checks are regex-based: they do not read Eloquent `$table` properties or variable table names.

## Notes

- Public registration, email verification, two-factor and passkeys are not part of the product; `/register` returns 404.
- API routes live under `/api/v1` and authenticate with the session cookie plus the `X-XSRF-TOKEN` header. Stateful hosts come from `SANCTUM_STATEFUL_DOMAINS`.
- Do not commit `vendor/`, `node_modules/`, `.env` or the SQLite file.
