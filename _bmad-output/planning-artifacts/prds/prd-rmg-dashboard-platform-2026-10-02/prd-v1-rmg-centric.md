---
title: "PRD: RMG Dynamic Dashboard Platform"
status: final
created: 2026-10-02
updated: 2026-10-02
inputs:
  - ../../briefs/brief-rmg-dashboard-platform-2026-10-02/brief.md (v3)
  - ../../briefs/brief-rmg-dashboard-platform-2026-10-02/addendum.md
  - ../../research/domain-rmg-production-and-executive-kpis-2026-10-02/research.md
  - ../../discovery/client-session-2-kit.md
companion: addendum.md
---

# PRD: RMG Dynamic Dashboard Platform

*Working title; confirm with client.*

## 0. Document Purpose

This PRD defines **what** the RMG Dynamic Dashboard Platform must do for its first release. It is written for three audiences:

- the delivery team: PM, UX, architecture, development and QA;
- the client stakeholders who confirm scope;
- the downstream BMAD workflows (`bmad-ux`, `bmad-architecture`, `bmad-create-epics-and-stories`), which must be able to proceed without rediscovering requirements.

**How it is structured**

- Vocabulary is fixed by the Glossary (§3). Every other section uses those terms exactly.
- Features (§4) carry globally numbered requirements, FR-1 to FR-63. Each FR lists testable consequences, written in Given / When / Then form where that helps.
- Cross-cutting quality requirements are in §5. Integration, governance, risk, scope and metrics follow.
- **Capabilities only.** Technology options and implementation rationale live in [addendum.md](addendum.md).

**Status tags (kept from discovery)**

| Tag | Meaning |
|---|---|
| **[CONFIRMED]** | Client or business fact |
| **[ASSUMPTION]** | Working assumption, indexed in §14 |
| **[PROPOSED]** | Our recommendation, not yet client-confirmed |
| **[OPEN]** | Needs a client answer |
| **[ADR]** | Architectural decision pending in `bmad-architecture` |
| **TBD** | No value yet; never invented |
| **[DIRECTION: …]** | Delivery-team direction, consistent with but not confirmed by the client |
| **[NOTE FOR PM]** | A callout to revisit |

Compound forms such as [CONFIRMED preference] or [PROPOSED; reason] keep the meaning of their first word.

**Open items O1–O11** come from brief v3 §9. They are carried as TBD and are not resolved here. New PRD-level questions start at O12 (§13). **What blocks which phase** is summarised in the phase-gating table at the end of §13.

**Inputs.** Brief v3 and its addendum hold the decisions and their history. The domain research holds the 43-KPI catalogue, formula variants V1–V17 and the glossary sources. The Session 2 kit holds the capture sheets still to be completed.

## 1. Vision

The client is an RMG (ready-made garments) organization. Its executives, CXOs and clients need a trustworthy, current, management-level view of their factories. Today, every new view requires development work, there is no agreed set of KPIs, and there is no history to show trends.

The RMG Dynamic Dashboard Platform is **a dashboard-centric platform with governed BI capabilities** **[DIRECTION: delivery team, 2026-10-02]**. This is consistent with the client's **[CONFIRMED]** "dashboard-centric, not general BI" stance. It is metadata-driven and sits on top of the client's existing RMG application. It works in four layers:

1. **Administrators** connect the RMG data and curate trusted **Datasets**.
2. **Authorized business users** define **KPIs** using the organization's own approved formulas, with no code and no SQL. Each KPI has an owner, a target and a direction.
3. **Dashboard designers** assemble **Widgets** on a grid. Each dashboard goes through approval before it is published.
4. **Executives** open dashboards on desktop or mobile and see two kinds of numbers: live operational numbers (about 30 seconds old where the source data allows) and daily or period executive numbers. Every number shows how fresh its data is, and every number builds history from go-live.

Success means a new KPI or dashboard reaches executives **without a development ticket**, and every number on screen has an accountable definition. The platform grows with the RMG application: each new module (quality, HR, orders, shipment) unlocks more KPIs from the same catalogue. This intent is **[CONFIRMED]** (brief v3 §1 and §10).

### 1.1 Positioning: a dashboard-centric platform with governed BI capabilities

Business intelligence (BI) is a **core part of the platform, not an add-on**. Every BI capability is exposed through governed objects (Datasets, KPI Definitions, Widgets and Dashboards) and is controlled by Roles, Data Scope and Approval. There is no open analyst workspace.

| Governed BI capability | In the MVP | Where |
|---|---|---|
| Semantic layer: Datasets, Fields, Dimensions, Measures | Yes | §4.3 |
| Governed metrics: KPI Definitions with owner, target, Direction, versions | Yes | §4.4 |
| Calculated metrics (no-code formulas) | Yes | FR-15 to FR-20 |
| Aggregation, including ratio-safe roll-ups | Yes | FR-13, FR-17 |
| Filtering and grouping | Yes | FR-25 to FR-28, FR-40 |
| Drill-down (Factory → Floor → Line) | Yes | FR-30, FR-48 |
| Trends and period comparisons | Yes (from go-live) | §4.11 |
| Visualization | Yes | §4.6 |
| Row- and field-level security | Yes | FR-3, FR-4, FR-28 |
| Live and periodic data | Yes | §4.10 |
| Exports, scheduled reports, alerts | Deferred | §11.2 |
| **General-purpose ad-hoc BI** (free-form exploration, unrestricted SQL, full analyst workspace) | **No** (non-goal) | §10 |

**Boundary rule.** General-purpose ad-hoc BI stays outside the MVP unless either of these identifies a clear requirement for it: the architecture build-vs-embed evaluation (addendum §J), or a later client discussion. It is **not** raised with the client as a new requirement at this stage.

**How it will be built.** Whether these capabilities come from a custom-built governed BI/query layer, an embedded BI engine, or a hybrid is an **[ADR]** for `bmad-architecture` (addendum §J).

## 2. Target Users

### 2.1 Jobs To Be Done

- **Executive / CXO.** "Tell me at a glance how my factories are doing today and over time, and where to intervene. I need to trust the numbers without asking who calculated them." **[CONFIRMED]** primary consumer.
- **Client (user group).** "Let me see the performance relevant to me." Whether this means internal management or external buyers is **[OPEN] (O7)**.
- **KPI author (authorized business user).** "Let me define and change KPIs using our approved definitions, without waiting for IT." **[CONFIRMED]**
- **Dashboard designer.** "Let me arrange the right KPIs for each audience and publish them safely." Capability **[CONFIRMED]**; who holds this role is **[OPEN] (O6)**.
- **Approver.** "Let me make sure nothing reaches executives without review." Approval **[CONFIRMED]**; who approves is **[OPEN] (O6)**.
- **Data administrator.** "Let me expose trusted RMG data safely, without overloading the production system." **[CONFIRMED]**
- **System administrator.** "Let me control who sees which factories and what they can change." **[CONFIRMED]**
- **Factory / line manager, shop-floor display.** "Show me live line status during the shift." **[ASSUMPTION]** secondary audience. TV/kiosk mode is **TBD (O10)**.

### 2.2 Non-Users (v1)

- Analysts who need free-form, ad-hoc BI exploration. The platform is **dashboard-centric with governed BI capabilities**, not a general-purpose BI tool **[CONFIRMED]** (§1.1).
- Anonymous or public viewers. No public links **[PROPOSED]**; see §11.
- Offline users **[CONFIRMED]**: offline operation is not required.
- External buyers: **undecided (O7)**. Data Scope is designed from the start to support buyer-level scoping, so a "yes" on O7 adds configuration, not re-architecture (FR-3, FR-5).

### 2.3 Key User Journeys

Names are illustrative.

