---
title: 'Infer field roles and record-path candidates (Story 3.11a, backend core)'
type: 'feature'
created: '2026-10-09'
status: 'done'
route: 'dispatch'
baseline_commit: 'b5d792fa6b9a424cc78c4be2754a429dc3e57efe'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Auto-map (3.12) and the Map Data step need each field's role and the array that holds the rows, but nothing infers roles, lists record-path candidates, or stores the Admin's choices in the Draft. Story 3.11 is split: this is 3.11a (backend). 3.11b (Map Data UI) and 3.11c (Datasets suggestion upsert) are deferred.

**Approach:** Add a pure `FieldRoleInference` to `app/Modules/Mapping` over the sample's `SampleShape`, accept `config.record_path` and `config.field_roles` (the Admin's overrides, the single source of truth) in the Draft config, and add a read-only `GET /api/v1/admin/blocks/drafts/{draft}/sample/roles` that returns candidates, the chosen path and every field with its effective role, confidence and any suggestion.

## Boundaries & Constraints

**Always:**
- Roles: `label`, `value`, `dimension`, `measure`, `time`, `filter`. Heuristics (documented in the class docblock, no model, no float): a string whose example is ISO-8601 date or datetime, or whose key matches `date|time|_at|_on|timestamp|month|year|period`, is `time`; booleans are `filter`; inside a record array a number is `measure`, except a key matching `id|code|zip|postcode|phone|key|number` (whole word) which is `dimension`; a string key matching `name|title|label|description` is `label`, other strings `dimension`; scalars outside the record path that are numbers are `value`, strings `label`. Confidence is `high` when name and type agree on the role, else `low`.
- Suggestion: a field whose role is `filter` or `dimension` but which is numeric and whose key matches `revenue|amount|total|price|cost|sales|spend|count|quantity` gets `suggestion: {role: 'measure'}`, never applied automatically.
- Record-path candidates: every array of objects in the shape, with `rows`, `columns`, `suggested` (most rows; ties go to the shallower, then first) and `pagination_like` (path or an ancestor key matches `links|meta|pagination|page|paging|next|prev|cursor`; such a candidate is never suggested while another exists). Candidates are returned when more than one array of objects exists or nesting is deeper than 2 levels (`needs_choice`); the chosen path is `config.record_path` or the suggested one.
- Overrides: `config.field_roles` maps stored paths to roles; an override wins, marks the field `overridden: true`, and has confidence `high`. Paths use the stored RFC 9535 subset; unknown roles or malformed paths are refused with `config.field_roles.*` / `config.record_path` errors under `blocks.invalid_config`. A save that omits them keeps the stored values, like `query_plan`.
- Read-only viewers may call the endpoint; it writes nothing, takes no lock, needs `blocks.edit`, 404 for a missing Draft, 422 `mapping.no_sample` without a sample. Numbers stay lexemes; no `json_decode`; no sample text in any error. Respect `tests/Architecture`.

**Never:** UI (3.11b); the Datasets suggestion table (3.11c); changing Confirmed or Overridden slot mappings (3.12); auto-mapping; per-slot preview changes; inventing numeric thresholds.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Single array | `data[*]` rows with id, name, revenue, date | one candidate, suggested; fields: id dimension, name label, revenue measure, date time | N/A |
| Several arrays | `data[*]` 40 rows and `meta.links[*]` 3 | `needs_choice`, data suggested, links `pagination_like` | N/A |
| Suspect role | `revenue` is numeric but key says id-like pattern or filter | `suggestion` measure, role unchanged | N/A |
| Override | `field_roles` sets revenue to dimension | role dimension, `overridden` true, no suggestion | N/A |
| Scalar response | no arrays, `summary.total` 284680 | no candidates, field role `value` | N/A |
| Bad config | role `bogus` or path `a..b` | save refused | 422 `blocks.invalid_config` with key |
| No sample | Draft has none | N/A | 422 `mapping.no_sample` |
| Viewer | lock held by another | 200 | N/A |

</frozen-after-approval>

## Code Map

- `app/Platform/Json/SampleShape.php` -- `of()` gives nodes (path, type, example, count, parent, depth), `records`; reuse, do not re-parse.
- `app/Modules/Blocks/Contracts/DraftSamples.php`, `StoredSample`, `SampleCheck->shape` -- sample read; `PreviewDraft.php` and `DraftPreviewController.php` are the pattern for a read-only use case, controller, error mapping, `routes/api.php`, `ShellNavigation::ADMIN_API_ROUTES`.
- `app/Modules/Blocks/Application/DraftConfig.php` -- `normalise()`/`merge()` pattern used for `query_plan`; add `record_path`, `field_roles`.
- `app/Modules/Mapping/` (Contracts, Application) -- new `FieldRoleInference` + result types + `SamplePath` for path syntax; `tests/Architecture/dependencies.php`.
- `app/Modules/Mapping/Contracts/ErrorCode.php`, `resources/js/types/error-codes.ts` -- reuse `mapping.no_sample` (add if missing, regenerate).

## Tasks & Acceptance

**Execution:**
- [ ] `app/Modules/Mapping/**` -- `FieldRoleInference` and result types, heuristics as specified
- [ ] `app/Modules/Blocks/Application/DraftConfig.php` -- accept, validate, keep `record_path` and `field_roles`
- [ ] `app/Modules/Blocks/**`, `DraftRolesController`, `routes/api.php`, `ShellNavigation.php` -- read-only roles endpoint
- [ ] `tests/**` -- unit tests for every matrix row, config validation, endpoint tests, architecture

**Acceptance Criteria:**
- Given the same sample and overrides, when the endpoint is called twice, then the responses are identical and nothing is written.
- Given any matrix row, when run, then the listed outcome occurs.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Non-canonical `field_roles` keys accepted but never match | medium | patch | Keys must equal the stored form; refused under `config.field_roles.<path>`; tested. |
| `field_roles: null` ignored while `record_path: null` clears | low | patch | Null now clears; tested. |
| Identifier key (`revenue_id`) still gets a measure suggestion | low | patch | Identifier word suppresses it; test updated. |
| 503 and invalid-sample refusals, stale record path, pagination-only fallback untested | medium | patch | Tests added. |
| Numeric `year`/`month`/epoch not time | low | rejected | Spec limits the time rule to strings. |
| Boolean confidence always low | low | rejected | Spec: high only when name and type agree. |
| No throttle or audit on the roles endpoint | low | rejected | Read-only, same as the sample show and check routes. |
| Stale overrides, stale record path flag, empty arrays as candidates, whole-word pagination match, "invalid sample" message | low | defer | Recorded in deferred-work.md. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green (retry the audit step alone if Packagist times out)
