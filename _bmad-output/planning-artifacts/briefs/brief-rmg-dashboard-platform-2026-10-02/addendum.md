---
title: "Addendum: RMG Dynamic Dashboard Platform brief"
created: 2026-10-02
updated: 2026-10-05 (marked historical)
---

# Addendum: RMG Dynamic Dashboard Platform

> **Historical record (read before using).** This addendum documents discovery for brief **v1–v3**, when the work was framed as an **RMG client engagement**. Since brief **v4** (2026-10-05) and PRD v3.1, the product is **domain-agnostic**. Read the RMG-specific content below as follows:
> - client sessions and KPI priorities;
> - SMV and attendance dependencies;
> - factory, floor and line scope;
> - access to the RMG database or replica;
> - the approval requirement.
>
> All of it is **example-domain or superseded material**. None of it is a platform requirement. Where this addendum conflicts with brief v4 or PRD v3.1, **those documents win**.


This file holds detail that belongs in downstream documents (PRD, UX, architecture). The brief itself stays at 1–2 pages.

## A. Source input

The initial input was a 37-section requirements prompt from Nahidul, dated 2026-10-02. It asks for a complete BMAD product-definition package with 42 deliverables, produced in stages: brief, PRD, UX, architecture, ADRs, epics, stories, acceptance criteria, traceability, and a readiness review. Every statement must be tagged CONFIRMED, ASSUMED, PROPOSED, OPEN QUESTION or ARCHITECTURAL DECISION.

### Standing directives from the input (they apply to every later stage)

- Do not start implementation, and do not lock the architecture early.
- Technologies the client mentioned (PGlite, WebSockets, Redis, GrapesJS, GridStack, ECharts, Kafka) are candidates, not requirements. Keep business requirements separate from implementation choices.
- Do not invent latency, scale or performance targets. Where the client has not given a number, write "TBD — Client Confirmation Required".
- Keep these concepts decoupled: dashboard, layout, widget, widget configuration, data binding, dataset, field, data source, query definition, query execution, dashboard and widget filters, real-time data, version, permissions, audit.
- Several widgets must be able to share one dataset or query definition.
- Do not create a physical table for each user-created component unless that is proven necessary. The preferred direction is metadata-driven.
- Business users must never get unrestricted arbitrary SQL.
- Prefer a modular monolith to microservices unless the requirements justify otherwise.

### Topics the PRD and architecture stages must cover (from input sections 3–35)

Dynamic components; the query engine and SQL safety (who may create, modify and execute queries; access to datasets and fields; parameter validation; injection prevention; cost limits); data-source registration and secrets; PostgreSQL design (JSONB, indexing, partitioning, materialized views, high availability, backup, retention); the PGlite question and its synchronization semantics; measurable real-time requirements; the widget catalogue and lifecycle states; the widget SDK and plugin model; the builder choice (GridStack vs GrapesJS vs a custom schema-driven builder); the dashboard lifecycle and versioning; the RMG domain KPIs; RBAC and data-level scoping (factory, department, business unit); security; audit; performance; scalability; UX states; sharing; export; alerts; NFRs; risks; and traceability.

## B. Discovery baseline (v2, after client round 1 on 2026-10-02)

| Area | Position | Changed from v1 |
|---|---|---|
| Client engagement, single organization | CONFIRMED; future multi-tenancy OPEN | — |
| Data source | **CONFIRMED** existing RMG application database and APIs; production module exists; more modules coming | was OPEN |
| Users | **CONFIRMED** clients, executives, CXOs; which "clients" is OPEN | was assumed operational personas |
| Primary purpose | **CONFIRMED** management/executive summary and visibility | new |
| KPI catalogue | **CONFIRMED** none exists; discovery and proposal required | was OPEN |
| Freshness | **CONFIRMED** about 30 s, configurable; level of configuration and which widgets are in scope OPEN | was TBD |
| Historical data | **CONFIRMED** currently unavailable; reason and implications OPEN | new |
| Off-the-shelf BI | **CONFIRMED** none in use; dashboard-centric, not generic BI; embedded BI is a comparison option only | was hypothesis |
| Offline / PGlite | **CONFIRMED** offline not required; PGlite not in MVP, future option | was ADR |
| Devices | **CONFIRMED** desktop + mobile web; TV/kiosk TBD | mobile was "consider" |
| Meaning of "dynamic" | **CONFIRMED** all of: layout, widget configuration, business-user KPI/calculated metrics, administrator-managed datasets/sources, all metadata-governed | was "concept" |

