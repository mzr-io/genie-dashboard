---
title: "PRD: Dashflow, Dynamic Dashboard Platform"
status: final
version: 3.1
created: 2026-10-02
updated: 2026-10-05
supersedes:
  - prd-v2-domain-agnostic.md (v2)
  - prd-v1-rmg-centric.md (v1)
inputs:
  - UI mockups (5 screens, product owner, 2026-10-05): sign-in, User Overview with Add-blocks panel, Admin overview, Create block wizard (two screens)
  - change signal 2026-10-02 (domain-agnostic; Admin Designer + End-User Dashboard)
  - ../../briefs/brief-rmg-dashboard-platform-2026-10-02/brief.md (v4, domain-agnostic, aligned with this PRD)
  - ../../research/domain-rmg-production-and-executive-kpis-2026-10-02/research.md (example-domain material only)
companion: addendum.md
---

# PRD: Dashflow, Dynamic Dashboard Platform

*"Dashflow" is the working product name from the mockups.*

## 0. Document Purpose

This PRD defines **what** Dashflow must do in its first release. It is written for three audiences:
- the delivery team (PM, UX, architecture, development, QA);
- stakeholders;
- the downstream BMAD workflows (`bmad-ux`, `bmad-architecture`, `bmad-create-epics-and-stories`).

**Version 3** combines the domain-agnostic scope from v2 with the **UI mockups** of 2026-10-05. It also adds the **API data mapping** model that the mockups do not show (§4.5, §4.6). Version 1 (RMG-centric) and version 2 are archived next to this file.

**Structure.**
- Vocabulary is fixed by the Glossary (§3).
- Features (§4) carry globally numbered requirements, FR-1 to FR-68. **IDs were reissued in v3**, so v1 and v2 IDs are not stable references.
- Every FR has testable consequences.
- Non-functional requirements are in §5. Technology options are in [addendum.md](addendum.md).

**Status tags.**

| Tag | Meaning |
|---|---|
| **[CONFIRMED]** | Product owner or client decision |
| **[MOCKUP]** | Shown in the 2026-10-05 UI mockups. Treated as confirmed design intent; exact visuals are refined in `bmad-ux` |
| **[PROPOSED]** | Our recommendation, not yet confirmed |
| **[OPEN]** | Needs an answer (§12) |
| **[ADR]** | Decided in `bmad-architecture` |
| **TBD** | No value yet; never invented |
| **[DIRECTION: …]** | Delivery-team direction |

**No open product questions remain** (§12). Items that depend on the first customer (API inventory, scale numbers, compliance regime, deployment choice) are tracked as **pending client inputs**, with owners (§12).

## 1. Vision

Organizations in any domain (RMG, HealthTech, FinTech, Healthcare, Retail and others) already expose their data through APIs. What they lack is a fast, safe way to turn those APIs into dashboards that each person can shape to their own work. Today, every new dashboard is a development task.

Dashflow has two areas **[CONFIRMED]**:

1. **Admin area ("System management").**
   - Admins register API data sources.
   - They create **blocks** in a guided wizard: Basic info → Data → **Map data** → Display & behavior → Preview → Publish.
   - Using the **API Data Mapper**, they decide exactly how an API response is presented: which value is the bold headline, which is the comparison, which fields become chart axes, table columns or list rows, and how each is formatted and highlighted.
   - Blocks are organized into **categories**, and dashboards can be packaged as **templates**.
   - Admins with publish permission publish blocks and templates directly to the Block Library. There is no approval workflow in the MVP [CONFIRMED 2026-10-05].
2. **User area ("Personal workspace").**
   - Users sign in to their **workspace** and get one or more personal dashboards.
   - They open the **Add blocks** panel on the right, search and filter by category, and add or remove blocks.
   - In **Edit layout** mode they drag and resize blocks on a grid.

Dashflow is **domain-agnostic**: nothing about any domain is built into the platform **[CONFIRMED]**. RMG KPIs are just one example configuration (§4.15).

Success means that a new dashboard block over an existing API reaches users **without a development ticket**, and that every number shown traces back to a published, versioned block definition.

### 1.1 Positioning

[DIRECTION: delivery team; retained from v1/v2]

**A dashboard-centric platform with governed BI capabilities.** The capabilities are:
- a typed field model over API responses;
- governed transforms: filter, group, aggregate, calculated fields, sort, top-N;
- ratio-safe aggregation;
- visualization and presentation rules;
- date and period filters, with block-level period selectors;
- an expanded view that shows a Block's full rows;
- role-based block access.

All of these are delivered through governed, versioned and audited blocks. **General-purpose ad-hoc BI** (free-form exploration, unrestricted queries, an analyst workspace) is a **non-goal** for the MVP. It is reopened only if the build-vs-embed evaluation (addendum §J) or a stakeholder discussion shows a clear need for it.

## 2. Target Users

### 2.1 Roles and jobs to be done

| Role | Job to be done | Status |
|---|---|---|
| **User** | "Give me the dashboards I need, and let me add, remove and arrange blocks my way." | [CONFIRMED][MOCKUP] |
| **Admin** | "Connect our APIs, build correct and well-presented blocks and templates, publish them safely, and manage users and the system." | [CONFIRMED][MOCKUP] |

At sign-in, a person who holds both roles chooses whether to sign in as **User** or **Admin** [MOCKUP].

### 2.2 Non-users (MVP)
- Analysts wanting ad-hoc BI (§1.1).
- Anonymous or public viewers [PROPOSED].
- Developers writing custom code inside the product: no user scripts.
- Offline users [CONFIRMED].

### 2.3 Key user journeys

Names are illustrative.

- **UJ-1. Alex (Admin) builds the "Revenue overview" block.**
  1. **Create block → Basic info:** name "Revenue overview", description, Category: Finance, Block type: **KPI & Chart**.
  2. **Configure → Data:** Data source: "Finance data warehouse"; endpoint `GET /api/v2/finance/revenue`; refresh every 15 minutes; default date range: current year.
  3. **Configure → Map data:** Alex clicks *Fetch sample* and maps the response fields to the block's slots:
     - **Headline** ← `data.total`, currency format, bold;
     - **Comparison** ← `data.previous_total`, rendered as "↗ 14.2% from last year" in green, because higher is better;
     - **Chart X** ← `data.monthly[].month` (format MMM);
     - **Chart Y** ← `data.monthly[].revenue`.
  4. **Configure → Display & behavior:** 8 columns × Medium (360 px); header, subtitle, resize, minimize and refresh enabled.
  5. **Preview:** checks desktop, tablet and mobile.
  6. **Publish:** publishes the block directly to the Block Library as version 1.0 (Alex holds the publish permission).
  - **Edge case:** the API later renames `total`. The headline shows "Unavailable: total missing", never "$0". Alex gets a mapping-health alert.
  - Realizes FR-9 to FR-37.
