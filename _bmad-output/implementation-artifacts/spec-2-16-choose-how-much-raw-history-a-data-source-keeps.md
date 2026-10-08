---
title: 'Choose how much raw history a Data Source keeps'
type: 'feature'
created: '2026-10-08'
status: 'done'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-2-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-14-fetch-each-endpoint-on-a-schedule-and-keep-the-last-good-response.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-15-use-conditional-requests-and-skip-unchanged-data.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Stories 2.14 and 2.15 keep every stored body and observation forever, and an Admin cannot choose how much raw history a Data Source keeps (Story 2.16; NFR-5, AR-13, AR-5, AR-57, AD-9, UX-DR-207, UX-DR-26, UX-DR-282). The Story 2.16 section of `epics.md` holds the acceptance criteria.

**Approach:** A Retention setting on the Data Source form (`latest`, the default, or `window(N days)`), saved and audited with the Data Source, copied to its sync targets by the existing outbox consumer, and applied by a scheduled `maintenance` sweep that is the only deleter of the raw tier and never removes a target's current payload.

## Boundaries & Constraints

**Always:**
- **Setting:** `data_sources.retention_mode` (`latest|window`, default `latest`) and `retention_days` (null for `latest`; for `window` a whole number from 1 to the maximum), CHECK-kept, part of `DataSourceInput`/`DataSource` (a `Retention` value object like `Pagination`), saved with the Data Source (revision bump, soft lock, audit `connector.data_source.updated` with both fields in the allowlisted before/after). Existing rows are `latest`. A new Workspace setting is not added.
- **Maximum:** new setting `dashflow.retention.max_window_days` (env `DASHFLOW_RETENTION_MAX_WINDOW_DAYS`, `pending_input`, no default, outside the closed AR-57 `tunables` like `egress`). Unset or malformed: `window` is refused (422 `retention_days`, key `retention-window-unavailable`) and the form shows the option disabled with that reason; `latest` always works. Set: N above it is a 422 field error.
- **Copy to targets:** `sync_targets` gains `retention_mode`, `retention_days` (defaults `latest`/null). The `connector.data_source.updated` consumer sets them on every target of the Data Source (retired ones too) and registration copies them into a new target; `Connector` exposes them on `DataSource`. A changed retention therefore never changes a fetch key, `current_payload_id` or `payload_seq` (revision-keyed re-registration stays as in 2.14).
- **Sweep:** `SweepRawHistoryJob` (queue `maintenance`, `worker-compute`, scheduled every five minutes, `onOneServer`, spans Workspaces) runs each Workspace through `WorkspaceTransaction::runIsolated('maintenance', ...)` on a new `maintenance` connection (`DB_MAINTENANCE_*`, straight to PostgreSQL, given to `worker-compute` only, never `BYPASSRLS`; the compose test extends to it). Ingestion's `SweepRawHistory` decides; the `RawStore` contract `RawTierSweep` does the deletes (nobody else deletes the raw tier). Fixed batch per statement; the rest waits for the next run. Rules, per target, using that target's `current_payload_id`/`payload_seq` read in the same statement:
  - `latest`: delete observations with `seq < payload_seq` whose successor observation is older than `sync.superseded_payload_grace`; then delete bodies that are not current and have no observation. Grace unset or malformed (whole seconds): this rule is inert.
  - `window(N)`: delete observations older than N days except the one with `seq = payload_seq`; then the same body clean-up. No grace.
  - The body named by `current_payload_id` and its newest observation are never deleted by either rule. A body whose delete hits a foreign-key violation (a concurrent `put` re-stored it) is skipped until the next run.
  - Cold: a target is cold when retired (`retired_at`) for longer than `sync.cold_purge_after` (whole seconds; unset or malformed: inert). The sweep deletes its observations, bodies and the target row. An unretired target is never cold-purged, so a current payload that is the only copy survives. Hot/cold demand is Story 2.19.
- **Roles:** `maintenance` holds DELETE (and SELECT) only; `app` has no DELETE on any raw table and SELECT/INSERT only on both, never UPDATE; an UPDATE fails for every role (existing trigger). No grant changes beyond what exists.
- **Observability:** metrics `dashflow.ingestion.raw_swept{kind=observation|body|target}` carry `workspace_id` only; no body, hash or key in logs, audit or outbox. The sweep writes no audit event (the setting save is the audited act).
- **UI:** a Retention section on the Data Source form (`SegmentedControl` or radio: "Latest only", "Keep a window"; a days field when window), helper text that older data is deleted by the next sweep, inline 422 errors with focus on the first invalid field, `msg:saved` on save. Strings from the catalogue or `labels.ts`.

