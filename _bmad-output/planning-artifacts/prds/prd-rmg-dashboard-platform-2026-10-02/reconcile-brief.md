---
title: "Reconciliation: PRD vs Product Brief v3 + brief addendum"
created: 2026-10-02
source_input:
  - ../../briefs/brief-rmg-dashboard-platform-2026-10-02/brief.md (v3)
  - ../../briefs/brief-rmg-dashboard-platform-2026-10-02/addendum.md
targets:
  - prd.md
  - addendum.md (PRD addendum)
method: read-and-compare only; the PRD was not edited
---

# Reconciliation: PRD vs Brief v3

Scope: every CONFIRMED decision, O1–O11, MVP KPI priority set, non-goals, data access, history, approval, Unavailable, devices, brief addendum D (technical posture) and E (risks), plus tone and emphasis. Content the brief marked superseded (v1 assumptions, the closed risks in E) is ignored.

**Overall result.** Coverage is high. Every CONFIRMED decision appears in the PRD, all of O1–O11 are carried in §13, and the nine priority KPIs are all in §4.15. The gaps are of four kinds: (1) a few PRD-introduced rules or defaults that quietly weaken a CONFIRMED rule or lean toward resolving an open item; (2) risk ratings and risks from brief addendum E that were downgraded or dropped; (3) tag drift (CONFIRMED became PROPOSED, or new CONFIRMED tags have no client source); (4) a shift of emphasis from "governed executive window, not general BI" toward "governed BI platform".

Severity: **H** = changes scope or decisions downstream; **M** = could mislead architecture, UX or stories; **L** = editorial.

## A. CONFIRMED decisions and operating model

| # | Sev | Source location | What the source says | PRD status | Suggested fix |
|---|---|---|---|---|---|
| A1 | H | Brief §1 Session 2 bullet 1; §8 "KPI definitions"; §6 bullet 1 | Every KPI carries an owner, **a target**, a calculation method and a direction [CONFIRMED]. Success: 100% of published KPIs have a target. | **Weakened.** FR-16 check allows "a target **or an explicit 'no target'**". This contradicts the CONFIRMED rule, and SM-2 (100% with a target) as well. | Remove the "no target" escape hatch, or log it as a new open item (for example "Can any KPI legitimately have no target?") and keep the CONFIRMED rule as the default. |
| A2 | H | Brief §2, §5, §10; brief addendum B "Off-the-shelf BI" | Positioning [CONFIRMED]: **dashboard-centric, not a general BI product**; "governed executive window onto the RMG application". Embedded BI is "a comparison option only, not an assumed replacement". | **Weakened / mis-tagged.** PRD §1 and §1.1 restate the positioning as "a dashboard-centric platform **with governed BI capabilities** [CONFIRMED positioning]", where "BI is a core part of the platform, not an add-on". PRD addendum J tags this "CONFIRMED by Nahidul". Brief addendum A identifies Nahidul as the author of the input prompt (our side), so this is not a client fact as the CONFIRMED tag defines it. The emphasis moves from executive visibility to a BI platform. | Lead §1 with the brief's framing ("governed executive window; dashboard-centric, not general BI") and present governed BI as the means. Re-tag the new positioning as [PROPOSED] (internal decision by Nahidul, pending client confirmation), or record that the client confirmed it and when. |
| A3 | M | Brief §5 "Build vs buy"; brief addendum C5-26 | Embedded BI is a **comparison option for architecture only**. Whether the client is open to an embedded component (C5-26) was never answered. | **Weakened.** PRD addendum J makes the build-vs-embed-vs-hybrid evaluation mandatory and treats the three options as equals. The "comparison only, not an assumed replacement" qualifier is gone, and client openness to embedding is not tracked as an open item. | Restore the qualifier in addendum J. Add an open item: "Would the client accept an embedded BI engine (licensing, UX, lock-in) if the evaluation favours it?" (C5-26). Make that answer a precondition for the ADR. |
| A4 | M | Brief §3 personas; §1 "administrator-managed datasets and data sources" [CONFIRMED] | KPI author, data/system administrator and executive (consumer) are [CONFIRMED]. Datasets are administrator-managed [CONFIRMED]. Only the dashboard-designer role holder and the approver are [OPEN]. | **Mis-tagged.** PRD §4.1 tags every role as [PROPOSED], including KPI Author and Data Administrator. §4.5 tags "Create or modify Datasets: Data Administrators" as [PROPOSED]. | Split the tags: the persona or capability is [CONFIRMED] where the brief says so (KPI Author, Data Administrator, administrator-managed Datasets, Executive as consumer); the exact role names and the permission matrix are [PROPOSED] / O6. |
| A5 | M | Brief §4.4 | Approval is [CONFIRMED]; the **flow** draft → review/approve → published is [PROPOSED]. | **Mis-tagged (untagged).** PRD §4.8 tags approval [CONFIRMED] correctly, but the six-state lifecycle (adding Unpublished and Archived) has no tag, so it reads as confirmed. | Tag the lifecycle [PROPOSED] and note that only "approval before publishing" is CONFIRMED. |
| A6 | M | Brief §4.1, §8 "Data source" | Preferred access is API or read replica, **if it meets freshness and data needs**; isolated direct DB access if necessary. [CONFIRMED preference; ADR] | **Present but weakened in places.** §4.2 is correct, but drops the condition "if it meets freshness and data needs". UJ-5 and §11.1 say "via API or replica" and omit the confirmed fallback (isolated direct DB access). | Add the conditional clause to §4.2. In §11.1 and UJ-5, write "API or read replica (preferred), or isolated direct access if needed; O11 / [ADR]". |
| A7 | L | Brief §2 [CONFIRMED]; addendum B | The client uses **no BI tool today**. | **Missing.** Not stated anywhere in the PRD. | Add it to the §1 problem framing. It explains why the product is dashboard-centric and why build-vs-embed matters. |
| A8 | L | Brief addendum B "single organization CONFIRMED" | Single-organization client engagement [CONFIRMED]; future multi-tenancy [OPEN]. | **Partly present.** Multi-tenant SaaS is a [PROPOSED] non-goal, but "future multi-tenancy [OPEN]" is not tracked. NFR-4 and §10 tag "Design must not assume one factory or department" as [CONFIRMED], and the brief has no such client statement. | Add future multi-tenancy as an open or future item. Re-tag the "not one factory or department" statement [PROPOSED], or cite its source (the input prompt). |
| A9 | M | Brief §6 bullet 2 | Success metric: KPIs and dashboards delivered **without developer involvement**. | **Weakened.** SM-1 measures "published with **no code change**", which a developer could still achieve through configuration. | Restate SM-1 as "without developer involvement (no code change and no developer action)". |
| A10 | L | Brief §6 bullet 6 | Zero access to **datasets, fields or KPIs** outside a user's scope. | **Weakened.** SM-6 counts only "data shown outside a user's Data Scope". | Extend SM-6 to cover access to Datasets, Fields and KPIs (FR-4) as well as data rows. |