- **UJ-2. Alex publishes an update.** Alex edits "Revenue overview". Before publishing, Dashflow shows the impact: 214 users have the block, and 2 Templates include it. Alex confirms, and v1.1 goes live. "Revenue overview" also appears in users' Add-blocks Panel under the **Finance** category chip, and in search. Realizes FR-39, FR-40.
- **UJ-3. Jamie (User) personalizes the Overview.**
  1. Signs in as User and lands on **Overview**.
  2. Clicks **Add block**. The panel shows "8 available blocks", with category chips (All, KPI, Charts, Tasks, Projects, Calendar) and search.
  3. Adds "Revenue vs Expense" (its card changes from "+ Add" to "✓ Added").
  4. Turns on **Edit layout**, drags the block next to "Revenue overview" and resizes it.
  5. Removes "Recent activity" and clicks **Done**.
  - Tomorrow, on mobile, the same blocks appear in the same order.
  - Realizes FR-51 to FR-56, FR-58.
- **UJ-4. Jamie creates a second dashboard from a template.** From **Templates** he picks "Finance weekly", names it, and it appears under **My dashboards**. He switches between dashboards with the dashboard switcher. Realizes FR-49 to FR-51.
- **UJ-5. An API outage.** The source behind "Pending approvals" goes down. The block keeps its last values, greyed, with "Stale: last data 10:42". It recovers automatically. Realizes FR-36, FR-60 to FR-62.
- **UJ-6. A new block version.** Alex changes the chart colour and adds a target line, and publishes the change as v1.1. Every user who has the block sees v1.1, and **nobody's layout moves** [MOCKUP: version-safe publishing]. Realizes FR-42.

## 3. Glossary

FRs and UJs use these terms verbatim.

**Tenancy and access**
- **Workspace.** An isolated tenant, for example "Genie Inc. – Enterprise workspace". It has its own users, data sources, blocks, templates and dashboards. A person may belong to several workspaces and switches between them with the workspace switcher [MOCKUP].
- **Role.** **User** or **Admin** [MOCKUP]. Admin capabilities are split into permissions (for example manage data sources, create/edit blocks, **publish**, manage templates, manage users, system settings) [CONFIRMED: access controlled through Admin roles and permissions].

**Data**
- **Data Source.** A registered API base, for example "Finance data warehouse": base URL, authentication, default headers and limits. It belongs to a Workspace. REST APIs are the only source type in the MVP [CONFIRMED].
- **Endpoint.** A request against a Data Source: method, path (for example `GET /api/v2/finance/revenue`) and parameters. A parameter is fixed, bound to the Date Range, or bound to user context.
- **Sample Response.** A fetched response used for mapping and preview.
- **Record Path.** The location of the array in the response that becomes rows, for example `data.monthly[]`.
- **Field.** A typed value taken from the response (text, number, decimal, date/time, boolean). It has a role: **Dimension** (groups or filters) or **Measure** (numeric, aggregatable).
- **Transform.** A declarative, governed operation: filter, group, aggregate (SUM, COUNT, AVG, MIN, MAX), calculated field, sort or top-N. Transforms are never scripts.
- **Calculated Field.** A Field computed by a governed expression, for example a percentage change.

**Blocks**
- **Block.** A published, reusable dashboard component. It holds a Block Type, an Endpoint, a **Slot Mapping**, Presentation Rules and Block Chrome settings. Blocks are versioned.
- **Block Type.** A kind of block, for example KPI card, KPI & Chart, line chart, bar chart, pie/donut, table, list, progress list, activity feed or calendar/agenda. Each Block Type declares its **Slots**.
- **Slot.** A named, typed place in a Block Type where data appears, for example *Headline value*, *Comparison*, *Chart X*, *Chart series*, *Table column* or *List item title*. Slots are listed in §4.6.
- **Slot Mapping.** The assignment of Fields, Calculated Fields or static text to Slots.
- **Presentation Rules.** Formatting (number, currency, percent, date, units, decimals), **emphasis** (headline/bold, size, colour), labels, axis settings, column settings, highlight and threshold rules, sort, and legend.
- **Direction.** Whether higher or lower values are good. It drives the colour of comparison and threshold values.
- **Block Chrome.** The block's frame: icon, header, subtitle, refresh, minimize and more-menu (⋯) controls, footer action, and default and allowed sizes [MOCKUP].
- **Block Category.** An admin-managed grouping used to filter the library, for example Finance, KPI, Charts, Tasks, Projects, Calendar, Communication or Reports [MOCKUP].
- **Block Version.** An immutable published revision, for example 1.0 or 1.1 [MOCKUP].

**Dashboards**
- **Dashboard.** A user-owned grid of block instances. A user can have several, listed under **My dashboards**. **Overview** is the user's default dashboard.
- **Block Instance.** A Block placed on a user's Dashboard, with its position, size and minimized state. It references the Block; it is not a copy.
- **Dashboard Template.** An admin-published starting layout of Blocks. It may mark some Blocks as **Mandatory**, which users cannot remove [CONFIRMED v2].
- **Add-blocks Panel.** The right-side panel listing the Blocks available to the user, with search, category chips, a filter and an Added / + Add state per Block [MOCKUP]. ("Block Library" in v2.)
- **Edit Layout Mode.** The mode in which Block Instances can be dragged and resized [MOCKUP].
- **Grid.** A 12-column responsive layout grid [PROPOSED; the mockup shows "8 columns × 360px"]. Height presets are Small, Medium (360 px) and Large [MOCKUP + PROPOSED].
- **Date Range.** The dashboard-level period control, for example "May 1 – May 31" [MOCKUP]. A **Block Period Selector** is a block-level override, for example "This year" [MOCKUP].

**Governance and freshness**
- **Lifecycle State.** Draft, Published, Unpublished or Archived (no review state in the MVP). It applies to Blocks and Dashboard Templates.
- **Publish.** Making a Block Version or Template available in the Block Library. **There is no approval step in the MVP**; a maker-checker workflow is a future option. "Pending approvals" in the mockup is only *business data* a Block may display.
- **Refresh Interval.** How often a Block's data is re-fetched, for example "every 15 minutes". "Live (~30 s)" is the shortest option [CONFIRMED earlier; PROPOSED minimum].
- **Data-as-of Time.** The time of the newest data shown: a mapped timestamp Field if one exists, otherwise the fetch time.
- **Stale.** The state where data is older than allowed. **Unavailable** means a value cannot be computed, for example because a mapped field is missing. It is never shown as zero.
- **Audit Event.** An immutable record of who changed what, and when.

## 4. Features

### 4.1 Sign-in, Workspace and Session

#### FR-1: Sign in with role choice
The sign-in page offers a **User** ("Personal workspace") and **Admin** ("System management") choice, plus email address, password and **Sign in as \<role\>** [MOCKUP].
- A person can sign in as Admin only if they hold the Admin role. Otherwise the system shows "You don't have admin access" and offers User.
- After sign-in, the person lands on the User **Overview** or the **Admin overview**.

#### FR-2: Credentials and recovery
The sign-in page has email and password fields, a show/hide password control, **Remember me** and **Forgot password** [MOCKUP]:
- **Forgot password** sends a time-limited reset link [PROPOSED].
- **Remember me** extends the session up to a maximum: **TBD**.
- Repeated failed attempts are throttled.
- Help text links to "Contact your workspace administrator" [MOCKUP].
- MVP authentication is email + password [MOCKUP]. **SSO is deferred** [PROPOSED].

