---
title: "PRD: Dynamic Dashboard Platform (domain-agnostic)"
status: draft
version: 2
created: 2026-10-02
updated: 2026-10-02
supersedes: prd-v1-rmg-centric.md (v1, RMG-centric, finalized 2026-10-02)
inputs:
  - ../../briefs/brief-rmg-dashboard-platform-2026-10-02/brief.md (v3; RMG-framed, partly superseded, see §0)
  - ../../briefs/brief-rmg-dashboard-platform-2026-10-02/addendum.md
  - ../../research/domain-rmg-production-and-executive-kpis-2026-10-02/research.md (now example-domain material only)
  - change signal from Nahidul, 2026-10-02 (domain-agnostic scope; Admin Designer + End-User Dashboard)
companion: addendum.md
---

# PRD: Dynamic Dashboard Platform

*Working title; confirm. Project codename: rmg-dashboard-platform.*

## 0. Document Purpose

This PRD defines **what** the Dynamic Dashboard Platform must do for its first release. It is written for three audiences:
- the delivery team: PM, UX, architecture, development and QA;
- the stakeholders who confirm scope;
- the downstream BMAD workflows: `bmad-ux`, `bmad-architecture` and `bmad-create-epics-and-stories`.

**Version 2 changes the product scope.** The platform is now **domain-agnostic**: it serves RMG, HealthTech, FinTech, Healthcare, Retail and other domains through configuration **[CONFIRMED]**. Version 1 was RMG-centric and is archived as `prd-v1-rmg-centric.md`. Everything RMG-specific (SMV, DHU, production efficiency, the KPI catalogue) is now **example domain configuration only** (§4.13). Brief v3 still frames the work as an RMG engagement. Where the two disagree, **this PRD supersedes it**; see the change log in §15.

**Structure.**
- Vocabulary is fixed by the Glossary (§3).
- Features (§4) carry globally numbered requirements, FR-1 to FR-60. **FR IDs were reissued in v2**, and v1 IDs are not stable references.
- Every FR has testable consequences, written as Given/When/Then where that helps.
- Cross-cutting quality requirements are in §5. Technology options are in [addendum.md](addendum.md).

**Status tags.**

| Tag | Meaning |
|---|---|
| **[CONFIRMED]** | Client, business or product-owner fact |
| **[ASSUMPTION]** | Working assumption, indexed in §14 |
| **[PROPOSED]** | Our recommendation, not yet confirmed |
| **[OPEN]** | Needs an answer; listed in §13 |
| **[ADR]** | Architectural decision pending in `bmad-architecture` |
| **TBD** | No value yet; never invented |
| **[DIRECTION: …]** | Delivery-team direction, consistent with but not confirmed by the client |
| **[SUPERSEDED v1]** | A v1 position replaced in v2. See §15 |

Compound forms such as [CONFIRMED preference] keep the meaning of their first word. **What blocks which downstream phase** is summarised in the phase-gating table at the end of §13.

## 1. Vision

Organizations in every domain need dashboards that change as fast as their questions do. Today each new dashboard is a development project. The data often already exists behind APIs, but turning an API response into a trustworthy chart or KPI needs a developer every time.

The Dynamic Dashboard Platform turns this into configuration. It has two areas **[CONFIRMED]**:

1. **Admin Dashboard Designer.** An admin registers an **API endpoint** as a data source. The admin then uses the **API Data Mapper** to map response fields to block properties, and to shape the data with governed transforms (filter, group, aggregate, calculated fields, top-N) and presentation rules (labels, columns, axes, formatting, highlights). The admin builds blocks such as KPI cards, bar, line and pie/donut charts and tables, arranges them into a dashboard, and publishes it after approval.
2. **End-User Dashboard.** Users sign in and open the published dashboards available to them. A **block library** on the right lets each user add and remove blocks and arrange and resize them. Each user's dashboard is **their own personal layer** on top of the admin's default.

The platform is **domain-agnostic** **[CONFIRMED]**. RMG production dashboards, hospital bed-occupancy views, FinTech transaction monitors and retail sales boards are all just configurations. No domain concept is hard-coded.

Success means a new dashboard over an existing API reaches its users **without a development ticket**. Every number shown traces back to a mapped, approved configuration.

### 1.1 Positioning: a dashboard-centric platform with governed BI capabilities

This positioning is **[DIRECTION: delivery team, 2026-10-02]** and is retained from v1. Business intelligence (BI) capabilities are core, but every one is delivered through governed, configured objects. There is no ad-hoc analyst workspace.

| Governed BI capability | In the MVP | Where |
|---|---|---|
| Typed field model over API responses (Dimensions, Measures) | Yes | §4.3 |
| Transforms: filter, group, aggregate, calculated fields, sort, top-N | Yes **[CONFIRMED]** | §4.3 |
| Ratio-safe aggregation | Yes | FR-17 |
| Governed metrics (owner, target, Direction, versions) | Yes | §4.4 |
| Visualization and presentation rules | Yes | §4.5 |
| Dashboard Filters and drill-down | Yes | FR-30, FR-31 |
| Trends over captured Snapshots | Yes (configurable) | §4.10 |
| Row-level Data Scope | Yes | FR-3, FR-27 |
| Exports, scheduled reports, alerts | Deferred | §11.2 |
| **General-purpose ad-hoc BI** (free-form exploration, unrestricted queries, analyst workspace) | **No** (non-goal) | §10 |

**Boundary rule.** Ad-hoc BI stays outside the MVP unless the architecture build-vs-embed evaluation (addendum §J) or a later stakeholder discussion shows a clear requirement for it.

## 2. Target Users

### 2.1 Jobs To Be Done

- **Dashboard Admin (designer).** "Given an API we already have, let me build and publish a correct, good-looking dashboard without a developer." **[CONFIRMED]**
- **Approver.** "Make sure nothing reaches users without review." **[CONFIRMED]** approval retained; who approves is **[OPEN] (O6)**.
- **End User.** "Let me see the dashboards I'm allowed to see, and arrange them my way. Add what I need, remove what I don't." **[CONFIRMED]**
- **Platform Administrator.** "Let me manage users, roles, data access and the API connections safely." **[CONFIRMED]**
- **Domain stakeholder** (for example an RMG executive, a clinic manager, a FinTech operations lead). They consume dashboards as End Users. Their domain needs arrive only as configuration (§4.13).

