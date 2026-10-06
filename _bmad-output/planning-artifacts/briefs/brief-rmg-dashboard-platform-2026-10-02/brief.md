---
title: "Product Brief: Dashflow, Dynamic Dashboard Platform"
status: final
version: 4.1
created: 2026-10-02
updated: 2026-10-05
supersedes: brief-v3-rmg-engagement.md (v3, RMG client engagement)
aligned_with: ../../prds/prd-rmg-dashboard-platform-2026-10-02/prd.md (v3.2)
related:
  - addendum.md (v1–v3 discovery history; RMG content is now example material only)
  - ../../research/domain-rmg-production-and-executive-kpis-2026-10-02/research.md (example domain material)
---

# Product Brief: Dashflow, Dynamic Dashboard Platform

> **Status: final, v4.** This version realigns the brief with PRD v3.1. Versions 1–3 described a dashboard engagement for one RMG (ready-made garments) client. **The product is now domain-agnostic.** RMG is one possible use case, not the product scope. Downstream work (UX, architecture, epics) must not reintroduce RMG-specific concepts as platform requirements.
>
> Tags: **[CONFIRMED]** product-owner decision · **[MOCKUP]** shown in the 2026-10-05 UI mockups · **[PROPOSED]** recommendation · **TBD** not yet known, never invented

## 1. Executive summary

**Dashflow** (working name) is a **domain-agnostic dynamic dashboard platform** **[CONFIRMED]**. Organizations already expose their data through REST APIs. Dashflow lets them turn those APIs into governed, well-presented dashboard **blocks** without a developer, and lets every user assemble those blocks into their own dashboards.

It has two areas **[CONFIRMED][MOCKUP]**:

- **Admin Dashboard Designer.** Admins use a five-step wizard (Configure Block → Data Source → Map Data → Preview → Save/Publish) to do four things:
  - register a **REST API** as a data source;
  - use the **API Data Mapper** to map response fields to a block. For example: this value is the bold headline, this is the comparison, these fields are the chart axes or table columns;
  - choose and configure a **block type**: KPI card, bar, line, pie/donut, table, list and others;
  - **publish** the block directly to the **Block Library**.
- **User Dashboard.** Users sign in to their workspace, open the **Block Library** panel on the right, add and remove blocks, and arrange and resize them on a grid. They can keep several dashboards of their own.

Dashflow is **a dashboard-centric platform with governed BI capabilities** (BI = business intelligence). It supports typed fields, aggregation, calculated metrics, period filters, trends and visualization, all through configured, versioned blocks. It is **not** a general-purpose, ad-hoc BI tool **[CONFIRMED]**.

## 2. The problem

- Every new dashboard or metric usually needs development work, even when the data is already available through an API.
- Turning a raw JSON response into a correct, readable KPI or chart needs decisions about aggregation, formatting and emphasis. Today those decisions live in code.
- People need different views of the same data, but most dashboards are fixed: one layout for everyone.
- This is true in any domain: manufacturing (RMG), HealthTech, FinTech, healthcare, retail and others. A single configurable product can serve all of them.

## 3. Who this serves

| Persona | Need | Status |
|---|---|---|
| **Admin** (with configurable permissions, such as publish, manage data sources, manage users) | Connect APIs, build and publish correct blocks and templates, manage users and settings | [CONFIRMED][MOCKUP] |
| **User** | See the blocks they're allowed to see, and build their own dashboards by adding, removing, arranging and resizing blocks | [CONFIRMED][MOCKUP] |
| **Domain stakeholders** (for example an RMG executive, a clinic manager, a FinTech operations lead) | Use Dashflow as Users. Their domain needs arrive only as configured blocks and templates | [CONFIRMED] |

## 4. The solution

- **REST API data sources (MVP).** The admin configures a JSON REST endpoint with its authentication, headers, parameters and refresh interval. ETag and conditional requests are preferred for detecting changes **[CONFIRMED]**. Calls are server-side only, with protection against requests to internal network addresses (SSRF) **[PROPOSED]**.
- **API Data Mapper.** The admin fetches a sample response, or pastes sample JSON (labelled as sample data, for mapping and preview only), assigns field roles (Label, Value, Dimension, Measure, Time, Filter/Category) for auto-mapping, picks the array that becomes rows, and maps fields to the **slots** of the chosen block type. A KPI card, for example, has headline, label, comparison and note slots; a chart has X axis and series. The admin then adds no-code transforms (filter, group, aggregate, calculated fields, top-N) and sets presentation rules (formats, bold/headline emphasis, colours based on whether higher or lower is better, text templates). A live preview shows the result on desktop, tablet and mobile **[CONFIRMED + PROPOSED]**.
- **Configurable blocks.** Block types include KPI card, KPI & chart, line/area, bar, pie/donut, table, list, progress list, activity feed and calendar/agenda. Each block has its own frame (header, refresh, minimize, ⋯ menu, footer action), default and allowed sizes, a refresh interval and period behaviour **[MOCKUP + PROPOSED]**.
- **Block Library and templates.** Published blocks are organized by category. Admins can also publish **dashboard templates**, with blocks marked mandatory where needed **[MOCKUP + CONFIRMED]**.
- **User Dashboard.** Each user has an Overview and any number of personal dashboards, an "Add blocks" panel with search and category chips, Edit-layout mode for drag and resize, and a date range. Layouts are saved on the server **[MOCKUP]**.
- **Publishing and versions.** There is **no approval workflow in the MVP** **[CONFIRMED]**: Admins with the publish permission publish directly. Every publish creates a new version. Users see the update, but their layout never moves. Admins can restore any earlier version, and all changes are audited **[CONFIRMED + MOCKUP]**.
- **Isolation.** Each workspace is isolated. Per-user data filtering is passed to the API using the user's context **[PROPOSED]**.