- **UJ-1. Farzana, COO, checks the group's day on her phone before a buyer call.**
  - **Persona and context:** Farzana is the COO of the RMG group. She has a 9:30 call with a key buyer and wants to know whether production is on track.
  - **Entry state:** already signed in on mobile web; she opens the Published "Group Production Today" Dashboard.
  - **Path:**
    1. She sees KPI cards for Production Achievement, Line Efficiency and DHU by factory. Each card shows "data as of 09:12:40".
    2. She taps Factory 2, which is red on achievement.
    3. The Dashboard filters to Factory 2's lines. One line shows high Downtime.
  - **Climax:** within a minute she knows Factory 2 is behind target because of downtime on one line. She knows the numbers are current, and each KPI's definition and owner are one tap away.
  - **Resolution:** she calls the factory manager. The Dashboard keeps refreshing while it is open.
  - **Edge case:** Line Efficiency shows **"Unavailable: SMV data missing"** for Factory 3. She sees this as a data gap, not as zero.
  - Realizes FR-19, FR-22, FR-28, FR-30, FR-33, FR-46, FR-50, FR-52.

- **UJ-2. Karim, production MIS lead, defines "Line Efficiency" using the company's approved formula.**
  - **Persona and context:** Karim owns production reporting. Management has approved the company's efficiency definition: "overall, operators + helpers, QC-passed output".
  - **Entry state:** signed in with the KPI author role. Opens the KPI library.
  - **Path:**
    1. Selects "New KPI from template → Line Efficiency".
    2. Chooses the variants: overall vs on-standard, manpower scope, output basis.
    3. Binds the inputs to fields of the Production and Attendance datasets.
    4. Sets owner, target 65% and "higher is better".
    5. Previews the values for last week's lines.
    6. Submits the KPI for approval.
  - **Climax:** the preview shows plausible values per line. The formula is stored as readable metadata, and nothing was coded.
  - **Resolution:** the KPI is "Pending approval". Karim is told when it is approved.
  - **Edge case:** the Attendance Dataset is not registered yet. The KPI saves and can go through approval. Once published it shows **"Unavailable: Attendance missing"** until the data exists (FR-19).
  - Realizes FR-15 to FR-21, FR-42 and FR-63.

- **UJ-3. Nadia, head of IE, approves a KPI change.**
  - **Path:** Nadia gets an approval request. She sees the diff between Line Efficiency v1 and v2 (the output basis changed), a preview, and the list of Dashboards affected. She approves.
  - **Climax:** v2 becomes current. The Dashboards that bind to it follow the configured binding behaviour (O15).
  - Realizes FR-21, FR-34 and FR-43.

- **UJ-4. Imran, dashboard designer, builds "Factory Today" and publishes it.**
  - **Path:**
    1. Creates a Dashboard from blank.
    2. Drags in KPI cards, an hourly-production line chart, a WIP-by-line table and a Downtime bar chart.
    3. Resizes and arranges them.
    4. Adds Factory, Floor and Line filters.
    5. Previews it as an executive would on mobile.
    6. Saves a draft and submits it for approval.
  - **Climax:** after approval it is published to the "Executives" audience.
  - **Edge case:** he tries to add a Widget bound to a Dataset he has no access to. The Dataset does not appear in his picker. A direct API attempt is denied and audited.
  - Realizes FR-4, FR-31, FR-32, FR-36 to FR-40, FR-42 and FR-47.

- **UJ-5. Sadia, data administrator, registers the RMG production data.**
  - **Path:**
    1. Registers the RMG application as a Data Source, using the approved access method (API or read replica, O11).
    2. Tests the connection.
    3. Discovers the available entities.
    4. Creates the "Production" Dataset with fields: Factory, Floor, Line, Style, Hour, Output Qty, Target Qty.
    5. Marks each field as a Dimension or a Measure, sets the allowed aggregations, and sets the Data Scope field (Factory).
  - **Climax:** KPI authors can now use Production fields without seeing credentials or raw tables.
  - Realizes FR-8 to FR-14.

- **UJ-6. Rafiq, Factory 2 manager, watches live line status on a desktop. [ASSUMPTION: secondary audience]**
  - **Path:** opens "Factory Today". Hourly Production and WIP refresh within their Freshness Interval. Then the network drops.
  - **Climax:** within one refresh interval, the Widgets show **"Stale: last data 10:42:10"** and a reconnecting indicator. They never show zeros.
  - **Resolution:** on reconnect, the Widgets catch up without a manual page reload.
  - Realizes FR-50, FR-51, FR-52 and FR-53.

## 3. Glossary

FRs, UJs and success metrics use these terms verbatim.

**Data and definitions**
- **Governed BI.** Business-intelligence capabilities (semantic layer, governed metrics, aggregation, filtering, grouping, drill-down, trends, visualization) delivered only through governed platform objects, under Roles, Data Scope and Approval. The opposite of ad-hoc BI.
- **Data Source.** A registered connection to a system that holds data. In the MVP this is the client's RMG application. Connection details and credentials are visible only to data administrators.
- **Dataset.** A curated, named view over one Data Source entity or query. It exposes **Fields** and carries a **Data Scope** mapping. One Data Source has many Datasets.
- **Field.** A named, typed column of a Dataset. Its role is either **Dimension** (used to group or filter, for example, Factory, Line, Hour) or **Measure** (numeric, aggregatable, for example, Output Qty). A Field may be restricted to certain Roles.
- **KPI Definition** (or **KPI**). A governed metric. It has:
  - a formula over Fields;
  - a Variant choice;
  - unit, owner, target and Direction;
  - an Aggregation Rule;
  - a Refresh Class;
  - Data Prerequisites.

  A KPI may be reused by many Widgets.
- **KPI Version.** An immutable revision of a KPI Definition. Exactly one version is **current**.
- **KPI Template.** A pre-built starting point for a common RMG KPI (from the research catalogue). It lists the known **Variants**. It is not a KPI until a KPI author instantiates and approves it.
- **Variant.** One of the documented alternative formulas for the same KPI concept (research V1–V17, for example, overall vs on-standard efficiency). The client selects one per KPI.
- **Direction.** Whether higher or lower values are better. Drives status colouring against the target.
- **Aggregation Rule.** How a KPI rolls up across Dimensions, for example, Line → Floor → Factory → Group. For ratio KPIs it is **sum of numerators ÷ sum of denominators**, never an average of percentages.
- **Data Prerequisite.** A Dataset or Field that a KPI needs, for example, SMV, Attendance. If any prerequisite is missing, the KPI is **Unavailable**.

**RMG domain terms** (definitions and sources: research §6)
- **SMV / SAM.** Standard minute value / standard allowed minutes: the standard time for an operation or garment. Some factories treat the two terms as the same; others treat SAM as SMV plus allowances (V1).
- **Line / Floor / Factory.** The production hierarchy used for Data Scope and drill-down.
- **Helper.** A non-machine line worker. Whether helpers count as manpower is a Variant choice (V3).
- **Overall vs on-standard efficiency.** Efficiency with vs without lost and off-standard time in the denominator (V2).
- **Defect vs defective.** A non-conformance vs a garment with one or more defects. **DHU** = defects ÷ pieces checked × 100; **defective %** = defective pieces ÷ checked × 100 (V6).
- **Rejection.** A garment that cannot be repaired; the base for the rate is a Variant (V7).
- **WIP.** Work in progress: input minus output per section or line.
- **NPT / downtime.** Non-productive time while operators are present, coded by reason.