### 2.2 Non-Users (v1)

- Analysts who want free-form, ad-hoc BI. See §1.1.
- Developers writing custom code inside the platform. No user scripts (§10).
- Anonymous or public viewers. No public links **[PROPOSED]**.
- Offline users **[CONFIRMED]**: offline operation is not required.

### 2.3 Key User Journeys

Names are illustrative. The domains vary on purpose.

- **UJ-1. Sadia, Dashboard Admin, turns a sales API into a published dashboard.**
  - **Context:** Sadia works at a retail chain. The team already exposes `GET /api/sales/daily`, which returns JSON rows of store, region, date, revenue, orders and target.
  - **Path:**
    1. Registers the endpoint as an API Data Source: bearer-token auth, a `date` parameter bound to the Dashboard date filter.
    2. Clicks "Fetch sample". The API Data Mapper shows the response tree and infers field types.
    3. Creates a Data View: maps `region` and `store` as Dimensions, and `revenue`, `orders` and `target` as Measures. Adds the calculated field `achievement = SUM(revenue) ÷ SUM(target) × 100`.
    4. Builds four Blocks:
       - a KPI card for Achievement %, with a highlight below 90%;
       - a bar chart of revenue by region (x-axis = region, y = SUM(revenue), labels on);
       - a top-5 stores table (columns renamed, currency formatting);
       - a line chart of daily orders.
    5. Arranges the Blocks on the grid and previews the dashboard as an End User.
    6. Submits for approval.
  - **Climax:** after approval, the dashboard is Published and its Blocks appear in End Users' Block Library. No developer was involved.
  - **Edge case:** the API renames `revenue` to `net_revenue`. The affected Blocks show **"Unavailable: field revenue missing"**, never zero. Sadia gets a mapping-health warning.
  - Realizes FR-7 to FR-20, FR-22 to FR-26, FR-28 to FR-37.

- **UJ-2. Nadia, Approver, reviews a dashboard change.**
  - Nadia sees the diff from the published version: Blocks, mapping and presentation changes, plus the list of users with personal layouts affected. She previews it and approves.
  - Realizes FR-38 to FR-41.

- **UJ-3. Farzana, End User (an RMG COO), personalises her dashboard on mobile.**
  - **Context:** her organization's admin has published "Group Production" from the RMG Domain Pack, an example configuration.
  - **Path:**
    1. Opens the dashboard and sees the admin's default layout.
    2. Opens the right-side Block Library and adds "Downtime by Line".
    3. Removes "Hourly Output", which she doesn't need.
    4. On desktop, drags and resizes the Blocks. On mobile, they stack in her chosen order.
  - **Climax:** next morning her layout is as she left it. A new Block the admin published since then appears in the library, marked "New".
  - **Edge case:** she tries to remove a Block the admin marked **Mandatory**. It cannot be removed, and the reason is shown.
  - Realizes FR-42 to FR-49.

- **UJ-4. Imran, clinic operations lead (HealthTech), watches live bed occupancy.**
  - **Path:** an occupancy KPI card refreshes on its Freshness Interval. Then the hospital API goes down.
  - **Climax:** the Block shows **"Stale: last data 10:42:10"**, never zero, and recovers on its own when the API returns.
  - Realizes FR-50 to FR-53.

- **UJ-5. Platform Administrator restricts data by region.**
  - The admin assigns Rafiq the Data Scope `region = North`. The dashboards Rafiq sees show only North rows, whichever Blocks he adds.
  - Realizes FR-3, FR-27.

## 3. Glossary

FRs, UJs and success metrics use these terms verbatim.

**Platform and domains**
- **Organization.** The entity that deploys and uses the platform. Whether one deployment serves one organization or many is **[OPEN] (O33)**.
- **Domain.** A business area such as RMG, HealthTech, FinTech, Healthcare or Retail. The core platform has **no** domain-specific logic.
- **Domain Pack.** An optional, exportable set of example configurations for one Domain: Data View templates, Blocks, Metrics and a dashboard template. It is not core functionality (§4.13).

**Data**
- **API Data Source.** A registered HTTP API endpoint: URL, method, auth, headers, parameters, pagination and limits. In the MVP this is the only source type **[CONFIRMED]**.
- **Endpoint Parameter.** A request parameter. Its value is either fixed, bound to a Dashboard Filter, or bound to the user context (for example a user or scope token).
- **Sample Response.** A fetched response that the API Data Mapper uses to discover fields.
- **Source Field.** A value located in the API response by a path expression (for example `data[].region`).
- **Data View.** A reusable, governed object: one API Data Source, plus a **Mapping**, plus **Transforms**. It produces a typed tabular result. Many Blocks can reuse one Data View.
- **Mapping.** The assignment of Source Fields to typed **Fields** of a Data View. Each Field has a role: **Dimension** (used to group or filter) or **Measure** (numeric and aggregatable).
- **Transform.** A governed, declarative operation in a Data View: filter, group, aggregate (SUM, COUNT, AVG, MIN, MAX), calculated field, sort, or top-N. Transforms are never user scripts.
- **Calculated Field.** A Field computed by a governed expression over other Fields.
- **Metric.** A governed calculated Measure. It has an owner, target, Direction, unit, Aggregation Rule and versions. A KPI is a Metric shown in a KPI Block.
- **Direction.** Whether higher or lower values are better.
- **Aggregation Rule.** How a Metric rolls up across Dimensions. For ratios it is **sum of numerators ÷ sum of denominators**, never an average of percentages.