## B. Open items O1–O11 (must stay TBD, not resolved by assumption)

| # | Sev | Source location | What the source says | PRD status | Suggested fix |
|---|---|---|---|---|---|
| B1 | — | Brief §9 | O1–O11, each with its effect on the PRD and an owner. | **Present.** All eleven appear in PRD §13 with owners and the FRs they affect. | None. |
| B2 | H | Brief §9 O7; §3 Client persona; addendum E | Who the "clients" are (internal or external) is **[OPEN]**. Risk mitigation: "Ask early; **design row-level scoping from the start**". | **Leaning toward resolution.** §2.2 lists "External buyers" under **Non-Users (v1)** unless O7 says otherwise, which makes "internal" the default. The §9 risk mitigation keeps "decide O7 early" but drops "design row-level scoping from the start". | Move external buyers out of Non-Users into a "conditional user group (O7)" note. Restore the mitigation: Data Scope and the Query Engine must be able to add a Buyer dimension without redesign (make that an explicit FR-3 / FR-28 consequence). |
| B3 | M | Brief §9 O8; §4.7; §8 "History" | Snapshot grain, retention and backfill are **TBD**. | **Leaning toward resolution.** FR-54 gives a "[PROPOSED default: hourly per Line for Live, daily per Line for Periodic]". It is labelled, but downstream sizing may treat it as the answer. §7 also attributes audit and log retention to O8, and O8 covers only Snapshots. | Keep the proposed default, but add "not to be used for sizing until O8 closes". Give audit and log retention their own TBD and do not file them under O8. |
| B4 | M | Brief §9 O10; §7 Deferred | TV/kiosk, exports **and alerts** belong to O10 (client decides scope). | **Weakened.** §11.2 marks alerts as "Deferred [PROPOSED]" with no O10 reference, while exports and kiosk carry it. | Add "(O10)" to the alerts row. |
| B5 | M | Brief §9 O2 effect | Line efficiency, productivity and manpower KPIs **may launch as "unavailable"**. | **Contradiction risk.** FR-19 adds an untagged rule: a KPI whose prerequisites are entirely unregistered "can be saved as Draft but **cannot be published**". UJ-2 repeats it. Under that rule these KPIs cannot launch in the Unavailable state the brief expects; they cannot launch at all. | Tag the rule [PROPOSED] and reconcile it with O2: either allow publishing a prerequisite-gated KPI that shows Unavailable, or state that those KPIs are absent from launch dashboards and that this meets the brief's intent. |
| B6 | L | Brief §9 O3 | Final **top-10** ranking. | Present. Note that the priority set has 9 items and O3 asks for a top 10. | Add one line in §4.15: the 10th slot is open pending O3. |
| B7 | L | Brief §2 [OPEN]; addendum B "Historical data … reason OPEN"; C2-9; C6-32 | Open: cost of the current gap; **why** history is unavailable; which decisions executives make; deadline and budget. | **Missing.** None of these is in PRD §13 (O28 covers sign-off only). | Add them as open items, or record them as deliberately dropped. Deadline and budget feed addendum J "Time to MVP". |
| B8 | M | Brief addendum C3-19, E "Permission model" | Scoping dimensions (factory, floor, line, department, buyer) must be **fixed early**. The input prompt also names department and business unit. | **Resolved by assumption.** The glossary fixes Data Scope as Organization / Factory / Floor / Line (+Buyer). FR-47 mentions "Department groups", but Department and Business Unit are not Data Scope dimensions, and no open item covers this. | Add an open item ("Confirm the Data Scope dimensions: department? business unit?"), or tag the dimension set [ASSUMPTION] in §14. |

