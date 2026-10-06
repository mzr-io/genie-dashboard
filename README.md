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

One image runs five process roles, chosen by command: `web` (nginx and php-fpm), `realtime` (Reverb), `scheduler` (`schedule:run` each minute), `worker-connector` (Horizon on `fetch-interactive`, `fetch-scheduled`) and `worker-compute` (Horizon on `compute`, `outbox`, `notifications`, `maintenance`). PostgreSQL 18 and two Valkey 9 instances (`valkey-queue`, `valkey-cache`) back them, and a one-shot `migrator` runs `migrate --force --database=migrator` before any role starts.

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

- `GET /health/live` is always 200; `GET /health/ready` is 200 `{"status":"ok"}` or 503 naming the failing check (PostgreSQL, PostgreSQL role, Valkey queue or Valkey cache).
- Each role: `docker compose exec <role> php artisan dashflow:health <role>` exits non-zero and names the failing check on stderr. Every role checks PostgreSQL and both Valkey stores, each over its own connection as that role's ACL user; workers also check their Horizon master, the scheduler its heartbeat, web and realtime their listening port.

Key purposes (AR-50) are mounted as placeholder files from `docker/dev-keys/` under `/run/secrets`: `web` gets `data` and `digest`; `worker-connector` gets `cred`, `token` and `data`; `worker-compute` gets `data`; `realtime` and `scheduler` get none. `tests/Architecture/ComposeKeysTest.php` enforces the mapping. Every scheduled task must use `onOneServer()`.

## Valkey: queue and cache stores

Eviction policy is per Valkey instance, so Compose runs two: `valkey-queue` (`noeviction`) and `valkey-cache` (`allkeys-lru`, `VALKEY_CACHE_MAXMEMORY`, a development value of 256mb). Both serve TLS only (a one-shot `valkey-tls` service creates a throwaway CA and certificate for development; use your own PKI elsewhere), keep no persistence and are never backed up. PostgreSQL is the system of record, and sessions use the `database` driver.

| Laravel connection | Holds                                                        | Used by                                                                   |
| ------------------ | ------------------------------------------------------------ | ------------------------------------------------------------------------- |
| `queue`            | queues, locks, rate limits, schedule mutexes, Reverb fan-out | queue store `redis`, cache store `queue`, Horizon (`horizon.use`), Reverb |
| `cache`            | results and tokens (cache store `redis`)                     | `Cache::store('redis')`: every write must carry a TTL                     |

The `redis` cache store is wrapped by `App\Support\Queue\TtlEnforcingStore`: the store rejects writes without a TTL: `forever`, `put` with a null TTL, and `increment`/`decrement` of a missing key throw `TtlRequiredException` and store nothing (a zero or negative TTL passed to `Cache::put` is treated by Laravel as a delete before the store is reached). Rate limits and schedule mutexes use the `queue` cache store (selected automatically when `CACHE_STORE=redis`).

Each process role connects as its own ACL user (`VALKEY_USERNAME`, `VALKEY_PASSWORD`); the ACL templates are `docker/valkey/queue.acl` and `docker/valkey/cache.acl`, filled from `VALKEY_PASSWORD_<ROLE>` variables (`WEB`, `REALTIME`, `SCHEDULER`, `WORKER_CONNECTOR`, `WORKER_COMPUTE`, `HEALTH`; development defaults in `compose.yaml`, letters, digits and `._~-` only). The default user is off, apart from one exception: Reverb's async Valkey client can only `AUTH` with a password, so on the queue store the `realtime` role is the `default` user. `realtime` can only publish and subscribe on the queue store and only read on the cache store. The `migrator` has no Valkey credentials. A role using another role's credentials gets `WRONGPASS`, and the health check names the store (`Valkey queue` or `Valkey cache`). Realtime starts with `-d openssl.cafile=$VALKEY_TLS_CA` so Reverb trusts the Valkey CA.

Read the stores (the `health` user may only `PING` and `INFO`):

```sh
docker compose exec valkey-queue valkey-cli --tls --cacert /tls-ca/ca.crt --user health --pass dashflow-dev-health --no-auth-warning INFO | grep maxmemory_policy   # noeviction
docker compose exec valkey-cache valkey-cli --tls --cacert /tls-ca/ca.crt --user health --pass dashflow-dev-health --no-auth-warning INFO | grep maxmemory_policy   # allkeys-lru
```

