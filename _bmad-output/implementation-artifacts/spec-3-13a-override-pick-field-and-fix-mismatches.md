---
title: 'Override a slot, pick a field and fix mismatches (Story 3.13a, backend core)'
type: 'feature'
created: '2026-10-10'
status: 'done'
route: 'dispatch'
baseline_commit: '5d977b8e82d07eab9330c5c8c25780837a876212'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-12a-auto-map-slots-with-confidence.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-11a-infer-field-roles-and-record-path-candidates.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** An override (3.12a) neither updates the field's role, offers an Undo, lists which fields fit a slot and why others do not, lets the Admin "treat" a field as another type, nor reports that the API response changed under a mapping. Story 3.13 is split: this is 3.13a (backend); the picker and message UI (3.13b) is deferred.

**Approach:** Extend the 3.12a mapping service: a per-slot field-picker listing, a role update with a stateless undo on override, `config.field_types` ("Treat as") honoured by the mapper and engine, and an `api_changed` report with match suggestions in the checklist.

## Boundaries & Constraints

**Always:**
- Picker: `GET /api/v1/admin/blocks/drafts/{draft}/mapping/slots/{slot}/fields` lists every single-scalar field (path, display, type, effective type, role, example) with `compatible` and, when false, a `reason` ("Text field can't be a numeric headline. Use it as the currency format below."; a field inside the records: "Needs aggregation (a later release)."). Compatible first, then document order, plus a `static_text` entry offered for text slots (never Text Template or Calculated Field). Read-only viewers may call it; it writes nothing. A slot that is unknown or config-only is 404.
- Override with role: when an override picks a field for a number slot and the field's effective role is not `value`/`measure`, store `config.field_roles[path] = 'measure'` (a number slot) and return `role_change: {path, from, to}`; for a text slot do not change roles. The response also returns `undo` (opaque to the client: the slot's previous `{path,state}` or null, and the previous role override or null). `POST .../mapping/undo` with that payload restores the slot entry and the role override exactly (validated like any write; 422 for an unknown slot or a malformed payload), then re-fills unconfirmed slots from the new roles by re-running auto-map; confirmed and overridden slots are never changed.
- "Treat as": `config.field_types` maps a stored path to `text|number|boolean|date` (canonical path, known type; save refused otherwise, null clears like `field_roles`). Effective type = override else inferred. A decimal-shaped string treated as `number` fits a number slot and the engine passes it as a decimal string; any other forced mismatch stays `type_mismatch` with the reason ("data.currency is text (\"USD\"). Headline value needs a number.") and the compatible fields; the field is never discarded from the slot.
- API change: the checklist gains `api_changed`: every decided or mapped slot whose path is not in the sample, with the slot, the missing path, and `match` (a field elsewhere with the same last key, or the same last key and compatible type) or null. The slot reads `required_missing` (required) or `optional_empty`; a match is only reported, never applied. The checklist blocker list includes these.
- Writes take the edit-lock claim and revision rules of 3.12a (423 `DraftLockLost`; no-op writes skip the update); `blocks.edit` only; no sample text in errors; no `json_decode`; respect `tests/Architecture`.

**Never:** UI (3.13b); Text Template or Calculated Field; applying a match automatically; changing confirmed or overridden slots on re-fill.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Picker | headline slot | compatible numeric single scalars first; text fields incompatible with reason | N/A |
| Records field | numeric only inside rows | incompatible, "Needs aggregation" | N/A |
| Override role | `revenue` (filter/dimension) to headline | slot overridden, role measure, `role_change`, `undo` | N/A |
| Undo | undo payload | slot and role restored; unconfirmed slots re-filled; decided untouched | 422 malformed |
| Text for number | override a text field to headline | refused with reason and compatible fields | 422 `slot.<key>` |
| Treat as number | `"284680"` string | fits headline; preview shows 284680 | N/A |
| Bad treat-as | type `money` or path `a..b` | save refused | 422 `blocks.invalid_config` |
| API changed | mapped `total` gone, `grand_total` exists | `api_changed` entry with match; headline required_missing; blocker | N/A |
| Viewer | no lock | picker and checklist 200; writes 423 | `DraftLockLost` |

</frozen-after-approval>

## Code Map

- `app/Modules/Mapping/Application/AutoMapper.php`, `Contracts/{AutoMapping,AutoMapResult,SlotState}.php`, `FieldRoleInference.php` -- fits/isSingle logic to extend with effective type and a field listing; baseline and stale rules from 3.12a.
- `app/Modules/Mapping/Infrastructure/InProcessExecutor.php` -- number slots already accept decimal-shaped strings; consult `field_types`.
- `app/Modules/Blocks/Application/ManageDraftSlots.php`, `DraftConfig.php` (`field_roles`, `slot_map` patterns), `DraftSlotsController.php`, `DraftSlotRequest.php`, `routes/api.php`, `ShellNavigation::ADMIN_API_ROUTES`, `tests/Database/DraftSlotsTest.php`, `AdminAccessTest.php` (new routes).

## Tasks & Acceptance

**Execution:**
- [ ] `app/Modules/Mapping/**` -- field listing with compatibility reasons, effective types, api-changed with matches
- [ ] `DraftConfig.php` -- accept, validate and keep `field_types`
- [ ] `app/Modules/Blocks/**`, controller, request, routes, `ShellNavigation.php` -- picker route, override role change and undo, undo route
- [ ] `tests/**` -- unit and database tests for every matrix row; add new routes to `AdminAccessTest`

**Acceptance Criteria:**
- Given an override that changes a role, when undone with the returned payload, then the config (slot map and role overrides) equals its state before the override.
- Given a string number treated as number and mapped to the headline, when previewed, then the headline shows its exact decimal string.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Undo trusts a forged or stale client payload | high | patch | Override returns `applied`; undo 409s when the slot changed, role path and restored path are validated; tested. |
| Cleared-slot undo may be rejected by `required_with` | medium | patch | Rules fixed; round-trip test added. |
| Undo of the first role override untested | medium | patch | Test added. |
| `str_replace('config.')` corrupts error keys; `fields()` bypasses error mapping; `undo.slot` unconstrained | low | patch | Fixed; 503 test added. |
| Mismatch reason names the inferred type for a forced one | low | patch | Uses the effective type. |
| Decimal check differs from the executor's | medium | patch | One shared `ExecutionResult::DECIMAL`; tested. |
| Role undo while another slot still maps the path | low | defer | Edge of overlapping overrides. |
| Fuzzy api-match on short keys; suggestion may duplicate a used field | low | defer | Heuristic polish. |
| Exact-restore claim ignores `query_plan` | low | rejected | The criterion names the slot map and role overrides. |
| `field_types` size unbounded; stale forced types | low | defer | Same family as stale overrides in 3.11a. |
| `api_changed` blocker reason unknown to later consumers | low | defer | No consumer exists until 3.12b/3.13b. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green (retry the audit step alone if Packagist times out)
