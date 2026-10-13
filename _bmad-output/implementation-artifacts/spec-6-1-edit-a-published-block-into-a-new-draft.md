---
title: '6-1 Edit a published Block into a new Draft'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '27cc45f'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-3-19-publish-block-v1-0.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A published Block could be started again only as an empty Draft numbered 1.0 (Story 6.1; FR-38, FR-40, FR-41, AR-17, AR-19, AD-13, C17).

**Approach:** Creating a Draft for an existing Block that has a published version makes it from that version: its content (the mapping, the plan, the presentation, the transforms and templates, the chrome and the source), the name, and the next version label fixed at creation (the next minor, the next major when the Block Type has a newer contract). The published version is not touched, the Draft's creation is audited in the same transaction, a Block with a Draft answers 409 with that Draft, an Archived Block answers 409 `blocks.block_archived`.

## Boundaries & Constraints

**Always:** `blocks.archived_at` (new, nullable) is the Archived mark; the content of the published version comes back as the mapping endpoints wrote it (`FrozenConfig::draftConfig` is the inverse of `build`), with the pinned sample revision left out so the plan reads as stale until the sample is fetched again. The one-Draft rule is the partial unique index.

**Never:** The published version, `current_version_id` and the live users are never written by Edit.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Edit | published v1.0, no Draft | Draft v1.1 with v1.0's content, audited | N/A |
| Edit again | Draft exists | 409 with that Draft's state | nothing created |
| Archived | `archived_at` set | 409 `blocks.block_archived` | nothing created |
| New contract | newer Block Type contract | proposes v2.0 | N/A |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_31_100000_add_archived_at_to_blocks.php`; `ManageDrafts::create` and `claimBlock`; `FrozenConfig::draftConfig`; `Transforms::steps`; `BlockArchived`, `ErrorCode::BlockArchived`; `DraftController::store`.
- `tests/Database/DraftPublishTest.php`, `tests/Unit/PresentationTest.php`; rollback step counts in `tests/Database` now come from `Cluster::migrationsSince`.

## Tasks & Acceptance

**Execution:**
- [x] Archived mark and the refusal; the Draft made from the published content with the next version
- [x] The inverse of the freeze and the steps of a plan
- [x] 409 carries the existing Draft's state
- [x] Tests: Unit, Database

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit and database tests.

## Verification

Pest Unit, Feature, Architecture and Database suites, vitest, `vp check`, PHPStan, Pint.

## Implementation Notes

- The permission and the denial event are the admin middleware's (`blocks.edit`, a 403 and an audited denial); the lock and revision rules are the existing Draft save's.
- The contract upgrade itself (carrying a Block to a newer Block Type contract) is not part of Edit: only the proposed label is.