### Signed jobs

Job payloads carry IDs only. Every payload pushed to the Redis queue gets a `signature`: HMAC-SHA256 over `job`, `uuid`, `data.commandName` and `data.command` with a key derived from `APP_KEY` (`HMAC-SHA256(APP_KEY, "dashflow.queue.job-signature.v1")`; the raw `APP_KEY` never signs). The worker checks it with `hash_equals` on `JobProcessing`, before the command is unserialized. A missing or wrong signature logs the security event `security.queue.job_signature_invalid` (reason, connection, queue and job UUID only, never payload content), deletes the job, records it as failed and never retries it. Code: `app/Support/Queue/` (`JobSigner`, `SignedRedisQueue`, `JobSignatureGuard`).

On the first deploy of signing, drain the queues first: jobs queued before the upgrade have no signature, so they are rejected and recorded as failed.

Rotating `APP_KEY` invalidates the signatures of jobs that are already queued: drain the queues first, or accept that those jobs are rejected and recorded as failed.

### Tunables and PgBouncer

Every tunable of AR-57 is a named setting under `dashflow.tunables.<area>.<name>` in `config/dashflow.php`, read from a `DASHFLOW_*` environment variable and flagged `pending_input`: its `value` stays `null` until the environment supplies one. Only `dispatch_tick` (proposed 5 s) and `require_https` (off) have a value. `DB_PGBOUNCER=true` switches the PostgreSQL connection to transaction-mode pooling settings (`PDO::ATTR_EMULATE_PREPARES`); off leaves it unchanged. The Compose stack has no PgBouncer service; tenant isolation under pooling is verified by the `Database` suite (see Workspace isolation).

## Workspace isolation (row-level security)

One shared schema. Every tenant table has a non-null `workspace_id`, `ENABLE` and `FORCE ROW LEVEL SECURITY` and the policy `workspace_id = nullif(current_setting('app.workspace_id', true), '')::uuid`, so with no Workspace context a query returns zero rows. (`nullif` is needed because PostgreSQL leaves an empty string, not NULL, in the setting after a transaction that used `set_config(..., true)`.) `workspaces` and `workspace_memberships` are the first tables; `workspaces` has no `workspace_id`, is a global table and is reachable only through the Access-owned `SECURITY DEFINER` function `access_user_memberships(user_id)`, the one cross-Workspace lookup (`App\Modules\Access\Contracts\MembershipLookup`).

`App\Platform\Tenancy\WorkspaceTransaction` is the only opener of the request or job transaction and the only caller of `set_config(..., true)`; an architecture test fails naming any other file. It is middleware on the `web` and `api` groups (the active Workspace comes from the `workspace_id` session key, set by the sign-in and switcher stories) and job middleware for jobs that implement `WorkspaceScopedJob` and use `RunsInWorkspace`. Such a job carries `workspaceId` as a property of its serialised command, so the job signature covers it. Before it runs it re-reads the IDs it was given (`referencedIds()`) under row-level security; a missing ID fails the job without retry and logs `security.tenancy.workspace_mismatch` (IDs only). Cache keys, locks, queue names, object keys and channel names come from `TenantKey` and start with the Workspace ID; `TenantCache` discards a hit whose stored `workspace_id` differs.

Database roles (created by `docker/postgres/initdb.sh` on the first start of an empty data directory; development passwords live in `compose.yaml`, override them with `POSTGRES_<ROLE>_PASSWORD`):

| Role          | Use                                                                                                                                                                                                                                                                                                                                                         |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `migrator`    | owns every table; runs `migrate --database=migrator`; only the `migrator` service holds its password                                                                                                                                                                                                                                                        |
| `app`         | runtime login of every other service; SELECT, INSERT, UPDATE on tenant tables, never DELETE there; DELETE only on the framework's global tables (`sessions`, `password_reset_tokens`, `personal_access_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`) so logout, session GC and token revocation work; no privilege on `workspaces` |
| `maintenance` | the only role with DELETE (retention sweeps); not used at runtime yet                                                                                                                                                                                                                                                                                       |
| `system`      | the outbox relay: column grants on `outbox_events` (relay columns, UPDATE on `sent_at`) and role-limited policies; given only to `worker-compute` (`DB_SYSTEM_*`)                                                                                                                                                                                           |
| `operator`    | the operator command only: INSERT on `workspaces`, `invitations` and `operator_audit`; given only to the one-off `operator` Compose service (`DB_OPERATOR_*`)                                                                                                                                                                                               |

