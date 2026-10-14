---
title: '6-3 Diff a new version against the current one'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: 'a828629'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-6-1-edit-a-published-block-into-a-new-draft.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** An Admin cannot see what a new version changes before users get it (Story 6.3; FR-39, FR-41, AD-13, UX-DR-76).

**Approach:** `BlockVersionDiff` compares the Draft's config with the published version's content made a Draft config again and returns four sections (Mapping, Presentation, Chrome, Endpoint) of before and after pairs. The summary (section names with counts) is frozen on the version in the freeze and can never change. The dialog's rows show before struck through and muted, after primary, with "was" and "now" for a screen reader.

## Boundaries & Constraints

**Always:** The wizard's own state is not a config and is never in a diff, so two Drafts with the same content diff alike; the pinned sample revision and the schema version are left out. A Draft with no change has every section empty ("No changes"). A first publish is not diffed. `change_summary` is written once, in the draft to prepared update, and the guard requires it unchanged on prepared to published.

**Never:** The diff writes nothing. A text of the diff is never markup.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| No change | Draft = published | four empty sections, empty summary | N/A |
| Wizard state only | step, warnings | still empty | N/A |
| Chrome change | description | `chrome: 1` | N/A |
| First publish | no earlier version | `first_publish: true`, no sections | N/A |
| Publish | a changed Draft | summary frozen on the version | N/A |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_31_120000_add_change_summary_to_block_versions.php`; `VersionDiff`, `BlockVersionDiff`, `ReadBlockVersionDiff`, `DraftDiffController`, `PublishDraft::changeSummary`; route `GET /api/v1/admin/blocks/drafts/{draft}/diff` (`blocks.edit`).
- `resources/js/lib/versionDiff.ts`, `components/wizard/VersionDiffRows.vue`, `versionDiffLabels`.
- `tests/Database/DraftPublishTest.php`, `tests/js/version-diff.test.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Diff of two configs in four sections; the summary
- [x] Endpoint, contract and the summary frozen with the version, guarded
- [x] The rows component and the client
- [x] Tests: Database, vitest

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the database and component tests.

## Verification

Pest Unit, Feature, Architecture and Database suites, vitest, `vp check`, `vue-tsc`, PHPStan, Pint.

## Implementation Notes

- The dialog that shows these rows is Story 6.4's; the rows component is here so that the diff is complete with its presentation.
