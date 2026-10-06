# PRD Quality Review: Dashflow PRD v3

Reviewed: `prd.md` (v3, 2026-10-05) and `addendum.md` (v3), against the 5 UI mockups (sign-in, User Overview with the Add-blocks panel, Admin overview, Create block wizard top, Create block wizard Display & behavior) and `prd-validation-checklist.md`.
Review date: 2026-10-05.

## Overall verdict

v3 is nearly final. It has a clear thesis ("a new block reaches users without a development ticket"), a well-built Glossary, contiguous FR IDs, testable FRs, and a mapping model (Slots, Slot Mapping, Presentation Rules, a normative example) that is much better than v2's generic mapping. It is not yet safe to finalize, for three reasons:
- **The mapping model cannot produce several things the mockups show.** These are composed and dynamic text, period comparisons that the API does not supply, and a clear date-range precedence.
- **Tenancy and deployment are underspecified.** Nothing says who creates Workspaces or how roles are scoped, and FR-10 would block the private-network APIs that enterprise clients typically expose.
- **"Only four open questions" understates what is open.** About ten TBDs have no owner or phase.

None of these is critical. Each needs a decision or a [PROPOSED] default, not new discovery.

**Counts:** critical 0 · high 8 · medium 16 · low 17.

| Dimension | Verdict |
|---|---|
| 1. Decision-readiness | adequate |
| 2. Substance over theater | strong |
| 3. Strategic coherence | adequate |
| 4. Done-ness clarity | adequate |
| 5. Scope honesty | adequate |
| 6. Downstream usability | adequate |
| 7. Shape fit | strong |
| Mapping model (Map data step + Slot catalogue) | adequate: clear and well illustrated, but incomplete (H1, H2, H3, M3–M7) |

---

## 1. Decision-readiness: adequate

Decisions are tagged as decisions ([CONFIRMED], [MOCKUP], [PROPOSED]), and the real tension between the mockup's "user layouts remain stable" and v2's "admin updates propagate" is resolved explicitly (FR-40). The approval and self-publish tension is correctly left open (Q1). Trade-offs are named honestly: no history in the MVP, so trends must come from the API (§8, NFR-5).

What weakens this dimension is decisions the PRD avoids without saying so:
- the date-range precedence (H3);
- whether a Block may use more than one Endpoint, or a comparison request (H2);
- the deployment model (H5);
- how Workspaces are provisioned (H4).

A reader cannot act on the mapping or tenancy architecture without these.

## 2. Substance over theater: strong

The PRD has three roles, all of which drive FRs, so there is no persona theater. The Vision is specific to this product (API to governed block to personal dashboard). The NFRs avoid boilerplate and honestly say TBD instead of inventing numbers ("No number in this section has been invented"). The normative mapping example (FR-29) and the ratio-safe arithmetic example (FR-26) are earned content.

## 3. Strategic coherence: adequate

The thesis is clear, and the FRs follow it: the data, mapping, governance and personalization FRs all serve "no dev ticket" and "traceable numbers". Counter-metrics exist (SM-C1 to SM-C3). Two things weaken it:
- Every SM target is TBD, so the thesis cannot yet be validated (H6).
- §1.1 lists **drill-down** as a positioning capability, and §10 puts §1.1 in scope, but no FR defines drill-down (H8).

## 4. Done-ness clarity: adequate

Most FRs have verifiable consequences. FR-26, FR-28, FR-29, FR-40 and FR-59 are strong examples. The gaps are:
- **Steps with no FR:** the wizard's ⑤ Preview step has no FR (M2).
- **Unbounded qualifiers:** "a reduced number of columns" (FR-56), "rate-limited" (FR-13, FR-58), "throttled" (FR-2), "fails repeatedly" (FR-28), and an undefined uptime window (FR-64) (M15).
- **Slots without a contract:** each Slot has a name, but nothing gives its accepted types, cardinality or aggregation rule (M3). Story writers will have to invent acceptance criteria for the mapper.

## 5. Scope honesty: adequate

§9 Non-Goals does real work, and deferrals are explicit (snapshots, SSO, email, export/import). The problems are:
- **Open items are under-counted.** §0 and §12 say "only four open questions", but there are about ten untracked TBDs (H6).
- **The Assumptions Index does not roundtrip.** There are no inline `[ASSUMPTION]` tags, and only 5 of the 40+ [PROPOSED] items are indexed (L16).
- **Seed content is unclear.** §4.15 does not say whether the mockup's example Blocks and Templates ship in the MVP (M16).