No role except the bootstrap superuser (`POSTGRES_USER`) has BYPASSRLS, and `dashflow:health` reports `PostgreSQL role` as failed if the runtime role could bypass it. The migrations fail with a message naming any missing role. An existing `postgres-data` volume keeps its old roles: run `docker compose down -v` once to re-create it.

Rules for code that runs in a Workspace:

- A request whose response status is 400 or above is rolled back (writes made before a 403 or 422 are not kept).
- The Workspace context is cleared before the transaction commits, so after-commit hooks run without a context and must re-enter one.
- Jobs must not rely on `SerializesModels` for tenant models: they are restored before the Workspace context is set, so under RLS they are not found. Pass IDs and re-read them (`referencedIds()`). A job with a malformed Workspace ID is a mismatch too.
- Key parts given to `TenantKey` cannot contain control characters, a `..` segment or a leading delimiter.

Residual risk: row-level security guards against application bugs, not against hostile SQL. The `app` role can call `set_config` itself and can execute `access_user_memberships`, so against SQL injection or compromised application code isolation still relies on application-layer discipline.

### Database suite

The `Database` Pest suite (`tests/Database`, `phpunit.database.xml`) runs against a throwaway PostgreSQL 18 and PgBouncer 1.26 (`compose.test.yaml`: transaction pooling, one server connection, loopback ports 55432 and 56432). It covers roles and grants, per-table RLS and leak tests, `WorkspaceTransaction`, job re-entry and interleaved clients on one pooled server connection. Because the tools container has no Docker socket, `bin/tools` starts the containers on the host through `bin/test-db` around `composer ci:check` and `composer test:database`, then removes them (`DASHFLOW_KEEP_TEST_DB=1` keeps them). If they cannot start, the command fails; nothing is skipped. The suite refuses to run (it migrates fresh and truncates) unless it targets database `dashflow_test` on the test ports; `phpunit.database.xml` forces those values over any shell `DB_*`.

```sh
bin/tools composer test:database     # only the Database suite
bin/test-db up                       # or start the containers yourself, then run composer test:database inside bin/tools
```

## Provisioning a Workspace and the first Admin

Public registration is off and no screen, route or API creates a Workspace (such requests return 404). The only way is the operator command, which runs on the `operator` database connection (`DB_OPERATOR_*`) and is never available to web or worker services:

```bash
docker compose run --rm operator php artisan dashflow:workspace:create "Acme Corp" acme admin@acme.example
```

In one operator transaction it creates the Workspace, a single-use invitation (256-bit token; only its SHA-256 hash is stored; bound to the email) and an `operator_audit` row (email as a keyed hash), and emails the link (`MAIL_MAILER=log` writes it to the log locally; the link is the only secret and is never stored or logged elsewhere). The email is sent before the transaction commits, so a failed send creates nothing. The action is then mirrored into the Workspace audit log (`platform.workspace.created`).

The command refuses to run while `DASHFLOW_INVITATION_LIFETIME` (tunable `users.invitation_lifetime`, `pending_input`) is unset. The value is a whole number of hours between 1 and 8760; there is no default. The name must not contain control characters, the label must be a unique lower-case slug (`acme-corp`), and the actor recorded in `operator_audit`, the invitation and the mirrored Workspace event is `operator:<OS user>` (`operator:unknown` when it cannot be told).

The invitee opens `/invitations/{token}` (also when signed in), enters the invited email, a name and a password (rules from `Password::defaults()`), and becomes `admin` of the Workspace with every permission of the closed `Permission` enum (`membership_permissions`; enforcement arrives with Story 1.19). For an email that already has a user (matched case-insensitively), only the membership is added: name and password stay as they are. An active member is promoted to Admin; a suspended or removed membership is never revived and is refused. The `identity.invitation.accepted` audit event says whether the membership was `created`, `promoted` or `unchanged`.

