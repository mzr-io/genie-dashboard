---
title: 'Isolate each Workspace with row-level security'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: '893ea5920139d5dd5b7d890823872d7f81da0e26'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-5-split-valkey-into-queue-and-cache-stores-with-signed-jobs.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-rmg-dashboard-platform-2026-10-05/ARCHITECTURE-SPINE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Nothing stops one Workspace's queries, cache entries or jobs from reaching another Workspace's data, and the database is used through a single privileged login (Story 1.10; FR-3, NFR-4, AR-4, AR-5, AR-14, AR-54, AD-3). The Story 1.10 section of `epics.md` holds the acceptance criteria.

**Approach:** PostgreSQL row-level security on every tenant table with transaction-scoped context set only by `WorkspaceTransaction`, five database roles with least privilege, Workspace-prefixed cache, lock, queue and channel keys, and a Docker-backed `Database` Pest suite (PostgreSQL 18 and PgBouncer) that `ci:check` must run.

## Boundaries & Constraints

**Always:** Migrations run as role `migrator` (owner of the tables); the runtime uses role `app`. Tables `workspaces` (UUIDv7 key, name, label, status) and `workspace_memberships` (UUIDv7 key, non-null `workspace_id`, `user_id` to `users.id`, role `user|admin`, status, `last_active_at`) are created in migrations; `workspace_memberships` has `ENABLE` and `FORCE ROW LEVEL SECURITY` with policy `workspace_id = current_setting('app.workspace_id', true)::uuid`. `workspaces` has no `workspace_id`, so it joins the global list in `tests/Architecture/dependencies.php` (and its expectation in `MigrationGuardTest`), is read only through the Access-owned `SECURITY DEFINER` function that returns a user's memberships across Workspaces (the only cross-Workspace lookup), and `app` has no direct privilege on it. Roles: `app` (NOBYPASSRLS; SELECT, INSERT, UPDATE; never DELETE), `migrator` (owns objects, NOBYPASSRLS), `maintenance` (the only role with DELETE), `system` and `operator` (created with minimal grants; `system` column grants wait for the tables of later stories). No role except a bootstrap superuser has BYPASSRLS. `WorkspaceTransaction` (in the `app/Platform` kernel) is the only caller of `set_config(..., true)` and the only opener of the request or job transaction. A job carries `workspace_id` as a property of its serialised command (so it is covered by the job signature), re-enters context and re-reads the IDs it was given under RLS; a mismatch throws and logs the security event `security.tenancy.workspace_mismatch` (IDs only, no data). Cache keys, locks, queue names, object keys and channel names are built by one helper that prefixes the Workspace ID; a cache hit whose stored `workspace_id` differs is discarded. Models using `HasUuids` get UUIDv7 keys generated in the app. The Compose stack creates the roles through a Postgres init script, runs the `migrator` service as `migrator` and every other service as `app`, and keeps dev passwords as development values only. The `Database` suite lives in `tests/Database`, uses its own `phpunit.database.xml` with PostgreSQL settings, and the existing SQLite suites and `phpunit.xml` stay unchanged.

**Never:** Runtime use of `maintenance`, `system` or `operator`; committed real passwords; converting `users.id` to UUID (it stays bigint until the identity stories, recorded as deferred work); a test or script that skips when PostgreSQL or PgBouncer is unreachable; business tables beyond `workspaces` and `workspace_memberships`; BYPASSRLS for the app.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| No context | Query as `app`, no `app.workspace_id` | Zero rows from every tenant table | N/A |
| Cross-tenant | Context A, rows exist for B | Only A's rows, per tenant table | Test fails naming the table |
| Role limits | `app` runs DELETE or tries to bypass RLS | Permission denied; `rolbypassrls` false; only `maintenance` may delete | N/A |
| Interleaved pooling | Two clients on one PgBouncer server connection, A then B | Each sees only its own rows | Test fails on any leak |
| Job re-entry | Job with `workspace_id` and an ID from another Workspace | Hard failure and `security.tenancy.workspace_mismatch` | Job fails, not retried |
| Context source | `set_config` anywhere else in `app/` | Architecture test fails naming the file | N/A |
| Cache leak | Entry stored for A read under B, or stored `workspace_id` differs | Miss and entry discarded | N/A |
| Keys | Cache key, lock, queue name, object key, channel name | All start with the Workspace ID | N/A |
| UUID | New `Workspace` or membership | Version 7 key | N/A |
| Gate | PostgreSQL or PgBouncer cannot start | `ci:check` fails | No skip |

