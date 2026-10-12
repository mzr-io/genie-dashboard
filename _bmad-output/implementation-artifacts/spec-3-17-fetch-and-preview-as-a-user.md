---
title: 'Fetch and preview as a user in the Create block wizard (Story 3.17)'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: 'e2767a53d13a0d7fe53d6b700fff62bd801bbf5d'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-16-preview-on-every-device-and-state.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-10-render-the-sample-through-the-query-plan-engine-live-preview.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Epic 2 built the `fetch_as_user` Operation (`app/Modules/Connector/**`: StartFetchAsUser, RunFetchAsUser, FetchesAsUser, audit actions), but the Create block wizard offers no "Fetch as user" in Step 2 or "Preview as" in Step 4, so an Admin cannot check per-user data.

**Approach:** Wire the existing Operation into the wizard: Step 2 "Fetch as user" (pick a user or a group member) and Step 4 "Preview as", gated by the `data.preview_as_user` permission; the preview is computed from the Operation's ephemeral result, labelled with the chosen user's name and "Sample data", and reverts to the shared sample after expiry with a notice. Reuse the existing endpoints, Operation polling and preview/Query Plan paths; add only the glue the existing API lacks.

## Boundaries & Constraints

**Always:**
- Without `data.preview_as_user`, "Fetch as user" and "Preview as" are disabled with the reason; an API attempt is 403 plus an audited security event (verify the existing path does this; add it if missing).
- The user's attribute values never reach the browser; the response lives only as the Operation's encrypted Valkey blob with a short TTL, visible only to the initiator; never written to `draft_samples` or the shared Draft; only value-free shape (paths and types) is kept. One audit event per use and the target-user notification emitted as an event (Epic 8 delivers it).
- A user whose bound attribute is missing: no request is made; item `unavailable` with reason `access.context_missing` and the Admin sees why.
- Step 4 labels the preview with the chosen user's name and "Sample data"; after the Operation expires it reverts to the shared sample and says so.
- Previewing as oneself is allowed only through this same audited path; editing own attributes/permissions is out of scope.
- Follow wizard conventions: lock claim, catalogue/labels, tokens, accessibility, existing preview path (no second renderer).

**Never:** new Operation types, storing the per-user response in Draft tables, sending attribute values to the client, Step 5 or publish.

## I/O & Edge-Case Matrix

| Scenario | State | Expected |
|----------|-------|----------|
| Permitted fetch as user | bindings exist, permission held | sample for that user in the tree, no attribute values in responses |
| No permission | lacks data.preview_as_user | controls disabled with reason; API 403 + audited event |
| Missing bound attribute | user lacks attribute | no request; unavailable access.context_missing shown |
| Preview as | fetch succeeded | Step 4 preview from ephemeral blob, labelled user name + Sample data |
| Expiry | Operation expired | preview reverts to shared sample with a notice |

</frozen-after-approval>

## Code Map

- Backend: `app/Modules/Connector/{Application/{StartFetchAsUser,RunFetchAsUser,ReadSample},Contracts/FetchesAsUser}.php`, `routes/api.php`, `app/Platform/Audit/AuditAction.php`; preview: `app/Modules/Blocks/Application/PreviewDraft.php`, `DraftPreviewController`.
- UI: `resources/js/components/wizard/{DataSourceStep,SampleSection,PreviewStep}.vue`, `lib/{blockSource,blockSample,blockPreview}.ts`, `pages/admin/BlockWizard.vue`.
- Tests: Database (`tests/Database`), Unit and `tests/js` beside existing ones.

## Tasks & Acceptance

- [x] Investigate what the existing API already exposes; add only the missing glue (permission gate, preview-from-ephemeral-result, expiry fallback)
- [x] Step 2 "Fetch as user" and Step 4 "Preview as" controls with reasons, labels and expiry notice
- [x] Tests for every matrix row (PHP and JS)

**Acceptance Criteria:**
- Given bindings and the permission, when the Admin fetches as a user, then they see that user's sample shape without attribute values reaching the browser and the preview is labelled with the user's name and "Sample data".
- Given no permission, then the controls are disabled with the reason and an API attempt is 403 with an audited event.

## Verification

- `bin/tools composer ci:check` -- expected: all green (run the Database suite through ci:check; composer test:database alone hits the 300s timeout).

## Review Triage Log

| Finding | Verdict | Route |
|---|---|---|
| Non-user sample_fetch Operation readable as a member's data | high | patch |
| Preview ids not tied to the Draft's config.source | medium | patch |
| Endpoint revision mixed across two transactions | medium | patch |
| Members filter after paging; shared data under "previewing as" label; stuck running phase; 403 misclassified; notification copy; 429 copy | medium/low | patch |
| Missing tests (fixed/date params, 429, inactive members, kind, mismatch) | medium | patch |
| Blob not forgotten on clear; reads not audited; preview-as needs a shared sample for user-bound endpoints; no group-first picker; 409 draft_published untested (unreachable before 3.19) | low/unverified | defer |
