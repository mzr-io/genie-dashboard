Findings were addressed in the spines on 2026-10-05; see the Resolution notes in the spines and the decision log (R1–R7).

# rmg-dashboard-platform (Dashflow) — UX Design Validation Report

- **DESIGN.md:** `/home/bs01729/Projects/rmg-dashboard-platform/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/DESIGN.md`
- **EXPERIENCE.md:** `/home/bs01729/Projects/rmg-dashboard-platform/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md`
- **Run at:** 2026-10-05T15:07:17+06:00

## Overall verdict

**Adequate. The pair is usable as a contract, but fix the high findings before story development.** The mechanics are clean: every `{token}` reference resolves (93 unique; the only miss is the URL-template `{user.email}`), every colour token has a hex value, token names are semantic so a dark theme can be added, the sources resolve, DESIGN.md sections are in canonical order, and all PRD journeys are covered by Key Flows. The weaknesses are coverage gaps a downstream builder would have to invent: three CONFIRMED MVP Block Types (Table, Bar chart, Pie/Donut) have no visual spec; most of Map Data's sub-components and all generic admin tables and form controls have no visual spec; several documented colour pairs fail WCAG AA; the Map Data layout does not fit at 1280 px; and charts have no specified interaction or non-visual access. EXPERIENCE.md is about 1.8× longer than it needs to be, mostly through repeated copy and FR content.

The accessibility review found 1 critical and 10 high findings, so the spines are not yet WCAG 2.1 AA as specified. The foundation is sound, though: the accent-ink text colour, a focus ring that passes 3:1 everywhere, arrows on deltas and words next to status dots, keyboard alternatives for every drag, a defined Esc order, inline reasons for disabled actions, honest Unavailable and Stale states and clear sample-data labelling. The gaps cluster in data visualisation, timing, focus stability, low-contrast boundaries, delta semantics and reflow; none needs a redesign.

After merging findings both reviewers raised, there are 68 findings: 1 critical, 14 high, 32 medium, 21 low. 9 findings credit both reviewers. Reviewer item IDs (for example accessibility C1, rubric §3) are given in each finding's source.

## Category verdicts

| # | Category | Verdict |
|---|---|---|
| 1 | Flow coverage | adequate |
| 2 | Token completeness | adequate |
| 3 | Component coverage | thin |
| 4 | State coverage | adequate |
| 5 | Visual reference coverage | adequate |
| 6 | Bloat & overspecification | thin |
| 7 | Inheritance discipline | adequate |
| 8 | Shape fit | adequate |
| — | Accessibility review (WCAG 2.1 AA) | not yet AA-conformant; 1 critical, 10 high, 21 medium, 13 low before merging |

## Findings by severity

### Critical

**F1: Charts and Table Blocks have no text alternative, keyboard access or user-side behaviour spec**  
*Category:* Component coverage · *Source:* rubric §3 (high) · accessibility S1 (critical), S6 Table row · *Location:* EXPERIENCE → Foundation (Charts), Accessibility Floor; DESIGN → Chart marks

A chart Block conveys its data only visually; the expanded table exists only when an Admin configures Expanded view, so for a product sold to regulated buyers a whole Block type is unusable for blind users (accessibility, S1, SC 1.1.1 / 1.3.1). EXPERIENCE has no Chart or Table component pattern at all: no keyboard focus on data points, no tooltip on focus, no legend interaction, no Table sort or pagination for Users; the Accessibility Floor says only "chart series carry legends or labels" (rubric §3, SC 1.1.1 / 2.1.1).

*Fix:* Add "Chart" and "Table Block" rows to Component Patterns and the Accessibility Floor: every chart is `role="img"` with a generated `aria-label` summary; every chart Block's ⋯ menu always has "View as table" (reusing Expanded view, `th` scope and units) regardless of the Expanded view setting; ← / → step through points with a polite announcement and a tooltip on focus; the legend is a list naming each series with its marker; Table headers are sort buttons with `aria-sort`, and pagination behaviour is specified.

### High

**F2: Documented text-on-fill pairs fail 4.5:1**  
*Category:* Token completeness · *Source:* rubric §2 (high) · accessibility C5 (medium) · *Location:* DESIGN → button-destructive-soft, slot-row state table (Optional, Required missing, Type mismatch), stepper-step

`error` on `error-soft` = 4.41 ("× Remove" in every drawer row, the Required-missing pill, whose text colour is also unspecified); `text-muted` on `surface-muted` = 4.39 (Optional pill, 11 px upcoming stepper numeral); `text-muted` on `error-soft` = 4.42 (captions in error rows). The mapdata-hybrid sketch also has High and Confirmed pills at 3.00. The spine claims AA for every load-bearing pair.

*Fix:* Use `error-text` (5.91) for destructive-soft and the error pills; `text-secondary` (9.37) for the Optional pill, the stepper numeral and error-row captions; add the resulting ratios next to the tokens.

**F3: Form control boundaries are below 3:1 non-text contrast**  
*Category:* Token completeness · *Source:* rubric §2 (high) · accessibility C3 (high) · *Location:* DESIGN → border-strong, input, select, checkbox, segmented-control

`border-strong` #D1D5DB is 1.47:1 on white and 1.39 on canvas. An unchecked checkbox is identifiable only by that border, and inputs and selects rely on it for their hit area. The checked checkbox edge (`accent-strong`) is 1.92; the check mark carries the state, but the box boundary still fails. Non-text contrast is not stated anywhere (SC 1.4.11).

*Fix:* Add `border-control: #80868F` (3.67 white, 3.45 canvas, 3.51 sunken) for input, select, textarea, checkbox (both states), radio, the method-prefix join and the outer edge of segmented-control; keep `border-strong` for decorative outlines and text-identified buttons; add a "Non-text contrast" line to DESIGN → Colors.

