---
title: 'Run all five process roles locally with Docker Compose'
type: 'feature'
created: '2026-10-06'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
baseline_commit: 'de7d4202da2aeefd32ae893c9a349cec8b00be05'
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-2-enforce-architecture-and-quality-gates-in-ci.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The platform only runs through `artisan serve` with SQLite, but the architecture needs one image running five roles with PostgreSQL and Valkey, the same way locally, in demos and in production (Story 1.3; AR-2, AR-29, AR-47, AR-48, AR-50, NFR-14).

**Approach:** One multi-stage image and a `compose.yaml` start the five roles by command and environment, with PostgreSQL 18, Valkey 9 and a one-shot `migrator`. Each role reports health from its own dependencies; key purposes are mounted only where AR-50 allows.

## Boundaries & Constraints

**Always:** One image, no per-role image. PHP 8.5 with `bcmath`, `pdo_pgsql`, `redis`, `uv`, `opentelemetry`, `pcntl`, `intl`, `zip`, `opcache`; Node 22 builds assets; web runs nginx and php-fpm as non-root on unprivileged ports; base tags pinned. The `migrator` runs `migrate --force` and finishes before any role starts. The scheduler runs `schedule:run` each minute, and every scheduled task uses `onOneServer()` with a cache store that supports locks. `worker-connector` runs Horizon on queues `fetch-interactive` and `fetch-scheduled`; `worker-compute` on `compute`, `outbox`, `notifications` and `maintenance`. All settings come from environment variables, never the image; no cloud service is needed. Key purposes mounted as placeholder files: `web` gets `data` and `digest`; `worker-connector` gets `cred`, `token` and `data`; `worker-compute` gets `data`; `realtime` and `scheduler` get none. The web port is 8080 and Reverb is 8081, bound to `127.0.0.1` (port 8000 is taken on this host).

**Never:** Splitting Valkey into queue and cache stores, signed jobs (Story 1.5); OpenTelemetry config (1.4); PgBouncer; signing, SBOM, Helm (Epic 9); CI files; real keys in the repo; product or UI changes.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Cold start | `docker compose up` | PostgreSQL and Valkey healthy, `migrator` exits 0, then all five roles become healthy | N/A |
| Web ready | `GET /health/ready` with dependencies up | 200 and `{"status":"ok"}` | N/A |
| PostgreSQL down | `GET /health/ready`, or a role's health command | 503 / non-zero exit with the failing check named | Reason in body or stderr |
| Valkey down | Same | 503 / non-zero with Valkey named | Reason in body or stderr |
| Liveness | `/health/live`, PostgreSQL stopped | 200 | N/A |
| Two schedulers | Two `schedule:run` in the same minute | Each task runs once | Second run skips |
| Missing `APP_KEY` | Stack started without it | Compose refuses with a clear message | Names the variable |
| Key mapping | Compose file inspected | Roles and key purposes match AR-50 exactly | Test fails on any extra or missing mount |

</frozen-after-approval>

## Code Map

