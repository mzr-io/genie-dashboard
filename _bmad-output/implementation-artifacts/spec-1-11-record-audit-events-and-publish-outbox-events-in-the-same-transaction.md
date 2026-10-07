---
title: 'Record audit events and publish outbox events in the same transaction'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: '967e501d67c79cce325a6e15140f67e149168392'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-1-10-isolate-each-workspace-with-row-level-security.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-rmg-dashboard-platform-2026-10-05/ARCHITECTURE-SPINE.md'
  - '{project-root}/_bmad-output/planning-artifacts/architecture/architecture-rmg-dashboard-platform-2026-10-05/SOLUTION-DESIGN.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Sensitive changes and denials leave no trace, and there is no safe way to tell other modules that something changed (Story 1.11; FR-5, NFR-4, AR-25, AR-36, AD-18, AD-29). The Story 1.11 section of `epics.md` holds the acceptance criteria.

**Approach:** Kernel services `Audit` and `Outbox` in `app/Platform` write tenant tables with row-level security inside the caller's transaction, a separate-connection path for security events that survive a rollback, a relay running as database role `system`, idempotent consumers, and a generated TypeScript enum of module error codes.

## Boundaries & Constraints

**Always:** Tables `audit_events`, `outbox_events` and `outbox_consumptions` are tenant tables (non-null `workspace_id`, `ENABLE` and `FORCE ROW LEVEL SECURITY`, UUIDv7 keys) created as `migrator`. `Audit::record` and `Outbox::emit` join the caller's open transaction (a rollback removes both rows) and refuse to run outside one. The outbox envelope is `{event_id, type, v, workspace_id, subject, subject_seq, occurred_at, actor, request_id, data}`; `data` accepts only integers, booleans, null, UUID strings and short enum-style slugs, and anything else throws. `subject_seq` increases by one per `(workspace_id, subject)` with no gaps or duplicates under concurrency. `AuditAction` is a closed backed enum whose values follow `{module}.{noun}.{past_verb}` (lowercase, three dot-separated parts); an unknown string throws, and a test checks the grammar of every case. Each module registers an `AuditSerializer` allowlist: only listed fields are stored, IDs and enums plain, every other attribute, header or sample value as a keyed hash (HMAC-SHA256 with a key derived from `APP_KEY` by a domain-separated HMAC, as the job signer does). `Audit::recordSecurityEvent` writes through a second database connection in its own transaction that sets its own Workspace context, so the row survives a rollback of the caller's transaction. Role `app` has INSERT and SELECT but no UPDATE or DELETE on `audit_events`. The relay runs as role `system` through a `system` connection configured from `DB_SYSTEM_*` (given only to the `worker-compute` service): it selects pending events with `FOR UPDATE SKIP LOCKED` on queue `outbox`, delivers each once to registered consumers inside that event's Workspace context, and sets `sent_at`; `system` gets column-limited grants (relay columns of `outbox_events` only) and a policy limited to its role, never BYPASSRLS. A consumer records `(consumer, event_id)` in `outbox_consumptions`, ignores a redelivery, and drops an event whose `subject_seq` is not newer than the last one it applied for that subject. Error codes are `{module}.{snake_case}` backed-enum cases in each module's `Contracts/ErrorCode.php`, generated into `resources/js/types/error-codes.ts` by an Artisan command; the generated file is committed and a test fails when it is stale or a code does not start with its owning module name. Dev passwords stay development values only.

**Never:** Runtime use of roles `maintenance` or `operator`; audit log screens or retention sweeps (Epic 8); tamper-evident hash chains; business events beyond a seed set of audit actions already named in Epic 1 (extend the enum when later stories need more); raw values or secrets in audit or outbox rows; BYPASSRLS for any role.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Same transaction | Command records audit and emits an event, then fails | Both rows absent after rollback; both present after commit | N/A |
| Outside a transaction | `Audit::record` or `Outbox::emit` with no open transaction | Throws | Nothing stored |
| Bad action | A string that is not an `AuditAction` value | Throws | Nothing stored |
| Enum grammar | Every `AuditAction` case | Matches `{module}.{noun}.{past_verb}` | Test names the case |
| Hashed values | Serializer input with an unlisted field and a free-text value | Unlisted field dropped; value stored as a keyed hash, never raw | N/A |
| Security event | `recordSecurityEvent` inside a transaction that rolls back | Row persists | N/A |
| Append-only | `app` runs UPDATE or DELETE on `audit_events` | Refused; INSERT and SELECT succeed | N/A |
| Bad payload | Event `data` holding free text or an object | Throws | Nothing stored |
| Sequence | Concurrent emits for one subject | `subject_seq` 1..n, no gaps or duplicates | N/A |
| Relay | Pending events, two relay workers | Each delivered once, `sent_at` set, no double delivery | Failed delivery stays pending |
| Redelivery | Consumer sees the same `event_id` twice | Second is ignored | N/A |
| Stale event | `subject_seq` not newer than the last applied | Dropped | N/A |
| Cross-tenant | Relay delivers events of two Workspaces | Each handled in its own context; no cross-read by `app` | N/A |
| Error codes | A code not starting with its module name, or a stale generated file | Test fails | N/A |

</frozen-after-approval>

## Code Map

