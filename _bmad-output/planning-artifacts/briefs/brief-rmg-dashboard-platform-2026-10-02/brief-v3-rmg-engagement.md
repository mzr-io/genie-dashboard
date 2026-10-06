---
title: "Product Brief: RMG Dynamic Dashboard Platform"
status: prd-input-v3
stage: validated-discovery-baseline
created: 2026-10-02
updated: 2026-10-02
reviewed: structure+prose 2026-10-02 (v1)
changelog:
  - "v2: client discovery round 1 (data source, users, purpose, freshness, offline, devices, meaning of 'dynamic')"
  - "v3: client Session 2 (KPI priorities, definitions policy, refresh policy, SMV/attendance dependency, governance, data access, history)"
related:
  - addendum.md
  - ../../research/domain-rmg-production-and-executive-kpis-2026-10-02/research.md
  - ../../discovery/client-session-2-kit.md
---

# Product Brief: RMG Dynamic Dashboard Platform

> **Status: validated discovery baseline, v3. This is the input to the PRD.** It includes the client's answers from rounds 1 and 2. What is still open is listed in §9 with its effect on the PRD. The PRD should carry those items as **TBD** and must not resolve them by assumption.
>
> Tags:
> - **[CONFIRMED]** client/business fact
> - **[ASSUMED]** working assumption
> - **[PROPOSED]** our recommendation
> - **[OPEN]** needs a client answer
> - **[ADR]** architectural decision still pending
> - **TBD** no value yet

## 1. Executive summary

An RMG (ready-made garments) organization wants a **dynamic, dashboard-centric platform**. It gives **executives, CXOs and clients** a management-level view of operations **[CONFIRMED]**. The data comes from the organization's **existing RMG application**: production data from its database today, and more modules later **[CONFIRMED]**.

"Dynamic" means all of the following **[CONFIRMED]**:
- layout customization;
- widget configuration;
- **business users creating KPIs and calculated metrics**;
- administrator-managed datasets and data sources.

All of it is governed through metadata and configuration. It must not need application code, or a new physical table, for each dashboard, component or KPI.

**Session 2 settled the operating model [CONFIRMED]:**
- **KPIs use the client's own approved definitions.** Where industry variants exist, the client chooses one. Every KPI carries an owner, a target, a calculation method and a direction (higher or lower is better).
- **Refresh follows the data.** Operational KPIs refresh live, near the ~30-second target, *where the source data supports it*. Executive KPIs refresh daily or per period.
- **KPIs without their source data stay unavailable.** Efficiency and productivity KPIs need SMV/SAM and attendance data; until that data exists, those KPIs are not shown. The platform does not fake or estimate them.
- **Publishing needs approval.** Normal business users get no raw SQL.
- **Production load is protected.** API or read-replica access is preferred over direct access to the production database.
- **Executives need history.** The platform should start **capturing snapshots from go-live**, because no historical data exists.

Runs on desktop and mobile web; offline operation is not required **[CONFIRMED]**.

## 2. The problem

- **[CONFIRMED]** Executives and clients lack a configurable, management-level view of RMG operations. Every new dashboard or metric needs development work.
- **[CONFIRMED]** There is no KPI catalogue and no history. The organization needs KPIs defined, governed and tracked over time, not just displayed.
- **[CONFIRMED]** The client uses no BI tool. The platform is dashboard-centric, not a general BI product.
- **[OPEN]** What the current gap costs: delayed decisions and manual report preparation.

## 3. Who this serves

| Persona | Status | Role in the platform |
|---|---|---|
| **Executive / CXO** | [CONFIRMED] primary | **Consumer** of dashboards: summary KPIs, daily/period plus a "factory today" view |
| **Client** | [CONFIRMED] user · **[OPEN] internal management or external buyers?** | Consumer. If external, external access and data isolation are needed |
| **Authorized business user (KPI author)** | [CONFIRMED] | Creates and configures KPIs within governance; no raw SQL |
| **Dashboard designer** | [CONFIRMED] capability · role holder [OPEN] | Arranges widgets and layouts; submits for approval |
| **Approver** | [CONFIRMED] approval required · who approves [OPEN] | Approves KPI and dashboard publishing |
| **Data / system administrator** | [CONFIRMED] | Registers data sources, curates datasets, manages access |
| Factory / line manager, shop-floor display | [ASSUMED, secondary] · TV/kiosk **TBD** | Operational consumers of live KPIs |