**Queries and freshness**
- **Query Definition.** A structured, validated description of what to retrieve: Dataset, Dimensions, Measures or KPIs, Filters, sort, limit. It is never raw SQL from a business user.
- **Query Engine.** The server-side component that validates Query Definitions, applies Data Scope and executes them safely.
- **Refresh Class.** **Live** (target interval ≈ 30 s, only where the source supports it) or **Periodic** (daily, per shift, or per period).
- **Freshness Interval.** The configured target refresh interval for a Widget or KPI.
- **Data-as-of Time.** The timestamp of the newest source data reflected in a value. This is different from the time the screen last refreshed.
- **Stale.** The state where Data-as-of Time is older than the allowed staleness for that Widget.
- **Unavailable.** The state where a value cannot be computed because a Data Prerequisite is missing. It is shown explicitly, never as zero.

**Widgets and dashboards**
- **Widget Type.** A kind of visual component, for example, KPI card, line chart, table. It declares a configuration schema and a data shape.
- **Widget.** An instance of a Widget Type placed on a Dashboard. It holds a **Widget Configuration** and a **Data Binding**.
- **Data Binding.** The link from a Widget to one Dataset, plus optionally one or more KPIs computed over that Dataset, and its Query Definition.
- **Dashboard.** A named arrangement of Widgets in a **Layout**, with Dashboard Filters, an audience and a lifecycle state.
- **Layout.** The grid positions and sizes of Widgets, with responsive variants for desktop and mobile.
- **Dashboard Filter / Widget Filter.** A user-selectable constraint (for example, Factory, Date) applied to all Widgets on a Dashboard, or to one Widget.
- **Dashboard Version.** An immutable snapshot of a Dashboard's Layout, Widgets and Filters at save or publish time.

**Lifecycle and governance**
- **Lifecycle State.** One of Draft, In Review, Approved, Published, Unpublished, Archived. It applies to both Dashboards and KPI Definitions.
- **Approval.** A recorded decision by an Approver that moves an item from In Review to Approved (or rejects it).
- **Snapshot.** A stored, timestamped KPI value at a defined grain (for example, per hour, per line). Snapshots are captured from go-live to provide history.
- **Data Scope.** The slice of data a user may see, expressed over Organization / Factory / Floor / Line (and Buyer, if O7 requires it).
- **Role.** A named set of Permissions. **Permission** is an allowed action on an object type.
- **Audit Event.** An immutable record of who did what, when, to which object, and its before and after state where applicable.

## 4. Features

### 4.1 Identity, Roles and Data Scope

**Description.** Users authenticate and act through Roles. Data Scope limits which organizations, factories, floors and lines they see. The MVP role set is listed below; the exact role-to-permission matrix is **[OPEN] (O6)**. Executives are primarily consumers **[CONFIRMED]**.

| Role | Status |
|---|---|
| System Administrator | [PROPOSED] |
| Data Administrator | [PROPOSED] |
| KPI Author | [PROPOSED] |
| Dashboard Designer | [PROPOSED] |
| Approver | [PROPOSED] |
| Viewer (Executive / Client) | [PROPOSED] |

#### FR-1: Authentication
Users can sign in to access the platform. Unauthenticated users cannot reach any dashboard, API or data.
- Given an unauthenticated request to any page or API, then the system rejects it and redirects to sign-in (or returns 401 for APIs).
- Sessions expire after an idle period. The idle period is **TBD**.
- Authentication method (local accounts vs SSO with the RMG application) is **[OPEN] (O19)**. [ASSUMPTION: the platform may need to reuse RMG application identities.]

#### FR-2: Role-based permissions
A System Administrator can assign one or more Roles to a user. Each Role grants Permissions on object types: Data Source, Dataset, KPI, Widget, Dashboard, User, Role, Audit Log.
- Given a user without the needed Permission, when they attempt an action through the UI or the API, then the system denies it and records an Audit Event.
- Permissions are enforced server-side. Hiding something in the UI is not enforcement.
- The MVP ships the predefined Roles in §4.1. Whether administrators can create custom Roles is **[OPEN] (O6)** [PROPOSED: predefined Roles only in the MVP].

#### FR-3: Data Scope assignment
A System Administrator can assign each user a Data Scope at Organization / Factory / Floor / Line level.
- Given a user scoped to Factory 2, when any query runs on their behalf, then results contain only Factory 2 rows. This holds regardless of the Dashboard Filters chosen.
- Data Scope is modelled as configurable dimensions: Organization, Factory, Floor and Line in the MVP. Department, Business Unit and Buyer can be added without re-architecture. Which extra dimensions are needed is **[OPEN] (O30)**.
- Aggregates shown to a scoped user (for example, "Group total") are computed **only over their scope** [PROPOSED], unless the Dashboard explicitly declares an unscoped aggregate. Whether that is allowed is **[OPEN] (O20)**.

#### FR-4: Dataset and Field access control
A Data Administrator can restrict a Dataset, or individual Fields, to specific Roles.
- Given a user without access to a Dataset, when they configure a Widget or KPI, then the Dataset is absent from pickers. Direct API use is denied (realizes UJ-4 edge case).
- Given a restricted Field, when a permitted query includes it for an unauthorized user, then the query is rejected. It is not silently dropped.

#### FR-5: External client isolation (conditional on O7)
If "clients" are confirmed as external buyers, they see only data tagged to their buyer and only Dashboards shared with them.
- **TBD pending O7.** If O7 is answered "internal only", this FR is removed.

#### FR-6: Self-service profile
Users can view their own Roles, Data Scope and recent activity.

#### FR-7: User administration
A System Administrator can create, deactivate and edit users, and assign Roles and Data Scope. Every change emits an Audit Event (§4.12).

### 4.2 Data Source Management

**Description.** Data Administrators register the systems the platform reads from. The MVP source is the **client's RMG application** **[CONFIRMED]**. The client prefers **API or read-replica** access; isolated direct database access is acceptable if necessary **[CONFIRMED preference]**. The final method is **[ADR]**, after the RMG technical assessment (**O11**). Further source types (CSV, other databases, event streams) are designed for but not built in the MVP **[PROPOSED]**.

#### FR-8: Register a Data Source
A Data Administrator can register a Data Source with a name, type, connection settings and credentials.
- Credentials are stored encrypted. They are never returned to the browser or any API caller after saving; only a masked indicator is shown.
- Only Data Administrators can view or edit connection settings.

#### FR-9: Test connection and health
A Data Administrator can test a Data Source. The system continuously reports source health: healthy, degraded or unreachable, with the last successful read time.
- When a Data Source becomes unreachable, the Widgets that depend on it show Stale (FR-51), not errors or zeros.

#### FR-10: Schema / entity discovery
A Data Administrator can list the entities (tables, views or API resources) and their attributes that the Data Source exposes, as the basis for Datasets.

#### FR-11: Read-only, load-protected access
The platform only reads from the RMG application. It never writes to it.
- Each query against the source runs with an enforced timeout and row limit (FR-27).
- The platform's total load on the source stays within a budget: **TBD**, to be set with the client's IT in O11.

### 4.3 Dataset and Field Management

**Description.** Datasets are the governed vocabulary that KPI authors and designers build from. They decouple Widgets and KPIs from physical tables and APIs **[CONFIRMED principle: metadata-driven, no table per component]**.

#### FR-12: Create a Dataset
A Data Administrator can create a Dataset from a Data Source entity or a source-side view. They name it, describe it, and select the Fields to expose.
- Creating a Dataset never creates a physical table in the source [CONFIRMED].

#### FR-13: Define Fields
For each Field, a Data Administrator sets:
- display name;
- type;
- role (Dimension or Measure);
- allowed aggregations for Measures;
- description;
- the Data Scope mapping (which Field represents Factory, Floor or Line);
- the time Field used for date filtering and hourly grouping;
- the **source capture frequency** of the Dataset (for example, per bundle, hourly, end of shift). It is recorded from O1 and used by FR-50 to bound the effective freshness.

Checks:
- Given a Measure with allowed aggregations {SUM}, when a KPI or Widget requests AVG on it, then validation fails with a clear message.

