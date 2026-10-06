---
title: Accessibility review — Dashflow UX spines
reviewed: 2026-10-05
standard: WCAG 2.1 AA + WAI-ARIA Authoring Practices (APG)
scope: DESIGN.md, EXPERIENCE.md, .memlog.md, .working/mapdata-hybrid.html, .working/addblocks-hybrid.html, imports/01–05
theme: light only (dark theme deferred; not reviewed)
---

# Accessibility review — Dashflow (light theme, MVP)

## Overall verdict

**Not AA-conformant as specified yet, but close and well-founded. Fix the 1 critical and 10 high findings before build. No redesign is needed.**

The spines already make several sound commitments:
- the amber accent-ink for text;
- a blue focus ring that passes 3:1 on every surface (lowest 3.38:1);
- arrows on deltas, and words next to status dots;
- keyboard alternatives for every drag;
- a defined Esc order;
- a non-modal drawer on wide screens and a modal sheet on mobile;
- inline reasons for disabled primary actions;
- honest "Unavailable" and "Stale" states;
- clear sample-data labelling.

The gaps cluster in six areas:
1. **Data visualisation.** Charts have no text alternative. Series 1 (yellow) is 1.53:1 on white. The "colour-blind-safe" palette is not safe for slate versus violet.
2. **Timing.** The ~6 s Undo toast, Live auto-refresh with no pause, and an unspecified session timeout.
3. **Focus stability.** Re-renders, auto-collapse, re-sorting and Remove can each drop focus to `<body>`.
4. **Low-contrast boundaries and states.** Input and checkbox borders are 1.47:1. Selection and active-option highlights are 1.07:1.
5. **Delta semantics.** Whether a change is good or bad is conveyed by colour alone.
6. **Reflow and zoom.** The wizard at 320 CSS px, and fixed pixel heights.

| Severity | Count |
|---|---|
| Critical | 1 |
| High | 10 |
| Medium | 21 |
| Low | 13 |

Locations use `FILE → section (line)`, with line numbers as of 2026-10-05.

---

## 1 · Colour and contrast