**F4: Chart series 1, 5 and 6 fail non-text contrast**  
*Category:* Token completeness · *Source:* rubric §2 (high) · accessibility C1 (high) · *Location:* DESIGN → colors.chart-1/5/6, Colors → Chart palette, Chart marks, progress-row.fillDefault

The yellow `chart-1` line is 1.53:1 on white and is the only series in most charts; `chart-5` is 2.80, `chart-6` 2.54, and the area fill 1.07. Legends and tooltips help identify a series but do not make the mark perceivable (SC 1.4.11).

*Fix:* Add `chart-1-stroke: {colors.accent-ink}` (4.92) for lines, points and bar outlines, keeping yellow `chart-1` as fill only (it still follows a Workspace accent override); change `chart-5` to #EA580C (3.56) and `chart-6` to #6B7280 (4.83); yellow bars and progress fills get a 1px stroke edge, or state that the % text is the information (see F58).

**F5: Three CONFIRMED MVP Block Types have no visual spec**  
*Category:* Component coverage · *Source:* rubric §3 · *Location:* DESIGN → Block Type faces, Chart marks; EXPERIENCE → Block-Type settings

Table / Data grid, Bar chart (grouped and stacked) and Pie / Donut have no face or tokens; nor does the PROPOSED Text / Status type (FR-32). Block-Type settings omit the Bar stack/series key, the Pie centre value and Text/Status. Renderer stories for 3 of the 5 change-signal Block Types have no visual contract.

*Fix:* In DESIGN add `table-header`, `table-row`, `table-cell-numeric`, sort indicator, pagination, row highlight; `chart-bar` with gap and stacking; `chart-pie` with centre label, slice order and slice border. In EXPERIENCE add settings rows for Bar, Pie and Text/Status.

**F6: Most Map Data parts have no visual spec**  
*Category:* Component coverage · *Source:* rubric §3 · *Location:* EXPERIENCE → Map Data; DESIGN → Components

The spine calls Map Data "the most important pattern in the product", but DESIGN covers only `slot-row*`, `role-chip`, `confidence-*` and `preview-hotspot`. Missing: the sticky status bar and meter, Roles table scalar cards and sample-rows table, ✦ suggestion row, collapsed roles summary, record-path candidate list and badges, "Aggregate rows by…", the Transforms builder, Comparison editor and Threshold rows, the step ② Parameters table and Paste-JSON textarea, the fetch-error card, the hatched missing-slot placeholder and the "Where each part comes from" legend.

*Fix:* Add a "Map Data parts" subsection to DESIGN → Components, naming a token set for each part (mostly reusing existing tokens) so component names match across both spines.

**F7: No generic data-table / list-page component, and several form primitives are missing**  
*Category:* Component coverage · *Source:* rubric §3 · *Location:* DESIGN → Components; EXPERIENCE → admin lists, Access, Mandatory, Expanded view, Edit Layout

Every Admin list (Block management, Draft and Published blocks, Data sources, Audit log, User configuration, Version history, Block categories) and My dashboards is a table or list page with search, filters, row menus and status, and DESIGN specifies none of it. Radio group, toggle/switch, textarea, tooltip, skeleton, two-month date-range calendar, icon picker, Expanded view and the Edit Layout Undo/Redo control are used in EXPERIENCE with no visual spec.

*Fix:* Add `data-table` (header, row, hover, selected, row-menu, status cell, empty and loading rows), `radio`, `switch`, `textarea`, `tooltip`, `skeleton`, `date-range-calendar` and `icon-picker`, reusing existing tokens.

**F8: Map Data does not fit at 1280 px and does not reflow at 320 CSS px**  
*Category:* Component coverage · *Source:* rubric §3 (high) · accessibility R1 (high) · *Location:* DESIGN → slot-row.columns, spacing.preview-pane-width; EXPERIENCE → Responsive & Platform (Wizard)

The slot row has 548 px of fixed columns plus a `1fr` presentation column (about 600 px minimum with gaps). Beside the fixed 384 px preview pane, the 232 px sidebar and 28 px gutters, the presentation summary at 1280 px gets about 36 px; the 1024–1279 row says only "preview pane narrows" (rubric). At 400% zoom on a 1280 px screen (320 CSS px) the user gets the mobile layout and a banner telling them to use a desktop; the slot row, expanded three-column editor, scalar cards and transform sentences have no narrow layout, and the slot checklist is not a data table, so it is not exempt from reflow (accessibility, SC 1.4.10).

*Fix:* Commit to a laptop behaviour (collapse the sidebar to the icon rail inside the wizard below 1440 px, wrap the presentation summary under the field below a stated width, or add `preview-pane-width-compact`) and add a breakpoint row. Below 640 px stack slot rows (status + name, field, presentation, pill + actions), stack the expanded editor, show one scalar card per row and wrap transform sentences; let sample rows and JSON scroll inside a focusable labelled region; make the "wider window" banner dismissible and non-blocking.

**F9: No session-timeout behaviour is specified**  
*Category:* State coverage · *Source:* rubric §4 (medium, session-expiry part) · accessibility T3 (high) · *Location:* EXPERIENCE → State Patterns, Password recovery ("Remember me … maximum duration TBD"), wizard

Regulated customers run short idle timeouts. The Map Data step is long and dense, and an unwarned expiry loses the Admin's work (accessibility, SC 2.2.1). State Patterns has no session-expired treatment for the wizard or other forms, even though the idle-timeout value is TBD (rubric §4).

*Fix:* Two minutes before idle expiry, a dialog: "You'll be signed out in 2:00 for security. Stay signed in", announced at 2:00, 1:00 and 0:30. Save wizard state as a local draft continuously and restore it after re-authentication in a dialog without losing the form. On return after expiry: "You were signed out to protect your workspace. Your draft was saved."

