---
title: 'Start, save and discard a Draft block'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: '43574f6ef22dda367ff36bb34d7a1570df9b24c2'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-1-register-the-block-type-contract-v1-and-the-kpi-card-package.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-2-8-prevent-overwriting-with-a-soft-lock.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Admins cannot start a block, keep progress safely or see their Drafts, and nothing stops two people overwriting each other's work.

**Approach:** Add the `Blocks` module with `blocks` and `block_versions` (state `draft`), `draft_samples` and `draft_ui_state` tables under RLS; admin API to create, save (compare-and-set on `revision` and `lock_epoch`) and discard a Draft; replace the Draft blocks placeholder with a list page.

## Boundaries & Constraints

**Always:** Module `app/Modules/Blocks` (already in dependencies.php; add `draft_ui_state` to its tables). Tables carry non-null `workspace_id`, UUIDv7 keys, `ENABLE`+`FORCE` RLS, workspace_isolation policy; app role gets no DELETE. Create: Block + Draft (`revision` 1, `lock_epoch` 1, prospective version "1.0") and audit `blocks.draft.created` in one transaction; block type must be a registry key. Partial unique index on `block_versions (block_id) WHERE state='draft'`; second Draft → 409 `blocks.draft_exists` with current Draft ID. Save: `expected_revision` mismatch → 409 with current state, nothing written; matching revision with stale `lock_epoch` → 423 `platform.edit_lock_lost` (check lock first, as Data Source does); success bumps `revision`, no audit per autosave. Lenient draft schema accepts name + type only. Secret-valued fields → 422 and nothing stored (reuse `RejectsSecretValues` on create and save routes). **Discard (decided by the user):** the audited web transaction sets `state='discarded'` (app UPDATE), after which saves return 404 and the list hides it; a queued signed job on worker-compute using the `maintenance` role physically deletes the draft, its `draft_samples` and `draft_ui_state`, per Workspace under RLS. Web never receives maintenance credentials. Audit `blocks.draft.discarded` with an allowlist `BlocksAuditSerializer`. Routes require `blocks.edit` (map in ShellNavigation); denials audited by the `admin` middleware; page shows `perm-denied` for 403. List (Admin > Draft blocks, replacing the placeholder): accessible `data-table` with name, category, type, version, owner, last updated, "Draft" badge, search, count; each row opens the wizard at the step in `draft_ui_state` (wizard itself is Story 3.4, link target only); only Drafts. Discard needs a confirm dialog. Register the Blocks `ErrorCode` enum and regenerate error-codes.ts. Update Cluster seeders and tenant-leak/role-privilege tests.