Every refusal (unknown, tampered, used or expired link, a different email, an unsupported role, an inactive membership) returns the same neutral page with HTTP 410 (`reset-expired`); responses are neutral, but latency is not guaranteed equal across refusal paths. A refusal for a known invitation records `identity.invitation.rejected` (reason only, never the token) as a security event. An unknown or malformed token names no Workspace, so there is nothing to attach an audit row to: it is logged with the reason only. Invitation responses carry `Referrer-Policy: no-referrer` and `Cache-Control: no-store`, and nginx logs the path as `/invitations/[token]`.

`app` reads `invitations` (email, token hash and Workspace are readable by it, because the accept flow looks a link up by hash) but may update only `used_at` and `updated_at`.

## Signing in

`/login` is a split-screen page: the dark hero on the left (about 55% above 1024 px, hidden below it) and the form on the right, with User and Admin role cards that relabel the single button "Sign in as User" or "Sign in as Admin". There is no SSO and no registration.

The server flow (`app/Modules/Identity`) replaces Fortify's login pipeline: it checks the email (case-insensitive) and password with Fortify's hashing, takes the active Workspace (the usable membership with the latest `last_active_at`, else the first by name, read through the Access `SECURITY DEFINER` lookup), checks the chosen area against the role in that Workspace, rotates the session ID and stores `workspace_id` and `area` (`user` or `admin`) in it, and stamps `last_active_at`. User lands on `overview` (the former `dashboard` page; `dashboard` stays as a route-name alias that redirects there), Admin on `admin.overview`. Admin without the `admin` role gets `signin-role-denied` (HTTP 422, no session) and the User card is selected; an unknown email, a wrong password and a user with no usable membership all get the same `signin-failed` (HTTP 422). The endpoint answers with catalogue keys, never wording.

Every outcome is a security event (`identity.signin.succeeded`, `identity.signin.failed`, `identity.area.denied`, `identity.signin.throttled`) in the person's active Workspace. With no Workspace to hold it (unknown email, no usable membership) a structured log line with the reason only is written. No password, raw email or token is recorded.

Throttling is per email and IP and answers HTTP 429 with `throttled`. The limits are `pending_input` tunables; while unset, Fortify's shipped 5 attempts per minute applies and Remember me adds nothing beyond the normal session lifetime:

| Variable                         | Tunable                          | Unit          |
| -------------------------------- | -------------------------------- | ------------- |
| `DASHFLOW_SIGN_IN_MAX_ATTEMPTS`  | `sessions.sign_in_max_attempts`  | attempts      |
| `DASHFLOW_SIGN_IN_DECAY_SECONDS` | `sessions.sign_in_decay_seconds` | seconds       |
| `DASHFLOW_REMEMBER_ME_DURATION`  | `sessions.remember_me_duration`  | whole minutes |

The admin overview (`/admin`) and Help & support (`/help`) are placeholders until Stories 1.16, 1.18 and 1.19.

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

OTEL variables must be real process environment variables (as in Compose) for the first request's root span to be exported; values only in `.env` start export after the framework boots. Metric names must match `dashflow.<module>.<measure>`; build them with `App\Support\Observability\MetricName`. API errors (codes are the `platform.*` cases of `App\Platform\Contracts\ErrorCode`; a status without a case now yields `platform.http_error` instead of `platform.http_<status>`) use `{"error":{"code","message","request_id","details"}}`; `details` is emitted only when a request is marked `area=admin` (request attribute set by future Admin middleware), so today it is always absent.

## Quality gates

`bin/tools composer ci:check` runs every gate below in order and stops at the first failure; each failing gate exits non-zero and names the offending file. No CI pipeline runs `ci:check` yet; it is run by hand.

