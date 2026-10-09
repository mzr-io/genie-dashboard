---
title: 'See the KPI card in every state, rendered safely and exactly'
type: 'feature'
created: '2026-10-09'
status: 'done'
baseline_commit: 'ad495e56fb646551939e8ce7cacf575dec492017'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-3-context.md'
  - '{project-root}/_bmad-output/implementation-artifacts/spec-3-1-register-the-block-type-contract-v1-and-the-kpi-card-package.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** The kpi-card package is a stub: it has no render payload and cannot show Normal, Loading, Empty, Stale, Unavailable or Error states, so an Admin cannot trust what a preview shows.

**Approach:** Make the pure Shaper emit a typed render payload (decimal strings plus format descriptors, no formatted text) and build `Renderer.vue` to draw all six states, format with Intl through the existing `resources/js/lib/format.ts`, show an accessible delta, and render every slot as literal text.

## Boundaries & Constraints

**Always:** Measures stay decimal strings end to end (never a JS float). Format descriptor kinds: number, decimal places, thousands separator, percent, currency code and symbol, compact, date pattern, relative time, duration, units suffix. Locale chain: user locale (configured in format.ts), then an optional `workspaceLocale` prop, then `en`. Unavailable never shows "0", "NaN" or "—" as the headline; User uses catalogue `unavailable-user`, Admin `unavailable-admin` with `{field}`. Delta always has an `aria-hidden` arrow glyph or "No change"; "Lower is better" appends "· better"/"· worse"; hidden text states direction, value, label, judgement; colour never the only signal. Loading: skeleton bars, after `STILL_LOADING_MS` a "Still loading…" caption (`controlLabels.stillLoading`) and shimmer stopped. Stale: muted values, clock icon, `stale` message in warning. Empty: centred muted caption. Error: icon, message, Retry link (emits `retry`). Body is a focusable scroll region labelled with the block title; nothing clipped at 200% zoom. Colours via tokens only. Every state is a Vitest fixture. Shaper stays pure. Extra copy goes in `labels.ts` (`kpiCardLabels`), keeping the 89 catalogue keys.

**Never:** No `v-html`/`innerHTML`/formatter strings. No pre-formatted locale strings in the payload. No new catalogue keys. No wizard, mapping or Query Plan engine work. No workspace default locale backend.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Currency | `284680.10`, USD, `en-US` | "$284,680.10" | N/A |
| Precision | 20-digit decimal string | every digit kept | N/A |
| Delta up/down/flat | positive, negative, zero | arrow + value; flat shows "No change" | N/A |
| Lower is better | negative change | "· better" in delta colour; hidden judgement text | N/A |
| Missing headline | slot unavailable | warning icon + `unavailable-user` (or admin variant); never 0/NaN/— | N/A |
| States | loading/stale/empty/error | per Always | Retry emits `retry` |
| XSS | `<img src=x onerror=alert(1)>` or `"><script>` in label, note, comparison label, badge | shown literally, nothing executes | N/A |
| Overflow | long label / 200% zoom | scrolls inside focusable labelled body | N/A |

</frozen-after-approval>

## Code Map

- `block-types/kpi-card/v1/{Shaper.php,Renderer.vue,schema.json}` -- stub Shaper returns input; stub Renderer; schema has no format descriptor yet
- `resources/js/lib/format.ts` -- `formatNumber/formatCurrency/formatDate` take decimal strings, no floats; reuse, never call Intl elsewhere
- `resources/js/locales/{en.ts,labels.ts}` -- `unavailable-user/admin`, `stale`; add `kpiCardLabels`; `controlLabels.stillLoading`
- `resources/js/composables/useStillLoading.ts`, `components/ui/skeleton`, `FetchErrorCard.vue` -- reuse; icons from `@lucide/vue`
- `resources/css/tokens.css`, `scripts/lint-colors.mjs` -- tokens only
- `app/Support/BlockTypes/BlockTypeGenerator.php`, `resources/js/types/block-types.ts` -- regenerate (`dashflow:block-types`) if schema.json gains payload/format types
- `tests/Architecture/ShaperPurityTest.php` -- Shaper must stay pure
- `tests/js/block-type-guards.test.ts`, `tests/js/format.test.ts` -- test style (happy-dom + @vue/test-utils); `@block-types` alias

## Tasks & Acceptance

**Execution:**
- [ ] `block-types/kpi-card/v1/Shaper.php` -- emit payload: decimal-string measures, typed values, format descriptor, no formatted strings -- AC 1
- [ ] `block-types/kpi-card/v1/schema.json` (+ regenerate) -- declare format descriptor/payload types if the generator needs them -- single source
- [ ] `block-types/kpi-card/v1/Renderer.vue` (+ helper .vue/.ts under the package) -- six states, Intl via format.ts, delta, a11y, scroll region
- [ ] `resources/js/locales/labels.ts` -- `kpiCardLabels` strings
- [ ] `tests/js/kpi-card-renderer.test.ts` -- every state fixture, currency/precision, delta cases, unavailable, XSS through each slot, overflow region
- [ ] `tests/Unit/KpiCardShaperTest.php` -- payload shape, decimal strings, no formatted strings

**Acceptance Criteria:**
- Given the I/O matrix rows, when the Shaper and Renderer run, then each behaves as listed.
- Given the repo checks, when `bin/tools composer ci:check` runs, then it passes (colour, renderer lint, Shaper purity, drift).

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route / evidence |
|---|---|---|
| Percent with `''`, `.`, `e5` renders "0%" (unformattable guard bypassed) | medium | patch |
| Huge exponent on percent throws RangeError during render | medium | patch |
| Delta colour is the only good/bad signal when not "lower is better"; spoken judgement missing | medium | patch: always speak favourable/unfavourable, colour only when judged |
| Delta that rounds to zero shows arrow and colour | medium | patch: treat as flat |
| Comparison unformattable gives a bare arrow | medium | patch |
| Stale with missing/unparsable `staleSince` renders blank message | medium | patch: generic stale label |
| Currency code `USD\n` passes `$` regex | low | patch (`\z`) |
| Tests: formatDuration/formatRelativeTime exact output, currencyDisplay code, stale+unavailable headline | medium | patch: add tests |
| Date/relative-time kinds unreachable via Shaper (headline slot is number) | low | reject: date slots arrive with later block types |
| Shaper-to-payload.ts contract/schema duplication; icon renders as text | low | defer |
| Currency null code falls back to number; whitespace-only text; duration exponent; midnight `today`; invalid locale flag; copy not catalogue; warning token contrast | low/false | reject: no demonstrated user harm or token `--df-warning` is text-grade |
| Focusable region on every card | false | spec requires a focusable labelled region |

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: all green