#### FR-14: Dataset versioning and impact
Changes to a Dataset are versioned. Before saving a change that removes or retypes a Field, the system shows which KPIs and Widgets depend on it.
- Given a Field used by a published KPI, when an administrator attempts to remove it, then the system blocks the change, or requires the dependent KPIs to be updated first. Which of the two is **[PROPOSED: block]**.

### 4.4 KPI Definition Management

**Description.** This is the core of "dynamic". **Authorized business users create and configure KPIs** **[CONFIRMED]**. KPIs follow the **organization's approved definitions**, and the client chooses among industry Variants **[CONFIRMED]**. Each KPI carries an owner, a target, a calculation method and a Direction **[CONFIRMED]**.

KPIs whose Data Prerequisites are missing are **Unavailable** until the data exists **[CONFIRMED]**. The platform ships **KPI Templates** for the MVP priority KPIs **[PROPOSED]**; see §4.15. Realizes UJ-2.

#### FR-15: Create a KPI with the no-code formula builder
A KPI Author can create a KPI by building a formula from Dataset Measures, the aggregations those Measures allow, arithmetic operators, constants and a fixed function set. **No SQL and no code are involved.**
- The formula is stored as structured metadata (a parse tree), not as text that gets executed.
- The function set is **[PROPOSED]**: SUM, COUNT, MIN, MAX, AVG where allowed, safe division, IF on comparisons, ROUND. The final list is **[OPEN] (O21)**.
- Given a formula that references a Field the author cannot access, then saving fails.

#### FR-16: KPI metadata
Each KPI records:
- name and description;
- unit (%, pcs, minutes, ratio);
- **owner** (a user);
- **target** (a value, or a value per Dimension member such as per line);
- **Direction**;
- **Variant** choice and the reason for it;
- **Aggregation Rule**;
- **Refresh Class**;
- display precision.

Checks:
- A KPI cannot be submitted for approval without an owner, a target, a Direction and an Aggregation Rule. Every KPI has a target **[CONFIRMED]**; the target value may be TBD while the KPI is in Draft.

#### FR-17: Ratio-safe aggregation
For ratio KPIs (efficiency, DHU, achievement, rejection), the system rolls values up across Dimensions by **summing numerators and denominators separately**, according to the Aggregation Rule.
- Given line A (produced 600 min / attended 1,000 min = 60%) and line B (produced 450 / attended 500 = 90%), when Factory Efficiency is shown, then it displays **70.0%** ((600 + 450) ÷ (1,000 + 500)), not 75.0% (the mean of 60% and 90%) (research V5).

#### FR-18: Variant selection from templates
When creating a KPI from a KPI Template, the KPI Author must choose one of the documented Variants, or write a custom formula. The choice is recorded and shown in the KPI's definition panel.
- Example: "Line Efficiency" offers three choices. Each choice changes the formula preview immediately:
  - overall vs on-standard (V2);
  - operators vs operators + helpers (V3);
  - QC-passed vs all output (V4).

#### FR-19: Data Prerequisites and Unavailable state
Each KPI declares its Data Prerequisites, derived automatically from the Fields and Datasets it uses, plus any declared manually (for example, "SMV per style must be present").
- Given a missing prerequisite (for example, no SMV Field registered, or SMV null for the style), when the KPI is displayed, then the value shows **"Unavailable: \<prerequisite\> missing"** for the affected rows or the whole KPI. It never shows 0 [CONFIRMED].
- A KPI whose prerequisites are not yet available **can be approved and published**. It then shows Unavailable until the data exists, so Dashboards can be laid out ahead of the data **[PROPOSED; consistent with the brief: "remain unavailable until the required data becomes available"]**.
- **Partial rule.** If prerequisites are missing for only some rows (for example, SMV missing for some styles), whether the KPI excludes those rows (with a visible "partial" badge) or shows Unavailable is **[OPEN] (O22)**.

#### FR-20: Preview and validation
Before submitting, a KPI Author can preview the KPI's values over a chosen date range and Data Scope.
- Validation runs before submission:
  - type checks;
  - division-by-zero handling;
  - a check that each aggregation is allowed for its Field;
  - a check that the Aggregation Rule is consistent with the formula.

#### FR-21: KPI versioning
Saving a change to an Approved or Published KPI creates a new KPI Version in Draft. The previous version stays current until the new one is published.
- Every version keeps its formula, metadata, author, approver and timestamps, and is viewable.

#### FR-22: KPI definition transparency for viewers
Any viewer can open a KPI's definition panel from a Widget. The panel shows the plain-language formula, Variant, owner, target, Direction, Refresh Class, Data-as-of Time and current version (realizes UJ-1).

#### FR-23: KPI library
Users can browse and search KPIs they have access to, by name, owner, Lifecycle State and Dataset. Each KPI shows where it is used.

#### FR-24: Retire a KPI
A KPI Author can request that a KPI be archived. A KPI used by Published Dashboards cannot be archived until those Dashboards stop using it, or are themselves archived.

### 4.5 Query Engine and Query Safety

**Description.** All data retrieval goes through the server-side Query Engine. It turns Query Definitions into safe source queries.

**Who may do what:**

| Action | Who | Status |
|---|---|---|
| Create or modify **Datasets** | Data Administrators | [PROPOSED] |
| Create or modify **KPIs** | KPI Authors | [CONFIRMED] |
| Create or modify **Widget Data Bindings** | Dashboard Designers | [PROPOSED] |
| **Execute** queries | Any authenticated user, through Widgets they may view, within their Data Scope | [PROPOSED] |
| Raw SQL | **Not available to business users** [CONFIRMED]. Whether Data Administrators may use raw SQL when defining a Dataset is **[OPEN] (O23)** | — |

#### FR-25: Structured queries only
Widgets and KPIs retrieve data only through Query Definitions: Dataset, Dimensions, Measures or KPIs, Filters, sort, limit, time grain.
- No API accepts SQL text from business users. Whether Data Administrators may supply SQL for Datasets is O23.

#### FR-26: Validation and parameterization
The Query Engine validates every Query Definition before execution:
- Fields exist and are permitted;
- the aggregations are allowed;
- filter values match the Field type;
- the date range is within the allowed bounds.

All user-supplied values are passed as bound parameters. Identifiers come only from Dataset metadata, never from user input.
- Given a filter value containing SQL syntax, then it is treated as a literal value and cannot change the query structure.

#### FR-27: Cost protection
Every query has the following limits:
- execution timeout: **TBD**;
- maximum rows returned: **TBD**;
- maximum date range per Refresh Class: **TBD**;
- maximum group-by cardinality: **TBD**.

Checks:
- Queries that exceed a limit are rejected or truncated with a visible message, never left hanging.
- Concurrent query load per user and system-wide is limited: **TBD**.

#### FR-28: Data Scope enforcement
The Query Engine adds the user's Data Scope constraints to every query, regardless of the Widget configuration (realizes FR-3). The only exception is an unscoped aggregate explicitly declared and approved on the Dashboard, if O20 allows it.

#### FR-29: Shared results and caching
Identical queries in the same Data Scope and freshness window may share one result [PROPOSED], so that many viewers of the same Dashboard do not multiply the load on the source.
- A cached result is never served to a user outside the Data Scope it was computed for.

#### FR-30: Drill-down
A user can drill from an aggregate (for example, Factory) to its children (Floor, then Line) on Widgets that enable it, within their Data Scope.

### 4.6 Widget Library and Configuration

**Description.** Widgets are reusable, typed components. Adding a new Widget Type must not require changing the Dashboard engine: each Widget Type declares its own metadata, configuration schema and expected data shape **[PROPOSED; mechanism ADR]**.