| Gate                                                                                         | Command                                                       |
| -------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| Pint (code style)                                                                            | `bin/tools composer lint:check`                               |
| Larastan level 7                                                                             | `bin/tools composer types:check`                              |
| Pest: unit, feature and architecture suites (SQLite)                                         | `bin/tools vendor/bin/pest`                                   |
| Pest: `Database` suite (PostgreSQL 18 and PgBouncer, started by `bin/test-db`)               | `bin/tools composer test:database`                            |
| Architecture rules only                                                                      | `bin/tools vendor/bin/pest --testsuite=Architecture`          |
| Lint, format and type check (Vue, TypeScript)                                                | `bin/tools npm run check` and `bin/tools npm run types:check` |
| Vitest                                                                                       | `bin/tools npm test`                                          |
| Colour lint (no raw hex or `rgba()` outside the token file; banned brand colours everywhere) | `bin/tools node scripts/lint-colors.mjs`                      |
| Composer advisories (high severity and above)                                                | `bin/tools composer audit:php`                                |
| npm advisories (high severity and above)                                                     | `bin/tools npm run audit:deps`                                |
| Licence deny-list (AGPL, SSPL, BSL) for Composer and npm                                     | `bin/tools node scripts/license-audit.mjs`                    |

### Audit, outbox and error codes

Audit and outbox are kernel services in `app/Platform` (the kernel calls no module). The tables `audit_events`, `outbox_events` and `outbox_consumptions` are tenant tables with row-level security.

- `Audit::record(AuditAction, after, before, subject, actor)` and `Outbox::emit(type, subject, data)` join the caller's `WorkspaceTransaction` and throw outside one: a rollback removes both rows. The outbox envelope is `{event_id, type, v, workspace_id, subject, subject_seq, occurred_at, actor, request_id, data}`; `data` accepts only integers, booleans, null, UUIDs and short slugs. `subject_seq` is allocated under a per-subject advisory lock, so it has no gaps or duplicates.
- `AuditAction` is the closed enum of `{module}.{noun}.{past_verb}` strings (add a case when a story needs one). Each module registers an `AuditSerializer` allowlist in `AppServiceProvider`: listed IDs and enums are stored plain, other values as HMAC-SHA256 hashes (key derived from `APP_KEY`). Role `app` has INSERT and SELECT only on `audit_events`.
- `Audit::recordSecurityEvent` writes on the `security_audit` connection (a second `app` connection, `DB_SECURITY_*`) in its own transaction, so it survives a rollback. Behind PgBouncer, give that connection its own pool.
- The relay (`RelayOutboxJob`, every minute on queue `outbox`) runs as role `system` on the `system` connection, selects pending events `FOR UPDATE SKIP LOCKED`, delivers each to the `OutboxConsumers` registered by modules inside the event's Workspace, and sets `sent_at`. `ConsumerDelivery` dedupes on `(consumer, event_id)` and drops an event whose `subject_seq` is not newer than the consumer's last applied one for that subject. The `system` and `security_audit` connections must bypass the pool-size-1 PgBouncer: the relay holds row locks while it delivers and a security event needs a second server connection while the caller's transaction holds the first. Set `DB_SYSTEM_HOST/PORT` and `DB_SECURITY_HOST/PORT` explicitly (Compose points them straight at `postgres`). The relay only takes a subject's oldest unsent event, so two workers never reorder a subject, and it leaves events pending while no consumer is registered.
- Error codes are `{module}.{snake_case}` cases of each module's `Contracts/ErrorCode.php`. `bin/tools php artisan dashflow:error-codes` (or `composer error-codes`) regenerates `resources/js/types/error-codes.ts`, which is committed; a test fails when it is stale or a code does not start with its module name.

The advisory audits need network access. Architecture rules live in `tests/Architecture`: allowed module edges, table ownership and the global-table list are in `tests/Architecture/dependencies.php` (the only place to change them), and each rule has a fixture under `tests/Architecture/Fixtures` proving it fails when broken. `resources/js/pages/Welcome.vue` and `resources/js/app.ts` are temporarily allowlisted in the colour lint (Stories 1.6 and 1.16). The table-ownership and migration checks are regex-based: they do not read Eloquent `$table` properties or variable table names.

## Notes

- Public registration, email verification, two-factor and passkeys are not part of the product; `/register` returns 404.
- API routes live under `/api/v1` and authenticate with the session cookie plus the `X-XSRF-TOKEN` header. Stateful hosts come from `SANCTUM_STATEFUL_DOMAINS`.
- Do not commit `vendor/`, `node_modules/`, `.env` or the SQLite file.
