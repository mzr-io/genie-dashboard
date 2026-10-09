---
title: 'Choose the Data Source, Endpoint, parameters and refresh (step 2)'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: '7d528da03a7931d9d15e44ac7ae74a96616cb695'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-4-configure-block-step-1-with-a-live-preview.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-5-autosave-continuously-and-recover-from-expiry-offline-and-save-failure.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-6-soft-lock-a-draft-and-take-over-editing.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Wizard step 2 is the generic "later step" placeholder, so a Draft cannot say which Data Source/Endpoint it reads, how parameters bind, how often it refreshes or its default date range.

**Approach:** Build step 2 per Story 3.7 (epics.md "### Story 3.7"; ACs there are authoritative and are the I/O matrix): Data Source select with health dot + word, Endpoint list (`GET /path`), "Edit data source" for `data_sources.manage`, POST read-only checkbox gating Fetch/Continue, parameters table (Fixed / Date Range from,to / Block Period Selector start,end only when `config.display.period` is `own_selector` / User context chip), resolved values in mono muted text, empty required path param blocks Fetch naming it, "shared data" badge when no user-context binding, Refresh Interval and Default date range selects, no-Data-Source state (`datasource-none`), and out-of-date marking with pinned revisions.

## Boundaries & Constraints

**Decisions (made by the builder from repo precedent; record, do not re-litigate):** (1) Step-2 data is stored in the Draft's lenient `config` JSON under a new whitelisted `source` key in `DraftConfig::normalise` (data_source_id, endpoint_id, endpoint_revision_id, data_source_revision, bindings values, refresh_interval, default_date_range {preset|custom from/to}, sample_out_of_date); no migration; values type-checked, nothing required (publish checks are 3.18). Blocks reads Connector only via Contracts; on save the server resolves and pins endpoint_revision_id and data_source_revision itself (client never supplies them) and refuses an Endpoint not belonging to the Data Source in the workspace. Expose the Endpoint `revision_id` and the Data Source revision pin through the existing resources/contracts as needed. (2) Refresh intervals come from the `dashflow.tunables.sync.refresh_intervals` pending_input tunable via a new read-only admin endpoint (or shared prop); unset/malformed → no intervals offered, with an inline reason, never an invented list; Live is offered only as its own disabled/enabled option per the Data Source `live_capable` flag with `live-not-supported` as description when off, and no new numeric value is invented for it. No default refresh interval or default date range is preselected. (3) "Affects the request" = Data Source, Endpoint, or any binding/param value changed; the client sets `sample_out_of_date` true in the Draft's source config when a sample exists or later appears (no sample store yet: the flag is set on any such change and cleared only by Stories 3.8/3.9); Continue past step 2 requires nothing about samples yet beyond showing the out-of-date notice. (4) A degraded or unreachable Data Source is selectable (status is shown). (5) Custom default date range is two plain date inputs (from ≤ to). (6) Bindings are per-Block values only for Fixed rows; bound rows display the Endpoint's resolution read-only. Secrets/header values never enter the wizard payload.

**Always:** Reuse StatusDot/DataSourceHealth, NativeSelect, GatedAction (`data_sources.manage`), Banner, existing labels (`post-readonly`, `live-not-supported`, `datasource-none`, `userContextTag`, `sharedData`); mirror EndpointForm's parameters table and POST checkbox pattern; Fetch is rendered (aria-disabled with reasons) but does nothing (3.9); step-2 changes ride the existing autosave/CAS/lock/offline machinery (`deltaOf`, `SaveBody`, `PendingDelta`); add labels to the catalogue; accessibility per existing wizard (aria-disabled with reasons inline, focus).

**Never:** No sample fetching/paste/mapping (3.8-3.12), no publish checks (3.18), no new tunable defaults or invented numbers, no Data Source/Endpoint form behaviour change, no secrets in payloads, no lock/autosave semantic change.

## I/O & Edge-Case Matrix

The Given/When/Then ACs of Story 3.7 in `_bmad-output/planning-artifacts/epics.md` ("### Story 3.7") are the matrix; every one must be covered by a passing test, plus: unset refresh tunable → inline reason; Endpoint from another Data Source/workspace → 422/404 and nothing stored; `source` secret-valued key → refused.

</frozen-after-approval>

## Code Map

- Wizard: `resources/js/pages/admin/BlockWizard.vue` (`later-step` section ~1273, `deltaOf`, `save`), `lib/blockWizard.ts` (`DraftConfig`, `StepForm`, `SaveBody`), `components/wizard/*`, `lib/wizardPending.ts`, `locales/labels.ts` (steps ~906)
- Server: `app/Modules/Blocks/Application/DraftConfig.php` (whitelist), `ManageDrafts.php`, `DraftController.php`, `app/Http/Requests/Admin/DraftRequest.php`, `BlocksAuditSerializer` (allowlist), `routes/api.php` (drafts `RejectsSecretValues:blocks`)
- Connector read side: `DataSourceController`, `EndpointController::bindingOptions`, `DataSourceResource` (health, live_capable, revision), `EndpointResource` (params, revision; add revision_id), `app/Modules/Connector/Contracts/*`, `lib/endpoints.ts` (`Binding`, `RESOLVED_TEXT`, `isUserBinding`), `lib/dataSources.ts`
- Refresh tunable: `config/dashflow.php` `tunables.sync.refresh_intervals`, `app/Modules/Ingestion/Infrastructure/SyncSettings.php`
- Reuse: `StatusDot.vue`, `DataSourceHealth.vue`, `NativeSelect.vue`, `GatedAction.vue`, `EndpointForm.vue` parameters table / POST checkbox; labels `post-readonly`, `live-not-supported`, `datasource-none`
- Tests: `tests/js/block-wizard*.test.ts` (add `block-wizard-step2.test.ts`), `tests/Database/BlockWizardTest.php`, `DraftBlocksTest.php`, `tests/Unit/BlocksAuditSerializerTest.php`, module-boundary tests

## Tasks & Acceptance

**Execution:**
- [x] server: `source` config whitelist + validation + server-side revision pinning, Endpoint/Data Source ownership check, refresh-interval read endpoint, resource exposure of revision id, audit allowlist
- [x] client: step-2 component(s) replacing the placeholder for step 2, autosave/pending integration, out-of-date flag, labels
- [x] Tests: Database tests (storage, pinning, ownership, secret refusal, intervals endpoint) and Vitest for every Story 3.7 AC

**Acceptance Criteria:**
- Given each Story 3.7 scenario, when exercised, then the outcome holds.
- Given the full suite, when `bin/tools composer ci:check` runs, then it passes.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Unchanged selection 422s forever after source/endpoint change or deletion; pins/out-of-date flag reset | medium | patch | unchanged selection keeps pins, drops stale bindings; flag carried over; tests added |
| Server source.* errors not shown on Step 2 | medium | patch | errors rendered beside controls |
| Stored live on non-Live source blocks saves | medium | patch | reset on load/switch; tests added |
| Fetch enabled with no handler | low | patch | aria-disabled with later-update reason |
| Missing param keys could 500 endpoint list | low | patch | defaults added |
| Pagination truncation | false | rejected | DataSources::list / Endpoints::list return the whole set, no cursor exists |
| POST read-only ack client-only, revision drift detection, Fixed param named like a secret refused, browser-clock dates, offline Fetch reason test, label cleanup, throttle on read routes | low/maybe-false | defer | recorded in deferred-work.md |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green
