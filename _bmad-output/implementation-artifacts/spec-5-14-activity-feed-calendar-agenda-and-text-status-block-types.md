---
title: '5-14 Activity feed, Calendar/Agenda and Text/Status Block Types'
type: 'feature'
created: '2026-10-13'
status: 'done'
route: 'dispatch'
baseline_commit: 'f84ce7e'
review_loop_iteration: 0
context: ['{project-root}/_bmad-output/implementation-artifacts/spec-5-13-list-and-progress-list-block-types.md']
---

<frozen-after-approval reason="human-owned intent — do not modify unless human renegotiates">

## Intent

**Problem:** Recent events, upcoming items and simple statuses cannot be shown on a dashboard (Story 5.14; FR-26, FR-29, FR-32, UX-DR-95 to 97, UX-DR-99).

**Approach:** Add `block-types/activity-feed/v1/` (actor, action, object, timestamp, avatar), `block-types/calendar-agenda/v1/` (date, start time, title, subtitle, duration, category; week strip setting) and `block-types/text-status/v1/` (one text or status value; Text or Status mode with threshold bands or value matches), on the shared Block frame.

## Boundaries & Constraints

**Always:** "How long ago" is the viewer's locale and clock, kept true by a timer without a reload; a time still to come shows its date; one that is not a date shows "—" and the Block is partial. A date is a calendar day and a time the wall clock as written: neither is moved by a time zone. Today in the week strip is the day in the viewer's time zone, with `aria-current="date"` and "Wednesday 22 May, today". An agenda row reads "09:30, Product sync, Meeting room 2A, 45 min"; the category is written beside its 3px chart-palette rule. A status is a pill with the band's colour, icon and word; a value in no band is neutral text with a word and an icon. Text is clamped to three lines and whole in "About this block".

**Never:** No zero for a missing value; no colour as the only signal; no value becomes markup or a token.

## I/O & Edge-Case Matrix

| Scenario | Input / State | Expected | Error Handling |
|----------|--------------|----------|----------------|
| Recent | 8 minutes old | "8 min. ago" in the locale | N/A |
| Future | tomorrow | the formatted date | N/A |
| Junk | "soon" | "—", partial | N/A |
| Strip | Wed 22 May | today circled, `aria-current` | N/A |
| Agenda | 09:30 Product sync | spoken with place and length | N/A |
| Status | "On track" in a band | ✓ pill with its word | N/A |
| No band | "Weird" | neutral text, word and icon | N/A |
| Long text | many lines | three, then an ellipsis | N/A |

</frozen-after-approval>

## Code Map

- `block-types/{activity-feed,calendar-agenda,text-status}/v1/*`, `composer.json`, `block-types/pins.json`, `PackageShapers.php`, generated slot classes and `block-types.ts`.
- `Presentation.php` (`week_strip`, `text_mode`), `PresentationOptions.php` (band of a text value, the two settings).
- `resources/js/lib/{blockRenderers,blockWizard,textTemplate,blockPresentation}.ts`, `components/{BlockCard.vue,BlockThumbnail.vue,wizard/PresentationEditor.vue,wizard/LivePreview.vue}`, `locales/labels.ts`.
- `tests/Unit/ActivityAgendaStatusTest.php`, `tests/js/activity-agenda-status.test.ts`.

## Tasks & Acceptance

**Execution:**
- [x] Three packages: schema, Shaper, payload, table, Renderer
- [x] Week strip and text mode settings; the band of a status value
- [x] Registration: autoload, pins, Shapers, Renderers, tables, wizard list, thumbnails, preview regions
- [x] Editor controls; full text in "About this block"
- [x] Tests: Unit, vitest, registry counts

**Acceptance Criteria:**
- Given the I/O matrix, each row holds in the unit and component tests.

## Verification

Pest Unit, Feature, Architecture and Database suites, vitest, `vp check`, `vue-tsc`, colour and renderer lints, block-type pins and generator checks, PHPStan and Pint.

## Implementation Notes

- The status word is the band's label; a value that is not a word (a number in a threshold band) is judged by the band and shown as that label.
- The text Block has no rows, so it offers no "View as table".
- Times are the wall clock of the data as written. Zone conversion of instants (`timestamp` of the feed) is the viewer's, through the shared formatters.