## 6. Downstream usability: adequate

The Glossary is strong and used consistently in FRs. FR-1 to FR-66 are contiguous and every UJ "Realizes" reference resolves. The weaknesses are:
- **The slot catalogue is a prose table,** not a contract that architecture and stories can extract (M3).
- **The v3-added pages have no place in the mockup navigation:** Data sources, the review queue and the audit log (M9).
- **The addendum has a broken FR reference** (M14).
- **v2 terms remain in the addendum** (L2).

## 7. Shape fit: strong

A multi-stakeholder B2B product with meaningful UX is correctly carried by named-protagonist UJs (Alex, Olivia, Jamie), a feature spec and an addendum for technical posture. The PRD is not over-formalized.

---

## Mockup coverage: elements missed or contradicted

| # | Mockup | Element / behaviour | PRD status | Finding |
|---|---|---|---|---|
| 1 | 2 | Dynamic subtitles: "6 requests need your attention", "3 active projects", "May 20 – 26, 2026" | Missing: Subtitle is static text only (FR-29) | H1 |
| 2 | 2 | Composed text: "Annual leave · 4 days", "Meeting room 2A · 45 min", "12/16 tasks", "Sarah Chen approved Q2 campaign budget" | Missing: no concatenation or text template in FR-25 | H1 |
| 3 | 2 | "↗ 14.2% vs last month" on KPI cards, with a dashboard range of May 1–31 | Only works if the API returns the previous value; no prior-period fetch | H2 |
| 4 | 2 | Growth rate 18.6% "↗ 2.4%": a percentage-point delta, not PCT_CHANGE | Comparison modes undefined | H2 |
| 5 | 2, 4 | Dashboard range "May 1 – May 31", block selector "This year", wizard "Default date range: Current year" | Precedence ambiguous | H3 |
| 6 | 1, 2, 3 | Workspace switcher "Genie Inc. – Enterprise workspace" (workspace *type*) | "type" undefined; provisioning undefined | H4 |
| 7 | 2 | Panel chips KPI / Charts / Tasks / Projects / Calendar vs wizard Category "Finance"; card tags Communication, Reports | Contradiction with UJ-1 and UJ-2 | H7 |
| 8 | 2 | Footer actions "View all approvals →", "View activity log →", "Open projects →" | Destination undefined; no drill-down FR | H8 |
| 9 | 4, 5 | 4-step stepper (Basic info → Configure → Preview → Publish); Basic info and Configure on one scrolling page | PRD restructures into 6 steps | M1 |
| 10 | 4 | Header buttons **Save draft / Preview / Publish block**, available at every step; "Draft" badge next to the title | Preview button and Preview step have no FR; Publish availability at every step unspecified | M1, M2 |
| 11 | 2 | Four header-less KPI cards in one row; panel lists "Revenue Summary (KPI)"; 8 panel blocks vs 9 dashboard tiles | Unclear whether a KPI group Block Type exists | M8 |
| 12 | 3 | Admin nav: Admin overview, Block management, Create block, Draft blocks, Published blocks, Block categories, Dashboard templates, User configuration, System settings. No Data sources, Review queue or Audit | PRD adds these features but does not place them | M9 |
| 13 | 2 | **Profile & settings**, **Help & support**, top-bar settings gear, user "⋯" menu | No FR | M10 |
| 14 | 1 | Role choice before the workspace is known; no visible way to switch area after sign-in | Unspecified | M11, L17 |
| 15 | 2 | "Total expenses ↘ 3.8%" in red | FR-27 colour wording is garbled; Direction semantics unclear | M12 |
| 16 | 4 | Block type dropdown "KPI & Chart"; area fill in the chart | FR-30 tags these [PROPOSED], not [MOCKUP] | M13 |
| 17 | 2 | Line chart with one highlighted point; Y-axis "$0…$40k" compact | Covered (FR-27, FR-30), but the axis format slot is implicit | (in M3) |
| 18 | 2 | Progress bars in per-row colours (yellow, purple, green) that are not threshold-based | "colour rule" only | L10 |
| 19 | 2 | Calendar week strip with today highlighted (22); any navigation not visible | No week-navigation statement | L10 |
| 20 | 4 | Resize handle on the live-preview block | Not stated whether dragging sets the default size | L11 |
| 21 | 4 | Category and Block type not starred | Block type is effectively required | L8 |
| 22 | 2 | "Good morning, Alex" while the signed-in user is "Jamie Davis, Workspace member" | Greeting rule and the "Workspace member" label are not defined | L4 |
| 23 | 3 | "Published by Olivia Martin": the author or the approver? | Ambiguous | L7 |
| 24 | 3 | Platform health shows only platform services, with uptime % | FR-64 conflates services with Data Sources; no uptime window | L15 |
| 25 | 1 | Marketing panel copy ("One workspace. Every insight.", "Enterprise-grade security", "Live data updates"), footer | Not required; leave to UX | L9 |
| 26 | 2 | Layout (4 × 3 columns, 8 + 4, 4 + 4 + 4) | Confirms the 12-column grid; PRD still tags it [PROPOSED] | L5 |