- `docker/tools/Dockerfile`, `bin/tools` -- dev tools image (extension pattern to reuse; no `redis` ext); keep working
- `bootstrap/app.php` -- `/up` liveness route exists; `routes/console.php` -- no scheduled tasks; `routes/web.php`
- `config/queue.php`, `cache.php`, `session.php`, `.env.example` -- SQLite and database drivers today; the stack sets `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `REDIS_HOST=valkey`; sessions stay `database`
- `composer.json` -- Horizon 5 and Reverb 1 installed, no `config/horizon.php`, `reverb.php` or `broadcasting.php`
- `vite.config.ts` -- the build runs `wayfinder:generate`, so the asset stage needs PHP and `vendor/`
- `phpstan.neon`, `tests/Architecture/dependencies.php` -- Larastan level 7; `app/Platform` and `app/Modules` are rule-scanned, so put health code under `app/Support` and `app/Console`
- Verified: `postgres:18-alpine`, `valkey/valkey:9-alpine`, `php:8.5-fpm-bookworm`, `node:22-bookworm-slim` exist; Docker Compose 5.5 installed

## Tasks & Acceptance

**Execution:**
- [x] `docker/Dockerfile`, `.dockerignore`, `docker/nginx.conf`, `docker/entrypoint.sh` -- multi-stage build (Composer and Vite stage, runtime stage); role chosen by command; web starts php-fpm and nginx and exits if either dies
- [x] `compose.yaml`, `docker/dev-keys/*.placeholder`, `.gitignore` -- services, health checks, ordering, env anchors, key secrets per role
- [x] `config/horizon.php`, `config/reverb.php`, `config/broadcasting.php`, `app/Providers/HorizonServiceProvider.php` -- role-selected supervisors and queues, Reverb host and port from env, dashboard denied by default
- [x] `app/Console/Commands/*`, `app/Support/Health/*`, `routes/web.php`, `routes/console.php` -- `dashflow:health {role}`, `/health/live`, `/health/ready`, a `onOneServer()` heartbeat task the scheduler health check reads
- [x] `tests/Feature/HealthTest.php`, `tests/Feature/SchedulerTest.php`, `tests/Architecture/ComposeKeysTest.php` -- health ok and unhealthy with reason, no duplicate scheduled run, key mapping
- [x] `.env.example`, `README.md` -- stack variables, `docker compose up`, ports, health commands

**Acceptance Criteria:**
- Given the Dockerfile, when built, then one image exists and `docker compose config` shows every role using it.
- Given `docker compose up`, when startup finishes, then PostgreSQL 18, Valkey 9 and the exited `migrator` are present and all five roles report healthy.
- Given PostgreSQL is stopped, when each role's health is checked, then each returns unhealthy and names PostgreSQL, while `/health/live` stays 200.
- Given the full gate, when `composer ci:check` runs, then it passes.

## Implementation Notes

- One multi-stage `docker/Dockerfile` (base with extensions, build stage with Composer and Node 22, runtime with nginx and php-fpm as uid 10001); roles chosen by `docker/entrypoint.sh`. Base tags are pinned to the verified tags, not digests.
- `compose.yaml` defaults `APP_ENV` to `production`, so the Story 1.1 seeder (skipped in production) creates no user. Ports 8080 and 8081 are overridable with `DASHFLOW_WEB_HOST_PORT` and `DASHFLOW_REALTIME_HOST_PORT`; PostgreSQL and Valkey publish no host ports.
- Health code lives in `app/Support/Health/*`. The HTTP failure body shows only the check name and exception class; the full reason goes to stderr from `dashflow:health`. `dashflow:health` for web and realtime also checks that nginx or Reverb is listening; `/health/ready` does not.
- `symfony/yaml` added as a dev dependency for the compose-key test. `config/database.php` gets a 5-second connect timeout.
- The scheduler test clears Laravel's per-process mutex memo by reflection to simulate a second process; the shared cache lock is what is tested.
- Verified by the reviewer: `docker compose` cold start (migrator exited 0, all five roles healthy), PostgreSQL stopped (503 naming PostgreSQL, `/health/live` 200, all five roles exit 1 naming PostgreSQL), recovery on restart, missing `APP_KEY` refused naming the variable, and two scheduler containers over several minutes (one ran the heartbeat 4 times, the other 0 and skipped 3). Full `ci:check`: 93 Pest tests, 20 Vitest tests, all gates green.

## Spec Change Log

## Review Triage Log

Layers: Blind Hunter, Edge Case Hunter, Verification Gap (all returned).

- patch (fixed): web role `docker stop` fell through to `exit 1`; now exits 0 on TERM/INT (`docker/entrypoint.sh`).
- patch (fixed): Horizon health counted any non-paused master as healthy; now requires `running`.
- patch (fixed): added tests for the Horizon health check (empty, paused, other host, running), the nginx and Reverb local port checks, and the denied Horizon gate.
- patch (fixed): README now explains how to get a sign-in user in the Compose stack.
- defer: unpinned digests, `/health/ready` throttle and caching, probe query and read timeouts, per-host scheduler heartbeat, default-secret guards for production, Reverb allowed origins, `default` queue consumer, `APP_KEY` needed for `compose down`, extra tests (heartbeat schedule, queue split, entrypoint). See `deferred-work.md`.
- reject: `.gitignore` not in diff (no change was needed, existing rules already cover `.env`); `npm run build` Wayfinder worry (image build verified live); `symfony/yaml` dev-only is intended; `ltrim('./')` in the compose test (paths are fixed placeholders).

## Design Notes

Scheduler loop: run `schedule:run`, sleep to the next minute. The heartbeat task is the scheduler's health signal and proves `onOneServer()`. Horizon picks supervisors by `config('horizon.env')`: `connector` or `compute`.

## Verification

**Commands:**
- `docker compose config -q` -- expected: valid
- `docker compose build && docker compose up -d` then `docker compose ps` -- expected: roles healthy, `migrator` exited 0
- `curl -s http://127.0.0.1:8080/health/ready` -- expected: 200 `ok`; after `docker compose stop postgres`, 503 naming PostgreSQL
- `bin/tools composer ci:check` -- expected: exit 0
