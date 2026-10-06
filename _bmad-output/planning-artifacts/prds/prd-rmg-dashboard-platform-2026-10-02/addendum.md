---
title: "PRD Addendum: Dashflow, Dynamic Dashboard Platform"
version: 3
created: 2026-10-02
updated: 2026-10-05
companion_of: prd.md (v3)
supersedes: addendum-v1-rmg-centric.md
---

# PRD Addendum: Dashflow

This addendum holds the technical posture, options and rationale that downstream stages need, mainly `bmad-architecture` and `bmad-ux`. That material does not belong in the PRD's capability statements. **Nothing here is a final decision.** Items marked [ADR] are decided in `bmad-architecture` as architecture decision records (ADRs).

**Tags:**
- **[PROPOSED]**: our recommendation.
- **[ADR]**: decided in `bmad-architecture`.
- **[CLOSED]**: decided for the MVP.
- **[DIRECTION …]**: delivery-team direction.
- **CONFIRMED**: confirmed by the client or product owner.

> **Neutrality note.** Sections A–I describe the *reference shape of a custom build*. They do **not** presuppose the build-vs-embed outcome (§J). If an embedded or hybrid option is chosen, they become **requirements the chosen engine must satisfy**: slot-based mapping, governed Transforms, ratio-safe roll-ups, the Unavailable state, user dashboards with version-safe propagation, Workspace isolation, and SSRF-safe API access.

## A. Configuration model [PROPOSED]

Kept separate:
- **Workspace**: the tenant isolation boundary.
- **Data Source**: base URL and auth.
- **Endpoint**: path, method and parameter bindings.
- **Block**: Block Type, Slot Mapping, Transforms, Presentation Rules, Block Chrome and version.
- **Block Category.**
- **Dashboard Template.**
- **User Dashboard** and **Block Instance**: a reference to a Block, plus position, size and state.
- Permission, Role/Group, Audit.

How these are stored:
- **Configuration** (Blocks, Templates, Endpoints) is held as versioned JSON documents, for example PostgreSQL JSONB, each validated against its Block Type's slot schema.
- **Users, roles, lifecycle, audit and user dashboards** are held in normalized relational tables. Every row is keyed by Workspace.
- **Block Instances** store only Block references, never copies. This is what makes version-safe propagation possible (PRD FR-42).

Rejected:
- a physical table per Block, dashboard or Template;
- entity-attribute-value (EAV) storage for fact data;
- persisting API payloads beyond short-lived caches (PRD NFR-5).

## B. Transform engine (API Data Mapper runtime) [PROPOSED]

- The pipeline runs server-side on each fetched response: **record-path extraction → typed Mapping → Transforms (filter → group → aggregate → calculated → sort → top-N) → Block shaping**.
- **Path expressions** (for example, JSONPath-style) are parsed and confined to the response. They can never reach outside it.
- **Calculated fields** use a restricted expression grammar. Each formula is parsed into an abstract syntax tree (AST), type-checked and evaluated by the engine. It is never run as code.
- **Ratio Calculated Fields** keep their numerator and denominator through grouping (PRD FR-28).
- **Guards:**
  - maximum response size and number of pages;
  - maximum rows after extraction;
  - maximum group cardinality;
  - a CPU time limit per transform run.
- **Where aggregation happens is an [ADR].** It can run in the platform over fetched rows, or be pushed down to the API through Endpoint Parameters (for example, `groupBy`, `from`, `to`) where the API supports it. Push-down protects the platform from very large payloads.

## C. API connector [PROPOSED; parts are ADR]

- **Execution.** All calls are server-side. The browser never calls source APIs directly. This keeps secrets server-side, makes user-context scoping enforceable and enables shared fetches.
- **SSRF controls:**
  - a host allowlist;
  - DNS resolution checked against private, loopback, link-local and metadata address ranges, both before connecting and after redirects;
  - no redirects to hosts that are not allowlisted;
  - a dedicated egress path if one is available.
- **Auth types** (confirmed per integration from the API inventory):
  - API key in a header or query string;
  - bearer token;
  - OAuth2 client credentials, with token refresh;
  - basic.

  User-context binding (PRD FR-8) adds user attributes to parameters or headers server-side.
- **Pagination:** page/offset, cursor, and link header.
- **Change detection:** ETag/`If-None-Match` and `Last-Modified`/`If-Modified-Since` are preferred. A `304` reuses the cached transformed result (PRD FR-13).
- **Caching:** results are keyed by *Workspace + endpoint + resolved parameters + user-context values + Refresh Interval window*. Cache lifetime is configurable per Data Source (shorter for sensitive data).
- **Rate limits:** each endpoint has a token bucket. On HTTP 429 the connector backs off.

## D. Refresh mechanism [ADR]

- **Baseline:** server-side scheduled or on-demand fetches at each Block's Refresh Interval, shared by all viewers. Browsers poll the platform, or receive a push such as Server-Sent Events or WebSocket through Laravel broadcasting.
- A dashboard can be no fresher than the API itself: data is bounded by the API's update frequency.
- **Reconnection and missed updates** (PRD FR-61, FR-62) must be designed for whichever transport is chosen.

## E. History [DEFERRED in v3]

In the MVP, trends and comparisons come from data the API provides, through the comparison and series Slots. Platform-side history capture is post-MVP. If it is added, use one time-partitioned store per Workspace, opt-in per Block and off for sensitive data.

## F. Designer and End-User Dashboard UI [ADR, decided after `bmad-ux`]

