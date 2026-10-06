# Spine Pair Review — rmg-dashboard-platform

Reviewed: `DESIGN.md` (7,020 words, 73 component token groups, 80 colour tokens) and `EXPERIENCE.md` (13,746 words), against `.memlog.md`, `reconcile-inputs.md`, brief v4, PRD v3.2 and the addendum. Date: 2026-10-05.

Not flagged, as instructed: `[ASSUMPTION]` and `[PROPOSED]` tags (accepted defaults), the light-only palette, the TBD admin-overview metric windows, and the generic-only states for settings and admin screens. Where a generic state is *claimed* but not actually written, that gap is flagged.

## Overall verdict

**Adequate. The pair is usable as a contract, but fix the high findings before story development.**

The mechanics are clean:
- every `{token}` reference resolves (93 unique references; the only miss is the false positive `{user.email}`);
- every colour token has a hex value;
- the token names are semantic, so a dark theme can be added;
- the sources resolve;
- DESIGN.md sections are in canonical order;
- all five PRD journeys are covered by Key Flows.

The weaknesses are coverage gaps that a downstream builder would have to invent:
- three CONFIRMED MVP Block Types (Table, Bar chart, Pie/Donut) have no visual spec;
- most of Map Data's own sub-components, and all generic admin tables and form controls, have no visual spec;
- several documented colour pairs fail WCAG AA (text and non-text);
- the Map Data layout does not fit at 1280px;
- charts have no specified interaction or non-visual access.

EXPERIENCE.md is about 1.8× longer than it needs to be, mostly because copy and FR content is repeated.

## 1. Flow coverage — adequate

Extracted from PRD §2.3: UJ-1 to UJ-6. Also extracted: the brief's core Admin job "register a REST API" (it is not a UJ). Mapping:
- UJ-1 → Flow 1 (edge case → Flow 4);
- UJ-2 and UJ-6 → Flow 3;
- UJ-3 → Flow 2;
- UJ-4 → Flow 5;
- UJ-5 → Flow 4;
- the brief job → Flow 0.

Every flow has numbered steps, a **Climax** beat and a failure path. No journey is missing.

### Findings
- **medium** Flow headings do not use the PRD UJ names verbatim (EXPERIENCE → Key Flows, Flows 1–5). Examples:
  - "Alex publishes 'Revenue overview' (UJ-1)" vs PRD "UJ-1. Alex (Admin) builds the 'Revenue overview' block";
  - "Jamie adds a block (UJ-3)" vs "Jamie (User) personalizes the Overview";
  - "API changed / Unavailable recovery (UJ-1 edge case, UJ-5)" vs "An API outage";
  - "Jamie creates a dashboard from a template" vs "…creates a second dashboard from a template".

  Traceability tools and story writers that match on UJ names will miss these. *Fix:* use the PRD UJ title verbatim as the heading, and keep the current wording as a subtitle if wanted.
- **medium** The Unavailable copy contradicts PRD UJ-1 without a tag. The PRD edge case says the headline shows "Unavailable: total missing". EXPERIENCE (Voice row "Unavailable", Flow 4 step 2) shows Users "Unavailable — this value can't be shown right now" and keeps the field name for Admins and About this block. This is a reasonable refinement (Users never see field paths), but it is not tagged as a UX decision, so a developer reading the PRD will build the other string. *Fix:* tag it `[UX DECISION]` and add it to the PRD v3.2 alignment list, or adopt the PRD string.
- **low** Flows 3, 4 and 5 have no protagonist line (name, role, context or viewport). Flows 0 to 2 do (EXPERIENCE → Flow 3/4/5 headers). *Fix:* add one line each, for example "Alex Morgan, Admin with publish permission" and "Jamie Davis, User, desktop".
- **low** Flow 2 contradicts PRD UJ-3 on small details:
  - "9 available blocks" vs the PRD's "8 available blocks";
  - step 6 says Jamie hovers "Recent Activities", but the toast says "Recent activity removed".

  (EXPERIENCE Flow 2, steps 2 and 6.) *Fix:* use 8, and use the configured name "Recent activity" throughout.

## 2. Token completeness — adequate

- **Colours:** 80 tokens, all hex. None is missing a value, so there are no critical findings here.
- **References:** 93 unique `{path}` references across both files; all resolve except `{user.email}` (see below).
- **Unreferenced tokens:** spacing `2`, `5` to `8`, `hero-surface-raised`, `hero-card` and `hero-border`, plus most `role-*` and `*-border` tokens. These are used by name in prose or by family convention, which is acceptable.
- **Dark-theme readiness:** names are semantic (`surface-*`, `text-*`, `on-accent`, `on-status`, `success-text`…), and the `-dark` suffix convention is stated. This passes.
- **Contrast:** stated for most text pairs. I recomputed the key pairs; the failures are listed below.

