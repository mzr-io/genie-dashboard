---
title: 'Serve all product copy from the canonical message catalogue'
type: 'feature'
created: '2026-10-06'
status: 'done'
baseline_commit: 'cad22c04814eee39cfd6533339988380b1c67d1d'
route: 'dispatch'
review_loop_iteration: 0
context:
  - '{project-root}/_bmad-output/implementation-artifacts/epic-1-context.md'
  - '{project-root}/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md'
  - '{project-root}/_bmad-output/planning-artifacts/epics.md'
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Product copy is written inline in components, so wording drifts and nothing checks it (Story 1.7; NFR-11, UX-DR-161, 162, 282). The Story 1.7 section of `epics.md` holds the acceptance criteria and UX-DR-282 lists the 89 keys.

**Approach:** One vue-i18n catalogue (locale `en`) holds exactly the 89 canonical messages of EXPERIENCE.md → Voice and Tone → Canonical messages, with parameters in place of example values. Tests prove key parity, verbatim text, paired announced variants and the copy rules, and an Admin-only "Technical details" component renders error details.

## Boundaries & Constraints

**Always:** The catalogue is `resources/js/locales/en.ts`, installed in `createInertiaApp` with locale and fallback `en`. Its top-level keys are exactly the 89 names, with no extras. Do text is verbatim, with real values replaced by named parameters (`{block}`, `{time}`, `{count}`, `{user}`, `{line}`, `{items}`, `{query}`, `{version}` and others the rows need); no example value such as "Revenue overview", "10:42" or "214" appears in the catalogue. A key with several Do texts (`required-slot-missing`, `datasource-none`, `save-failed`, `stale`, `stale-aggregate`, `wizard-subtitles`, `page-subtitles`) is an object of named sub-messages. Keys with announced variants (`toast-add`, `toast-add-below`, `toast-remove`, `session-warning`, `stale`, `stale-aggregate`) hold the paired screen-reader text as an `announce` sub-key, so the top-level count stays 89. `access-impact` renders the count in bold through component interpolation, never `v-html`. Control labels that are not canonical messages ("Technical details", "HTTP status", "Field path", "Request ID", "Copy request ID") live in a separate local-scope labels module, not in the catalogue. Component `TechnicalDetails` shows a collapsed disclosure (`aria-expanded`) with status, field path, request ID and a "Copy request ID" button only when `area` is `admin`; for any other area it renders nothing. Catalogue copy is sentence case, with no exclamation marks, emoji (the symbols ✓ ▸ → ↓ ⌘ · … are allowed) or RMG/domain terms.

**Never:** Rewriting existing starter-kit page copy (later stories consume the keys); a second locale; `v-html`; exposing HTTP codes, JSON paths or stack traces in the User area; changing the backend error envelope.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected Output / Behavior | Error Handling |
|----------|--------------|---------------------------|----------------|
| Key parity | Catalogue loaded | Exactly the 89 expected keys | Test names each missing or extra key |
| Verbatim text | Each key rendered with the example parameters | Equals the Do text in EXPERIENCE.md | Test names the key |
| Announced pair | The six variant keys | Each has an `announce` sub-key | Test fails on a missing pair |
| Bold count | `access-impact`, count 38 | Text "38 users will lose this block." with the count in `<strong>` | N/A |
| Duplicate copy | A Do or Don't string hard-coded in a component | Lint test fails, naming file and string | N/A |
| Copy rules | Any catalogue string | No `!`, emoji, domain term; sentence case | Test names the key |
| Admin details | `area` admin, status 502, path, request ID | Collapsed disclosure with the three values and Copy button | Copy failure leaves the ID visible |
| User details | `area` user | Nothing rendered, no status, path or ID in the DOM | N/A |

</frozen-after-approval>

## Code Map

- `resources/js/app.ts` -- `withApp` installs the i18n plugin; `package.json` already has `vue-i18n` ^11
- `resources/js/locales/en.ts`, `resources/js/locales/labels.ts` -- new catalogue and local labels
- `resources/js/lib/` -- small `i18n.ts` setup helper beside `flashToast.ts` and `brand.ts`
- `resources/js/components/TechnicalDetails.vue` -- new; follows `components/ui` primitives and token classes
- `tests/js/gates.test.ts`, `tests/js/tokens.test.ts` -- existing vitest style; add `tests/js/catalogue.test.ts` with an in-test fixture of the 89 rows (key, Do, Don't, example parameters) taken from EXPERIENCE.md; render components with `vue/server-renderer`, no new dev dependency
- `scripts/lint-colors.mjs` -- model for the duplicate-copy scan; `resources/js/components/ui/*` and generated dirs stay out of scope

## Tasks & Acceptance

**Execution:**
- [x] `resources/js/locales/en.ts`, `labels.ts`, `resources/js/lib/i18n.ts`, `resources/js/app.ts` -- catalogue, labels and plugin install
- [x] `resources/js/components/TechnicalDetails.vue` -- Admin-only disclosure with Copy request ID
- [x] `tests/js/catalogue.test.ts` -- every matrix row, plus a scan failing on any Do or Don't string in `resources/js` outside locales, generated and `ui` dirs (single generic words may be exempted only if they cause false positives, documented in the test)

**Acceptance Criteria:**
- Given the catalogue loads, when tests run, then it has exactly the 89 keys with verbatim Do text.
- Given `bin/tools composer ci:check`, when run, then it passes, including the new tests.

## Implementation Notes

## Spec Change Log

## Review Triage Log

| Finding | Verdict | Route | Evidence |
|---|---|---|---|
| `TechnicalDetails` renders the text "null" for a null `status` and an empty panel when no detail is given; `area` is a bare string | medium | patch | Backend envelope `details` can omit values; a typo such as `'Admin'` silently hides details. |
| Refused-copy test leaves a stubbed `navigator` behind when none existed | medium | patch | The test restores only `if (original)`; the stub leaks into later tests. |
| "Never `v-html`" is not enforced | low | patch | Nothing scans sources for `v-html`; one grep test closes it. |
| `access-impact` bold count tested only for 38; locale and fallback unasserted | low | patch | Singular and zero grammar go through the same `<I18nT>`; `createCatalogue()` locale is never checked. |
| Copy and toggle behaviour has no mounted test | medium | defer | Needs a DOM test environment; the spec forbids a new dev dependency. |
| Typed call-site keys, missing-key and missing-parameter handling | low | rejected | No consumer exists yet; Story 1.7 requires key parity tests, not call-site typing. |
| Duplicate scan misses wrapped or templated copy; fixture is hand-copied from EXPERIENCE.md | low | rejected | The fixture is the spec's chosen design; stronger scanning adds machinery with no consumer. |
| `block-error` lacks a final period; `—` and `←` outside the spec's symbol list | false | rejected | EXPERIENCE.md Do text for `block-error` has no period and `unavailable-user` contains `—`; the spec's list was incomplete, the test matches the source. |
| "Technical details" in both `fetch-failed` text and the labels module | low | rejected | The row's Do text embeds it verbatim; drift risk is theoretical. |
| Copy status never resets; no clipboard fallback; no SSR entry check | low | rejected | No SSR entry exists; reset and insecure-context fallback are polish. |

## Design Notes

Why `announce` sub-keys: UX-DR-282 wants paired screen-reader text and an exact 89-key catalogue; nesting satisfies both. Why a labels module: "Copy request ID" and the detail labels are controls, not canonical messages, and the catalogue must hold only the 89.

## Verification

**Commands:**
- `bin/tools composer ci:check` -- expected: exit 0
- `bin/tools npm run build` -- expected: exit 0
