---
title: 'Render the sample through the Query Plan engine (live preview endpoint)'
type: 'feature'
created: '2026-10-09'
status: 'done'
route: 'dispatch'
baseline_commit: '78f54adb005183e772b2e2d023fdbbf199715c88'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The wizard's preview must be computed by the same engine that will run in production, over the Draft's stored sample. No Query Plan engine, no `Mapping` module and no preview endpoint exist yet.

**Approach:** Add `app/Modules/Mapping` (the `QueryExecutor` port, `InProcessExecutor`, `QueryPlanEngine`, plan v1 with only `extract` and `shape`), accept `config.query_plan` in the Draft config, and add `POST /api/v1/admin/blocks/drafts/{draft}/preview` that runs the plan over the decoded sample and returns the Block Type Shaper's payload plus a per-slot `unavailable` reason map. All numeric limits stay `pending_input` settings with no default; the endpoint refuses, naming the setting, until each is configured.

## Boundaries & Constraints

**Always:**
- Plan v1: `{"v":1,"extract":{"fields":{"<alias>":"<stored path>"}},"shape":{"<slot key>":"<alias>"}}`. Paths are the stored RFC 9535 subset, evaluated by `SamplePath`; the sample is decoded only by `LosslessJson`. A path that yields exactly one scalar fills the slot; zero values, several values, or a non-scalar make that slot `unavailable` (reason `missing` / `not_single_value` / `wrong_type`), passed to the Shaper as null, never 0. Other slots still render. Numbers stay decimal strings.
- Stage order is fixed (extract, filter, row calc, group, aggregate, aggregate calc, sort, top-N, shape); the engine runs plan `v` 1 forever and refuses an unknown `v`. Arithmetic helper uses `BcMath\Number` at `tunables.numbers.decimal_scale`; no stage uses it yet.
- Guards (max extracted values, max evaluations, max wall milliseconds) abort the run with 422 `mapping.guard_exceeded`; no partial payload. Per-membership preview limit and window exceeded: 429 with `retry_after`. Unset settings (`preview.membership_limit`, `window_seconds`, `max_extracted_values`, `max_evaluations`, `max_wall_ms`) refuse with 422 naming the env var; `p95_target_ms` is recorded only.
- Preview needs only `blocks.edit` access and an existing Draft: a read-only viewer may request it; it never writes. Emit histogram `dashflow.mapping.preview_duration` (add a `record` method to `MetricEmitter`).
- The decoded sample is cached in-process per `(workspace, draft, sample_revision)`; never put the plaintext sample in a shared cache.
- `Mapping` calls only `BlockTypes` contracts and Platform; `Blocks` fetches the sample and calls the `Mapping` contract. Respect `tests/Architecture`.

