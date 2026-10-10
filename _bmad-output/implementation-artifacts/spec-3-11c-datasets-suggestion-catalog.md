---
title: 'Datasets suggestion catalog for new Drafts (Story 3.11c, backend)'
type: 'feature'
created: '2026-10-10'
status: 'done'
route: 'dispatch'
baseline_commit: 'fee2a1538e20984addc697a9a2cf52265e995509'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-11a-infer-field-roles-and-record-path-candidates.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-13c-harden-the-mapping-api-for-the-map-data-ui.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Story 3.11 requires that when an Admin picks a record path, the Dataset `(workspace, endpoint, record_path)` and its typed fields are upserted as a suggestion for new Drafts only. The `Datasets` module and its tables (`datasets`, `dataset_fields`, already named in `tests/Architecture/dependencies.php`) do not exist.

**Approach:** Create the `Datasets` module with those two tenant tables (RLS and grants like the other tenant tables), a `DatasetSuggestions` contract with `remember` (upsert) and `suggestFor` (read), call `remember` when a Draft's record path is written, and return a `dataset_suggestion` in the mapping read for a Draft that has not chosen a record path yet.

## Boundaries & Constraints

**Always:**
- Architecture rule (AD-9 area in ARCHITECTURE-SPINE.md): Datasets are a suggestion catalog for new Drafts only; the Draft's `config.field_roles`/`record_path` stay authoritative, runtime never reads Dataset rows. A suggestion is never applied automatically.
- Tables: `datasets` (id, workspace_id, endpoint_id, record_path stored RFC 9535 subset, created_at, updated_at; unique `(workspace_id, endpoint_id, record_path)`) and `dataset_fields` (id, workspace_id, dataset_id, path, type text one of the inferred types, role text one of the six roles or null, position). Follow the repo's migration, tenant-key, RLS policy, role grants and function-owner conventions exactly (see the latest tenant-table migrations and `bin/test-restore`: the restore check counts tenant tables with RLS, so update its expected count and any table-ownership or schema tests); the `Datasets` module may call only `Connector` contracts and Platform per `dependencies.php`.
- `remember`: on a successful record-path write (the `PUT .../mapping/record-path` path in `ManageDraftSlots`, same transaction), upsert the dataset for the Draft's Endpoint and the chosen record path, replacing its fields with the typed fields under that record path (path, inferred type, the Draft's effective role). Clearing the record path (null) upserts nothing. Idempotent; no Draft id or sample text stored; only field paths, types and roles.
- `suggestFor(workspace, endpoint)`: the most recently updated dataset for the Endpoint. The mapping read (`GET .../mapping`) returns `dataset_suggestion: {record_path, record_path_display, fields: [{path, type, role}], updated_at}` or null; it is returned only while the Draft has no chosen `record_path`, and `record_path` is returned in a form the candidates list can match. Never applied: do not change inference, the checklist or any stored config.
- Endpoint id comes from the Draft's chosen source (see `DraftSource`); a Draft with no endpoint gets no suggestion. Tenant isolation: another workspace's rows are invisible (RLS) and tested. Respect `tests/Architecture`, TableOwnershipTest, no `json_decode`.

**Never:** UI (3.11d); applying a suggestion; reading datasets at runtime; any other module's tables; storing sample values.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| First pick | record-path PUT `$.data[*]` | dataset and fields created for (workspace, endpoint, path) | N/A |
| Re-pick | same path again after role changes | same dataset row updated, fields replaced, `updated_at` moves | N/A |
| Another path | different record path | second dataset row for the Endpoint | N/A |
| Clear | record-path null | nothing upserted | N/A |
| New Draft | same Endpoint, no record path yet | mapping read has `dataset_suggestion` (latest) | N/A |
| Chosen path | Draft already has a record path | `dataset_suggestion` null | N/A |
| Other workspace | same endpoint id value in another workspace | not visible | RLS |
| No endpoint | Draft without a chosen Endpoint | suggestion null, nothing remembered | N/A |
| Authority | suggestion present | inference, checklist and config unchanged by it | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Blocks/Application/ManageDraftSlots.php` (`setRecordPath`, `write()`, `read()`), `DraftSource.php`, `DraftConfig.php`, `app/Http/Controllers/Admin/DraftSlotsController.php` (read response), `app/Modules/Mapping/Application/FieldRoleInference.php` (field shapes, effective roles), `SampleShapes.php`.
- `tests/Architecture/dependencies.php` (module edges, table ownership `'Datasets' => ['datasets','dataset_fields']`), `app/Providers/AppServiceProvider.php` (bindings), latest tenant-table migrations under `database/migrations`, `bin/test-restore` and its expected counts, `tests/Database` helpers (`Cluster`), `composer.json` PSR-4 if a new namespace is needed.

## Tasks & Acceptance

**Execution:**
- [ ] `database/migrations/*` -- `datasets`, `dataset_fields` with RLS, grants, constraints, unique key
- [ ] `app/Modules/Datasets/**` -- `DatasetSuggestions` contract, implementation, bindings
- [ ] `ManageDraftSlots.php`, `DraftSlotsController.php` -- remember on record-path write; `dataset_suggestion` in the read
- [ ] `bin/test-restore`, architecture/schema tests -- updated counts
- [ ] `tests/**` -- every matrix row, RLS isolation, architecture

**Acceptance Criteria:**
- Given an Admin picks a record path, when another Draft on the same Endpoint opens Map Data, then its mapping read offers that record path and typed fields as a suggestion and nothing is applied.
- Given the other workspace uses the same record path, when it reads, then it sees only its own rows.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| A catalog failure (bad Endpoint, FK, CHECK) aborts the authoritative pick | high | patch | Inputs validated; write in a savepoint with a logged, swallowed failure; tested. |
| ETag ignores `dataset_suggestion` | medium | patch | Suggestion hash folded in; tested (200 vs 304). |
| Catalog goes stale after role or type edits | medium | patch | Refreshed after role, type and undo writes; tested. |
| Empty dataset offered as a suggestion | low | patch | Skipped; tested. |
| No test with no sample, rejected PUT leaves catalog empty, isolation, DELETE refused | medium | patch | Tests added. |
| Suggestion not checked against the new sample's candidates | low | defer | UI can compare with the candidates list. |
| Unbounded dataset growth; no retention; retired field rows kept | low | defer | `app` has no DELETE by design; needs a purge job. |
| Hard-coded rollback step counts in seven test files | low | defer | Existing pattern. |
| Override-with-role-change refresh untested | low | defer | Same changed-keys check as the covered field-role write. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green, including the restore check (retry the audit step alone if Packagist times out; run the Database suite with pest directly if composer's 300s timeout hits)
