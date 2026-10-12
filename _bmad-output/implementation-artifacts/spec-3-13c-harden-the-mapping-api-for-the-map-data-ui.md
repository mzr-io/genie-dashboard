---
title: 'Harden the mapping API for the Map Data UI (Story 3.13c)'
type: 'refactor'
created: '2026-10-10'
status: 'done'
route: 'dispatch'
baseline_commit: '805ebedd3adda713304ff4092480f6e9aed46864'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-12a-auto-map-slots-with-confidence.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-13a-override-pick-field-and-fix-mismatches.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A cross-story review of 3.9–3.13a found gaps the Map Data UI (3.11b/3.12b/3.13b/3.14) would hit: the preview plan is stale after confirm or override; two writers (generic save and mapping endpoints) can desync the mapping keys; no single consistent read; a replaced or expired sample orphans the mapping; no per-slot rendered value for hotspots and the legend; mapping writes ignore the draft revision.

**Approach:** Make the mapping keys server-owned with mapping-scoped writes, recompute the plan on every write, add one consistent read with revisions, `plan_stale` and an expired-sample degrade, add per-slot values, and require the draft revision on mapping writes.

## Boundaries & Constraints

**Always:**
- Every mapping write (rerun, confirm, override, undo and the new writes below) recomputes the plan from the resulting mapping and stores it in `config.query_plan` with `config.mapped_sample_revision` = the sample revision; an empty shape REMOVES the stored plan and `mapped_sample_revision`. Every mapping response returns `query_plan`, `draft_revision`, `sample_revision`.
- Generic Draft save: `slot_map`, `record_path`, `field_roles`, `field_types` are refused with 422 `blocks.invalid_config` ("set through the mapping endpoints"); a save that omits them still keeps stored values; `query_plan` stays accepted as in 3.10 (validated v1). Add mapping-scoped writes through the same `ManageDraftSlots::write()` (lock claim, no-op skip): `PUT .../mapping/record-path` {record_path|null}, `PUT .../mapping/field-roles` {path, role|null}, `PUT .../mapping/field-types` {path, type|null}; each validates like the config rules and the sample, re-maps (a decided slot whose path vanished stays stored and reads `api_changed`), and rewrites the plan. Move existing tests that saved these keys through the generic save onto the endpoints.
- One read: `GET .../mapping` returns `{checklist, roles, query_plan, draft_revision, sample_revision, plan_stale, sample: 'ok'|'expired'}` from one pass, with `ETag: "d{draft_revision}-s{sample_revision}"` and `304` on a matching `If-None-Match`. `plan_stale` = plan present and `mapped_sample_revision != sample_revision`. With no or an expired sample it returns 200 with `sample: 'expired'`, the stored `slot_map`, `query_plan` and no computed checklist (not a 422). Add `draft_revision` to the roles and preview responses. Cache the decoded sample shape per (workspace, draft, sample_revision) in-process, as preview does; never in a shared cache.
- Preview response adds `slots: {slot: {value, path, unavailable?}}` from the engine's per-slot values (decimal strings; sample-derived, bounded like examples); checklist rows add `example`.
- Every mapping write requires `draft_revision` in the body; a mismatch is 409 `blocks.revision_conflict` with `current`, nothing written. The undo "slot changed" conflict gets its own code `blocks.slot_changed` (add to the enum, regenerate `error-codes.ts`).
- Respect the lock-claim rules, `tests/Architecture`, no `json_decode`, no sample text in errors.

**Never:** UI; changing heuristics or the checklist semantics of 3.12a/3.13a; a shared-cache copy of the sample; auto-applying matches.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Override then preview | override headline, then POST preview | preview uses the new plan; override response carries `query_plan` | N/A |
| Clear last slot | only mapped slot cleared | plan and `mapped_sample_revision` removed; preview 422 `mapping.no_plan` | N/A |
| Generic save of mapping key | config with `slot_map` / `field_roles` | refused, names the endpoint | 422 `blocks.invalid_config` |
| Record path write | valid / null / path not in sample | stored and re-mapped / cleared / refused | 422 |
| Stale revision | write with old `draft_revision` | nothing written | 409 `blocks.revision_conflict` + `current` |
| Slot changed | stale undo | nothing written | 409 `blocks.slot_changed` |
| Sample replaced | new sample revision, old plan | `plan_stale` true | N/A |
| Sample expired | TTL passed | 200, `sample: 'expired'`, stored mapping | N/A |
| Conditional GET | matching `If-None-Match` | 304 | N/A |
| Preview slots | headline mapped | `slots.headline_value.value` exact decimal string | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Blocks/Application/ManageDraftSlots.php` (`write()`, `rerun`, `confirm`, `override`, `undo`, `inputs()`), `DraftConfig.php`, `ReadDraftFieldRoles.php`, `PreviewDraft.php` (shape/tree cache pattern), `ManageDrafts.php` (revision check pattern).
- `app/Http/Controllers/Admin/{DraftSlotsController,DraftRolesController,DraftPreviewController}.php`, `Requests/Admin/DraftSlotRequest.php`, `routes/api.php`, `ShellNavigation::ADMIN_API_ROUTES`, `Blocks/Contracts/ErrorCode.php`, `resources/js/types/error-codes.ts`.
- `app/Modules/Mapping/Application/{QueryPlanEngine,AutoMapper}.php`, `Contracts/{PlanResult,ExecutionResult,AutoMapResult}.php` -- per-slot values and checklist `example`.
- Tests: `tests/Database/{DraftSlotsTest,DraftRolesTest,DraftPreviewTest,DraftBlocksTest,AdminAccessTest}.php`, `tests/Unit/{AutoMapperTest,QueryPlanEngineTest}.php`.

## Tasks & Acceptance

**Execution:**
- [ ] `ManageDraftSlots.php`, `DraftConfig.php` -- plan recompute and stamp on every write; server-owned keys; record-path, field-roles, field-types writes; revision requirement
- [ ] controllers, request, routes, `ShellNavigation.php`, enum, `error-codes.ts` -- single read with ETag, degrade, revisions in roles and preview, new codes
- [ ] `app/Modules/Mapping/**` -- per-slot values in the preview result; checklist `example`
- [ ] `tests/**` -- every matrix row; migrate old tests to the endpoints; `AdminAccessTest` new routes

**Acceptance Criteria:**
- Given any mapping write, when it succeeds, then a following preview reflects it without a re-run and the response revisions equal the stored ones.
- Given an expired sample, when the mapping is read, then the stored decisions are returned and nothing 422s.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| `plan_stale` differs between branches; hand-saved plan keeps an old stamp | medium | patch | One `planStale()` rule; generic save with `query_plan` drops the stamp; tested. |
| Invalid sample reported as `expired` | medium | patch | Now `sample: 'invalid'`; tested. |
| Empty-string `record_path`/`role`/`type` silently clears | medium | patch | 422; only null clears; tested. |
| Preview lacks `plan_stale`; dead merge branches; loose no-op comparison; stale docblock | low | patch | Fixed and tested. |
| Preview truncation, `If-None-Match: *` untested | low | patch | Tests added. |
| Torn read across draft, sample and contract version | low | defer | No shared snapshot. |
| 304 still builds the whole checklist; ETag ignores contract version | low | defer | Optimisation. |
| Shape cache keeps serving after limits tighten; orphaned overrides after a record-path change | low | defer | Same family as earlier stale-state items. |
| Generic save refusing mapping keys is a breaking change | low | rejected | Specified; the UI is the only client and no front-end code used them. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green (retry the audit step alone if Packagist times out; run the Database suite with pest directly if composer's 300s timeout hits)