### Findings
- **high** Several documented text-on-fill combinations fail WCAG AA 4.5:1, although the spine claims AA for every load-bearing pair:
  - `button-destructive-soft` ("× Remove" in every drawer row): foreground `{colors.error}` on `{colors.error-soft}` = **4.41:1** (DESIGN frontmatter `button-destructive-soft`);
  - the "Optional" slot pill, and the upcoming stepper numeral: `{colors.text-muted}` on `{colors.surface-muted}` = **4.39:1** (DESIGN → Slot row table "Optional-empty"; `stepper-step`);
  - the "Required missing" error pill has no text colour specified; if it uses `error` on `error-soft`, it also fails (4.41).

  *Fix:*
  - use `{colors.error-text}` (5.91:1) as the foreground for `button-destructive-soft` and the error pills;
  - use `{colors.text-secondary}` (9.37:1) on `surface-muted` for the Optional pill and the upcoming numeral;
  - add the resulting ratios next to the tokens.
- **high** Non-text contrast (WCAG 1.4.11, 3:1) is neither stated nor met for load-bearing controls:
  - **form controls:** input, select and unchecked checkbox borders use `{colors.border-strong}` = **1.47:1** on white; the checked checkbox is accent on white, 1.53:1, with an `accent-strong` border, 1.92:1;
  - **chart series:** `chart-1` = 1.53:1, `chart-5` = 2.80:1, `chart-6` = 2.54:1 on white. Labels and legends help identify a series, but they do not make the line perceivable;
  - **placeholder text:** `text-subtle` = 2.54:1 (1.4.3 applies to placeholder text).

  (DESIGN → `input`, `checkbox`, Colors → Chart palette, `text-subtle`.) *Fix:*
  - add an `input-border` / `control-border` token at ≥3:1 (for example `#8A94A3` or `text-muted`);
  - give the checkbox a ≥3:1 border in both states;
  - use `text-muted` for placeholders;
  - for charts, require ≥3:1 strokes (darken chart-5 and chart-6, and give series 1 a darker outline or companion marker), or record an explicit accepted exception;
  - add a "Non-text contrast" line to DESIGN → Colors.
- **medium** Shadows and halos are raw `rgba()` values outside the token system:
  - `toast.shadow`, `dialog.shadow`, the drawer-row shadow in prose, and `scrim` at "45%";
  - `just-added-outline.halo` hard-codes `rgba(250,204,21,.18)`, which is the default accent. This contradicts the Do's row "Hard-code hex values … would block the dark theme and Workspace branding", and the halo will not follow a Workspace accent override.

  *Fix:* add `elevation` / `shadow-*` tokens (or colour tokens such as `shadow-color` and `scrim-opacity`), and express the halo as `{colors.accent}` at an alpha.
- **medium** The rule that accent is never used for text is contradicted:
  - the sign-in tagline is `{colors.accent}` text on `hero-chip` (`signin-hero.tagline`; the `hero-chip` comment);
  - toast actions are `{colors.accent-border}` text on `surface-inverse` (`toast.actionColor`).

  Both pass contrast today, on dark surfaces. But the override guardrail ("Brand tokens", EXPERIENCE → Branding & Theming) only checks `on-accent` and `accent-ink`, so a Workspace accent override could make them fail. *Fix:* either qualify the rule ("never text on light surfaces") and extend the guardrail to accent-as-text on dark surfaces, or move both to dedicated tokens such as `hero-accent-text` and `toast-action`.
- **low** `{user.email}` in EXPERIENCE (Map Data → Footer action target) uses the token-reference syntax for a URL-template field token, so a resolver reports it as an unresolved design token. *Fix:* write it as `` `{user.email}` `` inside an explicitly marked URL-template example, or use another delimiter in prose.
- **low** Several values sit off the token scales or outside tokens:
  - off-scale radii: `role-chip.radius: 7px` and `checkbox.radius: 4px`;
  - popover widths (560, 360 and 320px) and stepper geometry (22px numeral, 56px connector) exist only in prose;
  - `role-chip` has no token binding to the per-role `role-*-text/fill/border` triples, so the binding depends on a naming convention.

  *Fix:* add `rounded.xs` and the popover-width spacing tokens, and add `role-chip-{role}` component entries that reference the triples.

## 3. Component coverage — thin

**DESIGN Components (visual) vs EXPERIENCE Component Patterns (behaviour).** These have both a visual spec and a behaviour spec: drawer and drawer row, toast, the stepper and wizard shell, slot row, role chip, Block Chrome, dialog, command palette, notifications, workspace switcher, header pickers, the admin overview cards and rows, and the sign-in role cards.