Covered well: sign-in fields, Remember me, Forgot password, show/hide password, help link (FR-1, FR-2); breadcrumb, greeting, dashboard switcher, Date Range, Edit layout (§4.10); the Add-blocks panel in full, including search, chips, Filter, count, Added / + Add, the drag footer hint and Done (FR-50, FR-51); block chrome refresh / minimize / ⋯ (FR-32); every Display & behavior control (FR-17); the live preview Desktop/Tablet/Mobile toggles and size label (§4.4); the Version-safe publishing note (§4.8); every Admin overview tile and panel (FR-63, FR-64).

## Internal inconsistencies and leftover v2 terms

- **Broken reference:** addendum §B "Ratio Metrics … (PRD FR-17)". FR-17 is Display & behavior; the reference should be FR-26 (M14).
- **v2 leftovers in live text:**
  - FR-53 "[PROPOSED; v2 O38 resolved]" (L1);
  - addendum §C "makes **Data Scope** enforceable" (L2);
  - addendum §F heading "**Designer** and **End-User Dashboard** UI" (L2);
  - addendum §A "User Dashboard" vs the PRD's "Dashboard" (L2).

  No live use of Data View, Personal Layout, Snapshot or Domain Pack outside the §14 change log and the frontmatter inputs, where they are historical and acceptable.
- **UJ-1 vs UJ-2:** UJ-1 sets Category Finance, but UJ-2 says the block appears "under KPI and Charts" (H7).
- **Role model:** the Glossary says "Role. **User or Admin**" (single-valued), but §2.1 says a person may hold "both roles" (M11).
- **Tags:** FR-30 tags KPI & Chart and area as [PROPOSED], but both are in the mockups (M13).
- **NFR-2:** it attributes performance targets to Q3, but Q3 covers scale only (H6).
- **FR-64:** "of each **Data Source**: Dashboard API, Data refresh service…" lists services, not Data Sources (L15).
- **§0 and §12:** they say "only four open questions", while ten or more TBDs are untracked (H6).
- **ID integrity:** FR-1 to FR-66 are contiguous; NFR-1 to NFR-13 are contiguous; SM-1 to SM-6 and SM-C1 to SM-C3 are fine; every UJ "Realizes" range resolves; Q1 to Q4 references resolve.

## Open questions Q1–Q4: necessary and sufficient?

- **Q1 (approver model, self-publish): necessary.** It is a product-owner policy decision. A default is already proposed (FR-20), so it gates `bmad-ux` only lightly. Keep it.
- **Q2 (API inventory): necessary for connector sizing and freshness.** It does not block UX or epics, since the mapper is generic. Keep it, and mark it as not blocking `bmad-ux`.
- **Q3 (scale): necessary.** Widen it to include the performance targets that NFR-2 already attributes to it.
- **Q4 (compliance and retention): necessary.** Keep it.

**Not sufficient.** One question is missing and must be added:
- **Q5, deployment and tenancy model.** Is Dashflow a multi-tenant SaaS, self-hosted per client, or a hybrid with a connector agent? Who provisions Workspaces? Can source APIs sit on private networks? This blocks `bmad-architecture` (FR-10, NFR-4, NFR-5, hosting) and the identity and tenancy epics (H4, H5). §14's resolution of "Deployment model (O33) → Workspaces" answers tenancy, not deployment.

These should be **decided as [PROPOSED] defaults rather than opened as questions**, because they are design choices the delivery team can make:
- date precedence (H3);
- one Endpoint per Block plus a prior-period comparison request (H2);
- text templates (H1);
- chips taxonomy (H7);
- drill-down in or out (H8);
- wizard step structure (M1);
- KPI group type (M8).

