# PRD Quality Review — RMG Dynamic Dashboard Platform

*Reviewed: prd.md (816 lines, as of 2026-10-02 16:34) and addendum.md. Rubric: `bmad-prd/assets/prd-validation-checklist.md`.*

## Overall verdict
This is a strong, honest PRD. It has a clear thesis ("a new KPI or dashboard reaches executives without a development ticket, and every number on screen has an accountable definition"), a tight Glossary, and core FRs that are concrete and testable (FR-17, FR-19, FR-26, FR-45, FR-51). Its status tagging is disciplined, so the 27 open items are visible rather than hidden. The risk is not the client TBDs themselves. The risks are four gaps: (a) the PRD never says which open items block which downstream phase; (b) one governance question with no default, O15, decides whether Dashboard approval actually controls what executives see; (c) every performance and scale NFR has no planning value, so architecture has nothing to size against; and (d) the approval flow depends on notifications and a review queue that no FR provides. UX and most of architecture can start now. The build-vs-embed ADR and the KPI-template epics cannot be closed until O1, O2/O5, O6, O7, O9 and O11 are answered.

## Decision-readiness — adequate

Decisions are stated as decisions, and the tagging makes their status clear. CONFIRMED / PROPOSED / OPEN / ADR / TBD are applied consistently. Most OPEN items come with a PROPOSED default that downstream teams can build against: FR-3 "computed only over their scope [PROPOSED]", FR-14 "[PROPOSED: block]", FR-49 "Viewers cannot change the Layout", FR-51 "[PROPOSED default: 2 × Freshness Interval]", FR-54 "hourly per Line for Live KPIs". The non-goals are real decisions with something given up, for example §10 "Not a general-purpose / ad-hoc BI platform" and §1.1's boundary rule. The Open Questions are genuinely open.

Where it falls short:
- The PRD does not tell a decision-maker which open items gate which phase. §13 lists 27 questions with an "Affects" column but no "blocks" column.
- The single `[NOTE FOR PM]` (§11.2, alerts) sits at a comparatively safe tension. Sharper tensions have no callout: O15, the FR-3/FR-28 scope contradiction, and whether the developer-free claim holds when new data must be exposed.
- The addendum describes the custom-build option in detail (§A JSONB storage, §B AST compiler, §E snapshot tables, §G Widget package contract, §D "Laravel broadcasting"). Meanwhile §J says "Do not choose by default" and admits the embedded candidates "have not been researched yet". The scale is tilted before the ADR runs.

**Phase-blocker assessment (not a defect list; the TBDs are deliberate):**

| Downstream phase | Can start now? | True blockers before the phase can *close* |
|---|---|---|
| `bmad-ux` | Yes | **O6** (who designs/approves/publishes, which shapes navigation and approval screens), **O15** (what the approver diff/impact view must show), **O22** (partial-data badge vs Unavailable is a visible state). O10/O14 are scope toggles that UX can design as variants. |
| `bmad-architecture` (incl. §J build-vs-embed) | Yes, for structure | **O11** (access method: replica/API/CDC), **O1** (capture frequency, which decides whether "Live" is real and which refresh mechanism to use), **O9** (scale; the scalability and cost criteria in §J cannot be scored without it), **O7** (external buyers add tenant-level isolation to the data model), **O19** (SSO). O8 matters for snapshot storage but has a PROPOSED default. |
| `bmad-create-epics-and-stories` | Yes, for platform epics (4.1–4.14) | **O2/O5** (prerequisite data for 6 of the 9 templates in §4.15), **O3/O4** (template content and Variants). FR-5 and FR-57 stay conditional on O7 and O8. **O15** blocks the stories for KPI versioning and binding. |
| Client sign-off | — | **O28**: nobody on the client side is named to sign off the PRD itself. |

