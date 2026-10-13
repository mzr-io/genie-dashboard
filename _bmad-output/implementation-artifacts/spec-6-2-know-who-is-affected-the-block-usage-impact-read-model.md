---
title: '6-2 Know who is affected: the block_usage impact read model'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: 'be4bd9c'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-6-1-edit-a-published-block-into-a-new-draft.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Before an Admin confirms a change they cannot see how many users have the Block on a dashboard or which Templates include it (Story 6.2; FR-39, AR-3, AR-36, AD-29).

**Approach:** Blocks owns `block_usage`, fed by `dashboards.dashboard.changed`, idempotent and ordered per dashboard, and `BlockImpact` reads only it.

## Boundaries & Constraints

**Always:** Distinct memberships, not instances: a member with the Block on three dashboards is one. An event at or below the dashboard subject's last applied `subject_seq` is dropped. The model can be cleared and rebuilt from the events to the same counts. Both tables are tenant tables with forced row-level security; a row leaves only through a Workspace-scoped function.

**Never:** `BlockImpact` joins no other module's table. The Template side adds no schema change when Templates exist (`kind = 'template'`), and is empty and 0 until then.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Three dashboards | same member | 1 user | N/A |
| Remove all | items to 0 | row released, 0 users | N/A |
| Replay | event at or below the cursor | dropped, nothing changes | N/A |
| Rebuild | model cleared | replay gives the same rows | N/A |
| Other Workspace | Admin of B asks about A's Block | 0, no leak | N/A |

</frozen-after-approval>

## Code Map

- `database/migrations/2026_10_31_110000_create_block_usage.php`; `TrackBlockUsage` (consumer), `RebuildBlockUsage`, `BlockImpact` and `ReadBlockImpact`; the consumer is registered and the contract bound in `AppServiceProvider`.
- `tests/Architecture/dependencies.php` (ownership), `tests/Database/Support/Cluster.php` (seeders), `tests/Database/BlockUsageTest.php`.

## Tasks & Acceptance

**Execution:**
- [x] Tables, policies and the release and reset functions
- [x] Consumer with the per-dashboard cursor; rebuild from the events
- [x] `BlockImpact` over `block_usage` only
- [x] Tests: Database, ownership and leak coverage

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the database tests.

## Verification

Pest Unit, Feature, Architecture and Database suites, PHPStan, Pint.

## Implementation Notes

- Existing dashboards are counted once by the migration's backfill; the consumer then keeps it.
- The reset function exists for the rebuild only and is scoped to the current Workspace.