#### FR-3: Workspace membership and switcher
Users belong to one or more Workspaces. A switcher, showing the workspace name and a descriptive label (for example "Enterprise workspace"; the label is cosmetic in the MVP), changes the active Workspace [MOCKUP].
- All data, configuration and dashboards are isolated per Workspace. A user in Workspace A can never see Workspace B's objects [PROPOSED].
- **Provisioning [PROPOSED]:** a platform operator, outside the Workspace Admin UI, creates Workspaces and invites each one's first Admin. Admins then manage their own Workspace.
- **Roles are per Workspace membership** [PROPOSED]: a person can be Admin in one Workspace and User in another.

#### FR-4: Session controls
**Sign out**, **Appearance** (light / dark / system theme) and a profile menu (name, role) are available in both areas [MOCKUP]. The idle timeout is **TBD** (§12.1).
- **Profile & settings** lets users edit their name, avatar, password, theme, locale and time zone [PROPOSED].
- **Help & support** links to help content configured by the Workspace Admin, plus "Contact your workspace administrator" [MOCKUP + PROPOSED].

### 4.2 Roles and Block Access

#### FR-5: Role enforcement
All Admin pages and APIs require the Admin role. Permissions are enforced server-side. A denied attempt returns "not authorized" and is audited.

#### FR-6: User configuration
In **User configuration** [MOCKUP], an Admin can:
- invite and deactivate users;
- assign User or Admin;
- assign **Admin permissions**, including the publish permission;
- assign **user groups**.

#### FR-7: Block and template access
An Admin can limit each Block and Dashboard Template to all users or to selected user groups. Users see only the Blocks they are allowed in the Add-blocks Panel, in Templates and in search.

#### FR-8: Row-level data scope through the API
When an Endpoint must return data specific to a user (for example, only their region), the Admin binds Endpoint parameters or headers to **user context** (user ID, email, group, or an attribute set in User configuration) [PROPOSED]. The **API is responsible for filtering**.
- Dashflow never lets a user change these bound values.
- A Block that has no user-context binding shows the same data to everyone who has access to it, and the Admin sees a "shared data" badge while configuring it.

### 4.3 Data Sources and Endpoints (Admin)

#### FR-9: Register a Data Source
An Admin registers a Data Source with:
- a name, for example "Finance data warehouse";
- a base URL;
- an auth type: API key, bearer token, OAuth2 client credentials or basic [PROPOSED];
- default headers;
- a timeout;
- maximum response size and number of pages.

Credentials are encrypted. After saving they are never shown again or sent to the browser.

#### FR-10: Outbound request safety
All API calls are made server-side. The base URL must match the Workspace's host allowlist. This prevents server-side request forgery (SSRF):
- **Loopback, link-local and cloud-metadata addresses are always blocked.**
- **Private-network addresses** are allowed **only** when the platform operator explicitly allowlists them for that Workspace. This is needed for internal APIs such as a "Finance data warehouse". Each such entry is audited [PROPOSED]. A future **connector/agent** for private networks remains possible (NFR-14).
- Redirects to hosts that are not on the allowlist are refused.
- A blocked attempt is rejected and audited.

#### FR-11: Define an Endpoint
In the block wizard the Admin selects a Data Source and enters a method and path, for example `GET /api/v2/finance/revenue` [MOCKUP]. Parameters can be:
- fixed values;
- bound to the **Date Range** (`from` / `to`);
- bound to the **Block Period Selector**;
- bound to user context (FR-8).

Constraints:
- **GET** is the default. **POST** is allowed only when the Admin marks the endpoint as a read-only query [PROPOSED].
- Dashflow never sends a request that modifies data.

#### FR-12: Test and health
The Admin can test an Endpoint, which shows the status, latency and Sample Response.
- Each Data Source shows its health (healthy, degraded or unreachable) and the time of its last successful call.

#### FR-13: Pagination, limits and load protection
- Configured pagination (page, offset, cursor or link header) is followed up to the limits.
- Calls are rate-limited per Data Source.
- Identical requests (same endpoint, resolved parameters and user-context values) within the Refresh Interval share one fetch.
- A response over the limits produces an Admin-visible error. It is never silently truncated in a way that changes totals.
- **Conditional requests are preferred** [CONFIRMED]. When an API returns `ETag` or `Last-Modified`, Dashflow sends `If-None-Match` or `If-Modified-Since` on the next fetch. A `304 Not Modified` reuses the cached result without re-transforming it, and updates the "checked at" time; the Data-as-of Time is unchanged. APIs without these headers are re-fetched on each interval.
- The MVP supports **REST APIs returning JSON** [CONFIRMED]. Non-JSON responses are rejected with a clear message.

### 4.4 Create Block Wizard (Admin)

**Description.** The wizard keeps the mockup's four-step stepper: **① Basic info → ② Configure → ③ Preview → ④ Publish** [MOCKUP].

**Configure** is one page with three sections, in this order:
1. **Data configuration** [MOCKUP];
2. **Map data**, added by v3 because the mockup lacks it [PROPOSED];
3. **Display & behavior** [MOCKUP].

A **Live preview** panel stays visible on the right throughout, with Desktop, Tablet and Mobile toggles and a size label (for example "8 columns × 360px") [MOCKUP].

#### FR-14: Basic info
The Admin enters:
- **Block name***;
- **Description*** (helper: "Help users understand what this block shows");
- **Category**;
- **Block type** [MOCKUP].

An icon is chosen from a set or defaults from the category [PROPOSED].

#### FR-15: Data
The Admin selects a **Data source** and an **API endpoint** (FR-11), a **Refresh interval** (for example Live ~30 s, 1, 5, 15 or 60 minutes, daily) and a **Default date range** (for example today, this week, this month, current year, custom) [MOCKUP].

#### FR-16: Map data section
This section of Configure contains the API Data Mapper (§4.5). The wizard cannot advance until every **required Slot** of the chosen Block Type is mapped.

#### FR-17: Display & behavior
The Admin sets the following [MOCKUP]:
- **Default width** in grid columns;
- **Default height** (Small, Medium 360 px, Large);
- **Show block header**, **Show subtitle**, **Allow resize**, **Allow minimize**, **Allow refresh**, **Show footer action**.

The Admin can also set the minimum and maximum size users may resize to [PROPOSED].
- **Period behaviour** [PROPOSED], one of:
  - **Follow dashboard:** the default;
  - **Own selector:** the Block shows a Block Period Selector (FR-35);
  - **Not date-filtered:** for example a current-status list.
- The **Default date range** (FR-15) is used when the dashboard has no Date Range, and as the initial value of an Own selector.