- `app/Platform/` -- kernel (from Story 1.10: `Tenancy/WorkspaceTransaction`, `WorkspaceContext`); add `Audit/`, `Outbox/`, `Contracts/`; the kernel calls no module, modules register serializers and consumers with it
- `app/Modules/Access/` -- existing module; add its `Contracts/ErrorCode.php` and an audit serializer; `app/Support/Observability/ApiErrorRenderer.php` -- existing `platform.*` codes move to a Platform `ErrorCode`
- `database/migrations/` -- new migrations beside the Story 1.10 ones; `docker/postgres/initdb.sh` -- `system` role exists; add its grants in the migration, not the init script
- `config/database.php`, `compose.yaml`, `.env.example` -- `system` connection (`DB_SYSTEM_*`) for `worker-compute` only; `routes/console.php` -- schedule the relay
- `tests/Database/` -- Story 1.10 Docker-backed suite and `Cluster` helpers; add audit, outbox, relay and consumer tests; `tests/Unit/` -- enum grammar, serializer, envelope and generator tests; `tests/Architecture/dependencies.php` -- table ownership already lists the three tables for the kernel
- `resources/js/types/` -- generated `error-codes.ts`; `composer.json` -- script for the generator
- `epics.md` Epic 1 stories 1.13 onward -- audit actions named there (for example `identity.invitation.accepted`, `access.admin.denied`) seed the enum

## Tasks & Acceptance

**Execution:**
- [x] `database/migrations`, `config/database.php`, `compose.yaml`, `.env.example` -- tables with RLS, privileges, `system` grants and policy, connection and credentials
- [x] `app/Platform/Audit`, `app/Platform/Outbox`, `app/Platform/Contracts`, `app/Modules/Access` -- `Audit`, `AuditAction`, `AuditSerializer` registry, `Outbox`, envelope and validator, relay job, consumer contract with dedupe and `subject_seq` drop, error-code enums and generator
- [x] `routes/console.php`, `bootstrap/app.php` -- relay schedule, provider wiring
- [x] `tests/Database/*`, `tests/Unit/*`, `tests/Architecture/*` -- every matrix row; stale-generated-file test
- [x] `README.md` -- audit, outbox and error codes

**Acceptance Criteria:**
- Given a domain change in a transaction, when `Audit::record` and `Outbox::emit` run and it commits, then both rows exist with the full envelope; when it rolls back, neither exists.
- Given `bin/tools composer ci:check`, when run, then it passes, including the Docker-backed `Database` suite.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Two relay workers deliver a subject's events out of order; seq 1 is then dropped as stale and marked sent, so it is lost | high | patch | `OutboxRelay` uses `SKIP LOCKED` per row; `$blocked` is per batch; `ConsumerDelivery` marks Stale as handled. Breaks "delivered once" and per-subject ordering. |
| Relay marks events sent when no consumer is registered | high | patch | `OutboxConsumers` starts empty; the delivery loop is empty and `sent_at` is still set, so later consumers never see earlier events. |
| `ConsumerDelivery` stale check (`max(subject_seq)` then insert) takes no per-subject lock | medium | patch | Two deliveries of one consumer and subject can both read the old maximum. |
| `subject` regex allows 69 characters into `varchar(64)`; `request_id` over 64 characters aborts the business transaction | medium | patch | Validation passes, then the insert fails inside the caller's transaction. |
| `recordSecurityEvent` fails for a Workspace that does not exist or an unavailable audit database, turning a 403 into a 500 | medium | patch | Separate connection cannot see an uncommitted Workspace; no fallback or log. |
| Poison events at the head of the queue starve newer events; failure log keeps only the class | medium | patch | 100 permanently failing rows fill the batch; nothing records attempts or the message. |
| Missing partial index for the pending scan | low | patch | `WHERE sent_at IS NULL ORDER BY occurred_at, subject_seq` scans a growing table. |
| `RelayOutboxJob` has no overlap, timeout or loop cap | medium | patch | Overlapping runs feed the ordering bug. |
| `ApiErrorRenderer` collapses unmapped statuses to `platform.http_error` | medium | patch | Unmapped 418, 503 and so on lose the status; the status is already in the HTTP response, but the code mapping is untested. |
| Relay job and schedule, status-to-code mapping, IP and user-agent hashing, identity serializer and `AuditHasher` normalisation untested | medium | patch | Tests call `OutboxRelay` directly, run audit only in the console, and use their own registries. |
| `DB_SYSTEM_*` only for `worker-compute` is not enforced; compose docs and `POSTGRES_SYSTEM_PASSWORD` in `.env.example` | low | patch | A compose test follows the existing `ComposeKeysTest` pattern; `.env.example` omits the variable. |
| Spec Code Map lists `bootstrap/app.php` and `tests/Architecture/*` as touched | low | rejected | Planning list only; no change was needed. |
| Actor not derived from the authenticated user; event types reuse `AuditAction`; `AuditHasher` key versioning; allowlist typos dropped silently; helper-name collisions; duplicated slug regex | low | rejected | Later stories pass the actor and add event types; versioning and strictness are beyond the story criteria. |
| Batching `UPDATE`s, generator for multi-word modules, idempotent provider registration, `--check` in `ci:check` | low | rejected | Performance and polish with no failing scenario now. |

## Design Notes

Postgres has no autonomous transactions, so security events use a second connection. The relay is the only runtime user of role `system`; its policy is `TO system` with column grants, so `app` still cannot read across Workspaces. Audit hashing reuses the job-signer key derivation pattern to avoid a new key purpose. The generated TypeScript enum is committed and checked by a test because the PHP-free Node build cannot run the generator.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools composer test:database` -- expected: exit 0