## 4. The solution *[PROPOSED]*

1. **Data sources.** The RMG application comes first, through API or read replica (preferred), or isolated direct database access if needed **[CONFIRMED preference; ADR]**.
2. **Datasets and fields.** Curated by administrators.
3. **KPI definitions.** Versioned metadata authored by authorized business users. Each one holds:
   - the formula (built from governed fields, no-code);
   - the chosen industry variant;
   - owner, target, direction;
   - unit;
   - **aggregation rule** (sum numerators and denominators; never average percentages, see research §7);
   - **refresh class** (live or daily/period);
   - **data prerequisites** (for example SMV, attendance). A KPI shows as **unavailable** while its prerequisites are missing **[CONFIRMED behaviour]**.
4. **Approval workflow.** KPI and dashboard changes go draft → review/approve → published **[CONFIRMED approval; flow PROPOSED]**.
5. **Query engine.** Structured, validated, parameterized queries; no raw SQL for business users **[CONFIRMED]**.
6. **Widgets and dashboards.** Grid layout, filters, versions, permissions and audit.
7. **Snapshot store.** The platform records KPI values over time from go-live, so trends exist **[CONFIRMED direction]**. Grain and retention are **TBD**.
8. **Freshness indicators.** Every widget shows when its *data* was last updated, and flags stale data.

## 5. What makes this different

- **[CONFIRMED]** Dashboard-centric executive visibility, not general BI.
- **[CONFIRMED]** Built on the client's own RMG application data, and grows with each new module.
- **[CONFIRMED]** **Governed, client-defined KPIs.** Business users author them under the client's approved definitions, with owners, targets and approval, so every executive number has an accountable definition.
- **[CONFIRMED]** History accumulates from go-live, so trends are built in.
- **Build vs buy:** embedded BI remains a comparison option for architecture only, not an assumed replacement.

## 6. Success criteria *[PROPOSED metrics · targets TBD]*

- Every published KPI has an approved definition, owner, target and direction: 100% (by design)
- Share of new or changed KPIs and dashboards delivered **without developer involvement**: TBD
- **Live KPIs:** share of time each widget reflects source changes within its configured interval. Target about 30 s **[CONFIRMED]**, bounded by the source's capture frequency. Compliance % TBD
- Executive adoption: weekly active executives and CXOs: TBD
- Trend availability: KPI snapshots captured continuously from go-live (grain TBD)
- Zero access to datasets, fields or KPIs outside a user's scope

## 7. Scope

### MVP KPI candidates [CONFIRMED priority set · ranking TBD by management]

| Client priority | Research ID | Likely refresh | Data dependency | Note |
|---|---|---|---|---|
| Production achievement / actual vs target production | P4 | Live, where the source supports it | Output + targets | **The client listed these as two items; the research treats them as one KPI. [OPEN] confirm** |
| Line efficiency | P2 | Live, where the source supports it | Output + **SMV + attendance** | Unavailable until SMV and attendance exist |
| Hourly production | P15 | Live, where the source supports it | Output by hour | |
| WIP | W3 | Live, where the source supports it | Line **input** and output | Needs input loading recorded |
| Downtime | P11 | Live, where the source supports it | Lost-time events with reasons | Source **[OPEN]** |
| DHU / quality | Q1 (or Q2) | Live / daily | Quality inspection data | Source **[OPEN]**; DHU vs defective % to choose (V6) |
| Rejection | Q3 | Daily / per order | Rejects, cut qty | Source **[OPEN]**; basis to choose (V7) |
| Manpower / attendance | W1 or manpower present | Daily (morning) | Attendance | **[OPEN]** whether this means manpower present count or absenteeism % |
| Productivity | P7 | Daily | Output + manpower (+ machines) | Unavailable until manpower data exists |

All nine items are operational and production-centric. The executive period KPIs from research §3.4 (OTD, cut-to-ship, CPM and similar) are **not in the priority set**. They depend on future modules.

### Platform MVP [PROPOSED; to confirm in the PRD]