## B2. Session 2 outcomes (2026-10-02)

The detailed answers are recorded in brief v3, §1, §7, §8 and §9. The open items (O1–O11) carry forward to the PRD. The capture sheets in `../../discovery/client-session-2-kit.md` are still the instrument for closing O1–O9.

**Note on the C pack below:** Session 2 has now been held. Questions it left unanswered are tracked as O1–O11 in brief §9.

## C. Client discovery pack: session 2 (priority order set by Nahidul)

Items already answered in round 1 have been removed. Ask the client to **rank** within each group.

### C1. Data reality → data-source ADR, feasibility, granularity of every KPI
1. What production data is available today in the RMG application? Show one real record.
2. What is the lowest granularity available: factory, floor, line, workstation, machine? And by time: piece, bundle, hour, shift?
3. How often is production data captured and updated? Manually, by scanner, or by IoT?
4. Which APIs and events does the RMG application expose (REST endpoints, webhooks, queues, database change events)?
5. What will future RMG modules provide, and roughly when (quality, workforce, WIP, inventory, orders, shipment)?
6. **Why is historical data unavailable:** not stored, purged, or not yet migrated? Should the platform **keep its own history (snapshots) from go-live**?
7. May the platform read the RMG application database directly, a read replica, or only through APIs? **[ADR input]**
8. Does the RMG application use Laravel/PostgreSQL? **[confirms ASSUMED]**

### C2. KPIs and business requirements → KPI catalogue, MVP widgets
9. Which decisions should executives and CXOs make from the dashboard (for example: reallocate capacity, escalate a late order, intervene at a factory)?
10. From the proposed KPI catalogue ([research.md §3](../../research/domain-rmg-production-and-executive-kpis-2026-10-02/research.md), 43 candidates), which production, efficiency, quality, workforce, WIP and operational KPIs matter? Rank the top 10.
11. Which KPIs need near-real-time (about 30 s) updates, and which are fine daily or per shift?
12. Which KPIs are stored directly in the RMG application and which must be calculated? Who owns each definition? For each chosen KPI with variants (research §4, V1–V17), which definition does the client use?

### C3. Dynamic platform governance → RBAC, lifecycle, builder UX
13. Who can create datasets?
14. Who can create KPIs and calculated metrics?
15. Who can create widgets?
16. Who can publish dashboards? Is approval required?
17. Who can modify existing (published) dashboards and KPIs? What happens to dashboards when a KPI definition changes?
18. Are KPI formulas expected to be fully no-code (picking fields and operators)? May administrators write raw SQL?
19. Is data visibility scoped by factory, floor, line, department, or buyer?
20. Can executives personalize their own layout, or only view published dashboards?

### C4. Real-time → real-time ADR
21. Confirm the 30-second target.
22. Is 30 s required for all widgets, or only selected operational KPIs?
23. At what level is freshness configured: system, dashboard, widget, or KPI? Who may change it?
24. What should a widget show when its data is older than its interval (stale badge, grey-out, last value)?

### C5. Build vs buy → architecture comparison scope
25. Confirm that the client wants a dashboard-centric application inside or alongside the RMG application, not a general-purpose BI platform.
26. Is the client open to an embedded BI component if architecture comparison shows clear advantages? (Comparison option only.)

### C6. Devices and remaining scope → NFRs, MVP
27. Confirm desktop and mobile web. Any minimum browsers or devices?
28. Is factory TV/kiosk mode actually required? For which audience?
29. Who are the "clients" among the users: internal management, or external buyers/customers? Must buyers see only their own orders?
30. Exports, alerts, scheduled reports: MVP or later?
31. Scale: number of factories, lines, users, peak concurrent users.
32. Deadline, budget, MVP sign-off owner.

## D. Proposed technical posture (rationale for later ADRs)