The untracked TBDs (H6) need a register with owner and phase, not more Qs.

---

## Findings

### Critical

None.

### High

- **H1. The mapping model cannot express composed or dynamic text that the mockups show** (FR-25, FR-29, FR-30 common Slots; Glossary "Slot Mapping").
  - **Problem:** Subtitle and text Slots accept a Field, a Calculated Field or *static* text only. FR-25 has no string concatenation or text formatting, and single-value Slots cannot take a row count. That means the mapper cannot produce any of these mockup strings:
    - "6 requests need your attention" or "3 active projects" (count of rows);
    - "Annual leave · 4 days" or "Meeting room 2A · 45 min" (two Fields joined);
    - "12/16 tasks";
    - "May 20 – 26, 2026" (the active period);
    - and the preview subtitle "Financial performance · Current year" goes wrong as soon as the Block Period Selector changes the period.
  - **Fix:**
    - Add a **Text Template** value type for any text Slot, with interpolation such as `{count} requests need your attention` or `{location} · {duration|min}`, a `CONCAT` function and per-token formatting.
    - Expose `ROW_COUNT`, the active period label and the active period range as built-in tokens.
    - Make Title and Subtitle mappable (static or template), and update FR-29 to use a template for the subtitle.

- **H2. Comparison values and the single-Endpoint limit are not decided** (Glossary "Block" holds "an Endpoint"; FR-27; FR-29; §8 risk "No platform history"; NFR-5).
  - **Problem:**
    - Every comparison in the mockups ("vs last month", "from last year") works only if the API returns the previous value in the same response. FR-29 assumes `previous_total` exists.
    - Nothing lets a Block re-call its Endpoint with the prior period's parameters, the usual way to compare when there is no stored history.
    - Nothing allows a second Endpoint, which "Revenue vs Expense" may need.
    - Comparison modes are undefined. Growth rate "↗ 2.4%" is a percentage-point delta, not PCT_CHANGE.
  - **Fix:**
    - State "one primary Endpoint per Block" as a decision.
    - Add an optional **Comparison request**: the same Endpoint with the Date Range shifted to the previous period, previous year or a custom offset, mapped to the Comparison Slot.
    - Define comparison modes: % change, absolute delta and percentage-point delta.
    - Either allow a secondary Endpoint for multi-series Blocks, or put it in §9 as [NON-GOAL for MVP] with "combine in the API" as the workaround.

- **H3. Date-range precedence is ambiguous** (FR-15 "Default date range", FR-33, FR-49, FR-17; mockups 2 and 4).
  - **Problem:**
    - A Block has an admin *Default date range*, the dashboard has a *Date Range*, and a Block may have a *Period Selector*.
    - FR-33 and FR-49 say a Block follows the dashboard Date Range unless its Period Selector is set. Then the FR-15 default has no stated role.
    - It is also unstated what happens to Blocks with no date-bound parameter, such as Pending approvals, and to the Period Selector's options and default.
    - FR-17 has no control to enable the Period Selector.
  - **Fix:**
    - Add a Display & behavior setting, **Period: Follow dashboard | Own period selector (options, default = FR-15 default) | Not date-filtered**.
    - Define the precedence: user's Period Selector choice > dashboard Date Range (for "Follow") > Block default.
    - Show a "not date-filtered" hint for undated Blocks.
    - Make the active period available as a subtitle token (H1).

- **H4. Workspace provisioning, the Workspace "type" and role scoping are undefined** (FR-3, FR-6, FR-64, Glossary "Workspace" and "Role"; mockup "Enterprise workspace").
  - **Problem:**
    - No FR or role creates a Workspace, sets its first Admin, or sets its initial host allowlist.
    - The "type" shown in the switcher is not defined.
    - It is not stated that roles and the approval permission are per Workspace membership.
    - It is not stated whether FR-64 Platform health is platform-wide, which a Workspace Admin in a multi-tenant system should not see, or scoped to the Workspace.
  - **Fix:**
    - Add [PROPOSED] text saying Workspaces are provisioned by a platform operator (an out-of-band operation or a minimal operator console), together with their first Admin.
    - Say that roles, groups and permissions are per Workspace membership.
    - Define Workspace type (for example a plan or tier) or drop it.
    - Scope FR-64 explicitly.
    - Tie all of this to the new Q5.