**F10: The chart palette is not colour-vision-deficiency safe**  
*Category:* Accessibility · *Source:* accessibility C2 · *Location:* DESIGN → Chart palette; .memlog V5

Slate (chart-2) vs violet (chart-4) is ΔE 8.9 protan and 9.1 deutan, and only 16.8 for typical vision; teal/grey and yellow/orange are also weak. The legend swatch is the only link from a line to its name, so in multi-series charts colour is the sole identifier (SC 1.4.1, 1.4.11).

*Fix:* Adopt the proposed palette (chart-1-stroke #A16207, chart-2 #4C6EB1, chart-3 #0F766E, chart-4 #DB2777, chart-5 #EA580C, chart-6 #6B7280). Make secondary encoding mandatory: every chart with 2+ series uses direct end-of-line labels or per-series dash and marker shapes; bar, pie and stacked series use patterns from series 3; legend items show the same dash or marker.

**F11: Active option and hotspot focus are not visible**  
*Category:* Accessibility · *Source:* accessibility C4 · *Location:* DESIGN → Popovers, preview-hotspot.hover; EXPERIENCE → Click-on-preview hotspots

In `aria-activedescendant` listboxes the highlighted option is the focus indicator (command palette, field picker, field tree, role menu, dashboard switcher, workspace list). An accent-soft fill on white is 1.07:1, the field tree's picked inset is 1.53, and the hotspot outline is 1.48 (SC 2.4.7, 1.4.11).

*Fix:* The active option in every listbox, menu, tree and palette shows a 2px `focus-ring` inset outline (or 3px left bar) plus the accent-soft fill. Hotspots use the standard focus ring on keyboard focus; the accent outline is for hover only.

**F12: Focus is lost when content re-renders**  
*Category:* Accessibility · *Source:* accessibility K1 · *Location:* EXPERIENCE → Date Range refresh, Live refresh, Remove, roles-table auto-collapse (T4), Needs-review filter, low-confidence pinning, optimistic rollback

A focused row replaced by a skeleton, the "Set as Measure" button that vanishes when the roles table auto-collapses (Flow 1, step 5 → 7), a row leaving the Needs-review filter, and a Block removed from its own ⋯ menu can each drop focus to `<body>` (SC 2.4.3).

*Fix:* Add a Focus stability subsection: refreshes update in place with stable keys (skeletons only on cold load); when the focused element must go, move focus to the nearest logical survivor and announce it politely; the roles table never auto-collapses while focus is inside it; pinning and sorting are computed on load and on Re-run auto-map, never while editing.

**F13: Whether a delta is good or bad is conveyed by colour alone**  
*Category:* Accessibility · *Source:* accessibility S2 · *Location:* DESIGN → KPI card, Admin metric cards; EXPERIENCE → Direction

The arrow gives direction; good versus bad is carried only by colour. With "Lower is better" an unfavourable ↗ in red has the same shape as a favourable ↗. Screen readers read the glyph as "north east arrow" or skip it (SC 1.4.1, 1.1.1, 1.3.1).

*Fix:* Visually hidden text such as "Up 14.2% from last year, favourable", glyph `aria-hidden`; with Lower is better, add a visible word ("↗ 3.8% · worse"); flat values read "No change". Add these to the Comparison editor's Label template defaults.

**F14: Undo exists only in a toast that closes after about 6 s**  
*Category:* Accessibility · *Source:* accessibility T1 · *Location:* EXPERIENCE → Toast (T9), Remove (A6), Interaction Primitives

Remove has no confirmation, so the toast's Undo is the only recovery, with a 6 s limit and no way to turn it off, adjust or extend it. A screen-reader user often needs longer to hear it and reach it. Re-adding is not equivalent: position, size and period are lost (SC 2.2.1).

*Fix:* Ctrl/⌘+Z on the dashboard undoes the last add, remove or move for as long as the dashboard is open; action toasts stay at least 10 s and never auto-dismiss while focus is in the drawer or the toast; add "Keep notifications until I close them"; the announcement names the Undo route.

**F15: Live and interval auto-refresh cannot be paused**  
*Category:* Accessibility · *Source:* accessibility T2 · *Location:* EXPERIENCE → Refresh Interval Live ~30 s, Live freshness, Block Chrome Refresh

Content updates automatically, alongside other content, indefinitely, with no pause, stop or frequency control. Values changing under the reading cursor disorient screen-reader and cognitive users (SC 2.2.2).

*Fix:* A "Pause live updates" toggle (`aria-pressed`) in the dashboard header whenever anything auto-refreshes; paused Blocks show "Paused · data as of 10:42"; resuming refreshes immediately; the choice persists per user and dashboard; value changes never animate.

### Medium

**F16: Flow headings do not use the PRD UJ names verbatim**  
*Category:* Flow coverage · *Source:* rubric §1 · *Location:* EXPERIENCE → Key Flows, Flows 1–5

"Alex publishes 'Revenue overview' (UJ-1)" vs PRD "UJ-1. Alex (Admin) builds the 'Revenue overview' block"; "Jamie adds a block (UJ-3)" vs "Jamie (User) personalizes the Overview"; similar drift for UJ-5 and UJ-4. Traceability tools and story writers matching on UJ names will miss these.

*Fix:* Use the PRD UJ title verbatim as the heading; keep the current wording as a subtitle if wanted.

**F17: Unavailable copy contradicts PRD UJ-1 without a tag**  
*Category:* Flow coverage · *Source:* rubric §1 · *Location:* EXPERIENCE → Voice (Unavailable), Flow 4 step 2

The PRD edge case shows "Unavailable: total missing"; EXPERIENCE shows Users "Unavailable — this value can't be shown right now" and keeps the field name for Admins. A reasonable refinement, but untagged, so a developer reading the PRD builds the other string.

*Fix:* Tag it `[UX DECISION]` and add it to the PRD v3.2 alignment list, or adopt the PRD string.

**F18: Placeholders are 2.54:1 and search fields use them as their only label**  
*Category:* Token completeness · *Source:* rubric §2 (within the high non-text finding) · accessibility C6 (medium) · *Location:* DESIGN → text-subtle, input.placeholder; EXPERIENCE → drawer search, top-bar search

`text-subtle` placeholder text is 2.54:1 on white (SC 1.4.3 applies to placeholders). The drawer and top-bar search fields have no visible label, so the placeholder is their only name (SC 3.3.2, 4.1.2).

*Fix:* `input.placeholder: {colors.text-muted}` (4.83); search fields without a visible label get an accessible name (`aria-label="Search blocks"`); placeholders never carry instructions; keep `text-subtle` for decorative marks only.

**F19: Shadows and halos are raw rgba() values outside the token system**  
*Category:* Token completeness · *Source:* rubric §2 · *Location:* DESIGN → toast.shadow, dialog.shadow, drawer-row shadow, scrim, just-added-outline.halo

`just-added-outline.halo` hard-codes `rgba(250,204,21,.18)` (the default accent), contradicting the Do's row against hard-coded values; it will not follow a Workspace accent override, and the others block the dark theme.

*Fix:* Add `elevation` / `shadow-*` tokens (or `shadow-color`, `scrim-opacity`), and express the halo as `{colors.accent}` at an alpha.

**F20: The rule that accent is never used for text is contradicted**  
*Category:* Token completeness · *Source:* rubric §2 · *Location:* DESIGN → signin-hero.tagline, toast.actionColor; EXPERIENCE → Branding & Theming

The sign-in tagline is accent text on `hero-chip`, and toast actions are `accent-border` text on `surface-inverse`. Both pass today on dark surfaces, but the override guardrail checks only `on-accent` and `accent-ink`, so a Workspace accent override could make them fail.

*Fix:* Qualify the rule ("never text on light surfaces") and extend the guardrail to accent-as-text on dark surfaces, or move both to dedicated tokens such as `hero-accent-text` and `toast-action`.

**F21: Single-character shortcuts have no stated scope**  
*Category:* Component coverage · *Source:* rubric §3 (low) · accessibility K4 (medium) · *Location:* EXPERIENCE → Interaction Primitives, Keyboard model (`/`, `A`, `M`)

The scope of `/` is "the current panel" without saying whether it works only while focus is inside the drawer; if not, it is a page-global single-character shortcut. `A` and `M` are acceptable only while the row or Block has focus (SC 2.1.4).

*Fix:* Single-key shortcuts fire only when the owning component has focus and the target is not a text field; add "Keyboard shortcuts: On / Off" in Profile & settings; "?" opens a shortcut list, also listed in Help.

**F22: The Edit Layout keyboard model is ambiguous: Esc both ends a move and saves the mode**  
*Category:* Component coverage · *Source:* rubric §3 (low) · accessibility K5 (medium) · *Location:* EXPERIENCE → Edit Layout Mode, Keyboard model, Esc order

Esc saves and exits Edit Layout Mode, while everywhere else Esc dismisses (rubric). "Enter or Esc ends the move" conflicts with "Esc exits Edit Layout Mode (saving)"; there is no cancel for a move and no defined focus target (accessibility, SC 2.1.1, 3.2.2).

*Fix:* Apply the APG grab pattern: a focusable "Move {Block}" button per header; M or Space picks up, arrows move, Shift+arrows resize; Enter drops, Esc cancels and restores, a second Esc exits; relative announcements; Ctrl/⌘+Z undoes the last move. Confirm in the spine whether exiting saves.

**F23: Responsive behaviour is not committed for 1024–1279 px**  
*Category:* Component coverage · *Source:* rubric §3 · *Location:* EXPERIENCE → Responsive & Platform (Shell); DESIGN → Layout → Narrow layout

The Shell column reads "Sidebar 232px, or icon rail [ASSUMPTION]", which is two options, not a default. With the drawer pushed, content width is viewport minus 512 px, below the 720 px narrow threshold up to about 1231 px, so the dashboard silently goes single-column.

*Fix:* Pick one shell behaviour, and state what the dashboard does with the drawer open between 1024 and 1231 px (single column, or overlay below 1232 px).

**F24: Save-failure, offline and concurrent-edit states are missing outside the Dashboard**  
*Category:* State coverage · *Source:* rubric §4 · *Location:* EXPERIENCE → State Patterns (Wizard, Data source form, Template editor, Settings forms)

Offline is specified only for the Dashboard. Concurrent draft editing by another Admin is load-bearing for architecture: it decides between locking and last-write-wins. (Session expiry is covered in F9.)

*Fix:* Add rows: save failed with work kept ("We couldn't save your draft. Your changes are still here. Try again."); offline banner with Save disabled and the reason; draft changed by another Admin, with a locking or last-write-wins decision and copy.

**F25: Generic states for settings and admin screens are claimed but only partly written**  
*Category:* State coverage · *Source:* rubric §4 · *Location:* EXPERIENCE → State Patterns; IA note under the table

The IA note says these screens "are covered by State Patterns", but the only generic rows are "Admin lists · Empty" (with Draft-blocks-specific copy) and "Any admin page · Permission denied". No generic cold load, save success or failure, search with no results, or unsaved-changes-on-leave.

*Fix:* Add four generic rows with templated copy, for example "No {items} yet. {Primary action} to start." and "No {items} match '{query}'.".

**F26: Small surface states are missing**  
*Category:* State coverage · *Source:* rubric §4 · *Location:* EXPERIENCE → State Patterns

Missing: step ② with no Data Sources registered, the Add-blocks list load error, an empty Notifications panel, Expanded view loading / empty / error, the Dashboard layout load error, and slow ⌘K search results.

*Fix:* Add one State Patterns row each.

**F27: The .working/ links will break when the hybrids are promoted**  
*Category:* Visual reference coverage · *Source:* rubric §5 · *Location:* DESIGN → Brand & Style, Narrow layout, Stepper; EXPERIENCE → IA, Map Data, step ⑤, Add-blocks Panel, Inspiration

The decision log says `mapdata-hybrid.html` and `addblocks-hybrid.html` will be promoted to `mockups/`, but about 8 inline links point at `.working/`.

*Fix:* Make the link update part of the promotion step, or link to `mockups/` now with a note.

**F28: EXPERIENCE.md is too long, and its repetition invites drift**  
*Category:* Bloat & overspecification · *Source:* rubric §6 · *Location:* EXPERIENCE → Component Patterns, Voice, Branding & Theming, Security-conscious UX

"You don't have permission to publish this block." appears ×5, "no longer available" ×7, "Required by your admin" ×5, and the missing-required-Slot reason in three different variants. There are 137 FR citations, with FR-9, FR-24, FR-27 and FR-35 content restated. Branding & Theming repeats DESIGN → Colors; Security-conscious UX re-states pattern rules.

*Fix:* Make the Voice table the single source of copy with row IDs cited elsewhere; replace restated FR lists with "per FR-n" plus UX deltas; reduce Branding to a 2-line pointer and Security to a 6-row index; convert rule lists to `element | rule` tables. Target about 8,000–9,000 words with no loss of decisions.

**F29: Some vocabulary is outside the PRD Glossary or collides with it**  
*Category:* Inheritance discipline · *Source:* rubric §7 · *Location:* EXPERIENCE → Version history, Add-blocks Panel (T10 group), publish toast

Version states "Live, Previous, Draft, Unpublished, Archived" are not the Glossary Lifecycle States, and "Live" collides with the Refresh Interval "Live (~30 s)"; "system Blocks" is undefined in the PRD; "Block Library" in the publish toast is the v2 name of the Add-blocks Panel.

*Fix:* Use "Published (current)" / "Published (earlier)" and avoid "Live" outside refresh; define "system Block" or drop it; decide whether "Block Library" is a valid Admin-facing term and align the toast.

**F30: Copy for the same moment differs between sections**  
*Category:* Inheritance discipline · *Source:* rubric §7 · *Location:* EXPERIENCE → Voice, State Patterns, Map Data, Flow 1; DESIGN → Block states

The required-slot reason has three variants. The Unavailable face copy differs between Voice ("Unavailable — this value can't be shown right now"), State Patterns ("Unavailable" with ⚠) and DESIGN ("⚠ … the word 'Unavailable'").

*Fix:* Make one Voice row canonical, and reference it from State Patterns and DESIGN.

**F31: Selected states rely on fills of 1.07:1**  
*Category:* Accessibility · *Source:* accessibility C7 · *Location:* DESIGN → signin-role-card-selected, sidebar-nav-item-active, segmented-control, chip-category-active

The selected role card uses an accent border (1.53) and accent-soft fill (1.07); the sidebar active item uses that fill and a weight change; the active segment is white on sunken with a border-default edge, which is effectively nothing (SC 1.4.11, 1.4.1).

*Fix:* Role card: a visible radio indicator plus a 2px `accent-ink-strong` border. Sidebar: a 3px `accent-ink-strong` leading bar and `aria-current="page"`. Segmented: a `border-control` edge, weight 600, and radio or `aria-pressed` semantics. Chips: a leading ✓ on the active chip.

**F32: The sketches due to become mockups carry failing colours**  
*Category:* Accessibility · *Source:* accessibility C9 · *Location:* .memlog (promotion of mapdata-hybrid and addblocks-hybrid); mapdata-hybrid <style>

Active step label #CA8A04 (2.74 on accent-soft), the block icon, the `.up` delta #16A34A (3.30), "Data as of" and `--subtle` labels #9CA3AF (2.54), and High / Confirmed pills at 3.00. Implementers copy mockups (SC 1.4.3, 1.4.11).

*Fix:* Correct these colours to the spine tokens before promotion, or add a banner to each promoted file: "Colours superseded by DESIGN.md".

**F33: The drawer row uses invalid ARIA and a mixed interaction model**  
*Category:* Accessibility · *Source:* accessibility K2 · *Location:* EXPERIENCE → Row, Accessibility Floor; addblocks-hybrid

`aria-expanded` is not supported on `listitem`, and a focusable listitem that also contains a Tab-reachable action is ambiguous for screen readers (SC 4.1.2, 2.1.1).

*Fix:* Each row is a `listitem` containing a disclosure button (the Block name, with `aria-expanded` / `aria-controls`) and the action button. ↑ / ↓ move between disclosure buttons, Enter or Space toggles the preview, A adds, Tab reaches the action.

**F34: F6 is the only route between regions and is not reliable**  
*Category:* Accessibility · *Source:* accessibility K3 · *Location:* EXPERIENCE → Interaction Primitives, Foundation

Browsers reserve F6 for pane and address-bar cycling and do not reliably deliver it to pages; screen-reader users navigate by landmarks instead (SC 2.1.1, 2.4.1).

*Fix:* Regions are landmarks: dashboard `main` labelled with its name, drawer `complementary` "Add blocks", toast stack `region` "Notifications". F6 is an enhancement; add a visible "Back to dashboard" skip link in the drawer header and a "Go to notifications" skip link while a toast is visible.

**F35: The preview hotspots need a keyboard specification**  
*Category:* Accessibility · *Source:* accessibility K6 · *Location:* EXPERIENCE → Click-on-preview hotspots, Sticky preview

Hotspots have no defined role, name, focus target or return path, and the Block chrome inside the preview is still interactive (SC 2.1.1, 2.4.3, 4.1.2).

*Fix:* Each hotspot is a `button` named "{Slot}: {field}, {rendered value}. Change field"; the hatched placeholder is "Chart series, required, not mapped. Choose field"; preview chrome is `inert`; activation moves focus into the field-picker search and Esc returns it; the pane is a region "Live preview, sample data" with a "Skip to slot checklist" link.

**F36: Slot checklist rows have several controls and conflicting keys**  
*Category:* Accessibility · *Source:* accessibility K7 · *Location:* EXPERIENCE → Keyboard model (slot checklist), Confirmed, Enter

Each row has Confirm, Change field and Expand, and Enter is defined as both expand and Confirm (SC 2.1.1, 4.1.2).

*Fix:* Slot rows are a list; each row is a `group` labelled by slot name and status; its buttons are in normal Tab order; ↑ / ↓ between rows is an optional accelerator; Expand uses `aria-expanded`; Confirm is never bound to Enter on the row.

**F37: The field tree needs the APG tree pattern**  
*Category:* Accessibility · *Source:* accessibility K8 · *Location:* EXPERIENCE → field tree; DESIGN → Field picker / field tree

A "Use" button inside each tree item is an interactive descendant of `treeitem`, which is invalid (SC 4.1.2, 2.1.1).

*Fix:* `role=tree` with `treeitem`s carrying `aria-level`, `aria-expanded`, `aria-setsize`, `aria-posinset`; arrow, Home / End and type-ahead navigation; Enter on a leaf = Use (the visual pill is `aria-hidden`); names include type and example; incompatible leaves are `aria-disabled` with the reason described; search announces "n fields match"; the breadcrumb is a `nav` "Field path".

**F38: Live regions announce too much in some places and too little in others**  
*Category:* Accessibility · *Source:* accessibility S3 · *Location:* EXPERIENCE → Accessibility Floor (Live regions), State Patterns (Offline, Live freshness), Toast, optimistic rollback

A reconnect that makes every Block stale produces N announcements; routine refreshes and the Map Data status bar risk constant chatter; error and rollback toasts auto-dismiss (SC 4.1.3).

*Fix:* Announce Reconnecting once and recovery once; aggregate and debounce Stale / Unavailable per dashboard (about 2 s); never put live freshness, KPI values or chart data in live regions; debounce the drawer count (about 500 ms); the status bar announces only when required-missing or needs-review counts change; error toasts use `role="alert"` and do not auto-dismiss; the toast announcement names the Undo route.

**F39: "✓ Added" turns into "× Remove" on hover or focus, so the state is hidden**  
*Category:* Accessibility · *Source:* accessibility S4 · *Location:* EXPERIENCE → Row actions; DESIGN → drawer row actions

A keyboard or screen-reader user who tabs to the action hears only "Remove X from dashboard"; the Added state is never announced, and a mouse user brushing it sees it turn destructive (SC 4.1.2, 1.3.1).

*Fix:* Added rows show persistent "✓ Added" status text and a separate Remove button (destructive-soft, visible on hover, focus-within and touch, always in the accessibility tree), named "Remove {Block} from dashboard".

**F40: Role chips used as selects lack menu-button semantics**  
*Category:* Accessibility · *Source:* accessibility S5 · *Location:* EXPERIENCE → Roles table, Small components; DESIGN → Role chips

Role chips act as selects with no specified role, name or menu semantics. Under simulated deuteranopia Measure and Time chips are near-identical (ΔE 0.4 text), which passes 1.4.1 only because names are always shown (SC 4.1.2, 1.4.1).

*Fix:* A role chip is a menu button named "Role for {field}: {role}" with `aria-haspopup="menu"`; flagged chips add ", possible wrong role"; the menu uses `menuitemradio`; after a change the T1 note is announced and focus returns to the chip. Never show role chips as bare colour swatches.

**F41: Names, roles and values are unspecified for other custom widgets**  
*Category:* Accessibility · *Source:* accessibility S6 · *Location:* EXPERIENCE → Small components; DESIGN → Badges, Stepper, Slot row, Block Type faces

Slot status dots, confidence badge, status-bar meter, sample-data badge, progress list, calendar strip, segmented controls, health rows, masked secrets and Technical details have no specified semantics (the Table Block row is folded into F1).

*Fix:* Add the reviewer's semantics table to the Accessibility Floor, for example: decorative dots and meters `aria-hidden` with text as the value; calendar today `aria-current="date"`; segmented controls as `radiogroup` or `aria-pressed`; masked secret read as "Secret set on 2 Oct 2026"; Technical details as a disclosure with a "Copy request ID" button.

**F42: Disabled buttons hide their reason from keyboard and screen-reader users**  
*Category:* Accessibility · *Source:* accessibility F1 (forms) · *Location:* EXPERIENCE → Shell (Publish), Continue to Preview, POST read-only, Live disabled, Restore / Unpublish; DESIGN → Disabled at 45% opacity

A native `disabled` button leaves the Tab order, so keyboard and screen-reader users never reach it or hear why. If the reason text inherits the 45% opacity it falls to about 1.8:1 (SC 4.1.2, 1.3.1, 3.3.1).

*Fix:* Blocked primary actions use `aria-disabled="true"`, stay focusable and describe the inline reason; activating one moves focus to the first blocker and announces it; reason text is never dimmed; disabled select options carry their reason.

**F43: Error association, error summary and JSON paste errors are unspecified**  
*Category:* Accessibility · *Source:* accessibility F2 (forms) · *Location:* DESIGN → Inputs, Error; EXPERIENCE → Paste sample JSON, Transforms errors, Parameters table

Errors are inline but not programmatically associated, multi-error steps have no summary, and JSON paste errors are not announced or navigable (SC 3.3.1, 4.1.3).

*Fix:* Invalid fields get `aria-invalid` and `aria-describedby`, with the message prefixed by an icon and hidden "Error:"; steps with more than one error show a linked error summary that receives focus on submit; JSON validation is announced politely; the textarea has a line-number gutter and a "Go to line 4" action.

**F44: Autocomplete purposes are missing**  
*Category:* Accessibility · *Source:* accessibility F3 (forms) · *Location:* EXPERIENCE → Sign in, Password recovery, Profile & settings

Identity fields do not declare their input purpose (SC 1.3.5).

*Fix:* Email `autocomplete="username"`, password `current-password`, new and confirm `new-password`; profile `name` and `email`; pasting into password fields is allowed.

**F45: Destructive confirmation dialogs lack focus and role rules**  
*Category:* Accessibility · *Source:* accessibility F5 (forms) · *Location:* DESIGN → Dialog; EXPERIENCE → Unpublish and archive, Reset to template, Delete, access change (B3), Restore with a draft (B2)

Initial focus, role and return focus are unspecified for destructive confirmations, and Reset to template has no Undo (SC 2.4.3, 4.1.2, 3.3.4 in spirit).

*Fix:* Use `role="alertdialog"` labelled by the title and described by the impact text; initial focus on Cancel; Esc cancels; focus returns to the invoker or its row; destructive buttons repeat the object ("Reset Finance weekly – Jamie").

**F46: Fixed pixel heights clip text at 200% text zoom or with text-spacing overrides**  
*Category:* Accessibility · *Source:* accessibility R2 (reflow) · *Location:* DESIGN → button-* 34px, input 36px, block-height-small / medium / large

Fixed control and Block heights clip enlarged or re-spaced text (SC 1.4.4, 1.4.12).

*Fix:* Control heights are `min-height` with padding that grows; Block presets set the grid height and overflowing content scrolls inside a focusable Block body labelled with its title; KPI cards grow in narrow layouts; no line-height or letter-spacing fixed in px.

**F47: Stacked sticky layers hide content at high zoom**  
*Category:* Accessibility · *Source:* accessibility R3 (reflow) · *Location:* EXPERIENCE → Map Data sticky status bar and preview, drawer sticky header, tablet Preview bar, 64 px top bar

At 1280×1024 and 400% the viewport is 320×256 CSS px; the top bar, status bar and bottom preview bar leave almost nothing (SC 1.4.10; 2.4.11 advisory).

*Fix:* Below 480 CSS px viewport height only the top bar stays sticky; focused elements scroll clear of sticky layers via `scroll-padding`.

### Low

**F48: Flows 3, 4 and 5 have no protagonist line**  
*Category:* Flow coverage · *Source:* rubric §1 · *Location:* EXPERIENCE → Flow 3/4/5 headers

Flows 0 to 2 state name, role, context or viewport; 3 to 5 do not.

*Fix:* Add one line each, for example "Alex Morgan, Admin with publish permission" and "Jamie Davis, User, desktop".

**F49: Flow 2 contradicts PRD UJ-3 on small details**  
*Category:* Flow coverage · *Source:* rubric §1 · *Location:* EXPERIENCE → Flow 2, steps 2 and 6

"9 available blocks" vs the PRD's 8; step 6 hovers "Recent Activities" but the toast says "Recent activity removed".

*Fix:* Use 8, and use the configured name "Recent activity" throughout.

**F50: `{user.email}` uses token-reference syntax for a URL-template field**  
*Category:* Token completeness · *Source:* rubric §2 · *Location:* EXPERIENCE → Map Data → Footer action target

A resolver reports it as an unresolved design token.

*Fix:* Mark it as a URL-template example in code, or use another delimiter in prose.

**F51: Several values sit off the token scales or outside tokens**  
*Category:* Token completeness · *Source:* rubric §2 · *Location:* DESIGN → role-chip.radius, checkbox.radius, popovers, stepper

Off-scale radii (7px, 4px); popover widths (560, 360, 320px) and stepper geometry only in prose; `role-chip` has no binding to the per-role text/fill/border triples.

*Fix:* Add `rounded.xs` and popover-width spacing tokens, and `role-chip-{role}` entries that reference the triples.

**F52: DESIGN → Components cites mockups by number without links**  
*Category:* Visual reference coverage · *Source:* rubric §5 · *Location:* DESIGN → Block Type faces, Top bar, Chart marks, Admin overview, Wizard page parts, Remember me

"mockup 02", "mockups 02 and 04", "mockup 03" and others are plain text.

*Fix:* Turn each into a link to `imports/0N-….png`.

**F53: EXPERIENCE re-quotes token values in pixels**  
*Category:* Bloat & overspecification · *Source:* rubric §6 · *Location:* EXPERIENCE → drawer, icon rail, thumbnails, block heights

"drawer (392px)", "icon rail (64px)", "Medium 360px" and others. Harmless while values agree, but every token change becomes a multi-place edit.

*Fix:* In EXPERIENCE reference the token (`{spacing.drawer-width}`) instead of the number; DESIGN parentheticals can stay.

**F54: Size notation is inconsistent**  
*Category:* Inheritance discipline · *Source:* rubric §7 · *Location:* EXPERIENCE → Add-blocks Panel inline preview, Flow 2, wizard

"Default size 2×2", "6×Medium" and "8 columns × 360px"; "2×2" fits neither the 12-column grid nor the named heights.

*Fix:* Use "{columns} columns × {Small|Medium|Large}" everywhere.

**F55: EXPERIENCE section order and frontmatter differ from the reference shape**  
*Category:* Shape fit · *Source:* rubric §8 · *Location:* EXPERIENCE → frontmatter, section order

Data Mapping Model, Branding & Theming and Security-conscious UX sit between Accessibility Floor and Key Flows; Responsive and Inspiration come after Key Flows; frontmatter uses `title` instead of `name`.

*Fix:* Move Data Mapping Model into Map Data; reduce Branding and Security (F28); put Responsive and Inspiration before Key Flows; rename `title` to `name`.

**F56: The resize corner is a 10px L-shape at 1.53:1**  
*Category:* Accessibility · *Source:* accessibility C8 · *Location:* DESIGN → Block card, Edit Layout Mode

Fails 1.4.11 and the spine's own 24px target floor.

*Fix:* A 24×24 hit area with a 10px corner glyph in `accent-ink-strong` (6.85).

**F57: Two text pairs pass with no margin**  
*Category:* Accessibility · *Source:* accessibility C10 · *Location:* DESIGN → Colors

`text-muted` on accent-soft is 4.50 (4.501) and on canvas 4.55; anti-aliasing or a token nudge will make them fail.

*Fix:* Add a rule: no text-muted on accent-soft; use text-secondary or accent-ink-strong.

**F58: The progress-bar fill is 1.39:1 against its track**  
*Category:* Accessibility · *Source:* accessibility C11 · *Location:* DESIGN → progress-row

Passes 1.4.11 only if the bar is supplementary.

*Fix:* State that the % text is the value and the bar is `aria-hidden` and supplementary, for all bars; apply the chart-1 stroke rule (F4) if a bar must stand alone.

**F59: Stepper semantics conflict with the forward-jump rule**  
*Category:* Accessibility · *Source:* accessibility K9 · *Location:* EXPERIENCE → Shell, Small components

"Future steps are inert" conflicts with "forward jumps allowed up to the first incomplete step".

*Fix:* The stepper is a `nav` "Create block progress" with an `ol`; done steps and the first incomplete step are links named "{n}. {label}, completed", current has `aria-current="step"`, later steps are plain text "(not started)".

**F60: Block-level structure is unspecified**  
*Category:* Accessibility · *Source:* accessibility S7 · *Location:* EXPERIENCE → Block Chrome

Blocks have no landmark or heading structure, and chrome controls are not named per Block.

*Fix:* Each Block is a `section` labelled by its title heading (h2 under the page h1); chrome controls include the Block name ("Refresh Revenue overview").

**F61: Gaps in the reduced-motion list**  
*Category:* Accessibility · *Source:* accessibility T4 · *Location:* EXPERIENCE → Reduced motion

Drawer push, sidebar collapse, accordions, the Just-added halo fade and skeleton shimmer are not covered (SC 2.3.3 advisory, 2.2.2 for long animations).

*Fix:* Add them; shimmer is static under reduced motion and in every mode stops after 5 s with "Still loading…".

**F62: The required marker is not exposed correctly**  
*Category:* Accessibility · *Source:* accessibility F4 (forms) · *Location:* DESIGN → Required fields (red `*`)

The asterisk is visual only.

*Fix:* The `*` is `aria-hidden` and inputs have `required` or `aria-required`; each form shows "* Required" once at the top.

**F63: The show/hide password control is unspecified**  
*Category:* Accessibility · *Source:* accessibility F6 (forms) · *Location:* EXPERIENCE → Sign in

No name or state is given for the toggle.

*Fix:* A button named "Show password" with `aria-pressed`; toggling keeps the caret and value.

**F64: Target sizes are not specified per component**  
*Category:* Accessibility · *Source:* accessibility R4 (reflow) · *Location:* DESIGN → chip-category, drawer + Add / × Remove, toast close

In the sketch, chips are about 20 px tall, `.btn-add` about 24 px, and the toast × is a `span`. (2.5.5 is AAA and 2.5.8 is WCAG 2.2, so this enforces the spine's own floor.)

*Fix:* Give components explicit `min-height`: chips, row actions and toast actions 28 px, 44 px on touch; the toast close is a `button` "Dismiss notification".

**F65: Mobile chip scroller and sheet details**  
*Category:* Accessibility · *Source:* accessibility R5 (reflow) · *Location:* EXPERIENCE → Responsive (mobile)

Focused chips can be hidden by the edge fade, and the sheet relies on a dim strip to close.

*Fix:* Chips scroll into view on focus and the fade never covers them; the sheet has a visible 44 px Close button; the toast never covers the focused element.

**F66: Masked values for assistive technology**  
*Category:* Accessibility · *Source:* accessibility D1 · *Location:* EXPERIENCE → Data source form, fetch as user

See F41 (masked secret). "Fetch as user" shows attribute names, which is good.

*Fix:* Make sure screen-reader text never includes resolved user-context values.

**F67: Freshness times are ambiguous across days and timezones**  
*Category:* Accessibility · *Source:* accessibility D2 · *Location:* EXPERIENCE → Stale, Last success, Data as of

A bare time is ambiguous after midnight or across zones for audit-minded users.

*Fix:* Include the date when not today ("Stale: last data yesterday 22:10"); About this block shows the full timestamp with timezone.

**F68: Domain jargon in Admin mapping has no in-context definitions**  
*Category:* Accessibility · *Source:* accessibility D3 · *Location:* EXPERIENCE → Roles table, Transforms builder

"Record path", "Measure", "Dimension", "Filter/Category" and `PCT_CHANGE` are shown without definitions outside the role legend.

*Fix:* Each role and transform function has a one-line description on focus as well as hover. General 1.4.13 rule: every tooltip appears on focus and hover, is dismissible with Esc, and stays while hovered.

## Reviewer files

- Rubric walk: `/home/bs01729/Projects/rmg-dashboard-platform/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/review-rubric.md` (critical 0 · high 7 · medium 12 · low 9)
- Accessibility review: `/home/bs01729/Projects/rmg-dashboard-platform/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/review-accessibility.md` (critical 1 · high 10 · medium 21 · low 13)
- HTML report: `/home/bs01729/Projects/rmg-dashboard-platform/_bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/validation-report.html`