#### FR-18: Live preview
The preview renders the Block with **real Sample Response data**, applies the Slot Mapping and Presentation Rules, and updates on every change. It also shows Unavailable, Stale and empty states on request, so the Admin can check them.
- The **Preview** step shows the Block full-size on Desktop, Tablet and Mobile, and inside a sample dashboard grid.
- When user-context bindings exist (FR-8), the Admin can **preview as** a chosen user or group.
- The step lists any validation warnings, which must be resolved or acknowledged before Publish.

#### FR-19: Save draft
**Save draft** stores progress at any step. Drafts appear under **Draft blocks** [MOCKUP] with the status badge "Draft".

#### FR-20: Publish step
**Publish block** publishes the Block directly to the Block Library, if the Admin holds the **publish** permission [CONFIRMED]. Admins without it can only save drafts. The step summarises the version that will be created, for example "1.0" [MOCKUP], and any validation warnings.

### 4.5 API Data Mapper

**Description.** This is the core answer to "how does an API response become a block?" Every Block Type declares **Slots** (§4.6). The Admin fills the Slots from the response, shapes the data with Transforms, and decides how each value is presented [PROPOSED; fills a mockup gap].

#### FR-21: Fetch and explore the sample
**Fetch sample** calls the Endpoint with the current parameter values. The response is shown as a collapsible JSON tree, and a table view is available for arrays.
- Each node shows its path (for example `data.monthly[].revenue`), its inferred type and an example value.
- The Admin can **re-fetch** with different parameters, and with user-context values set to a chosen user ("fetch as user").

#### FR-22: Choose the record path
For Block Types that need rows (charts, tables, lists, feeds, calendars), the Admin picks the **Record Path**: the array that becomes rows. Single-value Slots (for example the KPI headline) can point to any scalar outside the array.

#### FR-23: Map fields to slots
The Admin assigns each Slot a Field from the tree, by drag-and-drop or a picker with search. Slots can also take a **Calculated Field** or **static text**, for example the label "Total revenue".
- Required Slots are marked with *. Type mismatches are rejected, for example text in a numeric Y-axis.
- Types are inferred and can be overridden, for example a string "2026-01" parsed as a month.
- **Changing the Block type** after mapping keeps the Slots with the same name and type, and flags the rest as unmapped. Nothing is silently dropped.

#### FR-24: Transforms
The Admin can add Transforms, applied in order: **filter → row-level calculated fields → group → aggregate → aggregate-level calculated fields → sort → top-N**.
- Example: group rows by `region`, SUM `revenue`, sort descending, top 5.
- Transforms are declarative and run server-side. No scripts are allowed.

#### FR-25: Calculated fields
Calculated Fields use a restricted expression language with:
- arithmetic;
- comparisons;
- `IF`;
- safe division;
- `ROUND`, `ABS`;
- **percentage change** `PCT_CHANGE(current, previous)`;
- date parts.

[PROPOSED function set]

#### FR-26: Text Templates
Any text Slot (including Title, Subtitle, labels, list subtitles and captions) can take a **Text Template**: literal text mixed with **tokens**, each with optional formatting [PROPOSED]. Tokens are:
- `{field}`;
- `{field|currency}`;
- `{ROW_COUNT}`;
- `{period.start|MMM d}` and `{period.end|MMM d, yyyy}`;
- `{user.name}`.

Examples from the mockups:
- "{ROW_COUNT} requests need your attention";
- "{category} · {days} days";
- "{done}/{total} tasks";
- "{period.start|MMM d} – {period.end|MMM d, yyyy}".

Text Templates are rendered as escaped text (FR-37).

#### FR-27: Comparisons
A comparison Slot (KPI cards and KPI & Chart) is filled in one of two ways [PROPOSED]:
- (a) a **mapped previous value** from the same response;
- (b) a **comparison request**: the same Endpoint called again for the **prior period**. The prior period is the previous period of equal length, the same period last year, or a custom offset. It is computed from the active period.

The **comparison mode** is one of:
- **% change**, `PCT_CHANGE`;
- **absolute delta**;
- **percentage-point delta**, for ratios.

The comparison label is a Text Template, for example "vs last month". **One primary Endpoint per Block** in the MVP; Blocks that join several different Endpoints are a non-goal (§9).

#### FR-28: Ratio-safe aggregation
A Calculated Field can be marked as a **ratio**, with a declared numerator and denominator. When a ratio is aggregated across rows, Dashflow sums the numerators and denominators separately.
- Example: rows (600/1,000) and (450/500) give **70.0%**, not the 75.0% that averaging 60% and 90% would give.

#### FR-29: Presentation Rules per slot
For every mapped Slot the Admin sets:

| Setting | Options |
|---|---|
| **Format** | number / integer / decimal places / thousands separator / percent / currency (code and symbol) / compact (1.2k, 3.4M) / date and time pattern / relative time ("8 min ago", "Today", "Tomorrow") / duration / units suffix |
| **Emphasis** | role: *headline* (large, bold), *primary*, *secondary* (muted), or *badge*; size; weight |
| **Colour rules** | The arrow shows the actual direction of change (↗ up, ↘ down). The colour shows whether that change is good: **green** when it moves in the Block's Direction, **red** when it moves against it. Threshold bands add colour, icon and label |
| **Label** | text, prefix and suffix, for example "vs last month" |
| **Empty and null display** | for example "—" |

Colour is never the only signal; an arrow, icon or label always accompanies it.

#### FR-30: Validation and missing data
Before saving, the mapping is validated: required Slots, types, aggregations and expressions. At run time:
- if a mapped field is missing or has the wrong type, the Slot shows **Unavailable: \<field\> missing**, never zero;
- in tables and lists, rows with a missing value show "—" in that cell, and the Block shows a "partial data" badge [PROPOSED].

When a mapping fails repeatedly, the Block's owner Admin is notified.

#### FR-31: Mapping example (normative illustration)
Given this response from `GET /api/v2/finance/revenue`:
```json
{ "data": { "total": 284680, "previous_total": 249195, "currency": "USD",
  "monthly": [ { "month": "2026-01", "revenue": 10200 }, { "month": "2026-02", "revenue": 12650 } ] } }
```
a **KPI & Chart** Block is mapped like this:

| Slot | Mapped to | Presentation |
|---|---|---|
| Title / Subtitle | static "Revenue overview" / "Financial performance · Current year" | header |
| KPI label | static "Total revenue" | secondary |
| **Headline value** | `data.total` | **headline (bold, large)**, currency USD, 0 decimals → **$284,680** |
| Comparison | `PCT_CHANGE(data.total, data.previous_total)` | percent, 1 decimal, Direction = higher is better → "↗ 14.2% from last year" (green) |
| Chart X | `data.monthly[].month` | date "MMM" → Jan, Feb… |
| Chart series 1 | `data.monthly[].revenue` | currency compact; line style, area fill |

The rendered Block matches the mockup's "Revenue overview" preview.

### 4.6 Block Types and Slot Catalogue

#### FR-32: MVP Block Types and their slots
Every Block Type also has the common Slots **Title**, **Subtitle**, **Icon** and **Footer action** (label plus a link or "View all" target).