#### FR-31: MVP Widget Types
The MVP provides these Widget Types **[PROPOSED]**; the final list will be confirmed in UX:
- KPI card (value, target, status against Direction, trend sparkline when Snapshots exist);
- line chart, bar chart (and stacked bar);
- table / data grid with sorting and **pagination**;
- gauge / progress;
- status indicator;
- text;
- Dashboard Filter controls: Factory, Floor, Line and Date.

**Deferred:** map, heatmap, image, alert widgets.

#### FR-32: Configure a Widget
A Dashboard Designer can configure a Widget in a form generated from its Widget Type's schema. The form covers the Data Binding (KPI or Dataset), Dimensions, sort, top-N, thresholds and colours (per Direction), title, Freshness Interval (within the KPI's Refresh Class), and drill-down enablement.
- An invalid configuration cannot be saved; the error is shown inline.
- **Dynamic component example (from the original requirements).** A designer can create a "Production Performance" table Widget with these columns:
  - Factory, Line (Dimensions);
  - Production, Target (SUM Measures);
  - Efficiency (a KPI);
  - Updated At (the Data-as-of Time).

  It needs only Dataset, KPI and Widget configuration: no developer, no code change and no new physical table.
- Widget visibility follows Dashboard sharing (FR-47), and the data inside it follows Dataset/Field access (FR-4) and Data Scope (FR-28). There is no separate per-Widget permission model in the MVP [PROPOSED].
- No configuration path accepts code, scripts or raw HTML from users [PROPOSED non-goal].

#### FR-33: Widget states
Every Widget has a visible presentation for each of these states:
- **loading**;
- **empty** ("no data for the selected filters");
- **error** (with a non-technical message and a retry);
- **Stale** (FR-51);
- **Unavailable** (FR-19).

The Data-as-of Time is always visible, or one tap away on mobile.

#### FR-34: Reuse across Widgets
Many Widgets can bind to the same KPI or Dataset. Changing a KPI's current version affects all Widgets bound to it, according to the binding behaviour (O15).

#### FR-35: Extensible Widget Types
A developer can add a new Widget Type by providing its metadata, configuration schema, data-shape contract and renderer. No change is needed to the Dashboard Builder, Viewer or Query Engine [PROPOSED].
- Acceptance: adding a sample Widget Type in a test environment requires changes only inside the new Widget Type package.

### 4.7 Dashboard Builder

**Description.** A grid-based builder **[PROPOSED; the builder technology is an ADR after UX]** where designers add, move, resize and configure Widgets and set Dashboard Filters. The product needs **grid-based dashboard editing with business Widgets**. It does **not** need free-form page or HTML editing **[PROPOSED; confirm in UX]**. Realizes UJ-4.

#### FR-36: Create, duplicate, archive Dashboards
A Dashboard Designer can create a Dashboard (blank or from a template), duplicate an existing one, and request archival. New Dashboards start in **Draft**.
- Given an authorized designer, when they create a Dashboard, then it is saved as Draft and is invisible to viewers.

#### FR-37: Grid editing
A Dashboard Designer can add Widgets from the library, drag to reposition them, resize them on a grid, and remove them. The Layout is saved as metadata.
- Undo and redo are available during an editing session [PROPOSED].
- Concurrent editing: if two designers edit the same Draft, the second designer is warned about the conflict on saving. Last-write-wins without a warning is not allowed.

#### FR-38: Responsive Layouts
A Dashboard has a desktop Layout and a mobile Layout. The mobile Layout is derived automatically, and a designer can adjust it [PROPOSED].
- Given a Published Dashboard opened on a mobile browser, then Widgets stack in the mobile Layout order with no horizontal page scrolling.

#### FR-39: Preview as audience
A Dashboard Designer can preview a Draft as it will appear to a selected Role and Data Scope, on desktop and mobile, with live data.

#### FR-40: Dashboard Filters
A Dashboard Designer can add Dashboard Filters (Factory, Floor, Line, Date range, Shift [ASSUMPTION: shifts are defined, see O18]) and choose which Widgets each filter applies to. Widget Filters can narrow further.

#### FR-41: Dashboard templates
A Dashboard Designer can save a Dashboard as a template and create new Dashboards from templates [PROPOSED].

### 4.8 Lifecycle, Approval and Versioning (Dashboards and KPIs)

**Description.** **Publishing KPIs and Dashboards requires approval** **[CONFIRMED]**. Both follow this lifecycle:

**Draft → In Review → Approved → Published → (Unpublished) → Archived**

| From | Event | To |
|---|---|---|
| Draft | Submit | In Review |
| In Review | Withdraw (author) or Reject (Approver, with a comment) | Draft |
| In Review | Approve | Approved |
| Approved | Publish | Published (becomes the current version) |
| Published | Edit | A **new Draft version**; the Published version stays live |
| Published | Unpublish | Unpublished |
| Unpublished | Republish, with no content change | Published, no re-approval [PROPOSED] |
| Published / Unpublished / Draft | Archive | Archived (read-only) |

Who approves is **[OPEN] (O6)**. Realizes UJ-3.

#### FR-42: Submit for approval
The author or designer can submit a Draft for review. While an item is In Review, it is locked for editing. The author can withdraw it.
- Approvers see a **review queue** of the items awaiting them.
- Submitters and Approvers receive **in-app notifications** on submit, approve and reject [PROPOSED]. Email for these workflow notifications is **TBD (O32)**.
- These workflow notifications are separate from the KPI threshold alerts deferred in §11.2.

#### FR-43: Approve or reject
An Approver can approve or reject an item. Rejection requires a comment. The Approver sees:
- the **diff against the currently published version** (formula and metadata for a KPI; Widgets, Layout and Filters for a Dashboard);
- a preview;
- the **impact list** (for a KPI: the Dashboards that use it).

Checks:
- An author cannot approve their own submission [PROPOSED; confirm in O6].
- Approval and rejection emit Audit Events.

#### FR-44: Publish, unpublish, archive
Approved items can be published, by the Approver or the author (**[OPEN] (O6)**). Published Dashboards are visible to their audience. Unpublishing removes visibility but keeps history. Archiving makes an item read-only and hides it from libraries.

#### FR-45: Versions and rollback
Every publish creates an immutable version. An authorized user can view any previous version and **restore** it. Restoring creates a new Draft from the old version, which then goes through approval again [PROPOSED].
- Given a Published Dashboard v5, when a designer restores v3, then a Draft v6 that equals v3 is created, and v5 stays live until v6 is published.

### 4.9 Dashboard Viewing and Sharing

**Description.** Executives and other viewers open Published Dashboards that are shared with them, on desktop or mobile web **[CONFIRMED]**. Sharing is internal only. Public links and embedding are not in the MVP **[PROPOSED]**.

#### FR-46: View Published Dashboards
A Viewer can open any Published Dashboard shared with them, through their Role, a user grant or their Data Scope. Values reflect only their Data Scope.
- Given a Viewer without a grant, then the Dashboard is absent from their list, and a direct URL returns "not authorized".

#### FR-47: Share a Dashboard
A Dashboard Designer or an administrator can share a Published Dashboard with Roles, users, or Factory/Department groups. Sharing never widens a Viewer's Data Scope.
- Public, anonymous and external links are not available [PROPOSED; O7 may revisit for external clients].

#### FR-48: Apply filters and drill
A Viewer can change Dashboard Filters (within their Data Scope) and drill down (FR-30) without changing the Published Dashboard for others.

#### FR-49: Personal layout
Whether Viewers can personalise their own Layout (iGoogle-style) is **[OPEN] (O14)**. MVP default [PROPOSED]: Viewers cannot change the Layout. Personal filter selections are remembered per user.

### 4.10 Live and Periodic Data Refresh

**Description.** Refresh follows the data **[CONFIRMED]**:
- **Live** Refresh Class for operational KPIs: target about **30 seconds**, only where the source captures data that often;
- **Periodic** Refresh Class for executive KPIs: daily or per period.