- **H5. The deployment model is unknown, and FR-10 would block typical enterprise APIs** (FR-10, NFR-4, §6, §14 "Deployment model (O33) → Workspaces").
  - **Problem:** FR-10 always blocks private and loopback addresses. But the PRD's own example, "Finance data warehouse", and most internal RMG, Healthcare and FinTech APIs live on private networks. Whether that is reachable depends on SaaS vs self-hosted vs a connector agent, and no section or Q decides it.
  - **Fix:**
    - Add **Q5 (deployment and tenancy model)**, blocking `bmad-architecture`.
    - Amend FR-10: private ranges are allowed only when a privileged role explicitly allowlists them per Workspace, and the request is audited; loopback, link-local and cloud-metadata addresses are always blocked.
    - Note a connector-agent option in addendum §C.

- **H6. Untracked TBDs contradict "only four open questions"** (§0, §12, FR-2, FR-4, FR-63, NFR-1, NFR-2, NFR-6, SM-1 to SM-3, Glossary "Grid" heights).
  - **Problem:** these values are TBD with no owner or phase:
    - the Remember-me maximum and the idle timeout;
    - the Admin overview metric definitions ("TBD in UX");
    - the end-to-end freshness target;
    - the performance targets (attributed to Q3, which covers scale only);
    - uptime and RPO/RTO;
    - all three primary SM targets;
    - the Small and Large height values.
  - **Fix:**
    - Either set [PROPOSED] defaults (for example a 30 min idle timeout, a 30-day remember-me maximum, a p95 first render of 3 s at 12 Blocks, Small = 180 px, Large = 540 px),
    - or add a **TBD register** (item, owner, phase that sets it).
    - Widen Q3 to cover the performance targets.
    - Change the §0 and §12 wording to "four open questions plus N tracked TBDs".

- **H7. The category and filter-chip taxonomy contradicts itself** (UJ-1, UJ-2, FR-41, FR-50, Glossary "Block Category"; mockups 2 and 4).
  - **Problem:**
    - UJ-1 gives the block Category Finance, but UJ-2 says it then appears "under KPI and Charts".
    - The mockup's chips (KPI, Charts, Tasks, Projects, Calendar) look like type or function groups, while the wizard's Category is a business area (Finance), and card tags show Communication and Reports.
    - FR-41 says "one primary category + tags", but does not say whether chips filter by category, by tag, or by both.
  - **Fix:**
    - Decide that chips = Block Categories (admin-ordered, FR-41), the card tag = the primary Category, and additional tags are searchable but are not chips.
    - Change UJ-2 to "under Finance", or change UJ-1's category to match the chips.
    - Alternatively, make chips a union of Category and Block Type groups. Either way, state the rule explicitly.

- **H8. Drill-down is in scope but has no FR, and footer-action destinations are undefined** (§1.1 "drill-down", §10 "governed BI capabilities (§1.1)", FR-32, FR-35; mockup footers "View all approvals →", "View activity log →", "Open projects →").
  - **Problem:**
    - §10 puts drill-down in scope, but no FR defines it.
    - Footer actions point to an undefined "link or 'View all' target". There is no in-app detail view, and no URL template that can carry Field values, for example a link to the source system.
    - There is no row click-through for list rows.
  - **Fix:**
    - Either remove drill-down from §1.1 and add it to §9 as [NON-GOAL for MVP],
    - or add an FR: footer action and row action = an external http(s) URL template with Field tokens, or an in-app "expanded block" view (full list or table, paginated).
    - Define what "View all" opens.

### Medium

- **M1. The wizard step structure diverges from the confirmed mockup** (§4.4).
  - **Problem:** The mockup stepper has 4 steps, and Basic info, Data configuration and Display & behavior sit on one scrolling page. The PRD's 6 steps split Data and Display unnecessarily. The PRD also does not say whether the header buttons Save draft / Preview / Publish are always available, or what happens if Publish is pressed before the form is valid.
  - **Fix:** Keep the mockup's stepper as Basic info → Configure (sections Data, **Map data**, Display & behavior) → Preview → Publish. Alternatively, tag the 6-step version as an explicit [PROPOSED] deviation. Either way, state that Publish is disabled until validation passes and shows the missing items.
