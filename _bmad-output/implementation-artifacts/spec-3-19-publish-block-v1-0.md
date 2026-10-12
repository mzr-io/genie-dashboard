---
title: 'Publish block v1.0 from the validated Draft (Story 3.19)'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '21dcbb9e0c94f0ada2c582b654ecc298229643e3'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-18-validate-the-block-against-the-real-api.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-15a-presentation-rules-backend.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A validated Draft cannot become a Block Library version: `block_versions.state` allows only `draft` and `discarded`, the wizard's Publish button is always blocked, and there is no activation, immutability guard, Access subject registration or Published list.

**Approach:** Add the publish lifecycle (draft → prepared → published) in two transactions, an immutability trigger, the maintenance job that activates a version left prepared, Access-subject registration with the default "All users", the minimal Admin > Published blocks list, and the Step 5 Publish action gated by the `blocks.publish` permission and a passed, current-revision `ValidationReport`.

## Boundaries & Constraints

**Always:**
- `POST /api/v1/blocks/drafts/{id}/publish` (admin API conventions; add to `ShellNavigation::ADMIN_API_ROUTES` and `AdminAccessTest`): Tx1 `UPDATE block_versions SET state='prepared' WHERE state='draft' AND revision = report.revision`, emits `blocks.version.prepared`, and freezes `config` (`schema_version`, `type_key`, `contract_version`, `fields` snapshot with roles, `query_plan`, `presentation`, `chrome`, sizes, period behaviour, refresh interval) as the immutable version; the pinned Endpoint revision is recorded on the version. Tx2 (`ActivateVersion`) sets `state='published'`, switches `blocks.current_version_id`, writes the audit event and emits `blocks.version.published`. No results precompute in this epic; activation follows immediately.
- Without `blocks.publish`: Publish is disabled with `msg:perm-publish`; a direct API call is 403 with an audited denial; Save draft still works.
- A stale or missing report → 409, nothing changes. An autosave after Tx1 gets 409 `blocks.draft_published`; the immutable version is untouched.
- A version left `prepared` beyond `precompute_timeout` (a `pending_input` setting) is activated by the maintenance job (call `ActivateVersion`). A database trigger rejects every UPDATE of `block_versions` except draft content edits, draft→prepared and prepared→published (the state check constraint gains `prepared` and `published`).
- On commit the Block is registered as an Access subject in the same transaction with default access "All users" (no grant keyed by version; groups and access changes are Epic 6), and the Draft's `draft_samples` (and report-bound blobs) are deleted.
- Wizard on success: toast `msg:publish-success` ("{name} v1.0 is live in the Block Library under {category}.") and navigation to Admin > Published blocks with the new row highlighted and focused, listing name, category, type, version, owner, last updated, status "Published". A second Draft for the same Block can later be created for v1.1 (publish impact, restore, unpublish are Epic 6).
- Follow module boundaries/architecture tests, RLS and tenant-table registration, message catalogue/labels, audit and outbox conventions.

**Never:** results precompute, per-version access grants, groups/restore/unpublish (Epic 6), changing the validated config between report and freeze, versions other than 1.0 in the UI.

## I/O & Edge-Case Matrix

| Scenario | State | Expected |
|----------|-------|----------|
| Publish | passed report at current revision, blocks.publish | prepared then published; current_version_id set; access subject All users; samples deleted |
| No permission | lacks blocks.publish | disabled with perm-publish; API 403 + audited denial |
| Stale/missing report | revision moved or no report | 409, nothing changes |
| Autosave after Tx1 | draft saved post-prepare | 409 blocks.draft_published; version untouched |
| Prepared too long | prepared past precompute_timeout | job activates it |
| Illegal update | UPDATE of a published version | rejected by trigger |
| Success | commit | toast and Published blocks list with row focused |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_17_100000_create_blocks_and_drafts.php` (state check), `app/Modules/Blocks/**` (ManageDrafts, StartDraftValidation/ValidationReport from 3.18, Draft contracts), `app/Modules/Access/**` (subject registration, Permission `blocks.publish`), maintenance jobs (see how `PurgeDiscardedDrafts` is scheduled), `config/dashflow.php` tunables, `routes/api.php`, `ShellNavigation`, `AuditAction`, outbox events.
- UI: `pages/admin/BlockWizard.vue`, `components/wizard/ValidateStep.vue`, `pages/admin/DraftBlocks.vue` (model for a Published blocks page), `GatedAction.vue`.

## Tasks & Acceptance

- [x] Migration: states, immutability trigger, version pin columns as needed, access subject registration hook
- [x] Publish (Tx1 + ActivateVersion), maintenance activation, draft_samples cleanup, audit/outbox
- [x] Published blocks page and Step 5 Publish action with permission gating and success toast
- [x] Tests for every matrix row (PHP and JS); architecture and restore checks stay green

**Acceptance Criteria:**
- Given a passed report for the current revision and `blocks.publish`, when the Admin publishes, then the version is frozen and published, the Block is an Access subject for All users, samples are deleted and the Published list shows the row focused with the success toast.
- Given a stale report, missing permission, or an autosave after Tx1, then nothing changes and the API answers 409/403/409 as specified.

## Verification

- `bin/tools composer ci:check` -- expected: all green (use ci:check; the database suite alone hits composer's 300s timeout).

## Review Triage Log

| Finding | Verdict | Route |
|---|---|---|
| Retry after a failed activation was a dead end; Tx2 failure surfaced as 500/404 | high | patch (re-run idempotent activation; `blocks.publish_pending`) |
| Job activation lost the publisher identity | medium | patch (`prepared_by_membership_id`) |
| Frozen config could hold null roles/types; unknown block type reported as stale | medium | patch (`fields_unresolved`, `block_type_unavailable`) |
| Endpoint revision not locked at freeze; older prepared version could take back current_version_id; unique violation 500 | medium | patch |
| Null owner/category, double submit, size keys, empty maps as objects | low/medium | patch |
| Missing tests (lock token, activation idempotency, scheduler, frozen values, retry) | medium | patch |
| Published list unpaginated and un-throttled; no DELETE guard or destructive down(); highlight after a retried load; 429 and list ordering tests; report blobs not purged on publish | low/unverified | defer |