Visual-only, with no interaction rules: KPI card, list row, progress row, activity row, calendar strip and agenda row, chart marks.

Behaviour-only, or with no visual spec at all: see the findings below.

### Findings
- **high** Three CONFIRMED MVP Block Types have no visual spec: **Table / Data grid**, **Bar chart** (grouped and stacked) and **Pie / Donut**. The PROPOSED **Text / Status** type has none either (PRD FR-32). DESIGN → Block Type faces covers only List, Progress list, Activity feed and Calendar, and Chart marks covers only line and area. EXPERIENCE → Block-Type settings likewise omits the Bar stack/series key, the Pie centre value and Text/Status, although the Data Mapping Model mentions "Pie centre value". Renderer stories for 3 of the 5 change-signal Block Types have no visual contract. *Fix:*
  - in DESIGN, add faces and tokens: `table-header`, `table-row`, `table-cell-numeric`, sort indicator, pagination, row highlight; `chart-bar`, with gap and stacking; `chart-pie`, with the centre label, slice order and slice border;
  - in EXPERIENCE, add the settings rows for Bar, Pie and Text/Status.
- **high** Map Data, which the spine calls "the most important pattern in the product", has detailed behaviour but no visual spec for most of its parts (EXPERIENCE → Map Data; DESIGN has only `slot-row*`, `role-chip`, `confidence-*` and `preview-hotspot`). Missing visual specs:
  - the sticky status bar and its segmented meter;
  - the Roles table's single-value cards and the sample-rows table with chips above its columns;
  - the ✦ suggestion row;
  - the collapsed roles summary line;
  - the record-path candidate list with its "Suggested" and "looks like pagination" badges;
  - the "Aggregate rows by…" control;
  - the Transforms builder's sentence rows, stage labels and row counts;
  - the Comparison editor and the Threshold band rows;
  - the step ② Parameters table and Paste-JSON textarea;
  - the inline fetch-error card with its "▸ Technical details" expander;
  - the hatched missing-slot placeholder;
  - the "Where each part comes from" legend.

  *Fix:* add a "Map Data parts" subsection to DESIGN → Components with tokens for each part. Most can reuse existing tokens, but they must be named so that the component names match across both spines.
- **high** No generic **data table / list page** component, and several form primitives are missing.
  - Every Admin list (Block management, Draft and Published blocks, Data sources, Audit log, User configuration, Version history, Block categories) and My dashboards is a table or list page with search, filters, row menus and status. DESIGN specifies none of it.
  - These primitives are used in EXPERIENCE but have no DESIGN spec: **radio group** (Access), **toggle/switch** (Mandatory, the Live flag), **textarea**, **tooltip**, **skeleton**, **date-range calendar** (two-month), **icon picker grid**, **Expanded view** (full-size Block plus paginated table), and the Edit Layout Undo/Redo control and hint.

  *Fix:* add `data-table` (header, row, hover, selected, row-menu, status cell, empty and loading rows), `radio`, `switch`, `textarea`, `tooltip`, `skeleton`, `date-range-calendar` and `icon-picker` entries. Reuse the existing tokens.
- **high** Charts and Table Blocks have no user-side behaviour or non-visual access spec.
  - DESIGN describes hover tooltips and guides.
  - EXPERIENCE has no Chart or Table component pattern: nothing on keyboard focus on data points, tooltips on focus as well as hover, a screen-reader summary or data-table alternative (WCAG 1.1.1 and 2.1.1), legend interaction, or Table sort and pagination for Users.
  - The Accessibility Floor says only "chart series carry legends or labels".

  *Fix:* add "Chart" and "Table Block" rows to Component Patterns and to the Accessibility Floor:
  - focusable series and points with arrow-key traversal;
  - a tooltip on focus;
  - a "View as table" alternative (it can reuse Expanded view);
  - an `aria-label` summary;
  - sortable headers with `aria-sort`;
  - pagination behaviour.
- **high** Map Data does not fit at common laptop widths. The slot row has fixed columns: 18 + 140 + 168 + 126 + 96 = **548px** plus a `1fr` presentation column (`slot-row.columns`). Next to the fixed `{spacing.preview-pane-width}` 384px, the 232px sidebar and the 28px gutters, the left column at **1280px** is about 584px, which leaves the presentation summary about 36px. Responsive → the Wizard row says only "preview pane narrows" for 1024 to 1279px, with no value, and the token is fixed. *Fix:* commit to one of these, and add a breakpoint row:
  - collapse the sidebar to the icon rail inside the wizard below 1440px;
  - let the slot row wrap the presentation summary under the field below a stated column width;
  - give a narrower `preview-pane-width-compact` token.