- **M2. The Preview step (⑤) and the header "Preview" button have no FR** (§4.4).
  - **Fix:** Add an FR covering the full-size preview at each device size and grid size, state toggles (loading, empty, error, Stale, Unavailable), "preview as user or group" for user-context bindings, and the exit criteria.
- **M3. The slot catalogue is not a contract** (FR-22, FR-30; addendum §G).
  - **Problem:** FR-30 lists Slot names, but not each Slot's accepted type, cardinality, whether it is a scalar or row-level Slot, its default presentation, or whether a single-value Slot can take an aggregate over the Record Path, for example Headline = SUM(monthly[].revenue) when the API has no total. Axis-level settings (Y-axis format and range) are implicit.
  - **Fix:** Expand FR-30 into one table per Block Type with these columns: Slot, Accepts (Measure / Dimension / date / text / template), Scalar or Row, Cardinality, Required, Default Presentation. Allow scalar Slots to take an aggregate of a row Field.
- **M4. The Transform order and the ratio declaration are incomplete** (FR-24, FR-26; Glossary).
  - **Problem:** The fixed order filter → group → aggregate → calculated means a row-level calculated field (for example margin per row) cannot exist before grouping. FR-26 does not say how a ratio is declared. "Ratio Metric" appears only in the addendum.
  - **Fix:** Allow two calculated-field stages: row-level before grouping, and post-aggregate. Add a Glossary term "Ratio Metric" (a numerator Field and a denominator Field, aggregated separately) and reference it in FR-26.
- **M5. Changing the Block type after mapping is undefined** (FR-14, FR-16).
  - **Fix:** Keep Slot mappings whose name and type are compatible, flag the rest as unmapped, and ask the Admin to confirm before applying the change.
- **M6. Sample fetch with user-context bindings is undefined** (FR-21, FR-8, FR-18).
  - **Fix:** Add a "Sample as" control with test user or attribute values. Say whether Sample Responses are persisted, and for how long (this links to NFR-5 and Q4).
- **M7. Multi-series charts are under-specified** (FR-30 line / bar).
  - **Problem:** Line / Area has no series key for long-format data, and nothing covers series that come from sibling arrays, for example revenue[] and expense[].
  - **Fix:** Add a series key (split by Dimension) to Line / Area. State that each series must come from the single Record Path, or allow one Record Path per series.
- **M8. KPI row vs KPI card is ambiguous** (FR-30, FR-50; mockup 2).
  - **Problem:** The four KPI cards have no header and form one row. The panel lists "Revenue Summary (KPI)" with the description "Financial overview for the selected period", and shows 8 blocks while the dashboard has 9 tiles.
  - **Fix:** Decide whether a **KPI group** Block Type (n KPI cards in one Block) exists. If it does not, state that each KPI card is a separate Block with its header hidden (FR-17).
- **M9. Admin navigation for the v3-added features is not specified** (§4.3, FR-37, FR-66; mockup 3).
  - **Problem:** The mockup's admin navigation has no Data sources, Review queue or Audit log, and no Admin layout description equivalent to §4.10's.
  - **Fix:** Add an Admin layout paragraph that lists the mockup navigation plus these [PROPOSED] items: Data sources, Review queue (with a badge count) and Audit log, the last under System settings or as its own item.
- **M10. Profile & settings, Help & support and the top-bar settings gear have no FR** (§4.10 layout).
  - **Fix:** Add an FR for the profile: name, avatar, password change, theme, and a personal locale and time zone override. Make the Help link a URL configured in System settings (FR-65). Define what the gear opens, or remove it.
- **M11. The role model and area switching are inconsistent** (Glossary "Role", §2.1, FR-1, FR-6).
  - **Problem:** The Glossary defines Role as single-valued, but §2.1 says a person can hold both roles. Nothing says how a dual-role person moves between areas after sign-in.
  - **Fix:** State that Admin includes User access, add a "Switch to Admin / User area" item to the profile menu with no re-authentication [PROPOSED], and make Role a per-Workspace membership attribute.
- **M12. The FR-27 colour rule is garbled** (FR-27 "a positive change is green ↗ or red ↘ according to Direction").
  - **Fix:** Rewrite it as: "The arrow follows the sign of the change. The colour follows Direction: good = green, bad = red, no Direction = neutral." Add the expenses example: ↘ 3.8% shows green when lower is better. That would also flag the mockup's red ↘ on Total expenses as admin-configured.