**Blocks and dashboards**
- **Block.** A configured visual component bound to a Data View. Its Block Type determines how the data is shown. (v1 called this a "Widget".)
- **Block Type.** A kind of Block: KPI card, bar chart, line chart, pie/donut chart, table, and others. Each Block Type declares a configuration schema and the data shape it expects.
- **Block Configuration.** A Block's data binding (Data View, Fields, Metrics) plus its **Presentation Rules**.
- **Presentation Rules.** Labels, columns, chart axes, series, number/date/currency/percent formatting, highlighted or top values, thresholds and colours, sort, and legend.
- **Admin Dashboard.** A dashboard designed by a Dashboard Admin: a **Default Layout** of Blocks plus the set of Blocks offered in the **Block Library**, with Dashboard Filters, an audience and a Lifecycle State.
- **Block Library.** The panel on the right side of the End-User Dashboard listing the Blocks the user may add.
- **Mandatory Block.** A Block the admin requires on every user's layout. Users cannot remove it.
- **Personal Layout.** An End User's saved arrangement on top of an Admin Dashboard: added and removed Blocks, positions and sizes.
- **End-User Dashboard.** What a signed-in user sees: the Admin Dashboard's Default Layout as modified by their Personal Layout.
- **Dashboard Filter.** A user-selectable constraint (for example a date range or region) applied to the Blocks it is linked to.

**Lifecycle, freshness, governance**
- **Lifecycle State.** Draft, In Review, Approved, Published, Unpublished or Archived. It applies to Admin Dashboards, Data Views, Blocks and Metrics.
- **Approval.** A recorded decision that moves an item from In Review to Approved.
- **Freshness Interval.** The configured target refresh interval of a Block.
- **Data-as-of Time.** The time of the newest source data reflected in a value. Where the API provides it, it comes from a mapped timestamp Field; otherwise it is the fetch time **[PROPOSED]**.
- **Stale.** The state where the Data-as-of Time is older than the allowed staleness.
- **Unavailable.** The state where a value cannot be computed, for example because a mapped Field is missing from the response. It is shown explicitly, never as zero.
- **Snapshot.** A stored, timestamped Data View or Metric result, captured to provide history where it is enabled.
- **Data Scope.** The rows a user may see, defined as conditions on configurable scope attributes (for example `region`, `factory`, `clinic`, `account`).
- **Role / Permission / Audit Event.** As in v1: a named set of permissions, an allowed action on an object type, and an immutable record of a change.

## 4. Features

### 4.1 Identity, Roles and Data Scope

**Roles [PROPOSED; matrix O6]:**
- Platform Administrator
- Dashboard Admin (designer)
- Approver
- End User

The MVP ships predefined Roles. Whether custom Roles are allowed is **[OPEN] (O6)**.