- **medium** Responsive behaviour is not committed for 1024 to 1279px.
  - The Shell column reads "Sidebar 232px, **or** icon rail [ASSUMPTION]", which is two options, not one default (EXPERIENCE → Responsive & Platform).
  - With the drawer pushed, content width is the viewport minus 512px (rail 64 + drawer 392 + gutters 56). That is below the 720px narrow-layout threshold for every viewport up to about 1231px, so the dashboard silently becomes single-column (DESIGN → Layout → Narrow layout / Single column). This is not stated.

  *Fix:* pick one shell behaviour, and state what the dashboard does with the drawer open between 1024 and 1231px (single column, or overlay below 1232px).
- **low** Two keyboard semantics are unusual and unexplained (EXPERIENCE → Interaction Primitives, Edit Layout Mode):
  - `Esc` **saves** and exits Edit Layout Mode, while everywhere else Esc dismisses;
  - the scope of `/` is "the current panel", but it does not say whether `/` works only while focus is inside the drawer. If not, it is a single-character shortcut and WCAG 2.1.4 applies.

  *Fix:* state the scope of `/`, and confirm that Esc-saves is intended (Undo exists) or make it cancel.

## 4. State coverage — adequate

The walk of every IA surface is strong for Block states (FR-36 complete, plus Partial data, Unpublished and Access removed), the Dashboard, the Add-blocks Panel, the wizard steps, Data sources and Admin overview. These are missing:

| Surface | Missing state |
|---|---|
| Dashboard | Layout fails to load (not a per-Block error) |
| Wizard (all steps), Data source form, Template editor, Settings forms | Save failure (Save draft or Save rejected or offline); offline while editing (offline is specified only for Dashboard) |
| Wizard | Session expires mid-edit (the idle-timeout value is TBD, but the state still needs a treatment); another Admin edits or publishes the same Block's draft concurrently |
| Wizard · Data Source | No Data Sources registered (empty select; link to "+ Register data source") |
| Add-blocks Panel | List fails to load |
| Notifications | Empty panel |
| Expanded view | Loading, empty and error |
| Search ⌘K | Loading or slow results |

### Findings
- **medium** The save-failure, offline, session-expiry and concurrent-edit states are missing outside the Dashboard (EXPERIENCE → State Patterns). Concurrent draft editing is load-bearing for architecture: it decides between locking and last-write-wins. *Fix:* add rows for Wizard, Data source form and Template editor:
  - save failed, with work kept: "We couldn't save your draft. Your changes are still here. Try again.";
  - offline: a banner, with Save disabled and the reason;
  - session expired: re-authenticate in a dialog without losing the form;
  - draft changed by another Admin: a decision on locking or last-write-wins, with the copy.
- **medium** The generic states for settings and admin screens are claimed but only partly written. The IA note says these screens "are covered by State Patterns", but the only generic rows are "Admin lists · Empty" (whose copy is specific to Draft blocks) and "Any admin page · Permission denied". There is no generic page cold load, form save success or failure, search with no results in a list, or unsaved-changes-on-leave for those screens (EXPERIENCE → State Patterns; IA note under the table). *Fix:* add four generic rows with templated copy, for example "No {items} yet. {Primary action} to start." and "No {items} match '{query}'.".
- **medium** Small surface states are missing:
  - step ② with no Data Sources;
  - the Add-blocks list load error;
  - an empty Notifications panel;
  - Expanded view loading, empty and error;
  - the Dashboard layout load error.

  *Fix:* add one State Patterns row each.

## 5. Visual reference coverage — adequate

Files: `imports/01` to `05` (5 PNGs) and `.working/` (6 HTML files). All 11 are linked inline with what they illustrate:
- the imports are in DESIGN → Brand & Style and in the EXPERIENCE IA table rows;
- `mapdata-hybrid` is in the IA, Map Data, step ⑤ and DESIGN Stepper;
- the `mapdata-A/B/C` sketches are in Map Data and Inspiration;
- `addblocks-hybrid` is in DESIGN Narrow layout and the drawer section;
- `addblocks-variants` is in the drawer section and Inspiration.

There are no orphans. "Spines win on conflict" is stated in EXPERIENCE (intro) and as "this file wins" in DESIGN → Brand & Style; these are consistent.

### Findings
- **medium** The `.working/` links will break when the hybrids are promoted. The decision log (Mock coverage) says `mapdata-hybrid.html` and `addblocks-hybrid.html` will be promoted to `mockups/`, but about 8 inline links point at `.working/` (DESIGN Brand & Style, Narrow layout, Stepper; EXPERIENCE IA, Map Data, step ⑤, Add-blocks Panel, Inspiration). *Fix:* make the link update part of the promotion step, or link to `mockups/` now with a note.
- **low** DESIGN → Components cites mockups by number without links: "mockup 02" (Block Type faces, Top bar), "mockups 02 and 04" (Chart marks), "mockup 03" (Admin overview), "mockups 04 and 05" (Wizard page parts) and "mockup 01" (Remember me). *Fix:* turn each into a link to `imports/0N-….png`.