## 5. What makes this different

- **Domain-agnostic by design.** No domain logic is built into the core. A new domain needs configuration, not code **[CONFIRMED]**.
- **API-first.** It works directly on existing REST APIs. No data warehouse is required **[CONFIRMED]**.
- **Explicit presentation mapping.** The admin decides what is the headline, what is the comparison, and what goes on each axis or column. This is governed, versioned and previewed **[PROPOSED]**.
- **Personal dashboards over governed blocks.** Users personalize their dashboards, and admins control the definitions **[CONFIRMED]**.
- **Build vs embed:** whether to build the governed BI layer, embed an existing BI engine, or combine the two is decided in architecture. It is not assumed here **[CONFIRMED]**.

## 6. Success criteria *(targets TBD)*

- Share of new or changed blocks published without a code change
- Time from registering an API to a published block
- Share of active users who personalize their dashboards
- Share of blocks with no "unavailable" errors caused by mapping
- Freshness: share of time blocks stay within their refresh interval
- Zero cases of data shown outside a user's permitted access

## 7. Scope

**MVP** **[CONFIRMED / PROPOSED per PRD v3.1]**

**Sign-in, workspaces and access**
- Sign-in with a User/Admin choice, plus a workspace switcher.
- Email and password sign-in.
- Admin permissions.

**Admin side**
- REST + JSON data sources and endpoints.
- A Create-block wizard, with the API Data Mapper inside its Configure step.
- The block types and frame options listed in §4.
- Categories, dashboard templates and block management.
- Direct publishing with versions and restore.

**User side**
- Overview, My dashboards and Templates.
- The Add-blocks panel and Edit layout.
- Desktop, tablet and mobile web; light theme (dark theme deferred, with dark-ready design tokens).

**Platform**
- Live and periodic refresh, with stale and unavailable states.
- Search and in-app notifications.
- An admin overview with platform health.
- Audit, with configurable retention.

**Deferred**
- An approval (maker-checker) workflow.
- SSO.
- Data sources other than APIs (database, files, streams).
- Platform-stored history: trends come from the APIs in the MVP.
- Drill-down between blocks, and blocks that combine several APIs.
- Exports and scheduled reports, threshold alerts, email notifications.
- TV/kiosk mode, public links and embedding.
- A native mobile app.
- Export/import of blocks and templates between workspaces.
- A connector/agent for private networks.

**Non-goals**
- General-purpose, ad-hoc BI.
- Domain logic in the core.
- User scripts or HTML.
- Writing back to source APIs.
- Offline use.
- A physical database table per block or dashboard.

## 8. Use cases (illustrative, not product scope)

| Domain | Example blocks | Notes |
|---|---|---|
| **RMG** (ready-made garments) | Production achievement, line efficiency, WIP, downtime, DHU, rejection | Example only. The domain research holds the KPI catalogue and formula variants for building these as configured blocks. SMV, DHU and similar terms are **not** platform concepts |
| **HealthTech / Healthcare** | Bed occupancy, appointments, waiting times | Possibly regulated data: compliance requirements TBD per customer |
| **FinTech** | Transaction volume, failure rates, settlement status | Same |
| **Retail** | Sales by region, top stores, orders | — |
| **General business** (as in the mockups) | Revenue, expenses, pending approvals, project status, activity, calendar | — |

## 9. Constraints and technology posture

| Item | Position |
|---|---|
| Data source | REST APIs with JSON responses only, in the MVP **[CONFIRMED]** |
| Backend preference | Laravel + PostgreSQL **[CONFIRMED preference]**; justified in architecture |
| Configuration model | Metadata- and configuration-driven. No code and no physical table per block or dashboard **[CONFIRMED]** |
| Deployment | **Cloud or on-premises, TBD per client** (security policy, network access, data residency, infrastructure). The architecture must stay deployment-agnostic and keep a future connector/agent possible **[CONFIRMED]** |
| Scale | Numbers TBD from the first customer. The design must scale horizontally **[CONFIRMED]** |
| Security and compliance | Baseline access control, audit, encryption and configurable retention are required. Compliance regimes TBD per customer **[CONFIRMED]** |
| Offline / PGlite | Not required; not used **[CONFIRMED]** |

## 10. Pending client inputs (not open questions)

These are facts to collect from the first customer. They do not block UX or architecture.
- API inventory: endpoints, response shapes, authentication types, rate limits, update frequency and per-user filtering.
- Expected users, workspaces, blocks and concurrent usage.
- Compliance regime and data residency.
- Chosen deployment model.
- Retention defaults.

## 11. Vision

Dashflow becomes the standard way an organization turns any API into decision-ready dashboards: admins configure, users personalize, and nothing about the domain is hard-coded. Later steps can add maker-checker governance, non-API data sources, alerts, scheduled reports, a private-network connector and shareable template packs per domain.