- **Both areas need grid editing.** Admins arrange Dashboard Templates and use the wizard's live preview (desktop, tablet, mobile); users use Edit layout mode. Both need responsive desktop and mobile behaviour and accessible alternatives to drag and drop.
- That points to one shared grid-layout library (GridStack-class) inside a schema-driven builder, with a right-side Add-blocks Panel.
- **No free-form HTML or page editing** is needed, so a GrapesJS-style editor is not indicated.

## G. Block Type contract [PROPOSED]

Each Block Type package declares:
- **metadata:** name, icon, category;
- **configuration schema:** drives the generated form, including the Presentation Rules;
- **data-shape contract:** which Dimensions and Measures it accepts, and their cardinality;
- **size limits:** minimum, maximum and default grid size, which bound user resizing;
- **renderer**;
- **state handlers:** loading, empty, error, Stale, Unavailable.

Adding a Block Type touches only its own package.

## H. Browser-side data and offline [CLOSED for MVP]

Offline is not required, so no PGlite is used. The browser keeps the last result for each Block in memory, for re-rendering only.

## I. Candidate stack (preference, not a decision)

The **preferred backend is Laravel + PostgreSQL**. These candidates must each be justified in `bmad-architecture`:
- React and TypeScript;
- a grid-layout library;
- a charting library (for example, ECharts);
- Redis (cache, queues, broadcasting);
- a queue for scheduled fetches;
- Docker;
- GitLab CI/CD.

The shape is a modular monolith, **horizontally scalable** (stateless web/API nodes, shared cache, queue workers) and **deployment-agnostic**: container images, environment configuration, no provider-specific managed services required. It runs in the cloud or on-premises. A private-network **connector/agent** is a future module that would relay fetches from inside a customer network.

**Candidate backend modules:**
- Identity, Workspace and Access
- API Connector (Data Sources, Endpoints)
- Slot Mapper / Transform Engine
- Block, Category and Template
- User Dashboard
- Lifecycle and Publishing
- Refresh
- Search and Notifications
- Audit
- Admin Overview and Health

**Candidate frontend modules:**
- Sign-in
- Admin area (overview, block management, Create-block wizard with API Data Mapper, categories, templates, users, settings)
- User area (Overview, My dashboards, Templates, Add-blocks Panel, Edit layout)
- Block renderers
- Real-time client

## J. Build vs embed: governed BI layer [ADR, mandatory evaluation in `bmad-architecture`]

**Positioning [DIRECTION: delivery team (Nahidul), 2026-10-02]:** *a dashboard-centric platform with governed BI capabilities*. General-purpose ad-hoc BI stays outside the MVP.

**Options:**
1. **Custom-built** API Data Mapper, Transform engine and Block renderers.
2. **Embedded BI engine** (candidates: Metabase, Apache Superset, Power BI Embedded, a headless semantic layer). These have *not been researched yet*. Note that many BI engines expect database sources, so their fit with **API-only sources** must be checked.
3. **Hybrid.** For example, a custom mapper, user dashboards and governance, with an embedded or open-source charting or semantic component.

**Criteria.** Score each option against each criterion, with evidence:

| Criterion | PRD v3 anchor |
|---|---|
| **REST API as the only source**, with nested JSON, pagination and user-context binding | §4.3, FR-8 [CONFIRMED] |
| **Slot-based mapping** with emphasis and presentation rules (headline, comparison, axes, columns, list items) | §4.5, §4.6 |
| Governed Transforms and calculated fields without scripts | FR-24, FR-25 [CONFIRMED] |
| Block Types per the slot catalogue, including list, progress, activity and calendar | FR-32 |
| Create-block wizard with a live preview (desktop, tablet, mobile) | §4.4 [MOCKUP] |
| **User dashboards**: Add-blocks Panel, add/remove, Edit layout, resize, Mandatory Blocks, version-safe propagation | §4.10, FR-42 [CONFIRMED/MOCKUP] |
| Publish permissions (no approval in the MVP), versioning, impact view | §4.8 [CONFIRMED] |
| Ratio-safe roll-ups, Unavailable state, Data-as-of Time | FR-28, FR-30, FR-62 |
| Freshness (≈30 s where the source supports it), shared fetches | §4.11, FR-13 |
| **Workspace isolation** (multi-tenant) | FR-3 [MOCKUP] |
| Security: SSRF, secrets, escaped rendering, audit | FR-10, FR-37, NFR-4 |
| Data protection in regulated domains | NFR-4, NFR-5 |
| Horizontal scalability; cloud and on-premises deployment | NFR-3, NFR-14 |
| Cost: licensing, hosting, build, maintenance; lock-in; time to MVP | — |

**Inputs:** the pending client inputs in PRD §12: API inventory, scale, compliance and deployment model. Deployment-agnosticism (PRD NFR-14) is a criterion: an embedded engine must run in both cloud and on-premises deployments. Client acceptance of an embedded component is a conditional question: raise it only if that option is selected.

**Expected output:** an ADR with a scored comparison, the strongest counter-argument, and the conditions that would change the decision.

**Ad-hoc BI boundary:** reopen it only through the evaluation or a stakeholder discussion, and then as a scope change through `bmad-correct-course`.

## K. Domain examples [PROPOSED]

Domain content is delivered as **Blocks and Dashboard Templates** created with the standard wizard. Examples:
- **RMG:** efficiency, DHU and WIP, with the formula variants V1–V17 from `research/domain-rmg-production-and-executive-kpis-2026-10-02/research.md`.
- **Mockup set:** finance, tasks, projects, calendar and activity.

Export/import of Blocks and Templates between Workspaces, without credentials, is post-MVP.

## L. Traceability note

The PRD maps FRs to UJs and SMs. The full chain from goal to story to acceptance criteria is built in `bmad-create-epics-and-stories`. ADRs come from sections B–F and J.
