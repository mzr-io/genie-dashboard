---
title: "PRD Addendum: RMG Dynamic Dashboard Platform"
created: 2026-10-02
updated: 2026-10-02
companion_of: prd.md
---

# PRD Addendum: RMG Dynamic Dashboard Platform

This addendum holds technical posture, options and rationale that downstream stages need (`bmad-architecture`, `bmad-ux`), but that do not belong in the PRD's capability statements. **Nothing here is a final decision.** Items marked [ADR] are decided in `bmad-architecture` as architecture decision records (ADRs).

**Tags.** [PROPOSED] = our recommendation, not yet client-confirmed · [ADR] = decided in `bmad-architecture` · [CLOSED] = decided for the MVP · [DIRECTION …] = delivery-team direction · CONFIRMED = client or business fact.

> **Neutrality note.** Sections A–I describe the *reference shape of a custom build*. They do **not** presuppose the outcome of the build-vs-embed evaluation (§J). If an embedded or hybrid option is chosen, they become **requirements the chosen engine must satisfy**: governance, ratio-safe roll-ups, Unavailable state, Snapshots, Data Scope. They are not a design to implement as-is.

## A. Metadata-driven model [PROPOSED]

**Separation of concerns.** These concepts are kept separate, as the original requirements asked:

- Dashboard
- Layout
- Widget, Widget Configuration and Data Binding
- KPI Definition and KPI Version
- Dataset, Field and Data Source
- Query Definition and query execution
- Dashboard and Widget Filters
- Refresh
- Version, Permission and Audit

Many Widgets reuse one KPI or Dataset.

**Storage.**
- Configuration (Dashboards, Layouts, Widget Configurations, KPI formulas as parse trees, Dataset and Field metadata) is stored as **versioned JSON documents (for example, PostgreSQL JSONB)**, validated against a schema for each Widget Type and KPI.
- Identity, lifecycle, permissions and audit live in **normalized relational tables**.

**Rejected by default:**
- *A physical table per component or KPI.* It causes schema sprawl and runtime migrations, and complicates permissions. It contradicts the CONFIRMED principle "metadata-driven; no table per component or KPI" (brief v3 §8).
- *Entity-attribute-value (EAV) storage for fact data.* Aggregation performs poorly.

**Kept as options for performance:** source-side views, materialized views, or pre-aggregated tables for heavy KPIs. These are created by administrators or developers, never by business users.

## B. Query Engine safety [PROPOSED]

- Business users never send SQL. The engine compiles **Query Definitions** (Dataset, Dimensions, Measures/KPIs, Filters, sort, limit, grain) into SQL or API calls.
- **Identifiers come only from Dataset metadata.** Every user value is a bound parameter.
- **KPI formulas** use a restricted expression grammar: arithmetic, comparisons, a fixed set of functions and safe division. Each formula is parsed into an abstract syntax tree (AST), type-checked and compiled. It is never assembled by string concatenation.
- **Guards:**
  - a read-only database role or API credential;
  - a statement timeout;
  - row and group-by caps;
  - date-range caps for each refresh class;
  - per-user and global concurrency limits;
  - a result cache keyed by *normalized query + Data Scope + freshness window*.
- **Ratio KPIs:** compute the numerator and denominator per group, then roll up by summing (PRD FR-17). Snapshots store both parts (FR-54).

## C. Data access to the RMG application [ADR, decided after O11]

| Option | Freshness impact | Load on production | Notes |
|---|---|---|---|
| Read replica | Replica lag counts toward the ~30 s target | Isolated | **Client-preferred** if lag is small enough |
| RMG APIs | API latency and rate limits | Low, if the APIs are efficient | **Client-preferred.** Aggregation may need dedicated endpoints |
| Change events / change data capture into a platform store | Event-driven, potentially the freshest | Minimal | Also feeds Snapshots; more build effort |
| Direct read-only database access | Freshest | Risky | Acceptable only isolated and limited (client) |