</frozen-after-approval>

## Code Map

- `database/migrations/` -- new migrations; existing `0001_01_01_000000_create_users_table.php` (bigint `users.id`) stays
- `app/Platform/Tenancy/` -- new `WorkspaceTransaction` (middleware and job middleware), context holder, tenant key helper, tenant cache wrapper; `app/Modules/Access/` -- `WorkspaceMembership`, `Workspace` access through the function
- `bootstrap/app.php` -- middleware registration (after `RequestContextMiddleware`); `app/Support/Queue/` and `Observability/QueueContext.php` -- job payload hooks stay beside the signer
- `config/database.php` -- add a `migrator` connection; `app` stays the default; `compose.yaml`, `docker/postgres/initdb.sh` (new) -- roles, per-service credentials, `migrate --database=migrator` in `docker/entrypoint.sh`
- `compose.test.yaml` (new), `bin/test-db` (new), `bin/tools`, `composer.json`, `phpunit.database.xml`, `tests/Database/` -- throwaway PostgreSQL 18 and PgBouncer (tag at least 1.21 and pinned; transaction mode, one server connection per pool), started and torn down around `composer ci:check` and `composer test:database`, which fail when the environment is not up
- `tests/Architecture/dependencies.php`, `MigrationGuardTest.php`, `TableOwnershipTest.php` -- global-table list and a `set_config` scan; `README.md`, `.env.example` -- roles and how to run the database suite

## Tasks & Acceptance

**Execution:**
- [x] `database/migrations`, `docker/postgres`, `compose.yaml`, `config/database.php`, `docker/entrypoint.sh` -- tables, RLS, roles and grants, `SECURITY DEFINER` lookup, Compose wiring
- [x] `app/Platform/Tenancy`, `app/Modules/Access`, `bootstrap/app.php` -- context, `WorkspaceTransaction`, job re-entry and security event, key helper, tenant cache, models with UUIDv7
- [x] `compose.test.yaml`, `bin/test-db`, `bin/tools`, `composer.json`, `phpunit.database.xml` -- Docker-backed suite wired into `ci:check`
- [x] `tests/Database/*`, `tests/Architecture/*` -- every matrix row; per-tenant-table leak test generated from the table list; update the global-table expectation
- [x] `README.md`, `.env.example` -- documentation

**Acceptance Criteria:**
- Given the Compose stack, when started, then roles exist, services connect as `app`, and `migrator` migrates.
- Given `bin/tools composer ci:check`, when run, then it starts PostgreSQL 18 and PgBouncer, runs the `Database` suite and the unchanged SQLite suites, passes, and fails if the database environment cannot start.

## Implementation Notes

## Spec Change Log