| Block Type | Required slots (*) and optional slots | Example in mockups |
|---|---|---|
| **KPI card** | Headline value*, KPI label*, comparison value or change %, comparison label ("vs last month"), secondary note ("6 due today"), status badge | Total revenue, Total expenses, Pending tasks, Growth rate |
| **KPI & Chart** | KPI card slots plus Chart X*, Chart series* (1–n) | Revenue overview |
| **Line / Area chart** | X* (date or Dimension), series* (1–n Measures), series labels, highlighted point, target line | Revenue overview |
| **Bar chart** (grouped/stacked) | Category*, values* (1–n), stack/series key | Revenue vs Expense |
| **Pie / Donut** | Category*, value*, centre label/value (for example the total) | — |
| **Table / Data grid** | Columns* (each: Field, header, format, alignment, width, sortable); row highlight rule; pagination size | — |
| **List** | Item title*, item subtitle, avatar/initials or icon, right value (for example an amount), right meta (for example a date), status | Pending approvals |
| **Progress list** | Item title*, progress* (value ÷ total, or %), caption ("12/16 tasks"), colour rule | Project status |
| **Activity feed** | Actor*, action text*, object, timestamp* (relative), avatar | Recent activity |
| **Calendar / Agenda** | Date*, start time*, title*, subtitle/location, duration, colour/category; week strip with today highlighted | Upcoming |
| **Text / Status** | Text or status value* with colour rule | — |

KPI card, bar, line, pie/donut and table are **[CONFIRMED]** (change signal). KPI & Chart, list, progress list, activity feed and calendar are **[MOCKUP]**. Area and text/status are **[PROPOSED]**. A group of several KPI cards, such as the mockup's top row, is **four separate KPI card Blocks**, not one Block type [PROPOSED].

**Slot schema.** Each Slot declares:
- a **value type**: number, text, date/time, boolean or Text Template;
- a **cardinality**: *single* (one value per Block) or *per row* (one value per record from the Record Path);
- for numeric per-row Slots in charts, the **default aggregation** used when rows are grouped.

#### FR-33: Extensible Block Types
A developer can add a Block Type by supplying its slot schema, configuration schema, size limits and renderer. The wizard, the mapper and the dashboards need no change [PROPOSED].

### 4.7 Block Chrome and Block Controls

#### FR-34: Block chrome
Each Block shows these elements, as configured [MOCKUP]:
- icon, title and subtitle;
- **refresh** (if allowed);
- **minimize** (if allowed);
- **⋯ more menu**: refresh, view definition ("About this block": description, data source name, refresh interval, Data-as-of Time, version), remove from dashboard;
- an optional **footer action**, for example "View all approvals →". Its target is one of the following [PROPOSED]:
  - (a) an **external URL template** with field tokens, for example `https://erp.example.com/approvals?user={user.email}`; http(s) only;
  - (b) an **expanded view**: the Block opened full-size, with a paginated table of all its rows.

#### FR-35: Block Period Selector
When a Block's period behaviour is **Own selector** (FR-17), it shows a period dropdown, for example "This year" [MOCKUP].

**Precedence** [PROPOSED]:
1. the user's Block Period Selector choice;
2. otherwise the dashboard Date Range (for **Follow dashboard** Blocks);
3. otherwise the Block's Default date range.

**Not date-filtered** Blocks ignore all of these.

#### FR-36: Block states
Every Block has a visible presentation for each of these states:
- **loading** (skeleton);
- **empty** ("No data for this period");
- **error** (plain message + retry);
- **Stale** (last values greyed + "Stale: last data \<time\>");
- **Unavailable** (FR-30);
- **minimized** (header only).

#### FR-37: Safe rendering
API values are always rendered as text: escaped, never interpreted as HTML. Footer links accept only http(s) URLs or in-app routes [PROPOSED].

### 4.8 Block Lifecycle, Publishing and Versioning

**Description.** **No approval workflow in the MVP** [CONFIRMED 2026-10-05; supersedes the earlier approval decision]. Publishing is controlled by the Admin **publish** permission. A multi-level, maker-checker approval workflow is a **future enhancement** for clients that need governance controls. The mockup's **Version-safe publishing** rule applies: *publishing creates version 1.0, and future edits create a new version, so existing user layouts remain stable* [MOCKUP].

| From | Event | To |
|---|---|---|
| Draft | Publish (requires the publish permission) | Published (a new Block Version) |
| Published | Edit | A new Draft of the next version; the Published version stays live |
| Published | Unpublish | Unpublished: hidden from the Add-blocks Panel; existing Block Instances show "This block is no longer available" [PROPOSED] |
| Published / Unpublished | Archive | Archived (read-only) |

#### FR-38: Draft and Published lists
Admins have **Draft blocks** and **Published blocks** lists, showing name, category, type, version, owner, last updated and status [MOCKUP]. Lists can be filtered by category, type and owner [PROPOSED].

#### FR-39: Publish with impact confirmation
When an Admin publishes a **new version** of an already-published Block or Template, Dashflow first shows:
- a diff from the current version (mapping, presentation, chrome, endpoint);
- the **impact**: the number of users who have the Block on a Dashboard, and the Templates that include it.

The Admin confirms, and the new version goes live [PROPOSED]. A first publish shows only the validation summary.

#### FR-40: Publish permissions
Only Admins holding the **publish** permission can publish, unpublish, restore and archive Blocks and Templates. Admins with create/edit permission only can save drafts. Every publish action is audited (FR-68) [CONFIRMED].

#### FR-41: Versions and rollback
Every version is immutable and viewable. **Restore** creates a new Draft from an old version. Publishing that Draft creates a new version.

#### FR-42: Version-safe propagation
When a new Block Version is published, every Block Instance shows it on its next refresh. This is consistent with the v2 decision that admin updates propagate. Propagation **never changes a user's layout**:
- position and minimized state are kept;
- size is kept unless the new version's size limits exclude it, in which case it is clamped to the nearest allowed size.

This reconciles the mockup's "user layouts remain stable" with propagation [PROPOSED reconciliation].

#### FR-43: Block categories
Admins create, rename, order and archive **Block categories** [MOCKUP]. A Block has one primary category and can have additional tags. **Category chips in the Add-blocks Panel are exactly the Block Categories** that have at least one Block available to the user. Tags are searchable but do not create chips [PROPOSED].

#### FR-44: Block management
**Block management** lists all Blocks with search and filters (category, type, status, owner) and the actions edit, duplicate, unpublish, archive and view versions [MOCKUP].

### 4.9 Dashboard Templates (Admin)

#### FR-45: Create Dashboard Templates
An Admin arranges published Blocks into a **Dashboard Template** with a name, description, default layout and access (FR-7). The Admin can mark some Blocks as **Mandatory**. Templates follow the same publish lifecycle as Blocks (§4.8) [MOCKUP + CONFIRMED].

#### FR-46: Default dashboard for new users
An Admin chooses which Template seeds every new user's **Overview** dashboard [PROPOSED].

#### FR-47: Template updates
Updating a Template affects only dashboards created **after** the update. Existing user dashboards are not rearranged [PROPOSED; consistent with version-safe publishing]. The exception is Mandatory Blocks: a newly mandatory Block is added to existing dashboards created from that Template, at the end of the layout.