The Freshness Interval is configurable **[CONFIRMED]**. Live KPIs are only truly live if source capture is frequent enough (**O1**). The delivery mechanism (polling, push, change events) is **[ADR]**.

#### FR-50: Refresh behaviour
Each Widget refreshes according to its Freshness Interval. The default comes from its KPI's Refresh Class: Live ≈ 30 s **[CONFIRMED target]**, Periodic as configured.
- **Configuration level [PROPOSED]:** KPI default, overridable per Widget within limits. A system-wide minimum interval protects the source. Final choice **[OPEN] (O24)**.
- **Acceptance (Live):** given a Published Dashboard with a Live Widget, and a source whose data for that Widget changes, then the Widget reflects the change within the configured Freshness Interval plus source-access latency (replica lag or API delay). The end-to-end target is **TBD**, pending O1 and O11. Compliance percentage: TBD.
- The page does not need to be reloaded for updates.
- **Live is bounded by source capture.** If the Dataset's source capture frequency (FR-13) is coarser than the Freshness Interval, the Widget shows the effective freshness, for example, "source updates hourly". It does not imply live data. Live refresh is only meaningful where the source captures per bundle, per piece or per event (research §5).

#### FR-51: Stale and connection states
A Widget becomes **Stale** when its Data-as-of Time is older than its allowed staleness. Allowed staleness is **TBD** [PROPOSED default: 2 × Freshness Interval].
- A Stale Widget shows the last value, greyed, with "Stale: last data \<time\>". It never shows zero.
- When the browser loses connectivity, a dashboard-level "Reconnecting…" indicator appears within one Freshness Interval. On recovery, all Widgets refresh automatically.
- When the Data Source is unreachable (FR-9), the affected Widgets show Stale with the reason "source unavailable".

#### FR-52: Data-as-of transparency
Every Widget shows its Data-as-of Time, distinct from "refreshed at". Dashboards show the oldest Data-as-of Time among their Live Widgets.

#### FR-53: Missed-update recovery
After any interruption (network, server restart, source outage), the next successful refresh returns the current state. Widgets never show a partial mix of old and new values from different moments without an indication [PROPOSED].

### 4.11 KPI Snapshots and History

**Description.** No historical data exists today. Executives need trends, so the platform **captures Snapshots from go-live** **[CONFIRMED direction]**. Snapshot grain, retention and backfill are **TBD (O8)**. Storage design is an **[ADR]**.

#### FR-54: Capture Snapshots
The system records Snapshots of each Published KPI at a defined grain. [PROPOSED default: hourly per Line for Live KPIs, daily per Line for Periodic KPIs.] The final grain is **TBD (O8)**.
- Each Snapshot stores the KPI Version used to compute it, so later definition changes do not silently rewrite history.
- Snapshots also store numerator and denominator values for ratio KPIs, so roll-ups over history stay correct (FR-17).

#### FR-55: Trends and comparisons
Widgets can show trends and comparisons over Snapshots: today vs yesterday, week to date, month to date, and same period last month once that much history exists.
- Before enough history exists, the comparison shows "Not enough history (since \<go-live date\>)", not zero.

#### FR-56: Recalculation policy
When a KPI Version changes, historical Snapshots stay as computed by the version in force at the time [PROPOSED]. Optional recalculation of history under a new version is **[OPEN] (O25)**.

#### FR-57: Backfill (conditional on O8)
If the client provides historical exports, a Data Administrator can import them as Snapshots, marked as backfilled. **TBD pending O8.**

### 4.12 Audit

**Description.** Every configuration and governance change is auditable.

#### FR-58: Audit Events
The system records an Audit Event for each of the following:
- creation, update, deletion or archival of a Data Source, Dataset, Field, KPI, Widget or Dashboard;
- submit, approve, reject, publish, unpublish and restore;
- Role, Permission, Data Scope and sharing changes;
- sign-in success and failure;
- denied access attempts.

Each event records the user, timestamp, action, object type and ID, and the previous and new state (or a diff) where applicable.
- Audit Events cannot be edited or deleted by any role.
- Audit retention: **TBD**.

#### FR-59: Audit review
A System Administrator, or an auditor role [PROPOSED], can search and filter Audit Events by user, object, action and date, and export the results.

### 4.13 Administration

#### FR-60: Platform settings
A System Administrator can configure:
- the default Freshness Intervals and system minimum (O24);
- staleness thresholds;
- query cost limits (FR-27);
- the organization hierarchy: Organization, Factory, Floor, Line;
- shift and calendar definitions [ASSUMPTION, O18].

#### FR-61: Organization hierarchy source
The Factory, Floor and Line hierarchy used for Data Scope and filters is either maintained in the platform or synchronised from the RMG application [PROPOSED: synchronised]. **[OPEN] (O26)**.

### 4.14 Observability for Operators

#### FR-62: Platform health view
A System Administrator can see:
- Data Source health;
- refresh lag per Live Dataset;
- query error rates and timeouts;
- the slowest Query Definitions;
- Snapshot capture status.

### 4.15 MVP KPI Templates

**Description.** The client has given a **priority set** **[CONFIRMED]**. Management will confirm the ranking **(O3)**, and each definition will be confirmed by its KPI owner **(O4)**. The platform ships one KPI Template per item. Each template lists the documented Variants from the research. The templates are starting points only: no KPI is published until a KPI Author instantiates it, the client's chosen definition is applied, and it is approved **[PROPOSED]**.

| Template | Research ID · status | Variants offered | Refresh default | Module (current / future) | Prerequisites and data-quality |
|---|---|---|---|---|---|
| Production Achievement (Actual vs Target) | P4 · STD | — | Live **where the source supports it**; else hourly | PROD (current) + PLAN | Output, Target; agreed planned efficiency. **O3:** confirm the two client items are one KPI |
| Line Efficiency | P2 · STD+VAR | V1 (SMV/SAM basis), V2, V3, V4 | Live **where the source supports it**; else hourly | PROD + IE + HR (*future?*, O2) | Output, **SMV versioned per style**, **attendance by shift start**, line transfers tracked (O2) |
| Hourly Production | P15 · STD | — | Live **where the source supports it**; else hourly | PROD (current) | Output with hour timestamp |
| WIP | W3 · STD | — | Live **where the source supports it**; else hourly | PROD (current) | **Line input** recorded, not only output (O1) |
| Downtime | P11 · PRACTICE | Formula client-defined | Live with terminal or IoT logging; else end of shift | PROD + MNT (*future?*) | Downtime events with a **standard reason-code list**. Source is **O5** |
| DHU / Quality | Q1 / Q2 · STD | V6 | **Periodic** unless digital QC capture exists (O5) | QA (*future*: on module availability) | Every checked piece counted; defect-code master. Source is **O5** |
| Rejection | Q3 · STD+VAR | V7 | Periodic | QA (+ SHIP for the cut − shipped variant) (*future*) | Reject reason coded (material vs process). Source is **O5** |
| Manpower / Attendance | Manpower present (count; *not in research catalogue*) **or** W1 Absenteeism % | V9 (if W1) | Periodic (morning) | HR / attendance (*future?*, O2) | Roster plus leave-type coding. **O3:** which meaning |
| Productivity | P7 · STD | Manpower scope (V3) | Periodic | PROD + HR | Output, defined manpower scope (O2). Does **not** need SMV unless a minutes-based variant is chosen |

**Key.** Status (from research §2): STD = standard formula · STD+VAR = standard concept with variants (client chooses) · PRACTICE = no standard formula (client defines). Modules: PROD production · PLAN planning · IE industrial engineering/SMV · HR workforce/attendance · QA quality · MNT maintenance · SHIP shipment.

