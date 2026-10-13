---
title: '5-4 Text templates, tokens and footer action target'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: '57a64c2'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/epic-4-context.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** A text slot can only show one field as it is, and a Block's footer link cannot be configured.

**Approach:** Let any text slot take a Text Template of literal text and tokens (`{field}`, `{field|format}`, `{ROW_COUNT}`, `{period.start|MMM d}`, `{user.name}`), rendered to a viewer-free wire form on the server and filled in the viewer's locale and profile in the browser, and let the footer action be an external link with a URL template or the Expanded view.

## Boundaries & Constraints

**Always:** The server result is the same for every viewer: no `{user.*}` value in it. Numbers and dates are formatted in the browser with `Intl`. A token with no value shows the Slot's empty display, never 0 or "undefined". A link's scheme and host are literals and its tokens are percent-encoded in place.

**Never:** No value can become markup or a token (braces in data are doubled). No script or non-http(s) scheme in a link, no token in the host. No expression in a template.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Tokens | `{ROW_COUNT} requests`, `{done}/{total} tasks` | rendered with the rows and values | N/A |
| Period | `{period.start|MMM d} – {period.end|MMM d, yyyy}` for May 2026 | "May 1 – May 31, 2026" in en-US | N/A |
| Unknown field or format | `{ghost|currency}` | refused naming the token and position, Continue blocked | nothing stored |
| Null or missing value | `{gone}` | the empty display | N/A |
| `{user.name}` | cached result | unresolved on the server, the viewer's own name in the browser | N/A |
| Footer URL | `https://erp.example.com/a?user={user.email}` for `a&b@x.com` | `a%26b%40x.com` in place, ↗ cue | non-http(s), token host: `msg:url-invalid`, blocked |
| Markup in a value | `<img src=x onerror=alert(1)>` | literal text | N/A |

</frozen-after-approval>

## Code Map

- `app/Modules/Mapping/Application/Expressions/TextTemplate.php` (grammar), `Contracts/Templates.php` (config key, `tpl:` path, wire form), `Infrastructure/InProcessExecutor.php` (render), `Application/AutoMapper.php` (resolve, fields, picker entry, faults), `Contracts/{QueryPlan,QueryPlans,QueryExecutor}.php` (templates key, period).
- `app/Modules/Blocks/Application/{ManageDraftSlots,DraftConfig,FooterAction,FrozenConfig,ReadBlockCards,PreviewDraft}.php`, `app/Modules/Results/Application/ComputeResults.php` (period), `app/Modules/Dashboards/Application/ManageDashboards.php` (footer on the card).
- `resources/js/lib/{textTemplate,blockTemplates}.ts`, `resources/js/components/wizard/{TemplatesSection,MapDataStep,SlotRow,SlotChecklist,ConfigureBlockStep,LivePreview,PreviewStep}.vue`, `resources/js/components/BlockCard.vue`.

## Tasks & Acceptance

**Execution:**
- [x] Template grammar with positions, closed formats, safe wire form
- [x] Rendering with rows, period and empty markers; the period reaches compute and preview
- [x] `config.templates`, endpoint, picker entry, plan key, faults
- [x] Footer action config, validation, freeze, card and link
- [x] Editor section, picker flow, locale and profile expansion
- [x] Tests: Unit, Database, vitest

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit, database and component tests.

## Implementation Notes

- Wire form (a text slot's cached value): `{{`/`}}` braces, `{-}` empty, `{#n|fmt}` number, `{@date|pattern}` date, `{user.key}` viewer. Everything else is literal and shown as text; a brace in data is doubled before it is written.
- A slot with a template is read from the stored path `tpl:<slot>`; the template is the slot's mapping, removing it gives the slot back to auto-map.
- `ROW_COUNT` is the rows left after the row stages; without them it is the longest list any path of the plan selects.
- `currency` uses the Block's headline currency code when it has one, and writes the plain number otherwise (no currency is invented).
- The footer is `config.display.footer` on a Draft save, frozen with the Block; its label takes literal text and `{user.*}` only. The Expanded view target draws no link until Story 5.9.

## Verification

- Pest (Unit, Architecture, Feature), Database suite, vitest, `pint`, `phpstan`, `vue-tsc`, `vp check`, colour lint.

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---------|---------|-------|----------|
| Formatting must follow the viewer's locale, but results are cached for all | medium | fixed | The server writes a viewer-free wire form; the browser formats. |
| Braces in data could inject a token | high | fixed | Every brace in data and literals is doubled, tested with `{user.name}` in a value. |
| Footer label with field tokens would need per-viewer data | low | accept | Labels take literal text and `{user.*}` only. |
| Expanded view link and editor-row preview | low | defer | Two entries in `deferred-work.md`. |