- **Review iteration 1 (intent_gap resolved by the epic AC).** Triggering finding: with `app` holding no DELETE on any table, database-session logout and garbage collection, password-reset cleanup and token revocation fail in the Compose stack (`SESSION_DRIVER=database`). The frozen text says `app` has "never DELETE"; the source requirement (Story 1.10 AC, AR-5) restricts DELETE on **tenant** tables only. The only coherent reading is: `app` may DELETE on the named global framework and auth tables (`sessions`, `password_reset_tokens`, `personal_access_tokens`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`), never on tenant tables; `maintenance` stays the only role with DELETE on tenant tables. The frozen block is left untouched and the human should confirm this reading.
- **Deliberate deviation, not a defect.** The policy uses `nullif(current_setting('app.workspace_id', true), '')::uuid` instead of the literal cast, because a pooled session keeps `''` after `set_config(..., true)` and `''::uuid` throws; behaviour is otherwise as specified (unset context returns zero rows).
- **KEEP:** the Database suite, Docker harness, `nullif` policy, the `access_user_memberships` function design and the role set.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| `app` has no DELETE on global framework and auth tables | high | intent_gap (resolved by the epic AC, see Change Log) | `initdb.sh` default privileges grant only SELECT, INSERT, UPDATE; Compose uses `SESSION_DRIVER=database`, whose `destroy()` and `gc()` run `DELETE FROM sessions`. |
| Destructive Database tests can run against whatever `DB_*` the shell exports | high | patch | `phpunit.database.xml` uses plain `<env>`; `migrate:fresh` and `TRUNCATE ... CASCADE` would hit a dev database. |
| A rendered 4xx after partial writes commits them; only 5xx rolls back | medium | patch | Laravel's route pipeline turns the exception into a response inside the middleware, so the request transaction sees a 4xx response and commits. |
| Session Workspace resolution and middleware order untested | medium | patch | The "session" test sets a request attribute, never the session key; order against `StartSession` unchecked. |
| `TenantKey::object()` and `channel()` accept `..`, leading `/` and control characters | medium | patch | `part()` rejects only an empty string, so `../<other-id>/x` escapes the prefix on path-normalising stores. |
| `REVOKE`/`GRANT` silently skipped when roles are missing | medium | patch | An existing volume or a database without the roles leaves the lookup function unusable and `workspaces` exposed with no error. |
| `DB_PGBOUNCER=true` (emulated prepares) never exercised through PgBouncer | medium | patch | The documented pooling mode has no test; the suite uses native prepares. |
| Malformed `workspace_id` in a job is retried as a poison job | medium | patch | `runJob` lets `InvalidArgumentException` retry to max tries with no security event. |
| `set_config` scan exemption matches any path ending in `WorkspaceTransaction.php`; `Cluster::truncate()` hard-coded; EXIT trap registered after `bin/test-db up` | low | patch | Anchor the exemption, generate the truncate list, register the trap first. |
| `SerializesModels` restores before context; context cleared before after-commit hooks; `app` can set the GUC itself | medium | patch (documentation) | README and docblocks are silent on these limits. |
| Default privileges fail open for future global tables | medium | defer | Needs per-table default-deny grants and a grant-list test. |
| Active Workspace not validated against membership | medium | rejected | The session key has no writer until the sign-in and switcher stories, which must validate it. |
| API group gets no Workspace | false | rejected | Sanctum's stateful SPA requests carry the session; token-only access is a later story. |
| `DB_MIGRATOR_PASSWORD` without a `DB_PASSWORD` fallback | false | rejected | Falling back would give the migrator the app's password. |
| Transaction already open guard, streamed responses, fixed ports and concurrent runs, `bin/tools` argument matching, `WorkspaceScopedJob` implementers, `referencedIds` table allow-list, tenant-key call-site enforcement, migrator DELETE, "interleaved" sequential | low | rejected | Unlikely states or conventions that later stories enforce; fixes add machinery with no consumer. |

## Design Notes

`workspaces` is global for the migration guard but its rows are only reachable through the Access function, so the guard list and the privilege model agree. `users.id` stays bigint so Story 1.10 does not rewrite authentication; the membership `user_id` is a bigint foreign key until the identity stories convert it. Tests reach Docker through `bin/tools`, which starts the test containers on the host before the PHP container runs (the PHP container has no Docker socket).

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `docker compose up -d --build` then `docker compose exec web php artisan dashflow:health web` -- expected: healthy; `app` role cannot bypass RLS