#### FR-1: Authentication
Users must sign in. Unauthenticated requests to pages or APIs are rejected (401 for APIs).
- The idle session timeout is **TBD**.
- The authentication method (local accounts vs SSO with the deploying organization's identity provider) is **[OPEN] (O19)**.

#### FR-2: Role-based permissions
A Platform Administrator assigns Roles. Permissions are enforced server-side on these object types: API Data Source, Data View, Metric, Block, Admin Dashboard, User, Role and Audit Log.
- When a user lacks a Permission, the action is denied in both the UI and the API, and an Audit Event is recorded.

#### FR-3: Configurable Data Scope
A Platform Administrator defines **scope attributes** (for example region, factory, clinic, account). These are generic and not domain-coded **[CONFIRMED: domain-agnostic]**. The administrator assigns each user scope values.
- Given a user scoped to `region = North`, every Block they see contains only North rows. This holds regardless of the Dashboard Filters chosen or the Blocks they add.
- How scope is enforced for each Data View is configured as one of the following **[ADR / O37]**:
  - (a) **platform-side filter** on a mapped Field, after the fetch;
  - (b) **forwarded to the API** as an Endpoint Parameter or token, so the API returns only scoped data;
  - (c) both.
- A Data View that has no scope mapping can only be used on dashboards that the Platform Administrator marks as unscoped **[PROPOSED]**.

#### FR-4: Data access control on Data Views
A Dashboard Admin can restrict a Data View, or individual Fields, to specific Roles. Restricted items are absent from pickers, and direct API use of them is denied.

#### FR-5: External users (conditional on O7)
If End Users can be external to the deploying organization (for example the customers or buyers of a client), they see only data within their Data Scope and only the dashboards shared with them. **TBD pending O7.**

#### FR-6: User administration and self-service
A Platform Administrator creates and deactivates users and assigns Roles and Data Scope. Users can view their own Roles and scope. All changes are audited.

### 4.2 API Data Sources

**Description.** In the MVP the only data source is a **REST API endpoint provided by the admin** **[CONFIRMED]**. Direct database access, replicas, file upload and streaming are deferred **[CONFIRMED; SUPERSEDED v1]**. Because admins enter arbitrary URLs, **outbound-request safety** is a first-class requirement (FR-11).

#### FR-7: Register an API Data Source
A Platform Administrator or Dashboard Admin registers an endpoint with these settings:
- name;
- base URL and path;
- HTTP method;
- headers;
- authentication;
- Endpoint Parameters (fixed, bound to a Dashboard Filter, or bound to user context);
- pagination strategy;
- timeout;
- maximum response size.

Who may register endpoints is **O6**. Constraints:
- The supported methods are **GET** **[PROPOSED]**. **POST** is allowed for read-only query endpoints only if O34 confirms it. The platform never calls endpoints that modify data **[PROPOSED]**.
- The supported auth types are **TBD (O34)** **[PROPOSED: API key, bearer token, OAuth2 client credentials, basic]**.

#### FR-8: Secrets handling
Credentials and tokens are stored encrypted. After they are saved, they are never returned to the browser or to any API caller; only a masked indicator is shown.

#### FR-9: Test, fetch a sample and check health
An admin can test an endpoint and fetch a Sample Response. The system then tracks the endpoint's health:
- healthy, degraded or unreachable;
- the last successful fetch;
- the error rate;
- the latency.

When an endpoint is unreachable, the dependent Blocks show Stale (FR-51), never errors or zeros.

#### FR-10: Pagination and limits
The platform follows the configured pagination (page/offset, cursor, or link header) up to a maximum number of pages and a maximum total size. The limits are **TBD**.
- A response that exceeds the limits is rejected with a clear admin-facing message. It is never silently truncated in a way that would change aggregates [PROPOSED].

#### FR-11: Outbound request safety
API calls are made **server-side only**.
- Destinations must match an **allowlist** of hosts or domains managed by the Platform Administrator [PROPOSED].
- Requests to private, link-local or metadata network addresses are blocked unless explicitly allowlisted. This protects against server-side request forgery (SSRF).
- Redirects to hosts that are not allowlisted are refused.
- Given an admin who enters `http://169.254.169.254/…`, the request is blocked and an Audit Event is recorded.

#### FR-12: Load protection and caching
The platform limits the call rate per endpoint, which is configurable. It also shares one fetch across all viewers of the same Data View, within the same parameter values, Data Scope and Freshness Interval.
- A cached result is never served to a user outside the Data Scope it was produced for.

### 4.3 API Data Mapper (Data Views)

**Description.** This is the core of "dynamic". From a Sample Response, the admin builds a **Data View**: a Mapping of Source Fields to typed Fields, plus governed Transforms. Many Blocks can reuse one Data View. This keeps the v1 principle that **widgets reuse a shared dataset or query definition** **[CONFIRMED principle]**. Realizes UJ-1.

#### FR-13: Explore the response and map fields
The API Data Mapper shows the Sample Response as a navigable tree or table. The admin selects Source Fields (by path, including arrays of records) and maps each one to a Field with:
- a name and label;
- a type (text, number, integer, decimal, date/time, boolean);
- a role (Dimension or Measure);
- for Measures, the allowed aggregations.

Types are inferred from the sample and can be overridden.
- Nested JSON and arrays are supported. The admin chooses the **record path**, meaning which array becomes rows.

#### FR-14: Governed transforms
An admin can add Transforms to a Data View, applied in order:
- filter (on Fields, constants or Dashboard Filter values);
- group by Dimensions;
- aggregate Measures;
- calculated fields;
- sort;
- top-N / limit.

Constraints:
- Transforms are **declarative configuration** and run **server-side**. No scripts and no code **[CONFIRMED: governed transforms; PROPOSED: no scripts]**.
- The calculated-field expression language is restricted to arithmetic, comparisons, a fixed function set and safe division. It is parsed and validated, never evaluated as code. The function list is **O21**.

#### FR-15: Mapping validation and preview
Before saving, the admin sees a live preview of the Data View's result for chosen parameter values. Validation covers types, allowed aggregations, division-by-zero handling and references to unmapped Fields.

#### FR-16: Missing data handling
If a mapped Source Field is missing or has the wrong type in a live response, the affected Fields are **Unavailable** and the Blocks show "Unavailable: \<field\> missing". They are never shown as zero.
- When a mapping repeatedly fails, the admin is notified [PROPOSED].
- Whether partial gaps exclude the affected rows (with a "partial" badge) or make the whole value Unavailable is **O22**.

#### FR-17: Ratio-safe aggregation
For ratio Metrics and calculated fields, roll-ups sum numerators and denominators separately, following the Aggregation Rule.
- Given group A (num 600 / den 1,000 = 60%) and group B (450 / 500 = 90%), the total is **70.0%**. It is not 75.0%, the mean of 60% and 90%.

#### FR-18: Data View reuse and impact
A Data View can feed many Blocks. Before a change that removes or retypes a Field is saved, the admin sees the dependent Blocks and Metrics.
- A Field used by a Published Block cannot be removed until its dependants are updated [PROPOSED].

#### FR-19: Data View versioning
Changes to a Data View that is Approved or Published create a new version, which follows the approval lifecycle (§4.7).

### 4.4 Metrics (governed KPIs)

**Description.** Admins can promote a calculated Measure to a **Metric** that carries governance metadata. In v1 the client confirmed that every KPI has an **owner, a target, a calculation method and a Direction**. That rule is retained as the generic platform rule [CONFIRMED for the original client; PROPOSED as a platform default].

Domain variants, such as the RMG efficiency variants, are configuration only (§4.13). The **authoring role** changes from "business user" in v1 to **Dashboard Admin** [SUPERSEDED v1: business-user KPI authoring]. Whether a separate Metric author role is still needed is **O6**.

#### FR-20: Define a Metric
An admin defines a Metric with these attributes:
- a formula over Data View Fields (FR-14);
- unit;
- owner;
- target (a value, or a value per Dimension member);
- Direction;
- Aggregation Rule;
- description;
- display precision.

A Metric cannot be submitted for approval without an owner, a target, a Direction and an Aggregation Rule.

#### FR-21: Metric transparency
Any user can open a Metric's definition panel from a Block. It shows the plain-language formula, owner, target, Direction, version and Data-as-of Time.

### 4.5 Block Types and Presentation Rules

#### FR-22: MVP Block Types
The MVP provides these Block Types:
- **KPI / card** (value, target, status against Direction, trend sparkline if Snapshots exist);
- **bar chart** (including grouped and stacked);
- **line chart**;
- **pie / donut chart**;
- **table / data grid** (sorting and pagination).

All of these are **[CONFIRMED]**, from the change signal. These are **[PROPOSED]**:
- gauge / progress;
- area chart;
- status indicator;
- text;
- Dashboard Filter controls (date range, Dimension selectors).

Deferred: map, heatmap, image.

#### FR-23: Presentation Rules
For each Block, the admin configures these Presentation Rules **[CONFIRMED]**:
- **Labels and title.**
- **Table columns:** selection, order, header labels, width, alignment.
- **Chart axes and series:** x = Dimension, y = Measure(s), series split, axis titles, scale.
- **Formatting:** number, decimal places, thousands separator, percent, currency, date/time pattern, units.
- **Highlighted and top values:** highlight the top or bottom N, or values above or below thresholds, with colour plus icon or label.
- **Thresholds and colours,** following Direction.
- **Sort order.**
- **Legend and data labels.**

Formatting is locale-aware [PROPOSED]. The locale and currency defaults are **O36**.

#### FR-24: Block configuration form
The configuration form is generated from the Block Type's schema. An invalid configuration cannot be saved, and the error is shown inline. No configuration accepts scripts or raw HTML **[PROPOSED]**.
- **API values are always rendered as text** (escaped), never interpreted as HTML **[PROPOSED; security]**.

#### FR-25: Block states
Every Block has a visible presentation for each of these states:
- loading;
- empty ("no data for these filters");
- error (non-technical message, with a retry);
- Stale;
- Unavailable.

The Data-as-of Time is always visible, or one tap away on mobile.

#### FR-26: Extensible Block Types
A developer can add a Block Type by supplying its metadata, configuration schema, data-shape contract and renderer. No change to the Designer, the End-User Dashboard or the Data Mapper is needed **[PROPOSED]**.

### 4.6 Admin Dashboard Designer

**Description.** Realizes UJ-1. The designer uses a grid-based layout. Its technology is an **[ADR]**, decided after UX.

#### FR-27: Data Scope enforcement in every request
All Block data passes through the user's Data Scope (FR-3), regardless of Block configuration. The only exception is a dashboard explicitly marked unscoped by a Platform Administrator.

#### FR-28: Create, duplicate, archive Admin Dashboards
A Dashboard Admin can create an Admin Dashboard (blank or from a template), duplicate one, or request archival. New Admin Dashboards start in Draft and are invisible to End Users.

#### FR-29: Grid design of the Default Layout
The admin adds Blocks, drags them to position, resizes them on a grid and removes them. Undo and redo are available within a session [PROPOSED].
- If two admins edit the same Draft, the second admin is warned about the conflict on saving.

#### FR-30: Dashboard Filters
The admin adds Dashboard Filters (date range, and Dimension selectors such as region). Each filter is linked to Blocks, and filter values can bind to Endpoint Parameters (FR-7) or to Transform filters (FR-14).

#### FR-31: Drill-down
On Blocks where it is enabled, users can drill from a Dimension value to a lower Dimension (for example region → store). The drill target is configured by the admin and stays within the user's Data Scope.

#### FR-32: Curate the Block Library
For each Admin Dashboard, the admin decides:
- which Blocks are on the **Default Layout**;
- which Blocks are **library-only**, meaning users can add them but they are not shown by default;
- which Blocks are **Mandatory** **[CONFIRMED: default + personal layer, mandatory allowed]**.

#### FR-33: Responsive Default Layout
The Default Layout has desktop and mobile arrangements. Mobile is derived automatically and can be adjusted [PROPOSED].

#### FR-34: Preview as End User
The admin can preview a Draft as a selected Role and Data Scope would see it, on desktop and mobile, with live data. The preview includes the Block Library panel.

#### FR-35: Dashboard templates
The admin can save an Admin Dashboard, with its Data Views and Blocks, as a template, and create new dashboards from it. Templates can be exported and imported as part of a Domain Pack (§4.13) **[PROPOSED]**.

#### FR-36: Save and publish
The admin saves Drafts at any time. Publishing follows §4.7 **[CONFIRMED: approval retained]**.

#### FR-37: Audience
The admin sets which Roles, groups or users can access a Published Admin Dashboard.

### 4.7 Lifecycle, Approval and Versioning

**Description.** Approval before publishing is **retained** **[CONFIRMED]**. It applies to Admin Dashboards, Data Views, Blocks and Metrics. Who approves is **O6**. Realizes UJ-2.

| From | Event | To |
|---|---|---|
| Draft | Submit | In Review |
| In Review | Withdraw or Reject (with a comment) | Draft |
| In Review | Approve | Approved |
| Approved | Publish | Published (becomes the current version) |
| Published | Edit | A new Draft version; the Published version stays live |
| Published | Unpublish | Unpublished |
| Unpublished | Republish, with no change | Published, no re-approval [PROPOSED] |
| Any except In Review | Archive | Archived (read-only) |

#### FR-38: Submit, review queue, notifications
A Draft can be submitted for review. While it is In Review it is locked. Approvers have a review queue. Submitters and Approvers receive in-app notifications on submit, approve and reject [PROPOSED]. Email for these notifications is **O32**.

#### FR-39: Approve or reject with impact
The Approver sees:
- the diff from the Published version;
- a preview;
- the impact: the Blocks that use a changed Data View or Metric, and the number of users whose Personal Layouts include the affected Blocks.

Rejection requires a comment. Authors cannot approve their own items **[PROPOSED; O6]**.

#### FR-40: Versions and rollback
Every publish creates an immutable version. Restoring an old version creates a new Draft from it, which goes through approval again.

#### FR-41: Propagation to users
When a new version of a Block, Data View or Metric is published, every End-User Dashboard that contains the Block shows the new version on its next refresh. This is because the approval covered the impact (FR-39) **[CONFIRMED: admin updates propagate]**. Removing a Block from an Admin Dashboard removes it from the Block Library and from Personal Layouts, and affected users are told [PROPOSED].

### 4.8 End-User Dashboard

**Description.** **[CONFIRMED]** End Users personalise the dashboards they can access. Each End-User Dashboard is the Admin Dashboard's Default Layout plus the user's **Personal Layout**. Realizes UJ-3.

#### FR-42: Access Published dashboards
A signed-in End User sees the list of Published Admin Dashboards in their audience and opens one. Values reflect their Data Scope.
- If a user is not in the audience, the dashboard is not listed, and a direct URL returns "not authorized".

#### FR-43: Block Library panel
A **Block Library** panel on the right side **[CONFIRMED]** lists the Blocks available on that dashboard. For each Block it shows the title, Block Type icon, a short description, and whether the Block is on the user's dashboard. New Blocks are marked "New". The panel can be collapsed, and on mobile it opens as a sheet [PROPOSED].

#### FR-44: Add and remove Blocks
A user can add a Block from the library, by drag or by an "Add" action, and remove any Block that is not Mandatory.
- Removing a Mandatory Block is not possible, and the reason is shown.
- Adding the same Block twice is not allowed in the MVP [PROPOSED; O38].

#### FR-45: Arrange and resize
A user can drag Blocks to reorder them and resize them on the grid, within the size limits of each Block Type **[CONFIRMED: where applicable]**. On mobile, users reorder the stacked Blocks; free resizing is not available on mobile [PROPOSED].

#### FR-46: Persist the Personal Layout
Personal Layouts are saved per user and per Admin Dashboard on the server. They are restored on every device and session.
- Given a user who rearranged and removed Blocks, when they sign in on another device, they see the same layout. On mobile, the same Blocks appear in the same order.

#### FR-47: Reset to default
A user can reset their dashboard to the admin's current Default Layout.

#### FR-48: Personal filter values
A user's last Dashboard Filter selections are remembered per dashboard. Filters can never widen the user's Data Scope.

#### FR-49: Block-level personalisation beyond layout
Whether users can change Block settings, such as chart type or top-N, is **[OPEN] (O38)**. The MVP default is **[PROPOSED]**: layout only, with no changes to Block Configuration.

### 4.9 Data Refresh and Freshness

**Description.** Live refresh near **30 seconds** where the source supports it, with a configurable interval, was **[CONFIRMED]** for the original client and is retained as a platform capability. Each Block refreshes by re-fetching or re-using its Data View within its Freshness Interval. The mechanism is an **[ADR]** (addendum §D).

#### FR-50: Refresh behaviour
Each Block refreshes on its Freshness Interval:
- The default comes from its Data View.
- A per-Block override is allowed above a system minimum (O24).
- The page does not need to be reloaded.

**Live is bounded by the source.** An API that updates hourly cannot produce minute-fresh data. The admin records the source's update frequency on the Data View, and Blocks then show the effective freshness. The end-to-end target is **TBD**.

#### FR-51: Stale and connection states
Allowed staleness is **TBD** [PROPOSED default: 2 × Freshness Interval].
- A Stale Block shows the last value, greyed, with "Stale: last data \<time\>". It never shows zero.
- On a browser disconnect, a "Reconnecting…" indicator appears within one Freshness Interval, and the Blocks refresh automatically once the connection recovers.

#### FR-52: Data-as-of transparency
Each Block shows its Data-as-of Time, distinct from "refreshed at". A dashboard shows the oldest Data-as-of Time among its live Blocks.

#### FR-53: Missed-update recovery
After any interruption, the next successful refresh returns the current state. A Block never silently shows a mix of results from different moments [PROPOSED].

### 4.10 Snapshots and History

**Description.** In v1, history was **[CONFIRMED]** as necessary because the RMG client had none. In v2 history is a **configurable** platform capability, because some domains forbid persisting data. Healthcare and FinTech data may be sensitive.

#### FR-54: Configurable Snapshot capture
For each Data View or Metric, the admin enables or disables Snapshot capture and sets the grain (for example hourly or daily). Snapshots record the version used, and the numerator and denominator for ratios.
- The default is **off** for Data Views marked sensitive [PROPOSED].
- Retention is **TBD (O8)**.

#### FR-55: Trends and comparisons
When Snapshots exist, Blocks can show trends and comparisons such as previous period, period to date, and same period last month. Before enough history exists, they show "Not enough history (since \<date\>)", not zero.
- Blocks can also show history that the **API itself returns**, for example a time series, without Snapshots.

#### FR-56: History integrity
Snapshots keep the version in force when they were captured. Recalculating history under a new version is **O25**.

### 4.11 Audit

#### FR-57: Audit Events
The platform records Audit Events for these actions:
- creation, update, deletion or archival of API Data Sources, Data Views, Metrics, Blocks and Admin Dashboards;
- submit, approve, reject, publish, unpublish and restore;
- Role, Scope and audience changes;
- endpoint allowlist changes;
- sign-in success and failure;
- denied access;
- blocked outbound requests.

Each event records the user, time, action, object, and the before/after state or a diff.
- Audit Events are immutable. Audit retention is **TBD (O31)**.
- Personal Layout changes are **not** audited individually [PROPOSED].

#### FR-58: Audit review
A Platform Administrator can search, filter and export Audit Events.

### 4.12 Administration and Observability

#### FR-59: Platform settings and health view
A Platform Administrator configures:
- scope attributes (FR-3);
- the endpoint allowlist (FR-11);
- default and minimum Freshness Intervals;
- staleness thresholds;
- fetch limits;
- locale defaults.

A health view shows:
- endpoint health, latency and error rates;
- mapping failures;
- refresh lag;
- Snapshot capture status.

### 4.13 Domain Packs (example configurations, not core)

**Description.** Domain-specific concepts are **never core platform requirements** **[CONFIRMED]**. A **Domain Pack** is a set of example configurations that can be imported and exported: Data View templates (mappings and Transforms, assuming a sample API shape), Metrics, Blocks and an Admin Dashboard template. Domain Packs prove the platform is generic, and they speed up onboarding **[PROPOSED]**.

#### FR-60: Import and export configuration
A Dashboard Admin can export an Admin Dashboard with its Data Views, Metrics and Blocks as a portable package (without secrets), and import it into another environment or Organization. The admin then rebinds the endpoints and credentials.

**RMG example pack [PROPOSED; example only].** This carries v1's RMG content as configuration:
- example Metrics: production achievement, line efficiency (with documented variants such as overall vs on-standard), hourly output, WIP, downtime, DHU, rejection, manpower and productivity;
- the RMG glossary (SMV/SAM, DHU, WIP, NPT);
- data prerequisites.

The source is the domain research report. **None of this is core.** v1's open items O2–O5 (SMV and attendance availability, KPI ranking, definitions, quality data source) become **pack-level questions** for an RMG deployment, not platform blockers.

**Other packs** (HealthTech, FinTech, Healthcare, Retail) are **not** in the MVP. Which pack(s) ship first is **O35**.

## 5. Cross-Cutting Non-Functional Requirements

No number in this section has been invented.

| ID | Area | Requirement |
|---|---|---|
| NFR-1 | Freshness | Live target ≈ 30 s where the source supports it (CONFIRMED for the first client; retained). End-to-end and compliance values **TBD**, bounded by API update frequency and latency |
| NFR-2 | Dashboard load | Time to first meaningful render with N Blocks: **TBD**. Maximum Blocks per dashboard: **TBD** |
| NFR-3 | API fetch and transform | p95 fetch-plus-transform time; timeouts; maximum response size and pages: **TBD** |
| NFR-4 | Scale | Organizations, users, concurrent viewers, dashboards, Data Views, endpoints: **TBD (O9, O33)** |
| NFR-5 | Source protection | Per-endpoint rate limits and shared fetches (FR-12). Budgets agreed with each API owner: **TBD** |
| NFR-6 | Availability | Uptime, RPO/RTO: **TBD** |
| NFR-7 | Security | TLS everywhere. Encrypted secrets. Server-side authorization. **SSRF protection and endpoint allowlist (FR-11)**. No user scripts. API values escaped against XSS. CSRF protection. Rate limiting. Session management. Secrets never sent to browsers |
| NFR-8 | **Data protection and compliance** | Domain-agnostic means **regulated data is possible**: PHI in Healthcare/HealthTech, payment and financial data in FinTech. The applicable regimes (for example HIPAA, GDPR, PCI DSS) are **[OPEN] (O27)**. Default [PROPOSED]: the platform persists **only configuration, Snapshots where enabled, and short-lived caches**. Data Views can be marked sensitive, which disables Snapshots and shortens cache life |
| NFR-9 | Audit integrity | Append-only and tamper-evident [PROPOSED]. Retention **O31** |
| NFR-10 | Accessibility | WCAG 2.1 AA [PROPOSED; O17]. Drag-and-drop has a keyboard-accessible alternative for adding, removing and reordering Blocks. Status is never shown by colour alone |
| NFR-11 | Browsers | **TBD (O16)** |
| NFR-12 | Devices | Desktop and mobile web (CONFIRMED for the first client; retained). TV/kiosk **TBD (O10)** |
| NFR-13 | Observability | FR-59 metrics, logs and alerts |
| NFR-14 | Backup | Configuration, Personal Layouts and Snapshots are backed up. Retention **O8** |
| NFR-15 | Extensibility | New Block Types (FR-26) and future source types are added as modules. **No domain logic in the core** |
| NFR-16 | Time and locale | Store UTC and display in the user's or organization's time zone. Locale-aware formatting. Defaults **O36** |

## 6. Integration and Dependencies

| Dependency | Status | Notes |
|---|---|---|
| The deploying organization's REST APIs | **[CONFIRMED]** the sole MVP source | API shape, auth, update frequency and rate limits differ per deployment. Assessment of the first deployment's APIs: **O11** |
| Identity provider (SSO) | **[OPEN] (O19)** | |
| First deployment / first Domain Pack | **[OPEN] (O35)** | v1 assumed the RMG client. Confirm whether that is still the first deployment |
| Database, replica, file, streaming sources | Deferred **[CONFIRMED]** | Designed for as future modules |

## 7. Data Governance

- Configuration objects (Data View, Metric, Block, Admin Dashboard) are versioned, approved and audited.
- Every Metric has an owner, a target and a Direction (FR-20).
- The source APIs stay the system of record. The platform never writes to them.
- Persisted data is minimised (NFR-8). Retention of Snapshots, audit records and logs: **O8, O31**.

## 8. Stakeholders and Approvals

| Stage | Signs off | Status |
|---|---|---|
| PRD v2 scope | Product owner (Nahidul) + client sponsor | **[OPEN] (O28)** |
| First deployment and Domain Pack | Client | O35 |
| UX and builder approach | After `bmad-ux` | |

## 9. Risks and Mitigations

| Risk | Impact | Likelihood | Mitigation | Linked |
|---|---|---|---|---|
| SSRF or abuse through admin-entered URLs | High | Medium | Allowlist, network-range blocking, server-side calls only, audit (FR-11) | |
| Large or slow API responses overload the platform | High | Medium | Size and page limits, timeouts, shared fetches, caching (FR-10, FR-12) | O9 |
| API schema changes silently break dashboards | High | High | Mapping validation, Unavailable state, mapping-health alerts (FR-16) | |
| Per-user Data Scope cannot be enforced for some APIs | High | Medium | Forward scope to the API, or filter platform-side; unscoped dashboards flagged (FR-3) | O37 |
| Regulated data persisted without basis | High | Medium | Sensitive flag, Snapshots off by default, minimal persistence (NFR-8) | O27 |
| Personalisation conflicts with admin updates | Medium | Medium | Propagation rules, Mandatory Blocks, reset to default (FR-41, FR-44, FR-47) | |
| Wrong roll-ups from averaging percentages | High | Medium | Ratio-safe Aggregation Rule (FR-17) | |
| Transform engine grows into a scripting language | Medium | Medium | Declarative Transforms only; fixed function set (FR-14) | O21 |
| Domain creep back into the core | Medium | Medium | Domain Packs as configuration only; NFR-15 | |
| v1 decisions (RMG brief) and v2 scope diverge for stakeholders | Medium | High | §15 change log; brief v4 recommended | O35 |

## 10. Non-Goals (Explicit)

- **No general-purpose / ad-hoc BI**: no free-form exploration, no unrestricted queries, no analyst workspace **[CONFIRMED]**.
- **No domain-specific logic in the core.** RMG and other domains exist only as Domain Packs **[CONFIRMED]**.
- **No user scripts, JavaScript or HTML** in Transforms, Blocks or Presentation Rules **[PROPOSED]**.
- **No writes to source APIs.** The platform reads only **[PROPOSED]**.
- **No non-API data sources in the MVP** (databases, replicas, files, streams) **[CONFIRMED]**.
- **No offline operation** **[CONFIRMED]**. PGlite is not used **[CONFIRMED]**.
- **No physical table per dashboard, Block, Metric or Data View** **[CONFIRMED]**.
- Not a native mobile app **[PROPOSED]**.

## 11. MVP Scope

### 11.1 In Scope
- Governed BI capabilities (§1.1)
- Features 4.1–4.12; conditional FR-5 (O7)
- Domain Pack import/export (FR-60), with **one** example pack (O35) **[PROPOSED]**
- Desktop and mobile web

### 11.2 Out of Scope for MVP

| Item | Status |
|---|---|
| Non-API sources (DB, replica, CSV/Excel, streaming, IoT) | Deferred **[CONFIRMED]** |
| KPI threshold alerts; email/push notifications | Deferred **(O10)**. Workflow notifications are in scope (FR-38) |
| Exports, print, scheduled reports | Deferred **(O10)** |
| TV/kiosk mode | **TBD (O10)** |
| Public links, embedding | Deferred |
| Additional Domain Packs beyond the first | Deferred (O35) |
| Block-level personalisation by End Users | **O38** |
| Map, heatmap and image Blocks | Deferred |
| Native mobile, AI / natural-language dashboard creation, Block marketplace | Future |

## 12. Success Metrics

**Primary**
- **SM-1. Developer-free dashboards.** Share of new or changed dashboards over existing APIs published with no code change. Target **TBD**. Validates FR-7 to FR-24, FR-28 to FR-36.
- **SM-2. Time to first dashboard.** Time from registering an endpoint to an approved, published dashboard. Target **TBD**. Validates FR-7, FR-13 to FR-15, FR-38.
- **SM-3. Freshness compliance.** Share of time live Blocks are within their Freshness Interval, for sources that support it. Target **TBD**. Validates FR-50, FR-51.

**Secondary**
- **SM-4. Personalisation adoption.** Share of active End Users with a customised Personal Layout. Target **TBD**. Validates FR-43 to FR-46.
- **SM-5. Domain reuse.** Number of distinct Domains configured without core code changes. Target **TBD**. Validates NFR-15, FR-60.
- **SM-6. Access integrity.** Zero confirmed cases of data shown outside a user's Data Scope. Validates FR-3, FR-27.

**Counter-metrics (do not optimise)**
- **SM-C1. Block and dashboard count.** More is not better: watch for duplicates and clutter. Counterbalances SM-1.
- **SM-C2. Refresh rate.** Faster than the source updates only adds load. Counterbalances SM-3.
- **SM-C3. Approval speed.** Rubber-stamp approvals defeat governance. Counterbalances SM-2.

## 13. Open Questions

**Retained or generalised from v1**

| ID | Question | Affects |
|---|---|---|
| O1 | Update frequency of each source API (generalised from the v1 data-capture question) | FR-50, NFR-1 |
| O6 | Role matrix: who registers endpoints, builds Data Views, Metrics and Blocks, approves and publishes; self-approval; custom Roles | §4.1, FR-7, FR-39 |
| O7 | Can End Users be external to the deploying organization? | FR-5 |
| O8 | Snapshot grain defaults, retention, backfill | FR-54, NFR-14 |
| O9 | Scale | NFR-2 to NFR-4 |
| O10 | TV/kiosk mode; exports; alerts | §11.2 |
| O11 | Technical assessment of the first deployment's APIs: shape, auth, rate limits, update frequency, scope support. Generalised from the v1 RMG database assessment | §6, FR-3 |
| O12 | Same or different Approvers for configuration types? | FR-39 |
| O16 | Browser baseline | NFR-11 |
| O17 | Accessibility standard | NFR-10 |
| O19 | Authentication: local accounts or SSO | FR-1 |
| O21 | Calculated-field function set | FR-14 |
| O22 | Partial missing data: exclude rows with a badge, or mark the whole value Unavailable? | FR-16 |
| O24 | Freshness configuration level and the system minimum | FR-50 |
| O25 | Recalculate history under a new version? | FR-56 |
| O27 | Applicable data-protection and compliance regimes per domain (HIPAA, GDPR, PCI DSS…) | NFR-8 |
| O28 | Who signs off PRD v2? | §8 |
| O29 | If architecture selects an embedded or hybrid BI option, is a third-party component acceptable? | Addendum §J |
| O31 | Audit and log retention | FR-57 |
| O32 | Email for workflow notifications? | FR-38 |

**New in v2**

| ID | Question | Affects |
|---|---|---|
| O33 | Deployment model: one Organization per deployment, or a multi-tenant SaaS serving many organizations and domains? (v1 non-goal: multi-tenancy) | NFR-4, §3 |
| O34 | Supported API auth types and HTTP methods (POST for read-only query APIs?) | FR-7 |
| O35 | First deployment and first Domain Pack: is the RMG client still the first customer? | §4.13, §6 |
| O36 | Locale, currency and time-zone defaults | FR-23, NFR-16 |
| O37 | Scope enforcement per Data View: platform filter, forwarded to the API, or both? | FR-3 |
| O38 | May End Users change Block settings (chart type, top-N) or add the same Block twice? | FR-44, FR-49 |

**Closed or moved in v2**

| ID | Resolution |
|---|---|
| O14 | Personalisation is **CONFIRMED** (§4.8) |
| O15 | Propagation is **CONFIRMED** (FR-41) |
| O2, O3, O4, O5 | Moved to the RMG Domain Pack as pack-level questions (§4.13) |
| O18 | Generalised into O36 |
| O20 | Covered by FR-27 (unscoped dashboards) |
| O23 | No SQL sources; Transforms are declarative (FR-14) |
| O26 | Replaced by configurable scope attributes (FR-3) |
| O30 | Replaced by configurable scope attributes (FR-3) |

**Phase gating**

| Phase | Blocking open items |
|---|---|
| `bmad-ux` | O6, O22, O38 |
| `bmad-architecture` (incl. build-vs-embed, addendum §J) | O1, O6, O7, O9, O11, O19, O24, O27, O33, O34, O37 |
| `bmad-create-epics-and-stories` | O6, O33, O35 |
| Sign-off | O28 |
| Non-blocking | O8, O10, O12, O16, O17, O21, O25, O29, O31, O32, O36 |

## 14. Assumptions Index

- §3, Data-as-of Time: uses a mapped timestamp Field if one exists, otherwise the fetch time [PROPOSED].
- FR-7: GET-only by default.
- §6: the first deployment may still be the RMG client (O35).
- §1.1: the governed-BI positioning is delivery-team direction.

## 15. Change Log: v1 → v2

| v1 position | v2 position | Reason |
|---|---|---|
| RMG-centric platform for one RMG client | **Domain-agnostic** platform; RMG is an example Domain Pack | Change signal from Nahidul, 2026-10-02 [CONFIRMED] |
| Data source: RMG application database, API or replica | **REST API only** in the MVP | [CONFIRMED] |
| Datasets/Fields + SQL query engine | **API Data Mapper → Data View** (Mapping + declarative Transforms) | [CONFIRMED] |
| Business users author KPIs | **Dashboard Admin** authors Metrics; a separate author role is O6 | [CONFIRMED] two-area model |
| Viewer personalisation open (O14), default "no" | **Default Layout + Personal Layout**, Block Library on the right, Mandatory Blocks, propagation | [CONFIRMED] |
| Factory/Floor/Line Data Scope | Configurable scope attributes | Domain-agnostic |
| 9 RMG KPI templates, RMG glossary, SMV/attendance prerequisites | Moved to the RMG Domain Pack (§4.13) | [CONFIRMED] |
| Snapshots always from go-live | **Configurable** per Data View/Metric; off for sensitive data | Domain-agnostic data protection |
| Widget | **Block** | Terminology from the change signal |
| Approval before publish | **Retained** | [CONFIRMED] |
| Governed BI positioning, build-vs-embed evaluation | **Retained** | — |