## D. Live refresh mechanism [ADR, decided after O1 and O11]

- For the ~30 s target, the candidates are:
  - (a) short polling at the Freshness Interval, with shared server-side results;
  - (b) server push (Server-Sent Events or WebSocket via Laravel broadcasting) of "dataset changed" notifications, after which widgets re-query;
  - (c) change events from the RMG application into the platform.
- **Polling is the simplest baseline.** Push reduces load when many viewers watch the same data, and becomes worthwhile if the RMG application can emit change events.
- A dashboard's freshness can never be better than how often the source captures data (research §5).
- Reconnection, missed updates and ordering (PRD FR-51 to FR-53) must be designed for whichever option is chosen.

## E. History / Snapshots [ADR, decided after O8]

- Snapshots are stored in platform-owned, time-partitioned PostgreSQL tables, keyed by KPI, KPI Version, dimension members and time grain. Numerator and denominator are stored for each ratio KPI.
- **There is one Snapshot store, not one table per KPI.**
- Retention, partition pruning and backfill imports depend on O8.

## F. Dashboard builder [ADR, decided after `bmad-ux`]

- The confirmed needs are **grid-based editing, business Widgets, and responsive desktop and mobile layouts**. There is **no need for free-form page or HTML editing.**
- These needs point to a grid-layout library (for example, GridStack-class) inside a builder driven by Widget Type schemas.
- GrapesJS fits only if UX later confirms a need for rich free-form content or page editing.

## G. Widget Type contract [PROPOSED]

Each Widget Type package declares:

- **metadata:** name, icon, category;
- **configuration schema,** which drives the generated configuration form;
- **data-shape contract:** the Dimensions and Measures it expects;
- **renderer;**
- **state handlers:** loading, empty, error, Stale, Unavailable;
- **live-update behaviour.**

The engine loads registered types. Adding a type touches only its own package (PRD FR-35).

## H. Browser-side data and offline [CLOSED for MVP]

- Offline operation is not required, so **PGlite is not used in the MVP**. An in-memory cache of the last result per widget covers re-render and short network drops.
- Reopen this decision only if offline querying or heavy client-side analytics becomes a confirmed need. The decision tree is in brief addendum D4.

## I. Candidate stack (client preference, not a decision)

The client prefers **Laravel and PostgreSQL** for the backend. The following are candidates, each to be justified in `bmad-architecture` against these requirements:

- React and TypeScript for the frontend;
- a grid-layout library;
- a charting library (for example, ECharts);
- Redis, if caching or broadcasting needs it;
- a queue for Snapshot jobs;
- Docker and GitLab CI/CD.

A modular monolith is preferred over microservices.

Candidate modules:

| Side | Modules |
|---|---|
| Backend | Identity and Access; Data Source; Dataset; KPI; Query Engine; Widget/Dashboard; Lifecycle and Approval; Refresh; Snapshot; Audit; Admin/Observability |
| Frontend | Builder; Viewer; Widget Engine/Library; KPI Builder; Data Admin; Real-time client |

## J. Build vs embed: governed BI layer [ADR — mandatory evaluation in `bmad-architecture`]