#### FR-48: Template adoption
Admins see how many dashboards were created from each Template (feeds "Template adoption", FR-65).

### 4.10 User Dashboards (User area)

**Layout** [MOCKUP]:
- **Left sidebar:** Workspace switcher, **Overview**, **My dashboards**, **Templates**, **Profile & settings**, **Help & support**, **Sign out**, **Appearance**, and the user's name and role.
- **Top bar:** **Search** (⌘K), **Notifications**, theme toggle, settings, and **+ Add block**.
- **Page header:** a breadcrumb, a greeting ("Good morning, Alex"), the **dashboard switcher**, the **Date Range**, and **Edit layout**.

#### FR-49: Overview and My dashboards
Each user has an **Overview** dashboard, seeded by FR-46, and may create more under **My dashboards**: blank, from a Template, or by duplicating an existing dashboard. Dashboards can be renamed and deleted (Overview cannot be deleted) [PROPOSED].

#### FR-50: Templates gallery
**Templates** lists the Dashboard Templates the user may use, with a preview. "Use template" creates a new dashboard.

#### FR-51: Dashboard switcher and Date Range
The dashboard switcher changes the current dashboard. The **Date Range** applies to Blocks whose period behaviour is Follow dashboard (FR-17, FR-35). The Date Range is remembered per dashboard.

#### FR-52: Add-blocks Panel
**+ Add block** opens the right-side panel [MOCKUP], titled "Add blocks — Build a dashboard that works for you". It contains:
- **search**;
- **category chips** (All plus the categories);
- a **Filter** (type, data source);
- a count ("8 available blocks");
- a card per Block: icon, name, description, category tag, and **✓ Added** or **+ Add**.

**Done** closes the panel.

#### FR-53: Add and remove Blocks
- **+ Add** places the Block at the first free grid position with its default size.
- In Edit layout mode, cards can also be **dragged** onto the grid. The panel footer says "Turn on Edit layout to drag blocks" [MOCKUP].
- Remove works from the block's ⋯ menu or from the panel. Mandatory Blocks cannot be removed, and the reason is shown.
- The same Block cannot be added twice to one dashboard [PROPOSED].

#### FR-54: Edit layout
In **Edit layout** mode, users drag Blocks to reorder them and resize them within each Block's allowed sizes (FR-17), if resize is allowed. Leaving the mode saves the layout. An undo is available while editing [PROPOSED].

#### FR-55: Block controls for users
Users can refresh, minimize or expand Blocks, change a Block Period Selector, and open "About this block". **Users cannot change** a Block's mapping, type or presentation [PROPOSED].

#### FR-56: Persistence
Dashboards, layouts, minimized states, period selections and Date Ranges are saved on the server per user and Workspace. They are restored on any device.

#### FR-57: Reset
A user can reset a dashboard created from a Template back to that Template's current layout.

#### FR-58: Responsive behaviour
- **Desktop:** the full grid.
- **Tablet:** a reduced number of columns.
- **Mobile:** a single column in the user's order. Drag reordering is available; free resizing is not [PROPOSED].
- The Add-blocks Panel opens as a full-height sheet on mobile.

#### FR-59: Accessible editing
Adding, removing, reordering and resizing are possible without drag-and-drop, through keyboard and menu actions (for example "Move up" or "Make wider") [PROPOSED].

### 4.11 Data Refresh and Freshness

#### FR-60: Refresh behaviour
Each Block re-fetches on its Refresh Interval without a page reload. The shortest interval is Live (~30 s), which is offered only if the Admin marks the Data Source as able to support it [PROPOSED]. Users' manual refresh is rate-limited.
- A Block can be no fresher than its API: the Data-as-of Time shows the real data age.

#### FR-61: Stale and connection states
- A Block becomes Stale when its data is older than 2 × its Refresh Interval [PROPOSED].
- On a lost connection, a "Reconnecting…" banner appears, and Blocks refresh automatically when the connection returns.
- A Block never silently mixes results fetched at different times.

#### FR-62: Data-as-of transparency
Every Block shows its Data-as-of Time in "About this block", and inline when the data is Stale. The dashboard header shows the oldest Data-as-of Time of the Live Blocks [PROPOSED].

### 4.12 Search and Notifications

#### FR-63: Global search
**Search anything** (⌘K) [MOCKUP] finds, within the active Workspace and the user's permissions:
- dashboards;
- Blocks (in the Add-blocks Panel);
- Templates;
- for Admins: data sources, users and settings.

#### FR-64: In-app notifications
The bell [MOCKUP] shows these notifications:
- **Users:** new Blocks available; a Block on their dashboard was updated to a new version; a Block was unpublished.
- **Admins:** publications by other Admins in the Workspace, data source health alerts, and repeated mapping failures.

Email notifications are **deferred** [PROPOSED].

### 4.13 Admin Overview and Platform Health

**Admin navigation.** The mockup's navigation is: Admin overview, Block management, Create block, Draft blocks, Published blocks, Block categories, Dashboard templates, User configuration and System settings [MOCKUP]. v3 adds **Data sources** and **Audit log** [PROPOSED].

#### FR-65: System overview
The **Admin overview** [MOCKUP] shows:
- **Published blocks**, with the change this month;
- **Active dashboards**, with the change this week;
- **Template adoption**: % of dashboards created from a Template;
- **Active users**: count, and the share active this week;
- **Recent block activity**: published or draft, by whom, and when, with a "View all" link;
- a **Create block** button.

Metric definitions are **TBD in UX**, for example the active window.

#### FR-66: Platform health
A **Platform health** panel shows two things to the Workspace's Admins: the status and uptime of the shared platform services, and the health of **their Workspace's Data Sources**. The platform services are: Dashboard API, Data refresh service, Authentication and Notification service [MOCKUP], with an overall "Operational" badge.

### 4.14 System Settings and Audit

#### FR-67: System settings
Admins configure the following Workspace settings:
- the host allowlist (FR-10);
- **audit and log retention periods** [CONFIRMED: configurable; default values TBD per customer];
- the allowed refresh intervals;
- fetch limits;
- locale, time zone and currency defaults;
- the theme default;
- the Template for new users (FR-46);
- the user attributes available for user-context binding (FR-8).

#### FR-68: Audit
Dashflow records Audit Events for:
- data source, endpoint, Block, category and Template changes;
- publish, unpublish, restore and archive;
- role and access changes;
- allowlist changes;
- sign-in success and failure;
- denied access;
- blocked outbound requests.

Each event records the user, time, object, and before/after state. Audit Events are immutable and can be searched and exported by Admins. User layout changes are not audited [PROPOSED]. Retention is **configurable per Workspace** (FR-67); the default value is TBD per customer [CONFIRMED].

### 4.15 Domain Examples (configuration only)