KPIs whose module is *future* are delivered **on module availability**: the template exists, and the KPI shows Unavailable until the module's data is registered.

#### FR-63: Template instantiation
A KPI Author can instantiate any MVP KPI Template (realizes UJ-2). Each template's prerequisites are pre-declared, so a missing prerequisite shows Unavailable (FR-19).
- Acceptance: each of the nine templates can be expressed fully through FR-15 to FR-18, with no code change, once its prerequisite Datasets exist.

## 5. Cross-Cutting Non-Functional Requirements

No number in this section has been invented. TBD values need client confirmation (O9 and others).

| ID | Area | Requirement |
|---|---|---|
| NFR-1 | **Freshness** | Live Widgets: target ≈ 30 s **[CONFIRMED]**, bounded by source capture frequency (O1) and source-access latency (O11). End-to-end target and compliance %: **TBD** |
| NFR-2 | **Dashboard load** | Time to first meaningful render of a Published Dashboard with N Widgets on desktop or mobile: **TBD**. Maximum N per Dashboard: **TBD** |
| NFR-3 | **Query execution** | p95 query time per Refresh Class: **TBD**. Timeout: **TBD** (FR-27) |
| NFR-4 | **Scale** | Users, concurrent viewers, Dashboards, Widgets per Dashboard, factories and lines: **TBD (O9)**. Design must not assume one factory or department [CONFIRMED] |
| NFR-5 | **Source protection** | Platform load on the RMG source stays within the agreed budget (FR-11): **TBD with client IT** |
| NFR-6 | **Availability** | Required uptime and hours (for example, production shifts): **TBD**. RPO/RTO: **TBD** |
| NFR-7 | **Security** | HTTPS/TLS for all traffic, including real-time channels. Credentials encrypted at rest. Server-side authorization on every API. Protection against SQL injection (FR-26), XSS (no user HTML or script; output encoding), CSRF and brute force (rate limiting). Session management per FR-1. Secrets are never sent to browsers |
| NFR-8 | **Data protection** | Personal data such as worker attendance is shown only in aggregate, unless a Role is granted worker-level access [PROPOSED]. Applicable data-protection obligations: **[OPEN] (O27)** |
| NFR-9 | **Audit integrity** | Audit Events are append-only and tamper-evident [PROPOSED]. Retention: **TBD** |
| NFR-10 | **Accessibility** | WCAG 2.1 AA for the Viewer and Builder [PROPOSED; confirm (O17)]. Status is never conveyed by colour alone: values carry icons or labels as well as red/green |
| NFR-11 | **Browser support** | Current versions of major desktop and mobile browsers [PROPOSED]. Exact list: **TBD (O16)** |
| NFR-12 | **Responsiveness** | Desktop and mobile web **[CONFIRMED]**. TV/kiosk **TBD (O10)** |
| NFR-13 | **Observability** | Metrics, logs and alerts for source health, refresh lag, query errors and Snapshot capture (FR-62) |
| NFR-14 | **Backup and retention** | Platform metadata and Snapshots backed up. Snapshot retention **TBD (O8)** |
| NFR-15 | **Maintainability** | New Widget Types (FR-35) and new Data Source types are added as modules, without changing core engines [PROPOSED] |
| NFR-16 | **Time** | All timestamps are stored in UTC and displayed in the factory's local time zone. [ASSUMPTION: a single time zone (Bangladesh) for the MVP] (O18) |

## 6. Integration and Dependencies

| Dependency | Status | Notes |
|---|---|---|
| RMG application, **production module** | **[CONFIRMED]** source of production data | Capture frequency, granularity, owners: **O1**. Access method: **O11 / [ADR]** |
| SMV/SAM per style | Required **[CONFIRMED]**, availability **TBD (O2)** | Without it, Line Efficiency (and any minutes-based productivity variant) is Unavailable. Pieces-per-manpower Productivity (research P7) needs manpower data, not SMV |
| Attendance / manpower per line | Required **[CONFIRMED]**, availability **TBD (O2)** | Same as SMV |
| Quality inspection data, downtime events | Source **[OPEN] (O5)** | Gates DHU, Rejection, Downtime |
| Future RMG modules (quality, HR, orders, shipment, inventory, finance) | **[CONFIRMED]** planned | Unlock the executive period KPIs in research §3.4 |
| Identity (SSO with the RMG application?) | **[OPEN] (O19)** | |
| Organization hierarchy | **[OPEN] (O26)** | |

## 7. Data Governance

- **Definition ownership.** Every Published KPI has a named owner and an approved definition [CONFIRMED]. The owner is accountable for formula changes.
- **Variant record.** The chosen Variant and the reason are part of the KPI record (FR-18).
- **History integrity.** Snapshots keep the KPI Version they were computed with (FR-54, FR-56).
- **Retention.** Snapshot retention is **TBD (O8)**. Audit and log retention are **TBD (O31)**.
- **Source of truth.** The RMG application stays the system of record. The platform holds metadata and Snapshots, never edited operational data.

## 8. Stakeholders and Approvals

| Stage | Who signs off | Status |
|---|---|---|
| PRD scope | Client sponsor + delivery lead | **[OPEN] (O28)** |
| KPI definitions | KPI owners per KPI (O4) | |
| Data access method | Client IT / RMG application owner (O11) | |
| UX / Builder approach | Client sponsor, after `bmad-ux` | |

## 9. Risks and Mitigations

| Risk | Impact | Likelihood | Mitigation | Linked |
|---|---|---|---|---|
| Source data captured too infrequently for Live KPIs to be truly live | High | Unknown | Live is conditional on source capture (FR-50); Data-as-of Time shown; O1 | O1 |
| SMV or attendance missing, so headline KPIs are Unavailable at launch | High | Unknown | Explicit Unavailable state (FR-19); early O2 answer; template prerequisites | O2 |
| KPI definitions disputed or inconsistent | High | Medium | Variant selection, owners, approval, definition panel (FR-16/18/22/43) | O4 |
| Business-user formulas wrong or expensive | High | Medium | No-code parse tree, validation, preview, cost limits, approval (FR-15/20/27/43) | |
| Dashboard queries load the production database | High | Medium | API/replica preference, shared results, load budget (FR-11/29) | O11 |
| Wrong roll-ups from averaging percentages | High | Medium | Ratio-safe Aggregation Rule (FR-17) | |
| Permission model complexity (Role × Scope × Dataset × Field) | Medium | High | Server-side enforcement, scoped caching, early O6 answer | O6 |
| No history at launch weakens executive value | High | High | Snapshots from go-live; "not enough history" state | O8 |
| MVP stalls waiting for KPI definitions (no catalogue exists) | High | High | Templates with documented variants (§4.15); owners per KPI; preview-then-approve flow | O3, O4 |
| External clients add isolation needs late | High | Unknown | FR-5 placeholder; decide O7 early | O7 |
| Scale unknown | Medium | Unknown | Load assumptions documented in architecture; O9 | O9 |

## 10. Non-Goals (Explicit)

- **Not a general-purpose / ad-hoc BI platform.** No unrestricted SQL, no free-form exploration and no full analyst workspace in the MVP **[CONFIRMED]**. Governed BI capabilities are in scope (§1.1). Revisit only if the architecture evaluation (addendum §J) or a client discussion shows a clear requirement.
- **No raw SQL for business users** **[CONFIRMED]**.
- **No offline operation** **[CONFIRMED]**. PGlite or other browser-side databases are not in the MVP; they remain a future option [CONFIRMED].
- **No KPI value shown when its source data is missing**. No estimates and no zeros **[CONFIRMED]**.
- **No writes to the RMG application.** Read-only.
- **No arbitrary JavaScript, HTML or scripts** from users; no free-form page builder [PROPOSED].
- **No physical table per Dashboard, Widget or KPI** **[CONFIRMED]**.
- Not a multi-tenant SaaS product for the MVP [PROPOSED]. The design does not hard-code a single factory or department [CONFIRMED].
- Not a native mobile app [PROPOSED].