**Positioning [DIRECTION: delivery team (Nahidul), 2026-10-02; consistent with the client's CONFIRMED "dashboard-centric, not general BI"]:** the product is *a dashboard-centric platform with governed BI capabilities* (PRD §1.1). BI is a core part of the platform. General-purpose ad-hoc BI stays outside the MVP.

**Options the architecture stage must evaluate:**

1. **Custom-built governed BI/query layer.** The platform owns the semantic layer (Datasets, Fields, KPIs), the query engine, the visualizations and the builder. It is built on the preferred stack (addendum §I).
2. **Embedded BI engine.** An existing BI product supplies modelling, querying and charts, embedded inside the platform's own UI and access model. Candidates to assess: Metabase, Apache Superset, Power BI Embedded, or a headless semantic layer. These are examples only; they have **not been researched yet**.
3. **Hybrid.** For example, the platform owns KPI governance, approval, Data Scope and the dashboard UX, and delegates some of the following to an embedded engine or a headless semantic/query layer:
   - query compilation;
   - caching;
   - pre-aggregation;
   - visualization.

**Evaluation criteria.** Each is derived from a confirmed client requirement or a PRD requirement. Score every option against every criterion with evidence. Do not choose by default.

| Criterion | Requirement anchor | Question for each option |
|---|---|---|
| Dynamic KPI creation by business users | PRD §4.4, FR-15 to FR-21 [CONFIRMED] | Can non-technical users author KPIs as governed, versioned metadata, using the client's Variants, owner, target, Direction and Aggregation Rule, without SQL? |
| Approval workflow and versioning | §4.8, FR-42 to FR-45 [CONFIRMED approval] | Can KPI and Dashboard changes go through Draft → In Review → Approved → Published, with diffs, impact lists and rollback? |
| Dataset configuration | §4.3 | Can administrators curate Datasets and Fields, including allowed aggregations and Data Scope mapping, over the RMG source? |
| Ratio-safe aggregation, Unavailable state, Data-as-of | FR-17, FR-19, FR-52 | Can it roll up numerator and denominator separately, show "Unavailable, never zero", and show the source data time? |
| Dashboard customization and UX | §4.6, §4.7, FR-31 to FR-41 | Grid builder, responsive desktop and mobile layouts, RMG-specific widgets, consistent product UX inside the RMG context |
| Near-real-time (~30 s) | §4.10, NFR-1 [CONFIRMED target] | Can it refresh at a configurable interval per Widget or KPI, show stale and reconnecting states, and avoid multiplying load on the source? |
| Governance and security | §4.1, §4.5, §4.12, NFR-7 | Row-level Data Scope, field restrictions, no raw SQL for business users, complete audit, single sign-on (SSO) (O19), external client isolation if O7 requires it |
| Snapshots and history | §4.11 | Can it capture versioned KPI Snapshots and show trends over them? |
| Scalability | NFR-2 to NFR-4 (TBD, O9); NFR-5 (TBD with client IT) | Concurrency, caching, load budget on the RMG source |
| Extensibility | FR-35, NFR-15 | New Widget Types and new Data Source types as modules |
| Cost | — | Licensing (per user or per viewer for embedded engines), hosting, build effort, maintenance, skills |
| Lock-in and fit with the stack | Client preference: Laravel + PostgreSQL | How hard is it to integrate, upgrade or replace? |
| Time to MVP | Delivery plan (TBD) | Build effort versus integration effort |

**Client acceptance.** If an embedded or hybrid option is selected, confirm that the client accepts a third-party embedded component (PRD O29). Raise this with the client only at that point.

**Inputs the evaluation depends on:**
- O1, data capture frequency;
- O6, the role matrix;
- O7, whether there are external clients;
- O9, scale;
- O11, the RMG technical assessment;
- O19, single sign-on;
- O24, the level at which freshness is configured.

**Expected output:** an ADR that recommends one option, with a scored comparison, the strongest counter-argument, and what would change the decision.

**Ad-hoc BI boundary.** General-purpose ad-hoc BI (free-form exploration, unrestricted SQL, a full analyst workspace) is **not an MVP requirement**. It is **not** raised with the client at this stage. Two things could reopen it:
- the evaluation shows that an option provides it at negligible cost or risk, **and** a confirmed need emerges; or
- a later client discussion identifies a clear requirement.

If it is reopened, treat it as a scope change (`bmad-correct-course`), not a silent addition.

## K. Traceability note

- The PRD links FRs to user journeys and success metrics.
- The full chain (business goal → need → requirement → epic → story → acceptance criteria) is built in `bmad-create-epics-and-stories`, using the FR IDs as stable anchors.
- Architecture decision records (ADRs) are produced in `bmad-architecture`, from sections C–F and J above.