Domain concepts are **never core** **[CONFIRMED]**. Examples such as the RMG content (efficiency, DHU, WIP and their formula variants, from the domain research) or the mockup's finance, tasks and calendar Blocks are delivered as **Dashboard Templates plus Blocks**, created with the standard wizard. An export/import of Blocks and Templates (without credentials) between Workspaces is **[PROPOSED; post-MVP]**. It is not required for the MVP.

## 5. Non-Functional Requirements

No number in this section has been invented.

| ID | Area | Requirement |
|---|---|---|
| NFR-1 | Freshness | Live ≈ 30 s where the source supports it [CONFIRMED earlier]. Other intervals as configured. End-to-end target **TBD**; bounded by the API |
| NFR-2 | Performance | Dashboard first render with N Blocks, fetch and transform p95, maximum Blocks per dashboard: **TBD**. Validated from the first customer's expected usage [CONFIRMED] |
| NFR-3 | Scale | Numbers (Workspaces, users, concurrent users, Blocks, dashboards, API calls per minute) are **TBD**. Initial targets come from the first customer [CONFIRMED]. The architecture **must scale horizontally** [CONFIRMED]: stateless application nodes, shared cache, and queue-based fetch workers [PROPOSED] |
| NFR-4 | Security | Baseline security is required whatever the domain [CONFIRMED]: access control, audit logging (FR-68), encryption in transit (TLS) and **at rest** (secrets and stored data). Encrypted secrets. Server-side authorization. **SSRF protection** (FR-10). No user scripts. Escaped rendering (FR-37). CSRF protection. Sign-in throttling. Secure password reset. Workspace isolation (FR-3) |
| NFR-5 | Data protection | Dashflow persists **configuration, user layouts, audit records and short-lived caches only**. It stores **no historical copies** of API data in the MVP [PROPOSED]. Compliance regimes (for example HIPAA, GDPR, PCI DSS) are **TBD until the target customer and domain are confirmed** [CONFIRMED] |
| NFR-6 | Availability | Uptime, RPO/RTO: **TBD**. Platform health is visible (FR-66) |
| NFR-7 | Accessibility | **WCAG 2.1 AA** [PROPOSED default]. Keyboard alternatives for layout editing (FR-59). Colour is never the only signal |
| NFR-8 | Browsers | The latest two versions of Chrome, Edge, Firefox and Safari, desktop and mobile [PROPOSED default] |
| NFR-9 | Devices | Desktop, tablet and mobile web [MOCKUP preview toggles] |
| NFR-10 | Theming | Light and dark themes [MOCKUP]. Charts and status colours stay legible in both |
| NFR-11 | Localisation | Locale-aware number, currency and date formatting. Workspace defaults (FR-67) |
| NFR-12 | Extensibility | New Block Types (FR-33) and future source types are added as modules. **No domain logic in the core** |
| NFR-13 | Observability | Metrics and logs for fetches, mapping failures, refresh lag and health (FR-66). Log retention is configurable (FR-67) |
| NFR-14 | **Deployment-agnostic** | Supports both **cloud** and **on-premises / private infrastructure** deployment [CONFIRMED]. No coupling to a specific hosting provider or managed service: configuration via environment, standard containers, pluggable storage, cache and queue [PROPOSED]. The final model per client depends on security policy, network access, data residency and infrastructure. The design must leave room for a future **connector/agent** to reach private-network APIs [CONFIRMED] |

## 6. Dependencies

| Dependency | Status |
|---|---|
| Each Workspace's REST APIs (JSON) | The only MVP source [CONFIRMED]. The inventory (endpoints, response shapes, rate limits, user-context filtering) is collected from the first target integrations [CONFIRMED] |
| Email delivery (password reset, invitations) | Required [PROPOSED] |
| SSO identity providers | Deferred |
| Database, file and streaming sources | Deferred [CONFIRMED] |

## 7. Data Governance

- Blocks and Templates are versioned, permission-controlled and audited.
- Each Block records its owner, data source, endpoint and Refresh Interval, visible to users in "About this block".
- Source APIs remain the system of record. Dashflow never writes to them and does not keep history in the MVP (NFR-5).

## 8. Risks and Mitigations

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| SSRF through admin-entered URLs | High | Medium | Allowlist, address blocking, server-side calls only, audit (FR-10) |
| API schema changes break Blocks | High | High | Validation, Unavailable state, mapping-health notifications (FR-30, FR-64) |
| Mapping UX too technical for Admins | High | Medium | Visual JSON tree, drag-to-slot, live preview, inferred types, normative example (FR-21 to FR-31); validate in `bmad-ux` |
| Large or slow API responses | High | Medium | Limits, timeouts, shared fetches, push-down parameters (FR-13; addendum §B) |
| Per-user data relies on APIs filtering correctly | High | Medium | User-context binding, "shared data" badge, preview as user (FR-8, FR-18) |
| No platform history, so no trends unless the API provides them | Medium | Medium | Comparison and series Slots fed by the API (FR-31); history capture post-MVP |
| Mistakes published without a second reviewer | Medium | Medium | Publish permission, validation, preview as user, impact confirmation, versions and one-step restore, audit (FR-18, FR-39 to FR-41). Maker-checker is a future option |
| Domain logic creeps back into the core | Medium | Medium | Domain content as Templates and Blocks only (§4.15) |

## 9. Non-Goals (MVP)

- General-purpose or ad-hoc BI [CONFIRMED].
- Domain-specific logic in the platform core [CONFIRMED].
- User scripts, JavaScript or HTML in mappings or Blocks [PROPOSED].
- Writing to source APIs [PROPOSED].
- Non-API sources: databases, replicas, files, streams [CONFIRMED].
- Offline operation and PGlite [CONFIRMED].
- A physical table per Block, dashboard or Template [CONFIRMED].
- Drill-down between Blocks, and Blocks that join several different Endpoints [PROPOSED; post-MVP].
- A native mobile app, SSO, email notifications, exports and scheduled reports, KPI threshold alerts, TV/kiosk mode, public links and embedding, history snapshots, and export/import between Workspaces. **All are deferred.**

## 10. MVP Scope

**In scope:**
- §4.1–§4.14;
- governed BI capabilities (§1.1);
- Block Types per FR-32;
- desktop, tablet and mobile web;
- light and dark themes.

**Out of scope:** §9.

**Seed content [PROPOSED]:** the MVP ships no pre-built domain Blocks. An optional sample Workspace (finance, tasks, calendar, as in the mockups) may be provided for demos.

## 11. Success Metrics

**Primary**
- **SM-1. Developer-free Blocks:** share of new or changed Blocks published with no code change. Target **TBD**. Validates FR-9 to FR-32.
- **SM-2. Time to first Block:** from Data Source registration to a published Block. Target **TBD**. Validates FR-14 to FR-20, FR-39.
- **SM-3. Personalisation adoption:** share of active Users who added, removed or rearranged Blocks. Target **TBD**. Validates FR-52 to FR-54.

**Secondary**
- **SM-4. Template adoption:** FR-65.
- **SM-5. Mapping reliability:** Block-hours without an Unavailable state caused by mapping. Validates FR-30.
- **SM-6. Freshness compliance:** share of time Blocks are within their Refresh Interval. Validates FR-60, FR-61.