### Findings
- **[high]** No phase-gating of open items (§13, §0 "carried as TBD and are not resolved here") — 27 open items, each with "Affects" but no statement of which downstream phase or ADR it blocks, or by when it is needed. Downstream workflows will each have to re-derive this. *Fix:* add a "Blocks" column (UX / ADR-§C / ADR-§J / epic X) and a "needed by" date to the §13 tables, using the table above as a starting point.
- **[high]** O15 has no default and undermines approval governance (FR-34 "Changing a KPI's current version affects all Widgets bound to it, according to the binding behaviour (O15)"; UJ-3 Climax) — if bound Widgets follow automatically, approving a KPI change silently changes every Published Dashboard without Dashboard approval. That contradicts §4.8's "Publishing KPIs and Dashboards requires approval [CONFIRMED]". This is the only open question in the governance core with no PROPOSED default. *Fix:* propose a default (e.g. Widgets follow the current version automatically, and the KPI approval's impact list, FR-43, serves as the Dashboard owners' notice) and add a `[NOTE FOR PM]` naming the trade-off.
- **[medium]** Addendum pre-shapes the build-vs-embed ADR (addendum §A, §B, §E, §G, §I vs §J) — sections A–I are written as a custom-build design, while §J asks for an unbiased evaluation. *Fix:* label A/B/E/G explicitly as "Option 1 (custom) posture", or note in §J that they are one candidate's design, not the baseline.
- **[medium]** Only one `[NOTE FOR PM]`, and it is at a safe tension (§11.2 alerts) — the callouts are missing where they would do real work: O15, FR-3 vs FR-28, and SM-1's dependence on data-team work (see Strategic coherence). *Fix:* add callouts there.

## Substance over theater — strong

Very little furniture. The Vision is category-specific: four layers, "about 30 seconds old where the source data allows", and "every number builds history from go-live". It could not be swapped into another dashboard PRD. The eight JTBD map onto the six Roles in §4.1, and each UJ protagonist drives specific FRs; UJ-2's edge case "Prerequisite missing: Attendance" produces FR-19's publish block. There is no innovation theater; §1.1 positions honestly against general BI. The NFRs avoid boilerplate. The only exception is NFR-7, which is a generic security list, though it is anchored to FR-26 and FR-1. The PRD declines to invent numbers ("No number in this section has been invented") rather than padding with fake thresholds.

### Findings
- **[low]** JTBD list carries a role that is never exercised (§2.1 "Client (user group)", §4.1 "Viewer (Executive / Client)") — the Client persona affects nothing until O7 is answered, and no UJ covers it. That is acceptable, but it reads as a placeholder. *Fix:* fold it into the Executive row with "(+ external buyers if O7)".

## Strategic coherence — adequate

The thesis is explicit and the features serve it. Metadata-driven Datasets → no-code KPIs with Variants and owners → approval → viewer transparency (FR-22) all advance "developer-free change with accountable definitions". SM-1 and SM-2 validate the thesis directly. The counter-metrics SM-C1 to SM-C3 are well chosen; SM-C3 "Rubber-stamp approvals defeat governance" is a particularly good one. The MVP is plainly a platform-kind MVP, and its scope logic matches: nine templates as proofs of the engine.

The weakness is that **everything in 4.1–4.15 is MVP with equal weight**. §11.1 says "Features 4.1 to 4.15 as specified". Dashboard templates (FR-41), undo/redo (FR-37), audit export (FR-59), self-service profile (FR-6) and the platform health view (FR-62) carry the same priority as Data Scope enforcement. With "Time to MVP: Delivery plan (TBD)" (addendum §J), the PRD gives epic planning no guidance on what to cut first.

### Findings
- **[medium]** No priority tiers within the MVP (§11.1 "Features 4.1 to 4.15 as specified") — the backlog cannot be sequenced or trimmed against the thesis. *Fix:* tag FRs Must/Should (or MVP-core / MVP-complete). The thesis-critical core looks like FR-1–4, 8–28, 31–34, 36–38, 42–46, 50–54, 63.
- **[medium]** SM-1's developer-free claim has a hidden dependency (§1 "without a development ticket"; FR-12 "from a Data Source entity or a source-side view"; O23) — new KPIs that need new data require a Data Administrator, and possibly a DBA-built source view or raw SQL (O23). SM-1 counts "no code change", but a source-side view is a code change on the RMG side. The thesis holds only for KPIs over already-curated Datasets. *Fix:* state the scope of SM-1 ("KPIs/Dashboards over registered Datasets"), track Dataset onboarding lead time separately, and define how change requests are counted (the denominator).
- **[low]** No metric for time-to-publish (§12) — the thesis is about speed without IT, but no SM measures request-to-publish lead time. *Fix:* add a secondary SM for median request → Published time for KPIs.

## Done-ness clarity — adequate