## 6. Bloat and overspecification — thin

EXPERIENCE.md is 13,746 words, about 9 times the length of the reference examples. Component Patterns alone is about 7,400 words (Map Data about 2,500, the wizard about 1,350, the Add-blocks Panel about 900). Some density is justified, because Map Data is genuinely novel. But about 35 to 40% is repetition or restated source.

### Findings
- **medium** EXPERIENCE.md is too long, and its repetition invites drift. Consumers, including AI story writers, will pull inconsistent strings.
  - **Copy repeated verbatim in several places:**
    - "You don't have permission to publish this block." ×5 (step ⑤ shell, Publish impact, State Patterns, Security, Flow 3);
    - "no longer available" ×7;
    - "Required by your admin" ×5;
    - the version-safe note ×2 in EXPERIENCE plus ×1 in DESIGN;
    - the missing-required-Slot reason in **three different variants**: "Map Chart series to continue. Optional slots can stay empty." (Voice), "1 required slot missing: Chart series" (Map Data → When Continue…), and "Map the required slot Chart series to continue." (Flow 1 step 4).
  - **Restated PRD content:** 137 FR citations. The Data source form re-lists the FR-9 auth types and limits, the Transforms builder re-lists the FR-24 order, the Comparison editor restates FR-27, and Period precedence copies FR-35 verbatim.
  - **Branding & Theming Extensibility** repeats DESIGN → Colors (the brand and system token lists, the guardrail, dark deferral).
  - **Security-conscious UX** re-states rules already given in the patterns (A2 permission, masked secrets, sample labelling, escaped rendering).

  *Fix:*
  - (a) make the Voice table the single source of copy: give each row an ID (for example `V-perm-publish`), cite the ID in patterns, flows and states, and pick one variant of the required-slot reason;
  - (b) replace the restated FR lists with "per FR-n", keeping only UX deltas;
  - (c) reduce Branding & Theming to a 2-line pointer to DESIGN → Colors;
  - (d) turn Security-conscious UX into a 6-row index table (concern → where specified);
  - (e) convert Component Patterns sub-sections into `element | rule` tables where they are lists of rules.

  The target is about 8,000 to 9,000 words with no loss of decisions.
- **low** (pixel specs) EXPERIENCE re-quotes many token values in pixels: "drawer (392px)", "icon rail (64px)", "28px dimmed strip", "Thumbnail (64×46)", "~824px", "Medium 360px". DESIGN prose re-quotes token values next to the token names (`{spacing.sidebar-width}` (232px)). This is harmless while the values agree, but every token change becomes a multi-place edit. *Fix:* in EXPERIENCE, reference the token (`{spacing.drawer-width}`) instead of the number. The DESIGN parentheticals can stay.

## 7. Inheritance discipline — adequate

- **Sources:** all three paths resolve (`../../briefs/…/brief.md`, `../../prds/…/prd.md` v3.2, `../../prds/…/addendum.md`), and the PRD links back to the spines.
- **Glossary:** the spines carry no Glossary and defer to PRD §3 "verbatim". Usage was spot-checked and mostly holds (Block, Block Instance, Slot, Record Path, Add-blocks Panel, Edit Layout Mode, Data-as-of Time, Stale/Unavailable, Mandatory). Exceptions are below.
- **Component names:** consistent between the DESIGN token keys and EXPERIENCE (`drawer-row`, `preview-hotspot`).
- **Token references:** all resolve.

### Findings
- **medium** Some vocabulary is outside the PRD Glossary, or collides with it:
  - **Version history states** "Live, Previous, Draft, Unpublished, Archived" (EXPERIENCE → Version history) are not the Glossary Lifecycle States (Draft, Published, Unpublished, Archived), and "Live" collides with the Refresh Interval "Live (~30 s)";
  - **"system Blocks"** (Add-blocks Panel → T10 group) is undefined in the PRD;
  - **"Block Library"** appears in the publish toast copy, but the PRD Glossary names the panel "Add-blocks Panel" ("Block Library" in v2).

  *Fix:*
  - use "Published (current)" and "Published (earlier)" or similar for versions, and avoid "Live" outside refresh;
  - define "system Block" or drop it;
  - decide whether "Block Library" is a valid Admin-facing term, and align the toast.