## 11. MVP Scope

### 11.1 In Scope
- **Governed BI capabilities** as listed in §1.1
- Features 4.1 to 4.15 as specified, with conditional FRs (FR-5, FR-57) depending on O7 and O8
- RMG application as the single Data Source type (through API or replica, O11)
- The nine MVP KPI Templates (§4.15). Each is published only after client definition and approval
- Desktop and mobile web

### 11.2 Out of Scope for MVP

| Item | Reason / status |
|---|---|
| TV/kiosk mode | **TBD (O10)**; may move in |
| KPI threshold alerts and notifications (email, web push) | Deferred [PROPOSED]; **O10**. Workflow notifications are in scope (FR-42). [NOTE FOR PM]: executives may expect threshold alerts; revisit if the timeline allows |
| Exports (CSV/Excel/PDF/image), print, scheduled or emailed reports | Deferred **(O10)** |
| Public links, embedding, external sharing | Deferred; revisit with O7 |
| Executive period KPIs (OTD, cut-to-ship, CPM/CM, capacity booking, turnover) | Depend on future modules; same platform, added later |
| Additional Data Source types (CSV, other DBs, event streams, IoT) | Designed for, not built |
| Offline / PGlite, native mobile, AI / natural-language dashboards, widget marketplace | Future scope |
| Viewer personal layouts | **O14** |

## 12. Success Metrics

**Primary**
- **SM-1. Developer-free change.** Share of new or changed KPIs and Dashboards published with no code change. Target: **TBD**. Validates FR-15 to FR-18, FR-36 to FR-45.
- **SM-2. Governed numbers.** Share of Published KPIs with an owner, an approved definition, a target and a Direction. Target: 100% by design. Validates FR-16, FR-43.
- **SM-3. Live freshness compliance.** Share of time Live Widgets are within their Freshness Interval, for sources that support it. Target: **TBD**. Validates FR-50, FR-51.

**Secondary**
- **SM-4. Executive adoption.** Weekly active Executive/CXO viewers ÷ provisioned executives. Target: **TBD**. Validates FR-46.
- **SM-5. History coverage.** Days of continuous Snapshots since go-live, with gaps < **TBD**. Validates FR-54.
- **SM-6. Access integrity.** Zero confirmed cases of data shown outside a user's Data Scope. Validates FR-3, FR-4, FR-28.

**Counter-metrics (do not optimise)**
- **SM-C1. KPI count.** More KPIs is not better. Counterbalances SM-1; watch for duplicate or contradictory KPIs.
- **SM-C2. Refresh rate.** Refreshing faster than the source captures data adds load without adding information. Counterbalances SM-3.
- **SM-C3. Approval speed.** Rubber-stamp approvals defeat governance. Counterbalances SM-1.

## 13. Open Questions

**Carried from brief v3 (not resolved here)**

| ID | Question | Affects | Owner |
|---|---|---|---|
| O1 | Capture frequency, granularity and data owner per data item | FR-50, NFR-1, §4.15 | Client IT / RMG owner |
| O2 | Are SMV/SAM and attendance available? | FR-19, §4.15 | Client IE / HR |
| O3 | Final top-10 ranking; are "Achievement" and "Actual vs Target" one KPI; meaning of "manpower/attendance" | §4.15, §11 | Client management |
| O4 | Approved formula per KPI, variant choices (V1–V17), targets | FR-16, FR-18 | KPI owners |
| O5 | Source of quality data and downtime events | §4.15 | Client |
| O6 | Role matrix: who creates datasets, KPIs, widgets, dashboards; who approves; can authors self-approve; who publishes | §4.1, FR-43/44 | Client |
| O7 | Are "clients" internal management or external buyers? | FR-5, FR-47 | Client |
| O8 | Snapshot grain, retention, backfill | FR-54 to FR-57, NFR-14 | Client |
| O9 | Scale: factories, lines, users, concurrency | NFR-2 to NFR-4 | Client |
| O10 | TV/kiosk mode; exports; alerts | §11.2 | Client |
| O11 | RMG technical assessment: stack, APIs, replica feasibility, change events | §4.2, FR-50 | Delivery team + client IT |

**New in the PRD**

| ID | Question | Affects |
|---|---|---|
| O12 | Do KPI changes and Dashboard changes need the same approver, or different approvers? | FR-43 |
| O14 | May viewers personalise their own Layout? | FR-49 |
| O15 | When a KPI's current version changes, do bound Widgets follow automatically, or stay pinned until the Dashboard is republished? **[PROPOSED default: they follow on KPI approval, because the KPI approval explicitly includes the impact list of affected Dashboards (FR-43). That keeps a single approval gate, and the move is audited.]** | FR-34, UJ-3 |
| O16 | Browser and device support baseline | NFR-11 |
| O17 | Accessibility standard required (WCAG 2.1 AA?) | NFR-10 |
| O18 | Shift and calendar definitions per factory; time zone(s) | FR-40, FR-60, NFR-16 |
| O19 | Authentication: standalone or SSO with the RMG application? | FR-1 |
| O20 | Can a scoped user ever see unscoped aggregates (for example, group total)? | FR-3 |
| O21 | Final function set for the KPI formula builder | FR-15 |
| O22 | Partial prerequisites: exclude affected rows with a "partial" badge, or mark the whole KPI Unavailable? | FR-19 |
| O23 | May Data Administrators use raw SQL to define Datasets? | §4.5 |
| O24 | Level at which freshness is configured, and the system minimum interval | FR-50, FR-60 |
| O25 | Recalculate history under a new KPI Version: allowed or not? | FR-56 |
| O26 | Organization hierarchy: maintained in the platform or synchronised from RMG? | FR-61 |
| O27 | Data-protection obligations for worker-level data | NFR-8 |
| O28 | Who signs off this PRD on the client side? | §8 |
| O29 | If architecture selects an embedded or hybrid BI option (addendum §J), will the client accept an embedded third-party component (licensing, hosting, UX)? *Ask only if that option is selected.* | Addendum §J |
| O30 | Extra Data Scope dimensions needed: Department, Business Unit, Buyer? | FR-3 |
| O31 | Audit and log retention periods | FR-58, NFR-9 |
| O32 | Email delivery for workflow notifications (submit / approve / reject)? | FR-42 |

*(There is no O13; it was folded into O6.)*

**Phase gating.** Which open items block which downstream phase:

| Phase | Blocking open items | Note |
|---|---|---|
| `bmad-ux` | O6, O14, O15, O22 | Roles, personalisation, version-binding and partial-data UX |
| `bmad-architecture` (incl. build-vs-embed, addendum §J) | O1, O6, O7, O9, O11, O19, O24 | Sizing needs O9. Until it is answered, architecture records **explicit sizing assumptions marked for client confirmation**; no invented targets |
| `bmad-create-epics-and-stories` (KPI templates) | O2, O3, O4, O5 | Platform epics can proceed; KPI-template stories wait |
| Client sign-off | O28 | |
| Non-blocking (track) | O8, O10, O12, O16–O18, O20, O21, O23, O25–O27, O29–O32 | Defaults or TBDs are safe until build |

## 14. Assumptions Index

- §2.1, UJ-6: factory/line managers and shop-floor displays are a secondary audience.
- FR-1: the platform may need to reuse RMG application identities (O19).
- FR-40, FR-60, NFR-16: shifts are defined per factory, with a single time zone for the MVP (O18).
- Brief carry-over: the RMG application uses Laravel/PostgreSQL (to be confirmed in O11).
- Stakes: enterprise-grade PRD for client delivery; sign-off audience TBD (O28).