### Handled well
- `accent-ink` #A16207 passes on every surface it is specified for: 4.92 on white, 4.58 on accent-soft, 4.63 on canvas, 4.75 on accent-wash and 4.71 on sunken. The values stated in DESIGN.md were verified.
- All six role-chip text and fill pairs pass, from 4.52 (Filter) to 9.37 (Label).
- `on-accent` on accent is 11.58:1.
- The focus ring passes at least 3:1 on every surface, including the toast (3.43) and next to a yellow button (3.38).
- `success-text` (5.02), `error` (4.83) and `warning` (5.18) pass on white. The mockup greens and reds (#00D987, #FF004A) are correctly replaced.
- Deltas carry arrows. Status dots always carry words. Unread notifications use a dot **and** bold text.

### C1 · High: series 1 (yellow) and series 5 and 6 fail non-text contrast. SC 1.4.11
**Location:** DESIGN.md → `colors.chart-1/5/6` (L83–89), Colors → Chart palette (L586), Chart marks (L725–730), `progress-row.fillDefault` (L488).

The yellow `#FACC15` line is **1.53:1** on white, and it is the *only* series in most charts (mockups 02, 04 and 05). Chart-5 orange is 2.80 and chart-6 grey is 2.54. The area fill (`#FEF9C3`) is 1.07. The DESIGN.md mitigation is a legend and a tooltip, but these do not make the mark itself perceivable.

**Fix (token change):**
- Add `chart-1-stroke: '{colors.accent-ink}'` (#A16207, 4.92:1). Lines, points and bar outlines for series 1 use chart-1-stroke. The yellow `chart-1` stays as area, bar and progress *fill* only.
  - This keeps the brand look and still follows a Workspace accent override, because accent-ink is already guarded to be at least 4.5:1.
- Change `chart-5` to `#EA580C` (3.56) and `chart-6` to `#6B7280` (4.83).
- Bars and progress fills in yellow get a 1px chart-1-stroke edge. Otherwise state that the % text is the information and the bar is decorative (see C11).

### C2 · High: the chart palette is not colour-vision-deficiency safe. SC 1.4.1 and 1.4.11
**Location:** DESIGN.md → Chart palette (L586), `.memlog` V5.

Computed CIEDE2000 distances after simulation (Machado 2009, full severity). Below about 20 is risky, and below about 10 is effectively the same colour.

| Pair | Normal | Protan | Deutan | Tritan |
|---|---|---|---|---|
| chart-2 slate / chart-4 violet | **16.8** | **8.9** | **9.1** | 20.0 |
| chart-3 teal / chart-6 grey | — | 11.3 | 11.8 | — |
| chart-1 yellow / chart-5 orange | — | 19.9 | 12.8 | — |
| chart-2 slate / chart-3 teal | — | — | 18.5 | 11.8 |

Slate versus violet is weak even for typical vision. The legend swatch is the only link from a line to its name, so in multi-series charts colour is the sole identifier.

**Fix:**
- Replace the palette with the following [PROPOSED; first four series pairwise ≥ 12.6 under protan and deutan, all ≥ 3:1 on white]:

| Token | New value | Contrast on white |
|---|---|---|
| chart-1-stroke | #A16207 | 4.92 |
| chart-2 | #4C6EB1 | 5.03 |
| chart-3 | #0F766E | 5.47 |
| chart-4 | #DB2777 | 4.60 |
| chart-5 | #EA580C | 3.56 |
| chart-6 | #6B7280 | 4.83 |

- **Make secondary encoding mandatory, not optional.** Change "series 2 to 6 *can* add dash or pattern variants" to the following rules:
  - "Every chart with 2 or more series uses direct end-of-line labels **or** per-series dash and marker shapes: solid/●, dash/■, dot/▲, dash-dot/◆, long-dash/✕, dotted/○."
  - "Bar, pie and stacked series use patterns from series 3 on."
  - "Legend items show the same dash or marker as the line."

### C3 · High: form control boundaries are below 3:1. SC 1.4.11
**Location:** DESIGN.md → `border-strong` (L29), `input.border` (L226), `checkbox.border` (L425), `select`, `segmented-control`.

`border-strong` #D1D5DB is **1.47:1** on white and 1.39 on canvas.
- An **unchecked checkbox** is identifiable only by its border.
- Text inputs and selects rely on the same border to show their hit area.
- The checked checkbox edge (`accent-strong`) is 1.92. Its check mark (11.58) carries the state, which is acceptable, but the box boundary still fails.

**Fix:**
- Add a token `border-control: '#80868F'` (3.67 on white, 3.45 on canvas, 3.51 on sunken).
- Use it for `input`, `select`, textarea, `checkbox` (unchecked and checked edge), radio, the endpoint `method-prefix` join, and the outer edge of `segmented-control`.
- Keep `border-strong` for decorative dashed outlines and secondary-button borders, where the text identifies the control.

### C4 · High: active option and hotspot focus are not visible. SC 2.4.7 and 1.4.11
**Location:** DESIGN.md → Popovers (L824–827: the "active result has an accent-soft fill"), `preview-hotspot.hover` (L386–388); EXPERIENCE.md → Click-on-preview hotspots (L281: "Hover **or focus** outlines it in accent").

In comboboxes and listboxes driven by `aria-activedescendant`, the highlighted option *is* the keyboard focus indicator. That applies to the command palette, field picker, field tree, role menu, dashboard switcher and workspace list.
- accent-soft against white is **1.07:1**, so the focus is invisible.
- The field tree's "picked" row uses a 3px accent inset (1.53).
- The hotspot focus outline in accent on accent-wash is 1.48.

**Fix (behaviour rule):**
- "The active or highlighted option in every listbox, menu, tree and palette shows a 2px `focus-ring` inset outline (or a 3px focus-ring left bar) **plus** the accent-soft fill."
- "Hotspots use the standard `focus-ring` on keyboard focus; the accent outline is for hover only."

### C5 · Medium: four small-text pairs fail 4.5:1. SC 1.4.3
**Location:** DESIGN.md → `stepper-step` (L282–285), slot-row state table "Optional" pill (L749), `button-destructive-soft` (L214–218), Required-missing / Type-mismatch rows (L325–331, L750–751).

| Pair | Ratio | Where |
|---|---|---|
| text-muted on surface-muted | **4.39** | "Optional" pill; upcoming stepper numeral (11 px) |
| text-muted on error-soft | **4.42** | type hint and caption text inside Required-missing / Type-mismatch rows |
| error #DC2626 on error-soft | **4.41** | "× Remove" (destructive-soft); "Required missing" pill |
| (sketch) #16A34A on #DCFCE7 | 3.00 | High-confidence pill and "Confirmed" pill in mapdata-hybrid |

**Fix:**
- `stepper-step.numeralForeground` and the Optional pill use `text-secondary` (9.37).
- In error-soft rows, captions use `text-secondary` (or `error-text` for the reason).
- `button-destructive-soft.foreground` and the Required-missing pill use `error-text` #B91C1C (5.91).
- High and Confirmed pills use `success-text` (4.57). The spine already specifies this, so the fix belongs in the sketch (see C9).

### C6 · Medium: placeholders are 2.54:1 and search fields use them as their only label. SC 1.4.3, 3.3.2, 4.1.2
**Location:** DESIGN.md → `text-subtle` (L33), `input.placeholder` (L231); EXPERIENCE.md → drawer search "Search blocks…" (L365), top-bar search.

**Fix:**
- `input.placeholder: '{colors.text-muted}'` (4.83).
- "Search fields without a visible label have an accessible name (`aria-label="Search blocks"`). Placeholders never carry instructions."
- Keep `text-subtle` for decorative marks only.

### C7 · Medium: selected states rely on fills of 1.07:1. SC 1.4.11 and 1.4.1
**Location:** DESIGN.md → `signin-role-card-selected` (L411–414), `sidebar-nav-item-active` (L394–397), `segmented-control` (L240–244), `chip-category-active` (L250–254), sticky status-bar meter.

| Control | How the selected state is shown | Why it fails |
|---|---|---|
| Sign-in role card | accent border (1.53) and accent-soft fill (1.07) | Too faint |
| Sidebar active item | accent-soft fill and weight 600 versus 500 | Too faint |
| Segmented control active | white on sunken, with a border-default edge | Effectively nothing |
| Category chip active | weight 700 | Passes, but only just |

**Fix:**
- **Role card:** add a visible radio indicator, a 16px circle in `border-control` with an accent-ink-strong dot when selected, plus a 2px `accent-ink-strong` border.
- **Sidebar active:** add a 3px `accent-ink-strong` leading bar and `aria-current="page"`.
- **Segmented active:** a 1px `border-control` edge, weight 600, and radio or `aria-pressed` semantics.
- **Chips:** keep weight 700 and add a leading ✓ on the active chip.

### C8 · Low: the resize corner is a 10px L-shape at 1.53:1. SC 1.4.11 (and the spine's own 24px target floor)
**Location:** DESIGN.md → Block card, Edit Layout Mode (L709).

**Fix:** "The resize handle is a 24×24 hit area with a 10px corner glyph in `accent-ink-strong` (6.85)."

### C9 · Medium: the sketches due to become mockups carry failing colours. SC 1.4.3 and 1.4.11
**Location:** `.memlog` L64 ("promote mapdata-hybrid.html and addblocks-hybrid.html to mockups/"); mapdata-hybrid `<style>`.

Failing values in the sketches:
- active step label `#CA8A04` (2.74 on accent-soft);
- block icon `#CA8A04`;
- `.up` delta `#16A34A` (3.30);
- "Data as of" and `--subtle` labels `#9CA3AF` (2.54);
- High and Confirmed pills at 3.00.

Implementers copy mockups.

**Fix:** correct these colours to the spine tokens before promotion. Otherwise add a banner to each promoted file: "Colours superseded by DESIGN.md; see review-accessibility.md C5/C9."

### C10 · Low: two pairs pass with no margin
`text-muted` on accent-soft is **4.50** (4.501), and on canvas it is 4.55. Anti-aliasing or a future token nudge will make them fail.

**Fix:** a rule in DESIGN.md → Colors: "No text-muted on accent-soft; use text-secondary or accent-ink-strong."

### C11 · Low: the progress-bar fill is 1.39:1 against its track. SC 1.4.11 (passes if the bar is supplementary)
**Location:** DESIGN.md → `progress-row` (L483–488).

**Fix:** "The % text is the value. The bar is `aria-hidden` and supplementary. Threshold-band icons or labels sit next to the %." This is already stated for bands; extend it to all bars. Apply the C1 stroke rule if the bar must stand alone.

---

## 2 · Keyboard and focus

### Handled well
- Keyboard alternatives for every drag: M + arrows, Shift + arrows, and the menu items Move up, Move down, Make wider and Make narrower (FR-59).
- Field mapping has no drag at all (A3).
- On drawer open, focus moves to search. On close, focus returns to "+ Add block", or to the Block after Show me.
- The Esc order is explicit (L636).
- The mobile sheet is a real modal with focus trapped and the background inert.
- Category chips are one tab stop with arrow keys.
- The focus ring is required on every interactive element.

### K1 · High: focus is lost when content re-renders. SC 2.4.3 (and 2.1.1 in practice)
**Location:** EXPERIENCE.md → Dashboard Date Range "refreshes the affected Blocks, each with its own skeleton" (L518), Live refresh (~30 s, L166), Remove from the ⋯ menu (L406), roles table **auto-collapse** (T4, L231), "Needs review" filter (L235), "Low-confidence slots are pinned to the top" (L236), Optimistic-UI rollback (L639).

Each of these can delete or move the focused node:
- a focused list row inside a Block that is replaced by a skeleton;
- the "Set as Measure" button that disappears when the roles table auto-collapses (this is Flow 1, step 5 → 7);
- a row confirmed under the "Needs review" filter, which leaves the list;
- a Block removed from its own ⋯ menu.

**Fix:** add a **Focus stability** subsection to Interaction Primitives:
1. Refreshes (Live, interval, manual, Date Range) update values **in place**, with stable element keys. Skeletons appear only on cold load or when a Block has no prior data. A focused element is never replaced.
2. If the focused element must disappear, focus moves to the nearest logical survivor:
   - a removed Block → the next Block's title, otherwise the previous one, otherwise "+ Add block";
   - a filtered-out slot row → the next row in the list;
   - a collapsed roles table → its "Edit roles" button.

   The change is announced politely, for example "Roles accepted. Roles table collapsed."
3. The roles table **never auto-collapses while focus is inside it**. It collapses on the next blur, or when the Admin presses "Looks right".
4. Pinning and sorting are computed when the step loads and on "Re-run auto-map", **never while the user is editing**.

### K2 · Medium: the drawer row uses invalid ARIA and a mixed interaction model. SC 4.1.2 and 2.1.1
**Location:** EXPERIENCE.md → Row (L369–376), Accessibility Floor (L659); addblocks-hybrid uses `role="listitem" tabindex="0"` with `aria-expanded`.

`aria-expanded` is not supported on `listitem`. A focusable listitem that also contains a Tab-reachable action is ambiguous for screen readers.

**Fix:**
- "Each row is a `listitem` containing:
  - (a) a **disclosure button**: the Block name, with `aria-expanded` and `aria-controls` pointing at the preview region;
  - (b) the action button."
- "↑ and ↓ move between the disclosure buttons (roving tabindex is optional). Enter or Space toggles the preview. A adds while a disclosure button has focus. Tab reaches the action."

### K3 · Medium: F6 is the only route between regions and is not reliable. SC 2.1.1 and 2.4.1
**Location:** EXPERIENCE.md → Interaction Primitives (L635), Foundation (L30).

Browsers reserve F6 for pane and address-bar cycling and do not reliably deliver it to pages. Screen-reader users navigate by landmarks instead.

**Fix:** "Regions are landmarks:
- the dashboard is `main`, labelled with the dashboard name;
- the drawer is `complementary`, labelled 'Add blocks';
- the toast stack is `region`, labelled 'Notifications'.

F6 and Shift+F6 are an enhancement. The drawer header also has a visible 'Back to dashboard' skip link, and the toast region is reachable from a 'Go to notifications' skip link while a toast is visible."

### K4 · Medium: single-character shortcuts. SC 2.1.4
**Location:** EXPERIENCE.md → Keyboard model (L629–636): `/`, `A`, `M`.

`A` and `M` are acceptable only while the row or Block has focus. `/` is described for "the current panel" and must not be page-global.

**Fix:**
- "Single-key shortcuts fire only when the owning component (drawer, row or Block) has focus and the target is not a text field."
- "Profile & settings has 'Keyboard shortcuts: On / Off'."
- "'?' opens a shortcut list, and the shortcuts are listed in Help."

### K5 · Medium: the Edit Layout keyboard model is ambiguous. SC 2.1.1 and 3.2.2
**Location:** EXPERIENCE.md → Edit Layout Mode (L418–429), Keyboard model (L634), Esc order (L636).

"Enter **or Esc** ends the move" conflicts with "Esc exits Edit Layout Mode (saving)". There is no cancel for a move, and no defined focus target for the move.

**Fix (APG grab pattern):**
- "In Edit Layout Mode each Block header has a focusable **Move {Block}** button (`aria-describedby` → 'Press M or Space to pick up').
- M or Space picks the Block up. Arrows move it, and Shift + arrows resizes it.
- **Enter drops** the Block. **Esc cancels** the move and restores the original position. A second Esc exits the mode.
- Announcements are relative, for example 'Revenue vs Expense, row 3, column 7, right of Revenue overview; Project status moved down'.
- Ctrl/⌘+Z undoes the last move."

### K6 · Medium: the preview hotspots need a keyboard specification. SC 2.1.1, 2.4.3, 4.1.2
**Location:** EXPERIENCE.md → Click-on-preview hotspots (L279–284), Sticky preview (L286–289).

**Fix:**
- "Each hotspot is a `button` named '{Slot}: {field}, {rendered value}. Change field', for example 'Headline value: total, $284,680. Change field'.
- The hatched missing placeholder is a button named 'Chart series, required, not mapped. Choose field'.
- The Block's own chrome controls in the preview (↻, –, ⋯) are inert (`inert`, or `tabindex=-1` with `aria-hidden`).
- Activating a hotspot moves focus into the field-picker search box. Esc or closing the picker returns focus to the hotspot.
- The preview pane is a region labelled 'Live preview, sample data' with a skip link 'Skip to slot checklist'."

### K7 · Medium: slot checklist rows have several controls and conflicting keys. SC 2.1.1 and 4.1.2
**Location:** EXPERIENCE.md → Keyboard model "↑ ↓ … slot checklist (roving tabindex)" (L632), Confirmed "pressed Enter on a focused row's Confirm" (L241), Enter "expand a slot row" (L633).

Each row has Confirm, Change field and Expand. Enter is defined as both expand and Confirm.

**Fix:**
- "Slot rows are a list. Each row is a `group` labelled by its slot name and status, for example 'Headline value, required, auto-mapped, high confidence'.
- Its buttons (Confirm, Change field, Expand presentation) are in normal Tab order.
- ↑ and ↓ between rows is an optional accelerator.
- Expand uses `aria-expanded`. Confirm is its own button and is never bound to Enter on the row."

### K8 · Medium: the field tree needs the APG tree pattern. SC 4.1.2 and 2.1.1
**Location:** EXPERIENCE.md → field tree (L296–300); DESIGN.md → Field picker / field tree (L827).

A "Use" button inside each tree item is an interactive descendant of `treeitem`, which is invalid.

**Fix:**
- "`role=tree`, with `treeitem` elements carrying `aria-level`, `aria-expanded`, `aria-setsize` and `aria-posinset`.
- → and ← expand and collapse; ↑ and ↓ move; Home and End jump; type-ahead works.
- **Enter on a leaf = Use.** The visual 'Use' pill is `aria-hidden`.
- The accessible name includes the type and example ('amount, number, 284680').
- Incompatible leaves are `aria-disabled` and have their reason in `aria-describedby`.
- The search announces 'n fields match'.
- The breadcrumb is a `nav` labelled 'Field path'."

### K9 · Low: stepper semantics and the forward-jump rule
**Location:** EXPERIENCE.md → Shell (L145), Small components (L576).

The spec says "Future steps are inert" but also "forward jumps allowed up to the first incomplete step".

**Fix:** "The stepper is a `nav` 'Create block progress' containing an `ol`.
- Done steps and the first incomplete step are links or buttons named '{n}. {label}, completed', with the current step carrying `aria-current="step"`.
- Later steps are plain text with '(not started)'."

---

## 3 · Screen readers

### Handled well
- Drawer rows expose expanded state, and the preview region is labelled "Preview of {Block} with sample data".
- Thumbnails are `aria-hidden`.
- Buttons have full names ("Remove Calendar from dashboard"). The sketch does this correctly.
- Locked rows announce "required by your admin and cannot be removed".
- Move announcements are specified.
- Stale and Unavailable are announced once per Block, not on every refresh.

### S1 · Critical: charts have no text alternative. SC 1.1.1 and 1.3.1
**Location:** EXPERIENCE.md → Foundation, Charts (L32); Accessibility Floor (L656, "chart series carry legends or labels" only); DESIGN.md → Chart marks (L725–730).

A chart Block (Revenue vs Expense, the KPI & Chart line) conveys its data only visually. The expanded table exists only when an Admin configures Expanded view (L270). For a dashboard product sold to regulated buyers, a whole Block type is unusable for blind users.

**Fix (behaviour rule):**
- "Every chart renders as `role="img"` with a generated `aria-label` summary. For example: 'Line chart, Revenue, Jan to Dec 2026. Lowest $10.2k in Jan, highest $40.1k in Dec, trend up.'
- Every chart Block's ⋯ menu always has **'View as table'**. This opens the Block's data as an accessible table, with `th` scope and units, regardless of the Expanded view setting.
- Tooltips are also reachable by keyboard. When the chart has focus, ← and → step through points, and the point value is announced politely.
- The legend is a list that names each series with its marker shape (C2)."

### S2 · High: whether a delta is good or bad is conveyed by colour alone, and the arrow is not announced meaningfully. SC 1.4.1, 1.1.1, 1.3.1
**Location:** DESIGN.md → KPI card (L711–716: "The colour adds whether the change is good"), Admin metric cards (L786); EXPERIENCE.md → Direction (L259).

The arrow gives direction. **Good versus bad** is a separate piece of information, and only colour carries it. This matters when Direction is "Lower is better": an expense ↗ shown in red looks identical in shape to a favourable ↗.

Screen readers read "↗ 14.2% from last year" as "north east arrow 14.2%…", or skip the glyph entirely.

**Fix:**
- "The delta has visually hidden text, for example: 'Up 14.2% from last year, favourable'. The glyph itself is `aria-hidden`.
- When Direction is *Lower is better*, the visible delta adds a word after the %: '↗ 3.8% · worse' or '↘ 2.1% · better'. This is the case where colour contradicts the usual reading of the arrow.
- Flat values read 'No change'."

Add these to the Comparison slot editor as part of the Label template defaults.

### S3 · Medium: live regions announce too much in some places and too little in others. SC 4.1.3
**Location:** EXPERIENCE.md → Accessibility Floor, Live regions (L650–655); State Patterns: Offline / reconnecting (L595), Live freshness (L596); Toast (L396–400); optimistic rollback "error toast" (L639).

**Fix (rules):**
- **Reconnecting** is announced once (`role=status`). On reconnect, one message: "Back online. Refreshing your dashboard."
- **Stale and Unavailable** are **aggregated per dashboard and debounced** (about 2 s): "3 blocks are stale. Last data 10:42." This avoids N separate announcements when a reconnect makes every Block stale. Recovery is announced once: "Blocks are up to date."
- **Live freshness, KPI values and chart data are never in live regions.** Nothing is announced on routine 30 s or interval refreshes.
- **The drawer count** is debounced about 500 ms after typing stops.
- **The Map Data status bar** announces only when the *required-missing* or *needs-review* count changes, not on every role edit.
- **Error and rollback toasts** use `role="alert"` and **do not auto-dismiss**.
- **The toast announcement names the Undo route**, for example "Revenue vs Expense added next to Recent activity. Undo with Ctrl+Z."

### S4 · Medium: "✓ Added" turns into "× Remove" on hover or focus, so the state is hidden. SC 4.1.2 and 1.3.1
**Location:** EXPERIENCE.md → Row actions (L371–374); DESIGN.md → drawer row actions (L762–766).

A keyboard or screen-reader user who tabs to the action hears only "Remove X from dashboard". The "Added" state is never announced. A mouse user who brushes "Added" sees it turn destructive.

**Fix:** "Added rows show a persistent '✓ Added' status text (`aria-hidden=false`, not a control) and a separate **Remove** button (destructive-soft, visible on hover, focus-within and touch; always in the accessibility tree), named 'Remove {Block} from dashboard'."

### S5 · Medium: role chips used as selects. SC 4.1.2, 1.4.1
**Location:** EXPERIENCE.md → Roles table (L224–231), Small components (L577); DESIGN.md → Role chips (L754–758).

**Fix:**
- "A role chip is a menu button, named 'Role for {field}: {role}', with `aria-haspopup="menu"`. A flagged chip adds ', possible wrong role' and the '!' glyph is `aria-hidden`.
- The menu uses `menuitemradio` with `aria-checked`.
- After a change, the T1 note ('revenue is now a Measure … Undo') is announced politely and focus returns to the chip."

Note: under simulated deuteranopia, Measure (blue) and Time (violet) chips are near-identical (ΔE 0.4 for text, 1.9 for fill). Because the chips carry their names this passes 1.4.1. Never show role chips as bare colour swatches, for example in the meter or legend.

### S6 · Medium: names, roles and values for other custom widgets
**Location:** EXPERIENCE.md → Small components (L567–578); DESIGN.md → Badges, Stepper, Slot row, Block Type faces.

Add this table to the Accessibility Floor:

| Widget | Required semantics |
|---|---|
| Slot status dot (✓ ? ✎ !) | `aria-hidden`. The status pill text is the value. "Confirmed by you" (currently hover only) becomes visible caption text or `aria-describedby` |
| Confidence badge | Text "Confidence: Medium". The meter is `aria-hidden` |
| Status-bar segmented meter | `aria-hidden`. The text summary is the value |
| Sample data badge | The "S" glyph is `aria-hidden`; the text is read |
| Progress list | Each row is a list item "Website redesign, 12 of 16 tasks, 78%". The bar is `aria-hidden` |
| Calendar week strip | `list` of days. Today has `aria-current="date"` and the name "Wednesday 22 May, today". The agenda is a list with "09:30, Product sync, Meeting room 2A, 45 min" |
| Segmented controls (Desktop/Tablet/Mobile, Fetch/Paste, Source) | `radiogroup` with `radio`, or buttons with `aria-pressed` |
| Health rows | "Dashboard API, Operational, 99.99% uptime". The dot is `aria-hidden` |
| Masked secret | "Secret set on 2 Oct 2026" instead of "bullet bullet…". Replace is named "Replace token" |
| Technical details | A disclosure button with `aria-expanded`. The request ID can be copied with a named "Copy request ID" button |
| Table Block | `th` scope. Sortable headers are buttons with `aria-sort` |

### S7 · Low: block-level structure
**Fix:**
- "Each Block is a `section` with `aria-labelledby` pointing to its title, which is a heading (h2 on a dashboard, under the page h1).
- The Block chrome controls include the Block name ('Refresh Revenue overview', 'Minimize Revenue overview', 'More actions for Revenue overview')."

---

## 4 · Timing and motion

### Handled well
- Toasts pause on hover **and** focus (T9).
- The reduced-motion list covers the Show me pulse, reflow, chart draw-in and the toast slide (L663).
- The edge pill persists until the user acts.
- Nothing auto-scrolls.

### T1 · High: Undo exists only in a toast that closes after about 6 s. SC 2.2.1
**Location:** EXPERIENCE.md → Toast (T9, L396–400), Remove (A6, L405–407; no confirmation), Interaction Primitives "Toasts with Undo" (L637).

Remove has no confirmation, so the toast's Undo is the only recovery. That recovery has a 6 s limit with no turn-off, adjust or extend option. A screen-reader user hears the polite message only after the current speech queue finishes, then must F6 (see K3) and Tab. That often takes more than 6 s. Re-adding is not equivalent: the Block lands at the first free slot, and its size and period choice are lost.

**Fix:** keep the toast and remove the time limit from the *function*:
1. "**Ctrl/⌘+Z on the dashboard** undoes the last add or remove (or layout move) for as long as the dashboard stays open, with no time limit."
2. "Toasts that carry an action stay at least 10 s, and **do not auto-dismiss while focus is inside the drawer or the toast**."
3. "Profile & settings has 'Keep notifications until I close them'."
4. "The toast announcement names the Undo route (S3)."

### T2 · High: Live and interval auto-refresh cannot be paused. SC 2.2.2
**Location:** EXPERIENCE.md → Refresh Interval Live ~30 s (L166), Dashboard header Live freshness (L596); Block Chrome Refresh (L433).

The content updates automatically, alongside other content, indefinitely. No pause, stop or control of frequency is specified. For screen-reader and cognitive users, values changing under the reading cursor are disorienting.

**Fix:**
- "The dashboard header shows a **'Pause live updates'** toggle (`aria-pressed`) whenever any Block refreshes automatically. While paused, Blocks show 'Paused · data as of 10:42'.
- Resuming refreshes immediately.
- The choice persists per user and per dashboard.
- Refreshes never animate value changes. With reduced motion there are no number tickers or chart transitions."

### T3 · High: no session-timeout behaviour is specified. SC 2.2.1
**Location:** EXPERIENCE.md → Password recovery "Remember me … maximum duration TBD" (L542). Nothing appears in the wizard or Security-conscious UX.

Regulated customers run short idle timeouts. The Map Data step is long and dense, and an unwarned expiry loses the Admin's work.

**Fix:**
- "Two minutes before an idle session expires, a dialog says 'You'll be signed out in 2:00 for security. **Stay signed in**'. The countdown is announced at 2:00, 1:00 and 0:30, not every second.
- Wizard state is saved as a local draft continuously and restored after re-authentication.
- Expiry while away shows 'You were signed out to protect your workspace. Your draft was saved.'"

### T4 · Low: gaps in reduced motion. SC 2.3.3 (AAA, advisory) and 2.2.2 for long animations
**Location:** EXPERIENCE.md → Reduced motion (L663).

**Fix:** add these to the list:
- the drawer push and slide;
- the sidebar collapse to the icon rail;
- accordion expand (drawer preview, slot editor);
- the "Just added" halo fade (use a static outline for 3 s, then remove);
- skeleton shimmer (static under reduced motion; and in every mode, shimmer stops after 5 s and shows "Still loading…").

---

## 5 · Forms and errors

### Handled well
- Labels sit above fields.
- Errors are inline in error-text, and focus moves to the first invalid field on submit.
- Validation runs on blur, not on every keystroke.
- Error copy is specific and suggests a fix (3.3.3), for example "Line 4 has an extra comma" and "Use a link that starts with https://".
- Disabled primary actions always show an inline reason, not only a tooltip (L348, L571).
- The permission-disabled Publish has its reason (A2).
- Password reset does not leak whether an account exists.

### F1 · Medium: disabled buttons hide their reason from keyboard and screen-reader users. SC 4.1.2, 1.3.1, 3.3.1
**Location:** EXPERIENCE.md → Shell, Publish (L144), "When Continue to Preview is enabled" (L341–348), POST read-only (L159), Live disabled (L166), Restore and Unpublish disabled (L493, L498); DESIGN.md → Disabled at 45% opacity (L670).

A native `disabled` button leaves the Tab order, so a keyboard or screen-reader user never reaches it or hears why. If the reason text inherits the 45% opacity, it falls to about 1.8:1.

**Fix:**
- "Blocked primary actions use `aria-disabled="true"`, stay focusable, and point `aria-describedby` at the inline reason.
- Activating one moves focus to the first blocker, for example the 'Chart series' slot row, and announces the reason.
- Reason text is never dimmed: it is always `text-secondary` or `error-text` at full opacity.
- Disabled select options (Live) carry their reason in the option's description."

### F2 · Medium: error association, the error summary and JSON paste errors. SC 3.3.1, 4.1.3
**Location:** DESIGN.md → Inputs, Error (L676); EXPERIENCE.md → Paste sample JSON (L169), Transforms errors (L321), Parameters table (L160–165).

**Fix:**
- "Invalid fields get `aria-invalid="true"` and `aria-describedby` pointing at the message. The message starts with an error icon and the visually hidden text 'Error:'.
- A step with more than one error shows an **error summary** at the top of the step, made of links to each field. Focus moves to the summary on submit.
- JSON paste validation results are announced politely.
- The textarea shows a line-number gutter. The error has **'Go to line 4'**, which places the caret at that line and column."

### F3 · Medium: autocomplete purposes are missing. SC 1.3.5
**Location:** EXPERIENCE.md → Sign in, Password recovery (L537–542), Profile & settings (L64).

**Fix:**
- "Email `autocomplete="username"`, password `current-password`, new and confirm `new-password`.
- Profile name `name`, email `email`.
- Pasting into password fields is allowed."

### F4 · Low: the required marker
**Location:** DESIGN.md → "Required fields mark the label with a red `*`" (L674).

**Fix:**
- "The `*` is `aria-hidden`, and the input has `required` or `aria-required`.
- Each form shows '* Required' once at the top in caption text-secondary."

### F5 · Medium: focus and roles for destructive confirmation dialogs. SC 2.4.3, 4.1.2, 3.3.4 (spirit)
**Location:** DESIGN.md → Dialog (L819–821); EXPERIENCE.md → Unpublish and archive (L496–501), Reset to template (L524, no Undo), Delete, access change (B3, L186), Restore with a draft present (B2).

**Fix:**
- "Destructive confirmations are `role="alertdialog"`, with `aria-labelledby` pointing at the title and `aria-describedby` pointing at the impact text.
- **Initial focus is on Cancel.**
- Esc cancels. Focus returns to the invoking control, or to its row if that control no longer exists.
- Destructive buttons repeat the object ('Reset Finance weekly – Jamie')."

### F6 · Low: the show/hide password control
**Fix:** "A button named 'Show password' with `aria-pressed`. Toggling keeps the caret and the value."

---

## 6 · Reflow, zoom and target size

### Handled well
- The dashboard is a single column below about 720 px with no horizontal page scroll.
- The drawer becomes a modal sheet below 1024 px, so at 200% zoom on a 1440 px screen (720 CSS px) users get the sheet automatically.
- Mobile reorder has Move up and Move down.
- The target floor of 24 px (28 px for chrome, 44 px for touch) is stated.

### R1 · High: the wizard at 320 CSS px and the "desktop recommended" banner. SC 1.4.10
**Location:** EXPERIENCE.md → Responsive table, Wizard column (L790–791); DESIGN.md → `slot-row.columns` (L323): 18 + 140 + 168 + 1fr + 126 + 96 px, plus gaps, is about 600 px minimum. The expanded three-column editor (L752) and the transforms sentence rows are also affected.

A desktop user at 400% zoom on a 1280 px screen gets 320 CSS px, the mobile layout, **and a banner telling them to use a desktop**. The slot row, the expanded editor and the scalar cards (sketch: four columns) have no specified narrow layout. The slot checklist is not a data table, so it is not exempt from reflow.

**Fix:**
- "Below 640 px:
  - slot rows stack as: status + name, then field, then presentation summary, then pill + actions;
  - the expanded editor stacks Format, Emphasis and Direction;
  - scalar cards are one per row;
  - transform sentences wrap.
- The sample-rows table and the JSON view may scroll horizontally inside a focusable region labelled 'Sample rows, scrolls sideways' (`tabindex=0`).
- The banner reads 'This step has a lot of detail. A wider window shows the preview beside the checklist.' It has a Dismiss control, is never shown again once dismissed, and never blocks."

### R2 · Medium: fixed pixel heights clip text at 200% text zoom or with text-spacing overrides. SC 1.4.4, 1.4.12
**Location:** DESIGN.md → `button-*` height 34px, `input` 36px, `block-height-small` 120px (KPI card), Medium 360 and Large 540 (L181–183, L196, L230).

**Fix:**
- "Control heights are `min-height`, with padding that grows with the text.
- Block height presets set the **grid** height. Block content that overflows scrolls inside the Block body, which is a focusable region labelled with the Block title, and is never clipped.
- KPI cards grow to fit their content in the narrow and single-column layouts.
- No `line-height` or letter-spacing is fixed in px."

### R3 · Medium: stacked sticky layers hide content at high zoom. SC 1.4.10 (and 2.4.11 from WCAG 2.2, advisory)
**Location:** EXPERIENCE.md → Map Data sticky status bar and sticky preview (L201, L204, T4), drawer sticky header (L365), tablet bottom "Preview" bar (L790), top bar 64 px.

At 1280×1024 and 400% the viewport is 320×256 CSS px. A 64 px top bar, the status bar and the bottom preview bar leave almost nothing.

**Fix:**
- "When the viewport height is below 480 CSS px, only the top bar stays sticky. The status bar and the preview bar scroll with the content.
- Focused elements are scrolled clear of sticky layers, using `scroll-padding` equal to their height."

### R4 · Low: target sizes are not specified per component
**Location:** DESIGN.md → `chip-category` (no height), drawer `+ Add` and `× Remove`; sketch: chips are about 20 px tall, `.btn-add` about 24 px, and the toast × is a `span`.

**Fix:**
- Give components explicit `min-height`: chips 28 px; row actions, toast actions and toast close 28 px. On touch layouts all of these are 44 px.
- The toast close is a `button` named "Dismiss notification".
- 2.5.5 is AAA and 2.5.8 is WCAG 2.2, so this enforces the spine's own floor rather than a 2.1 AA requirement.

### R5 · Low: the mobile chip scroller and sheet
**Fix:**
- "Chips that scroll horizontally scroll into view on focus, and the edge fade never covers the focused chip.
- The sheet has a visible 44 px Close button in addition to the dim strip.
- The toast inside the sheet never covers the focused element."

---

## 7 · Cognitive and data-handling clarity (regulated users)

### Handled well
- **Sample data** is labelled in words in every place it appears. It uses a single badge rather than repeating noise (T5). It states that published Blocks call the live API after a shape check, and the preview region name includes "with sample data".
- **Honest numbers:** Unavailable is never shown as 0, and Stale always shows its time.
- **Permissions:** denied actions explain themselves, say what is still possible ("You can still save drafts") and are audited.
- **Plain language:** a calm, specific and actionable voice. Technical detail sits behind a disclosure for Admins only, and users never see codes.
- **Masked secrets** are write-only and include the date they were set.

### D1 · Low: masked values for assistive technology
See S6, "Masked secret". In addition: "fetch as user" shows attribute *names*, which is good. Make sure screen-reader text never includes resolved user-context values either.

### D2 · Low: time and timezone ambiguity in freshness labels
**Location:** "Stale: last data 10:42", "Last success 10:42", "Data as of 10:40".

For audit-minded users in several timezones, a bare time is ambiguous after midnight or across zones.

**Fix:** "Freshness times include the date when it is not today ('Stale: last data yesterday 22:10'). About this block shows the full timestamp with timezone, for example 'Data as of 5 Oct 2026 10:40 BST'."

### D3 · Low: domain jargon in Admin mapping
Terms such as "Record path", "Measure", "Dimension", "Filter/Category" and `PCT_CHANGE` are shown without in-context definitions outside the role legend.

**Fix:** "Each role chip menu item and each transform function has a one-line description, available on focus, not only on hover (`aria-describedby`; 1.4.13: dismissible with Esc and hoverable)."

**General 1.4.13 rule to add:** every tooltip (hotspot, icon rail, disabled-reason tooltip, "Confirmed by you") appears on focus as well as hover, can be dismissed with Esc without moving focus, and stays visible while the pointer is over it.

---

## Contrast table (computed, WCAG 2.x relative luminance)

### Text (4.5:1 for normal text; 3:1 for text of at least 18.66 px bold or 24 px)

| Pair | Foreground / background | Ratio | Result |
|---|---|---|---|
| text-primary on white | #111827 / #FFFFFF | 17.74 | Pass |
| text-primary on canvas | #111827 / #F7F8FA | 16.69 | Pass |
| text-secondary on white | #374151 / #FFFFFF | 10.31 | Pass |
| text-secondary on surface-muted (tag, lock) | #374151 / #F3F4F6 | 9.37 | Pass |
| text-muted on white | #6B7280 / #FFFFFF | 4.83 | Pass |
| text-muted on canvas | #6B7280 / #F7F8FA | 4.55 | Pass (thin) |
| text-muted on surface-sunken | #6B7280 / #F9FAFB | 4.63 | Pass |
| text-muted on accent-wash | #6B7280 / #FFFBEB | 4.66 | Pass |
| text-muted on accent-soft | #6B7280 / #FEF9C3 | 4.50 | Pass (no margin), C10 |
| **text-muted on surface-muted** (Optional pill, stepper numeral) | #6B7280 / #F3F4F6 | **4.39** | **Fail**, C5 |
| **text-muted on error-soft** (captions in error rows) | #6B7280 / #FEF2F2 | **4.42** | **Fail**, C5 |
| **text-subtle** (placeholder) on white | #9CA3AF / #FFFFFF | **2.54** | **Fail**, C6 |
| text-subtle on surface-sunken | #9CA3AF / #F9FAFB | 2.43 | Fail (decorative only) |
| accent-ink on white | #A16207 / #FFFFFF | 4.92 | Pass |
| accent-ink on accent-soft | #A16207 / #FEF9C3 | 4.58 | Pass |
| accent-ink on canvas | #A16207 / #F7F8FA | 4.63 | Pass |
| accent-ink on accent-wash | #A16207 / #FFFBEB | 4.75 | Pass |
| accent-ink on surface-sunken | #A16207 / #F9FAFB | 4.71 | Pass |
| accent-ink-strong on white | #854D0E / #FFFFFF | 6.85 | Pass |
| accent-ink-strong on accent-soft (badges) | #854D0E / #FEF9C3 | 6.38 | Pass |
| accent as text on white (forbidden) | #FACC15 / #FFFFFF | 1.53 | Fail (correctly banned) |
| sketch active step #CA8A04 on accent-soft | #CA8A04 / #FEF9C3 | 2.74 | Fail (sketch only), C9 |
| on-accent on accent (primary button, New, Just added, today) | #111827 / #FACC15 | 11.58 | Pass |
| on-accent on accent-strong (hover) | #111827 / #EAB308 | 9.25 | Pass |
| white on accent | #FFFFFF / #FACC15 | 1.53 | Fail (never use; the on-accent auto-switch handles this) |
| success-text on white | #15803D / #FFFFFF | 5.02 | Pass |
| success-text on success-soft (High, Operational, Confirmed) | #15803D / #DCFCE7 | 4.57 | Pass |
| success-text on success-wash ("✓ Added") | #15803D / #F0FDF4 | 4.79 | Pass |
| success-text on surface-sunken (GET prefix) | #15803D / #F9FAFB | 4.80 | Pass |
| success #16A34A as text on white | #16A34A / #FFFFFF | 3.30 | Fail (correctly banned for text) |
| sketch #16A34A on #DCFCE7 pills | #16A34A / #DCFCE7 | 3.00 | Fail (sketch only), C9 |
| error on white (negative delta) | #DC2626 / #FFFFFF | 4.83 | Pass |
| **error on error-soft** (destructive-soft, Required pill) | #DC2626 / #FEF2F2 | **4.41** | **Fail**, C5 |
| error-text on error-soft | #B91C1C / #FEF2F2 | 5.91 | Pass |
| white on error (destructive button, bell badge) | #FFFFFF / #DC2626 | 4.83 | Pass |
| warning on white (Stale) | #C2410C / #FFFFFF | 5.18 | Pass |
| warning on warning-soft (Low, Partial data) | #C2410C / #FFEDD5 | 4.52 | Pass (thin) |
| info on white | #1D4ED8 / #FFFFFF | 6.70 | Pass |
| info-strong on info-soft (Overridden, Reconnecting) | #1E40AF / #DBEAFE | 7.15 | Pass |
| role Label | #374151 / #F3F4F6 | 9.37 | Pass |
| role Value | #854D0E / #FEF08A | 5.89 | Pass |
| role Dimension | #0F766E / #CCFBF1 | 4.86 | Pass |
| role Measure | #1D4ED8 / #DBEAFE | 5.49 | Pass |
| role Time | #6D28D9 / #EDE9FE | 5.98 | Pass |
| role Filter/Category | #C2410C / #FFEDD5 | 4.52 | Pass (thin) |
| text-inverse on surface-inverse (toast, tooltip) | #F9FAFB / #111827 | 16.98 | Pass |
| toast action accent-border on inverse | #FDE047 / #111827 | 13.46 | Pass |
| toast close #9CA3AF on inverse | #9CA3AF / #111827 | 6.99 | Pass |
| hero-text-muted on hero-surface | #9099A6 / #0A0E11 | 6.73 | Pass |
| accent on hero-chip (tagline) | #FACC15 / #1C1C13 | 11.20 | Pass |
| disabled primary (45% opacity): text on fill | ≈#938A64 / ≈#FDE896 | 2.83 | Exempt (inactive); the reason text must not be dimmed (F1) |
| reason text if dimmed to 45% (text-muted) | ≈#BCC0C6 / #FFFFFF | 1.83 | Would fail, F1 |

### Non-text (3:1 against adjacent colours)

| Element | Colour / against | Ratio | Result |
|---|---|---|---|
| focus-ring on white | #2563EB / #FFFFFF | 5.17 | Pass |
| focus-ring on canvas | #2563EB / #F7F8FA | 4.86 | Pass |
| focus-ring on accent-soft | #2563EB / #FEF9C3 | 4.81 | Pass |
| focus-ring on error-soft | #2563EB / #FEF2F2 | 4.72 | Pass |
| focus-ring on surface-inverse (inside the toast) | #2563EB / #111827 | 3.43 | Pass |
| focus-ring next to an accent fill | #2563EB / #FACC15 | 3.38 | Pass |
| focus-ring on hero-surface | #2563EB / #0A0E11 | 3.75 | Pass |
| **border-strong** (input, select, checkbox) on white | #D1D5DB / #FFFFFF | **1.47** | **Fail**, C3 |
| border-strong on canvas | #D1D5DB / #F7F8FA | 1.39 | Fail, C3 |
| proposed `border-control` on white / canvas / sunken | #80868F | 3.67 / 3.45 / 3.51 | Pass |
| checked checkbox edge (accent-strong) | #EAB308 / #FFFFFF | 1.92 | Fail (the check mark at 11.58 carries the state), C3 |
| selected role card border (accent) | #FACC15 / #FFFFFF | 1.53 | Fail, C7 |
| selected-state fill (accent-soft against white) | #FEF9C3 / #FFFFFF | 1.07 | Fail as the only indicator, C4 / C7 |
| hotspot idle dashed (border-default) | #E5E7EB / #FFFFFF | 1.24 | Fail (the pane header text compensates), K6 |
| hotspot hover or focus (accent on accent-wash) | #FACC15 / #FFFBEB | 1.48 | Fail for focus, C4 |
| "+ Add" border (accent-border) | #FDE047 / #FFFFFF | 1.32 | Text-identified; acceptable |
| resize corner (accent) | #FACC15 / #FFFFFF | 1.53 | Fail, C8 |
| just-added outline (accent) | #FACC15 / canvas | 1.47 | Informational, with a "Just added" text pill; acceptable |
| Required-missing left rule (error on error-soft) | #DC2626 / #FEF2F2 | 4.41 | Pass |
| error-border pill outline | #FCA5A5 / #FFFFFF | 1.90 | Pill text carries the meaning; acceptable |
| **chart-1** yellow line | #FACC15 / #FFFFFF | **1.53** | **Fail**, C1 |
| chart-2 slate | #4C6EB1 / #FFFFFF | 5.03 | Pass |
| chart-3 teal | #0D9488 / #FFFFFF | 3.74 | Pass |
| chart-4 violet | #8B5CF6 / #FFFFFF | 4.23 | Pass (CVD clash with chart-2), C2 |
| **chart-5** orange | #F97316 / #FFFFFF | **2.80** | **Fail**, C1 |
| **chart-6** grey | #9CA3AF / #FFFFFF | **2.54** | **Fail**, C1 |
| proposed chart-1-stroke (accent-ink) | #A16207 / #FFFFFF | 4.92 | Pass |
| proposed chart-3 / 4 / 5 / 6 | #0F766E / #DB2777 / #EA580C / #6B7280 | 5.47 / 4.60 / 3.56 / 4.83 | Pass |
| chart-grid gridlines | #F3F4F6 / #FFFFFF | 1.10 | Acceptable only while gridlines are not the sole value cue (the axis labels are) |
| progress fill chart-1 against its track | #FACC15 / #F3F4F6 | 1.39 | Supplementary to the % text, C11 |
| success status dot / step check fill | #16A34A / #FFFFFF | 3.30 | Pass |
| warning dot / error dot | #C2410C, #DC2626 / #FFFFFF | 5.18 / 4.83 | Pass (but 3.3 ΔE apart under deuteranopia, so the words are required; they are present) |
| success check in toast | #16A34A / #111827 | 5.38 | Pass |

### Colour-vision simulation summary (CIEDE2000; below 10 means effectively the same colour)

| Set | Worst pairs |
|---|---|
| Chart palette (current) | slate/violet 8.9 (protan), 9.1 (deutan), 16.8 (normal vision); teal/grey 11.3–11.8; yellow/orange 12.8 (deutan) |
| Chart palette (proposed, C2) | First four series ≥ 12.6 under protan and deutan; weakest is teal/grey 5.0 (deutan), only when 6 series are shown together, so the mandatory dash or marker covers it |
| Role chip text | Measure/Time 0.4 (deutan), 3.7 (protan); Value/Filter 3.0 (protan). Passes because names are always shown |
| Delta positive/negative | 10.4 (protan), 12.7 (deutan). Arrows cover direction; S2 covers good versus bad |
| Status success/warning/error | warning/error 3.3 (deutan). Words are always shown, so this passes |

---

## Resolution (2026-10-05)

Applied to `DESIGN.md` and `EXPERIENCE.md` using decisions R1–R7 in `.memlog.md`. Status is **Resolved**, **Partial** or **Not adopted**.

| ID | Sev. | Status | What changed |
|---|---|---|---|
| S1 | critical | Resolved | New EXPERIENCE **Chart Block** pattern. Every chart is `role="img"` with a generated summary, and its ⋯ menu always has **View as table** (opens `expanded-view` with an accessible table). ←/→ step through points (↑/↓ switch series), with `chart-point-focus` and the tooltip on focus, and the value announced politely. The legend names each series' marker and dash. Accessibility Floor → Charts added |
| C1 | high | Resolved | Per R1: `chart-1` stays the fill; new `chart-1-stroke` #A16207 (4.92) for lines, points and fill edges; yellow bars and slices take a 1px stroke edge. chart-5 → #EA580C (3.56), chart-6 → #6B7280 (4.83) |
| C2 | high | Resolved | Per R2: palette is yellow/amber, slate #4C6EB1, teal #0F766E, pink #DB2777, orange #EA580C, grey #6B7280. `chart-series-style` makes direct labels or dash and marker variants mandatory for 2 or more series; bar and pie fill patterns from series 3 on; the legend shows the marker and dash |
| C3 | high | Resolved | New `border-control` #80868F, used by input, select, textarea, checkbox, radio, switch, segmented control and the method-prefix join. `border-strong` is limited to decorative and secondary-button use |
| C4 | high | Resolved | New `listbox-option-active` (accent-soft plus a 2px inset focus-ring, 4.81) for the palette, pickers, menus, the tree and switchers; `listbox-option-selected` uses a ✓ or a 3px accent-ink-strong bar. `preview-hotspot.focus` uses the standard focus ring; the accent outline is for hover only |
| K1 | high | Resolved | New **Focus stability** in Interaction Primitives: in-place updates with stable keys; the nearest-survivor rule with an announcement; never auto-collapse, re-sort or re-pin while focus is inside. Applied to Refresh, the Date Range, Remove, the T4 roles collapse, the Needs-review filter, low-confidence pinning, transform removal, Table refresh and optimistic rollback |
| S2 | high | Resolved | Hidden delta text ("Up 14.2% from last year, favourable"), with the glyph `aria-hidden` and "No change" for flat values. A visible "· better" / "· worse" for Lower-is-better (`kpi-card.deltaWord`, also on admin metric cards). Added to the Comparison editor's Label and Direction |
| T1 | high | Resolved | Per R3: action toasts stay at least 10 s and persist while focus is in the drawer or toast; Ctrl/⌘+Z undoes the last add or remove with no time limit; the announcement names the Undo route. The setting "Keep notifications until I close them" is **not adopted** (not part of R3) |
| T2 | high | Resolved | Per R4: new **Live updates and pause** pattern, with the `live-updates-toggle` (`aria-pressed`) in the dashboard header and `msg:paused`. Resume refreshes at once; no value animations. The pause is not persisted across visits [ASSUMPTION]. The review suggested persisting it per user and dashboard; this was not adopted, so nobody returns to quietly old data |
| T3 | high | Resolved | Per R5: new **Session timeout** pattern, a 2-minute warning with Stay signed in, the countdown announced at 2:00, 1:00 and 0:30. The wizard autosaves continuously and restores after re-sign-in (`msg:session-expired`); other forms get the warning only |
| R1 | high | Resolved | Below 640px, slot rows, the expanded editor, scalar cards, transform rows and threshold rows stack; only the sample table and JSON view scroll sideways, inside labelled focusable regions. The banner is `msg:map-small-screen`, dismissible and never blocking (DESIGN Layout, EXPERIENCE Map Data, Responsive, Accessibility Floor) |
| C5 | medium | Resolved | The stepper numeral and Optional pill use text-secondary; error-row captions use text-secondary; destructive-soft and error pills use error-text. The sketch pills are covered under C9 |
| C6 | medium | Resolved | `input.placeholder` → text-muted; text-subtle is decorative only; search fields have an accessible name; placeholders carry no instructions |
| C7 | medium | Resolved | Role card: radio indicator plus a 2px accent-ink-strong border. Sidebar and icon rail: a 3px accent-ink-strong bar plus `aria-current`. Segmented control: a border-control edge, weight 600 and radiogroup semantics. Chip: a ✓ plus an accent-ink-strong border |
| C9 | medium | Partial | DESIGN → Brand & Style and Inspiration state that the promoted sketches' failing colours are superseded and name them. **Correcting the sketch CSS, or adding a banner inside the files, is left to the parent's promotion step**; this pass did not edit the HTML |
| K2 | medium | Resolved | Drawer rows are a `listitem` containing a disclosure button (`aria-expanded`, `aria-controls`) and separate action buttons; the keys are defined |
| K3 | medium | Resolved | Landmarks (`banner`, `navigation`, `main`, `complementary` "Add blocks", `region` "Notifications") plus skip links ("Skip to content", "Back to dashboard", "Go to notifications", "Skip to slot checklist"); F6 is an enhancement only |
| K4 | medium | Resolved | Single-key shortcuts are scoped to their focused component and not in text fields; per R6, there is a "Keyboard shortcuts" switch, On by default, which lists the shortcuts. The "?" shortcut-list key is **not adopted**: it would add another single-key shortcut |
| K5 | medium | Resolved | APG grab pattern: a Move {Block} button; M or Space picks up, Enter drops, Esc cancels the move [ASSUMPTION], and a second Esc or Done exits and saves. Relative announcements; Ctrl/⌘+Z maps to the mode's Undo |
| K6 | medium | Resolved | Hotspots are buttons named "{Slot}: {field}, {value}. Change field"; the placeholder is a button; preview chrome is inert; focus goes to the picker search and returns on Esc; the preview region has its label and skip link |
| K7 | medium | Resolved | Slot rows are a `group` with their own Tab-order buttons; ↑/↓ is optional; Confirm is never bound to Enter on the row |
| K8 | medium | Resolved | APG tree: `aria-level`, `aria-expanded`, `aria-setsize`, `aria-posinset`; Enter on a leaf is Use, and the Use pill is `aria-hidden`; `aria-disabled` with a reason; a match count; the breadcrumb is a `nav` |
| S3 | medium | Resolved | The Live regions rules are rewritten: reconnect and back-online announced once; Stale and Unavailable aggregated and debounced; routine refreshes, values and chart data never announced; the count debounced; the status bar announces only count changes; error and rollback toasts are `role="alert"` and do not auto-dismiss |
| S4 | medium | Resolved | "✓ Added" is persistent status text; a separate Remove button sits beside it, visible on hover, focus-within and touch, and always in the accessibility tree |
| S5 | medium | Resolved | Role chips are menu buttons with `menuitemradio` items, flagged names, a polite T1 note and focus return; a rule bans bare role swatches |
| S6 | medium | Resolved | Semantics are written into the patterns and Small components: status dot, confidence, meter, sample badge glyph, progress list, calendar strip, segmented controls, health rows, masked secret, Technical details, Table Block |
| F1 | medium | Resolved | Interaction Primitives → **Disabled controls**: `aria-disabled` plus `aria-describedby`, the control stays focusable, activating it focuses the first blocker, reason text is never dimmed (`button-disabled.reason`), and disabled options carry their reason |
| F2 | medium | Resolved | `aria-invalid` plus `aria-describedby` and "Error:"; an error summary for more than one error (wizard steps and forms); JSON results announced; a line-number gutter and "Go to line" (`json-textarea`) |
| F3 | medium | Resolved | `autocomplete` values on sign-in, recovery and profile fields; paste allowed into passwords |
| F5 | medium | Resolved | Interaction Primitives → **Destructive confirmations**: `alertdialog`, initial focus on Cancel, Esc cancels, the button repeats the object, focus returns. Applied to unpublish, archive, reset, delete, replace draft and access change |
| R2 | medium | Resolved | `minHeight` on controls; Block presets set the grid height, and overflow scrolls inside a focusable Block body; KPI cards grow; no px line height |
| R3 | medium | Resolved | Under 480 CSS px of viewport height only the top bar is sticky [ASSUMPTION — accepted default: the threshold]; `scroll-padding` on sticky layers |
| C8 | low | Resolved | `resize-handle`: a 24×24 hit area with an accent-ink-strong glyph (6.85) |
| C10 | low | Resolved | Rule added: no text-muted on accent-soft |
| C11 | low | Resolved | The progress bar is supplementary and `aria-hidden`; the % is the value |
| K9 | low | Resolved | Stepper semantics: a `nav` with an `ol`, `aria-current="step"`, and later steps as plain text |
| S7 | low | Resolved | Each Block is a `section` labelled by its heading; chrome controls include the Block name |
| T4 | low | Resolved | The reduced-motion list adds the drawer push, sidebar collapse, accordions, a static just-added outline and static shimmer; shimmer stops after 5 s with "Still loading…" |
| F4 | low | Resolved | `*` is `aria-hidden`, fields use `required`, and "* Required" appears once per form |
| F6 | low | Resolved | "Show password" is a button with `aria-pressed`; the caret and value are kept |
| R4 | low | Resolved | `target-min`, `target-chrome` and `target-touch` tokens; chips, row actions and toast actions at least 28px; the toast close is a named button |
| R5 | low | Resolved | Chips scroll into view on focus and the fade never covers the focused chip; the sheet has a 44px Close; the toast never covers the focus |
| D1 | low | Resolved | Masked secrets are read as "Secret set on…"; screen-reader text never includes resolved user-context values |
| D2 | low | Resolved | `msg:stale` adds the date when it is not today [ASSUMPTION]; About this block shows the full date and time zone |
| D3 | low | Resolved | Role menu items and transform functions have one-line descriptions on focus; a general 1.4.13 tooltip rule is in Small components |

**Counts:** critical 1 of 1 resolved; high 10 of 10 resolved; medium 20 of 21 resolved, 1 partial (C9: the HTML sketches still need their colours corrected or a banner at promotion); low 13 of 13 resolved. Two sub-suggestions were not adopted because they would add product behaviour beyond R3 and R6: T1's "Keep notifications until I close them" and K4's "?" key.