- Authentication, roles, and access scoped to the organization and its factories
- RMG application data source, connected through API or replica, plus administrator-curated datasets
- **KPI definition management:** no-code formulas, variant selection, owner, target, direction, aggregation rule, refresh class, data prerequisites, versioning, **approval workflow**
- Grid dashboard builder; draft → approve → publish; versioning and rollback
- Core widgets: KPI card, line/bar chart, table, gauge/progress, status, filter, text
- Dashboard-level and widget-level filters (factory / floor / line / date)
- Refresh: live (~30 s) for operational KPIs where the source supports it, daily/period for others; "data as of" time and stale-data indicators; **"unavailable: missing data" state**
- **KPI snapshot capture from go-live** for trends
- Audit of configuration, KPI, approval and permission changes
- Responsive desktop and mobile web

**Deferred [PROPOSED]:** TV/kiosk mode (TBD), alerts, scheduled or emailed reports, exports, embedding and public links, executive period KPIs that depend on future modules, PGlite/offline, native mobile, AI features.

**Non-goals for MVP:**
- **[CONFIRMED]** offline operation
- **[CONFIRMED]** general-purpose BI
- **[CONFIRMED]** raw SQL for normal business users
- **[CONFIRMED]** showing KPIs whose source data is missing
- **[PROPOSED]** arbitrary JavaScript or HTML from users
- **[PROPOSED]** multi-tenant SaaS

## 8. Key constraints and technology posture

| Item | Position |
|---|---|
| Data source | **[CONFIRMED]** existing RMG application database. **Preferred access: API or read replica**, if it meets freshness and data needs; isolated direct database access if necessary. **[ADR]** after technical assessment of the RMG system |
| Live freshness | **[CONFIRMED]** about 30 s for operational KPIs *where the source supports it*. The delivery mechanism is **[ADR]**. Replica lag or API latency counts toward the 30 s |
| KPI definitions | **[CONFIRMED]** client-approved, versioned, with an owner, target and direction. The client selects among variants (research §4) |
| Governance | **[CONFIRMED]** approval before publishing; no raw SQL for business users. Role matrix **[OPEN]** |
| History | **[CONFIRMED]** snapshots from go-live. Grain, retention and backfill **TBD**. Storage design is **[ADR]** |
| Metadata-driven; no table per component or KPI | **[CONFIRMED]** |
| Laravel + PostgreSQL | **[CONFIRMED preference]**. That the RMG application uses the same stack is **[ASSUMED]** |
| Offline / PGlite | **[CONFIRMED]** not in MVP |
| Builder technology | **[ADR]** after the UX requirements |
| Architecture shape | **[PROPOSED]** modular monolith |

## 9. Remaining open items, and their effect on the PRD

| # | Open item | Effect on the PRD | Owner |
|---|---|---|---|
| O1 | Capture frequency, granularity and data owner per data item (research §1 sheet) | Decides which priority KPIs can actually be live. The PRD states the live requirement *conditionally* | Client IT / RMG app owner |
| O2 | Are SMV/SAM and attendance available in the RMG application? | Line efficiency, productivity and manpower KPIs may launch as "unavailable" | Client IE / HR |
| O3 | Final top-10 ranking; are P4's two names one KPI; meaning of manpower/attendance | MVP KPI list | Client management |
| O4 | The client's approved formula per KPI and variant choices (V2–V16), with targets | KPI acceptance criteria | KPI owners |
| O5 | Source of quality data (DHU, rejection) and of downtime events | Feasibility of Q1, Q3, P11 | Client |
| O6 | Role matrix: who creates datasets, KPIs, widgets and dashboards; who approves | RBAC requirements | Client |
| O7 | Who the "clients" among the users are: internal or external buyers | Access model, data isolation | Client |
| O8 | Snapshot grain, retention period, backfill possibility | History requirements, storage NFRs | Client |
| O9 | Scale: factories, lines, users, concurrent users | Performance and scalability NFRs | Client |
| O10 | TV/kiosk mode; exports; alerts | Scope | Client |
| O11 | RMG application technical assessment (stack, APIs, replica feasibility, change events) | Data-access ADR | Our team + client IT |

## 10. Vision

The platform becomes the **governed executive window onto the RMG application**. Every number has an owner, an approved definition and a history. Each new module (quality, HR, orders, shipment) unlocks more KPIs from the research catalogue, such as OTD, cut-to-ship and cost per minute. Business users then add them to dashboards without a development ticket.
