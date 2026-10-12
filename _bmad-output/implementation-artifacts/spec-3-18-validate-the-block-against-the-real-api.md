---
title: 'Validate the block against the real API, with a ValidationReport gate (Story 3.18, wizard step 5)'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: 'ae1eb6c151178826cfdcf2cf0fb7ebb15d969c1e'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-17-fetch-and-preview-as-a-user.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-16-preview-on-every-device-and-state.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-10-render-the-sample-through-the-query-plan-engine-live-preview.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Nothing proves a mapped Draft works against the real API: there is no Step 5, no `publish_validation` Operation, no `ValidationReport`, so a pasted sample could be the only evidence before publish (Story 3.19 needs a passed report bound to the current revision).

**Approach:** Add the `publish_validation` Operation kind (registered by the Blocks module on the platform Operations kernel) and a tenant-scoped `ValidationReport` store bound to the Draft's `subject_revision`. Validation fails fast on strict-schema errors before any API call, otherwise fetches the live response through the connector, runs `QueryPlanEngine` against `config.fields`, and stores `passed`, `failed` (mismatching paths) or `stale`. Step 5 of the wizard starts it, shows the version to create ("1.0"), the result and the catalogue messages, and reports Publish readiness (the Publish action itself is 3.19).

## Boundaries & Constraints

**Always:**
- `POST /api/v1/admin/blocks/drafts/{draft}/validation` starts an Operation with `subject_revision` = the Draft's revision, behind the existing admin/lock-claim conventions and the per-membership limit (429 with `retry_after`); `GET` reads the latest report. Add routes to `ShellNavigation::ADMIN_API_ROUTES` and `AdminAccessTest`.
- Report statuses: `passed`, `failed` (lists mismatching paths and types), `unreachable` (live API not reachable: `msg:live-shape-unreachable`, Publish blocked), `stale` (subject revision moved while the Operation ran, or any later autosave bumps the revision). Stored value-free apart from paths and types; no sample text. An audit event "validated" on pass.
- Fail fast, before any API call, with the list, when the Draft has strict-schema errors (a required slot unmapped, invalid presentation, plan failing its type check).
- A pasted sample, or no real Data Source/Endpoint, is never proof: Step 5 explains that a real Endpoint must validate and Publish stays disabled.
- Endpoint with `requires_user_context`: a negative check fetches without the attribute and must be refused by the platform (no outbound fetch, `access.context_missing`); an Endpoint that could be published unbound fails validation.
- Step 5 shows the version to create ("1.0") and `msg:version-safe`; Save draft stays available whenever Publish is blocked; new copy goes through the message catalogue/labels conventions. Create a migration with RLS, tenant-table registrations and architecture/restore checks matching how other tenant tables (`draft_samples`) are declared.
- Reuse the connector fetch path (`app/Modules/Connector`), `QueryPlanEngine`, `DraftConfig` validation and the wizard step shell; no second fetcher.

**Never:** publish, version state changes, Access subject registration (3.19); preview-as-user data in a report; storing response bodies.

## I/O & Edge-Case Matrix

| Scenario | State | Expected |
|----------|-------|----------|
| Live response matches | real Endpoint, mapped, revision 12 | report passed, bound to 12, audit "validated"; version "1.0" and msg:version-safe shown |
| Autosave after pass | revision 13 | report reads stale; Publish disabled again |
| Missing path / wrong type | live response differs | failed, mismatching paths listed; Save draft available |
| API unreachable | connector fails | unreachable, msg:live-shape-unreachable, Publish blocked |
| Pasted only / no real source | sample is pasted | Publish disabled with reason; never accepted as proof |
| User-context endpoint | requires_user_context | negative check refused (access.context_missing, no fetch); unbound-publishable fails |
| Strict-schema errors | required slot unmapped | fails fast with list, no API call |
| Revision moved mid-run | autosave during Operation | stored stale, Admin told to validate again |
| Too fast | over per-membership limit | 429 with retry_after |

</frozen-after-approval>

## Code Map

- Operations kernel (`app/Platform/**`, `operations` table, how `fetch_as_user`/`sample_fetch` register kinds and run in `app/Modules/Connector/Application`), `app/Modules/Blocks/**` (DraftConfig, PreviewDraft, ManageDraftSlots), `QueryPlanEngine`, `routes/api.php`, `ShellNavigation`, `AuditAction`, `ErrorCode`.
- UI: `resources/js/pages/admin/BlockWizard.vue`, `components/wizard/PreviewStep.vue` and `WizardStepper.vue`, `lib/blockWizard.ts`; add a Validate step component and lib.
- Tests: `tests/Database`, `tests/Unit`, `tests/Architecture`, `tests/js`.

## Tasks & Acceptance

- [x] Migration + store for ValidationReport (RLS, architecture and restore checks)
- [x] `publish_validation` Operation, fail-fast, live fetch, plan run, report statuses, audit, rate limit, routes
- [x] Step 5 UI with version, result, mismatches, unreachable and pasted-only reasons
- [x] Tests for every matrix row (PHP and JS)

**Acceptance Criteria:**
- Given a real Data Source, Endpoint and mapped Draft at revision 12, when the Admin validates, then a report bound to revision 12 is stored as passed, an audit event is recorded and Step 5 shows version "1.0"; an autosave to 13 marks it stale.
- Given a missing path, an unreachable API, a pasted-only sample or strict-schema errors, then Publish stays disabled with the specific reason and Save draft stays available.

## Verification

- `bin/tools composer ci:check` -- expected: all green (run it via ci:check; test:database alone hits the 300s timeout).

## Review Triage Log

| Finding | Verdict | Route |
|---|---|---|
| Passed report survived an Endpoint edit; late older run masked the current report | high | patch |
| Absent/empty record path stored as passed; engine throw left no report | high | patch |
| Blanket Throwable catch reported as draft_gone; store not idempotent | medium | patch |
| Unbound negative fetch failures reported as unbound_publishable; RunSampleFetch code change hit every caller | medium | patch (flag) |
| UI showed an older report as this run's result; raw connector codes; unused import | medium/low | patch |
| Missing tests (lock token, fail-fast slot, strict check faults, published/discarded, endpoint edit, record path) | medium | patch |
| Unbounded report history; unbounded client date range; brittle migration --step counts; unbound_publishable only tested with a fake | low/unverified | defer |