- **medium** Copy for the same moment differs between sections. The required-slot reason has three variants (see section 6). The Unavailable face copy differs between Voice ("Unavailable — this value can't be shown right now"), State Patterns ("Unavailable" with ⚠) and DESIGN ("⚠ … the word 'Unavailable'"). *Fix:* make one Voice row canonical, and reference it from State Patterns and DESIGN.
- **low** The size notation is inconsistent. The inline preview meta says "Default size 2×2" (Add-blocks Panel → Inline preview). Flow 2 says "Default size 6×Medium", and the wizard says "8 columns × 360px". "2×2" fits neither the 12-column grid nor the named heights. *Fix:* use "{columns} columns × {Small|Medium|Large}" everywhere.

## 8. Shape fit — adequate

- **DESIGN.md:** the frontmatter has `name`, `description`, `colors`, `typography`, `rounded`, `spacing` and `components`, plus the metadata `status`, `created`, `updated` and `sources`. The body sections are in canonical order: Brand & Style → Colors → Typography → Layout & Spacing → Elevation & Depth → Shapes → Components → Do's and Don'ts. This is **strong**.
- **EXPERIENCE.md:** all required defaults are present (Foundation, IA, Voice and Tone, Component Patterns, State Patterns, Interaction Primitives, Accessibility Floor, Key Flows), and so are Responsive & Platform and Inspiration & Anti-patterns.
- **Invented sections:**
  - **Data Mapping Model** earns its place. The role → Slot table and the confidence definitions are load-bearing for the auto-map implementation, but they belong inside Map Data.
  - **Branding & Theming** does not earn its place (it duplicates DESIGN).
  - **Security-conscious UX** partly earns its place (the regulated-buyer posture), but it is mostly duplicated.

### Findings
- **low** EXPERIENCE section order and frontmatter differ from the reference shape:
  - Data Mapping Model, Branding & Theming and Security-conscious UX sit between Accessibility Floor and Key Flows;
  - Responsive & Platform and Inspiration come *after* Key Flows (the reference order is … Accessibility → Responsive → Inspiration → Key Flows);
  - the frontmatter uses `title` where the reference uses `name`.

  *Fix:*
  - move Data Mapping Model into Map Data;
  - reduce Branding to a pointer and Security to an index (section 6);
  - put Responsive & Platform and Inspiration before Key Flows;
  - rename `title` to `name`.

## Mechanical notes

- **Token resolution:** I parsed the DESIGN frontmatter as YAML and found 93 unique `{section.path}` references across both files; 92 resolve. `{user.email}` is a URL-template token, not a design token (section 2, low). `extends` targets (`input`, `button-ghost`, `button-secondary`, `kpi-card`) all exist. All 80 colour values are 6-digit hex.
- **Components defined in the frontmatter but never named in DESIGN prose:** `drawer`, `button-ghost`, `drawer-thumbnail-mobile`, `badge`, `badge-new`, `badge-draft`, `stepper-step*`, `kpi-headline-in-block` and `preview-hotspot`. They are described in prose under other labels, or only in EXPERIENCE, which is acceptable.
- **Recomputed contrast:**

  | Pair | Ratio | Result |
  |---|---|---|
  | success-text / success-soft | 4.57 | pass, not stated |
  | success-text / success-wash | 4.79 | pass, not stated |
  | accent-ink-strong / accent-soft | 6.38 | pass |
  | role-filter text / fill | 4.52 | pass |
  | role-dimension | 4.86 | pass |
  | error / error-soft | 4.41 | **fail** |
  | text-muted / surface-muted | 4.39 | **fail** |
  | border-strong / white | 1.47 | **fail, non-text** |
  | chart-5 | 2.80 | **fail, non-text** |
  | chart-6 | 2.54 | **fail, non-text** |
  | chart-3 | 3.74 | pass |
  | chart-4 | 4.23 | pass |
  | chart-2 | 5.03 | pass |
  | focus-ring / accent | 3.38 | pass |
  | accent-border / surface-inverse | 13.46 | pass |

- **Word counts:** DESIGN 7,020; EXPERIENCE 13,746. EXPERIENCE by section: Component Patterns about 7,400 (54%), Key Flows about 1,400, Voice about 950, State Patterns about 900, IA about 700.
- **Decision log vs spines:** A1 to A7, B1 to B3, D1 to D4, T1 to T10 and V1 to V6 are all reflected in the spines. The three "Gaps" decisions are reflected (metric windows TBD, generic states, dark contrast unchecked). No decision-log decision is contradicted.
- **Severity totals:** critical 0 · high 7 · medium 12 · low 9.

## Resolution (2026-10-05)

Applied to `DESIGN.md` and `EXPERIENCE.md` using decisions R1–R7 in `.memlog.md`. Finding IDs are `R-{section}.{n}`, numbered in the order the findings appear above. Status is **Resolved**, **Partial** or **Deferred**.