## C. MVP KPI priority set

| # | Sev | Source location | What the source says | PRD status | Suggested fix |
|---|---|---|---|---|---|
| C1 | — | Brief §7 table | Nine priority items [CONFIRMED set; ranking TBD]. | **Present** (§4.15), with research IDs, variants and prerequisites. | None. |
| C2 | M | Brief §7 "Likely refresh" column | Every Live item is "Live, **where the source supports it**". | **Weakened.** The §4.15 column "Refresh Class default" says plain "Live". The condition appears only in §4.10. | Write "Live (if source supports, O1)" in the table, or add a footnote. |
| C3 | L | Brief §7 rows Rejection, Productivity | Rejection: "Daily / **per order**". Productivity: "Output + manpower **(+ machines)**". | **Weakened.** "Per order" and "machines" are dropped. | Restore both. Machines is also a potential prerequisite (O2 / O1). |
| C4 | M | Brief §7 intent; §1 | The priority set is the MVP's KPI content. | **Weakened in emphasis.** The PRD ships them as *templates*, and no success metric or acceptance criterion checks that the priority KPIs are actually published (or explicitly Unavailable) at go-live. | Add an MVP exit criterion: each priority KPI is published with the client's approved definition, or is shown as Unavailable with its named missing prerequisite. |

## D. Non-goals, devices, Unavailable behaviour, history

| # | Sev | Source location | What the source says | PRD status | Suggested fix |
|---|---|---|---|---|---|
| D1 | — | Brief §7 Non-goals | Offline, general BI, raw SQL, missing-data KPIs [CONFIRMED]; user JS/HTML, multi-tenant [PROPOSED]. | **Present** in §10 with matching tags. | None (see A2 for the BI emphasis). |
| D2 | M | Brief §1 "does not fake or estimate"; §7 non-goal | KPIs without source data are not shown; never faked or estimated [CONFIRMED]. | **Present, with one risk.** O22 offers "exclude affected rows with a 'partial' badge" as an option. A partial value is close to the "estimate" the brief forbids. | Frame O22 so that both options must satisfy the CONFIRMED rule: no number that implies full coverage, and the partial badge mandatory. |
| D3 | — | Brief §1, addendum B | Desktop + mobile web [CONFIRMED]; TV/kiosk TBD; offline not required. | **Present** (NFR-12, FR-38, §2.2, O10, O16). | None. |
| D4 | — | Brief §1, §4.7, §8 | Snapshots from go-live [CONFIRMED direction]; storage [ADR]. | **Present** (§4.11, FR-54/55, addendum E). | See B3. |
| D5 | H | Brief standing directive (addendum A) | "Do not invent latency, scale or performance targets; write 'TBD — Client Confirmation Required'." | **Contradicted in one place.** SM-1 proposes "≥ 90% of change requests". Other PROPOSED defaults (FR-51 2 × interval, FR-54 grain) are labelled, but they are still numbers the client has not given. | Remove the ≥ 90% figure (or move it to a "suggested for discussion" note), and use the directive's wording "TBD — Client Confirmation Required" consistently. |