The thesis-critical FRs are excellent and story-ready:
- FR-17's worked example (70.0% not 75.0%);
- FR-19's "never shows 0" with a rule for partial data;
- FR-26's literal-injection check;
- FR-45's "Draft v6 that equals v3 … v5 stays live";
- FR-38's "no horizontal page scrolling";
- FR-35's package-only acceptance;
- FR-63's "expressed fully through FR-15 to FR-18, with no code change".

But roughly a dozen FRs have no testable consequence, the cost and refresh FRs are entirely TBD, and the performance NFRs have no bounds at all. The NFR situation is a legitimate client dependency, but it leaves architecture with no planning values.

### Findings
- **[high]** Performance and scale NFRs have no planning values (NFR-2, NFR-3, NFR-4, NFR-6; FR-27 "execution timeout: TBD; maximum rows returned: TBD; … TBD") — elsewhere the PRD gives PROPOSED defaults, but here it gives none. Architecture and the §J scalability/cost criteria cannot be scored, and FR-27 is untestable. *Fix:* add PROPOSED planning envelopes clearly marked as assumptions, e.g. "design for ≤ N factories, ≤ M lines, ≤ K concurrent viewers; first render ≤ X s at ≤ 12 Widgets", so the client can correct numbers rather than invent them.
- **[medium]** FRs with no testable consequence — FR-6, FR-10, FR-23, FR-30 (drill: which Widget Types, what happens at Line level), FR-39, FR-40 (no check that a filter applies only to the Widgets selected), FR-41, FR-44, FR-59 (export format), FR-60, FR-61, FR-62 (no lag or error thresholds). *Fix:* add at least one Given/When/Then each. FR-30 and FR-40 matter most because UJ-1 and UJ-4 depend on them.
- **[medium]** Lifecycle state machine incomplete (§4.8 "Draft → In Review → Approved → Published → (Unpublished) → Archived"; FR-42–45) — some transitions are undefined: where a rejected item goes (back to Draft?); whether re-publishing an Unpublished item needs re-approval; what happens when an Approved-but-unpublished item is edited; and what FR-43's "diff against the currently published version" shows for a first submission. *Fix:* add a transition table (from, event, to, who, approval needed).
- **[medium]** FR-3 contradicts FR-28 (FR-3 "unless the Dashboard explicitly declares an unscoped aggregate"; FR-28 "adds the user's Data Scope constraints to every query, whatever the Widget configuration says") — as written, the two cannot both pass. *Fix:* make FR-28 say "except declared unscoped aggregates permitted under O20", or drop the exception until O20 is answered.
- **[medium]** "Production Performance" example breaks the Data Binding model (FR-32 example: "Production, Target (SUM Measures); Efficiency (a KPI)" in one table; Glossary "Data Binding. The link from a Widget to a KPI **or** Dataset") — one Widget that mixes raw Dataset Measures and a KPI needs either multi-binding or a KPI-plus-Dataset join. Neither is specified. *Fix:* either allow a Widget to bind several KPIs/Measures over one Dataset grain (and update the Glossary), or restate the example as three KPIs.
- **[low]** Vague phrasings — FR-13 "fails with a clear message"; FR-27 "rejected or truncated" (which one?); FR-53 "without an indication [PROPOSED]" (what indication?); FR-9 "continuously reports" (how often?). *Fix:* pick one behaviour each.

## Scope honesty — strong

§10 Non-Goals does real work. Each item is tagged, and the ad-hoc-BI boundary includes a reopen rule (addendum §J: "treat it as a scope change (`bmad-correct-course`)"). §11.2 gives each deferral a reason. Conditional FRs (FR-5, FR-57) say exactly what removes or activates them. "No number in this section has been invented" is exactly the right posture. Open-item density is high (27 open questions, about 50 PROPOSED tags, roughly 25 TBD values), but this is a pre-sign-off client PRD feeding design phases, not a green light to build, so the density is appropriate. It becomes a blocker only for the phases listed under Decision-readiness.