**Never:** No wizard UI, locking endpoints (Story 3.6), autosave client (3.5), publish or prepared/published states, Published list, validation schema. No web DELETE grants. No per-autosave audit.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Create | name + `kpi-card`, `blocks.edit` | 201, Block + Draft v"1.0", revision 1, audit row | unknown type 422 |
| Second draft | Block already has a Draft | none created | 409 `blocks.draft_exists` + Draft ID |
| Stale revision | Draft rev 4, `expected_revision` 3 | 409 with current state | nothing written |
| Stale epoch | rev 4 ok, epoch behind | 423 `platform.edit_lock_lost` | nothing written |
| Save | rev 4, epoch 1 | 200, revision 5, no audit | N/A |
| Secret field | token/password in payload | none | 422, nothing stored |
| Discard | confirm | state discarded, audit, later save 404, job deletes rows | already discarded 404 |
| No permission | no `blocks.edit` | page `perm-denied`, API 403 | audited |
| Isolation | no workspace context | zero rows | N/A |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_16_100000_create_sync_generations.php`, `2026_10_08_120000_add_lock_epoch_to_data_sources.php` -- RLS migration and lock_epoch trigger patterns; `docker/postgres/initdb.sh` default grants (app S/I/U, maintenance S/D)
- `tests/Architecture/dependencies.php`, `tests/Database/Support/Cluster.php` (`seedTenantRow`), `tests/Database/{RolePrivilegesTest,TenantLeakCoverageTest}.php` -- registries to update
- `app/Platform/EditLock/*`, `app/Modules/Connector/Infrastructure/DataSourceLockEpochs.php`, `app/Providers/AppServiceProvider.php:~265` -- register a `draft_block` LockEpochs
- `app/Modules/Connector/Application/ManageDataSources.php` (update ~154-170), `app/Http/Controllers/Admin/DataSourceController.php`, `app/Http/Requests/Admin/DataSourceRequest.php`, `app/Http/Responses/AdminApiError.php` -- write path, 409/423 precedent
- `app/Platform/Audit/AuditAction.php`, `app/Modules/Connector/Infrastructure/ConnectorAuditSerializer.php`, `AppServiceProvider.php:~253` -- audit enum + serializer
- `app/Http/Middleware/RejectsSecretValues.php` -- reuse; currently hardcodes Connector error code, generalise or add `blocks.secret_values_refused`
- `app/Modules/Ingestion/Application/SweepRawHistory.php`, `config/database.php` (maintenance), signed jobs (Story 1.5) -- worker deletion precedent
- `routes/web.php:61-65` (placeholder `admin.blocks.drafts`), `routes/api.php`, `app/Http/Navigation/ShellNavigation.php` -- routes + permission mapping
- `resources/js/pages/admin/DataSources.vue`, `lib/dataSources.ts`, `components/{DataTable,ConfirmDialog}.vue`, `tests/js/data-sources.test.ts` -- list page, confirm, tests precedent
- `app/Modules/BlockTypes/Contracts/BlockTypeRegistry.php` -- validate block type

## Tasks & Acceptance

**Execution:**
- [ ] migration(s) + `app/Modules/Blocks/**` -- tables, models/services, ErrorCode, audit actions + serializer, lock epochs, discard job
- [ ] routes, controller, requests, resources, ShellNavigation -- create/save/discard/list API; page route
- [ ] `resources/js/pages/admin/DraftBlocks.vue` + lib + locale labels -- list page, search/count, discard confirm
- [ ] regenerate `resources/js/types/error-codes.ts`; update Cluster/leak/privilege/arch registries
- [ ] Tests: Database (create, 409s, 423, save, secret 422, discard + job, RLS, audit, perm denial), Unit (serializer/actions), Vitest (list states, perm-denied, discard)

**Acceptance Criteria:**
- Given the I/O matrix rows, when exercised, then each outcome holds.
- Given the full suite, when `bin/tools composer ci:check` runs, then it passes.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route / evidence |
|---|---|---|
| Purge leaves orphan `blocks` rows with no versions | medium | patch: purge also deletes blocks left with no versions |
| `DraftLockEpochs` untested (state filter, no revision bump, registry) | medium | patch: add Database test |
| Request name max 400 vs domain/DB 120 | low | patch: use `Drafts::NAME_MAX` |
| `RejectsSecretValues` unknown module silently uses Connector code | low | patch: throw on unknown module |
| Discard double-activation shows false 404 toast | low | patch: in-flight guard |
| Stale step-count comments in rollback tests | low | patch: direct correction |
| Discard has no revision/lock guard or owner check; type change via save unaudited | low | defer: spec silent; locking arrives in 3.6, wizard in 3.4 |
| Unique-index race path in create untested | low | defer: needs a concurrency seam |
| List unbounded, no pagination; purge batch/tries; focus after last discard; domain messages not in catalogue | low | reject: no demonstrated harm at MVP scale |
| Maintenance grants missing | false | default privileges in initdb.sh grant S/D; purge test passes |
| `DraftNotFound` after insert unhandled | false | row was just inserted in the same transaction |
| No create/save UI clients; migration date | false | wizard and autosave are Stories 3.4/3.5; date follows the 2026_10_16 sequence |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green