## E. Risks (brief addendum E)

| # | Sev | Source location | What the source says | PRD status | Suggested fix |
|---|---|---|---|---|---|
| E1 | H | Addendum E row 2 | No history: Impact **High**, Likelihood **High** (confirmed). | **Weakened.** PRD §9 rates it Impact **Medium**. | Restore Impact High, or record why it was downgraded. |
| E2 | H | Addendum E row 3 | No KPI catalogue, so **the MVP stalls on definitions**: High/High. | **Missing.** The PRD has "KPI definitions disputed or inconsistent" (High/Medium), which is a different risk. The schedule risk of waiting on O3/O4 answers is gone. | Add the risk "MVP blocked on client KPI ranking and definitions (O3, O4)", High/High. Mitigation: templates plus research catalogue, time-boxed client sign-off. |
| E3 | M | Addendum E row 6 | External clients: mitigation includes "design row-level scoping from the start". | **Weakened.** See B2. | Restore the mitigation. |
| E4 | L | Addendum E row 5 | Production load mitigation: replica **or snapshot/aggregate store**; shared cache; **per-query limits**. | **Weakened.** PRD §9 lists API/replica preference, shared results and load budget. The aggregate store and per-query limits (FR-27) are not cited. | Add FR-27 and the pre-aggregation option (addendum A) to the mitigation. |
| E5 | L | Addendum E row 7 | Permission complexity: "Fix the scoping dimensions early (C3-19)". | **Weakened.** PRD says "early O6 answer". O6 is the role matrix, not the scoping dimensions. | Link it to the new scoping-dimension item from B8. |

## F. Technical posture (brief addendum D)

| # | Sev | Source location | What the source says | PRD status | Suggested fix |
|---|---|---|---|---|---|
| F1 | — | D1, D2, D4, D5 | Semantic layer, JSONB config, rejected options, AST query engine, guards, PGlite closed, grid builder. | **Present** in PRD addendum A, B, F, H. | None. |
| F2 | L | D3 | ~30 s sits in the 5–60 s band. Deciding factors: viewer count, source change frequency, change events. Resolution order KPI > widget > dashboard > system [PROPOSED]. | **Partly present.** Addendum D has the options. FR-50 proposes "KPI default, overridable per Widget" and drops the dashboard level from the brief's proposed order. The deciding factors are not listed. | List the deciding factors in addendum D, and restore the full proposed resolution order as input to O24. |
| F3 | L | D4a | Snapshots affect **the MVP widget set (line charts) and storage sizing**. | **Weakened.** Not stated in addendum E. | Add one line. |
| F4 | L | Addendum A topics | "Widget catalogue **and lifecycle states**"; PostgreSQL HA, indexing, partitioning. | **Partly present.** Widget-level lifecycle states are not addressed (only Dashboard and KPI lifecycles). HA and indexing are left implicit for architecture. | State whether Widgets have their own lifecycle or inherit the Dashboard's. List HA, indexing and partitioning as architecture topics. |
| F5 | M | Brief §8 "Laravel + PostgreSQL" | Client preference [CONFIRMED preference]; that the RMG application uses the same stack is [ASSUMED]. | **Wording risk.** PRD §14 says "the RMG application uses Laravel/PostgreSQL (**confirmed in O11**)", which reads as already confirmed. | Reword: "to be confirmed in O11". |

## G. Qualitative intent (tone and emphasis)

| # | Sev | Source location | What the source says | PRD status | Suggested fix |
|---|---|---|---|---|---|
| G1 | M | Brief §1, §5, §10 | The tone is executive-first: "governed executive window", "every number has an owner, approved definition and history". | **Partly shifted.** The PRD vision keeps the accountability language, but §1.1 makes a BI capability matrix the centrepiece (see A2). | Keep §1.1, but subordinate it to the executive-visibility framing. |
| G2 | L | Brief §1 "Refresh follows the data" | Freshness is honest and bounded by the source. | **Present and strengthened** (Data-as-of, SM-C2 counter-metric). | None. |
| G3 | L | Brief standing directive "every statement tagged" | All statements carry a status tag. | **Weakened.** Many PRD-introduced rules have no tag (FR-19 publish block, FR-37 conflict warning, the lifecycle states, most NFR-7 items). | Tag new behavioural rules [PROPOSED] so that downstream stages can tell them from confirmed facts. |