### Findings
- **[high]** Approval notifications and the review queue are assumed but not scoped (UJ-2 "Karim is told when it is approved"; UJ-3 "Nadia gets an approval request"; §11.2 defers "Alerts and notifications (KPI thresholds, email, web push)") — no FR gives approvers a pending-review inbox or tells authors about decisions. The approval workflow, a CONFIRMED requirement, does not work without one. The deferral in §11.2 reads as if it covers this too. *Fix:* add an FR for an in-app review queue and status notifications for submit/approve/reject, and state in §11.2 that only threshold or external notifications are deferred.
- **[medium]** PROPOSED items are not indexed (§14 has 5 entries; the body has about 50 `[PROPOSED]` tags, including the entire Role set in §4.1) — the client confirmation pass cannot find our unconfirmed recommendations in one place. *Fix:* add a "Proposed decisions awaiting client confirmation" index, or fold the PROPOSED items into §14.

## Downstream usability — adequate

This is a chain-top PRD, and it is built to be source-extracted. The Glossary is thorough, capitalized terms are used consistently, FR-1 to FR-63 are contiguous, UJ-1 to UJ-6 each carry "Realizes FR-…", the SMs name the FRs they validate, and addendum §J maps each criterion to FR anchors. The deductions come from glossary terms that drift or are missing (see Mechanical notes), from O15 and the lifecycle gaps that story-writers will hit at once, and from the missing review-queue FR.

### Findings
- **[medium]** Sharing and audience model is underspecified (Glossary "Dashboard … an audience"; UJ-4 "published to the 'Executives' audience"; FR-46 "shared with them, through their Role, a user grant or their Data Scope"; FR-47 "Factory/Department groups") — "audience" is never defined. FR-46 implies Data Scope can grant visibility of a Dashboard, which conflicts with FR-47 "Sharing never widens a Viewer's Data Scope". "Department groups" is a grouping that exists nowhere else. *Fix:* define "Audience" in the Glossary as the set of Role, user and group grants, and drop Data Scope as a grant path, or explain it.
- **[low]** UJ-3 to UJ-6 lack the Entry state and Edge case fields that UJ-1 and UJ-2 have. UJ-3 has no edge case, though rejection and diff-on-first-version are the obvious ones. *Fix:* complete them for UX extraction.

## Shape fit — strong

A multi-stakeholder enterprise B2B platform at the top of the chain calls for UJs with named protagonists plus a capability spec. That is what this PRD is. The six UJs cover the six roles without bloat; UJ-6 is correctly tagged `[ASSUMPTION: secondary audience]`. Capabilities stay in the PRD and technology stays in the addendum. The KPI-template table (§4.15) suits a domain-heavy product. The PRD is not over-formalized: operator capabilities (FR-60–62) are correctly written as capability specs with no journeys.

## Mechanical notes
- **ID continuity:** FR-1–FR-63, NFR-1–16, UJ-1–6, SM-1–6 and SM-C1–C3 are contiguous and unique. O13 is absent, and the PRD explains why ("folded into O6"). **O12 is never referenced inline**: FR-43 should cite it.
- **Glossary drift and missing terms:**
  - "Department" (FR-47, NFR-4, §10) is not in the Data Scope hierarchy.
  - "Live Dataset" (FR-62) is wrong: Refresh Class belongs to KPI/Widget, not Dataset.
  - "live interval" / "refresh interval" (UJ-6, FR-51) are used where Freshness Interval is meant.
  - "Group" (UJ-1, Aggregation Rule) is used where "Organization" is meant.
  - "Shift" (FR-40, FR-60) is not in the Glossary.
  - "Viewer", "Approver" and "KPI Author" are Roles in §4.1 but not Glossary entries.
  - "client" means both the customer organization and the O7 user group. Disambiguate this, since O7 turns on it.
- **Assumptions Index roundtrip:**
  - Inline ASSUMPTIONs (UJ-6/§2.1, FR-1, FR-40, FR-60, NFR-16) are all indexed.
  - Index entries "Brief carry-over: the RMG application uses Laravel/PostgreSQL (confirmed in O11)" and "Stakes: enterprise-grade…" have no inline tag. "Confirmed in O11" also reads as if it were already confirmed. Reword it as "to be confirmed in O11".
- **Cross-refs:** these resolve as checked: §1.1 table → FRs, UJ "Realizes" lists, SM → FR, and addendum §J → PRD sections. The addendum §J criteria correctly reference §4.x.
- **Required sections:** all present for the stakes: vision, users, UJs, glossary, FRs, NFRs, integration, governance, stakeholders, risks, non-goals, scope, SMs, open questions and assumptions. §8's sign-off table has empty Status cells for 3 of 4 rows.