- **M13. The FR-30 status tags are wrong.** KPI & Chart (in the wizard's Block type dropdown) and area (the filled chart) are both visible in the mockups.
  - **Fix:** Retag both as [MOCKUP]. Only text/status remains [PROPOSED].
- **M14. Broken reference in addendum §B:** "Ratio Metrics … (PRD FR-17)".
  - **Fix:** Change it to FR-26.
- **M15. Several qualifiers are unbounded** (FR-2 "throttled", FR-13 "rate-limited", FR-28 "fails repeatedly", FR-56 "reduced number of columns", FR-58 "manual refresh is rate-limited", FR-64 uptime window).
  - **Fix:** Give [PROPOSED] values or name them as configurable settings with defaults. Examples: 5 failed sign-ins → 15 min lockout; tablet = 6 columns below 1024 px, mobile = 1 column below 640 px; 3 consecutive mapping failures → notify; uptime measured over 30 days.
- **M16. Example and seed content in the MVP is unclear** (§4.15, §10, addendum §K).
  - **Fix:** State whether the MVP ships the mockup's demo Blocks and Templates (finance, tasks, calendar) with a demo or mock Data Source for acceptance and sales, and who builds the RMG example content and when.

### Low

- **L1.** FR-53 "v2 O38 resolved" is a leftover O-number in a live FR. *Fix:* delete it; §14 already records the resolution.
- **L2.** The addendum keeps v2 terms: §C "Data Scope", the §F heading "Designer and End-User Dashboard UI", and §A "User Dashboard". *Fix:* change them to "user-context binding", "Admin and User area UI" and "Dashboard".
- **L3.** The Glossary is missing terms that are used: User Group (FR-6, FR-7, FR-8), Workspace type (FR-3), Approval permission, and Ratio Metric (M4). *Fix:* add entries for them.
- **L4.** The greeting rule ("Good morning, \<first name\>", based on local time) and the role label "Workspace member" vs Role "User" are undefined. The mockup greets "Alex" while the signed-in user is Jamie. *Fix:* define both in §4.10.
- **L5.** The Grid is tagged [PROPOSED], but the mockup layout (4 × 3 columns, 8 + 4, 4 + 4 + 4) confirms 12 columns. *Fix:* retag it [MOCKUP]. Set the Small and Large heights (H6).
- **L6.** The currency code can only be static. FR-29's response carries `"currency": "USD"`. *Fix:* allow a currency or unit format to be bound to a Field.
- **L7.** "Published by X" in Recent block activity could mean the author or the approver. *Fix:* specify it in FR-63, for example "Published by \<approver\>", with the author on hover.
- **L8.** FR-14 does not mark Block type as required, although every Slot depends on it. *Fix:* mark it required, and make Category required if chips depend on it (H7).
- **L9.** The sign-in marketing panel copy is not covered. *Fix:* leave it to UX, but note that the "Live data updates" claim depends on the Live interval (FR-58).
- **L10.** The calendar week strip has no navigation rule (fixed to the current week or navigable), and the progress list uses per-row colours that are not threshold-based. *Fix:* state the week behaviour, and allow a colour per row from a category Field or a palette.
- **L11.** The live-preview resize handle (mockup 4) has no defined behaviour. *Fix:* state whether dragging it sets the default width and height.
- **L12.** The FR-62 notification "new Blocks available" to every user may be noisy. *Fix:* limit it to Blocks the user can access, with an opt-out.
- **L13.** FR-28's "Unavailable: \<field\> missing" exposes internal field paths to Users. *Fix:* show Users "Unavailable", and show the field to Admins and in "About this block".
- **L14.** FR-60's "oldest Data-as-of Time of the Live Blocks" is ambiguous: Blocks on the Live interval, or all visible Blocks? *Fix:* clarify which.
- **L15.** FR-64 lists platform services under "each Data Source", and the mockup shows services only. *Fix:* separate the two. Data Source health stays in FR-12; Platform health lists the services, with their uptime window.
- **L16.** The Assumptions Index does not roundtrip: there are no inline `[ASSUMPTION]` tags, and 5 entries stand for 40+ [PROPOSED] items. *Fix:* rename it "Key [PROPOSED] decisions to confirm", or index every [PROPOSED] item by FR.
- **L17.** The workspace a multi-workspace user lands in after sign-in is not stated. *Fix:* use the last-used Workspace (or the default one), with the switcher available immediately.
