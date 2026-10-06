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

`db:seed` creates the local-only user test@example.com / password (registration is disabled); it is skipped in production. In the Compose stack, start it with `DASHFLOW_ENV=local` and run `docker compose exec web php artisan db:seed` to get a sign-in user.

Everyday commands:

```sh
bin/tools vendor/bin/pest            # test suite (SQLite in memory)
bin/tools npm run types:check        # vue-tsc
bin/tools npm run check              # vp check (lint and format)
bin/tools php artisan route:list
bin/tools php artisan serve --host=0.0.0.0   # http://localhost:8000
```

If your host already has PHP 8.5 with the extensions above, you can run the same commands without the `bin/tools` prefix.

## Docker Compose stack

One image runs five process roles, chosen by command: `web` (nginx and php-fpm), `realtime` (Reverb), `scheduler` (`schedule:run` each minute), `worker-connector` (Horizon on `fetch-interactive`, `fetch-scheduled`) and `worker-compute` (Horizon on `compute`, `outbox`, `notifications`, `maintenance`). PostgreSQL 18 and Valkey 9 back them, and a one-shot `migrator` runs `migrate --force` before any role starts.

```sh
export APP_KEY="$(bin/tools php artisan key:generate --show)"   # or keep APP_KEY in .env
docker compose up --build -d
docker compose ps
```

| Service           | Address                                                                |
| ----------------- | ---------------------------------------------------------------------- |
| web               | http://127.0.0.1:8080 (`DASHFLOW_WEB_HOST_PORT` changes the host port) |
| realtime (Reverb) | 127.0.0.1:8081 (`DASHFLOW_REALTIME_HOST_PORT`)                         |

Compose refuses to start if `APP_KEY` is missing. Health:

- `GET /health/live` is always 200; `GET /health/ready` is 200 `{"status":"ok"}` or 503 naming the failing check (PostgreSQL or Valkey).
- Each role: `docker compose exec <role> php artisan dashflow:health <role>` exits non-zero and names the failing check on stderr. Every role checks PostgreSQL and Valkey; workers also check their Horizon master, the scheduler its heartbeat, web and realtime their listening port.

Key purposes (AR-50) are mounted as placeholder files from `docker/dev-keys/` under `/run/secrets`: `web` gets `data` and `digest`; `worker-connector` gets `cred`, `token` and `data`; `worker-compute` gets `data`; `realtime` and `scheduler` get none. `tests/Architecture/ComposeKeysTest.php` enforces the mapping. Every scheduled task must use `onOneServer()`.

## Observability

Every request gets a `request_id`: a valid incoming `X-Request-Id` (`[A-Za-z0-9._-]{8,64}`) is kept, anything else is replaced by a generated ULID, and the response always carries it. Queued jobs carry it in the payload and restore it before running, so job logs and spans share the originating request's id.

Logs are one JSON object per line on stdout (`LOG_CHANNEL=stdout`, set by the Compose stack) with `timestamp`, `level`, `channel`, `message`, and `request_id`, `trace_id`, `workspace_id` when known. Read them with `docker compose logs web` (or `docker compose logs -f web | jq .`) and match the `request_id` to the response header, for example `curl -si localhost:8080/up | grep -i x-request-id`. Request bodies are never logged.

Scrubbing is always on and not configurable. Query strings, fragments and userinfo are removed from every URL in logs, spans and metric labels; only the headers `content-type`, `accept`, `user-agent`, `x-request-id` and `content-length` are kept; span attributes outside an allowlist are dropped. The code is `app/Support/Observability/Scrubber.php`, shared by the log processor and the span processor.

| Variable                                                                | Effect                                                                                         |
| ----------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| `OTEL_EXPORTER_OTLP_ENDPOINT` (or `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT`) | Collector URL. Unset: the app runs, nothing is exported and one warning is logged per process. |
| `OTEL_EXPORTER_OTLP_PROTOCOL`, `OTEL_EXPORTER_OTLP_HEADERS`             | Standard OTLP settings (default `http/protobuf`).                                              |
| `OTEL_SERVICE_NAME`, `OTEL_RESOURCE_ATTRIBUTES`                         | Service identity (`dashflow` in Compose).                                                      |
| `LOG_CHANNEL`, `LOG_LEVEL`                                              | `stdout` for JSON lines; the other channels get the same ids and scrubbing.                    |

OTEL variables must be real process environment variables (as in Compose) for the first request's root span to be exported; values only in `.env` start export after the framework boots. Metric names must match `dashflow.<module>.<measure>`; build them with `App\Support\Observability\MetricName`. API errors use `{"error":{"code","message","request_id","details"}}`; `details` is emitted only when a request is marked `area=admin` (request attribute set by future Admin middleware), so today it is always absent.

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