### D1. Component and data model: metadata-driven semantic layer [PROPOSED]
- Datasets map to physical sources (a table, view, materialized view, or API resource). Fields carry a role (dimension/measure), a type, allowed aggregations and access rules.
- Widget and dashboard configuration is stored as versioned JSON (JSONB) and validated against a schema for each widget type.
- **Rejected by default:** a physical table per component (schema sprawl, migrations at runtime, permission complexity) and EAV storage for fact data (poor aggregation performance).
- Keep **normalized domain/fact tables** plus **materialized views or pre-aggregated tables** for heavy KPIs. These are created by data administrators or developers, not by dashboard designers.

### D2. Query engine [PROPOSED]
- Widgets send a structured query (dataset, dimensions, measures, filters, sort, limit). The server builds SQL only from whitelisted dataset fields, using bound parameters.
- Calculated fields use a restricted expression grammar (arithmetic, a fixed set of functions) parsed to an AST, never string concatenation.
- Guards: row limits, statement timeout, a read-only database role, and a result cache keyed by the normalized query plus the user's data scope.

### D3. Real-time [ADR pending; target about 30 s CONFIRMED]
- A ~30 s target falls in the "5–60 s" band. Candidates: (a) short polling at the configured interval with a server-side result cache shared across viewers; (b) server push (SSE or WebSocket through Laravel broadcasting) of "dataset changed" notifications, after which widgets re-query.
- Polling is simpler and may be enough at 30 s; push reduces load when many viewers watch the same data and enables sub-interval updates. The deciding factors are viewer count, source change frequency (C1-3), and whether the RMG application can emit change events (C1-4).
- Freshness can never beat the source's capture frequency, so a 30 s target is only meaningful for data captured at least that often.
- Configurable interval: a resolution order such as KPI > widget > dashboard > system default is PROPOSED, pending C4-23.

### D4. PGlite [CLOSED for MVP]
- Offline is not required (round 1), so PGlite is **not in MVP**. An in-memory client cache of the last result per widget covers fast re-render and short network blips.
- Reopen only if offline querying or heavy client-side analytics becomes a confirmed need. The earlier decision tree is still valid then: last-known display → IndexedDB snapshot; offline filter/query → PGlite plus a sync design.

### D4a. History [NEW, ADR pending C1-6]
- With no historical data, trend widgets and period comparisons (today vs yesterday, week to date) have nothing to show unless the platform **captures snapshots**: for example, periodic aggregates per KPI and dimension stored in time-partitioned PostgreSQL tables owned by the platform (not one table per KPI).
- This affects the MVP widget set (line charts) and storage sizing.

### D4b. Business-user KPI formulas [PROPOSED]
- Business users authoring formulas raises the stakes on D2: the formula builder offers only governed fields and a fixed function set, produces an AST, and is validated (types, division by zero, aggregation level) before it is saved.
- KPI definitions are versioned; dashboards bind to a KPI version or "latest" (decision needed: C3-17).

### D5. Builder [ADR pending]
- Needs so far point to a **grid-based dashboard with business widgets**, not free-form page or HTML editing. A GridStack-class grid inside a schema-driven builder fits. GrapesJS fits only if rich free-form page or content editing is confirmed.

## E. Early risk register (v2, to expand in the PRD)

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Production data is not captured often or finely enough for 30 s freshness to matter | High | Unknown | C1-2/3 first; set freshness per KPI to match the source |
| No history, so executives get snapshots without trends or comparisons | High | **High** (confirmed no history) | Platform-owned snapshot store (D4a); decide in C1-6 |
| No KPI catalogue, so the MVP stalls on definitions | High | High | Domain research proposes a catalogue; client ranks the top 10 (C2-10) |
| Business-user formulas produce wrong or expensive KPIs | High | Medium | Governed fields, AST validation, preview against sample data, versioned KPIs, cost guards |
| Dashboard queries load the live RMG application database | High | Medium | Read replica or snapshot/aggregate store; shared result cache; per-query limits |
| "Clients" means external buyers, adding external access and data isolation late | High | Unknown | Ask C6-29 early; design row-level scoping from the start |
| Permission model grows complex (role × factory × dataset × KPI) | Medium | High | Fix the scoping dimensions early (C3-19) |
| ~~PGlite adopted without a need~~ | — | — | Closed: not in MVP (D4) |
| ~~Build not differentiated from off-the-shelf BI~~ | — | — | Reduced: client confirmed dashboard-centric scope; embedded BI is a comparison only |
