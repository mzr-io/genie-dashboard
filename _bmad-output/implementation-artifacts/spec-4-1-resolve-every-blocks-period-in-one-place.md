---
title: '4-1 Resolve every Block''s period in one place'
type: 'feature'
created: '2026-10-12'
status: 'done'
route: 'dispatch'
baseline_commit: '18db49d'
review_loop_iteration: 0
context: []
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** FR-35 period precedence has no owner. The wizard preview, validation and (soon) dashboards would each decide a Block's date window, so they could disagree, drift across time zones, or accept client-supplied dates.

**Approach:** Add the pure kernel function `Platform\Period\PeriodResolver` (AD-27) returning `ResolvedPeriod{token, start, end, comparison?}` (half-open, Workspace-local dates) and a Workspace timezone setting. The Epic 3 preview, fetch, fetch-as-user and validation get their dates from it through one Blocks helper using the Block's existing Default date range (`source.default_date_range`); clients no longer send dates. An architecture test forbids a second implementation.

## Boundaries & Constraints

**Always:** Precedence is own-selector choice, then dashboard Date Range (Follow dashboard only), then the Block default. Windows are `[start, end)` in the Workspace timezone; user timezone is display only. Only presets in the Block's enabled list are accepted. Weeks start Monday. All input outside the catalogue is rejected with a 422 validation error, never coerced.

**Never:** No client absolute dates (a Block's `custom` default range is out of scope). No second precedence implementation. No Workspace-timezone editing UI (Epic 8). No engine/query-plan time filter (Epic 5), no dashboards, selectors or pre-warming (4.4, 4.15, Ingestion).

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Follow dashboard | behaviour follow, dashboard `last_7_days`, default `this_month` | dashboard range window | N/A |
| Follow, no dashboard range | dashboard null | Block default window | N/A |
| Own selector | selection `last_month`, dashboard `this_year` | selection wins | N/A |
| Own, no choice | selection null | default is the initial value | N/A |
| Not date-filtered | any selection / range | null (all input ignored, even invalid) | N/A |
| Preset not enabled | selection `this_year`, enabled `[this_month]` | rejected | 422 |
| Absolute date / unknown | `2026-01-01` | rejected | 422 |
| Zone boundary | now `2026-10-31T23:30Z`, tz `Asia/Dhaka` | `this_month` = Nov 1 to Dec 1 (local) | N/A |

</frozen-after-approval>

## Code Map

- `app/Platform/Json/` -- sibling kernel packages, namespacing/style to follow; kernel calls no module
- `app/Platform/Tenancy/WorkspaceSettings.php` -- add `timezone()` read; table `workspace_settings` (migration `2026_10_07_100001`)
- `app/Modules/Blocks/Application/DraftSource.php` -- stores `source.default_date_range` `{preset, from?, to?}` (Story 3.7); now validates presets against the platform catalogue
- `app/Modules/Blocks/Application/{StartDraftSampleFetch,StartDraftValidation,ManageUserSamples}.php` -- took client `range`/`period`; now derive them
- `resources/js/lib/blockSource.ts` -- `fetchBodyOf` computed client dates
- `app/Modules/Blocks/Application/PreviewDraft.php` -- builds `DraftPreview`; add resolved period
- `app/Modules/Blocks/Contracts/DraftPreview.php` -- preview DTO
- `tests/Architecture/dependencies.php`, `tests/Architecture/ShaperPurityTest.php` -- architecture test patterns

## Tasks & Acceptance

**Execution:**
- [x] `app/Platform/Period/{PeriodPreset,PeriodBehaviour,ResolvedPeriod,InvalidPeriodInput,PeriodResolver}.php` -- pure resolver, catalogue and result -- AD-27
- [x] `database/migrations/*_add_timezone_to_workspace_settings.php` + `WorkspaceSettings::timezone()` -- expand-only, default `UTC`, invalid stored zone falls back to UTC
- [x] `DraftSource.php`, requests, controllers, Start* services, JS fetch/validation callers -- server derives `range`/`period` from the Draft's default range; client `range`/`period` are 422
- [x] `app/Modules/Blocks/Application/DraftPeriod.php` + `PreviewDraft.php` + `DraftPreview.php` + preview controller resource -- preview returns `period` resolved from the Block default
- [x] `tests/Unit/PeriodResolverTest.php`, `tests/Architecture/PeriodResolverTest.php`, preview, fetch and validation tests -- conformance table, sole owner, wiring

**Acceptance Criteria:**
- Given the I/O matrix, when the resolver runs, then each row holds and the conformance table of presets x timezones x boundaries passes.
- Given any file outside `app/Platform/Period` that computes period precedence or preset windows, when the architecture test runs, then it fails naming the file.
- Given a Draft with a `default_range`, when it is previewed, then the response carries the period produced by the resolver in the Workspace timezone.

## Implementation Notes

- Reused the Story 3.7 Default date range (`source.default_date_range`; presets `today`, `this_week`, `this_month`, `current_year`, `custom`) instead of adding new Block config. The catalogue adds yesterday, last week/month/quarter/year, this quarter and rolling 7/30/90 days; the wizard UI still offers the original four plus custom. No Block-enabled preset list exists yet, so `enabled` is empty and only the default is accepted until the Own selector story (4.15) adds one.
- `custom` is the Admin's own dates, accepted only as the Block default, never as a selection or dashboard Date Range.
- The fetch routes take the form's range as a choice (`default_date_range`) because the form may be unsaved; validation reads the saved Draft. `range`/`period` in any request is a 422 (`prohibited`).
- Workspace tz is `workspace_settings.timezone` (default UTC; Epic 8 adds the editor). Migration rollback-step counts in 7 Database tests bumped by one.
- Preview returns `period`; the engine has no period input until Epic 5, so there is nothing further to pass it to.
- Deferred: the wizard's client-side `resolveRange` (blockSource.ts) still computes date labels for the parameter rows in the browser's time zone, display only. It should be replaced by server-resolved values (UX P11) when the Draft response can carry them.

## Verification

- SQLite suite (Unit, Architecture, Feature): 2057 passed. Database suite against PostgreSQL 18 and PgBouncer: 1677 passed. Vitest: 1350 passed.
- `pint --test`, `phpstan analyse`, `vue-tsc --noEmit` and `vp check` are clean.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| Client `resolveRange` still computes parameter-row labels in the browser time zone | low | defer | Display only; nothing it computes is sent. Logged in deferred-work.md. |
| `enabled` presets always empty, so a non-default selection is always 422 | low | defer | No consumer sends a selection until 4.15; the Block-enabled list belongs to that story. |
| Fetch reads the form's range, validation the saved Draft | false | none | Intended: the fetch form may be unsaved (bindings travel the same way), and validation must check what will be published. |
| Preview calls resolver but the engine takes no period | false | none | Engine has no time filter until Epic 5; `period` is returned for the UI. |
