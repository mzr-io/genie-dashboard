---
title: '5-6b Prior-period comparison requests: Admin controls and the live check'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '4c75255'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-5-6a-prior-period-comparison-requests-backend.md', '{project-root}/_bmad-output/implementation-artifacts/spec-3-18-validate-the-block-against-the-real-api.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The backend of a prior-period comparison (Story 5.6a) has no Admin controls: the source cannot be chosen, the preview would look broken for it, and Publish validation checks only the primary request.

**Approach:** Enable the "Prior-period request" source in the comparison editor with its prior window, tell the Admin in the preview that the comparison is checked against the live API before publish (the sample never supplies one), and have the step 5 live check fetch the comparison request for the prior window of the Default date range too.

## Boundaries & Constraints

**Always:** The window is chosen from the three kinds of Story 5.6a; a custom offset is a whole number from 1 to 366 and a unit, judged in the client and again by the server. The validation fetches through the Connector's one fetcher, the comparison after the primary and only once the primary is sound. A comparison request that fails is `unreachable` (Publish blocked), a headline missing from its response a mismatch on the comparison slot.

**Never:** No value in the preview for a prior-period comparison, no second fetcher, no body stored.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Choose the source | segmented control | source and the previous-period window are written | N/A |
| Custom offset | 3 weeks | window `{custom_offset, 3, week}` | amount outside 1 to 366: inline fault, not sent |
| Preview | prior-period source | "checked against the live API before publish", no comparison value | N/A |
| Validation | both requests succeed | `passed` | N/A |
| Comparison fails | 500 | `unreachable`, reason `comparison_fetch-failed` | Publish blocked |
| Headline missing in prior response | no revenue | `failed`, mismatch on `comparison` | Publish blocked |
| No prior-period source | any | one request, as before | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/components/wizard/{PresentationEditor,LivePreview,MapDataStep}.vue`, `resources/js/pages/admin/BlockWizard.vue`, `resources/js/lib/blockPresentation.ts`, `resources/js/locales/labels.ts`.
- `app/Modules/Blocks/Application/{DraftPeriod,StartDraftValidation,RunDraftValidation}.php`, `app/Modules/Connector/Application/RunEndpointValidation.php`, `Contracts/{EndpointValidations,PreparedEndpointValidation}.php`.

## Tasks & Acceptance

**Execution:**
- [x] Source and window controls, client rules
- [x] Preview note for a prior-period comparison
- [x] Prior window dates for the validation and the Connector's second set of values
- [x] Step 5 live check of the comparison request
- [x] Tests: vitest, Database

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the component and database tests.

## Implementation Notes

- The validation's second fetch goes through `EndpointValidations::run()` again for the same Operation; the first body is taken and dropped before the second is fetched, so the Operation keeps one blob at a time.
- The prior dates come from `DraftPeriod::priorDates()` (the Default date range's prior window, never client dates).
- Step 5 shows the existing report: a comparison failure is its reason (`comparison_*`) and a missing headline its mismatch.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour and renderer lint, block-type pins.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| A prior window with no data in the prior period blocks Publish | medium | accept | The check proves the request and the headline resolve; a window before the API's data is a real mismatch to fix. |
| Step 5 has no separate line for the comparison request | low | defer | Reported through the existing report's reason and mismatches. |