| ID | Sev. | Finding | Status | What changed |
|---|---|---|---|---|
| R-1.1 | medium | Flow headings not verbatim UJ names | Resolved | The Key Flows headings now carry the PRD titles: "Flow 1: Alex (Admin) builds the "Revenue overview" block (UJ-1)", "Flow 2: Jamie (User) personalizes the Overview (UJ-3)", "Flow 3: Alex publishes an update (UJ-2) · A new block version (UJ-6)", "Flow 4: An API outage (UJ-5) · UJ-1 edge case…", "Flow 5: Jamie creates a second dashboard from a template (UJ-4)" |
| R-1.2 | medium | Unavailable copy differs from PRD UJ-1, untagged | Resolved | The `unavailable-user` message is tagged `[UX DECISION — PRD alignment needed]` with the reason; `unavailable-admin` keeps "Unavailable: total missing". The new tag is defined in the EXPERIENCE tag list |
| R-1.3 | low | Flows 3–5 have no protagonist line | Resolved | Protagonist lines added to Flows 3, 4 and 5 |
| R-1.4 | low | Flow 2 details contradict UJ-3 | Resolved | "8 available blocks" everywhere, "Recent activity" throughout, and the size written "6 columns × Medium" |
| R-2.1 | high | Text pairs fail 4.5:1 | Resolved | `button-destructive-soft.foreground` → `error-text` (5.91); new `slot-pill-error` (error-text) and `slot-pill-optional` (text-secondary, 9.37); `stepper-step.numeralForeground` → text-secondary; error-row captions → text-secondary (9.42). Ratios are noted next to the tokens and in Colors |
| R-2.2 | high | Non-text contrast neither stated nor met | Resolved | New `border-control` #80868F (3.67 / 3.45 / 3.51) for every form control; the checkbox gets a border-control edge, and when checked an accent-ink-strong edge (6.85) and an on-accent mark (11.58); `input.placeholder` → text-muted (4.83); chart strokes per R1 and R2 (all ≥ 3.56). A "Non-text contrast (1.4.11)" table is added to DESIGN → Colors |
| R-2.3 | medium | Raw `rgba()` shadows and halo | Resolved | New `shadow-color` token; toast, dialog, overlay sheet and drawer-row shadows are expressed as shadow-color at an opacity; the scrim is set by `overlay-sheet.scrim`; the halo is `{colors.accent}` at 18%, so it follows a Workspace override. Elevation & Depth names the three levels. The Don'ts row now bans `rgba()` |
| R-2.4 | medium | Accent used as text | Resolved, with a deviation | The brief asked for accent-text amber, but #A16207 fails on these dark surfaces (3.60:1 on surface-inverse, 3.48:1 on hero-chip). A new brand token, `accent-ink-inverse` #FDE047 (13.46 / 13.01), is used for toast actions and the sign-in tagline. The override guardrail is extended to it, and the "never text" rule now covers accent and accent-border on any surface |
| R-2.5 | low | `{user.email}` looks like a design token | Resolved | It now appears only inside an explicitly labelled FR-26 URL-template example; the check script treats `user.*` and `period.*` as template tokens |
| R-2.6 | low | Off-scale values | Resolved | Added `rounded.xs` (checkbox) and moved `role-chip` to `rounded.sm`. Added `popover-width-*`, `stepper-numeral` and `stepper-connector` spacing tokens, plus `role-chip-{label,value,dimension,measure,time,filter,none}` entries that bind the role triples |
| R-3.1 | high | No faces for Table, Bar, Pie, Text/Status | Resolved | DESIGN tokens `table-header`, `table-row`, `table-cell-numeric`, `table-sort-indicator`, `table-pagination`, `table-row-highlight`, `chart-bar`, `chart-pie` and `text-status-face`, with prose faces under Block Type faces. EXPERIENCE Block-Type settings rows are added for Line/Area, Bar, Pie/Donut and Text/Status |
| R-3.2 | high | Map Data parts have no visual spec | Resolved | New DESIGN "Map Data parts" with tokens: `mapdata-status-bar`, `status-meter`, `scalar-card`, `roles-table`, `roles-summary-line`, `suggestion-row`, `record-path-list`, `aggregate-control`, `transform-row`, `comparison-editor`, `threshold-band-row`, `parameters-table`, `json-textarea`, `fetch-error-card`, `missing-slot-placeholder` and `source-legend`. EXPERIENCE references the same names |
| R-3.3 | high | No data table; form primitives missing | Resolved | Added `data-table`, `radio`, `switch`, `textarea`, `tooltip`, `skeleton`, `date-range-calendar`, `icon-picker`, `expanded-view` and `edit-layout-toolbar` (Undo, Redo, hint), all reusing existing tokens |
| R-3.4 | high | Charts and Table Blocks have no behaviour or access spec | Resolved | New EXPERIENCE patterns **Chart Block** (`role="img"` summary, always-present View as table, arrow-key points with a tooltip on focus, a legend that names markers) and **Table Block** (`aria-sort`, pagination, keyboard, refresh). Matching Accessibility Floor rules added |
| R-3.5 | high | Map Data does not fit at 1280px | Resolved | Per R7, the wizard uses the icon rail below 1440px (left column about 752px at 1280px), and `slot-row-wrapped` applies below `{spacing.slot-row-wrap-below}` (about 496px at 1024px). Breakpoint rows added to the Responsive table |
| R-3.6 | medium | 1024–1279px shell uncommitted; drawer below ~1232px unstated | Resolved | The shell is committed to the icon rail at 1024–1279px. With the drawer open below about 1232px the dashboard is single-column; the drawer still pushes (DESIGN Layout; EXPERIENCE drawer and Responsive) |
| R-3.7 | low | Esc-saves and `/` scope unexplained | Resolved | Esc now ends (cancels) a move; a second Esc or Done exits and saves. `/` works only while focus is in the drawer, and single-key shortcuts can be turned off (R6) |
| R-4.1 | medium | Save failure, offline, session expiry, concurrent edit missing | Partial | Save failure, offline and session expiry (R5 warning, autosave, restore) are added for the Wizard, the Data source form and the Template editor. **Concurrent draft edit** is added as a state with copy and the never-silently-overwrite rule, but tagged `[OPEN — product decision]`: choosing between an edit lock and last-write-wins or conflict detection is a product and architecture decision |
| R-4.2 | medium | Generic admin and settings states only partly written | Resolved | New "Generic states for admin and settings screens" table (cold load, load failure, empty, no results, save success and failure, validation, unsaved changes, offline, permission denied, row action not permitted) with templated `msg:list-empty` and `msg:list-no-match` |
| R-4.3 | medium | Small surface states missing | Resolved | Rows added for step ② with no Data Sources (`msg:datasource-none`), the Add-blocks list load error, the empty Notifications panel, Expanded view / View as table loading, empty and error, the dashboard layout load error, and Search loading |
| R-5.1 | medium | `.working/` links break on promotion | Resolved | Links to the two hybrids now point to `mockups/mapdata-hybrid.html` and `mockups/addblocks-hybrid.html` (the parent creates the files). The other sketches are labelled "rejected alternatives (.working/)" |
| R-5.2 | low | Mockups cited by number without links | Resolved | Each "mockup 0N" citation in DESIGN body prose now links to `imports/0N-….png` |
| R-6.1 | medium | EXPERIENCE too long and repetitive | Partial | (a) Done: a keyed **Canonical messages** table (about 90 keys) replaces the Voice table, and patterns, states and flows cite `msg:key`; the required-slot reason has one canonical variant. (b)–(e) are **deferred to the polish pass**: the brief said not to condense for length in this pass |
| R-6.2 | low | Pixel values re-quoted in EXPERIENCE | Partial | The drawer, icon-rail, dim-strip, thumbnail and preview-pane mentions now cite tokens; other prose values are left for the polish pass |
| R-7.1 | medium | Vocabulary outside the Glossary | Resolved | Version history uses the Lifecycle States (Draft, Published, Unpublished, Archived) plus a "Current" tag, and no longer uses "Live". "System Blocks" is dropped from the spine, with a note that PRD FR-52 and T10 use it undefined. "Block Library" is kept for Admin copy, because the PRD Glossary entry for **Publish** uses it; this is noted beside the toast |
| R-7.2 | medium | Copy for one moment differs between sections | Resolved | One canonical row each (`required-slot-missing`, `unavailable-user` / `unavailable-admin`), referenced from State Patterns, Flows and DESIGN |
| R-7.3 | low | Size notation inconsistent | Resolved | "{columns} columns × {Small, Medium or Large}" is stated in DESIGN Layout; "2×2" and "6×Medium" are fixed; the preview label reads "8 columns × Medium (360px)" |
| R-8.1 | low | EXPERIENCE order and frontmatter differ | Partial | The frontmatter `title` is renamed to `name`. Moving sections (Data Mapping Model into Map Data, Responsive and Inspiration before Key Flows, trimming Branding and Security) is **deferred to the polish pass**, because it is restructuring |

**Counts:** high 7 of 7 resolved. Medium 10 of 12 resolved and 2 partial (R-4.1 concurrent edit is OPEN; R-6.1 condensing is deferred). Low 7 of 9 resolved and 2 partial (R-6.2, R-8.1, both deferred to polish).

**Checks after the changes:** 135 unique `{token}` references across both files, 0 unresolved (`{user.email}` is skipped as an FR-26 template token). All 78 colour tokens are hex. No `extends` targets are broken. 52 relative links checked: 0 broken, and the 2 `mockups/` targets are pending creation by the parent.