**Never:** Filter, group, aggregate, sort or top-N stages (Epic 5); wizard UI wiring or plan authoring (Stories 3.11–3.15); `json_decode`; a default for any `pending_input` value; inventing the C6 p95 (recorded as pending in Implementation Notes).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Happy path | Draft with sample and valid plan | 200 `data.payload` from Shaper, `unavailable` empty, `sample_revision` | N/A |
| Missing field | A mapped path absent | That slot null, `unavailable.<slot> = missing`; others render | N/A |
| Wrong type / many values | Object, array, or `[*]` with 2+ hits | `wrong_type` / `not_single_value` | N/A |
| No sample / no plan | Draft lacks either | 422 `mapping.no_sample` / `mapping.no_plan` | no engine run |
| Unknown plan version | `v` is 2 | config save refused with `config.query_plan.v` | 422 `blocks.invalid_config` |
| Guard exceeded | Limit passes during run | 422 `mapping.guard_exceeded`, no payload | metric still emitted |
| Throttled | Over membership limit | 429 `retry_after` | pane keeps last preview |
| Setting unset | Any preview setting unset | 422 naming the env var | nothing run |
| Read-only viewer | Lock held by another Admin | 200 as normal | N/A |
| Draft gone / published | Discarded / published | 404 / 409 as the sample routes | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Mapping/` -- new module: `Contracts/` (QueryExecutor, PlanResult, GuardExceeded, InvalidPlan), `Application/QueryPlanEngine.php`, `Infrastructure/InProcessExecutor.php`, settings reader; `tests/Architecture/dependencies.php` already allows `Mapping => BlockTypes`.
- `block-types/kpi-card/v1/Shaper.php`, `app/Modules/BlockTypes/Contracts` -- Shaper via registry; slot keys in `KpiCardSlots`; Shaper is pure, so per-slot reasons are the engine's.
- `app/Modules/Blocks/Application/ManageDraftSamples.php`, `Contracts/DraftSamples::find` -- sealed sample read (`StoredSample->text`, `->sampleRevision`); `app/Platform/Json/LosslessJson`, `SamplePath` -- decode and select.
- `app/Modules/Blocks/Application/DraftConfig.php` -- `normalise()` drops unknown keys; add validated `query_plan` (v 1, alias and slot keys, stored-path syntax) with `InvalidDraft` errors under `config.query_plan.*`.
- `app/Http/Controllers/Admin/DraftSampleController.php`, `routes/api.php`, `app/Http/Navigation/ShellNavigation.php` (ADMIN_API_ROUTES) -- controller pattern, `AdminApiError`, route mapping; new `DraftPreviewController` + `PreviewDraft` in Blocks.
- `app/Modules/Connector/Application/SampleFetchRateLimit.php`, `Infrastructure/SampleFetchSettings.php` -- pattern for limiter and settings; `config/dashflow.php`, `.env.example` -- new `preview` block.
- `app/Support/Observability/MetricEmitter.php`, `OtelMetricEmitter.php`, `MetricName` -- add histogram `record`; update fakes.

## Tasks & Acceptance

**Execution:**
- [ ] `config/dashflow.php`, `.env.example`, `README.md` -- `preview` settings, all `pending_input` -- no invented numbers
- [ ] `app/Modules/Mapping/**` -- port, engine, in-process executor, guards, plan validation -- AD-11/12, AR-15
- [ ] `app/Support/Observability/*` -- histogram `record` -- `dashflow.mapping.preview_duration`
- [ ] `app/Modules/Blocks/Application/DraftConfig.php` -- accept and validate `query_plan`
- [ ] `app/Modules/Blocks/**`, `app/Http/Controllers/Admin/DraftPreviewController.php`, `routes/api.php`, `ShellNavigation.php`, error codes -- preview use case, route with limiter, errors
- [ ] `tests/**` -- Mapping unit tests, DraftConfig, endpoint tests covering every matrix row, architecture tests

**Acceptance Criteria:**
- Given a Draft with a sample and valid plan, when preview is requested twice, then both return the same payload and the second does not re-parse the sample in that worker.
- Given any matrix row, when run, then the listed outcome occurs and no preview refusal leaks sample content.

## Implementation Notes

C6 spike: preview latency p95 on CI hardware is **pending**; target `DASHFLOW_PREVIEW_P95_TARGET_MS` is unset and the metric is emitted so it can be measured. Decision (server preview vs TypeScript evaluator) is deferred until measured.

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| UnknownBlockType uncaught gives 500 | medium | patch | Now 422 `mapping.block_type_unavailable`; tested. |
| Unshaped slots missing from `unavailable` | medium | patch | Now null with reason `missing`; tested. |
| Plan replacement, throttle ordering, 503 and invalid stored plan unasserted | medium | patch | Tests added; vacuous assertion fixed. |
| Plan cannot be cleared by a null `query_plan` | low | defer | Plan authoring belongs to 3.11-3.15. |
| Plaintext tree cached past sample TTL / discard; stale revision after purge | maybe-false | defer | Needs purge hook or `created_at` in key. |
| Guards checked between evaluations, not inside `SamplePath::select` | medium | defer | Needs a budget inside the Platform path evaluator. |
| No workspace-level preview limit; p95 target unread; DecimalMath unused | low | rejected | Spec names only a per-membership limit; `p95_target_ms` is recorded-only; DecimalMath is the specified helper. |
| Published-Draft 409 untested | low | defer | State cannot exist until Story 3.19. |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green
