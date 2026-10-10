---
title: 'Auto-map the KPI slots with confidence and a checklist (Story 3.12a, backend core)'
type: 'feature'
created: '2026-10-09'
status: 'done'
route: 'dispatch'
baseline_commit: 'fd2d69c3e4d66fddffdb85779d378b9f13bc255b'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-10-render-the-sample-through-the-query-plan-engine-live-preview.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-11a-infer-field-roles-and-record-path-candidates.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Slots are mapped by hand-written plans only. Nothing fills a Block Type's slots from the inferred roles, grades confidence, tracks Confirmed or Overridden slots, or tells the wizard what blocks Continue. Story 3.12 is split: this is 3.12a (backend); the checklist UI (3.12b) is deferred.

**Approach:** Add a pure `AutoMapper` to `app/Modules/Mapping` (sample field report + Block Type slot definitions + stored slot states in, per-slot result and a plan out), store the Admin's decisions in `config.slot_map`, and add endpoints to read the checklist, re-run auto-map (writing `config.query_plan`), confirm a slot and override a slot.

## Boundaries & Constraints

**Always:**
- Slots come from the Block Type contract (key, required, value type); `kpi-card` only. Only fields that select exactly one scalar are candidates (fields outside the record path, per 3.11a `in_records=false`); a field inside records is ineligible for a slot (aggregation is Epic 5) and, if it is the only match, the slot is `type_mismatch` with that reason.
- Headline (number slot): candidates are numeric `value`-role fields. One candidate: High (unique match by role and type). Several: drop keys that are, or start with, `previous`, `prior`, `prev`, `last`, `baseline`; exactly one left: Medium, reason "N Values; picked the one not named previous_*"; otherwise Low (first in document order), reason names the ambiguity. Dropped ones are reported as `baseline` candidates and are NOT mapped to a slot. Text slots: a `label`-role (or text) scalar whose key word matches the slot (title: `title|name`, subtitle: `subtitle|description`, kpi_label: `label|metric|name`, status_badge: `status`, secondary_note: `note`) is Medium; no match is optional-empty or required-missing. Slots the contract marks as config-only (`footer_action`, `icon`) are never mapped.
- States per slot: `auto_high`, `auto_medium`, `auto_low`, `confirmed`, `overridden`, `optional_empty`, `required_missing`, `type_mismatch`. `auto_high` counts as confirmed; `confirm` on a medium/low slot stores `config.slot_map[slot] = {path, state: 'confirmed'}`; `override` stores `{path, state: 'overridden'}` after checking the field exists, is a single scalar and its type fits the slot (else 422 naming the slot), or `path: null` clears the slot to empty. Re-run never changes a confirmed or overridden slot, and never discards an override. A confirmed slot whose path is no longer in the sample reads `type_mismatch`, not silently dropped.
- Checklist payload: groups HEADER, KPI, META (from the contract's grouping, else by a fixed key list you document), each slot with state, confidence, reason, suggestion (a required-missing slot with a known candidate), field path; `counts` (auto_mapped, total, confirmed, needs_review, required_missing, type_mismatch), `blockers` (required missing, unconfirmed low, type mismatch; medium optional does not block), and Low rows first. Re-run writes `config.query_plan` v1 (extract fields + shape) built from every mapped slot, replacing a previous plan.
- Block type change: `carryOver(slotMap, newTypeSlots)` keeps entries whose slot key and value type match and returns the rest as unmapped; nothing dropped silently. Cover with a fixture contract with a second test-only slot set.
- Writes (re-run, confirm, override) take the edit-lock claim and revision rules of the existing Draft sample write (`DraftLockLost`, 423); GET is for read-only viewers too. No `json_decode`; no sample text in errors; respect `tests/Architecture`.

**Never:** UI (3.12b); computing a comparison from the baseline field (Epic 5); changing Step 1 block type UI; inventing confidence numbers beyond the three words.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Unique headline | one numeric value `total` | headline `auto_high`, plan written | N/A |
| Previous vs current | `total`, `previous_total` | headline `total` Medium with reason; `previous_total` reported baseline, unmapped | N/A |
| Ambiguous | `total`, `revenue` | headline Low, first by order, reason | N/A |
| Only in records | numbers only inside rows | headline `type_mismatch` | N/A |
| Missing required | no numeric value | `required_missing`, suggestion if a candidate exists | blocker listed |
| Confirm | medium slot | `confirmed`, stored | 404 slot unknown |
| Override | valid scalar path / `null` | `overridden` / cleared | 422 unfit field |
| Re-run | confirmed + overridden present | untouched; others recomputed | N/A |
| Stale confirmed | path gone from new sample | `type_mismatch` | N/A |
| Block type change | fixture second slot set | same key+type kept, rest unmapped list | N/A |
| Viewer / lock lost | not holder writes | GET 200; writes 423 | `DraftLockLost` |

</frozen-after-approval>

## Code Map

- `app/Modules/Mapping/Application/FieldRoleInference.php`, `Contracts/FieldRoleReport.php` -- field report (path, type, role, in_records, confidence); reuse as input.
- `app/Modules/BlockTypes/Contracts/{BlockType,BlockTypeSlot,KpiCardSlots}.php`, `block-types/kpi-card/v1/schema.json` -- slot key, required, type; group names if present.
- `app/Modules/Mapping/Contracts/QueryPlan.php`, `QueryPlanEngine` -- plan v1 shape to build; preview (3.10) reads `config.query_plan`.
- `app/Modules/Blocks/Application/DraftConfig.php` -- add validated `slot_map` (like `field_roles`); `ReadDraftFieldRoles.php`, `DraftRolesController.php` -- read pattern; `ManageDraftSamples.php`, `DraftSampleController.php` -- lock/revision write pattern; `routes/api.php`, `ShellNavigation::ADMIN_API_ROUTES`.

## Tasks & Acceptance

**Execution:**
- [ ] `app/Modules/Mapping/**` -- `AutoMapper`, result types, `carryOver`
- [ ] `app/Modules/Blocks/Application/DraftConfig.php` -- `slot_map` accept, validate, keep
- [ ] `app/Modules/Blocks/**`, controller, `routes/api.php`, `ShellNavigation.php` -- GET checklist, POST re-run, confirm, override
- [ ] `tests/**` -- unit tests for every matrix row, endpoint and config tests, architecture

**Acceptance Criteria:**
- Given a flat sample with total, previous_total, month, revenue, region, status, when re-run, then the headline is `total` (Medium, with the reason caption), `previous_total` is baseline-only, the plan is written and the preview endpoint returns the headline.
- Given a confirmed and an overridden slot, when re-run, then both are unchanged.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| `DraftSlotRequest::path()` shadows the framework method | medium | patch | Renamed `slotPath()`. |
| Override with `""` silently clears the slot | medium | patch | Now 422; only explicit null clears; tested. |
| Re-run with nothing mapped writes an empty plan | medium | patch | Plan left untouched; tested. |
| One field fills several slots | medium | patch | Paths reserved across slots; tested. |
| Baseline list empties once the headline is decided | medium | patch | Computed independently; tested. |
| No-op confirm / re-run bumps the revision | low | patch | UPDATE skipped when config unchanged; tested. |
| 409 test never publishes; viewer test has no `blocks.edit` viewer; Low confirm unasserted | medium | patch | Tests fixed or added. |
| `slot_map` saved through the generic save skips fit checks | low | defer | Stale or unfit paths read as `type_mismatch`. |
| `carryOver` not wired to a Block Type change; takes three arguments | low | defer | Wiring belongs with the Step 1 change flow (3.12b). |
| Baseline detects only the first key word; `file_name` matches title; null-only text fields | low | defer | Heuristic polish. |
| `counts.confirmed` includes auto_high; no throttle or audit; re-run replaces the plan | low | rejected | Spec: auto_high counts as confirmed; plan replacement is specified. |
| 3.12 acceptance sample with a numeric `revenue` grades Low | low | defer | Spec example conflicts with its own rules; test uses a string `revenue`. Raise with the 3.12b UI. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green (retry the audit step alone if Packagist times out)