**Never:** Hash history or a history viewer, per-Endpoint retention, deleting a Data Source or Endpoint, hot/cold subscriptions or budgets (2.19), retry or health (2.17, 2.18), a new tunable beyond the three named, an `app` DELETE or UPDATE grant, a `SECURITY DEFINER` delete function, dropping partitions, changing `FetchTransport`, `RawStore::put`/`get`, the fence, or the fetch key, `json_decode` of bodies, a confirmation dialog on save.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Default | New or existing Data Source | `latest`, `retention_days` null | N/A |
| Choose window | `window`, N within the maximum | Saved; revision + 1; audited with before/after; targets updated by the consumer | N/A |
| No maximum | `window` while the maximum is unset | Option disabled with reason; server 422 | `retention-window-unavailable`; nothing saved |
| Out of range | N 0, negative, non-integer, above the maximum; days sent with `latest` | 422 on `retention_days` | Nothing saved |
| Superseded, latest | Grace set, two payloads, older one superseded past grace | Old observation and body deleted; current kept | N/A |
| Within grace / unset | Superseded inside grace, or grace unset | Nothing deleted | N/A |
| Window sweep | Observations older and newer than N days | Older deleted except the current payload's observation; orphan bodies deleted | N/A |
| Unchanged since long | Current payload older than N days (2.15 stores nothing on unchanged) | Current body and its observation stay | N/A |
| A, B, A | Current body re-observed after another | Current body kept; superseded observations removed | N/A |
| Retention changed | Window to latest, or N shortened | Next sweep applies the new rule; `current_payload_id` unchanged | N/A |
| Cold | Retired target past `cold_purge_after` | Observations, bodies and target deleted | N/A |
| Active, only copy | Unretired target, any age | Never purged | N/A |
| Cold unset | `cold_purge_after` unset | No purge | N/A |
| Race | `put` re-stores a body while it is swept | Delete skipped; body and new observation intact | FK violation caught |
| Roles | `app` DELETE on a raw table; any UPDATE; `maintenance` without context | Permission denied / trigger error; zero rows | N/A |
| Isolation | Two Workspaces | Sweep of one never reads or deletes the other's rows | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Connector/{Contracts/{DataSourceInput,DataSource,Retention}.php,Application/{ManageDataSources,ValidateDataSourceInput}.php,Infrastructure/{DataSourceSettings,ConnectorAuditSerializer}.php}` -- retention value object, create/update SQL and `COLUMNS`/hydration, audit allowlist, maximum read next to the ceilings
- `app/Http/{Requests/Admin/DataSourceRequest.php,Resources/DataSourceResource.php}`, `resources/js/{pages/admin/DataSourceForm.vue,lib/dataSources.ts,locales/labels.ts}` -- request/resource fields, the Retention section
- `database/migrations/` (new) -- `data_sources.retention_*` with CHECKs, `sync_targets.retention_*`; patterns from `2026_10_09_100000_add_pagination_to_data_sources.php`, `2026_10_10_100000_create_sync_targets_and_raw_tier.php` (immutability trigger, partitions, `maintenance` default grants)
- `app/Modules/Ingestion/Application/RegisterSyncTargets.php`, `Infrastructure/SyncSettings.php` -- copy retention on register and on `data_source.updated`; whole-second readers for `superseded_payload_grace` and `cold_purge_after` (both exist in `config/dashflow.php`)
- `app/Modules/Ingestion/Application/{SweepRawHistory,SweepRawHistoryJob}.php` (new), `app/Modules/RawStore/{Contracts/RawTierSweep.php,Infrastructure/PostgresRawTierSweep.php}` (new), `AppServiceProvider.php` -- sweep decision and deletes; `Ingestion => [Connector, RawStore]` already allowed
- `config/{dashflow,database}.php`, `routes/console.php`, `compose.yaml`, `.env.example`, `README.md`, `docker/postgres/initdb.sh` (no change expected) -- setting, `maintenance` connection, schedule, `DB_MAINTENANCE_*` on `worker-compute` only
- `app/Platform/Tenancy/WorkspaceTransaction.php` (`runIsolated`, unchanged) -- the Workspace context for the sweep; `tests/Architecture/{dependencies.php,ComposeSystemRoleTest,TableOwnershipTest}.php`, `tests/Feature/{SchedulerTest,DashflowTunablesTest}.php`, `tests/Database/{ScheduledFetchTest,DataSourcesTest,RolePrivilegesTest}.php`, `tests/{Unit,Security,js}/` -- extend