**Counter-metrics:**
- **SM-C1.** Block count: duplicates and clutter are not success.
- **SM-C2.** Refresh rate: faster than the API changes only adds load.
- **SM-C3.** Publish frequency: many tiny versions churn users' dashboards.

## 12. Open Questions and Pending Client Inputs

**No open product questions remain.** The five questions in the v3 draft were answered on 2026-10-05:

| Former Q | Decision |
|---|---|
| Q1 Approval | **No approval workflow in the MVP.** Admins publish directly, controlled by permissions. Maker-checker is a future option (§4.8) |
| Q2 API inventory | **REST + JSON.** Admins configure endpoint, auth, headers, parameters, refresh interval and mapping. **ETag and conditional requests are preferred** (FR-13). The inventory comes from the first integrations |
| Q3 Scale | Numbers stay **TBD**. **Horizontal scaling** is required (NFR-3) |
| Q4 Compliance | Regimes **TBD per customer**. Baseline security, audit, access control, encryption and **configurable retention** are required (NFR-4, FR-67) |
| Q5 Deployment | **Deployment-agnostic: cloud and on-premises**. A future connector/agent stays possible (NFR-14) |

**Pending client inputs.** These are not questions; they are facts collected from the first target customer. They refine values but do not change requirements.

| Input | Feeds | Owner | Needed by |
|---|---|---|---|
| API inventory: endpoints, response shapes, auth types, rate limits, update frequency, user-context filtering | Connector configuration, mapping templates, NFR-1 | First customer + delivery team | Before integration build |
| Expected users, Workspaces, Blocks, API sources, concurrent usage | NFR-2, NFR-3 sizing | First customer | Architecture validation |
| Applicable compliance regimes and data residency | NFR-4, NFR-5, NFR-14 | First customer | Before production |
| Chosen deployment model (cloud or on-premises) and network constraints | NFR-14, FR-10 | First customer | Before environment setup |
| Default audit and log retention | FR-67, FR-68 | First customer | Before production |

### 12.1 TBD register

Numeric values still to be set. None blocks UX, architecture or epics; architecture uses explicit planning assumptions, designed for horizontal scale, until they are set.

| Item | Where | Owner | Needed by |
|---|---|---|---|
| Session idle timeout and Remember-me duration | FR-2, FR-4 | Product owner + security | Build |
| Live freshness end-to-end target and compliance % | NFR-1 | Product owner (with the API inventory) | Architecture |
| Dashboard load, fetch p95, maximum Blocks per dashboard | NFR-2 | First customer | Architecture validation |
| Uptime, RPO/RTO | NFR-6 | First customer | Architecture validation |
| Height presets for Small and Large | §3 Grid, FR-17 | UX | `bmad-ux` |
| Admin-overview metric windows (for example, the "active" period) | FR-65 | UX | `bmad-ux` |
| Success-metric targets | SM-1 to SM-6 | Product owner | Before go-live |

## 13. Assumptions Index

- §3, Grid: a 12-column grid [PROPOSED]; the mockup's "8 columns" fits it.
- FR-8: per-user data is filtered by the API through user-context binding.
- FR-42 and FR-47: version-safe propagation and Template-update rules reconcile the mockup with the v2 decision.
- NFR-5: no history stored in the MVP; trends come from API data or comparison requests (FR-27).
- FR-3: Workspaces are provisioned by a platform operator; roles are per membership.
- FR-10: private-network APIs are reachable only through operator allowlisting, or later a connector/agent (NFR-14).

## 14. Change Log

**v3 → v3.1 (2026-10-05, answers to Q1–Q5)**

| v3 | v3.1 |
|---|---|
| Approval before publishing; review queue; approve/reject | **Removed for the MVP.** Direct publishing controlled by Admin permissions (FR-20, FR-39, FR-40). Maker-checker is a future enhancement |
| Q2 API inventory open | REST + JSON confirmed; ETag and conditional requests preferred (FR-13); inventory is a pending client input |
| Q3 scale open | TBD numbers; horizontal scaling required (NFR-3) |
| Q4 compliance open | TBD per customer; baseline security plus configurable audit and log retention (NFR-4, FR-67) |
| Q5 deployment open | Deployment-agnostic, cloud and on-premises; future connector/agent (NFR-14) |
| 5 open questions | **0 open questions**; 5 pending client inputs (§12) |

**v2 → v3 (2026-10-05, from the UI mockups)**

| v2 | v3 |
|---|---|
| Admin designs whole Admin Dashboards | **Block-centric admin:** Blocks (wizard), Block categories, Dashboard Templates [MOCKUP] |
| One End-User Dashboard per Admin Dashboard | Users own **Overview + My dashboards**, created blank or from Templates [MOCKUP] |
| Data View object (source + mapping + transforms) | **Data Source + Endpoint**, plus a per-Block **Slot Mapping** and Transforms; shared fetches give runtime reuse |
| Generic mapping | **Slot catalogue per Block Type**, with emphasis and presentation rules and a normative example (FR-29 to FR-32) |
| Widget/Block chrome undefined | Header, subtitle, refresh, minimize, ⋯ menu, footer action, size presets [MOCKUP] |
| Configurable Snapshots | **Deferred.** Trends come from API data |
| Platform-side Data Scope attributes | **Workspace isolation** plus **user-context binding to the API** |
| 38 open questions | **5** (§12) plus a TBD register (§12.1) |
| — | Added after the v3 review: Text Templates (FR-26), Comparisons (FR-27), period behaviour and precedence (FR-17, FR-35), footer-action targets and expanded view (FR-34), chip rule (FR-43), Workspace provisioning (FR-3), private-API allowlisting (FR-10), Q5 deployment model; drill-down and multi-Endpoint Blocks moved to non-goals |

**Questions resolved since v2**

| Earlier question | Resolution |
|---|---|
| Deployment model (O33) | Workspaces [MOCKUP] |
| Authentication (O19) | Email + password; SSO deferred [MOCKUP] |
| Freshness configuration level (O24) | Per Block [MOCKUP] |
| User Block settings (O38) | Layout and Block controls only (FR-55) |
| Partial data (O22) | FR-30 |
| Browsers and accessibility (O16, O17) | NFR-7, NFR-8 defaults |
| Function set (O21) | FR-25 |
| Locale (O36) | FR-67 |
| External users (O7) and scope enforcement (O37) | Workspace membership and FR-8 |
| Email notifications (O32) | Deferred |
| First Domain Pack (O35) and TV/exports/alerts (O10) | Deferred |
| History questions (O8, O25) | Snapshots deferred |
| Sign-off (O28) | Moved to project governance |
| Embedded-BI acceptance (O29) | Conditional; kept in addendum §J |
| Endpoint auth types (O34) and API inventory (O1, O11) | Merged into Q2 |
| Scale (O9) | Q3 |
| Compliance (O27) and audit retention (O31) | Q4 |
| Approver model (O6, O12) | Q1 |

**v1 → v2:** see `prd-v2-domain-agnostic.md` §15.