## Tasks & Acceptance

**Execution:**
- [x] migration -- `retention_*` on `data_sources` and `sync_targets`, CHECKs, backfill `latest`
- [x] Connector retention value object, validation, SQL, audit allowlist, `DataSourceSettings` maximum, request/resource -- the setting end to end
- [x] `config/dashflow.php` -- `retention.max_window_days` pending_input; `config/database.php`, compose, `.env.example`, README -- `maintenance` connection and its credentials
- [x] `RegisterSyncTargets` and `SyncSettings` -- copy retention to targets, read grace and cold settings
- [x] `RawTierSweep`, `SweepRawHistory`, `SweepRawHistoryJob`, schedule -- the three rules with batches and metrics
- [x] `DataSourceForm.vue`, `dataSources.ts`, `labels.ts` -- Retention section and states
- [x] tests -- every matrix row (two-connection race, role grants, RLS isolation, unset settings inert), compose test for `DB_MAINTENANCE_*`, tunables test, schedule test, js form test

**Acceptance Criteria:**
- Given a Data Source on `latest` with grace set, when a new payload becomes current and the grace passes, then the sweep removes the superseded observation and body and never the current payload.
- Given `window(N)`, when the sweep runs, then raw history older than N days is gone except the current payload, and a later change of retention takes effect on the next sweep with `current_payload_id` unchanged.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Take-over flush of refused retention untested | medium | patch (fixed) | Test added beside pagination case |
| Inertia prop retentionMaxWindowDays unverified | medium | patch (fixed) | Test added for create and edit |
| Maximum above INTEGER range gives 500 | low | patch (fixed) | Effective maximum capped at 2147483647 |
| Retention copy skipped on early return | false | rejected | Early return only for a missing Data Source; test added |
| .env.example missing POSTGRES_MAINTENANCE_PASSWORD | false | rejected | Already documented |
| Retention save re-keys targets (new fetch key, null payload pointer on active target) | medium | defer | Follows 2.14's data_source_revision keying and 2.3's revision bump; spec Design Notes document it; fix needs a product/architecture decision |
| One poison target rolls back a Workspace's whole sweep; cold-slot starvation | medium | defer | Single transaction per Workspace |
| Saved window blocks other edits when maximum later lowered or unset | medium | defer | UX grandfathering undecided |
| Sweep/put orphan-body race, 1000-row batch drain rate, overlap, observability, index cost | low | defer | Spec accepts retry next run; tuning |
| latest rule inert when grace unset; purgeTarget retired_at re-check; a11y and structure nits | low | rejected | By design (pending_input inert) or negligible |

## Design Notes

Decided here, not in the epic: **Retention lives on the Data Source and is copied to targets** because the sweep may read only Ingestion and RawStore tables (table-ownership rule), and the existing outbox consumer already fires on every Data Source save. Eventually consistent within the relay's minute; the sweep only ever sees a rule an Admin saved. **A retention save is a Data Source revision**, so by AD-7 it re-keys the targets exactly as any other edit does (name, headers); the old target is retired with its payload and the new one fetches fresh. This is existing 2.14 behaviour, not widened here. **"Cold" means retired** until Story 2.19 adds subscriptions; its hot window will redefine it. Retired targets are deleted whole, with their bodies, so they stop accumulating. **Unset settings are inert, not defaulted:** no grace means `latest` keeps superseded payloads, no cold setting means no purge, no maximum means no `window`. The last fails closed because an unbounded window is longer retention than NFR-5 allows by default. **Sweep granularity:** the "successor observation" time is when a payload was superseded; 2.15 stores nothing for unchanged bodies, so an unchanged current payload keeps its original observation. **Deletes are one statement per batch** joining `sync_targets` so "current" is judged in the same snapshot as the delete; `maintenance` has no UPDATE and cannot lock the target, so the rare `put` race is caught as a foreign-key violation and retried next run.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0, including the `Database` suite
- `bin/tools npm run build` -- expected: exit 0
