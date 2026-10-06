---
stepsCompleted: [1, 2, 3, 4]
inputDocuments:
  - _bmad-output/planning-artifacts/prds/prd-rmg-dashboard-platform-2026-10-02/prd.md
  - _bmad-output/planning-artifacts/prds/prd-rmg-dashboard-platform-2026-10-02/addendum.md
  - _bmad-output/planning-artifacts/architecture/architecture-rmg-dashboard-platform-2026-10-05/ARCHITECTURE-SPINE.md
  - _bmad-output/planning-artifacts/architecture/architecture-rmg-dashboard-platform-2026-10-05/SOLUTION-DESIGN.md
  - _bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/DESIGN.md
  - _bmad-output/planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md
  - _bmad-output/specs/spec-rmg-dashboard-platform/SPEC.md
---

# Dashflow (rmg-dashboard-platform) - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for Dashflow (rmg-dashboard-platform), decomposing the requirements from the PRD, UX Design if it exists, and Architecture requirements into implementable stories.

## Requirements Inventory

### Functional Requirements

FR-1: The sign-in page offers a User ("Personal workspace") and Admin ("System management") choice, plus email address, password and "Sign in as <role>" [MOCKUP]. A person can sign in as Admin only if they hold the Admin role; otherwise the system shows "You don't have admin access" and offers User. After sign-in the person lands on the User Overview or the Admin overview.
FR-2: The sign-in page has email and password fields, a show/hide password control, Remember me and Forgot password [MOCKUP]. Forgot password sends a time-limited reset link [PROPOSED]; Remember me extends the session up to a maximum that is TBD; repeated failed attempts are throttled. Session-timeout warning [UX DECISION R5]: 2 minutes before the session expires a dialog offers "Stay signed in", and the Create-block wizard draft is autosaved continuously and restored after the user signs back in. Help text links to "Contact your workspace administrator" [MOCKUP]. MVP authentication is email + password [MOCKUP]; SSO is deferred [PROPOSED].
FR-3: Users belong to one or more Workspaces; a switcher showing workspace name and a descriptive label (cosmetic in the MVP) changes the active Workspace [MOCKUP]. All data, configuration and dashboards are isolated per Workspace, and a user in Workspace A can never see Workspace B's objects [PROPOSED]. Provisioning [PROPOSED]: a platform operator, outside the Workspace Admin UI, creates Workspaces and invites each one's first Admin, who then manages their own Workspace. Roles are per Workspace membership [PROPOSED] (Admin in one Workspace, User in another).
FR-4: Sign out and a profile menu (name, role) are available in both areas [MOCKUP]. The Appearance (theme) control is hidden in the MVP because only the light theme ships; it returns with the dark theme [UX DECISION]. Idle timeout is TBD (§12.1). Profile & settings lets users edit name, avatar, password, locale and time zone [PROPOSED] (theme preference added with the dark theme). Help & support links to help content configured by the Workspace Admin plus "Contact your workspace administrator" [MOCKUP + PROPOSED].
FR-5: All Admin pages and APIs require the Admin role, and permissions are enforced server-side. A denied attempt returns "not authorized" and is audited.
FR-6: In User configuration [MOCKUP], an Admin can invite and deactivate users, assign User or Admin, assign Admin permissions (including the publish permission), and assign user groups.
FR-7: An Admin can limit each Block and Dashboard Template to all users or to selected user groups; users see only allowed Blocks in the Add-blocks Panel, Templates and search. An access change does NOT create a new version [UX DECISION B3]; it applies immediately after a short impact confirmation (e.g. "38 users will lose this block") and is audited. Changing access requires the dedicated `access.manage` Admin permission [CONFIRMED 2026-10-05; architecture Q-A1]. If a user's group loses access to a Block already on their Dashboard, the Block shows "This block is no longer available to you." with a Remove action and shows no data [UX DECISION B1].
FR-8: When an Endpoint must return user-specific data (e.g. only their region), the Admin binds Endpoint parameters or headers to user context (user ID, email, group, or an attribute set in User configuration) [PROPOSED]; the API is responsible for filtering. Dashflow never lets a user change these bound values. A Block with no user-context binding shows the same data to everyone with access, and the Admin sees a "shared data" badge while configuring it.
FR-9: An Admin registers a Data Source with name, base URL, auth type (API key, bearer token, OAuth2 client credentials or basic) [PROPOSED], default headers, timeout, and maximum response size and number of pages. Credentials are encrypted and, after saving, never shown again or sent to the browser.
FR-10: All API calls are made server-side, and the base URL must match the Workspace's host allowlist (SSRF prevention). Loopback, link-local and cloud-metadata addresses are always blocked. Private-network addresses are allowed only when the platform operator explicitly allowlists them for that Workspace, and each such entry is audited [PROPOSED] (a future connector/agent remains possible, NFR-14). Redirects to non-allowlisted hosts are refused. A blocked attempt is rejected and audited.
FR-11: In the block wizard the Admin selects a Data Source and enters a method and path (e.g. `GET /api/v2/finance/revenue`) [MOCKUP]. Parameters can be fixed values, bound to the Date Range (`from`/`to`), bound to the Block Period Selector, or bound to user context (FR-8). GET is the default; POST is allowed only when the Admin marks the endpoint as a read-only query [PROPOSED]. Dashflow never sends a request that modifies data.
FR-12: The Admin can test an Endpoint, which shows status, latency and Sample Response. Each Data Source shows its health (healthy, degraded or unreachable) and the time of its last successful call.
FR-13: Configured pagination (page, offset, cursor or link header) is followed up to the limits; calls are rate-limited per Data Source; identical requests (same endpoint, resolved parameters and user-context values) within the Refresh Interval share one fetch. A response over the limits produces an Admin-visible error and is never silently truncated in a way that changes totals. Conditional requests are preferred [CONFIRMED]: when an API returns ETag or Last-Modified, Dashflow sends If-None-Match or If-Modified-Since on the next fetch; a 304 Not Modified reuses the cached result without re-transforming it and updates the "checked at" time while the Data-as-of Time is unchanged; APIs without these headers are re-fetched on each interval. The MVP supports REST APIs returning JSON only [CONFIRMED]; non-JSON responses are rejected with a clear message.
FR-14: In step 1 (Configure Block) the Admin enters Block name*, Description* (helper: "Help users understand what this block shows"), Category and Block type [MOCKUP]. An icon is chosen from a set or defaults from the category [PROPOSED]. (The wizard is five steps [UX DECISION 2026-10-05]: Configure Block -> Data Source -> Map Data -> Preview -> Save/Publish; a sticky Live preview panel with Desktop/Tablet/Mobile toggles and a size label such as "8 columns x 360px" stays visible on the right in steps 1 to 3 [MOCKUP].)
FR-15: In step 2 (Data Source) the Admin selects a Data source and an API endpoint (FR-11), a Refresh interval (e.g. Live ~30 s, 1, 5, 15 or 60 minutes, daily) and a Default date range (e.g. today, this week, this month, current year, custom) [MOCKUP], then obtains a Sample Response via "Fetch from API" or "Paste sample JSON" (FR-21).
FR-16: The Map Data step contains the API Data Mapper (§4.5) and follows the loop Auto-map -> Review -> Override -> Preview [UX DECISION]. Admins assign Field Roles; Dashflow auto-fills Slots and shows a confidence level for each. Admins review the slot checklist and confirm or override auto-mapped slots; an override also updates that Field's Role, with Undo. Clicking a region of the live preview is a shortcut to change that Slot's field. "Continue to Preview" stays disabled until every required Slot is mapped AND every low-confidence or required Slot is confirmed; high-confidence auto-maps are accepted automatically [UX DECISION].
FR-17: As part of step 1 the Admin sets display & behavior [MOCKUP]: Default width in grid columns; Default height (Small, Medium 360 px, Large); toggles Show block header, Show subtitle, Allow resize, Allow minimize, Allow refresh, Show footer action. The Admin can also set minimum and maximum resize size [PROPOSED]. Period behaviour [PROPOSED] is one of: Follow dashboard (default), Own selector (shows a Block Period Selector, FR-35), or Not date-filtered (e.g. a current-status list). The Default date range (FR-15) is used when the dashboard has no Date Range, and as the initial value of an Own selector.
FR-18: The live preview renders the Block with real Sample Response data, applies Slot Mapping and Presentation Rules, and updates on every change; on request it shows Unavailable, Stale and empty states. The Preview step shows the Block full-size on Desktop, Tablet and Mobile and inside a sample dashboard grid. When user-context bindings exist (FR-8) the Admin can "preview as" a chosen user or group. The step lists validation warnings, which must be resolved or acknowledged before Publish.
FR-19: "Save draft" stores progress at any step; drafts appear under Draft blocks [MOCKUP] with status badge "Draft". Concurrent editing uses a soft lock [UX DECISION]: while one Admin edits a Draft a second Admin sees "<name> is editing this draft (since <time>)" and a read-only view with "Take over editing"; taking over first saves the holder's work, then notifies them. The lock releases after about 15 minutes of inactivity or when the holder closes the tab (accepted default).
FR-20: "Publish block" publishes the Block directly to the Block Library only if the Admin holds the publish permission [CONFIRMED]; Admins without it can only save drafts. The step summarises the version to be created (e.g. "1.0") [MOCKUP] and any validation warnings. Publish is enabled only after a successful validation against the real configured API, where the real response must match the Slot Mapping; a Sample Response, even a pasted one, is never enough to publish [UX DECISION A1]. Admins without the publish permission see the Publish button disabled with "You don't have permission to publish this block." [UX DECISION A2].
FR-21: The Admin either fetches a sample (calls the Endpoint with current parameter values) or pastes sample JSON [UX DECISION D2]. Pasted JSON is validated (e.g. "Valid JSON · 3 records found at data.monthly[]"); invalid JSON shows where parsing failed. A pasted sample is labelled "Sample data · used for mapping and preview only" for the rest of the session, is never stored outside its Draft (where it is kept encrypted only so autosave can restore the wizard, and deleted when the Draft is published or discarded), and is never used as a production source [CONFIRMED 2026-10-05; architecture C5]. The response is shown as a collapsible JSON tree (table view available for arrays); each node shows its path (e.g. `data.monthly[].revenue`), inferred type and example value. The Admin can re-fetch with different parameters, and with user-context values set to a chosen user ("fetch as user").
FR-22: For Block Types that need rows (charts, tables, lists, feeds, calendars) the Admin picks the Record Path (the array that becomes rows); single-value Slots (e.g. KPI headline) can point to any scalar outside the array. For nested responses Dashflow lists candidate arrays with row counts and offers a field-tree browser for picking any path by hand. When a nested array is flattened an "Aggregate rows by..." control appears, defaulting to summing the Measures grouped by the Time field, and showing row counts before and after [UX DECISION].
FR-23: The Admin assigns each Slot a Field through Field Role auto-fill, a field picker with search, or by clicking the preview; dragging fields onto Slots is not supported [UX DECISION A3]. Slots can also take a Calculated Field or static text (e.g. "Total revenue"). Required Slots are marked *; type mismatches are rejected (e.g. text in a numeric Y-axis). Types are inferred and can be overridden (e.g. string "2026-01" parsed as a month). Changing the Block type after mapping keeps Slots with the same name and type and flags the rest as unmapped; nothing is silently dropped.
FR-24: The Admin can add Transforms applied in this order: filter -> row-level calculated fields -> group -> aggregate -> aggregate-level calculated fields -> sort -> top-N (e.g. group by region, SUM revenue, sort descending, top 5). Transforms are declarative, run server-side, and no scripts are allowed.
FR-25: Calculated Fields use a restricted expression language with arithmetic, comparisons, `IF`, safe division, `ROUND`, `ABS`, percentage change `PCT_CHANGE(current, previous)`, and date parts [PROPOSED function set].
FR-26: Any text Slot (including Title, Subtitle, labels, list subtitles, captions) can take a Text Template: literal text mixed with tokens, each with optional formatting [PROPOSED]. Tokens: `{field}`, `{field|currency}`, `{ROW_COUNT}`, `{period.start|MMM d}`, `{period.end|MMM d, yyyy}`, `{user.name}`. Mockup examples: "{ROW_COUNT} requests need your attention"; "{category} · {days} days"; "{done}/{total} tasks"; "{period.start|MMM d} – {period.end|MMM d, yyyy}". Text Templates are rendered as escaped text (FR-37).
FR-27: A comparison Slot (KPI card and KPI & Chart) is filled either (a) by a mapped previous value from the same response, or (b) by a comparison request, i.e. the same Endpoint called again for the prior period (previous period of equal length, same period last year, or custom offset), computed from the active period [PROPOSED]. Comparison mode is one of % change (`PCT_CHANGE`), absolute delta, or percentage-point delta (for ratios). The comparison label is a Text Template (e.g. "vs last month"). One primary Endpoint per Block in the MVP; Blocks joining several different Endpoints are a non-goal (§9).
FR-28: A Calculated Field can be marked as a ratio with a declared numerator and denominator. When a ratio is aggregated across rows, Dashflow sums numerators and denominators separately (e.g. rows 600/1,000 and 450/500 give 70.0%, not the 75.0% from averaging 60% and 90%).
FR-29: For every mapped Slot the Admin sets Presentation Rules. Format: number / integer / decimal places / thousands separator / percent / currency (code and symbol) / compact (1.2k, 3.4M) / date and time pattern / relative time ("8 min ago", "Today", "Tomorrow") / duration / units suffix. Emphasis: headline (large, bold), primary, secondary (muted) or badge; size; weight. Colour rules: the arrow shows the actual direction of change (up/down) while colour shows whether it is good: green when it moves in the Block's Direction, red when against it; threshold bands add colour, icon and label. Label: text, prefix and suffix (e.g. "vs last month"). Empty and null display (e.g. "—"). Colour is never the only signal; an arrow, icon or label always accompanies it.
FR-30: Before saving, the mapping is validated for required Slots, types, aggregations and expressions. At run time, if a mapped field is missing or has the wrong type the Slot shows "Unavailable: <field> missing", never zero. In tables and lists, rows with a missing value show "—" in that cell and the Block shows a "partial data" badge [PROPOSED]. When a mapping fails repeatedly, the Block's owner Admin is notified.
FR-31: Normative mapping example: for `GET /api/v2/finance/revenue` returning `{data:{total:284680, previous_total:249195, currency:"USD", monthly:[{month:"2026-01",revenue:10200},{month:"2026-02",revenue:12650}]}}`, a KPI & Chart Block maps Title/Subtitle to static "Revenue overview" / "Financial performance · Current year" (header); KPI label static "Total revenue" (secondary); Headline value `data.total` (headline bold large, currency USD, 0 decimals -> $284,680); Comparison `PCT_CHANGE(data.total, data.previous_total)` (percent, 1 decimal, Direction = higher is better -> "↗ 14.2% from last year", green); Chart X `data.monthly[].month` (date "MMM" -> Jan, Feb); Chart series 1 `data.monthly[].revenue` (currency compact; line style, area fill). The rendered Block matches the mockup's "Revenue overview" preview.
FR-32: Every Block Type has common Slots Title, Subtitle, Icon and Footer action (label plus link or "View all" target). MVP Block Types and slots (* = required): KPI card: Headline value*, KPI label*, comparison value or change %, comparison label, secondary note, status badge. KPI & Chart: KPI card slots plus Chart X*, Chart series* (1-n). Line/Area chart: X* (date or Dimension), series* (1-n Measures), series labels, highlighted point, target line. Bar chart (grouped/stacked): Category*, values* (1-n), stack/series key. Pie/Donut: Category*, value*, centre label/value. Table/Data grid: Columns* (each: Field, header, format, alignment, width, sortable), row highlight rule, pagination size. List: Item title*, item subtitle, avatar/initials or icon, right value, right meta, status. Progress list: Item title*, progress* (value/total or %), caption, colour rule. Activity feed: Actor*, action text*, object, timestamp* (relative), avatar. Calendar/Agenda: Date*, start time*, title*, subtitle/location, duration, colour/category, week strip with today highlighted. Text/Status: Text or status value* with colour rule. KPI card, bar, line, pie/donut and table are [CONFIRMED]; KPI & Chart, list, progress list, activity feed and calendar are [MOCKUP]; Area and text/status are [PROPOSED]. A group of several KPI cards (mockup top row) is four separate KPI card Blocks, not one Block type [PROPOSED]. Each Slot declares a value type (number, text, date/time, boolean or Text Template), a cardinality (single or per row), and for numeric per-row chart Slots a default aggregation used when rows are grouped.
FR-33: A developer can add a Block Type by supplying its slot schema, configuration schema, size limits and renderer, with no change needed to the wizard, mapper or dashboards [PROPOSED].
FR-34: Each Block shows, as configured [MOCKUP]: icon, title and subtitle; refresh (if allowed); minimize (if allowed); a ⋯ more menu with refresh, view definition ("About this block": description, data source name, refresh interval, Data-as-of Time, version), "View as table" for every chart (accessible text alternative) [UX: accessibility review], and remove from dashboard; and an optional footer action (e.g. "View all approvals ->") whose target is either (a) an external URL template with field tokens (e.g. `https://erp.example.com/approvals?user={user.email}`; http(s) only) or (b) an expanded view: the Block full-size with a paginated table of all its rows [PROPOSED].
FR-35: When a Block's period behaviour is Own selector (FR-17) it shows a period dropdown (e.g. "This year") [MOCKUP]. Precedence [PROPOSED]: (1) the user's Block Period Selector choice; (2) otherwise the dashboard Date Range (for Follow dashboard Blocks); (3) otherwise the Block's Default date range. Not-date-filtered Blocks ignore all of these.
FR-36: Every Block has a visible presentation for each state: loading (skeleton); empty ("No data for this period"); error (plain message + retry); Stale (last values greyed + "Stale: last data <time>"); Unavailable (FR-30); minimized (header only).
FR-37: API values are always rendered as text, escaped and never interpreted as HTML. Footer links accept only http(s) URLs or in-app routes [PROPOSED].
FR-38: Admins have Draft blocks and Published blocks lists showing name, category, type, version, owner, last updated and status [MOCKUP]; lists can be filtered by category, type and owner [PROPOSED]. (Lifecycle, no approval workflow in MVP [CONFIRMED 2026-10-05]: Draft -Publish (requires publish permission)-> Published as a new Block Version; Published -Edit-> new Draft of next version while the Published version stays live; Published -Unpublish-> Unpublished, hidden from Add-blocks Panel, existing Block Instances show "This block is no longer available" [PROPOSED]; Published/Unpublished -Archive-> Archived, read-only. Version-safe publishing [MOCKUP]: publishing creates version 1.0, later edits create a new version so existing user layouts remain stable.)
FR-39: When an Admin publishes a new version of an already-published Block or Template, Dashflow first shows a diff from the current version (mapping, presentation, chrome, endpoint) and the impact (number of users who have the Block on a Dashboard, and the Templates that include it). The Admin confirms, then the new version goes live [PROPOSED]. A first publish shows only the validation summary.
FR-40: Only Admins holding the publish permission can publish, unpublish, restore and archive Blocks and Templates; Admins with create/edit permission only can save drafts. Every publish action is audited (FR-68) [CONFIRMED]. Changing Block and Template access requires `access.manage` (FR-7), not the publish permission [CONFIRMED 2026-10-05].
FR-41: Every version is immutable and viewable. Restore creates a new Draft from an old version, and publishing that Draft creates a new version. If a Draft already exists, Restore first asks "Replace your current draft with v1.1?" (Replace draft / Cancel); a pending Draft is never discarded silently [UX DECISION B2].
FR-42: When a new Block Version is published, every Block Instance shows it on its next refresh. Propagation never changes a user's layout: position and minimized state are kept; size is kept unless the new version's size limits exclude it, in which case it is clamped to the nearest allowed size [PROPOSED reconciliation of "user layouts remain stable" with propagation].
FR-43: Admins create, rename, order and archive Block categories [MOCKUP]. A Block has one primary category and can have additional tags. Category chips in the Add-blocks Panel are exactly the Block Categories that have at least one Block available to the user; tags are searchable but do not create chips [PROPOSED].
FR-44: Block management lists all Blocks with search and filters (category, type, status, owner) and the actions edit, duplicate, unpublish, archive and view versions [MOCKUP].
FR-45: An Admin arranges published Blocks into a Dashboard Template with name, description, default layout and access (FR-7), and can mark some Blocks as Mandatory. Templates follow the same publish lifecycle as Blocks (§4.8) [MOCKUP + CONFIRMED].
FR-46: An Admin chooses which Template seeds every new user's Overview dashboard [PROPOSED].
FR-47: Updating a Template affects only dashboards created after the update; existing user dashboards are not rearranged [PROPOSED]. Exception: a newly Mandatory Block is added to existing dashboards created from that Template, at the end of the layout.
FR-48: Admins see how many dashboards were created from each Template (feeds "Template adoption", FR-65).
FR-49: Each user has an Overview dashboard (seeded by FR-46) and may create more under My dashboards: blank, from a Template, or by duplicating an existing dashboard. Dashboards can be renamed and deleted; Overview cannot be deleted [PROPOSED].
FR-50: Templates lists the Dashboard Templates the user may use, with a preview; "Use template" creates a new dashboard.
FR-51: The dashboard switcher changes the current dashboard. The Date Range applies to Blocks whose period behaviour is Follow dashboard (FR-17, FR-35), and is remembered per dashboard.
FR-52: "+ Add block" opens the right-side Add-blocks Panel [MOCKUP] titled "Add blocks — Build a dashboard that works for you", containing: search; category chips (All plus categories); a Filter (type, data source); a count ("8 available blocks"); one list row per Block with visual thumbnail, name, one-line description, category tag, "New" where applicable, and "✓ Added" or "+ Add", plus an inline Preview that expands in the list to show a larger sample rendering and metadata (data source, refresh rate, version); and a separate "On your dashboard" group for Mandatory and system Blocks with a lock indicator, excluded from the available count, still searchable [UX DECISION]. "Done" closes the panel. Panel behaviour [UX DECISION]: right-side drawer with dashboard visible; on wide screens it pushes the dashboard narrower and collapses the left sidebar to an icon rail; below about 1024 px it overlays the dashboard as a sheet; multi-block add is out of the MVP.
FR-53: "+ Add" places the Block automatically at the first suitable free grid position at its default size, calculated on the user's saved full-width layout; existing Blocks are never rearranged. The new Block appears immediately with a brief highlight and a toast offering "Show me" and "Undo"; if there is no free space in view the Block goes below and the toast offers Show me [UX DECISION D4]. Blocks cannot be dragged from the panel onto the grid [UX DECISION A4; supersedes v3.1 drag]; after adding, the user moves/resizes in Edit Layout Mode. Remove works from the Block's ⋯ menu or from the panel, is immediate with an Undo toast and no confirmation dialog [UX DECISION A6]; Mandatory Blocks cannot be removed and the reason is shown. The same Block cannot be added twice to one dashboard [PROPOSED].
FR-54: In Edit layout mode, users drag Blocks to reorder them and resize them within each Block's allowed sizes (FR-17), if resize is allowed. Leaving the mode saves the layout. Undo is available while editing [PROPOSED].
FR-55: Users can refresh, minimize or expand Blocks, change a Block Period Selector, and open "About this block". Users cannot change a Block's mapping, type or presentation [PROPOSED].
FR-56: Dashboards, layouts, minimized states, period selections and Date Ranges are saved on the server per user and Workspace and restored on any device.
FR-57: A user can reset a dashboard created from a Template back to that Template's current layout.
FR-58: Desktop shows the full grid; tablet a reduced number of columns; mobile a single column in the user's order, where drag reordering is available but free resizing is not [PROPOSED]. The Add-blocks Panel opens as a full-height sheet on mobile.
FR-59: Adding, removing, reordering and resizing are possible without drag-and-drop through keyboard and menu actions (e.g. "Move up", "Make wider") [PROPOSED]. Single-key shortcuts (e.g. `/`, A, M) can be turned off via a "Keyboard shortcuts" setting in Profile & settings, on by default [UX DECISION R6; WCAG 2.1.4]. Toasts carrying an action (Undo, Show me) stay at least 10 s and stay while focus is in the toast or drawer. Ctrl/⌘+Z undoes the last add or remove with no time limit [UX DECISION R3].
FR-60: Each Block re-fetches on its Refresh Interval without a page reload. The shortest interval, Live (~30 s), is offered only if the Admin marks the Data Source as able to support it [PROPOSED]. Users' manual refresh is rate-limited. A "Pause live updates" toggle in the dashboard header stops automatic refresh and shows "Paused · data as of <time>"; user-initiated refreshes (refresh button, Date Range, period) still run [UX DECISION R4; WCAG 2.2.2]. A Block can be no fresher than its API: the Data-as-of Time shows the real data age.
FR-61: A Block becomes Stale when its source has not been successfully checked within 2 x its Refresh Interval [CONFIRMED 2026-10-05; architecture C12]; a successful check that finds unchanged data (e.g. 304 Not Modified) keeps the Block fresh while the Data-as-of Time still shows the real age of the data. On a lost connection a "Reconnecting…" banner appears and Blocks refresh automatically when the connection returns. A Block never silently mixes results fetched at different times.
FR-62: Every Block shows its Data-as-of Time in "About this block", and inline when the data is Stale. The dashboard header shows the oldest Data-as-of Time of the Live Blocks [PROPOSED].
FR-63: Global search ("Search anything", ⌘K) [MOCKUP] finds, within the active Workspace and the user's permissions: dashboards, Blocks (in the Add-blocks Panel), Templates, and for Admins also data sources, users and settings.
FR-64: The in-app notification bell [MOCKUP] shows, for Users: new Blocks available, a Block on their dashboard updated to a new version, a Block unpublished; for Admins: publications by other Admins in the Workspace, data source health alerts, and repeated mapping failures. Email notifications are deferred [PROPOSED].
FR-65: The Admin overview [MOCKUP] shows Published blocks (with change this month); Active dashboards (with change this week); Template adoption (% of dashboards created from a Template); Active users (count and share active this week); Recent block activity (published or draft, by whom, when, with "View all" link); and a Create block button. Metric definitions (e.g. active window) are TBD in UX. (Admin navigation [MOCKUP]: Admin overview, Block management, Create block, Draft blocks, Published blocks, Block categories, Dashboard templates, User configuration, System settings; v3 adds Data sources and Audit log [PROPOSED].)
FR-66: A Platform health panel shows to the Workspace's Admins the status and uptime of shared platform services (Dashboard API, Data refresh service, Authentication, Notification service) [MOCKUP] with an overall "Operational" badge, and the health of their Workspace's Data Sources.
FR-67: Admins configure Workspace settings: the host allowlist (FR-10); audit and log retention periods [CONFIRMED: configurable; default values TBD per customer]; allowed refresh intervals; fetch limits; locale, time zone and currency defaults; theme default (light only in MVP; setting appears once the dark theme exists); the Template for new users (FR-46); and the user attributes available for user-context binding (FR-8).
FR-68: Dashflow records immutable Audit Events for data source, endpoint, Block, category and Template changes; publish, unpublish, restore and archive; role and access changes; allowlist changes; sign-in success and failure; denied access; and blocked outbound requests. Each event records user, time, object and before/after state, and Admins can search and export them. User layout changes are not audited [PROPOSED]. Retention is configurable per Workspace (FR-67); default value TBD per customer [CONFIRMED].

### NonFunctional Requirements

NFR-1: Freshness: Live is about 30 s where the source supports it [CONFIRMED earlier]; other intervals as configured. End-to-end target is TBD and bounded by the API.
NFR-2: Performance: Dashboard first render with N Blocks, fetch and transform p95, and maximum Blocks per dashboard are TBD; validated from the first customer's expected usage [CONFIRMED].
NFR-3: Scale: Numbers (Workspaces, users, concurrent users, Blocks, dashboards, API calls per minute) are TBD, with initial targets from the first customer [CONFIRMED]. The architecture must scale horizontally [CONFIRMED]: stateless application nodes, shared cache, and queue-based fetch workers [PROPOSED].
NFR-4: Security: Baseline security is required whatever the domain [CONFIRMED]: access control, audit logging (FR-68), encryption in transit (TLS) and at rest (secrets and stored data). MVP exception [CONFIRMED 2026-10-05; architecture C16]: outbound calls to source APIs may use plain http; such Data Sources are badged "Not encrypted" and audited, and a `require_https` setting enforces TLS later without code changes. Browser-to-platform traffic always uses TLS. Also required: encrypted secrets, server-side authorization, SSRF protection (FR-10), no user scripts, escaped rendering (FR-37), CSRF protection, sign-in throttling, secure password reset, Workspace isolation (FR-3).
NFR-5: Data protection: Dashflow persists configuration, user layouts, audit records, caches and, per API request, the latest source response as a durable last-known-good copy (for stale display, change detection and remapping without refetching). Longer retention is opt-in per Data Source; unused per-user copies are purged. Platform history features remain post-MVP [CONFIRMED 2026-10-05; architecture C1]. Compliance regimes (e.g. HIPAA, GDPR, PCI DSS) are TBD until the target customer and domain are confirmed [CONFIRMED].
NFR-6: Availability: Uptime, RPO/RTO are TBD. Platform health is visible (FR-66).
NFR-7: Accessibility: WCAG 2.1 AA [PROPOSED default]; keyboard alternatives for layout editing (FR-59); colour is never the only signal.
NFR-8: Browsers: The latest two versions of Chrome, Edge, Firefox and Safari, desktop and mobile [PROPOSED default].
NFR-9: Devices: Desktop, tablet and mobile web [MOCKUP preview toggles].
NFR-10: Theming: Light theme only in the MVP [UX DECISION V3; supersedes "light and dark"]. Colour tokens are semantic so a full dark theme can be added later without redesign. Workspace branding can later override only accent and logo (brand tokens); system colours stay fixed (DESIGN.md).
NFR-11: Localisation: Locale-aware number, currency and date formatting, with Workspace defaults (FR-67).
NFR-12: Extensibility: New Block Types (FR-33) and future source types are added as modules; no domain logic in the core.
NFR-13: Observability: Metrics and logs for fetches, mapping failures, refresh lag and health (FR-66); log retention is configurable (FR-67).
NFR-14: Deployment-agnostic: Supports both cloud and on-premises/private infrastructure deployment [CONFIRMED]. No coupling to a specific hosting provider or managed service: configuration via environment, standard containers, pluggable storage, cache and queue [PROPOSED]. Final model per client depends on security policy, network access, data residency and infrastructure. The design must leave room for a future connector/agent to reach private-network APIs [CONFIRMED].

### Additional Requirements

**Starter template**

- AR-1: STARTER TEMPLATE (Epic 1 Story 1). Initialise from the Laravel Vue starter kit (Laravel 13, PHP 8.5, Inertia 3, Vue 3.5, TypeScript 5, Tailwind 4, shadcn-vue on reka-ui 2, Fortify auth, Wayfinder typed routes, Vite 8, Node 22 LTS). Required modifications: public registration disabled; Fortify two-factor feature disabled (no MFA in MVP, C13; re-enableable later); Teams scaffold not used (tenancy is Workspace per AD-3); Sanctum added via `php artisan install:api` (same-origin SPA auth); Pest 5 replaces the kit's PHPUnit; ext-bcmath and ext-uv required (composer + image); Node 22 in build. Also add Horizon 5, Reverb 1, Pinia 4, vue-i18n 11, ECharts 6.1 / vue-echarts 8.3, opis/json-schema 2.6, symfony/json-path 8.1 (dev, test oracle only), OTel PHP SDK 1.15 / auto-laravel 1.9. Acceptance: fresh clone builds, sign-in works, registration route 404, 2FA routes absent, `pest` runs green.

**Binding decisions**

- AR-2 (AD-1): One codebase, one image, five process roles: `web`, `realtime` (Reverb), `scheduler` (`schedule:run` each minute, tasks `onOneServer()`, dispatcher every `dispatch_tick` with `onOneServer()`), `worker-connector` (Horizon queues `fetch-interactive`, `fetch-scheduled`), `worker-compute` (`compute`, `outbox`, `notifications`, `maintenance`). A new deployable requires a new AD. FR-66 services are logical components mapped to roles.
- AR-3 (AD-2): Module layout `App\Modules\{Module}\{Contracts,Domain,Application,Infrastructure,Http}` plus kernel `app/Platform/{Tenancy,Outbox,Audit,Operations,EditLock,Layout,Period}`. Two Pest architecture tests fail CI: forbidden namespace edge (source of truth `tests/Architecture/dependencies.php`), and any `DB::table()`/query naming another module's table (table ownership in each module README). Kernel calls no module; no reverse edges (use event subscription + owned read models); no domain vocabulary (RMG) in core code/schemas/seeds; Blocks/Templates register subjects via `Access\Contracts\RegisterAccessSubject` in the same transaction.
- AR-4 (AD-3): Row-level tenancy. Every tenant table has non-null `workspace_id`, `ENABLE`+`FORCE ROW LEVEL SECURITY`, policy `workspace_id = current_setting('app.workspace_id', true)::uuid` (unset = zero rows). `WorkspaceTransaction` middleware (HTTP and job) is the only opener of the request/job transaction and caller of `set_config(..., true)`. CI test: query with no context returns zero rows. CI test: migration creating a table without `workspace_id` fails unless it is a global table (`users`, `sessions`, `password_reset_tokens`, `invitations`, `service_health_samples`, `operator_audit`, framework job tables).
- AR-5 (AD-3): DB roles `app` (no BYPASSRLS), `migrator` (owns tables), `maintenance` (only role with DELETE), `system` (column-limited cross-tenant access to `sync_targets` dispatch columns and `outbox_events` relay columns; dispatcher and outbox relay only), `operator`. System-enqueued jobs carry `workspace_id`, re-enter tenant context and re-read IDs under RLS; mismatch = hard failure + security event. Cross-workspace membership lookup only via one `SECURITY DEFINER` function owned by Access. Every cache key, lock, queue payload, object key and channel name is prefixed with workspace ID; cache hits re-check `workspace_id`.
- AR-6 (AD-4): `users` global; role/permissions/groups/attributes belong to `workspace_membership`. Session holds active workspace and area (User/Admin); admin endpoints need area=Admin AND permission key. `Access\Contracts\AccessEvaluator` is the only visibility definition: `visibleSubjectIds(type, membership, area)` returns an SQL fragment over Access tables; `canUse()` = `EXISTS` over same fragment; other modules filter only with `WHERE id IN (fragment)`. Property test compares PHP and SQL paths. No decision cached beyond the request. Access owns attribute-key catalog and values; grant/attribute/permission change commits with audit + `access.*.changed` outbox event.
- AR-7 (AD-5): Grants keyed by Block/Template identity, never version. Publish, restore, archive never read/write grants. Grant change effective on next request and audited. Test: restore does not re-grant.
- AR-8 (AD-6): Only `worker-connector` calls source APIs, OAuth token URLs and pagination URLs, through `FetchTransport` (`direct` in MVP; `agent` later). `FetchRequest` carries `secret_ref`s + credential scheme, never values. One `EgressGuard` on every URL: allowlist match; resolve all A/AAAA; deny-list on parsed binary addresses (loopback, link-local, metadata, IPv4-mapped/compatible, NAT64, 6to4, Teredo, ULA, CGNAT, unspecified, multicast); private ranges only with operator grant; deployment's own CIDRs undeniable; pin IP with `CURLOPT_RESOLVE`, curl handler only, http(s) only. Test matrix covers each address class and DNS rebinding.
- AR-9 (AD-6): Pagination next URL must share the Endpoint's origin; cross-origin redirects refused; credentials stripped on origin change; https-to-http redirect downgrade always refused; proxy mode enforces same policy. Plain http allowed in MVP (C16): persistent "Not encrypted" badge for Admins, creation audited, `require_https` setting (Workspace + deployment override, default off). Only GET, plus POST for read-only-flagged Endpoints (needs `data_sources.manage`, risk confirmation, audited, `Idempotency-Key`, no auto-retry on ambiguous failure, excluded from Live). Interactive calls are Operations.
- AR-10 (AD-7): `fetch_key = "fk1:" + hex(sha256(JCS(input)))` (RFC 8785), input `{v, workspace_id, endpoint_revision_id, data_source_revision, params:{name:{t,v}}, ctx}`; typed params (string/number/bool/date/datetime), dates workspace-local `YYYY-MM-DD`, datetimes UTC `Z`, absent bindings omitted; `ctx` = `"shared"` or `HMAC(digest_key_ws_v, "bound|"+JCS(attrs))`. Only `Ingestion\Contracts\FetchKeyResolver` computes it; golden-vector tests. Missing/invalid bound attribute = no fetch, item `unavailable` reason `access.context_missing`; Endpoint with `requires_user_context` cannot be published unbound. Optional `scope_by_caller=true` adds membership ID to ctx.
- AR-11 (AD-7): Ingestion owns `sync_subscriptions(sync_target, block_version_id, role primary|comparison, refresh_interval, compute_ctx, resolved_period, last_access_at, hot_until)`; Results registers/touches via throttled `Ingestion\Contracts\Subscribe`; interval copied at subscribe time. Effective interval = min over hot subscriptions; hot targets refresh at it, cold targets get only a health probe (one representative target per Endpoint revision). Dispatcher enforces per-workspace budgets: max hot keys, fetch rate per Data Source, new cold keys per membership per hour.
- AR-12 (AD-8): Change-detection cascade: `If-None-Match` if ETag stored, else `If-Modified-Since`, else sha256 of lossless-canonical body (whitespace/key-order normalized, number lexemes as received). 304 or equal hash: set `last_success_at`, keep payload, no compute. Conditional state resets when `endpoint_revision_id` or `data_source_revision` changes.
- AR-13 (AD-9): Three tiers. Raw: `raw_bodies` (`bytea`, never `jsonb`, content-addressed per sync target) + `raw_observations` (partitioned, immutable, never UPDATE). Config: Data Sources, Endpoint revisions, Block Versions, Templates. Derived: Block Results, disposable. `sync_targets.current_payload_id` is the only "latest". Retention per Data Source `latest` (default) or `window(N days)`; never removes current payload; superseded payloads swept after grace; cold targets purged after `cold_purge_after`; DELETE only for `maintenance`; pasted samples never enter raw tier.
- AR-14 (AD-10): All platform keys are UUIDv7 generated in app (`HasUuids` -> `Str::uuid7()`), no sequential IDs exposed. Source record identity = optional Dataset record-key path value, stored as text namespaced by `dataset_id`, never an FK; without it, position index.
- AR-15 (AD-11): `block_versions.config.query_plan` (`"v": N`) is the sole store of mapping/transforms/calculated fields/slots (no `slot_mapping`). Fixed stage order: extract, filter, row calc, group, aggregate, aggregate calc, sort, top-N, shape. `config.fields` snapshots field catalog; runtime never reads mutable Dataset rows. One server-side `QueryPlanEngine` behind `QueryExecutor` runs preview, validation and runtime, and every published plan `v` forever. Ratios keep numerator/denominator through grouping; missing/mistyped field = slot `unavailable`, never 0; guards abort, never truncate. Early spike (C6) sets preview latency target; TS evaluator is the fallback with conformance tests.
- AR-16 (AD-12): Stored paths are a strict RFC 9535 subset (member names and `[*]` only) evaluated over the lossless tree; `symfony/json-path` is the conformance oracle in tests; dotted UI form is display only. Calculated Fields, Text Templates, URL templates share one grammar with a closed function list, stored as AST + source text.
- AR-17 (AD-13): Block Drafts: at most one per Block (partial unique index `WHERE state='draft'`); every write is compare-and-set on `revision` AND `lock_epoch`; lenient draft schema vs strict validation schema; prospective version number fixed at creation. Publish needs `blocks.publish` and a passing `ValidationReport` bound to the current revision.
- AR-18 (AD-13): Two-phase publish. Tx1 `UPDATE ... SET state='prepared' WHERE state='draft' AND revision = report.revision` (emits `blocks.version.prepared`); Results precomputes hot keys up to `precompute_timeout` then calls `Blocks\Contracts\ActivateVersion`; Tx2 sets `published`, switches `current_version_id`, emits `blocks.version.published`. Maintenance job activates versions left `prepared` past timeout; uncached items show `pending`. DB trigger permits only draft edits, draft->prepared, prepared->published. "Superseded" derived, never stored. Samples only in `draft_samples`.
- AR-19 (AD-13): Restore creates a Draft; with an existing Draft requires `expected_draft_revision` and `replace_draft=true`; bumps `revision` (invalidates report). `Platform\EditLock` key `edit_lock:{ws}:{type}:{id}` for Drafts, Templates, Data Source forms; take-over increments `lock_epoch` in PostgreSQL after `platform.edit_lock.flush_requested`; old-epoch writes get `423 platform.edit_lock_lost`; take-over saves holder's valid non-secret fields (UX-1). Templates follow same rules.
- AR-20 (AD-14): `dashboard_item` references `block_id` (unique per dashboard+block) and renders current published version. Mutations only via closed command set, each `SELECT ... FOR UPDATE` on the dashboard, checks `expected_revision`, bumps it, emits `dashboards.dashboard.changed`: AddItem (idempotent), RestoreItem, RemoveItem (rejected for mandatory), MoveResizeItems (never creates/deletes), SetMinimized, SetPeriodSelection, SetDateRange, SetCompactOrder, AcknowledgeMandatory, ResetToTemplate, ApplyMandatory. Lifecycle: CreateDashboard, CreateFromTemplate, DuplicateDashboard, RenameDashboard, DeleteDashboard (never Overview).
- AR-21 (AD-14): `Platform\Layout` is a pure kernel (12 columns) for placement/clamp/collision, shared by Dashboards, Templates and `next_free_slot`; `fill` mode for user adds, `append` for mandatory propagation. Clamp is server-side, deterministic, never pushes neighbours (keeps old size and reports to Admin at publish). Templates never write dashboards: Dashboards consumes `templates.version.published{mandatory_added}` as system actor; inaccessible users recorded in `dashboard_pending_mandatory`, re-applied on `access.*.changed` (C18). Layout kernel conformance suite in CI.
- AR-22 (AD-15): `GET /api/v1/dashboards/{id}/results` returns hits immediately and `pending` for misses; a miss enqueues compute, or an interactive sync if no current payload; per-item endpoint may compute inline under single-flight lock. Access re-evaluated per item before serialization. Freshness only via `Results\Contracts\Freshness`: Stale when `now - last_success_at > 2 x viewing Block Version interval` (C12); `data_as_of` = mapped Data-as-of slot else payload `first_observed_at`; every result returns `stale_at`. All timestamps from DB clock. Nothing on read path calls a source API.
- AR-23 (AD-16): Reverb private channels `workspace.{ws}.membership.{m}` and `workspace.{ws}.block.{block}` (shared-data Blocks, authorized by `canUse`); channel auth re-checks DB; membership/role/access change forces re-authorization. Events carry IDs and `result_etag` only; clients refetch over `/api/v1`. Polling fallback with same semantics.
- AR-24 (AD-17): Valkey 9 non-cluster, two logical stores: `queue` (queues, locks, rate limits, Reverb fan-out; `noeviction`) and `cache` (results, tokens; LRU, every key has TTL). Sessions use DB driver. Valkey TLS, ACL users per role, NetworkPolicy isolation, persistence off or encrypted. Jobs carry IDs only; job payloads HMAC-signed by a queue-connector wrapper verifying before unserialize (spike confirms hook; fallback `allowed_classes`). Domain events, notifications, audit live in PostgreSQL.
- AR-25 (AD-18): Audited command writes its event in the same transaction as the change. Denials, sign-in outcomes, egress blocks, impersonation via `Audit::recordSecurityEvent` in an autonomous transaction. `action` uses AD-29 grammar from closed `AuditAction` enum; each module registers allowlist `AuditSerializer`; attribute/header values and samples stored as hashes only. `app` role has INSERT+SELECT only. Per-workspace retention via `maintenance` row deletes, never below operator floor; reductions delayed and audited. Operator/system actions to `operator_audit` mirrored into workspace log. CSV export neutralizes formulas (injection test).
- AR-26 (AD-19): Envelope encryption via `SecretVault` port (drivers: local keyring, cloud KMS, OpenBao/Vault transit), per-workspace DEKs per purpose: `cred` and `token` (worker-connector only), `data` (web + worker-connector; draft samples, attribute and context values), `digest` (HMAC key for AD-7, versioned). Keyring mounted only into roles needing it. APIs return `{configured, updated_at}` only. Unsaved Test connection uses transient secret row (TTL, owned by its Operation). Draft/form payloads reject secret-valued fields. Test: web role cannot decrypt `cred`.
- AR-27 (AD-20): Inertia pages carry shell, nav and admin CRUD form props only (dashboard pages `{dashboard_id, revision}`). Layouts, item states, results, layout commands, wizard preview/validation, Operations, search, notifications, session status go only via `/api/v1` with same-origin Sanctum SPA auth; typed `fetch` client sends `X-XSRF-TOKEN`, `X-Request-Id`, `X-Background: 1` (background calls never extend idle session). Error envelope `{error:{code, message, request_id, details}}`, `details` stripped in User area.
- AR-28 (AD-21): Block Type packages `block-types/{key}/v{contractVersion}/` with `schema.json` (single source for PHP and TS), pure `Shaper.php`, `Renderer.vue`. MVP keys: kpi-card, kpi-chart, line-area, bar, pie-donut, table, list, progress-list, activity-feed, calendar-agenda, text-status. Contract dir never deleted while a version pins it; contract upgrade creates a Draft; first-party build-time only. Renderers implement every state and use shared `useChart` (ECharts richText/encodeHTML); lint bans `v-html`, `innerHTML`, formatter strings; XSS fixture through every slot; charts offer View as table. grid-layout-plus 1.1 is spike-gated (gridstack 14 fallback).
- AR-29 (AD-22): Same signed image and migrations in every model (SaaS, dedicated, customer-hosted, hybrid). All config from environment. Every external dependency behind a port with self-hostable driver: PostgreSQL 18, Redis-protocol store, SMTP, OTLP, SecretVault, RawStore (pg now; S3 later). No proprietary managed service required.
- AR-30 (AD-23): No BI engine embedded in MVP; ECharts and grid lib are rendering only. Any Hybrid executor (SQL or Cube Core) only behind `QueryExecutor` reading the same Query Plan.
- AR-31 (AD-24): OpenTelemetry traces/metrics/logs over OTLP; JSON logs to stdout. Every span, log, Operation, sync run, error carries `request_id`/`trace_id` and `workspace_id`. Mandatory scrubbing processor strips query strings/fragments and drops request/response headers except an allowlist (test with canary secrets). `sync_runs` store sanitized URL template + parameter names only. Metric names `dashflow.<module>.<measure>`.
- AR-32 (AD-25): `Results` is sole writer of Block Results and owner of compute jobs. Key `res:{ws}:{block_version}:{primary_fk}:{compute_ctx}`, `compute_ctx = sha256(resolved period, comparison window, workspace tz, compute-affecting settings revision, engine version, shaper contract version)`. Value: versioned `BlockResult` DTO (`generation_id`, `payload_seq`, `data_as_of`, `last_success_at` (older of two for comparisons), `state` pending|ok|empty|unavailable|error|access_removed|unpublished, flags `stale`/`refreshing`, `slot_states`, locale-free user-free `render_payload`, `rowset_ref` rows before top-N). `result_etag = sha256(key, generation_id, v)`. Precedence `access_removed > unpublished > error > pending > unavailable > empty > ok`; `partial` derived, `loading` client-only. Fan-out recipients from Results' own `block_viewers` read model fed by `dashboards.dashboard.changed`.
- AR-33 (AD-26): Fenced sync runs: each dispatch increments `sync_targets.dispatch_seq`; run commits payload, conditional state, `current_payload_id`, `payload_seq` in one tx guarded by `applied_seq < :dispatch_seq`; late runs recorded `superseded`; Valkey lock is optimization only. Comparison = sync group fetched in one run writing one `sync_generations` row; compute uses only complete generations; failed comparison = comparison slot `unavailable`. `ingestion.payload.changed` is an outbox event; result writes via Lua CAS on `payload_seq`; maintenance sweep re-enqueues targets whose `payload_seq` is ahead of results. Race tests required.
- AR-34 (AD-27): `Platform\Period\PeriodResolver` is sole implementation of FR-35: pure function from item selection, dashboard range, Block period behaviour/presets, workspace tz -> `ResolvedPeriod{token, start, end, comparison?}` half-open in workspace-local dates. Workspace tz for resolution, user tz display only; only Block-enabled presets accepted; client dates never accepted. Ingestion pre-warms next window for hot rolling targets within `prewarm_lead` (with jitter). Preview/validation call resolver with Block default.
- AR-35 (AD-28): `Platform\Operations` kernel owns `operations` (kind, requester membership, subject, `subject_revision` at enqueue, status, expiry) and the handler registry; never stores bodies in PG. Kinds: `connection_test` (Connector); `sample_fetch`, `fetch_as_user`, `publish_validation` (Ingestion); `template_validation` (Templates via Blocks contracts); `audit_export` (Audit kernel). Result body passed as encrypted Valkey blob with TTL to the requester's `OperationHandler`; report whose subject revision moved stored `stale`; results visible only to initiator; completion event `platform.operation.completed`; rate-limited per membership and workspace.
- AR-36 (AD-29): `Platform\Outbox`: `Outbox::emit` joins caller's transaction; envelope `{event_id, type, v, workspace_id, subject, subject_seq, occurred_at, actor, request_id, data}` (data = IDs/enums only); `type = {module}.{noun}.{past_verb}` with schema in emitter's `Contracts/Events`; consumers record `(consumer, event_id)` in `outbox_consumptions` and drop events older than subject's last applied `subject_seq`; relay uses role `system`, SKIP LOCKED, marks `sent_at`. Audit actions reuse same strings; error codes `{module}.{snake_case}` in `Contracts/ErrorCode.php` generated into a TS enum.
- AR-37 (AD-30): Missing context fails closed. Fetch-as-user and preview-as need separate `data.preview_as_user` permission; responses ephemeral (Operation blob, initiator only), never written to shared Draft (only value-free shape), each use audited and target user notified. Nobody edits own attributes/permissions. Attribute change = `access.attribute.changed` event invalidating affected results.
- AR-38 (AD-31): Fortify auth, registration disabled; invitations single-use, hashed, email-bound, capped at inviter's permissions; permission grantable only by a holder; last `users.manage` holder cannot be removed/downgraded; email/password self-service or operator-only; session ID rotates on sign-in, area change, workspace switch; no MFA in MVP (C13); `password.confirm` guards secret changes, private-host grants, permission grants and audit export. Session idle and Remember-me durations are pending_input.
- AR-39 (AD-32): Migrations expand/contract only, run before rollout (one-shot `migrator` job). Release N works with N-1 for DB schema, versioned job payloads, outbox events, `FetchRequest`, `/api/v1`. Workers drain on deploy; rollback = redeploy N-1. Every stored JSON doc has `schema_version`; owning module supplies pure read-time upcasters tested against stored fixtures; Drafts upcast on save, published rows never. Engine/shaper/renderer version retired only when a command reports zero pinned versions. CI gate: N-1/N compatibility test.
- AR-40 (AD-33): Payload bodies parsed only by platform lossless JSON decoder (`LosslessJson`, number lexemes kept as `DecimalLiteral`); `json_decode` banned in Ingestion, RawStore, Mapping, Results by architecture test. Arithmetic via `BcMath\Number` with one internal scale (pending_input). Measures sent to browser as decimal strings; shaper emits typed values + format descriptors; renderer formats with `Intl` (user locale, then workspace default, then `en`). `{user.*}` tokens in Text/URL templates resolved in browser from session profile; URL tokens percent-encoded per position, scheme and host fixed literals. Lossless-decoder conformance suite.
- AR-41 (AD-34): Backups: PostgreSQL PITR; SecretVault keyring/KMS backed up separately (loss = all secrets unreadable); Valkey never backed up. Rotation runbooks (tested): `APP_KEY` (`APP_PREVIOUS_KEYS`), DB role passwords, Reverb and Valkey secrets, workspace DEKs (re-wrap), digest keys (versioned, pre-warmed). Environments `local` -> `ci` -> `staging` -> `production`, promoting the same image. Support policy: current Laravel major with security support; PostgreSQL major upgrades within support window; documented N-1 to N upgrade path for customer-hosted. RPO/RTO/uptime are pending_input.

**Cross-cutting conventions (SPINE Consistency Conventions)**

- AR-42: Conventions enforced by lint/arch tests: `timestamptz` UTC from DB clock, ISO 8601 `Z` on wire; tables `snake_case` plural with `workspace_id` first; commands `VerbNounCommand`; events mirror AD-29 (`BlockVersionPublishedEvent`); Vue/TS layout `resources/js/{pages,modules,components/ui,lib/api,lib/charts,lib/format}`, one Pinia store per module, UI strings via vue-i18n from day one (English only); mutations = application command handler in one transaction (domain change + audit + outbox), controllers hold no logic; concurrency = `revision` CAS (+ `lock_epoch`) returning 409/423 with current state; all tunables in `config/dashflow.php` (env only); feature seams are ports (`FetchTransport`, `QueryExecutor`, `RawStore`, `SecretVault`, `IdentityProvider`), never `if` branches.

**Pipeline, queues and ingestion mechanics (SD §6, §14)**

- AR-43 (SD §14): Queues and roles: `fetch-interactive` (highest priority, per-membership and per-workspace caps), `fetch-scheduled` (fenced, per-Data-Source buckets), `compute` (`ShouldBeUniqueUntilProcessing` + Lua CAS), `outbox`, `notifications` (`notifications(source_event_id)` unique), `maintenance` (retention sweeps, partition mgmt, reconciliation sweep, health sampling, cold purge, lock cleanup, `failed_jobs` purge). Jobs versioned, ID-only, idempotent; Horizon supervised. Dispatcher: `FOR UPDATE SKIP LOCKED` under `system` role, increments `dispatch_seq`, advances `next_due_at`, enqueues `FetchJob(workspace_id, sync_group_id, dispatch_seq)`; lost jobs re-derived next tick.
- AR-44 (SD §6.1): Data Source config: auth types `none`, `api_key` (header preferred, query allowed with warning), `bearer`, `basic`, `oauth2_client_credentials` (token URL via EgressGuard, no redirects, JSON only under size cap, cached `oauth:{ws}:{ds}:{secret_version}` until `expires_in - skew`, refresh once on 401). Bound header values reject CR/LF and non-visible ASCII. Param bindings `fixed`, `period.start/end`, `user_context.<key>`; path segments percent-encoded, `/` `.` `..` rejected; templates parsed once into URL AST. Pagination `none|page|offset|cursor|link_header` bounded by `max_pages` and `max_bytes` (decompressed); on limit `connector.limit_exceeded`, nothing stored. JSON only (Content-Type AND successful parse), depth and byte limits before parsing. Live (~30 s) only on `live_capable` sources within budget; withdrawn intervals clamp with Admin notification and impact list.
- AR-45 (SD §6.4): Failure handling: exponential backoff with full jitter to `max_attempts`; honour 429/503 `Retry-After` (capped) and drain per-Data-Source token bucket; 401+OAuth refresh once then `connector.auth_failed`; other 4xx no retry; per-Data-Source circuit breaker (half-open probe); concurrency cap and per-workspace fairness; per-membership/workspace limits on Test, Fetch sample, Fetch as user, Validate, preview; manual refresh (FR-60) limited per membership per item with `retry_after`; Test connection errors collapsed to few user codes, repeated `connector.ssrf_blocked` raises alert.
- AR-46 (SD §6.5-6.7): Source health `healthy|degraded|unreachable` from rolling `sync_runs` window + breaker (304 = success); probe on Data Source save ("Checking..." until then); state change notifies Admins. Validation layers: paste (byte/depth limits, candidate Record Paths), Map Data, publish validation against REAL API incl. negative check for `requires_user_context`, per-payload drift check -> slot `unavailable` and `mapping_health_incidents` with match suggestions computed while old and new payloads both available. Sample vs production: fetched/pasted samples in `draft_samples` (`data` key, TTL), pasted never publishable, fetch-as-user never stored.

**Infrastructure and deployment (Epic 1 skeleton, Epic 11 full)**

- AR-47 (SD §16.2): One signed OCI image, multi-stage build: Composer; Vite on Node 22; PHP 8.5 with `bcmath`, `pgsql`, `redis`, `uv`; nginx (+php-fpm for web). Same image runs all five roles by command/env. Image signed in CI; SBOM published.
- AR-48 (SD §16.2): Docker Compose for single-host self-hosted and demo installs (all roles, PG 18, Valkey queue + cache, optional PgBouncer, migrator one-shot). Both Compose and Helm are MVP scope (Epic 11); Epic 1 delivers a packaging skeleton.
- AR-49 (SD §16.2): Helm chart for Kubernetes: HPA per role, PodDisruptionBudgets, NetworkPolicies (web has no egress to sources; only worker-connector egresses via EgressGuard/optional enforcing proxy), separate Secrets per key purpose, Valkey `queue` and `cache` as separate instances/databases with their eviction policies, optional PgBouncer (transaction mode, `max_prepared_statements` > 0, >= 1.21), migrator pre-install/upgrade hook, Helm lint in CI. Reverb needs raised file-descriptor limits.
- AR-50 (SD §4.1, §16): Key-to-role mapping enforced by deployment: `web` gets `data`+`digest`; `worker-connector` `cred`,`token`,`data`; `worker-compute` `data`; `realtime` and `scheduler` none. Scheduler is a single active instance (mutex). External deps: PG 18, Valkey 9 (or Redis-compatible), SMTP, OTLP collector; optional KMS/OpenBao; S3 post-MVP.
- AR-51 (SD §16.2): CI/CD on GitLab CI stages: Pint; Larastan; Pest unit/feature/architecture (RLS no-context, table ownership, dependency edges, `json_decode` ban, global-table migration guard); Vitest; Playwright e2e on Chromium, Firefox, WebKit + mobile emulation (NFR-8) with browserslist latest two versions; axe accessibility; Fetch Key golden vectors; conformance suites (Query engine, lossless decoder, layout kernel, XSS, RFC 9535 vs symfony/json-path oracle); dependency/license audits; SBOM; image signing; Helm lint. Environments local (Sail with Valkey) -> ci -> staging -> production promoting the same image.
- AR-52 (AD-17, SD §16): Valkey queue/cache split is a foundation deliverable (Epic 1): two connections/instances with `noeviction` vs LRU+TTL, TLS, per-role ACL users, HMAC-signed job payloads. PgBouncer optional: tenant context is transaction-scoped (`set_config(...,true)`) so transaction-mode pooling is safe; verify with CI test.

**Observability, security, scale, backup**

- AR-53 (AD-24): OTel collector wiring, health endpoints per role, metric catalog `dashflow.<module>.<measure>`, `service_health_samples` sampling job (Health module, interval pending_input), correlation of `X-Request-Id` to trace IDs through jobs, outbox events and Reverb. Scrubbing-processor tests mandatory in CI.
- AR-54 (AD-3, 6, 17, 19, 21, 31): Security test suite in CI: cross-tenant RLS leak tests per tenant table; SSRF/EgressGuard matrix; secret non-disclosure (API, logs, audit, browser payloads); XSS fixtures per Block slot; CSV formula injection; CSRF/session rotation; signed-job tamper test; invitation single-use/cap tests; last-`users.manage`-holder rule; fail-closed missing user context (`access.context_missing`).
- AR-55 (SD §13, §17, NFR-1/2): Load-test harness (skeleton in Epic 1 DoD, full in Epic 11) drives dashboard results fan-in, sync dispatch with hot/cold keys, per-workspace budgets and Reverb fan-out; targets are pending_input (sizing inputs). Early spikes: server preview latency (C6), grid-layout-plus vs gridstack, queue HMAC hook.
- AR-56 (AD-34, SD §18): Backup/restore: PITR configured and a restore drill documented and tested in staging; keyring/KMS backup separate; restore verifies RLS, roles and `current_payload_id` pointers; Valkey loss recovery verified (queues, results rebuilt from PG state and reconciliation sweep). Customer-hosted N-1 -> N upgrade path documented.

**Tunables that must exist as `pending_input` settings in `config/dashflow.php` (SD §22, SPINE)**

- AR-57: Create each as a named env-driven setting flagged `pending_input` with no invented value (proposed default only where stated): `dispatch_tick` (proposed 5 s), `precompute_timeout`, `hot_window`, `cold_purge_after`, `prewarm_lead`, health probe interval, retry base/cap/`max_attempts`, circuit-breaker failure count and cool-down, health thresholds (healthy/degraded/unreachable), manual-refresh window, guards (`max_pages`, `max_bytes`, depth limit, platform timeout ceiling), `connect_timeout`/`total_timeout` defaults, budgets (max hot keys per workspace, max fetch rate per Data Source, max new cold keys per membership per hour), draft-sample TTL, edit-lock TTL, health-sampling interval, uptime window, Overview metric windows, internal decimal scale, Workspace allowed refresh-interval set and Live budget, superseded-payload grace period, audit retention floor and defaults, `require_https` (default off), session idle (Admin vs User) and Remember-me durations, RPO/RTO/uptime targets, Live end-to-end freshness target (NFR-1), NFR-2 latency targets, preview latency target (C6). Also non-tunable pending client inputs: API inventory, sizing, compliance/residency, deployment model.

### UX Design Requirements

**Design tokens**

- UX-DR-1: Implement the brand colour tokens as CSS custom properties: accent #FACC15, accent-strong #EAB308, accent-border #FDE047, accent-soft #FEF9C3, accent-wash #FFFBEB, accent-ink #A16207, accent-ink-strong #854D0E, accent-ink-inverse #FDE047, on-accent #111827, logo-tile #FACC15, logo-mark #111827. AC: every token resolves in the light theme; accent and accent-border are never used as text colour on any surface (lint/test); accent-ink is 4.92:1 on white, 4.58:1 on accent-soft, 4.63:1 on canvas, 4.75:1 on accent-wash; accent-ink-inverse is used only on dark surfaces (toast actions, sign-in tagline). (D, Colors; frontmatter colors)
- UX-DR-2: Implement the fixed system tokens: surface-canvas #F7F8FA, surface-card #FFFFFF, surface-sunken #F9FAFB, surface-muted #F3F4F6, border-default #E5E7EB, border-strong #D1D5DB, border-control #80868F, text-primary #111827, text-secondary #374151, text-muted #6B7280, text-subtle #9CA3AF, text-inverse #F9FAFB, surface-inverse #111827, scrim #111827, shadow-color #111827, focus-ring #2563EB, on-status #FFFFFF. AC: Workspace brand override cannot change any of these; text-subtle is never used for placeholders or informative text (e.g. chart axis labels); border-strong is never the only boundary of an input/checkbox/radio; every form-control boundary uses border-control (3.67:1 on white, 3.45 canvas, 3.51 sunken, 3.33 surface-muted). (D, Colors; Do's and Don'ts)
- UX-DR-3: Implement status tokens: success #16A34A (icons/progress/step fill, never small text), success-text #15803D, success-soft #DCFCE7, success-wash #F0FDF4, success-border #BBF7D0, error #DC2626, error-text #B91C1C, error-soft #FEF2F2, error-border #FCA5A5, warning #C2410C, warning-bar #F97316 (non-text only), warning-soft #FFEDD5, warning-border #FED7AA, info #1D4ED8, info-strong #1E40AF, info-soft #DBEAFE. AC: small text uses success-text not success; error words on error-soft use error-text (5.91:1) not error (4.41:1); captions inside error-soft rows use text-secondary (9.42:1) not text-muted; no text-muted on accent-soft; every status colour is paired with an icon, arrow or word. (D, Colors; Text on tinted fills)
- UX-DR-4: Implement sign-in hero tokens (hero-surface #0A0E11, hero-surface-raised #11161A, hero-card #181920, hero-border #1D2229, hero-text #FFFFFF, hero-text-muted #9099A6, hero-chip #1C1C13). AC: these are the only dark surfaces in the MVP; no partial dark panels elsewhere; hero-text 19.38:1 and hero-text-muted 6.73:1 on hero-surface. (D, Colors; Do's and Don'ts)
- UX-DR-5: Implement data-mapping role tokens for the six roles as text/fill/border triples: Label (#374151/#F3F4F6/#E5E7EB), Value (#854D0E/#FEF08A/#FDE047), Dimension (#0F766E/#CCFBF1/#99F6E4), Measure (#1D4ED8/#DBEAFE/#BFDBFE), Time (#6D28D9/#EDE9FE/#DDD6FE), Filter/Category (#C2410C/#FFEDD5/#FED7AA). AC: each role text is >= 4.5:1 on its fill; type chips reuse families (number = Measure blue, text = Label grey, date = Time violet, array = Filter orange). (D, Colors; frontmatter colors)
- UX-DR-6: Implement the chart palette tokens (max 6 series, fixed order): chart-1 #FACC15 (series-1 FILLS only), chart-1-stroke #A16207 (series-1 lines, points, fill edges), chart-1-fill #FEF9C3, chart-2 #4C6EB1, chart-3 #0F766E, chart-4 #DB2777, chart-5 #EA580C, chart-6 #6B7280, chart-grid #F3F4F6. AC: series strokes meet 4.92/5.03/5.47/4.60/3.56/4.83:1 on white (all >= 3:1); a yellow line on white is never drawn; a 7th series is impossible (Admin must group or top-N); first four series pass protan/deutan simulation (dE >= 12.6). (D, Colors Chart palette; Do's and Don'ts)
- UX-DR-7: Implement the typography scale in Inter with fallback stack (Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif): display-hero 56/500/1.08/-0.02em; headline-lg 28/500/1.25/-0.01em; headline-md 20/700/1.3; title-md 15/600/1.35; title-sm 13/600/1.35; body-md 14/400/1.5; body-sm 13/400/1.45; caption 12/400/1.4; label-caps 11/600/1.4/0.08em; kpi-headline 26/700/1.15/-0.01em; kpi-card 20/700/1.2/-0.01em; mono (ui-monospace, SFMono-Regular, Menlo, Consolas, monospace) 12/400/1.45. AC: every figure (KPI values, table numbers, deltas, axis ticks, times, counts) uses font-variant-numeric: tabular-nums; no all-caps except label-caps; no body copy below 12px; one headline (kpi-headline) per Block, other emphasis steps down (primary = title-sm, secondary = caption text-muted, badge = badge). (D, Typography)
- UX-DR-8: Apply the typography role mapping: display-hero only for the sign-in hero headline; headline-lg for the sign-in form title; headline-md for page titles; caption text-muted for page subtitles; title-md for wizard section-card titles, "Live preview", Admin panel titles, drawer title, dialog titles; title-sm for Block Chrome titles, button labels, slot names; label-caps for sidebar section labels, slot group headers (HEADER/KPI/CHART/META) and the "WELCOME BACK" eyebrow; kpi-card for standalone KPI cards and Admin metric cards; mono for JSON paths, endpoints, sample values. AC: visual review/snapshot confirms each. (D, Typography table)
- UX-DR-9: Implement radius tokens xs 4, sm 6, md 8, lg 10, xl 12, sheet 16 (leading corners), full 9999 and their usage: xs checkbox and top corners of chart bars; sm tags/small buttons/role chips/tooltips/kbd; md inputs/buttons/nav items/icon tiles/thumbnails; lg cards/Blocks/drawer rows/toasts/workspace switcher; xl dialogs and floating desktop drawer; full chips/pills/badges/stepper numerals/avatars. AC: user avatars are circles and Workspace avatars are rounded squares (never confused). (D, Shapes; frontmatter rounded)
- UX-DR-10: Implement the spacing and sizing tokens: scale 1=4,2=8,3=12,4=16,5=20,6=24,7=28,8=32,10=40; page-gutter 28px, page-gutter-mobile 16px, grid-gap 14px, grid-columns 12, grid-row-unit 60px, block-height-small 120, medium 360, large 540, sidebar-width 232, icon-rail-width 64, topbar-height 64, drawer-width 392, drawer-dim-strip-mobile 28, preview-pane-width 384, popover widths palette 560 / notifications 360 / picker 320, stepper-numeral 22, stepper-connector 56, target-min 24, target-chrome 28, target-touch 44, reflow-stack-below 640, slot-row-wrap-below 760. AC: components reference tokens, not literals; height presets are multiples of grid-row-unit. (D, frontmatter spacing; Layout)
- UX-DR-11: Implement elevation rules: cards and Blocks at rest have border-default 1px and NO shadow; floating items use shadow-color at set opacity: toast 0 10px 30px @25%, dialog/overlay-sheet 0 12px 40px @12%, drawer-row open 0 4px 16px @6%; a Block lifted during drag uses the float elevation; the desktop pushed drawer has a left border and no shadow; mobile sheet scrim is scrim @45%. AC: no raw rgba() or hex in components (lint); only semantic tokens referenced. (D, Elevation & Depth; Do's and Don'ts)
- UX-DR-12: Implement the focus-ring token: 3px solid focus-ring (#2563EB), 2px offset, on every interactive element, deliberately blue not yellow; contrast 3.38:1 next to accent and 3.43:1 on surface-inverse. AC: keyboard focus is visible on all controls; yellow is never used for focus; sticky layers set scroll-padding so a focused element is never hidden. (D, Colors Focus; components.focus-ring; E, Accessibility Floor)
- UX-DR-13: Satisfy the non-text contrast table (1.4.11, >= 3:1): checked checkbox edge accent-ink-strong 6.85/4.47, checkmark 11.58, radio dot / switch-on track / selected role-card border / sidebar active bar / selected-chip border 6.85 on white and 6.38 on accent-soft, active-option inset ring 4.81 on accent-soft and 5.17 on white, resize-handle glyph 6.85, required-missing left rule 4.41, success dot 3.30. AC: automated contrast check on these pairs passes; supplementary-only marks (area fill 1.07, progress fill 1.39, gridlines 1.10, "+ Add" border 1.32, just-added outline 1.47, hotspot idle dash 1.24, accent-soft selected fill 1.07) each have a text, shape or >= 3:1 companion. (D, Colors Non-text contrast)
- UX-DR-14: Selected states never rely on fill alone: sidebar/icon-rail active item and selected table row get a 3px accent-ink-strong leading bar; sign-in role card gets 2px accent-ink-strong border plus radio; segmented control gets a 1px border-control edge and weight 600; category chip gets leading check and accent-ink-strong border; listbox selected gets a leading check (trees: 3px left bar); keyboard-active option gets 2px inset focus-ring outline. AC: each selected state has its >= 3:1 cue. (D, Colors Selected states)
- UX-DR-15: Replace mockup colours with spec values: delta green #00D987 -> success-text, delta red #FF004A -> error, mockup yellow #FFDD1D -> accent #FACC15; do not build from sketch CSS (#CA8A04 active step, .up #16A34A, #9CA3AF subtle labels, pre-R2 chart palette). AC: no occurrence of the replaced values in the codebase. (D, Brand & Style; Colors Replaced mockup colours)

**Components**


*Buttons and form controls*

- UX-DR-16: `button-primary`: bg accent, fg on-accent, hover accent-strong, radius md, minHeight 34px, paddingX 14px, title-sm. AC: exactly one primary per view ("Sign in as User →", "+ Add block", "Create block", "Publish block", "Continue to Preview →"); two yellow buttons never side by side. (D, frontmatter; Components Buttons)
- UX-DR-17: `button-secondary`: bg surface-card, fg text-primary, 1px border-strong, radius md, minHeight 34px; used for "Save draft", "Preview", "Done" (Done in edit-layout-toolbar is primary per that component). AC: label identifies the control; border is decorative-level contrast acceptable. (D, frontmatter; Components)
- UX-DR-18: `button-ghost`: transparent, fg text-secondary, hover surface-sunken, radius md; no border; used for icon buttons. AC: visible focus ring; hover fill. (D, frontmatter; Components)
- UX-DR-19: `button-link`: fg accent-ink, caption, weight 600; used e.g. "View all →", "Edit roles", "Go to line n", "Use category icon". AC: 4.5:1 on its surface. (D, frontmatter; Components)
- UX-DR-20: `button-destructive-soft`: bg error-soft, fg error-text (5.91:1), 1px error-border, radius sm, minHeight target-chrome (28px); used for "× Remove" in drawer rows. AC: 28px min hit area (44px on touch). (D, frontmatter; Components)
- UX-DR-21: `button-destructive`: bg error, fg on-status (4.83:1), radius md, minHeight 34px; used in destructive confirm dialogs and repeats the object name (e.g. "Reset Finance weekly – Jamie"); Cancel sits to its left and receives initial focus. AC: unpublish, archive, delete dashboard, reset to template, replace draft, access change dialogs all use it. (D, Dialog; E, Interaction Primitives)
- UX-DR-22: `button-disabled`: opacity 45% on the control only; a caption text-secondary reason at full opacity adjacent; implemented as aria-disabled="true" (not disabled), focusable, aria-describedby on the reason. AC: a disabled primary always shows an inline reason (e.g. msg:required-slot-missing); reason text never dimmed; activating does nothing except focus first blocker and announce. (D, Components; E, Interaction Primitives Disabled controls)
- UX-DR-23: `input`: bg surface-card, 1px border-control, focus border accent plus 3px accent-soft halo plus the keyboard focus-ring, radius md, minHeight 36px, placeholder text-muted, error border error and message caption error-text with leading error icon. AC: label above in title-sm 600; helper below in caption text-muted; placeholders never carry instructions; required marker red `*` (aria-hidden) with `required`/aria-required and "* Required" once per form in caption text-secondary; validate on blur and submit not per keystroke; aria-invalid, aria-describedby to message starting with error icon and hidden "Error:". (D, frontmatter/Components; E, Small components)
- UX-DR-24: `textarea`: extends input, minHeight 96px, vertical resize only; grows to viewport and never clips. AC: horizontal resize disabled. (D, Components; E, Small components)
- UX-DR-25: `json-textarea`: extends textarea, mono, minHeight 200px; gutter with line numbers in mono text-muted on surface-sunken with 1px border-default right edge; error line = error-soft band with 3px error left rule in gutter; result valid = caption success-text with leading check; invalid = caption error-text, error icon, "Go to line n" button-link. AC: validates on paste and blur; invalid result states line and column; link moves caret to that line and column. (D, frontmatter; E, Step 2)
- UX-DR-26: `select`: extends input with chevron in text-muted. AC: disabled options carry the reason as description (e.g. Live). (D, frontmatter; E, Disabled controls)
- UX-DR-27: `method-prefix`: "GET"/method segment, bg surface-sunken, fg success-text, mono, joins the path input with a border-control edge. AC: joined edge visible at >= 3:1. (D, frontmatter; Components Endpoint field)
- UX-DR-28: Endpoint field (prose component): method prefix + path input in step 2; POST reveals the read-only checkbox. AC: see Wizard section. (D, Components Buttons and form controls)
- UX-DR-29: Secret field (prose component): masked "••••••••"; once saved shows "•••••••• · set 2 Oct 2026 · Replace", read as "Secret set on 2 Oct 2026"; no reveal; Replace ("Replace token") clears and requires a new value. AC: screen-reader text never includes resolved user-context values; unsaved secrets never autosaved. (D, Components; E, Small components, Session timeout)
- UX-DR-30: `segmented-control`: bg surface-sunken, 1px border-control, active bg surface-card with 1px border-control and weight 600, radius md; role radiogroup with arrow keys or buttons with aria-pressed. AC: used for Desktop/Tablet/Mobile, Fetch/Paste, comparison Source. (D, frontmatter; E, Small components)
- UX-DR-31: `chip-category` and `chip-category-active`: inactive = white, text-secondary, 1px border-default, full radius, minHeight 28px; active = accent-soft, accent-ink-strong text, 1px accent-ink-strong border, weight 700, leading check. AC: single-select, one tab stop, arrows move between chips; on narrow widths chips scroll horizontally with an edge fade that never covers the focused chip; scrolling brings focused chip into view. (D, frontmatter/Components; E, Small components, Responsive)
- UX-DR-32: `radio`: 16px, 1px border-control, white; checked = 8px accent-ink-strong dot with 1px accent-ink-strong border; label body-md text-primary. AC: used for Access (All users / Selected groups), record-path list, sign-in role cards; radiogroup arrow-key semantics. (D, frontmatter/Components)
- UX-DR-33: `checkbox`: 16px, radius xs, 1px border-control, checked bg accent + 1px accent-ink-strong border + 2px on-accent check, hit area 24px including label. AC: same treatment everywhere including sign-in "Remember me" and wizard options; never the browser default. (D, frontmatter/Components)
- UX-DR-34: `switch`: track 32x18 full radius; off = surface-card + 1px border-control; on = accent-ink-strong; thumb 14px circle (off: 1px border-control); visible "On"/"Off" word in caption text-secondary beside track. AC: role="switch" with aria-checked; Space toggles; used for Template Mandatory, Keyboard shortcuts, other on/off settings. (D, frontmatter/Components; E, Small components)
- UX-DR-35: `tooltip`: bg surface-inverse, fg text-inverse, caption, radius sm, paddingX 8px, maxWidth 280px. AC (1.4.13): shows on hover AND keyboard focus; Esc dismisses without moving focus; stays while pointer over it; never holds the only copy of information (hotspot, icon rail, disabled reason, chart point, role/transform descriptions). (D, Components; E, Small components)
- UX-DR-36: `skeleton`: fill surface-muted, radius sm, shimmer = surface-sunken sweep; bars shaped like the content. AC: shimmer static under prefers-reduced-motion; shimmer stops after 5 s in every mode and "Still loading…" caption (text-muted) appears. (D, frontmatter/Components)

*Chips and badges*

- UX-DR-37: `tag`: bg surface-muted, fg text-secondary, radius sm; used for category on drawer rows, "looks like pagination, not data", version "v1.1" neutral tag, "user context" tag (info-soft/info-strong). AC: never interactive. (D, Components; E, Small components)
- UX-DR-38: Type chip (prose component): number/text/date/array/object chips, small, radius sm, role-family colours. AC: number = Measure blue, text = Label grey, date = Time violet, array = Filter orange. (D, Components Chips and badges; Colors)
- UX-DR-39: `badge` base: full radius, caption, weight 600, paddingX 8px. AC: badges are never interactive and always include text. (D, frontmatter; E, Small components)
- UX-DR-40: `badge-draft`: accent-soft bg, accent-ink-strong fg, text "• Draft". (D, Components)
- UX-DR-41: `badge-sample-data`: accent-soft bg, accent-ink-strong fg, 1px accent border, "S on accent circle" icon (aria-hidden), bold text "Sample data · used for mapping and preview only" (msg:sample-wizard). (D, frontmatter/Components)
- UX-DR-42: `badge-new`: accent bg, on-accent fg ("New"). (D, Components)
- UX-DR-43: `badge-operational`: success-soft bg, success-text fg, with a dot and the word. (D, Components)
- UX-DR-44: `badge-partial-data`: warning-soft bg, warning fg; shown in Block header when Partial data. (D, Components; E, State Patterns)
- UX-DR-45: Version tag (prose component): "v1.1" in a neutral `tag`. (D, Components Chips and badges)
- UX-DR-46: Lock badge (prose component): lock glyph plus "Locked" or "Required by your admin" (msg:locked) in text-secondary on surface-muted. AC: text always present; announced "required by your admin and cannot be removed". (D, Components; E, Add-blocks Panel)

*Stepper*

- UX-DR-47: `stepper-step`: numeral 22px full radius on surface-muted, numeral text-secondary (9.37:1), connector 56px x 1px border-default, label text-muted. AC: upcoming step plain text "(not started)". (D, frontmatter; E, Wizard shell)
- UX-DR-48: `stepper-step-active`: accent-soft pill, numeral accent bg + on-accent, label accent-ink, `aria-current="step"`. AC: not the sketch #CA8A04. (D, frontmatter/Components Stepper)
- UX-DR-49: `stepper-step-done`: numeral success bg with on-status white check, label text-secondary, connector success. AC: done steps 1 and 2 collapse into one-line summary cards with green check and "Edit"; done steps and first incomplete step are buttons "{n}. {label}, completed" / ", not completed"; five steps Configure Block, Data Source, Map Data, Preview, Save/Publish in a `nav` "Create block progress" with ordered list. (D, Components Stepper; E, Wizard shell)

*Blocks*

- UX-DR-50: `block-card`: surface-card, 1px border-default, radius lg, no shadow, header padding 12px 16px. AC: Block is a `section` labelled by its heading title (h2 under page h1). (D, frontmatter; E, Block Chrome controls)
- UX-DR-51: `block-chrome-header`: 28px icon tile (accent-soft or category soft colour, radius md), title title-sm, subtitle caption text-muted, controls refresh, minimize, more (⋯) in text-muted with 28px hit area, 1px border-default divider. AC: controls named "Refresh Revenue overview", "Minimize Revenue overview", "More actions for Revenue overview"; optional period selector (compact select, top right of body) and block-footer-link; minimized shows header row only. (D, Components Block card; E, Block Chrome)
- UX-DR-52: `resize-handle`: hit area 24x24, 10px L-shape glyph 2px accent-ink-strong (6.85:1) at bottom-right in Edit Layout Mode only. AC: present only when Allow resize is set. (D, frontmatter/Components; E, Edit Layout Mode)
- UX-DR-53: `kpi-card`: 3-column Small Block; label body-sm text-muted, value kpi-card, delta line with arrow, icon tile 28px at top right with soft fill of KPI colour; deltaPositive success-text, deltaNegative error. AC: delta always has ↗/↘; when Direction = Lower is better the visible delta adds `deltaWord` ("· better"/"· worse", caption 600 in delta colour); hidden text states direction and judgement ("Up 14.2% from last year, favourable"), arrow glyph aria-hidden, flat reads "No change"; comparison label in text-muted ("vs last month"). (D, frontmatter/Components KPI card; E, Map Data Direction)
- UX-DR-54: `kpi-headline-in-block`: Headline value slot inside a larger Block uses kpi-headline (26px/700). AC: one headline per Block. (D, frontmatter/Typography)

*Slot rows (Map Data checklist)*

- UX-DR-55: `slot-row`: bg card, divider 1px surface-muted, columns 18px status · 140px slot name · 168px field · minmax(0,1fr) presentation · 126px confidence/status · 96px action, paddingY 8px. AC: grouped under label-caps headers HEADER/KPI/CHART/META; required slots show red `*`. (D, frontmatter; Components Slot row)
- UX-DR-56: `slot-row-wrapped`: when left column < 760px [R7]: line1 18px status · slot name · 126px confidence/status · 96px action; line2 indented: field · presentation summary wrapping. AC: at 1024px (left column ~496px) the wrapped row fits. (D, frontmatter/Layout)
- UX-DR-57: `slot-row-stacked`: viewport < 640px: status+slot name / field / presentation summary / pill+actions, each full width. (D, frontmatter/Layout)
- UX-DR-58: `slot-pill-optional`: surface-muted bg, text-secondary fg (9.37:1), text "Optional"; field column reads "—". (D, frontmatter/Components)
- UX-DR-59: `slot-pill-error`: error-soft bg, error-text fg, 1px error-border; texts "Required missing" and "Type mismatch". (D, frontmatter/Components)
- UX-DR-60: `slot-row-required-missing`: error-soft bg, 3px error left rule, name in error-text, caption text-secondary (never text-muted), status dot "!" on error. AC: shows suggestion when one exists ("Use revenue as series"). (D, frontmatter/Components; E, Slot checklist)
- UX-DR-61: `slot-row-type-mismatch`: error-soft bg, 3px error left rule, caption text-secondary, one-line reason ("Text field can't be a numeric headline"). (D, frontmatter/Components)
- UX-DR-62: `slot-row-expanded`: accent-wash bg, 3px accent left rule, three-column editor (Format · Emphasis · Direction), one column below 640px; one expanded at a time. (D, frontmatter/Components)
- UX-DR-63: Slot row state matrix (all nine states): Auto-mapped High (check on success-soft, `confidence-high` "High", full meter, counts as Confirmed); Auto-mapped Medium (check on success-soft, "Medium", 2/3 meter); Auto-mapped Low (? on warning-soft, "Low confidence — please review", pinned top, caption reason in warning); Confirmed (check on success, "Confirmed" success-soft pill, caption "Confirmed by you" visible, not hover-only); Overridden (pencil on info-soft, "Overridden" info-strong on info-soft, inline note when role changed); Optional-empty (dashed empty circle, `slot-pill-optional`); Required-missing (! on error, `slot-pill-error`); Type-mismatch (! on error, `slot-pill-error` plus reason); Expanded. AC: each renders per table. (D, Components Slot row table)
- UX-DR-64: `confidence-high` (success-soft bg, success-text fg, meter success), `confidence-medium` (accent-soft bg, accent-ink fg, meter accent), `confidence-low` (warning-soft bg, warning fg, meter warning-bar). AC: confidence always a word and a meter; badge reads "Confidence: Medium" to AT; meter and status dot aria-hidden. (D, frontmatter; E, Slot checklist)

*Role chips*

- UX-DR-65: `role-chip` base: radius sm, caption, weight 700, paddingX 8px, minHeight 24px; as a picker shows a ⌄ caret. (D, frontmatter/Components Role chips)
- UX-DR-66: `role-chip-label`, `role-chip-value`, `role-chip-dimension`, `role-chip-measure`, `role-chip-time`, `role-chip-filter` each bind its role triple (see tokens); `role-chip-none` = text-muted with 1px dashed border-control meaning no role. AC: chips always show names; never a bare colour swatch (not in status meter, not in a legend) because Measure and Time are near-identical under deuteranopia; a flagged chip (suspected wrong role) adds a leading "!" in error (aria-hidden, AT adds ", possible wrong role"). (D, Components Role chips)

*Add-blocks drawer*

- UX-DR-67: `drawer`: surface-card, width 392px, 1px border-default left border, no shadow when pushed. (D, frontmatter/Elevation)
- UX-DR-68: `drawer-row`: columns 64px thumbnail · 1fr text · auto action, radius lg, hover surface-sunken, open = 1px accent border + 0 4px 16px shadow @6%, just-added bg success-wash, locked bg surface-sunken, action minHeight 28px (44px touch). Layout: name title-sm, description (2 lines desktop, 1 mobile), category tag, optional "New" badge. Actions: "+ Add" (accent-ink text, white fill, accent-border border), "✓ Added" (caption 600 success-text on success-wash, 1px success-border, status text NOT a control, persistent), "× Remove" (`button-destructive-soft` beside Added; shown on hover, focus-within and touch; always in AX tree), "Locked" badge. AC: no drag grips; focus ring on focused button. (D, frontmatter/Components Add-blocks drawer row)
- UX-DR-69: `drawer-thumbnail` 64x46 and `drawer-thumbnail-mobile` 52x38: 1px border-default, radius md, decorative schematic (KPI/chart/list/progress) in chart palette on white, aria-hidden. (D, frontmatter/Components)

*Feedback and overlays*

- UX-DR-70: `toast`: surface-inverse bg, text-inverse fg, actions accent-ink-inverse (13.46:1) bold underlined with min height 28px, close "×" named "Dismiss notification" 28px square, radius lg, shadow 0 10px 30px @25%, 18px success check circle left, body-sm message. AC: error toasts use error icon and never auto-dismiss; position top right of dashboard area never over the drawer; on mobile inside the sheet above its footer, never covering focus. (D, frontmatter/Components Toast)
- UX-DR-71: `just-added-outline`: 3px solid accent, offset 2px, halo 0 0 0 8px accent @18%, plus "Just added" pill (accent fill, on-accent, 11px bold) at top-left edge; follows Workspace accent override. AC: ~3 s; under reduced motion halo does not fade, static outline 3 s then removed. (D, frontmatter/Components Feedback marks)
- UX-DR-72: `free-space-hint`: accent-wash bg, 2px dashed accent-border, accent-ink fg, radius lg, copy msg:free-space-hint. AC: shown only on hover/focus of "+ Add"/"+ Add to dashboard"; never on touch. (D, frontmatter; E, Add-blocks Panel)
- UX-DR-73: Edge pill (prose component): accent pill "↓ 1 new block below" (msg:edge-pill) at edge of dashboard viewport when new Block out of view. (D, Components Feedback marks; E, Add-blocks Panel)
- UX-DR-74: `preview-hotspot`: idle 1px dashed border-default; hover 1.5px solid accent on accent-wash (pointer only); focus = standard focus-ring. AC: never the 1.48:1 accent outline as the focus indicator. (D, frontmatter; E, Preview hotspots)
- UX-DR-75: `listbox-option-active`: bg accent-soft with 2px inset focus-ring outline (4.81:1); `listbox-option-selected`: leading check in accent-ink-strong, trees: 3px accent-ink-strong left bar. AC: used in pickers, menus, trees, palette, switchers, popover lists; active and selected are distinct. (D, frontmatter/Components Popovers)
- UX-DR-76: `dialog`: surface-card, radius xl, shadow 0 12px 40px @12%; title title-md, actions right-aligned primary last. AC: used for publish impact, session timeout warning, unsaved changes, destructive confirmations; publish-impact diff shows before -> after rows (before text-muted strikethrough, after text-primary; impact numbers bold tabular). (D, frontmatter/Components Dialog)
- UX-DR-77: `overlay-sheet`: surface-card, radius 16 on leading corners, shadow 0 12px 40px @12%, scrim at 45%, close button 44px square visible in header. (D, frontmatter/Components)
- UX-DR-78: `focus-ring` component (3px solid focus-ring, 2px offset) applied to every interactive element. (D, frontmatter)

*Navigation and shell components*

- UX-DR-79: `sidebar`: surface-card, width 232px, 1px border-default right, section labels label-caps text-muted ("WORKSPACE", "ADMINISTRATION"); logo row 64px tall with 28px logo tile + "Dashflow" title-md bold; nav items 16px icon title-sm text-secondary; footer: Help & support, Sign out, Appearance (hidden in MVP), user row (avatar circle, name, role, ⋯). (D, frontmatter/Components Sidebar)
- UX-DR-80: `sidebar-nav-item-active`: accent-soft bg, text-primary, weight 600, 3px accent-ink-strong leading bar full radius, radius md. (D, frontmatter)
- UX-DR-81: `icon-rail`: 64px wide, surface-card; same items as 20px icons with tooltips; active keeps accent-soft tile and leading bar; workspace switcher becomes avatar tile only. (D, frontmatter/Components)
- UX-DR-82: `workspace-switcher`: surface-sunken, 1px border-default, radius lg, avatar 30px accent tile radius md with initials ("GI"), name title-sm, label caption, › chevron. (D, frontmatter/Components)
- UX-DR-83: `icon-button-topbar`: extends button-ghost, 36px, 18px icon text-muted; settings gear between notifications and primary action with tooltip "Settings". (D, frontmatter/Components Top bar)
- UX-DR-84: `header-picker-button`: extends button-secondary, leading 16px icon text-muted, label title-sm text-primary, trailing › chevron; dashboard switcher ("Grid dashboard", grid glyph) and Date Range ("May 1 – May 31", calendar glyph, tabular); open popovers on dialog surface radius lg. (D, frontmatter/Components)
- UX-DR-85: `back-button`: extends button-secondary, 32px, ← 16px text-secondary, left of breadcrumb and title. (D, frontmatter/Components)
- UX-DR-86: `page-header`: title headline-md text-primary, subtitle caption text-muted; right side: Dashboard = dashboard switcher + Date Range + "✎ Edit layout" (secondary); Admin overview = "+ Create block" (primary). (D, frontmatter/Layout)
- UX-DR-87: `form-section-card`: card, 1px border-default, radius lg, padding 16, icon tile 28px accent-soft with accent-ink icon, title title-md, subtitle caption text-muted, divider 1px border-default; content: step 1 "Basic information", "Display & behavior"; step 2 "Data configuration". (D, frontmatter/Components Wizard page parts)
- UX-DR-88: `preview-pane`: card, 1px border-default, radius lg, title "Live preview" title-md, subtitle "Changes appear here automatically.", Desktop/Tablet/Mobile segmented-control right, canvas surface-sunken with 1px border-default dots every 12px, size label pill (caption text-muted, 1px border-default, full radius) centred above Block e.g. "8 columns × Medium (360px)". (D, frontmatter/Components)
- UX-DR-89: `callout-version-safe`: accent-wash, 1px accent-border, radius lg, shield icon 16px accent-ink, title "Version-safe publishing" title-sm accent-ink-strong, body caption text-secondary (msg:version-safe). AC: under preview canvas in steps 1-3, repeated in step 5 summary; version number is the one the publish will create. (D, frontmatter/Components)
- UX-DR-90: `signin-hero`: hero-surface bg, display-hero headline hero-text, body-md hero-text-muted, tagline accent-ink-inverse on hero-chip full radius ("One workspace. Every insight." with sparkle). Full content spec: logo and "Dashboard Management System" top right, abstract dashboard illustration (hero-card panels with accent bars), two trust lines "Enterprise-grade security" and "Live data updates", faint concentric circles in hero-surface-raised, footer "© 2026 Dashflow. All rights reserved." caption hero-text-muted. About 55% width split. (D, frontmatter/Components Sign-in hero)
- UX-DR-91: `signin-role-card` (card, 1px border-default, radius lg, radio top right) and `signin-role-card-selected` (accent-soft, 2px accent-ink-strong border, checked radio). Two cards: "User · Personal workspace" / "Admin · System management". AC: radiogroup with visible radios; choice sets button label "Sign in as User / Admin". (D, frontmatter; E, Small components)

*Data display components*

- UX-DR-92: `avatar-initials`: full radius, 32px list / 28px feed, caption 700, five tints (accent-soft/accent-ink-strong, dimension, measure, time, surface-muted/text-secondary) chosen deterministically from the name. AC: same person same tint everywhere. (D, frontmatter/Block Type faces)
- UX-DR-93: `list-row`: divider 1px surface-muted, paddingY 12, title title-sm, subtitle caption text-muted, rightValue title-sm tabular, rightMeta caption text-muted; avatar or mapped icon. (D, frontmatter/Block Type faces List)
- UX-DR-94: `progress-row`: title title-sm, caption text-muted ("12/16 tasks"), percent title-sm tabular right-aligned above 6px surface-muted full-width track, fill chart-1 or colour rule. AC: % text is the value, bar aria-hidden; with threshold bands the band icon/label sits beside the %; AT reads "Website redesign, 12 of 16 tasks, 78%". (D, frontmatter; E, Small components)
- UX-DR-95: `activity-row`: actor body-sm 600 text-primary, action body-sm text-secondary, time caption text-muted ("8 min ago") beneath. (D, frontmatter)
- UX-DR-96: `calendar-week-strip`: dayLetter caption text-muted, date title-sm tabular, today = 28px accent circle with on-accent date + 4px accent dot below. AC: week strip is a list; today has aria-current="date" ("Wednesday 22 May, today"); today shape cue not colour only. (D, frontmatter; E, Small components)
- UX-DR-97: `agenda-row`: time caption text-muted tabular, 3px full-radius rule in category colour from the chart palette, title title-sm, subtitle caption text-muted. AC: AT reads "09:30, Product sync, Meeting room 2A, 45 min". (D, frontmatter; E, Small components)
- UX-DR-98: `block-footer-link`: accent-ink, caption 600, centred ("View all approvals →", "Open projects →", "View activity log →"). (D, frontmatter)
- UX-DR-99: `text-status-face`: text body-md up to 3 lines then ellipsis (full text in About this block); status = pill with band soft fill, band text colour, leading band icon, status word title-sm ("✓ On track", "⚠ At risk"). AC: no match -> neutral text; word and icon always present. (D, frontmatter/Block Type faces)
- UX-DR-100: `table-header` (surface-sunken, caption 600 text-secondary, 1px border-default divider), `table-row` (body-sm text-primary, 1px surface-muted divider, minHeight 40px, hover surface-sunken), `table-cell-numeric` (right-aligned, body-sm tabular), `table-sort-indicator` (sorted ▲/▼ 10px text-secondary with header weight 700; unsorted ↕ 10px text-muted on sortable columns only), `table-pagination` (caption text-muted tabular "1–10 of 48", Previous/Next button-secondary at 28px, page-size select), `table-row-highlight` (band soft fill plus band icon and label in first cell in band text colour). AC: header row stays visible while body scrolls inside Block; narrow table scrolls sideways in a focusable region labelled with Block title with first column pinned; pagination bottom right. (D, frontmatter/Block Type faces Table)
- UX-DR-101: `chart-line`: 2px stroke in series colour (chart-1-stroke series 1; chart-2..6 others), monotone curve, round joins; single-series line has no point markers at rest. (D, frontmatter/Chart marks)
- UX-DR-102: `chart-series-style` (mandatory for >= 2 series): dashes series 1 solid · 2 dash · 3 dot · 4 dash-dot · 5 long-dash · 6 dotted; markers 1 ● · 2 ■ · 3 ▲ · 4 ◆ · 5 ✕ · 6 ○ drawn at every point when points <= 24 else at line end; directLabel caption 600 text-secondary at line end with marker; fillPatterns for bar/pie series 3-6: diagonal, dots, cross-hatch, vertical lines in 1px surface-card over fill. AC: series never identified by colour alone. (D, frontmatter; Colors Chart palette)
- UX-DR-103: `chart-area`: vertical gradient chart-1-fill at the line to transparent at baseline; only series 1 filled when several series; fill supplementary. (D, frontmatter/Chart marks)
- UX-DR-104: `chart-gridline`: 1px chart-grid, horizontal only; no vertical gridlines, no chart border, no tick marks. (D, frontmatter/Chart marks)
- UX-DR-105: `chart-axis-label`: caption text-muted; Y ticks compact ("$40k"), tabular. (D, frontmatter/Chart marks)
- UX-DR-106: `chart-point-highlight`: 10px, surface-card fill, 2px series stroke; used for the highlighted-point Slot and hover; hover also draws 1px border-control vertical guide and chart-tooltip. (D, frontmatter/Chart marks)
- UX-DR-107: `chart-point-focus`: chart-point-highlight plus 3px focus-ring ring at 2px offset and 1px border-control vertical guide, with same tooltip as hover. (D, frontmatter/Chart marks)
- UX-DR-108: `chart-tooltip`: extends tooltip, content series marker · series name · value (tabular) · X value. AC: tooltip never hides the focused point. (D, frontmatter; E, Chart Block)
- UX-DR-109: `chart-legend`: items = series marker and dash sample in series stroke colour then name caption text-secondary, gap 16px, under or beside the plot; bars and slices show swatch with fill pattern. AC: legend is a list naming marker and dash ("Revenue, solid line, circle markers"), not toggles in MVP. (D, frontmatter; E, Chart Block)
- UX-DR-110: `chart-bar`: series fill with series 1 = chart-1 + 1px chart-1-stroke edge; radius xs on top corners only; groupGap 30% of category band; barGap 2px; stack divider 1px surface-card, stack order bottom-up in palette order; baseline 1px border-control at zero; valueLabel caption text-secondary tabular above bar or inside segment end. AC: vertical by default, grouped and stacked; stacked bars show total above the stack in caption text-secondary tabular. (D, frontmatter/Block Type faces)
- UX-DR-111: `chart-pie`: slices in palette order largest first clockwise from 12 o'clock; series 1 chart-1 + 1px chart-1-stroke edge; 2px surface-card slice divider; donut thickness 28% of radius; centre value kpi-headline tabular, centre label caption text-muted; slice labels caption text-secondary name and % outside ring with 1px border-control leader. AC: at most 6 slices (top 5 + "Other"); when labels collide, legend lists name, % and value; patterns from series 3 on. (D, frontmatter/Block Type faces)

*Admin and generic parts*

- UX-DR-112: `admin-metric-card`: extends kpi-card; icon tile 28px accent-soft with accent-ink icon top left; four per row; delta always starts with ↗/↘ and a sign ("↗ +4 this month"), success-text good, error bad, text-muted flat (deltaNeutral), with same hidden judgement text and deltaWord rule; a share that is not a change ("92% active this week") is plain text-muted with no arrow. (D, frontmatter/Admin overview)
- UX-DR-113: `activity-admin-row`: 28px category-soft icon tile, title title-sm, meta caption ("Published by Olivia Martin"/"Draft by Maya Patel"), time caption tabular ("2 hours ago", "Yesterday", "May 18, 2026"), divider 1px surface-muted. (D, frontmatter/Admin overview)
- UX-DR-114: `health-row`: 8px dot success/warning/error, name body-md, statusWord caption text-muted, uptime title-sm tabular, divider; status words Operational/Degraded/Outage (services), Healthy/Degraded/Unreachable plus "Last success 10:42" (data sources). AC: dot never without its word; AT "Dashboard API, Operational, 99.99% uptime"; overall badge uses `badge-operational` or warning-soft/error-soft with word Degraded/Outage. (D, frontmatter/Admin overview; E, Small components)
- UX-DR-115: `data-table`: toolbar (search input with leading icon and visible label or aria-label, filter selects, count caption text-muted, primary action right); container card 1px border-default radius lg; header/row per table-header/table-row; rowSelected accent-soft + 3px accent-ink-strong bar; rowMenu "⋯" ghost 28px named "More actions for {row name}"; statusCell badge or dot plus word never dot alone; emptyRow one full-width row body-sm text-muted with primary action; loadingRows 5 skeleton rows; pagination. AC: used for every Admin list (Block management, Draft blocks, Published blocks, Data sources, Audit log, User configuration, Version history, Block categories, Dashboard templates) and My dashboards; real `table`; sortable headers are buttons with aria-sort; results count announced politely debounced. (D, frontmatter/Generic admin; E, Small components)
- UX-DR-116: `date-range-calendar`: presets vertical list left (listbox-option-active when active), two months side by side (one below 640px), weekday letters caption text-muted, day 28px square body-sm tabular full radius, today 1px border-control ring, range ends accent fill + on-accent date, between accent-soft band with text-primary, focus day focus-ring, footer chosen range caption text-secondary then Cancel and Apply (button-primary). AC: Apply disabled with reason until both dates set. (D, frontmatter; E, Dashboard switcher and Date Range)
- UX-DR-117: `icon-picker`: 320px popover, search input top, grid 6 columns of 28px+8px tiles with 20px icon text-secondary, tileActive = listbox-option-active, tileSelected = accent-soft fill + 1px accent-ink-strong border + check badge, footer "Use category icon" button-link. AC: one tab stop; arrows move, Enter picks, Esc closes to "Change"; tiles named e.g. "Bar chart icon"; generic icon set only (charts, people, calendar, documents, status), never domain-specific. (D, frontmatter; E, Step 1)
- UX-DR-118: `expanded-view`: full-height panel over dashboard using dialog surface, 90% viewport up to 1200px wide, full screen below 768px; header = Block icon tile, title headline-md, Data-as-of Time caption text-muted, Close; body = Block at full size then rows in data-table with table-pagination. AC: also serves "View as table" with only the table; focus to panel title; Esc/Close returns to ⋯. (D, frontmatter/Generic; E, Chart Block)
- UX-DR-119: `edit-layout-toolbar`: Undo and Redo button-secondary icon buttons with text labels, then "Done" button-primary; hint caption text-secondary "Drag to move · corner to resize · M to move with the keyboard". (D, frontmatter; E, Edit Layout Mode)
- UX-DR-120: `live-updates-toggle`: button-secondary with pause/play glyph "Pause live updates" / "Resume live updates" (aria-pressed); pausedLabel caption text-secondary "Paused · data as of 10:42" (msg:paused). AC: in page header next to Date Range; shown whenever any Block on the dashboard refreshes automatically. (D, frontmatter; E, Live updates and pause)
- UX-DR-121: `skip-link`: surface-inverse bg, text-inverse, title-sm, pill, hidden until focused, then top left of its region above all sticky layers. AC: "Skip to content" first on every page; "Back to dashboard" in drawer header; "Go to notifications" while a toast shows; "Skip to slot checklist" before Map Data preview. (D, frontmatter; E, Landmarks and skip links)
- UX-DR-122: `banner-dismissible`: banner family with × "Dismiss" button 28px square. AC: used for `map-small-screen`; never blocks the step. (D, frontmatter/Banners)

*Map Data parts*

- UX-DR-123: `mapdata-status-bar`: surface-card, 1px border-default bottom, padding 12/16, summary title-sm tabular counts, subtitle caption text-muted ("Fields → slots of your KPI & Chart block"), actions "↻ Re-run auto-map" (ghost) and "Continue to Preview →" (primary, reason inline when disabled). AC: sticky except under 480px viewport height; summary e.g. "Auto-mapped 6 of 9 · 2 confirmed · 1 required missing"; Sample data badge shown once here. (D, frontmatter/Map Data parts; E, Map Data)
- UX-DR-124: `status-meter`: one 6px-tall segment per Slot, equal widths, 2px gaps, full-radius ends; colours Confirmed/High success · Medium accent · Low warning-bar · Overridden info · Optional-empty surface-muted · Required-missing/Type-mismatch error; aria-hidden. (D, frontmatter)
- UX-DR-125: `scalar-card`: card, 1px border-default, radius md, padding 12; key mono text-primary, example mono text-muted, role chip, qualifier caption text-secondary ("· main", "· baseline", "· unit", "· as-of"); wrapping row 4 per row max, 1 per row below 640px; includes type chip. (D, frontmatter; E, Roles)
- UX-DR-126: `roles-table`: role chip above each column, sample as table-header/table-row with mono values, at most 5 rows; scroll region horizontal inside focusable region with 1px border-default edge and edge fade, labelled "Sample rows, scrolls sideways"; real `table` with th scope. (D, frontmatter; E, Roles)
- UX-DR-127: `roles-summary-line`: surface-sunken, 1px border-default, radius md, body-sm text-secondary with mono fields each followed by role chip, "Edit roles" button-link; e.g. "Roles: total Value · previous_total Value · month Time · revenue Measure · region Dimension · status Filter/Category — Edit roles". (D, frontmatter; E, Roles)
- UX-DR-128: `suggestion-row`: accent-wash, 1px accent-border, radius md, "✦" glyph accent-ink-strong, body-sm text-primary, accept (button-secondary "Set as Measure") and dismiss (button-ghost "Keep as Filter") both 28px; text "✦ revenue looks like a Measure — use as series?". AC: after accept, focus moves to affected role chip. (D, frontmatter; E, Roles)
- UX-DR-129: `record-path-list`: row = radio · path mono text-primary · "12 rows · 5 columns" caption text-muted · hint caption text-secondary; selected = accent-soft + 1px accent-ink-strong border radius md; "Suggested" badge (success-soft/success-text); "looks like pagination, not data" tag. AC: radiogroup titled "Rows from (record path)". (D, frontmatter; E, Record path)
- UX-DR-130: `aggregate-control`: surface-sunken, 1px border-default, radius md; sentence body-sm with inline compact selects ("Sum [sales.amount] by [sales.month]"); rowCounts title-sm tabular "36 rows → 12 rows"; note caption text-secondary ("ratio: sums numerator and denominator"). (D, frontmatter; E, T3)
- UX-DR-131: `transform-row`: columns 120px stage label (label-caps text-muted) · sentence (body-sm with compact inputs at 28px min height) · row counts (caption text-muted tabular "36 → 12 rows") · 28px remove; divider 1px surface-muted; error = error-soft + 3px error left rule + caption error-text message; stacked below 640px (label, wrapping sentence, counts + remove). Stage labels FILTER, CALCULATE, GROUP, AGGREGATE, SORT, TOP-N. (D, frontmatter/Map Data parts)
- UX-DR-132: `comparison-editor`: inside slot-row-expanded; source segmented-control "Mapped previous value" / "Prior-period request"; fields in two-column grid (one column below 640px); example caption text-secondary live result ("↗ 14.2% from last year"). (D, frontmatter; E, Comparison editor)
- UX-DR-133: `threshold-band-row`: columns condition · 132px colour select · 120px icon select · label · 28px remove; colourSelect swatch plus status name (Success, Warning, Error, Info, Neutral) never bare swatch; 20px order numeral caption text-muted at left; stacked below 640px (condition, colour · icon, label). (D, frontmatter; E, Thresholds)
- UX-DR-134: `parameters-table`: extends data-table; columns Name (mono) · Binding (select) · Value (input/select) · remove; resolved value mono text-muted under value ("from = 2026-01-01"); "user context" tag info-soft/info-strong. (D, frontmatter; E, Step 2)
- UX-DR-135: `fetch-error-card`: error-soft, 1px error-border, 3px error left rule, radius lg, title title-sm error-text with error icon, body body-sm text-secondary, actions Retry (secondary) and "Paste sample JSON instead" (link), "▸ Technical details" disclosure (caption 600 text-secondary, aria-expanded), details panel surface-card inset 1px border-default radius md with mono lines (status, request ID, host, reason) and "Copy request ID" ghost. AC: focus moves to its title on fetch error. (D, frontmatter; E, Step 2)
- UX-DR-136: `missing-slot-placeholder`: error-soft with 45° hatching 1px error-border every 8px, 1px dashed error border, radius md, label plate surface-card with caption 600 error-text; text "Chart series required · No Measure mapped · click to choose"; standard focus ring; button named "Chart series, required, not mapped. Choose field". (D, frontmatter; E, Hotspots)
- UX-DR-137: `source-legend`: heading label-caps text-muted "WHERE EACH PART COMES FROM"; row = role chip · field mono text-secondary · → · rendered result body-sm text-primary tabular (e.g. "Value total → $284,680"); divider 1px surface-muted; a list placed under preview Block, followed by msg:sample-note. (D, frontmatter; E, Preview)

*Prose-only components (DESIGN.md Components section, not in frontmatter)*

- UX-DR-138: Banners (full-width at top of content area, body-sm, icon plus word): Reconnecting (info-soft/info-strong), Offline while editing (info-soft/info-strong), API changed in wizard (warning-soft with 3px warning left rule), Live shape mismatch (error-soft/error-text), Map Data small-screen (info-soft/info-strong, × Dismiss). AC: all five render per spec. (D, Components Banners)
- UX-DR-139: Command palette (⌘K) popover 560px: search input top, results grouped under label-caps headers, two skeleton rows per group while loading. (D, Popovers)
- UX-DR-140: Notifications panel popover 360px: row = 28px icon tile + body-sm sentence + caption time; unread = 6px accent dot AND bold text; bell badge = error-filled pill with on-status count; empty = centred caption text-muted. (D, Popovers)
- UX-DR-141: Workspace switcher list popover: rows avatar tile, name, label, role tag; current has check; searchable above 7 Workspaces. (D, Popovers; E, Workspace switcher)
- UX-DR-142: Field picker / field tree popover 320px: search at top; disabled fields text-muted with reason in caption; tree rows have indent guide border-default, type chip, example in mono text-muted; picked leaf listbox-option-selected; "Use" pill decorative. (D, Popovers)
- UX-DR-143: Role menu popover 320px: each item shows role chip and one-line caption text-secondary description. (D, Popovers)
- UX-DR-144: Popovers generally share dialog surface at radius lg with float elevation; keyboard-active option = listbox-option-active, chosen = listbox-option-selected. (D, Popovers)
- UX-DR-145: Block Type faces overview rows: List, Progress list, Activity feed, Calendar/Agenda, Table/Data grid [CONFIRMED], Bar chart [CONFIRMED], Pie/Donut [CONFIRMED], Text/Status [PROPOSED], Initials avatar; rows separated by surface-muted dividers; footer actions are centred block-footer-link. AC: each face renders its keys as specified above. (D, Block Type faces)
- UX-DR-146: Block state faces: Loading (skeleton bars, only cold load/no prior data), Stale (values text-muted, clock icon, "Stale: last data 10:42" caption in warning), Unavailable (⚠ + msg:unavailable-user in text-secondary, never zero), Paused (values unchanged, header freshness line "Paused · data as of 10:42" caption text-secondary, no per-Block styling), Error (error icon, body-sm message, Retry link), Partial data (badge-partial-data in header), Empty (centred caption text-muted). (D, Components Block state faces)

**Layout and responsive**

- UX-DR-147: App shell by width: >= 1440px full sidebar (232px); 1280-1439px sidebar (icon rail in wizard); 1024-1279px icon rail (64px) with ☰ opening the full sidebar as a sheet; 768-1023px icon rail + ☰ sheet; 640-767px top bar with ☰ and "+ Add", sidebar as sheet; < 640px same, single column. AC: each breakpoint renders the specified shell. (E, Responsive & Platform; D, Layout App shell by width)
- UX-DR-148: Desktop shell composition: sidebar; top bar 64px (search ⌘K, notifications, settings gear, area primary action e.g. "+ Add block"; theme toggle hidden); breadcrumb strip; page header; content with 28px side padding. Mobile: 16px gutters, single column, sidebar as sheet from ☰, no horizontal page scroll. (D, Layout App shell, Mobile)
- UX-DR-149: Dashboard grid: 12 columns, 14px gap; width presets 3 (KPI card), 4, 6, 8, 12 columns; height presets Small 120 / Medium 360 / Large 540 (multiples of 60px); sizes written "{columns} columns × {Small|Medium|Large}". AC: presets set grid height not a clipping box; content overflowing at 200% text zoom or text-spacing overrides scrolls inside the Block body, a focusable region labelled with the Block title; KPI cards grow to fit in narrow/single-column layouts; control heights are minimums with no fixed-px line height or letter spacing. (D, Layout Dashboard grid)
- UX-DR-150: Narrow layout: when content area is ~720-1099px wide the grid keeps 12 columns, 3-column Blocks stay 4-up and wider Blocks render at 6 columns (2-up) in same reading order; display only, saved positions never change. (D, Layout Narrow layout; E, Responsive)
- UX-DR-151: Single-column layout: below ~720px content width Blocks render one column in the user's order; display only. (D, Layout Single column)
- UX-DR-152: Add-blocks drawer push vs overlay: at >= ~1024px viewport the 392px drawer PUSHES the dashboard and the sidebar collapses to icon rail (~824px for Blocks at 1280px); between 1024 and ~1231px with the drawer open content < 720px so dashboard is single-column in user order (still pushed, nothing covered, just-added highlight in that column); below ~1024px it is an `overlay-sheet` (modal, focus trapped, background inert, 28px dimmed strip that closes on tap, 44px Close). AC: closing restores sidebar, 12-column layout and unchanged positions. (D, Layout Add-blocks drawer; E, Add-blocks Panel T6)
- UX-DR-153: Wizard layout: left column of form section cards, right sticky preview pane (384px in Map Data; ~45% content width in steps 1-2). Below 1440px sidebar collapses to icon rail [R7] (left column ~752px at 1280px). Slot rows use `slot-row-wrapped` when left column < 760px (at 1024px left column ~496px fits). 768-1023px and 640-767px: single column, collapsible "Preview" bar pinned at the bottom, slot rows wrapped. (D, Layout Create-block wizard; E, Responsive table)
- UX-DR-154: Reflow 1.4.10: below 640px viewport (including desktop at 400% zoom / 320 CSS px): slot rows stacked; expanded slot editor stacks Format, Emphasis, Direction; scalar cards one per row; transform and threshold-band rows stack; comparison-editor one column; date-range-calendar one month; ONLY the sample-rows table, JSON view and wide Table Blocks scroll sideways, each inside a focusable region labelled (e.g. "Sample rows, scrolls sideways"); nothing else scrolls horizontally; no page horizontal scroll at 320px. AC: test at 320 CSS px. (D, Layout Reflow; E, Accessibility Floor)
- UX-DR-155: Sticky layers: at most top bar, one sub-bar (Map Data status bar or drawer header) and the tablet "Preview" bar are sticky; when viewport < 480 CSS px tall only the top bar stays sticky (status bar and Preview bar scroll); every sticky layer sets scroll-padding equal to its height. AC: focused element never hidden under a sticky layer. (D, Layout Sticky layers)
- UX-DR-156: Mobile dashboards: reorder only (drag or Move up/Move down), no free resize (FR-58); Date Range, dashboard switcher and Pause live updates become compact selects/controls in a header row; touch: hover affordances get tap equivalents, × Remove always shown beside ✓ Added, no free-space hint; all targets 44px. (E, Responsive & Platform; Edit Layout Mode)
- UX-DR-157: Zoom equivalence: 200% on 1440px (720 CSS px) uses the tablet row; 400% on 1280px (320 CSS px) uses the < 640px row; supported browsers latest two versions of Chrome, Edge, Firefox, Safari (desktop and mobile). (E, Responsive & Platform)
- UX-DR-158: Sign-in layout: split screen ~55% dark hero left, white form right; below ~1024px hero hidden and form centred with logo above. (D, Components Sign-in hero)

**Navigation and information architecture**

- UX-DR-159: Implement the two-area IA: User area ("Personal workspace": Overview, My dashboards, Templates, Profile & settings, Help & support) and Admin area ("System management": Admin overview, Block management, Create block, Draft blocks, Published blocks, Block categories, Dashboard templates, Data sources, User configuration, System settings, Audit log) sharing sidebar, top bar, breadcrumb shell; Auth: Sign in (role choice), Forgot password, Reset password. AC: routes exist for every surface in the IA table. (E, Information Architecture)
- UX-DR-160: Cross-cutting navigation: Search ⌘K, Notifications bell, settings gear (Profile & settings in User area, System settings in Admin area), Workspace switcher, Sign out; Appearance hidden in MVP. Overlays on a Dashboard: Add-blocks Panel, Edit Layout Mode, About this block, Expanded view, View as table, Dashboard switcher, Date Range picker. (E, Foundation; Information Architecture)
- UX-DR-161: Domain-agnostic copy rule: product UI copy (navigation, labels, buttons, helper text, empty states, errors, notifications, help) contains no RMG or domain terms; domain terms appear only in user-configured content (names, descriptions, Text Templates, mapped data). AC: copy audit; Voice: Admin copy uses Slot/Record Path, User copy uses "block"/"dashboard" never "Slot Mapping"; sentence case, plain verbs, numbers before nouns, no exclamation marks or emoji. (E, Foundation; Voice and Tone)
- UX-DR-162: Error voice: errors are calm, specific and actionable (what happened, what still works, what next); Admins get HTTP status, field path, request ID in an expandable "Technical details"; Users never see stack traces, HTTP codes or JSON paths. (E, Voice and Tone)
- UX-DR-163: Dashboard switcher popover: Overview first, then My dashboards in order, check on current, "+ New dashboard" and "Manage dashboards"; choosing loads in place and closes the Add-blocks Panel. (E, Dashboard switcher and Date Range)
- UX-DR-164: Date Range popover: presets Today, This week, This month, Current year, and Custom with two-month `date-range-calendar` + Apply; APG date grid (each month a `grid`; arrows day/week, Page Up/Down month, Home/End week ends; Enter sets start then end announced "May 1 to May 31 selected"; Apply disabled with reason until both set; Esc returns focus to button). Applies to Follow-dashboard Blocks, remembered per dashboard on the server; a change refreshes affected Blocks in place (last values with header spinner; skeleton only without prior data); nothing moves; focus returns to the button. (E, Dashboard switcher and Date Range)
- UX-DR-165: Period selector precedence: only Own-selector Blocks show a period dropdown; Not-date-filtered Blocks ignore periods; a Block whose period differs from dashboard Date Range states it in the subtitle ("Financial performance · This year"); changing Date Range never resets a Block Period Selector choice; About this block states behaviour in words. (E, Period selector precedence)
- UX-DR-166: My dashboards: list (`data-table`) with Rename, Duplicate, Reset to template, Delete (row or dashboard ⋯), create blank/from Template/duplicate. Reset to template only on Template-created dashboards (incl. seeded Overview): destructive confirm msg:reset-template, button "Reset Finance weekly – Jamie" replaces blocks, positions and sizes with the Template's current layout incl. Mandatory Blocks, no Undo. Delete on Overview is disabled with msg:overview-delete. (E, My dashboards actions; Information Architecture)
- UX-DR-167: Templates (User): gallery of allowed Dashboard Templates with preview and "Use template" creating e.g. "Finance weekly – Jamie"; opens with template layout and locked Mandatory Blocks; appears in My dashboards and switcher; inaccessible Block omitted with msg:template-block-omitted; none -> msg:templates-none; templates/Blocks limited to other groups never listed in gallery, drawer or search. (E, Flow 5; State Patterns)
- UX-DR-168: Workspace switcher behaviour: sidebar card opens popover (name, label, user's role), searchable above 7; switching changes whole app: same area if user holds the role there else User Overview with msg:workspace-role; unsaved wizard work prompts Save draft; search, notifications and lists are Workspace-scoped. (E, Workspace switcher)
- UX-DR-169: Settings gear and Profile & settings: gear opens Profile & settings (User) / System settings (Admin); Profile includes Keyboard shortcuts switch (On default); theme setting hidden while only light exists; Help & support shows admin-configured links and "Contact your workspace administrator". (E, Foundation; IA table)

**Wizard and Map Data**

- UX-DR-170: Wizard shell: header "Block management / Create block", title, "• Draft" badge, Save draft (every step), Preview, Publish block. Publish block enabled only on step 5 after successful validation against the real configured API; without publish permission shown disabled with msg:perm-publish while drafts still allowed. Back (←) returns to opening page; with unsaved changes shows msg:unsaved-changes (Save draft / Discard changes / Keep editing). (E, Create-block wizard Shell)
- UX-DR-171: Wizard stepper behaviour: five steps Configure Block → Data Source → Map Data → Preview → Save/Publish; completed step collapses to summary card with check and Edit; jump back to any completed step, forward only to first incomplete; nav "Create block progress" with ordered list; buttons "{n}. {label}, completed|not completed"; later steps plain text "(not started)". (E, Wizard Shell; D, Components Stepper)
- UX-DR-172: Step 1 Configure Block: fields per FR-14 and Display & behavior per FR-17 on same step; "Show footer action" enables Footer action Slot; icon picker defaults from Category with "Change" and "Use category icon"; live preview updates on every change with Desktop/Tablet/Mobile toggles, size label "8 columns × Medium (360px)", Block Type skeleton before data exists; msg:version-safe callout under preview. (E, Step 1)
- UX-DR-173: Step 2 Data Source: Data Source select shows health dot and word; Endpoint = method prefix + path; POST (GET default) reveals required msg:post-readonly checkbox, Fetch and Continue disabled until ticked; Refresh Interval lists System-settings intervals; Live disabled with msg:live-not-supported when Data Source Live flag off; managers get "Edit data source". Continue requires Endpoint AND Sample Response. (E, Step 2)
- UX-DR-174: Parameters table bindings: Fixed value (text input); Date Range · from/to (Follow-dashboard Blocks); Block Period Selector · start/end (Own selector only); User context (user ID, email, group, System-settings attribute; "user context" chip; never user-changeable). Bound rows show resolved value mono text-muted ("from = 2026-01-01"); empty path parameters block Fetch naming the parameter. (E, Step 2)
- UX-DR-175: Sample Response segmented control: "Fetch from API" (default; server-side with current params; "Fetch as user" when user-context bindings exist; msg:fetch-ok) and "Paste sample JSON" (json-textarea; validates on paste and blur announced politely: msg:json-valid or msg:json-invalid with "Go to line 4" moving caret to line/column, Continue disabled; non-JSON or oversized input rejected stating the limit). Pasted data is sample data only; no user-context binding -> "shared data" badge. (E, Step 2)
- UX-DR-176: Step 2 fetch errors: inline `fetch-error-card` with Retry, "Paste sample JSON instead", Technical details, using msg:host-not-allowlisted, msg:blocked-address, msg:response-too-large, msg:not-json or msg:fetch-failed; focus to its title; too-large/too-many-pages never truncated. No Data Sources: msg:datasource-none replaces select (with "+ Register data source" in new tab if permitted, else ask-an-admin text); Fetch and Continue disabled with reason; Paste stays available but Publish needs a real Data Source. (E, Step 2; State Patterns)
- UX-DR-177: Step 4 Preview: state switcher (Normal, Loading, Empty, Stale, Unavailable, Minimized), "Acknowledge" checkbox per unresolved warning before Continue, "Sample data" label whenever data is a sample; Unavailable state shows msg:unavailable-user not $0. (E, Step 4; Flow 1)
- UX-DR-178: Step 5 Save/Publish: summary (version to create "1.0", validation result, repeated msg:version-safe naming the version e.g. 1.1 when editing v1.0); Access radio All users / Selected groups (searchable multi-select of User-configuration groups; none chosen blocks Publish with msg:group-required; default All users; audited); access changes create no new version and apply immediately after msg:access-impact dialog (Confirm/Cancel, focus on Cancel); live shape check (mismatch lists paths and blocks Publish, Save draft remains; unreachable -> msg:live-shape-unreachable); first publish shows validation summary; success msg:publish-success toast and Published blocks opens with new row highlighted and focused. (E, Step 5)
- UX-DR-179: Publish impact confirmation dialog for new versions: title "Publish Revenue overview v1.1?"; What changed diff by Mapping, Presentation, Chrome, Endpoint before → after; impact msg:publish-impact ("214 users... 2 templates"); for Template "38 dashboards were created from this template..." ; promise "Their layouts won't move. Blocks keep their position and size; sizes outside new limits adjusted to nearest allowed size."; buttons "Publish v1.1" (primary) and Cancel; focus opens on title, Cancel first in Tab order; Esc cancels to Publish block; audited; without permission dialog never opens. (E, Publish impact confirmation)
- UX-DR-180: Data Mapping Model: six roles Label (single; PRD Dimension), Value (single headline number; Measure), Dimension (per row; Dimension), Measure (per row; Measure), Time (per row/single; Dimension), Filter/Category (per row, not plotted, used by filter transforms and group-by; Dimension) with typical Slots per table; overriding a Slot updates the Field's role (T1); changing a role re-fills unconfirmed Slots; changing Block Type re-fills Slots from unchanged roles without asking, keeping same-name-and-type Slots and flagging the rest unmapped. AC: auto-map never changes Confirmed or Overridden slots. (E, Data Mapping Model)
- UX-DR-181: Confidence rules: High = unique match by role and type; Medium = heuristic (name patterns, generated label); Low = ambiguous (several candidates, deep nesting, repetition across flattened array). With two Values, the one not named `previous_*` is the headline, the other the comparison baseline (shown to Admin). Each Medium/Low status shows a reason caption (e.g. "2 Values; picked the one not named previous_*", "3 numbers named 'amount' at different depths"). (E, Map Data)
- UX-DR-182: Map Data layout and loop: left column step 1/2 summaries, sticky status bar, "1 · Roles" ("What does each field mean?"), "2 · Review block slots", collapsible "3 · Transforms"; right sticky live preview 384px visible while left scrolls; loop Auto-map → Review → Override → Preview with every change re-rendering preview at once. (E, Map Data Layout and loop)
- UX-DR-183: Status bar behaviour: `status-meter` aria-hidden; text e.g. "Auto-mapped 6 of 9 · 2 confirmed · 1 required missing" announced politely ONLY when required-missing or needs-review count changes; Sample data badge (T5) once here; "↻ Re-run auto-map"; Continue to Preview →. (E, Map Data; Accessibility Floor)
- UX-DR-184: Sample badge rule (T5): badge appears once in the status bar and the preview carries "Sample data" whenever on-screen data is a Sample Response (pasted or fetched); pasted samples add "Pasted 09:14" to the step 2 summary. (E, Map Data Mismatches)
- UX-DR-185: Roles section scalars and rows: `scalar-card` for single values (key mono, type chip, example, role chip with qualifier Value · main, Value · baseline, Label · unit, Time · as-of); `roles-table` of up to 5 sample rows with role chip above each column; real table, th scope. (E, Roles)
- UX-DR-186: Role chip as menu button: name "Role for {field}: {role}", aria-haspopup="menu", flagged appends ", possible wrong role" (its "!" aria-hidden); items `menuitemradio` with aria-checked and one-line description on focus; after change the T1 note announced politely and focus returns to chip. (E, Roles)
- UX-DR-187: Suggestions: `suggestion-row` "✦ revenue looks like a Measure — use as series? [Set as Measure] [Keep as Filter]"; focus then moves to affected role chip. Collapse (T4): once accepted (no Low-confidence slots and no open suggestions, or "Looks right") table collapses to `roles-summary-line`; re-expands on Edit or when a role flagged, without moving focus; collapse waits for focus to leave (or immediate on "Looks right"); if collapse removes focused control focus goes to "Edit roles" and "Roles accepted. Roles table collapsed." announced. (E, Roles; Focus stability)
- UX-DR-188: "View JSON" opens raw JSON tree; "Replace sample / Fetch from API" reopens step 2 controls inline. (E, Roles)
- UX-DR-189: Slot checklist filters: radiogroup All (9) · Needs review (n) · Missing (n); Needs review = Low confidence, Medium-confidence required, Type-mismatch, Required-missing; a row confirmed under a filter stays while focus is in the list, announced ("Headline value confirmed. 1 slot still needs review."). Low-confidence slots pinned to top; pinning and sorting computed at step load and "Re-run auto-map" only. (E, Slot checklist)
- UX-DR-190: Slot statuses: Auto-mapped High/Medium/Low, Confirmed (Confirm check button; "Confirmed by you"), Overridden (other field, static text, Text Template or Calculated Field), Optional-empty, Required-missing (with suggestion "Use revenue as series"), Type-mismatch. Confirmed requirement (T2): before Continue every required and every Low-confidence Slot is Confirmed, Overridden or (Low optional only) cleared; Medium optional slots don't block but stay in Needs review. (E, Slot checklist)
- UX-DR-191: Slot row semantics: rows form a list; each a `group` labelled by name and status ("Headline value, required, auto-mapped, high confidence"); Confirm, Change field and Expand presentation (aria-expanded) in Tab order; Confirm never bound to Enter on the row; status dot and meter aria-hidden; badge reads "Confidence: Medium". (E, Slot checklist)
- UX-DR-192: Override updates role (T1): row shows note "revenue is now a Measure (was Filter/Category). Undo"; Undo restores slot and role; other unconfirmed Slots fed by the old role re-fill. (E, Slot checklist)
- UX-DR-193: Field picker: search combobox (listbox-option-active), compatible fields first, "n fields match" announced; incompatible fields aria-disabled with reason in aria-describedby ("Text field can't be a numeric headline. Use it as the currency format below."); also Static text, Text Template (FR-26 token helper), Calculated Field (FR-25 e.g. PCT_CHANGE(total, previous_total)); Esc returns focus to opener. (E, Slot checklist)
- UX-DR-194: Expanded slot row (one at a time): Format with live example ("284680 → $284,680"); Emphasis ("one headline per block"); Direction ↑ Higher / ↓ Lower is better with note "Colors the comparison: up = green" and Lower-is-better adding "· better"/"· worse" plus hidden judgement text; Thresholds via threshold-band-row "+ Add band" (ordered condition ≥, ≤, between with colour, icon, label; colours only from fixed status set success/warning/error/info/neutral never accent; icon or label required alongside colour; first match top to bottom wins). (E, Slot checklist)
- UX-DR-195: Comparison editor: Source segmented control Mapped previous value (field picker e.g. previous_total) or Prior-period request (custom offset number + unit); hidden text joins direction, value, label, judgement ("No change from last year" when flat); pasted sample cannot preview a prior-period request: preview says "Checked against the live API before publish" and step 5 check runs both requests. (E, Comparison editor)
- UX-DR-196: Footer action target (META Slot when Show footer action on): Label is a Text Template ("View all approvals"); Target Expanded view or External link with URL template using FR-26 tokens (e.g. https://erp.example.com/approvals?user={user.email}); non-http(s) shows msg:url-invalid; preview shows ↗. (E, Slot checklist)
- UX-DR-197: Block-Type settings table: Table (per column header, format, alignment, width, sortable; pagination size; row highlight rule), List (avatar initials or icon, right value, right meta), Progress list (value ÷ total or %; colour rule = threshold bands), Calendar/Agenda (week strip on/off with today; event colour from mapped category), Line/Area (per series label Text Template, direct label on/off, optional target line value and label), Bar (layout Grouped/Stacked, orientation vertical default, stack/series key, value labels, sort data order/by value), Pie/Donut (shape, centre value mapped Value/slice total/none with label, top 5 + "Other" max 6), Text/Status (mode Text via Text Template or Status via mapped field + colour rule threshold bands or value matches; no match -> neutral). (E, Block-Type settings)
- UX-DR-198: Transforms section: declarative rows only (never scripts), always displayed and run in FR-24 order; "+ Add step" inserts at its place (no drag reorder); rows: Filter "Keep rows where [status] [is] [Approved]", Calculated field "[margin] = [expression]" (FR-25 functions, inline validation, Ratio option with numerator and denominator), Group "Group by [region]", Aggregate "[SUM] of [revenue]" (SUM, COUNT, AVG, MIN, MAX), Sort "Sort by [revenue] [descending]", Top-N "Keep the top [5] rows"; each control named ("Filter field", "Filter operator", "Filter value"); one-line descriptions on focus and hover via aria-describedby; rows in/out ("36 → 12 rows"); row errors ("status isn't in the response", "Can't SUM a text field", invalid expression with position) block Continue; × named "Remove step: Keep rows where status is Approved". (E, Transforms)
- UX-DR-199: Preview hotspots: mapped regions faint dashed outline; hover accent outline + tooltip "Click to change field · Headline value ← total"; keyboard focus standard ring + same tooltip; each a button "{Slot}: {field}, {rendered value}. Change field"; click/Enter/Space scrolls to the Slot, expands it, opens picker with focus in search; Esc returns to hotspot; missing required Slot = hatched missing-slot-placeholder button; preview Block chrome (↻, –, ⋯) inert; slots with no region (Data-as-of, series 2..n) reached from checklist. Preview region "Live preview, sample data" preceded by skip link "Skip to slot checklist". (E, Preview, hotspots)
- UX-DR-200: Record path: with more than one array or nesting deeper than 2 levels Roles opens with "Rows from (record path)" `record-path-list` radiogroup (row count, columns, hint); choosing one re-runs auto-map; most rows = "Suggested"; `meta.links[]`-like arrays = "looks like pagination, not data"; status bar "◐ Auto-mapped 6 of 9 · 3 low confidence — please review" with explanatory text on 3 candidate arrays and numbers nested 3-4 levels deep. (E, Record path)
- UX-DR-201: Field tree for deep responses: searchable, collapsible, with type and example; breadcrumb nav "Field path" (data › summary › kpis › total); "Use" per leaf; footer "Selected: data.summary.kpis.total.amount · 284680 → $284,680". APG tree: role=tree, treeitem with aria-level, aria-expanded, aria-setsize, aria-posinset; → ← expand/collapse, ↑ ↓ move, Home/End, type-ahead; Enter on a leaf = Use ("Use" pill aria-hidden); names include type and example ("amount, number, 284680"); incompatible leaves aria-disabled with reason; active listbox-option-active, picked listbox-option-selected. (E, Preview, hotspots and nested data)
- UX-DR-202: Nested data "Aggregate rows by…" (T3): flattening a nested array (data.regions[].stores[]) makes parent fields columns (region.name) and shows `aggregate-control` above sample table; default sum Measures grouped by Time field with row counts ("36 rows → 12 rows (sum of sales.amount by sales.month)"); group-by fields and aggregation (SUM, COUNT, AVG, MIN, MAX) editable and write the equivalent Transform (editable in either place); ratio fields aggregate ratio-safely labelled "ratio: sums numerator and denominator". (E, Nested data; Transforms)
- UX-DR-203: Type-mismatch handling: role change, Block Type change or re-fetch can cause it; Slot shows reason ("data.currency is text ("USD"). Headline value needs a number."), compatible fields, and "Treat as …" when parsing fixes it (e.g. "2026-01" as month); blocks Continue. (E, Mismatches)
- UX-DR-204: API changed: on "Re-fetch sample" or when published Block's scheduled check or run-time mapping fails: msg:api-changed banner; affected Slots become Required-missing (Unavailable for published version); dependants pause ("Comparison (% change) is paused too"); msg:api-match-found suggestion never applied silently; fix of a published Block creates a new Draft version on normal publish path. (E, Mismatches)
- UX-DR-205: Continue to Preview enabled only when (1) every required Slot mapped, (2) every required and Low-confidence Slot Confirmed/Overridden, (3) no Type-mismatch, (4) Calculated Field/Text Template/Transform rows validate and footer URL template http(s), (5) a flattened array used by a per-row chart Slot has aggregation set. Until then aria-disabled with reason inline (msg:required-slot-missing or first blocker), not only in tooltip; activating focuses first blocking row; several blockers add an error summary of links at top focused on activation. (E, Mismatches, API changes and Continue)
- UX-DR-206: Map Data below 640px: one column; preview becomes collapsible "Preview" bar; msg:map-small-screen banner shows once, never after Dismiss, never blocking. (E, Map Data Layout; D, Banners)
- UX-DR-207: Data source form (list + form): list `data-table` with name, host, auth type, health (dot + Healthy/Degraded/Unreachable), last successful call, Blocks using it; primary "+ Register data source". Form sections: Connection (Name*, Base URL*, allowlist check on blur; blocked -> msg:host-not-allowlisted inline, Save disabled), Authentication (API key header+key; Bearer token; OAuth2 token URL, client ID, client secret, scope; Basic username/password; secrets write-only), Default headers (key/value rows, "+ Add header", values markable secret), Limits and pagination (Timeout, max response size, max pages; pagination style None/page/offset/cursor/link header), Refresh ("Supports Live refresh (~30 s)" off by default with helper text), Test connection (msg:test-ok or error card with Technical details; optional before Save), Save returns to list with row highlighted and health "Checking…". Health shows dot AND word plus "Last success 10:42" in list, step 2 select, Platform health, Admin notifications; Admins see "Data as of 10:40 · checked 10:55" in About this block. (E, Data source form)
- UX-DR-208: Version history and restore: "Versions" in row menu of Block management, Published blocks, Dashboard templates opens `data-table` newest first (version, Lifecycle State Draft/Published/Unpublished/Archived, published by, date, change summary); current user-served version tagged "Current", older Published rows "Superseded by v1.1" caption text-muted; "Live" word not used; View is read-only preview with state switcher and per-step summary; "Restore as draft" creates new Draft (msg:restore-done) and opens wizard at step 1; if a Draft exists msg:restore-replace first (Cancel focused, "Replace draft"); needs publish permission and is an Audit Event. (E, Version history and restore)
- UX-DR-209: Unpublish and archive confirmations (publish permission only): "Unpublish Revenue overview?" with msg:unpublish-impact and buttons "Unpublish Revenue overview"/Cancel; "Archive Revenue overview? It becomes read-only." with impact count while published; audited; users see Unpublished face and "was unpublished" notification. (E, Unpublish and archive)
- UX-DR-210: Template editor: Name*, Description, Access (as step 5), canvas = grid in Edit Layout Mode always on, "+ Add block" lists published Blocks with first-free-slot rule; Mandatory `switch` in each Block's ⋯ (menuitemcheckbox On/Off) with msg:locked badge; Save draft and "Publish template" follow Block lifecycle (validation summary, impact dialog with Template line, Version history); newly mandatory Blocks appended per FR-47 and on next load show just-added outline and user gets msg:mandatory-added. (E, Template editor)
- UX-DR-211: Password recovery: Forgot password (one email field, "Send reset link", always msg:reset-requested, throttled like sign-in); Reset password (new + confirm, then Sign in with msg:password-changed; expired/used msg:reset-expired with "Request a new link"); autocomplete (email `username`, password `current-password`, new/confirm `new-password`; profile `name`, `email`); paste into passwords allowed; Show password button with aria-pressed keeping caret and value (named "Show password"). (E, Password recovery)
- UX-DR-212: Sign-in screen: role cards (User/Admin) radiogroup; email and password inputs with leading icons and show/hide eye; Remember me checkbox and "Forgot password?" (accent-ink); full-width primary "Sign in as User →/Admin →"; "Secure workspace access" divider and help line; eyebrow "WELCOME BACK", title "Sign in to your workspace", subtitle msg:signin-subtitle; errors msg:signin-role-denied, msg:signin-failed, msg:throttled. (D, Sign-in hero; E, Password recovery)

**Add-blocks Panel**

- UX-DR-213: Opening: "+ Add block" (top bar or a search result) opens "Add blocks — Build a dashboard that works for you" on the right; dashboard stays visible and usable; focus goes to search; wide drawer is non-modal `complementary` "Add blocks"; header "Back to dashboard" skip link goes to first Block (or "+ Add block" when empty). (E, Add-blocks Panel)
- UX-DR-214: Drawer content: sticky search (placeholder "Search blocks…", accessible name "Search blocks") covering names, descriptions, tags with highlights; category chips (FR-43); Filter (type, Data Source); count "8 available blocks" / "2 of 8 blocks" (announced politely, debounced ~500 ms); list; "On your dashboard" group; footer msg:drawer-footer and "Done". "Request a block" button out of MVP. (E, Add-blocks Panel)
- UX-DR-215: Drawer row behaviour: + Add ("Add {Block} to dashboard") adds immediately without confirmation; "✓ Added" persistent status text not a control; separate "× Remove" ("Remove {Block} from dashboard"); Locked for Mandatory; no Block twice. ARIA: `list` of `listitem`s each with a disclosure button (name; aria-expanded; aria-controls preview; description as aria-describedby) plus action buttons; row-body click or Enter/Space on disclosure opens inline preview. (E, Add-blocks Panel)
- UX-DR-216: Inline preview (accordion, one open, scroll kept): full description, real Block ~340px wide with sample data, msg:sample-drawer, meta "Data source: HR analytics API · Refreshes hourly · v1.0 · Default size 4 columns × Medium"; region "Preview of {Block} with sample data"; Block inert; "+ Add to dashboard" (row collapses to ✓ Added) and "Close preview"; Esc collapses it, a second Esc closes the drawer. (E, Add-blocks Panel)
- UX-DR-217: "On your dashboard" group: Mandatory Blocks with lock and msg:locked, announced "required by your admin and cannot be removed"; excluded from the count but still searchable; added non-mandatory Blocks stay in main list as ✓ Added so the list never jumps. (E, Add-blocks Panel T10)
- UX-DR-218: Placement (D4/T7): first free grid position at default size computed on the SAVED full-width layout (not narrow view); existing Blocks never move or resize; no auto-scroll; `just-added-outline` + "Just added" ~3 s persisting ~2 s after drawer closes; focus stays on the row; single-column layouts add at end; out of view: no scroll, msg:toast-add-below and msg:edge-pill until scrolled to or Show me; free-space hint only on hover/focus of "+ Add". (E, Add-blocks Panel Placement)
- UX-DR-219: Add toast: msg:toast-add top right of dashboard area never over drawer; "Show me" scrolls, pulses outline (static outline under reduced motion) and focuses Block title; "Undo" removes; on mobile inside sheet and Show me closes sheet first; timing per R3 (at least 10 s, pause on hover, never auto-dismiss while focus in drawer or toast). (E, Add-blocks Panel; Toasts with Undo)
- UX-DR-220: Remove: "× Remove" or ⋯ "Remove from dashboard" removes at once with msg:toast-remove, same timing and undo, no confirmation [A6]; focus from drawer stays on row (now + Add); from ⋯ per Focus stability; Undo returns focus to Block title; Mandatory Blocks show reason, no Remove. (E, Add-blocks Panel Remove)
- UX-DR-221: Add-blocks Panel states: empty search msg:drawer-empty-search with Clear search; none published msg:drawer-none; loading row skeletons with thumbnail placeholders; load failure msg:drawer-load-failed with Retry and search/chips disabled with reason; no space in view msg:toast-add-below with "Show me ↓" and edge pill; inline preview always shows msg:sample-drawer. (E, State Patterns)
- UX-DR-222: Out of MVP (must not be built): multi-add [A5] (use fast sequential adds: ↓ then A, or Tab to next + Add), dragging from drawer onto grid [A4] (no drag grips, footer says to use Edit layout), "Request a block" button. Mobile Add-blocks Panel can stay open in Edit Layout Mode still placing in first free slot. (E, Add-blocks Panel Out of MVP; Edit Layout Mode)

**Edit Layout Mode and placement/undo**

- UX-DR-223: Edit Layout Mode toggle: "✎ Edit layout" becomes "Done" beside Undo/Redo (`edit-layout-toolbar`); leaving returns focus to "✎ Edit layout"; movable Blocks get move handle (whole header), `resize-handle` if Allow resize, and hint "Drag to move · corner to resize · M to move with the keyboard". (E, Edit Layout Mode)
- UX-DR-224: Keyboard move (APG grab): focusable "Move {Block}" button described "Press M or Space to pick up"; M or Space picks up, arrows move, Shift+arrows resize, Enter drops, Esc cancels move and restores Block; moves announced against neighbours ("Revenue vs Expense, row 3, column 7, right of Revenue overview; Project status moved down"). (E, Edit Layout Mode; Keyboard model)
- UX-DR-225: Resize and push: resize snaps to column and height presets within min and max (FR-17); dragging pushes overlapped Blocks down, nothing compacts upward; Mandatory Blocks can move and resize but not be removed; ⋯ adds Move up, Move down, Make wider, Make narrower. (E, Edit Layout Mode)
- UX-DR-226: Save semantics: Done or a second Esc (nothing picked up, no menu open) saves to server; failure keeps mode open with msg:layout-save-failed; first Esc only cancels a move, a second Esc exits and saves; one press never does both. (E, Edit Layout Mode; Keyboard model)
- UX-DR-227: Undo/redo: Ctrl+Z / ⌘Z on dashboard undoes last add or remove with no time limit (history lasts while dashboard open), toast or not; works with single-key shortcuts off; ignored in text fields; in Edit Layout Mode it is the mode's Undo with Ctrl+Shift+Z / ⌘⇧Z Redo; every Undo fully reverses. (E, Keyboard model; Toasts with Undo)
- UX-DR-228: Optimistic UI: Add, Remove, minimize and layout moves apply at once and roll back with msg:toast-rollback (role=alert) on rejection, restoring element in place and keeping focus on it. (E, Optimistic UI)
- UX-DR-229: Newly mandatory Blocks append at end of user's layout, show just-added outline on next load with msg:mandatory-added (the one exception to "layouts never move"). Published updates keep position and size ("Their layouts won't move"). (E, Template editor; Flow 3)
- UX-DR-230: Drag and resize only in Edit Layout Mode with keyboard or menu equivalent; no drag from Add-blocks Panel; mapping uses role assignment, field pickers and hotspots, never drag [A3]. One grid library shared by Edit Layout Mode, Template editor and wizard preview with in-place updates and stable keys; narrow reflow display-only, never writes positions back. (E, Interaction Primitives; Foundation UI system)

**Block states and chrome**

- UX-DR-231: Block Chrome controls: Refresh ↻ (this Block only; spinner in icon; values update in place; focus stays; rate-limited -> msg:refresh-limited with aria-disabled for the window; works while paused), Minimize – (header only; persisted per Block Instance; becomes Expand), ⋯ menu (Refresh; About this block; Expanded view if configured; View as table for every chart Block; Remove from dashboard disabled with msg:locked when Mandatory). Footer action: in-app expanded view or external http(s) link in new tab with ↗. (E, Block Chrome controls)
- UX-DR-232: About this block panel: description, Data Source, Refresh Interval, Data-as-of Time with full date and zone (e.g. "Data as of 5 Oct 2026 10:40 BST"), version, period behaviour in words; Admins also see technical details and msg:unavailable-admin, "Data as of 10:40 · checked 10:55". (E, Block Chrome; State Patterns; Data source form)
- UX-DR-233: Block state: Loading skeleton only on cold load or no prior data, real header; later refreshes update in place. Empty: msg:block-empty centred body-sm text-muted. Error: msg:block-error with Retry link; Admins get Technical details in About this block. (E, State Patterns)
- UX-DR-234: Block state Stale: last values text-muted, clock icon, msg:stale in warning ("Stale: last data 10:42"; "yesterday 22:10" or "3 Oct 22:10" when not today); recovers automatically; Stale and Unavailable announced aggregated per dashboard debounced ~2 s (msg:stale-aggregate), recovery announced once. (E, State Patterns; Messages; Accessibility Floor)
- UX-DR-235: Block state Unavailable: Slot shows msg:unavailable-user with ⚠, NEVER 0/NaN/blank; About this block and Admins see msg:unavailable-admin; rest of Block renders. Partial data: missing cells "—" and header badge "Partial data". (E, State Patterns)
- UX-DR-236: Block state Minimized (header only, Expand replaces Minimize); Paused (values unchanged, no auto refresh, header msg:paused); Unpublished (msg:block-unpublished with Remove); Access removed [B1] (msg:block-access-removed with Remove, no data shown). (E, State Patterns)
- UX-DR-237: Dashboard states: Empty (msg:dashboard-empty with + Add block); Cold load (shell at once, each Block loads independently with own skeleton); Layout fails to load (shell renders, msg:dashboard-load-failed with Retry, no partial/default layout, nothing saved). (E, State Patterns)
- UX-DR-238: Live updates and pause [R4]: header `live-updates-toggle` (aria-pressed) shown whenever any Block refreshes automatically; paused = no automatic refresh, msg:paused with oldest Data-as-of Time, button reads "Resume live updates"; ↻, Date Range and Block Period Selector changes still fetch; Resume refreshes paused Blocks immediately; pause lasts until resumed or dashboard left, reopening starts live; no animated value changes (no tickers, no chart transitions); routine refreshes never announced; header freshness line shows oldest Data-as-of of Live Blocks. (E, Live updates and pause; State Patterns)
- UX-DR-239: Focus stability: update in place with stable keys (never replace or re-create a focused element); if focused item disappears focus nearest survivor and announce politely ("Recent activity removed. Focus on Project status."): removed Block -> next Block title, else previous, else "+ Add block"; slot row leaving filter -> next row, else previous, else filter; control removed by roles collapse -> "Edit roles"; removed transform row -> next, else previous, else "+ Add step"; Admin list row -> next row, else list search. Never auto-collapse, re-sort or re-pin while focus is inside. (E, Interaction Primitives Focus stability)
- UX-DR-240: Dynamic escaped rendering: API text renders as text (FR-37); external links show ↗ and open in new tab. (E, Security-conscious UX)

**Charts and tables**

- UX-DR-241: Chart Block types: Line/Area, Bar, Pie/Donut, KPI & Chart. Plot is role="img" with generated aria-label (type, series, X range, lowest/highest, trend, highlighted point, target line), e.g. "Line chart, Revenue, Jan to Dec 2026. Lowest $10.2k in Jan, highest $40.1k in Dec, trend up. Target $35k"; bars name categories and largest value; pies largest slices with %; regenerated on change, never announced. (E, Chart Block)
- UX-DR-242: Chart keyboard: one tab stop; ← → step points (Home/End to ends), ↑ ↓ switch series; point shows chart-point-focus and hover tooltip and is announced politely ("Revenue, March, $21.4k"); bars and slices step the same way; Esc leaves point mode keeping focus on plot; tooltips never hide the focused point. (E, Chart Block)
- UX-DR-243: "View as table" always in ⋯ menu for every chart Block regardless of Expanded view setting; opens `expanded-view` with data as accessible table (th scope, units in headers, chart formats); focus to panel title; Esc/Close returns to ⋯; loading skeleton rows, msg:expanded-empty, msg:expanded-error with Retry. (E, Chart Block; State Patterns)
- UX-DR-244: Multi-series charts: two or more series require direct labels or dash and marker variants [R2]; series-1 lines use chart-1-stroke [R1]; bar/pie series 3-6 take fill patterns; legend is a list naming marker and dash; tooltip names the series; max 6 series, top-N/"Other" beyond. (E, Chart Block; D, Colors)
- UX-DR-245: Table Block: real `table` with visually hidden caption = Block title, th scope="col", units in headers; sortable columns only via button in th carrying aria-sort, cycle ascending → descending → data order, announced ("Sorted by Amount, descending"), focus stays, session-only; pagination uses Block pagination size, Previous/Next around "1–10 of 48" with aria-disabled at ends, focus stays and new range announced, resets to page 1 on sort/Date Range/period change; row highlight icon and label in first cell and in row accessible text ("At risk"); overflowing table scroll container focusable labelled with Block title; Refresh replaces values in place keeping sort and page (or shows and announces last page if page gone). (E, Table Block)
- UX-DR-246: Delta display rule everywhere (KPI, Admin metric cards, tables): always ↗/↘ with sign where applicable; colour never sole indicator; hidden text states direction and judgement; Lower-is-better adds "· better"/"· worse". (D, KPI card; Admin overview; E, Slot checklist Direction)
- UX-DR-247: Expanded view for configured Blocks: footer action "Expanded view" opens full-size Block with paginated table; focus to panel title, Esc/Close returns to invoker; 90% viewport up to 1200px, full screen below 768px. (D, expanded-view; E, Block Chrome)

**Locks, session, autosave, offline/reconnect**

- UX-DR-248: Autosave [R5]: wizard autosaves continuously and restores at same step after re-sign-in with msg:session-expired; unsaved secrets never autosaved. (E, Wizard Shell; Session timeout)
- UX-DR-249: Session timeout warning [R5]: 2 minutes before expiry show dialog msg:session-warning with countdown, "Stay signed in" (primary, initial focus) and Sign out; announced at 2:00, 1:00 and 0:30; Stay signed in extends and restores focus. Other forms (Data source form, Template editor, settings) same warning; after expiry sign-in returns to the page but unsaved fields are not restored. Idle timeout length TBD. (E, Session timeout)
- UX-DR-250: Soft draft lock: when another Admin has the Draft open show read-only view with msg:draft-locked banner and buttons "Take over editing"/Close; Take over autosaves the holder's work then notifies them with msg:draft-taken-over; lock releases after ~15 min inactivity or tab close; nobody's changes silently overwritten. Applies also to Data source form and Template editor concurrent edits. (E, State Patterns)
- UX-DR-251: Offline in wizard/forms: banner msg:offline-editing; editing continues; Save draft, Fetch, Test and Publish disabled with that reason (aria-disabled + inline reason) until reconnect. Save failure: msg:save-failed inline by Save draft with Retry, every change stays. Generic admin lists offline: read-only, row actions disabled with msg:offline-editing. (E, State Patterns; Generic states)
- UX-DR-252: Dashboard offline/reconnecting: banner msg:reconnecting announced once (role=status), Blocks keep last values then go Stale on schedule; on reconnect msg:back-online once and Blocks refresh in place; no offline editing (non-goal). (E, State Patterns; Accessibility Floor)
- UX-DR-253: Unsaved changes: leaving with unsaved changes shows msg:unsaved-changes with Save (or Save draft), Discard changes, Keep editing (focus on Keep editing in generic forms). (E, Messages; Generic states)
- UX-DR-254: Locks on Mandatory Blocks: Locked badge/msg:locked ("Required by your admin"), no Remove in drawer or ⋯ (disabled with reason), still movable/resizable. (E, Add-blocks Panel; Block Chrome; Edit Layout Mode)
- UX-DR-255: Permission gating: Publish, Unpublish, Restore, Archive shown disabled with msg:perm-publish (aria-disabled, reason inline), drafts still allowed; Admin pages without permission show msg:perm-denied, page not rendered, audited; non-permitted actions aria-disabled with reason. (E, Wizard Shell; State Patterns; Security-conscious UX)

**Search, notifications**

- UX-DR-256: Search ⌘K: combobox palette from top bar or ⌘K/Ctrl+K; active result listbox-option-active via aria-activedescendant; grouped Dashboards, Blocks, Templates and for Admins Data Sources, Users, Settings; only permitted items; Block result opens Add-blocks Panel with that row focused and expanded; states: ready groups shown immediately, two skeleton rows per loading group, "Searching…" announced once after ~1 s, no match msg:search-empty. (E, Search ⌘K; State Patterns; D, Popovers)
- UX-DR-257: Notifications: bell shows unread count (number, not only dot); popover lists items newest first (icon, sentence, relative time, link) with "Mark all as read"; User types: new Block, msg:notify-version, Block unpublished; Admin types: other Admins' publications, Data Source health, repeated mapping failures (msg:notify-mapping-failed); in-app only, email deferred; empty msg:notifications-empty. (E, Notifications; D, Popovers)

**Admin screens**

- UX-DR-258: Admin overview: four `admin-metric-card`s (Published blocks with change this month, Active dashboards with change this week, Template adoption % of dashboards from a Template, Active users count and share active this week) with ↗/↘ signed changes, shares plain; windows and "active" definition TBD (PRD labels until defined); primary action "Create block". (E, Admin overview and Platform health; D, Admin overview)
- UX-DR-259: Admin overview "Recent block activity" panel: five latest publish/edit actions newest first (category icon tile, Block name, "Published by …"/"Draft by …", time relative within a day then "Yesterday" then date); rows open Block management; "View all →" opens Audit log filtered to Block events. (E, Admin overview; D, Admin overview)
- UX-DR-260: Admin overview "Platform health" panel: Platform services (Dashboard API, Data refresh service, Authentication, Notification service: dot, word, uptime %) and Your data sources (dot, Healthy/Degraded/Unreachable, "Last success 10:42"; opens form); overall badge Operational/Degraded/Outage with word; Degraded/Outage triggers Admin notification. (E, Admin overview; D, Admin overview; State Patterns)
- UX-DR-261: Admin list pages use `data-table`: Block management (search, filter, edit, duplicate, unpublish, archive, versions), Draft blocks, Published blocks (status, version, owner, last updated), Block categories (create, rename, order, archive; drive drawer chips), Dashboard templates, Data sources, User configuration (invite, deactivate; role, permissions, groups), Audit log (search and export Audit Events), Version history. (E, Information Architecture)
- UX-DR-262: System settings: allowlist, retention, refresh intervals, locale, Template for new users. Settings and admin-management screens (Profile & settings, Help & support, User configuration, System settings, Block categories, Audit log, Workspace switcher) get generic states only. (E, IA; Generic states)
- UX-DR-263: Generic list/form states for every Admin list and form (and Profile, Help, User config, System settings, Block categories, Audit log, Dashboard templates, My dashboards): Lists: cold load toolbar + 5 skeleton rows; load failure full-width row "We couldn't load {items}. Try again." with Retry; empty msg:list-empty with primary action; no results msg:list-no-match with Clear search and count "0 of n" announced politely; save success row highlighted (accent-soft, leading bar) and focused; save failure rollback with msg:toast-rollback (alert, not auto-dismissed). Forms: skeleton fields with Save disabled until loaded; load failure "We couldn't load these settings. Try again." with Retry (never stale values); save success msg:saved with focus staying on Save; save failure msg:save-failed form wording, values kept, focus to message; validation inline errors + error summary; unsaved changes; offline; permission denied; action not permitted (aria-disabled with reason). (E, Generic states)

**Accessibility (WCAG 2.1 AA)**

- UX-DR-264: Conformance target WCAG 2.1 AA (NFR-7) for the light theme: text contrast >= 4.5:1, non-text >= 3:1 per the D token tables; dark-theme contrast unchecked while deferred. AC: automated (axe) and manual audits pass on every screen. (E, Accessibility Floor; D, Colors)
- UX-DR-265: Drag alternatives (FR-59): move with M or Space + arrows or the ⋯ menu; resize with Shift + arrows or Make wider/narrower; add without dragging. AC: every drag action has a keyboard path. (E, Accessibility Floor)
- UX-DR-266: Focus order: Drawer = "Back to dashboard", Close, Search, chips (one stop), Filter, each row's disclosure then actions, Done. Wizard = "Skip to content", stepper, step content in reading order, status bar actions, Continue. (E, Accessibility Floor)
- UX-DR-267: Focus return: closing the drawer returns to "+ Add block" (or the added Block after Show me); closing any popover, picker or dialog returns to its invoker (or its row if invoker gone); refresh, collapse, filter or removal never drops focus to body. (E, Accessibility Floor; Destructive confirmations)
- UX-DR-268: Focus visible and not obscured: focus-ring on every interactive element; active options use listbox-option-active; hotspots use standard ring; sticky layers set scroll-padding. (E, Accessibility Floor; D, Layout)
- UX-DR-269: Keyboard model: ⌘K/Ctrl+K global search; Ctrl+Z/⌘Z undo add/remove; single-key shortcuts (/ focuses drawer search only while drawer has focus; A adds focused drawer row; M picks up focused Move button) fire only while their component has focus and focus is not in a text field, and can be turned off in Profile & settings (default On) leaving modifier shortcuts and widget keys (arrows, Enter, Space, Esc, Tab) working and Move {Block} still taking Space; ↑ ↓ optional accelerators between drawer rows and slot rows; Enter/Space activate focused button, Enter never has a hidden second meaning on a row; F6/Shift+F6 cycle dashboard, drawer and toast region as an enhancement only; Esc closes innermost layer (picker/popover, then inline preview, then drawer). (E, Keyboard model; Profile & settings R6)
- UX-DR-270: Landmarks and skip links: banner (top bar), navigation (sidebar or icon rail), main (labelled with page or dashboard name), non-modal complementary "Add blocks" for wide drawer, modal dialog with focus trapped for mobile sheet, toast stack = region "Notifications"; skip links "Skip to content" (first on every page), "Back to dashboard", "Go to notifications" while a toast shows, "Skip to slot checklist". (E, Interaction Primitives)
- UX-DR-271: Destructive confirmations (unpublish, archive, delete dashboard, reset to template, replace draft on restore, access change): role="alertdialog", aria-labelledby title, aria-describedby impact, initial focus on Cancel, Esc cancels, destructive button repeats the object, focus returns to invoker or its row. Other dialogs (publish impact, session warning, unsaved changes) focus the title or the safe primary action; dialogs at most one deep; inline expansion preferred over modals (drawer previews, slot editors, Technical details, roles table expand in place one at a time). (E, Interaction Primitives)
- UX-DR-272: Disabled controls pattern: blocked primary/row actions (Publish without permission, Continue to Preview, Fetch, Restore, Unpublish, Archive, Delete on Overview, rate-limited Refresh) use aria-disabled="true", remain focusable, aria-describedby points to inline reason; activation does nothing except focus first blocker and announce reason; reason text never dimmed. (E, Interaction Primitives)
- UX-DR-273: Never colour alone: deltas carry ↗/↘ (+ word for Lower-is-better); statuses icons and words; role chips names; confidence a word and meter; selected states >= 3:1 bar/border/check/radio; chart series markers, dashes or direct labels. (E, Accessibility Floor; D, Do's and Don'ts)
- UX-DR-274: Names and forms (1.3.5, 3.3.1, 4.1.2): buttons carry full names ("Remove Calendar from dashboard"); labels above fields; submit focuses first invalid field; 2+ errors show an error summary of links focused on submit; required marker aria-hidden with required attr; autocomplete attributes on auth/profile forms. (E, Accessibility Floor; Small components)
- UX-DR-275: Reflow and zoom (1.4.10, 1.4.4, 1.4.12): at 320 CSS px (400%) one column no horizontal page scroll; only sample-rows table, JSON view and wide Table Blocks scroll sideways in labelled focusable regions; control heights are minimums; Block content scrolls inside its Block so 200% text and text-spacing overrides lose nothing; under 480px viewport height only top bar sticky. (E, Accessibility Floor; D, Layout)
- UX-DR-276: Live regions: POLITE for drawer count (debounced ~500 ms), add/remove/move, action toasts with Undo route, Map Data status bar on required-missing or needs-review count change, JSON validation, focus recovery, sort and page changes; ONCE (role="status") for reconnecting and msg:back-online; Stale/Unavailable aggregated per dashboard and debounced ~2 s into one message (msg:stale-aggregate), recovery once; ASSERTIVE (role="alert") for error and rollback toasts, not auto-dismissed; NEVER announced: routine refreshes, freshness line, KPI values, chart data and summaries. (E, Accessibility Floor)
- UX-DR-277: Reduced motion (prefers-reduced-motion): static outline instead of Show me pulse; instant reflow, drawer push and slide, sidebar collapse; no chart draw-in or value transitions; toasts appear without sliding; accordions open without animation; "Just added" halo static outline for 3 s; skeletons don't shimmer. (E, Accessibility Floor; D, skeleton, just-added-outline)
- UX-DR-278: Pointer targets: >= 24px (target-min) generally; 28px (target-chrome) for Block Chrome icons, chips, row actions, toast actions and toast close; 44px (target-touch) on all touch layouts. (E, Accessibility Floor; D, spacing)
- UX-DR-279: Status pieces and widget semantics: dots, status-bar meter and progress bars aria-hidden (word/number is the value); Sample badge "S" glyph aria-hidden; Technical details = disclosure with aria-expanded plus "Copy request ID" button; data tables real table with sortable header buttons carrying aria-sort; radiogroup/segmented/switch semantics per Small components; tooltips per 1.4.13. (E, Small components)
- UX-DR-280: Toasts with Undo timing [R3]: action toast stays at least 10 s, pauses on hover, never auto-dismisses while focus in drawer or toast, announced politely naming the Undo route ("Undo with Ctrl+Z"); error and rollback toasts role="alert" and never auto-dismiss; toast stack region "Notifications". (E, Interaction Primitives)
- UX-DR-281: Tooltip + hover/focus parity and touch: hover affordances have tap equivalents on touch; hotspots, icon rail items, disabled reasons, chart points, role/transform descriptions all expose tooltip on focus. (E, Small components; Responsive)

**Canonical messages table**

- UX-DR-282: Implement ALL 89 canonical message keys of EXPERIENCE.md → Voice and Tone → Canonical messages in a vue-i18n catalogue (default locale en) as the single source of product copy; components reference keys, never inline strings; screen-reader (announced) variants stored as separate/paired keys where given (toast-add, toast-add-below, toast-remove, session-warning countdown, stale, stale-aggregate); parametrised values ({block}, {time}, {count}, {user}, {line}, {items}, {query}, {version}) interpolated from real data. Keys: field-error, signin-role-denied, signin-failed, throttled, fetch-failed, json-invalid, json-valid, test-ok, notify-mapping-failed, notify-version, fetch-ok, required-slot-missing, post-readonly, url-invalid, group-required, access-impact, api-changed, api-match-found, live-shape-unreachable, drawer-empty-search, drawer-none, drawer-load-failed, dashboard-empty, dashboard-load-failed, block-empty, block-error, sample-wizard, sample-drawer, sample-note, toast-add, toast-add-below, toast-remove, toast-rollback, edge-pill, free-space-hint, drawer-footer, unavailable-user, unavailable-admin, stale, stale-aggregate, paused, reconnecting, back-online, refresh-limited, block-unpublished, block-access-removed, locked, perm-publish, perm-denied, publish-impact, publish-success, version-safe, host-not-allowlisted, blocked-address, response-too-large, not-json, live-not-supported, datasource-none, reset-requested, reset-expired, password-changed, restore-done, restore-replace, unpublish-impact, reset-template, overview-delete, mandatory-added, template-block-omitted, templates-none, search-empty, notifications-empty, expanded-empty, expanded-error, workspace-role, layout-save-failed, save-failed, offline-editing, session-warning, session-expired, draft-locked, draft-taken-over, map-small-screen, list-empty, list-no-match, saved, unsaved-changes, wizard-subtitles, page-subtitles, signin-subtitle. AC: catalogue contains exactly these 89 keys with the Do text verbatim; a test fails on any missing key or any hard-coded duplicate of a Do/Don't string; each Don't phrasing is absent from the UI; example values (e.g. "Revenue overview", "10:42", "214") come from real Block/time/count. (E, Canonical messages)

**Branding hooks**

- UX-DR-283: Brand-token seam: only the brand tokens (accent, accent-strong, accent-border, accent-soft, accent-wash, accent-ink, accent-ink-strong, accent-ink-inverse, on-accent, logo-tile, logo-mark; chart-1 follows accent and chart-1-stroke follows accent-ink) are overridable per Workspace at runtime in future (V2); system tokens never overridable; MVP ships Dashflow defaults with no admin UI for overriding. AC: a single override layer (e.g. data-workspace or style scope) can swap brand tokens and logo without touching component code; just-added halo follows accent override. (D, Brand & Style; Colors; E, Foundation)
- UX-DR-284: Override guardrails [PROPOSED]: on-accent switches automatically between #111827 and #FFFFFF, whichever has higher contrast; Workspace-supplied accent-ink must be >= 4.5:1 on white and on its own accent-soft else default amber kept; accent-ink-inverse must be >= 4.5:1 on surface-inverse and hero-chip else default kept. AC: unit tests for derivation and fallback. (D, Colors Override guardrails)
- UX-DR-285: Dark-ready tokens: all components reference only semantic tokens (no hex or rgba in components); a later dark theme adds a "-dark" counterpart per system and brand token (e.g. surface-card-dark, text-primary-dark); MVP ships light theme only; no -dark values specified or contrast-checked now; chart palette and status colours must be verified on dark surfaces when built. AC: lint forbids raw colours in component CSS; token file structured so a second theme is additive. (D, Colors Dark theme readiness; E, Foundation)
- UX-DR-286: Appearance control hidden: the sidebar footer "Appearance" and the mockups' theme toggle and Profile theme setting are hidden/not rendered in the MVP; they appear only when a dark theme exists. AC: no theme toggle in DOM or tab order. (D, Layout; E, Foundation, Profile & settings)
- UX-DR-287: Token-to-library mapping: DESIGN.md tokens map to the UI library's CSS variables (shadcn/Radix-style headless primitives; one grid library; one chart library, final choice by architecture); no component-level hex; required behaviours: non-modal push drawer with labelled landmark, first-free-slot placement, keyboard move/resize, display-only narrow reflow, palette fixed order with series-1 stroke token, legends/direct labels/dash-marker variants, tabular numerals, escaped text, reduced motion, keyboard focus on data points, generated summary, data-table alternative, toasts with Undo and pause on hover/focus and polite live region, command palette, popover menus, accordions with full keyboard support. (E, Foundation UI system)
- UX-DR-288: Logo identity: 28px logo tile (accent default) with four logo-mark tiles inside, "Dashflow" wordmark; sign-in hero shows logo and "Dashboard Management System"; logo overridable via brand seam. (D, Colors; Components Sidebar, Sign-in hero)

### FR Coverage Map

FR-1: Epic 1 - The sign-in page offers a User ("Personal workspace") and Admin ("System management") choice, p
FR-2: Epic 1 - The sign-in page has email and password fields, a show/hide password control, Remember me and F
FR-3: Epic 1 - Users belong to one or more Workspaces; a switcher showing workspace name and a descriptive lab
FR-4: Epic 1 - Sign out and a profile menu (name, role) are available in both areas [MOCKUP].
FR-5: Epic 1 - All Admin pages and APIs require the Admin role, and permissions are enforced server-side.
FR-6: Epic 1 - In User configuration [MOCKUP], an Admin can invite and deactivate users, assign User or Admin,
FR-7: Epic 6 - An Admin can limit each Block and Dashboard Template to all users or to selected user groups; u
FR-8: Epic 2 - When an Endpoint must return user-specific data (e.g.
FR-9: Epic 2 - An Admin registers a Data Source with name, base URL, auth type (API key, bearer token, OAuth2 
FR-10: Epic 2 - All API calls are made server-side, and the base URL must match the Workspace's host allowlist 
FR-11: Epic 2 - In the block wizard the Admin selects a Data Source and enters a method and path (e.g.
FR-12: Epic 2 - The Admin can test an Endpoint, which shows status, latency and Sample Response.
FR-13: Epic 2 - Configured pagination (page, offset, cursor or link header) is followed up to the limits; calls
FR-14: Epic 3 - In step 1 (Configure Block) the Admin enters Block name*, Description* (helper: "Help users und
FR-15: Epic 3 - In step 2 (Data Source) the Admin selects a Data source and an API endpoint (FR-11), a Refresh 
FR-16: Epic 3 - The Map Data step contains the API Data Mapper (§4.5) and follows the loop Auto-map -> Review -
FR-17: Epic 3 - As part of step 1 the Admin sets display & behavior [MOCKUP]: Default width in grid columns; De
FR-18: Epic 3 - The live preview renders the Block with real Sample Response data, applies Slot Mapping and Pre
FR-19: Epic 3 - "Save draft" stores progress at any step; drafts appear under Draft blocks [MOCKUP]
FR-20: Epic 3 - "Publish block" publishes the Block directly to the Block Library only if the Admin holds the p
FR-21: Epic 3 - The Admin either fetches a sample (calls the Endpoint with current parameter values) or pastes 
FR-22: Epic 3 - For Block Types that need rows (charts, tables, lists, feeds, calendars) the Admin picks the Re
FR-23: Epic 3 - The Admin assigns each Slot a Field through Field Role auto-fill, a field picker with search, o
FR-24: Epic 5 - The Admin can add Transforms applied in this order: filter -> row-level calculated fields -> gr
FR-25: Epic 5 - Calculated Fields use a restricted expression language with arithmetic, comparisons, `IF`, safe
FR-26: Epic 5 - Any text Slot (including Title, Subtitle, labels, list subtitles, captions) can take a Text Tem
FR-27: Epic 5 - A comparison Slot (KPI card and KPI & Chart) is filled either (a) by a mapped previous value fr
FR-28: Epic 5 - A Calculated Field can be marked as a ratio with a declared numerator and denominator.
FR-29: Epic 3 - For every mapped Slot the Admin sets Presentation Rules.
FR-30: Epic 3 - Before saving, the mapping is validated for required Slots, types, aggregations and expressions
FR-31: Epic 5 - Normative mapping example: for `GET /api/v2/finance/revenue` returning `{data:{total:284680, pr
FR-32: Epic 5 - Every Block Type has common Slots Title, Subtitle, Icon and Footer action (label plus link or "
FR-33: Epic 5 - A developer can add a Block Type by supplying its slot schema, configuration schema, size limit
FR-34: Epic 4 - Each Block shows, as configured [MOCKUP]: icon, title and subtitle; refresh (if allowed); minim
FR-35: Epic 4 - When a Block's period behaviour is Own selector (FR-17) it shows a period dropdown (e.g.
FR-36: Epic 4 - Every Block has a visible presentation for each state: loading (skeleton); empty ("No data for 
FR-37: Epic 4 - API values are always rendered as text, escaped and never interpreted as HTML.
FR-38: Epic 6 - Admins have Draft blocks and Published blocks lists showing name, category, type, version, owne
FR-39: Epic 6 - When an Admin publishes a new version of an already-published Block or Template, Dashflow first
FR-40: Epic 6 - Only Admins holding the publish permission can publish, unpublish, restore and archive Blocks a
FR-41: Epic 6 - Every version is immutable and viewable.
FR-42: Epic 6 - When a new Block Version is published, every Block Instance shows it on its next refresh.
FR-43: Epic 6 - Admins create, rename, order and archive Block categories [MOCKUP].
FR-44: Epic 6 - Block management lists all Blocks with search and filters (category, type, status, owner) and t
FR-45: Epic 7 - An Admin arranges published Blocks into a Dashboard Template with name, description, default la
FR-46: Epic 7 - An Admin chooses which Template seeds every new user's Overview dashboard [PROPOSED].
FR-47: Epic 7 - Updating a Template affects only dashboards created after the update; existing user dashboards 
FR-48: Epic 7 - Admins see how many dashboards were created from each Template (feeds "Template adoption", FR-6
FR-49: Epic 4 - Each user has an Overview dashboard (seeded by FR-46) and may create more under My dashboards: 
FR-50: Epic 7 - Templates lists the Dashboard Templates the user may use, with a preview; "Use template" create
FR-51: Epic 4 - The dashboard switcher changes the current dashboard.
FR-52: Epic 4 - "+ Add block" opens the right-side Add-blocks Panel [MOCKUP]
FR-53: Epic 4 - "+ Add" places the Block automatically at the first suitable free grid position at its default 
FR-54: Epic 4 - In Edit layout mode, users drag Blocks to reorder them and resize them within each Block's allo
FR-55: Epic 4 - Users can refresh, minimize or expand Blocks, change a Block Period Selector, and open "About t
FR-56: Epic 4 - Dashboards, layouts, minimized states, period selections and Date Ranges are saved on the serve
FR-57: Epic 7 - A user can reset a dashboard created from a Template back to that Template's current layout.
FR-58: Epic 4 - Desktop shows the full grid; tablet a reduced number of columns; mobile a single column in the 
FR-59: Epic 4 - Adding, removing, reordering and resizing are possible without drag-and-drop through keyboard a
FR-60: Epic 4 - Each Block re-fetches on its Refresh Interval without a page reload.
FR-61: Epic 4 - A Block becomes Stale when its source has not been successfully checked within 2 x its Refresh 
FR-62: Epic 4 - Every Block shows its Data-as-of Time in "About this block", and inline when the data is Stale.
FR-63: Epic 8 - Global search ("Search anything", ⌘K) [MOCKUP]
FR-64: Epic 8 - The in-app notification bell [MOCKUP]
FR-65: Epic 8 - The Admin overview [MOCKUP]
FR-66: Epic 8 - A Platform health panel shows to the Workspace's Admins the status and uptime of shared platfor
FR-67: Epic 8 - Admins configure Workspace settings: the host allowlist (FR-10); audit and log retention period
FR-68: Epic 8 - Dashflow records immutable Audit Events for data source, endpoint, Block, category and Template

## Epic List

### Epic 1: Secure Workspaces and Access
People can sign in to isolated Workspaces, and Admins can invite users and control roles, permissions and groups. Includes the project foundation: starter kit, tenancy, audit and event foundations, CI.
**FRs covered:** FR-1, FR-2, FR-3, FR-4, FR-5, FR-6

### Epic 2: Connect Your APIs
Admins can register REST/JSON Data Sources and Endpoints, test them, bind per-user data safely and see source health, with outbound calls guarded against SSRF.
**FRs covered:** FR-8, FR-9, FR-10, FR-11, FR-12, FR-13

### Epic 3: Build and Publish Your First Block
Admins can build a KPI Block in the five-step wizard (sample, Auto-map, Review, Override, Preview), validate it against the real API and publish it.
**FRs covered:** FR-14, FR-15, FR-16, FR-17, FR-18, FR-19, FR-20, FR-21, FR-22, FR-23, FR-29, FR-30

### Epic 4: Live Personal Dashboards
Users can see published Blocks on personal dashboards with live, trustworthy data, and add, remove, move and resize them.
**FRs covered:** FR-34, FR-35, FR-36, FR-37, FR-49, FR-51, FR-52, FR-53, FR-54, FR-55, FR-56, FR-58, FR-59, FR-60, FR-61, FR-62

### Epic 5: Rich Blocks and Governed Calculations
Admins can shape data with governed transforms, calculated fields and comparisons, and publish all 11 Block Types; developers can add Block Types.
**FRs covered:** FR-24, FR-25, FR-26, FR-27, FR-28, FR-31, FR-32, FR-33

### Epic 6: Governed Publishing and Access
Admins can manage Block versions safely (impact, restore, propagation), organise Blocks into categories and control which groups see each Block.
**FRs covered:** FR-7, FR-38, FR-39, FR-40, FR-41, FR-42, FR-43, FR-44

### Epic 7: Templates and Onboarding
Admins can publish dashboard Templates with Mandatory Blocks, and users can start new dashboards from them.
**FRs covered:** FR-45, FR-46, FR-47, FR-48, FR-50, FR-57

### Epic 8: Find, Be Notified and Operate
Users can search and receive notifications; Admins can see Workspace activity and health, configure settings and retention, and search and export the audit log.
**FRs covered:** FR-63, FR-64, FR-65, FR-66, FR-67, FR-68

### Epic 9: Production-Ready Deployment
Operators can deploy Dashflow with Compose and Helm, scale it, back it up, recover it, rotate keys and load-test it, meeting the non-functional requirements.
**FRs covered:** NFR-1 to NFR-14 (non-functional); AR-45 to AR-57 (deployment, operations, CI/CD)

## Epic 1: Secure Workspaces and Access

People can sign in to isolated Workspaces, and Admins can invite users and control roles, permissions and groups. The epic also lays the project foundation: starter kit, CI, tenancy with row-level security, audit and outbox kernels, Docker Compose dev environment, observability baseline, Valkey split, design tokens, shared components and the message catalogue.

### Story 1.1: Initialise the project from the Laravel Vue starter kit

As a developer,
I want the repository created from the Laravel Vue starter kit with the required modifications,
So that every later story builds on the agreed stack with sign-in working from a fresh clone.

**Requirements:** AR-1, NFR-4, NFR-8

**Acceptance Criteria:**

**Given** a fresh clone of the repository
**When** a developer runs the documented install and build commands (Composer, Node 22, Vite 8)
**Then** the app builds without errors on PHP 8.5 with `ext-bcmath` and `ext-uv` required in `composer.json`
**And** the stack is Laravel 13, Inertia 3, Vue 3.5, TypeScript 5, Tailwind 4, shadcn-vue on reka-ui 2, Fortify, Wayfinder, Pest 5 (the kit's PHPUnit is removed).

**Given** the running app
**When** a request is made to the registration route
**Then** the response is HTTP 404
**And** no Fortify two-factor routes exist (route list contains none), and the Teams scaffold is absent.

**Given** a seeded test user
**When** the user submits valid email and password on the kit's sign-in page
**Then** the user is signed in and the session is established
**And** a wrong password is rejected with HTTP 422 and no session.

**Given** Sanctum installed via `php artisan install:api`
**When** a same-origin SPA request calls an `/api/v1` test route after sign-in
**Then** it is authenticated by the session cookie with CSRF protection (a request without `X-XSRF-TOKEN` is rejected with 419).

**Given** the dependency manifest
**When** it is inspected
**Then** it includes Horizon 5, Reverb 1, Pinia 4, vue-i18n 11, ECharts 6.1 with vue-echarts 8.3, opis/json-schema 2.6, symfony/json-path 8.1 (dev only), OTel PHP SDK 1.15 and auto-laravel 1.9
**And** `pest` runs green with at least one passing test.

### Story 1.2: Enforce architecture and quality gates in CI

As a developer,
I want CI to run formatting, static analysis, tests and architecture rules on every change,
So that module boundaries, tenancy rules and quality are enforced from the first commit.

**Requirements:** AR-3, AR-42, AR-51, AR-54, NFR-4, NFR-7, NFR-8, UX-DR-15, UX-DR-264, UX-DR-285

**Acceptance Criteria:**

**Given** a pull request
**When** the GitLab CI pipeline runs
**Then** it executes stages Pint, Larastan, Pest (unit, feature, architecture), Vitest, Playwright e2e and axe accessibility
**And** any failing stage blocks the merge.

**Given** the module layout `App\Modules\{Module}\{Contracts,Domain,Application,Infrastructure,Http}` and kernel `app/Platform/{Tenancy,Outbox,Audit,Operations,EditLock,Layout,Period}`
**When** a class adds a namespace edge not listed in `tests/Architecture/dependencies.php`
**Then** the Pest architecture test fails
**And** a kernel class that references a module fails the same test.

**Given** a module query using `DB::table()` or SQL that names another module's table
**When** the architecture test runs
**Then** the build fails naming the offending file.

**Given** a fixture migration that creates a table without `workspace_id` that is not in the global-table list (`users`, `sessions`, `password_reset_tokens`, `invitations`, `service_health_samples`, `operator_audit`, framework job tables)
**When** the migration guard test runs
**Then** CI fails.

**Given** the Playwright config
**When** e2e runs
**Then** it covers Chromium, Firefox, WebKit and mobile emulation with browserslist set to the latest two versions (NFR-8)
**And** a sample page is checked at desktop, tablet and mobile widths (NFR-9).

**Given** component CSS or Vue files
**When** the lint rule runs
**Then** raw hex or `rgba()` colours fail (UX-DR-285), and the replaced mockup values `#00D987`, `#FF004A`, `#FFDD1D` and `#CA8A04` fail (UX-DR-15).

**Given** the axe step
**When** it runs against every routed page
**Then** a WCAG 2.1 AA violation fails the pipeline (UX-DR-264).

**Given** the CI config
**When** it is inspected
**Then** it also runs the dependency and licence audit, and `json_decode` is banned in Ingestion, RawStore, Mapping and Results by an architecture test (no-op until those modules exist).

### Story 1.3: Run all five process roles locally with Docker Compose

As a developer,
I want one image started as five process roles with Postgres and Valkey in Docker Compose,
So that the whole platform runs locally and in demo installs the same way it will in production.

**Requirements:** AR-2, AR-29, AR-47, AR-48, AR-50, NFR-14

**Acceptance Criteria:**

**Given** the multi-stage Dockerfile (Composer, Vite on Node 22, PHP 8.5 with `bcmath`, `pgsql`, `redis`, `uv`, nginx plus php-fpm for web)
**When** the image is built
**Then** one image is produced and no role has its own image.

**Given** `docker compose up`
**When** startup completes
**Then** the roles `web`, `realtime` (Reverb), `scheduler`, `worker-connector` (Horizon queues `fetch-interactive`, `fetch-scheduled`) and `worker-compute` (queues `compute`, `outbox`, `notifications`, `maintenance`) run from that image by command/env
**And** PostgreSQL 18, Valkey 9 and a one-shot `migrator` job that runs before the roles start are present.

**Given** the `scheduler` role
**When** it runs
**Then** it executes `schedule:run` each minute with tasks marked `onOneServer()`, and a second scheduler instance does not run duplicate tasks.

**Given** each role
**When** its health endpoint or command is called
**Then** it returns healthy only when its own dependencies are reachable, and unhealthy with a reason when PostgreSQL is stopped.

**Given** all configuration
**When** the stack is started
**Then** every setting comes from environment variables and no cloud-provider service is required.

**Given** the Compose file
**When** key purposes are inspected
**Then** only the roles listed in AR-50 receive each key purpose (placeholder keyring mount per role; `realtime` and `scheduler` receive none).

### Story 1.4: Observe every request with OpenTelemetry and scrubbed logs

As an operator,
I want traces, metrics and JSON logs with correlation IDs and secret scrubbing,
So that I can trace a request across roles without leaking secrets.

**Requirements:** AR-31, AR-53, NFR-13, NFR-4

**Acceptance Criteria:**

**Given** an HTTP request without `X-Request-Id`
**When** it is handled
**Then** the response carries a generated `X-Request-Id`
**And** the same ID appears on every span and log line as `request_id`, along with `trace_id` and `workspace_id` where known.

**Given** a queued job dispatched from a request
**When** the worker runs it
**Then** its spans and logs carry the originating `request_id`.

**Given** logs are written
**When** inspected
**Then** they are JSON on stdout
**And** metric names follow `dashflow.<module>.<measure>`.

**Given** a request URL with a canary secret in the query string, fragment or a non-allowlisted header
**When** the scrubbing processor runs
**Then** none of the canary appears in traces, metrics or logs (CI test with canary secrets).

**Given** an OTLP collector configured by environment
**When** the stack starts
**Then** telemetry is exported over OTLP, and with no collector configured the app still runs and logs a single warning.

**Given** the error envelope `{error:{code, message, request_id, details}}`
**When** an error occurs for a User-area request
**Then** `details` is stripped (UX-DR-162 for Users: no stack traces or HTTP codes in copy).

### Story 1.5: Split Valkey into queue and cache stores with signed jobs

As an operator,
I want separate queue and cache stores and tamper-proof job payloads,
So that queue data is never evicted and a forged job is never executed.

**Requirements:** AR-24, AR-52, AR-57, NFR-3, NFR-4

**Acceptance Criteria:**

**Given** the Laravel config
**When** inspected
**Then** two Valkey connections exist: `queue` (queues, locks, rate limits, Reverb fan-out) with `noeviction`, and `cache` with LRU eviction where every key set has a TTL
**And** the session driver is the database driver.

**Given** the `cache` store
**When** a key is written without a TTL
**Then** the write is rejected by the cache wrapper in tests.

**Given** the Compose stack
**When** connections are opened
**Then** TLS and a per-role ACL user are used, and a role connecting with another role's credentials is refused.

**Given** a job is enqueued
**When** the worker picks it up
**Then** the payload carries IDs only and an HMAC signature verified before unserialize
**And** a payload altered in Valkey is rejected and logged as a security event, never executed (signed-job tamper test).

**Given** `config/dashflow.php`
**When** inspected
**Then** each tunable listed in AR-57 exists as a named env-driven setting flagged `pending_input` with no invented value (only `dispatch_tick` shows the proposed 5 s)
**And** a test fails if a listed setting is missing.

**Given** the database connection configuration
**When** inspected
**Then** an optional PgBouncer transaction-mode switch exists; the tenant-isolation check under pooling is added with row-level security in Story 1.10, not here.

### Story 1.6: Apply the Dashflow design tokens and brand seam

As a user,
I want a consistent light-theme visual system with accessible colours, type and spacing,
So that every screen is readable and consistent, and a Workspace brand can be swapped later without component changes.

**Requirements:** NFR-7, NFR-10, UX-DR-1, UX-DR-2, UX-DR-3, UX-DR-4, UX-DR-5, UX-DR-6, UX-DR-7, UX-DR-8, UX-DR-9, UX-DR-10, UX-DR-11, UX-DR-12, UX-DR-13, UX-DR-14, UX-DR-283, UX-DR-284, UX-DR-285, UX-DR-286, UX-DR-287, UX-DR-288

**Acceptance Criteria:**

**Given** the token file
**When** the light theme loads
**Then** every brand, system, status, hero, data-role and chart token from UX-DR-1..6 resolves as a CSS custom property
**And** the contrast ratios stated in UX-DR-1..6 and UX-DR-13 are verified by an automated test.

**Given** Inter with the specified fallback stack
**When** the typography scale UX-DR-7 is applied
**Then** every named style has the specified size, weight, line height and tracking
**And** figures use `font-variant-numeric: tabular-nums` and no body copy is below 12px.

**Given** the radius, spacing, sizing and elevation tokens (UX-DR-9, 10, 11)
**When** components are built
**Then** they reference tokens only; resting cards have a 1px border and no shadow.

**Given** keyboard focus on any interactive element
**When** it is focused
**Then** a 3px solid `focus-ring` (#2563EB) with 2px offset shows, never yellow (UX-DR-12)
**And** sticky layers set `scroll-padding` so a focused element is not hidden (UX-DR-268).

**Given** selected states (sidebar item, table row, role card, segmented control, chip, listbox option)
**When** rendered
**Then** each shows its non-colour cue (3px bar, border, check, radio) per UX-DR-14.

**Given** a test override layer (e.g. `data-workspace` scope) setting brand tokens and logo
**When** applied
**Then** brand tokens and logo change without touching component code; system tokens (UX-DR-2) do not change (UX-DR-283)
**And** `on-accent` switches between #111827 and #FFFFFF by contrast, and an `accent-ink` below 4.5:1 on white or on its accent-soft falls back to the default (unit tests, UX-DR-284).

**Given** the MVP build
**When** the DOM is inspected
**Then** there is no Appearance or theme toggle in the DOM or tab order (UX-DR-286)
**And** the token file is structured so a second theme is additive, with no `-dark` values defined (UX-DR-285).

**Given** the logo component
**When** rendered
**Then** it shows the 28px accent tile with four `logo-mark` tiles and the "Dashflow" wordmark, and follows the brand seam (UX-DR-288).

**Given** the token-to-library mapping
**When** shadcn-vue primitives render
**Then** their CSS variables are bound to the DESIGN.md tokens with no component-level hex (UX-DR-287).

### Story 1.7: Serve all product copy from the canonical message catalogue

As a developer,
I want one vue-i18n catalogue holding the canonical message keys,
So that every screen uses the same approved copy and tests catch drift.

**Requirements:** NFR-11, UX-DR-282, UX-DR-161, UX-DR-162

**Acceptance Criteria:**

**Given** the vue-i18n catalogue (default locale `en`)
**When** it is loaded
**Then** it contains exactly the 89 keys listed in UX-DR-282 with the "Do" text verbatim from EXPERIENCE.md
**And** a test fails on any missing key or extra key.

**Given** components render product copy
**When** the lint/test runs
**Then** no component contains a hard-coded duplicate of a Do or Don't string
**And** each Don't phrasing (for example "Invalid input.", "403 Forbidden", "No data.") is absent from rendered UI.

**Given** keys with announced variants (`toast-add`, `toast-add-below`, `toast-remove`, `session-warning` countdown, `stale`, `stale-aggregate`)
**When** the catalogue is read
**Then** each has its paired screen-reader key.

**Given** `msg:access-impact` with `{count}`
**When** rendered with count 38
**Then** the output reads "38 users will lose this block." with the count bold, interpolated from real data (never the example).

**Given** the copy audit
**When** it scans product UI strings
**Then** no RMG or domain terms appear, copy is sentence case, and no exclamation marks or emoji appear (UX-DR-161).

**Given** an Admin-facing error with details
**When** shown
**Then** a collapsed "Technical details" disclosure shows HTTP status, field path and request ID with a "Copy request ID" button; in the User area none of these appear (UX-DR-162).

**Given** all messages in this epic (`field-error`, `signin-role-denied`, `signin-failed`, `throttled`, `reset-requested`, `reset-expired`, `password-changed`, `session-warning`, `session-expired`, `perm-denied`, `workspace-role`, `offline-editing`, `save-failed`, `saved`, `unsaved-changes`, `list-empty`, `list-no-match`, `toast-rollback`, `signin-subtitle`, `page-subtitles`, `reconnecting`, `back-online`)
**When** later stories render them
**Then** they reference these keys only; the remaining keys are present now and consumed by later epics.

### Story 1.8: Build the shared form controls and status components

As a user,
I want accessible buttons, fields, chips and badges,
So that every form and list behaves the same and is operable by keyboard and screen reader.

**Requirements:** NFR-7, UX-DR-16, UX-DR-17, UX-DR-18, UX-DR-19, UX-DR-20, UX-DR-21, UX-DR-22, UX-DR-23, UX-DR-24, UX-DR-26, UX-DR-29, UX-DR-30, UX-DR-31, UX-DR-32, UX-DR-33, UX-DR-34, UX-DR-35, UX-DR-36, UX-DR-37, UX-DR-39, UX-DR-40, UX-DR-43, UX-DR-46, UX-DR-272, UX-DR-273, UX-DR-274, UX-DR-278, UX-DR-279, UX-DR-281

**Acceptance Criteria:**

**Given** the button variants primary, secondary, ghost, link, destructive-soft and destructive
**When** rendered in a component gallery
**Then** each matches UX-DR-16..21 (colours, 34px min height, radii, focus ring)
**And** a view with two primary buttons fails a lint/test.

**Given** a disabled control
**When** rendered
**Then** it uses `aria-disabled="true"`, stays focusable, has `aria-describedby` to an inline reason at full opacity, and activation does nothing except focus the first blocker and announce the reason (UX-DR-22, 272).

**Given** an input with a validation error
**When** blur or submit occurs
**Then** the border is `error`, the message shows `error-text` with a leading error icon and hidden "Error:", `aria-invalid` and `aria-describedby` are set, and validation does not run per keystroke
**And** required fields show an `aria-hidden` red `*` with `required`, and "* Required" appears once per form (UX-DR-23, 274).

**Given** a form with two or more errors
**When** submitted
**Then** an error summary of links is focused and each link focuses its field; with one error the first invalid field is focused (UX-DR-274).

**Given** textarea, select, secret field, segmented control, category chip, radio, checkbox and switch
**When** operated by keyboard
**Then** each follows its semantics: radiogroup arrows, switch Space with visible "On"/"Off" word, chip single tab stop with arrows, textarea vertical resize only (UX-DR-24, 26, 30..34).

**Given** a saved secret
**When** rendered
**Then** it shows "•••••••• · set <date> · Replace", reads as "Secret set on <date>", has no reveal, and "Replace" clears it and requires a new value
**And** screen-reader text never contains the secret (UX-DR-29).

**Given** a tooltip
**When** hovered or focused
**Then** it appears, Esc dismisses it without moving focus, it persists while the pointer is over it, and it never holds the only copy of information (UX-DR-35, 281).

**Given** a skeleton
**When** shown
**Then** shimmer stops after 5 s and a "Still loading…" caption appears; under `prefers-reduced-motion` it is static (UX-DR-36, 277).

**Given** tag, badge, badge-draft, badge-operational and lock badge
**When** rendered
**Then** none is interactive, each includes text, and the lock badge reads "Locked" or `msg:locked` and is announced as "required by your admin and cannot be removed" (UX-DR-37, 39, 40, 43, 46).

**Given** any status colour
**When** displayed
**Then** it is paired with an icon, arrow or word (UX-DR-273)
**And** pointer targets are at least 24px generally, 28px for chrome/chip/row actions and 44px on touch layouts (UX-DR-278)
**And** status dots and meters are `aria-hidden` with the word or number as value (UX-DR-279).

### Story 1.9: Build dialogs, toasts, banners, popovers and live regions

As a user,
I want consistent overlays, notices and announcements,
So that confirmations, errors and connection changes are clear and accessible.

**Requirements:** NFR-7, UX-DR-70, UX-DR-75, UX-DR-76, UX-DR-77, UX-DR-78, UX-DR-138, UX-DR-144, UX-DR-251, UX-DR-252, UX-DR-253, UX-DR-267, UX-DR-268, UX-DR-270, UX-DR-271, UX-DR-276, UX-DR-277, UX-DR-280, UX-DR-122

**Acceptance Criteria:**

**Given** a destructive confirmation dialog
**When** it opens
**Then** it has `role="alertdialog"`, `aria-labelledby` title, `aria-describedby` impact, initial focus on Cancel, Esc cancels, and the destructive button repeats the object name
**And** focus returns to the invoker (or its row if the invoker is gone) (UX-DR-271, 267).

**Given** a non-destructive dialog (unsaved changes)
**When** it opens with `msg:unsaved-changes`
**Then** it offers Save, Discard changes and Keep editing, and focus lands on Keep editing in generic forms (UX-DR-253)
**And** dialogs never stack more than one deep.

**Given** an action toast
**When** shown
**Then** it stays at least 10 s, pauses on hover, never auto-dismisses while focus is in it, and is announced politely
**And** an error or rollback toast uses `role="alert"`, an error icon, never auto-dismisses, and sits in a region named "Notifications" (UX-DR-70, 280, 276).

**Given** the overlay sheet on a mobile layout
**When** opened
**Then** it has a scrim at 45%, a visible 44px close button and a trapped modal dialog (UX-DR-77)
**And** a toast on mobile appears inside the sheet above its footer.

**Given** the popover surface
**When** a list is shown
**Then** keyboard-active options use `listbox-option-active` (2px inset ring) and the chosen option uses `listbox-option-selected` (leading check), distinct from each other (UX-DR-75, 144).

**Given** the connection drops while on a form
**When** the browser goes offline
**Then** the banner `msg:offline-editing` shows, editing continues, and Save, Fetch, Test and Publish are `aria-disabled` with that reason until reconnect (UX-DR-251, 138)
**And** on a generic Admin list offline the list is read-only with row actions disabled with that reason.

**Given** the dashboard shell
**When** the connection drops and returns
**Then** `msg:reconnecting` is announced once (`role="status"`), then `msg:back-online` once (UX-DR-252)
**And** all five banner variants of UX-DR-138 render per spec.

**Given** layout landmarks
**When** pages render
**Then** the skip link "Skip to content" is first on every page, the toast stack is a region "Notifications", and live-region policy (polite, once, assertive, never) of UX-DR-276 is enforced by a shared announcer
**And** under `prefers-reduced-motion` toasts appear without sliding and dialogs without animation (UX-DR-277).

**Given** a dismissible banner is shown (for example `map-small-screen`)
**When** the user activates its 28 px square "Dismiss" button
**Then** the banner is removed, focus returns to a sensible element, and the banner never blocks the step it appears on (UX-DR-122)

### Story 1.10: Isolate each Workspace with row-level security

As an operator,
I want every tenant table protected by row-level security and Workspace-scoped context,
So that a user in one Workspace can never read another Workspace's data.

**Requirements:** FR-3, NFR-4, AR-4, AR-5, AR-14, AR-54, AD-3

**Acceptance Criteria:**

**Given** the migration creating `workspaces` (UUIDv7 key, name, label, status) and `workspace_memberships` (role `user|admin`, status, `last_active_at`)
**When** it runs as the `migrator` role
**Then** `workspace_memberships` has non-null `workspace_id`, `ENABLE` and `FORCE ROW LEVEL SECURITY` and a policy `workspace_id = current_setting('app.workspace_id', true)::uuid`
**And** `workspaces` and `users` are handled as global tables per AR-4 (workspace lookup only through the Access-owned `SECURITY DEFINER` function).

**Given** the DB roles `app` (no BYPASSRLS), `migrator`, `maintenance` (only role with DELETE), `system` and `operator`
**When** the CI role test runs
**Then** `app` cannot bypass RLS and cannot DELETE on tenant tables.

**Given** a query as role `app` with no tenant context set
**When** it selects from a tenant table
**Then** it returns zero rows (CI test)
**And** with context set to Workspace A it never returns Workspace B rows (cross-tenant leak test per tenant table).

**Given** the `WorkspaceTransaction` middleware (HTTP and job)
**When** a request or job runs
**Then** it is the only opener of the transaction and the only caller of `set_config(..., true)`
**And** a job carrying `workspace_id` re-enters tenant context and re-reads IDs under RLS; a mismatch is a hard failure plus a security event.

**Given** cache keys, locks, queue payloads, object keys and channel names
**When** built
**Then** each is prefixed with the workspace ID, and a cache hit whose stored `workspace_id` differs is discarded.

**Given** PgBouncer in transaction mode
**When** the CI test runs two interleaved requests for Workspaces A and B
**Then** each sees only its own rows (context is transaction-scoped via `set_config(..., true)`).

**Given** a model uses `HasUuids`
**When** a row is created
**Then** its key is UUIDv7 generated in the app and no sequential ID is exposed.

### Story 1.11: Record audit events and publish outbox events in the same transaction

As an Admin,
I want every sensitive change and denial recorded as an audit event,
So that actions are traceable (the audit log screen arrives in Epic 8).

**Requirements:** FR-5, NFR-4, AR-25, AR-36, AD-18, AD-29

**Acceptance Criteria:**

**Given** the tables `audit_events` and `outbox_events` (tenant tables with RLS) and `outbox_consumptions`
**When** a command calls `Audit::record` and `Outbox::emit`
**Then** both rows commit in the same transaction as the domain change, and a rollback removes both
**And** the envelope contains `{event_id, type, v, workspace_id, subject, subject_seq, occurred_at, actor, request_id, data}` with `data` holding IDs and enums only.

**Given** an action string
**When** it is recorded
**Then** it must be a case of the closed `AuditAction` enum using the grammar `{module}.{noun}.{past_verb}`; an unknown string throws
**And** each module registers an allowlist `AuditSerializer`, and attribute or header values are stored as hashes only.

**Given** `Audit::recordSecurityEvent` (denials, sign-in outcomes)
**When** called inside a transaction that later rolls back
**Then** the security event is still persisted (autonomous transaction).

**Given** role `app`
**When** it tries UPDATE or DELETE on `audit_events`
**Then** the statement is refused; INSERT and SELECT succeed.

**Given** the outbox relay running as role `system` with `SKIP LOCKED` on the `outbox` queue
**When** events are pending
**Then** each is delivered once and `sent_at` is set; a consumer that has recorded `(consumer, event_id)` ignores a redelivery and drops events older than the subject's last applied `subject_seq`.

**Given** the error-code convention
**When** the build runs
**Then** codes from `Contracts/ErrorCode.php` are generated into a TypeScript enum, each starting with the owning module name.

### Story 1.12: Provision a Workspace and let its first Admin accept an invitation

As a platform operator,
I want to create a Workspace and invite its first Admin outside the Workspace Admin UI,
So that a new customer can start managing their own Workspace.

**Requirements:** FR-3, FR-2, FR-6, NFR-4, AR-38, AD-31, UX-DR-23

**Acceptance Criteria:**

**Given** an operator runs the CLI command to create a Workspace with a name, label and first-Admin email
**When** it completes
**Then** a Workspace exists and a single-use invitation is emailed to that address, stored hashed and bound to the email (table `invitations`, global)
**And** the action is written to `operator_audit` and mirrored into the Workspace audit log.

**Given** a valid invitation link
**When** the invitee opens it and sets a name and password
**Then** a `users` row (or an existing user for a known email) gets a membership with role `admin` and `users.manage` plus all Admin permissions in that Workspace
**And** audit `identity.invitation.accepted` is recorded and the invitation cannot be used again.

**Given** an invitation that is used, expired or tampered with
**When** opened
**Then** the response is non-enumerating, shows `msg:reset-expired`-style copy for invitations ("This link has expired or was already used"), and records a security event
**And** the invitation lifetime comes from a `pending_input` setting.

**Given** the invitation email address already belongs to an existing user
**When** the invitation is accepted
**Then** the user is added as a member of the new Workspace without changing their password (global identity fields stay self-service or operator-only).

**Given** the Workspace Admin UI
**When** inspected
**Then** there is no screen or API to create a Workspace, and calling any such route returns 404.

**Given** an invalid password on the accept form
**When** submitted
**Then** field errors show with `msg:field-error` wording and focus moves per UX-DR-274.

### Story 1.13: Sign in as User or Admin

As a person with an account,
I want to sign in choosing User or Admin,
So that I land on my personal workspace or on system management.

**Requirements:** FR-1, FR-2, NFR-4, AR-38, AD-31, UX-DR-4, UX-DR-23, UX-DR-32, UX-DR-33, UX-DR-90, UX-DR-91, UX-DR-159, UX-DR-274, UX-DR-282, UX-DR-158, UX-DR-212

**Acceptance Criteria:**

**Given** the sign-in page
**When** it loads
**Then** a hero (about 55% width) shows the logo, "Dashboard Management System", headline, tagline, two trust lines, illustration and the footer line per UX-DR-90
**And** the form shows `msg:signin-subtitle`, two role cards "User · Personal workspace" and "Admin · System management" as a radiogroup with visible radios, email, password, show/hide password, Remember me and Forgot password
**And** choosing a card relabels the single primary button "Sign in as User" or "Sign in as Admin".

**Given** valid credentials and role User
**When** the form is submitted
**Then** the session ID rotates, the active Workspace and area User are stored in the session, and the person lands on the User Overview route
**And** `identity.signin.succeeded` is recorded as a security event.

**Given** valid credentials, role Admin selected, and the person holds the Admin role in their Workspace
**When** submitted
**Then** the person lands on the Admin overview route with area Admin in the session.

**Given** valid credentials, role Admin selected, and the person does not hold the Admin role
**When** submitted
**Then** `msg:signin-role-denied` is shown, the User card is offered and no Admin area session is created
**And** `identity.area.denied` is recorded.

**Given** wrong email or password
**When** submitted
**Then** `msg:signin-failed` is shown identically for unknown and known emails (non-enumerating), with HTTP 422 and `identity.signin.failed` recorded.

**Given** repeated failed attempts above the throttle limit
**When** another attempt is made
**Then** HTTP 429 with `msg:throttled` is returned and `identity.signin.throttled` is recorded
**And** the limits come from `pending_input` settings.

**Given** Remember me is ticked
**When** the session is created
**Then** its lifetime is extended up to the Remember-me maximum from a `pending_input` setting
**And** "Contact your workspace administrator" help text is shown (linking to Help & support) and SSO is not offered.

**Given** a user belonging to several Workspaces signing in
**When** sign-in succeeds
**Then** the active Workspace is the last used one (or the first by name for a new user).

**Given** keyboard-only use and a screen reader
**When** the page is operated
**Then** fields have labels, `autocomplete` attributes (`username`, `current-password`), the checkbox and radios use the shared components, and an error summary receives focus on failed submit
**And** at 320 CSS px the hero stacks or hides and no horizontal scroll appears.

**Given** the routing for UX-DR-159
**When** the router is inspected
**Then** routes exist for Sign in, Forgot password and Reset password.

**Given** the sign-in page is opened on a viewport wider than about 1024 px
**When** it renders
**Then** it shows a split screen with the dark hero at about 55% on the left and the white form on the right, and below about 1024 px the hero is hidden and the form is centred with the logo above (UX-DR-158)
**And** the form has the User and Admin role cards as a radiogroup, email and password inputs with leading icons and a show/hide eye, a Remember me checkbox, a "Forgot password?" link and a full-width primary "Sign in as <role>" button (UX-DR-212)

### Story 1.14: Reset a forgotten password

As a person who forgot their password,
I want a time-limited reset link,
So that I can regain access securely.

**Requirements:** FR-2, NFR-4, AR-38, UX-DR-23, UX-DR-159, UX-DR-282

**Acceptance Criteria:**

**Given** the Forgot password page
**When** any well-formed email is submitted
**Then** `msg:reset-requested` is shown whether or not an account exists, and a reset email is sent only if it does
**And** requests are throttled and over-limit attempts return 429 with `msg:throttled`.

**Given** a reset link
**When** opened within its lifetime (a `pending_input` setting)
**Then** the Reset password page accepts a new password and confirmation
**And** on success all other sessions for that user are invalidated, `msg:password-changed` shows on the sign-in page and `identity.password.reset` is recorded.

**Given** a reset link that is expired or already used
**When** opened or submitted
**Then** `msg:reset-expired` is shown with a link to request a new one, and no password changes.

**Given** a malformed email
**When** the field is blurred
**Then** `msg:field-error` ("Enter an email address, like name@company.com.") shows inline.

**Given** a mail failure
**When** the reset is requested
**Then** the user still sees `msg:reset-requested` and the failure is logged without the email address in the logs.

### Story 1.15: Warn before session expiry and keep forms safe

As a signed-in user,
I want a warning before my session ends and a clear way back,
So that I do not lose work or get silently signed out.

**Requirements:** FR-2, NFR-4, AR-27, UX-DR-248, UX-DR-249, UX-DR-253, UX-DR-282

**Acceptance Criteria:**

**Given** a session with an idle timeout from a `pending_input` setting
**When** 2 minutes remain
**Then** a dialog shows `msg:session-warning` with a live countdown, "Stay signed in" (primary, initial focus) and "Sign out"
**And** the countdown is announced at 2:00, 1:00 and 0:30.

**Given** the warning is shown
**When** the user chooses "Stay signed in"
**Then** `POST` to the session-extend endpoint extends the session, the dialog closes and focus returns to the previously focused element.

**Given** `X-Background: 1` API calls (polling, refresh)
**When** they run
**Then** they never extend the idle session; only user-initiated calls do (test).

**Given** the user does nothing
**When** the session expires
**Then** the next request returns 401, the user is redirected to sign-in and, after signing back in, lands on the page they were on with `msg:session-expired`
**And** on non-wizard forms (data source form, template editor, settings) unsaved fields are not restored.

**Given** a form with unsaved changes and expiry
**When** the user signs back in
**Then** the page-level form-draft hook (restore contract) is invoked if registered; the Create-block wizard's own autosave and restore is out of scope here and delivered in Epic 3
**And** unsaved secrets are never autosaved.

**Given** the session status endpoint `/api/v1/session`
**When** called
**Then** it returns remaining seconds and area without extending the session.

### Story 1.16: Navigate the two-area shell with sign out and profile menu

As a signed-in person,
I want a sidebar, top bar and profile menu in both areas,
So that I can move around, see my role and sign out.

**Requirements:** FR-4, FR-1, NFR-7, NFR-9, UX-DR-9, UX-DR-10, UX-DR-79, UX-DR-80, UX-DR-81, UX-DR-83, UX-DR-84, UX-DR-85, UX-DR-86, UX-DR-159, UX-DR-160, UX-DR-161, UX-DR-263, UX-DR-266, UX-DR-267, UX-DR-270, UX-DR-275, UX-DR-286, UX-DR-121

**Acceptance Criteria:**

**Given** a signed-in User in the User area
**When** the shell renders
**Then** the sidebar (232px) shows section label "WORKSPACE" and items Overview, My dashboards, Templates, Profile & settings, Help & support; each item routes to a placeholder page with title, `msg:page-subtitles` and a generic empty state
**And** the active item has the accent-soft fill plus 3px leading bar.

**Given** a signed-in Admin in the Admin area
**When** the shell renders
**Then** the sidebar shows "ADMINISTRATION" items Admin overview, Block management, Create block, Draft blocks, Published blocks, Block categories, Dashboard templates, Data sources, User configuration, System settings, Audit log
**And** items for which the Admin lacks the permission are shown as disabled with the reason, not hidden silently (reason is `msg:perm-denied`).

**Given** the sidebar footer
**When** inspected
**Then** it contains Help & support, Sign out and a user row (circle avatar, name, role, ⋯), and Appearance is not rendered (UX-DR-286).

**Given** the user selects Sign out in either area
**When** confirmed by the action
**Then** the session is destroyed, the session ID is rotated before destroy, the user lands on the sign-in page and `identity.signout.completed` is recorded.

**Given** the profile menu
**When** opened from the user row
**Then** it shows name and role for the active Workspace and links to Profile & settings and Sign out
**And** Esc closes it and focus returns to the user row (UX-DR-267).

**Given** a viewport below the reflow breakpoint (640px) and a tablet width
**When** the shell renders
**Then** the sidebar collapses to the 64px icon rail (tablet) with tooltips on focus and hover, or to an overlay sheet (mobile)
**And** at 320 CSS px there is no horizontal page scroll (UX-DR-275).

**Given** the top bar
**When** inspected
**Then** it is a `banner` landmark 64px tall with the settings gear (tooltip "Settings") opening Profile & settings in User area and System settings in Admin area, breadcrumb with the back button where applicable, and the page header per UX-DR-86
**And** the notifications bell and ⌘K search are present as disabled-with-reason placeholders delivered in Epic 8.

**Given** landmarks
**When** inspected
**Then** `navigation`, `main` (labelled with the page name) and the "Skip to content" link exist on every page (UX-DR-270).

**Given** every list-bearing page
**When** it loads, fails, or is empty
**Then** the generic states of UX-DR-263 apply (5 skeleton rows, "We couldn't load {items}. Try again." with Retry, `msg:list-empty`).

**Given** any page of either area is loaded
**When** a keyboard user presses Tab once
**Then** a "Skip to content" skip link (surface-inverse background, hidden until focused) becomes visible at the top left above every sticky layer and moves focus to the main region when activated (UX-DR-121)

### Story 1.17: Switch the active Workspace

As a user in more than one Workspace,
I want to switch the active Workspace,
So that I only ever see one Workspace's data at a time.

**Requirements:** FR-3, FR-1, NFR-4, AR-5, AR-6, AR-38, UX-DR-9, UX-DR-81, UX-DR-82, UX-DR-141, UX-DR-168, UX-DR-282

**Acceptance Criteria:**

**Given** the sidebar switcher card
**When** it renders
**Then** it shows a 30px rounded-square avatar tile with initials, the Workspace name and its descriptive label (cosmetic), and a › chevron; in the icon rail it shows the avatar tile only.

**Given** the switcher is opened
**When** the popover renders
**Then** rows show avatar tile, name, label and the user's role tag, with a check on the current Workspace
**And** a search field appears only when the user has more than 7 Workspaces.

**Given** the user selects another Workspace where they hold the same role as the current area
**When** the switch completes
**Then** the active Workspace in the session changes, the session ID rotates, the area is kept and all lists, search and notifications are scoped to the new Workspace.

**Given** the user selects a Workspace where they are only a User while in the Admin area
**When** the switch completes
**Then** the user lands on the User Overview with `msg:workspace-role` (for example "You're a User in Acme Ltd.")
**And** area becomes User.

**Given** unsaved form work
**When** a switch is chosen
**Then** `msg:unsaved-changes` offers Save, Discard changes and Keep editing before the switch.

**Given** a user is requesting data with a forged or stale Workspace ID, or a membership that is deactivated
**When** the request is made
**Then** the response is 403 with `access.workspace_forbidden`, a security event is recorded and no data is returned
**And** a user in Workspace A requesting any object ID from Workspace B gets 404 (cross-tenant test).

**Given** the switch
**When** audited
**Then** `identity.workspace.switched` is recorded in the new Workspace's audit log.

### Story 1.18: Edit my profile and settings, and open Help & support

As a user,
I want to edit my name, avatar, password, locale and time zone and find help,
So that the app suits me and I know who to contact.

**Requirements:** FR-4, NFR-11, NFR-4, AR-38, UX-DR-23, UX-DR-169, UX-DR-263, UX-DR-269, UX-DR-286, UX-DR-262

**Acceptance Criteria:**

**Given** Profile & settings
**When** the page loads
**Then** a form shows name, avatar upload, locale, time zone, a "Change password" section and the Keyboard shortcuts switch (default On)
**And** no theme or Appearance setting is rendered.

**Given** a valid change to name, locale or time zone
**When** saved
**Then** `msg:saved` is announced politely, focus stays on Save, and the new locale and time zone apply to number, currency and date formatting for this user.

**Given** a password change
**When** the user submits current password, new password and confirmation
**Then** the current password is required (`password.confirm`), other sessions are invalidated, and `identity.password.changed` is recorded
**And** a wrong current password shows a field error and changes nothing.

**Given** an invalid avatar (wrong type or oversize)
**When** uploaded
**Then** the upload is refused with a field-level error and the existing avatar stays.

**Given** the save fails
**When** the response is an error
**Then** `msg:save-failed` (form wording) shows, values are kept and focus moves to the message.

**Given** the Keyboard shortcuts switch is turned Off
**When** saved
**Then** single-key shortcuts are disabled for this user while modifier shortcuts and widget keys still work (UX-DR-269).

**Given** Help & support
**When** opened
**Then** it lists help links configured by the Workspace Admin (an empty list shows `msg:list-empty`) and a "Contact your workspace administrator" link
**And** the Admin-side editing of those links is delivered with System settings in Epic 8.

### Story 1.19: Enforce Admin-only access with area and permission checks

As an Admin,
I want Admin pages and APIs to require the Admin role and the right permission on the server,
So that nobody can reach management functions by guessing URLs.

**Requirements:** FR-5, FR-3, NFR-4, AR-6, AR-38, AR-54, UX-DR-255, UX-DR-272, UX-DR-282

**Acceptance Criteria:**

**Given** the permission catalogue `data_sources.manage`, `blocks.edit`, `blocks.publish`, `templates.manage`, `users.manage`, `settings.manage`, `audit.view`, `data.preview_as_user`, `access.manage` stored in `membership_permissions`
**When** the migration runs
**Then** the table has `workspace_id` with RLS and the catalogue is a closed enum.

**Given** a User-role session (or area User)
**When** an Admin route or `/api/admin` endpoint is requested
**Then** the response is 403 `access.not_authorized` with `msg:perm-denied` for pages (page content not rendered) and the error envelope for APIs
**And** the denial is recorded via `Audit::recordSecurityEvent` as `access.admin.denied` with route and actor.

**Given** an Admin session lacking the required permission key
**When** the page or API is requested
**Then** the same denial occurs and is audited, and the page shows `msg:perm-denied`.

**Given** an Admin with the permission but area=User in the session
**When** an Admin endpoint is called
**Then** it is refused (area AND permission are both required).

**Given** a missing `X-XSRF-TOKEN` on a state-changing Admin request
**When** called
**Then** it is rejected with 419 before any authorization logic runs.

**Given** an action the person cannot do (for example Publish without `blocks.publish`)
**When** rendered
**Then** it is `aria-disabled` with `msg:perm-publish` inline, and draft actions remain enabled.

**Given** the permission store
**When** a decision is made
**Then** it is evaluated each request and never cached beyond the request (test: revoking a permission denies the next request).

**Given** the authorization test matrix
**When** CI runs
**Then** every Admin route in the router is covered by a test for User, Admin-without-permission and Admin-with-permission.

### Story 1.20: List the Workspace's users in User configuration

As an Admin,
I want a User configuration list showing members, roles, permissions and groups,
So that I can see who has access to my Workspace.

**Requirements:** FR-6, FR-5, NFR-4, UX-DR-37, UX-DR-261, UX-DR-263, UX-DR-279, UX-DR-282

**Acceptance Criteria:**

**Given** an Admin with `users.manage`
**When** User configuration opens
**Then** a `data-table` lists members of the active Workspace only: name, email, role, status (Active, Invited, Deactivated), groups and last active
**And** it is a real table with sortable header buttons carrying `aria-sort`.

**Given** an Admin without `users.manage`
**When** the page or `GET /api/admin/members` is requested
**Then** the response is 403 and `msg:perm-denied` shows; the attempt is audited.

**Given** a search term
**When** it matches nothing
**Then** `msg:list-no-match` shows with Clear search, and the count "0 of n" is announced politely.

**Given** a Workspace with only the first Admin
**When** the list renders
**Then** it shows that Admin and a primary "Invite user" action; with no members at all `msg:list-empty` shows.

**Given** the list is loading or fails
**When** the request runs
**Then** toolbar plus 5 skeleton rows show, and on failure "We couldn't load users. Try again." with Retry shows.

**Given** members of another Workspace
**When** queried by ID
**Then** the response is 404 (RLS).

**Given** page size over a pending_input limit
**When** paginated
**Then** the list pages by cursor and announces page changes politely.

### Story 1.21: Invite users to the Workspace

As an Admin,
I want to invite people by email with a role, permissions and groups,
So that they can join my Workspace without public registration.

**Requirements:** FR-6, NFR-4, AR-38, AD-31, UX-DR-23, UX-DR-143, UX-DR-282

**Acceptance Criteria:**

**Given** an Admin with `users.manage`
**When** they submit an invitation with email, role User or Admin, and (for Admin) permissions
**Then** a single-use hashed invitation bound to that email is created and emailed, the list shows status "Invited", and `access.membership.invited` is audited with an `access.membership.changed` outbox event
**And** no Workspace Admin permission is required beyond `users.manage`.

**Given** the inviter selects permissions
**When** the form renders
**Then** only permissions the inviter holds are offered; a crafted request with others returns 403 `access.permission_not_held`.

**Given** a password re-confirmation requirement for permission grants (`password.confirm`)
**When** an Admin invites with any Admin permission
**Then** the Admin is prompted for their password first; a wrong password blocks the invite.

**Given** the role menu
**When** opened
**Then** each item shows a role chip and a one-line description (UX-DR-143).

**Given** an invalid or duplicate email (already a member or already invited)
**When** submitted
**Then** a field error shows and nothing is created; for a duplicate pending invitation the Admin may resend, which replaces the earlier token.

**Given** the invitee accepts
**When** the link is opened
**Then** the flow of Story 1.12 applies, the membership becomes Active with the invited role, permissions and groups
**And** an invitation can be revoked by the Admin, after which the link returns the expired response.

**Given** an email delivery failure
**When** the invite is sent
**Then** the Admin sees `msg:save-failed` form wording with a Retry and the invitation stays "Invited" with a "Resend" action.

### Story 1.22: Assign roles and Admin permissions

As an Admin,
I want to assign User or Admin and grant Admin permissions including publish,
So that people have exactly the access they need.

**Requirements:** FR-6, FR-5, NFR-4, AR-6, AR-38, AD-31, UX-DR-143, UX-DR-254, UX-DR-255, UX-DR-271, UX-DR-282

**Acceptance Criteria:**

**Given** an Admin with `users.manage`
**When** they change a member's role between User and Admin and save
**Then** the change commits with audit `access.role.changed` (before and after) and outbox `access.role.changed`
**And** the member's next request reflects the new role (no cached decision).

**Given** the permissions editor on an Admin member
**When** the Admin grants `blocks.publish`, `access.manage` or any other catalogue permission
**Then** it is granted only if the granter holds it, after password re-confirmation, and audited as `access.permission.changed`
**And** a User-role member has no permissions and the editor is disabled with a reason.

**Given** a member is the last holder of `users.manage`
**When** the Admin attempts to remove the permission, downgrade or deactivate that member
**Then** the request is refused with 409 `access.last_users_manage_holder` and an inline explanation
**And** nothing changes.

**Given** an Admin editing their own row
**When** they try to change their own permissions or role
**Then** it is refused (nobody edits their own permissions) with 403 `access.self_change_forbidden`.

**Given** a destructive downgrade (Admin to User)
**When** the Admin confirms
**Then** an alertdialog names the member and impact with initial focus on Cancel (UX-DR-271), and the member's active Admin-area sessions are re-authorized on the next request.

**Given** a concurrent edit by another Admin
**When** the stale `revision` is submitted
**Then** 409 with current state returns and the form shows `msg:save-failed` form wording with the latest values.

**Given** a member who has lost `blocks.publish`
**When** they open a Publish action in a later epic
**Then** it follows UX-DR-255; here the test asserts the permission lookup returns false and `msg:perm-publish` is the reason text.

### Story 1.23: Organise users into groups

As an Admin,
I want to create user groups and assign members,
So that access can later be granted by group.

**Requirements:** FR-6, NFR-4, AR-6, AR-7, UX-DR-261, UX-DR-263, UX-DR-282

**Acceptance Criteria:**

**Given** an Admin with `users.manage`
**When** they create a group with a unique name within the Workspace
**Then** the group exists (tables `user_groups`, `group_members` with RLS), and `access.group.changed` is audited and emitted
**And** a duplicate name returns 422 with a field error.

**Given** a group
**When** the Admin adds or removes members (from the group or from the member's row)
**Then** membership changes apply on the next request, are audited with member and group IDs, and the list shows the groups per member.

**Given** a group is renamed or deleted
**When** confirmed (deletion in an alertdialog stating the member count)
**Then** it is audited; a group referenced by an access grant (introduced in Epic 6) cannot be deleted without a warning, and in this epic deletion removes memberships only.

**Given** a user belonging to Workspace B
**When** an Admin of Workspace A tries to add them by ID
**Then** the response is 404 (RLS).

**Given** no groups
**When** the Groups view loads
**Then** `msg:list-empty` shows with a "Create group" action
**And** search with no match shows `msg:list-no-match`.

**Given** a deactivated member
**When** groups are shown
**Then** their membership is retained and displayed as "Deactivated".

**Given** the Admin lacks `users.manage`
**When** any group API is called
**Then** 403 `access.not_authorized` is returned and audited.

### Story 1.24: Deactivate and reactivate users

As an Admin,
I want to deactivate a user and restore them later,
So that former staff lose access immediately without deleting history.

**Requirements:** FR-6, FR-3, NFR-4, AR-6, AR-38, UX-DR-263, UX-DR-271, UX-DR-282

**Acceptance Criteria:**

**Given** an Admin with `users.manage`
**When** they deactivate a member and confirm in an alertdialog naming the member
**Then** the membership status becomes Deactivated, all that member's sessions for this Workspace are revoked, and `access.membership.deactivated` is audited with an outbox event
**And** the member's next request returns 401/403 and sign-in into this Workspace is refused with `msg:signin-failed` wording.

**Given** a deactivated member also belongs to another Workspace
**When** they sign in
**Then** they can still use the other Workspace; the deactivated one is not offered in the switcher.

**Given** the member is the last `users.manage` holder, or is the acting Admin
**When** deactivation is attempted
**Then** it is refused with 409 `access.last_users_manage_holder` or 403 `access.self_change_forbidden`.

**Given** a deactivated member
**When** an Admin reactivates them
**Then** the membership returns to Active with its previous role, permissions and groups, audited as `access.membership.reactivated`.

**Given** a pending invitation
**When** the Admin deactivates (revokes) it
**Then** the invitation is invalidated and a later use shows the expired response.

**Given** the list refresh after a save
**When** the action succeeds
**Then** the affected row is highlighted (accent-soft, leading bar), focused, and `msg:saved` is announced
**And** if the save fails the row rolls back and an error toast `msg:toast-rollback` is shown, not auto-dismissed.

**Given** data owned by a deactivated user
**When** Epics 3 and later read it
**Then** it is retained; this story only changes membership status and does not delete data.

### Story 1.25: Verify security and load readiness with test suites and a load-test skeleton

As a developer,
I want the security test suite and a load-test harness skeleton in the repo,
So that tenancy and auth guarantees are continuously verified and capacity work can start later.

**Requirements:** NFR-4, NFR-3, NFR-7, AR-54, AR-55, AR-56, UX-DR-264, UX-DR-275

**Acceptance Criteria:**

**Given** the CI Pest security suite
**When** it runs
**Then** it includes cross-tenant RLS leak tests for every tenant table existing so far, CSRF and session-rotation tests (sign-in, area change, workspace switch), invitation single-use and cap tests, the last-`users.manage`-holder test, and the signed-job tamper test
**And** a new tenant table without a leak test fails the build.

**Given** the load-test harness skeleton
**When** run against the Compose stack
**Then** it executes a scripted sign-in and authenticated page flow at a configurable concurrency and reports request rate and p95
**And** every target number comes from `pending_input` settings, none are hard-coded.

**Given** the harness
**When** inspected
**Then** it has placeholder scenarios for dashboard results fan-in, sync dispatch with hot and cold keys, per-workspace budgets and Reverb fan-out, marked "not implemented" and skipped without failing CI.

**Given** an accessibility pass
**When** axe and the manual keyboard checklist run on Sign in, Reset password, Profile & settings and User configuration
**Then** no WCAG 2.1 AA violation is reported, focus order is logical, and the page reflows at 320 CSS px without horizontal scroll.

**Given** a backup/restore expectation (AR-56)
**When** the foundation is reviewed
**Then** a note in the repo records that the full restore drill is delivered in Epic 9 and this epic only verifies RLS and role setup after a fresh database restore in CI.

## Epic 2: Connect Your APIs

Admins can register REST/JSON Data Sources and Endpoints, test them, bind per-user data safely and see source health, with outbound calls guarded against SSRF. Dashboards never wait on source APIs: only `worker-connector` calls them, and the latest good response is kept as last-known-good.

### Story 2.1: Manage the Workspace host allowlist

As an Admin with `settings.manage`,
I want to maintain the list of hosts my Workspace may call,
So that Dashflow only ever contacts APIs I have approved.

**Requirements:** FR-10, FR-67 (allowlist part), NFR-4, AR-8, AR-25, UX-DR-115, UX-DR-262, UX-DR-263, UX-DR-23, UX-DR-282

**Acceptance Criteria:**

**Given** I am an Admin in the Admin area with `settings.manage`
**When** I open System settings > Host allowlist
**Then** a `data-table` lists entries (host, scheme, port, added by, added on) with a primary "+ Add host" action
**And** a cold load shows five skeleton rows, a load failure shows "We couldn't load {items}. Try again." with Retry, and an empty list shows `msg:list-empty` with "+ Add host" (UX-DR-263)

**Given** I enter `api.example.com` (or `api.example.com:8443`) and save
**When** the entry is valid
**Then** a `host_allowlist_entries` row is created with non-null `workspace_id` under RLS, the row is highlighted and focused, and `msg:saved` is announced
**And** an `connector.host_allowlist_entry.created` Audit Event is committed in the same transaction with actor, host and request ID

**Given** I enter a value containing a scheme path, wildcard-only entry (`*`), IP literal in a blocked class, whitespace or an empty string
**When** I save
**Then** the server answers 422 with an inline error on the field (message with leading error icon, `aria-invalid`, `aria-describedby`), focus moves to the first invalid field, and nothing is written

**Given** an entry already exists for the same host and port
**When** I add it again
**Then** the request is rejected as a duplicate with an inline message and no second row is created

**Given** I remove an entry that Data Sources currently use
**When** I confirm the `alertdialog` (initial focus on Cancel, destructive button repeats the host name)
**Then** the entry is deleted, `connector.host_allowlist_entry.removed` is audited, and the dialog lists the Data Sources that will be blocked on their next call

**Given** I lack `settings.manage` or I am in the User area
**When** I request the allowlist API or page
**Then** the API returns 403 `perm-denied` (message `msg:perm-denied`), the denial is recorded via `Audit::recordSecurityEvent`, and no entries are returned

**Given** a user in another Workspace
**When** they list host allowlist entries
**Then** they see zero rows from my Workspace (RLS test with no context returns zero rows)

**Given** Admin B has the allowlist page open
**When** the list is stale and Admin A has changed it
**Then** B's save returns 409 with the current `revision` and the page shows the fresh state without losing B's typed value

### Story 2.2: Guard every outbound URL with EgressGuard and operator private-range grants

As a platform operator,
I want every outbound address checked and private ranges granted only by me,
So that no Admin, URL or DNS trick can make Dashflow reach internal infrastructure.

**Requirements:** FR-10, NFR-4, AR-8, AR-9, AR-25, AR-54, AR-31, AD-6, UX-DR-282

**Acceptance Criteria:**

**Given** the operator runs `dashflow:egress:check {workspace} {url}`
**When** the URL's host is on the Workspace allowlist and all resolved A/AAAA addresses are public
**Then** the command prints `allowed` with the pinned IP and the guard decision trail, and no request is sent

**Given** a URL whose host is not on the Workspace allowlist
**When** the guard evaluates it
**Then** the verdict is `denied: host_not_allowlisted` (maps to `msg:host-not-allowlisted`), the attempt is recorded via `Audit::recordSecurityEvent` as `connector.egress.blocked`, and error code is `connector.ssrf_blocked`

**Given** an allowlisted host that resolves to any of: loopback, link-local, cloud-metadata, IPv4-mapped/compatible, NAT64, 6to4, Teredo, ULA, CGNAT, unspecified, multicast, in IPv4 or IPv6, including decimal/octal/hex IP spellings and mixed A/AAAA answers where one record is bad
**When** the guard evaluates the parsed binary addresses
**Then** the whole request is denied with `msg:blocked-address`, each address class has a passing test in the matrix, and no operator grant can lift loopback, link-local, metadata or the deployment's own CIDRs

**Given** an allowlisted host that resolves to an RFC 1918 or other private-range address and no grant exists
**When** the guard evaluates it
**Then** the request is denied with `msg:host-not-allowlisted` (which tells the Admin to ask an operator for a private-network address)

**Given** the operator runs `dashflow:egress:grant {workspace} {cidr} --reason` after password re-confirmation
**When** the CIDR is private and not on the undeniable deny-list
**Then** a grant row is stored for that Workspace, an entry is written to `operator_audit` and mirrored into the Workspace audit log, and the guard now allows that range for that Workspace only
**And** the same command with a deployment-own or deny-listed CIDR is refused with a clear error and no row

**Given** a grant exists
**When** the operator revokes it
**Then** later requests to that range are denied, and the revocation is audited the same way

**Given** a DNS name that first resolves to a public address and then to 127.0.0.1 (rebinding)
**When** the transport connects
**Then** the connection uses the IP the guard checked (`CURLOPT_RESOLVE` pin), only the curl handler is used, only http and https are accepted, and the rebinding test never reaches loopback

**Given** a request would follow a redirect
**When** the target is a different origin, a non-allowlisted host, or an https-to-http downgrade
**Then** the redirect is refused, credentials are stripped on any origin change, and the refusal is audited as a security event

**Given** `HTTP_PROXY`-style environment variables are set on the worker
**When** a request is built
**Then** they are ignored (httpoxy test)

**Given** repeated `connector.ssrf_blocked` events for one Workspace
**When** the count passes the alert threshold from a `pending_input` setting
**Then** an operator alert metric `dashflow.connector.ssrf_blocked` fires and no Admin-facing detail leaks the resolved address

### Story 2.3: Register and edit a Data Source

As an Admin with `data_sources.manage`,
I want to register a Data Source with its base URL, headers, limits and refresh capability,
So that I can later point Endpoints at it.

**Requirements:** FR-9, FR-10, NFR-4, NFR-12, AR-8, AR-9, AR-14, AR-25, AR-44, AR-57, UX-DR-207, UX-DR-115, UX-DR-261, UX-DR-263, UX-DR-23, UX-DR-26, UX-DR-22, UX-DR-37, UX-DR-39, UX-DR-274, UX-DR-282

**Acceptance Criteria:**

**Given** I am an Admin with `data_sources.manage`
**When** I open Data sources
**Then** a `data-table` lists name, host, auth type, health (dot plus word), last successful call and Blocks using it, with search, count caption, "+ Register data source" as the only primary button, and skeleton, empty (`msg:list-empty`), no-match (`msg:list-no-match`) and failure states

**Given** I open the form
**When** I fill Name* and Base URL* and leave the Base URL field
**Then** the server checks the host against the allowlist on blur, shows nothing on success, and for a blocked host shows `msg:host-not-allowlisted` inline with Save `aria-disabled` and its reason adjacent

**Given** a valid form
**When** I save
**Then** a `data_sources` row (UUIDv7 id, `revision` = 1, `workspace_id`) is created, I return to the list with the new row highlighted and health "Checking…", and `connector.data_source.created` is audited
**And** the form offers default headers as key/value rows ("+ Add header"), Timeout, maximum response size and maximum pages, each validated against the platform ceilings and `pending_input` defaults in `config/dashflow.php` (no invented numbers)

**Given** a default header value contains CR/LF or non-visible ASCII
**When** I save
**Then** it is rejected 422 and nothing is stored

**Given** the Refresh section
**When** I view "Supports Live refresh (~30 s)"
**Then** it is off by default with the helper "Only turn this on if the API can handle a call every 30 seconds per block." and the value is stored as `live_capable`

**Given** I enter an `http://` Base URL and `require_https` is off
**When** I save
**Then** the save succeeds, `connector.data_source.created` records `scheme=http`, the form and list show a persistent "Not encrypted" `badge` with text, and Admins see it on every later view

**Given** `require_https` is on (Workspace setting, or deployment-level override)
**When** I save an `http://` Base URL, or the deployment override forbids it
**Then** save is rejected with an inline explanation, no row is written, and toggling the setting needs no code change; default is off

**Given** I edit a saved Data Source and change base URL, headers, limits or pagination
**When** I save with the current `revision`
**Then** `revision` increments (this is the `data_source_revision` later pinned in Fetch Keys), `connector.data_source.updated` records an allowlisted before/after, and a stale `revision` returns 409 with current state

**Given** I lack `data_sources.manage`
**When** I call create/update endpoints
**Then** the API returns 403 and a security event is recorded; users in the User area cannot see Data sources at all

**Given** the form is loading or has load failure
**When** it renders
**Then** fields are skeletons with Save disabled until loaded, and a failure shows "We couldn't load these settings. Try again." with Retry, never stale values

### Story 2.4: Add authentication and write-only secrets to a Data Source

As an Admin with `data_sources.manage`,
I want to configure API key, bearer token or basic credentials that are never shown again,
So that Dashflow can call protected APIs without exposing the credentials.

**Requirements:** FR-9, NFR-4, AR-26, AR-44, AR-50, AR-54, AR-25, AR-31, AD-19, UX-DR-29, UX-DR-207, UX-DR-23, UX-DR-248, UX-DR-282

**Acceptance Criteria:**

**Given** the Authentication section
**When** I choose `none`, API key (header name and key; query placement allowed with a visible warning), Bearer (token) or Basic (username and password)
**Then** only the fields for that type are shown, and the secret fields render masked "••••••••"

**Given** I save a secret
**When** the request reaches the server
**Then** the value is envelope-encrypted via `SecretVault` under the workspace `cred` DEK, only `{configured, updated_at}` is returned by any API, and the plaintext never appears in logs, audit (hash only), Inertia props or the browser
**And** the `secrets` row references purpose `cred`, key version and wrapped DEK ref

**Given** a secret is saved
**When** I reopen the form
**Then** it shows "•••••••• · set 2 Oct 2026 · Replace", read by assistive tech as "Secret set on 2 Oct 2026", with no reveal control; "Replace token" clears the field and requires a new value

**Given** I change or replace a secret
**When** I submit
**Then** `password.confirm` is required first, the change is audited as `connector.data_source.secret_changed` (no value), and `data_source_revision` increments

**Given** default headers are marked secret
**When** I save
**Then** those values are stored as `cred` secrets, shown as set-and-replace only, and unsaved secret values are never autosaved or restored after session expiry

**Given** a form or draft payload includes a secret-valued field
**When** it is posted to a Draft/form endpoint that must not carry secrets
**Then** it is rejected 422 (secret-valued fields refused)

**Given** the `web` role
**When** a test attempts to decrypt a `cred` secret
**Then** decryption fails (keyring not mounted); only `worker-connector` holds `cred` and `token`

**Given** a `FetchRequest` is built
**When** it is serialised
**Then** it contains `secret_ref`s and a credential scheme only, never values (contract test)

**Given** an Admin without `data_sources.manage`
**When** they try to read or set secrets
**Then** the API returns 403 and nothing is disclosed

**Given** canary secrets in a test run
**When** logs, traces, audit rows and API responses are scanned
**Then** no canary appears anywhere

### Story 2.5: Test a Data Source connection

As an Admin with `data_sources.manage`,
I want to test a Data Source before or after saving,
So that I know the URL, allowlist and credentials work.

**Requirements:** FR-12, FR-10, NFR-4, NFR-1, AR-8, AR-31, AR-26, AR-35, AR-45, AR-27, AR-31, AD-28, UX-DR-135, UX-DR-207, UX-DR-22, UX-DR-70, UX-DR-276, UX-DR-282, AR-8

**Acceptance Criteria:**

**Given** the form (saved or unsaved) with valid fields
**When** I press "Test connection"
**Then** a `connection_test` Operation is created (kind registered by Connector in the `Platform\Operations` kernel, introduced in this epic), runs on `worker-connector` queue `fetch-interactive` through `FetchTransport` and `EgressGuard`, and I see a polite status while it runs

**Given** the call succeeds with HTTP 2xx
**When** the Operation completes
**Then** I see `msg:test-ok` ("Connected · 200 · 184 ms" with real status and latency), the result is visible only to me, and the Operation completion event `platform.operation.completed` is emitted

**Given** the form is unsaved and holds typed secrets
**When** I test
**Then** the secrets travel as a transient `secrets` row (`ephemeral`, TTL, owned by the Operation) and are deleted at completion or expiry; they are never autosaved

**Given** the host is not allowlisted, resolves to a blocked address, times out, returns 401/403/5xx or the TLS handshake fails
**When** the Operation completes
**Then** a `fetch-error-card` shows with `msg:host-not-allowlisted`, `msg:blocked-address` or `msg:fetch-failed`, "Technical details" (status, request ID, host, collapsed reason code) as an `aria-expanded` disclosure, Retry, and "Copy request ID"; focus moves to its title
**And** errors are collapsed to a small set of user codes; detail goes only to operator logs

**Given** a blocked attempt
**When** it occurs
**Then** it is audited as `connector.egress.blocked` and counts toward the repeated-`connector.ssrf_blocked` alert

**Given** I press Test repeatedly
**When** I exceed the per-membership or per-workspace limit (from `pending_input` settings)
**Then** the API returns 429 with `retry_after`, the button shows `aria-disabled` with the reason, and nothing is enqueued

**Given** I lack `data_sources.manage`
**When** I POST a test
**Then** 403 and a security event

**Given** Test connection is optional
**When** I save without testing
**Then** save succeeds

**Given** a different member
**When** they request my Operation result
**Then** 404/403 and no body is returned

**Given** the first outbound attempt of any kind is made by `worker-connector`
**When** it completes or fails
**Then** an expand-only migration in this story has created `sync_runs` (workspace-scoped, RLS, partitioned monthly) and the attempt is recorded with a sanitised URL template (no query string), status, HTTP status, latency, bytes, error code and request ID, and never a body or a secret
**And** later stories (OAuth, scheduled sync, retries, health) reuse this table

### Story 2.6: Accept only JSON, losslessly and within limits

As an Admin,
I want non-JSON, oversized or malformed responses rejected clearly,
So that totals are never silently wrong or truncated.

**Requirements:** FR-13, NFR-4, AR-40, AR-44, AR-13, AR-57, AD-33, UX-DR-135, UX-DR-282

**Acceptance Criteria:**

**Given** a response whose Content-Type is not JSON, or whose body fails to parse
**When** Test connection or any fetch handles it
**Then** it is rejected with `msg:not-json`, error code from `Contracts/ErrorCode.php`, no retry, and nothing stored

**Given** a response larger than the Data Source's maximum response size, measured on the decompressed stream
**When** it is read
**Then** reading stops at the limit, the error is `connector.limit_exceeded` with `msg:response-too-large` quoting the actual and allowed sizes, nothing is stored, and no truncated data is ever used

**Given** a JSON body deeper than the depth limit (`pending_input` setting)
**When** it is received
**Then** it is rejected before parsing

**Given** a body containing numbers such as `12345678901234567890.12` and `1.10`
**When** it is decoded by `LosslessJson`
**Then** each number is kept as a `DecimalLiteral` with its original lexeme, never as float, and the lossless-decoder conformance suite passes

**Given** the codebase
**When** the architecture test runs
**Then** it fails if `json_decode` appears in Ingestion, RawStore, Mapping or Results

**Given** a body with whitespace/key-order differences only
**When** canonicalised
**Then** the lossless-canonical bytes and hash are identical (golden vectors)

**Given** a gzip bomb
**When** the decompressed size passes the limit
**Then** reading aborts with `connector.limit_exceeded`

**Given** the error card
**When** it shows `msg:response-too-large`
**Then** the text states "Nothing was shown, so no totals are wrong" and focus moves to its title

### Story 2.7: Use OAuth2 client credentials

As an Admin with `data_sources.manage`,
I want to configure OAuth2 client credentials,
So that Dashflow can call APIs that require tokens without me handling them.

**Requirements:** FR-9, NFR-4, AR-26, AR-44, AR-45, AR-9, AD-19, UX-DR-29, UX-DR-207

**Acceptance Criteria:**

**Given** I choose OAuth2 client credentials
**When** I enter token URL, client ID, client secret and optional scope
**Then** the client secret is write-only (set/replace only) and the token URL must pass the allowlist check on blur and again at egress

**Given** a fetch or Test connection needs a token
**When** the transport requests it
**Then** the token request goes through `EgressGuard`, follows no redirects, accepts JSON only under a size cap, and the token is cached at `oauth:{ws}:{ds}:{secret_version}` (purpose `token`, `worker-connector` only) until `expires_in` minus a skew from a `pending_input` setting

**Given** the API answers 401 with a cached token
**When** the call is made
**Then** the token is refreshed once and the call retried once; a second 401 yields `connector.auth_failed` with no further retry and the error card says authentication failed

**Given** the secret version changes (Replace)
**When** the next call is made
**Then** the old cached token is not used

**Given** the token URL is blocked, non-JSON or redirects
**When** a token is requested
**Then** it fails with `msg:host-not-allowlisted`, `msg:not-json` or `msg:fetch-failed` as appropriate and is audited when it is an egress block

**Given** the Authorization header is built
**When** logs and `sync_runs` are written
**Then** no token appears (scrubbing test with canary tokens)

### Story 2.8: Prevent overwriting on the Data source form with a soft lock

As an Admin,
I want to be warned when another Admin is editing the same Data Source,
So that nobody's changes are silently overwritten.

**Requirements:** FR-9, AR-19, AR-42, AR-17, UX-DR-250, UX-DR-249, UX-DR-248, UX-DR-263, UX-DR-282

**Acceptance Criteria:**

**Given** Admin A opens a Data Source for editing
**When** the form loads
**Then** `Platform\EditLock` key `edit_lock:{ws}:data_source:{id}` is taken (kernel introduced in this epic) with a TTL from a `pending_input` setting, and A can edit

**Given** Admin B opens the same Data Source
**When** the lock is held
**Then** B sees a read-only form with the `msg:draft-locked` banner and "Take over editing" and Close buttons

**Given** B chooses "Take over editing"
**When** the flush completes (`platform.edit_lock.flush_requested`)
**Then** `lock_epoch` increments in PostgreSQL, A's valid non-secret fields are saved first, A is notified with `msg:draft-taken-over`, and B can edit

**Given** A saves with the old epoch
**When** the server checks it
**Then** it returns 423 `platform.edit_lock_lost` and A's unsaved secret values are not saved

**Given** the lock holder is inactive past the TTL or closes the tab
**When** B reloads
**Then** the lock is released and B edits normally

**Given** a session is 2 minutes from expiry on the form
**When** the warning shows
**Then** `msg:session-warning` appears with the countdown, "Stay signed in" has initial focus and extends the session; after expiry, sign-in returns to the page without restoring unsaved fields and with `msg:session-expired`

**Given** two writers race on `revision`
**When** both save
**Then** one succeeds and the other gets 409 with current state

### Story 2.9: Register Endpoints on a Data Source

As an Admin with `data_sources.manage`,
I want to define an Endpoint (method, path, parameters),
So that Blocks can later select a ready, safe request.

**Requirements:** FR-11, FR-8 (parameter model), NFR-4, AR-9, AR-44, AR-25, AR-14, UX-DR-134, UX-DR-27, UX-DR-28, UX-DR-23, UX-DR-26, UX-DR-37, UX-DR-22, UX-DR-282

**Acceptance Criteria:**

**Given** a saved Data Source
**When** I open its Endpoints tab and add an Endpoint
**Then** I see the Endpoint field (a `method-prefix` GET segment joined to a mono path input) and a `parameters-table` (Name mono, Binding select, Value input, remove)

**Given** I save a valid GET Endpoint (e.g. `GET /api/v2/finance/revenue`)
**When** it is stored
**Then** an `endpoints` row with an immutable `endpoint_revisions` row (revision 1, method, path AST, bindings) is created, `connector.endpoint.created` is audited, and the Endpoint appears in the list

**Given** I edit a saved Endpoint
**When** I save
**Then** a new immutable `endpoint_revisions` row is created, the current-revision pointer moves, older revisions stay unchanged, and `connector.endpoint.revised` is audited

**Given** the parameter Binding options
**When** I choose them
**Then** Fixed value, Date Range `from`/`to`, Block Period Selector `start`/`end` are offered (user-context options are added in a later story); date/period bindings store the binding only and resolve at fetch time (period resolution wiring is out of scope here)

**Given** a path template with `{id}` placeholders
**When** it is parsed
**Then** it becomes a URL AST once, segments are percent-encoded, and a value containing `/`, `.` or `..` is rejected with 422 naming the parameter

**Given** a bound header value with CR/LF or non-visible ASCII
**When** I save
**Then** it is rejected

**Given** I switch the method to POST
**When** the form updates
**Then** the required checkbox `msg:post-readonly` appears; Save is `aria-disabled` with its reason until it is ticked
**And** ticking needs `data_sources.manage`, shows a risk confirmation, and saving audits `connector.endpoint.read_only_flag_set`
**And** a POST body places bound values only at typed JSON positions

**Given** any method other than GET or POST (PUT, PATCH, DELETE)
**When** submitted
**Then** it is rejected 422; Dashflow never sends a modifying request

**Given** a path that is absolute (`https://other.host/x`) or protocol-relative
**When** I save
**Then** it is rejected, because Endpoint URLs resolve only against the Data Source base URL

**Given** the page of Endpoints
**When** I lack `data_sources.manage`
**Then** 403 on all writes

### Story 2.10: Test an Endpoint and see the Sample Response

As an Admin with `data_sources.manage`,
I want to run an Endpoint and see status, latency and a Sample Response,
So that I know it returns what I expect before building on it.

**Requirements:** FR-12, FR-11, FR-13, NFR-4, NFR-1, AR-35, AR-45, AR-46, AR-13, AR-40, AD-28, UX-DR-135, UX-DR-25 (JSON viewer region), UX-DR-26, UX-DR-70, UX-DR-276, UX-DR-279, UX-DR-282

**Acceptance Criteria:**

**Given** an Endpoint with parameters
**When** I press "Test endpoint" and supply test values for each Fixed, Date Range and Period parameter
**Then** a `sample_fetch` Operation (kind registered by Ingestion) runs through `FetchTransport` with the Endpoint's current revision, and I see status, latency and the Sample Response in a mono viewer inside a focusable labelled region

**Given** the call succeeds
**When** the result is delivered
**Then** `msg:fetch-ok` is announced politely, the body is an encrypted Valkey blob with TTL visible only to me, it is never written to Postgres or `raw_bodies`, and numbers keep their lexemes

**Given** an empty required path parameter
**When** I press Test
**Then** the action is `aria-disabled` with a reason naming the parameter and nothing is sent

**Given** the call fails (host not allowlisted, blocked address, response too large, not JSON, other)
**When** the Operation completes
**Then** the `fetch-error-card` shows the matching message key with Technical details and "Copy request ID", and focus moves to its title

**Given** the response exceeds the limits
**When** the call ends
**Then** `connector.limit_exceeded` is returned, nothing is stored, and no truncated sample is shown

**Given** a POST read-only Endpoint
**When** tested
**Then** it sends an `Idempotency-Key`, is not auto-retried on ambiguous failure, and the test audit event records method and Endpoint ID

**Given** I exceed the Fetch sample rate limit
**When** I retry
**Then** 429 with `retry_after`, button `aria-disabled` with reason

**Given** the Endpoint revision changes while the Operation runs
**When** the result arrives
**Then** it is marked stale for the new revision and not shown as current

**Given** Admin without `data_sources.manage` or from another Workspace
**When** they request the Operation
**Then** no data returned

### Story 2.11: Follow pagination up to the limits

As an Admin,
I want paginated APIs fetched completely within safe bounds,
So that totals are correct and a runaway API cannot hurt us.

**Requirements:** FR-13, NFR-3, AR-9, AR-44, AR-57, AR-8, UX-DR-26, UX-DR-135, UX-DR-282

**Acceptance Criteria:**

**Given** a Data Source with pagination style None, page, offset, cursor or link header and `max_pages` and `max_bytes`
**When** an Endpoint test or fetch runs
**Then** pages are followed until the API signals the end or a limit is hit, and the merged result is one logical response

**Given** the style is `link_header`
**When** the next URL has a different origin than the Endpoint, or is not allowlisted, or downgrades https to http
**Then** following stops with `connector.ssrf_blocked`, the attempt is audited, and nothing is stored

**Given** the style is `cursor`
**When** the cursor is read
**Then** it is treated as a token, never as a URL

**Given** the page count would exceed `max_pages` or the combined decompressed bytes exceed `max_bytes`
**When** the limit is reached
**Then** the run fails with `connector.limit_exceeded` and `msg:response-too-large` (or the page-limit equivalent), nothing is stored and nothing is truncated

**Given** a page fails midway
**When** retries are exhausted
**Then** the whole fetch fails, last-known-good is untouched, and the error card names the page number

**Given** the credentials
**When** a next-page URL changes origin
**Then** credentials are stripped before any request

**Given** the pagination style is page/offset
**When** the API returns an empty page
**Then** following ends cleanly

### Story 2.12: Define user attributes for user-context binding

As an Admin with `users.manage`,
I want to define attribute keys and set per-user values,
So that Endpoints can later return only each user's data.

**Requirements:** FR-8, FR-67 (attributes part), NFR-4, AR-6, AR-26, AR-37, AR-25, AR-36, AD-4, AD-30, UX-DR-115, UX-DR-23, UX-DR-263, UX-DR-282

**Acceptance Criteria:**

**Given** I have `settings.manage`
**When** I add an attribute key (immutable key id, label) in System settings > User attributes
**Then** `user_attribute_keys` gets a row, `access.attribute_key.created` is audited, and the key is immutable afterward (only the label may change)

**Given** I have `users.manage`
**When** I open a member in User configuration
**Then** I can set a value for each defined attribute; values are encrypted under the `data` DEK with a blind index, never shown in logs, and audit stores only hashes

**Given** I try to edit my own attributes
**When** I save
**Then** it is refused 403 (nobody edits their own attributes or permissions) and a security event is recorded

**Given** an attribute value is created or changed
**When** the transaction commits
**Then** an `access.attribute.changed` outbox event (IDs only) is emitted in the same transaction as the audit event

**Given** I lack `users.manage`
**When** I call the attribute API
**Then** 403

**Given** a user's membership is removed
**When** `access.membership.removed` is consumed
**Then** that member's attribute values are deleted by Access

**Given** a value is empty or fails the key's validation
**When** I save
**Then** inline error, 422, nothing stored

**Given** another Workspace
**When** keys are listed
**Then** none from this Workspace appear (RLS)

### Story 2.13: Bind Endpoint parameters and headers to user context

As an Admin,
I want Endpoint parameters or headers bound to the signed-in user's ID, email, group or attribute,
So that the API can return only that user's data and users cannot change the binding.

**Requirements:** FR-8, FR-11, FR-12, NFR-4, AR-10, AR-37, AR-25, AR-27, AR-35, AR-54, AD-7, AD-30, UX-DR-134, UX-DR-37, UX-DR-22, UX-DR-276, UX-DR-282

**Acceptance Criteria:**

**Given** the Binding select
**When** I open it
**Then** it lists "User context" with user ID, email, group, and each defined attribute key; a bound row shows the `tag` "user context" (info-soft/info-strong) and resolved value text in mono text-muted without revealing real user values

**Given** I save a user-context binding in a parameter or header
**When** the Endpoint revision is stored
**Then** `endpoint_revisions` holds the binding with `requires_user_context` derived true, a new revision is created, and `connector.endpoint.revised` is audited

**Given** an Endpoint with no user-context binding
**When** I view it
**Then** it shows a "shared data" badge (text, never colour only) telling me everyone with access sees the same data

**Given** a user-context binding
**When** a client tries to supply the value
**Then** client input is never accepted; values come only from server-side membership data

**Given** I have `data.preview_as_user`
**When** I run "Fetch as user" for a chosen member
**Then** a `fetch_as_user` Operation returns the response only to me as an ephemeral blob, nothing is stored in `draft_samples` or Raw Store, `connector.fetch_as_user.performed` is audited, and the target user is notified (event emitted)

**Given** I lack `data.preview_as_user`
**When** I request Fetch as user
**Then** 403 and the control is `aria-disabled` with `msg:perm-denied`

**Given** the chosen user lacks a value for a bound attribute
**When** Fetch as user runs
**Then** it fails closed with `access.context_missing`, no request is sent, and the message names the missing attribute (not any value)

**Given** a bound header value
**When** it contains CR/LF
**Then** rejected

**Given** `requires_user_context` is true
**When** the optional `scope_by_caller` flag is set
**Then** membership ID is included in `ctx` (see Fetch Key story)

**Given** assistive tech reads the table
**When** a binding resolves
**Then** screen-reader text never includes resolved user-context values

### Story 2.14: Fetch each Endpoint on a schedule and keep the last good response

As an Admin,
I want Dashflow to call each Endpoint on a schedule and keep the latest good response,
So that data stays available when the API is slow or down.

**Requirements:** FR-13, NFR-1, NFR-3, NFR-5, NFR-12, NFR-13, AR-2, AR-5, AR-10, AR-13, AR-14, AR-33, AR-36, AR-43, AR-31, AD-7, AD-9, AD-26, AD-29

**Acceptance Criteria:**

**Given** a saved Endpoint revision
**When** it is created
**Then** one representative sync target for that revision (shared context, Admin-defined test values for fixed params) is registered with `fetch_key = "fk1:" + hex(sha256(JCS(input)))` computed only by `FetchKeyResolver`

**Given** the golden-vector suite
**When** it runs in CI
**Then** typed params (string, number, bool, date as workspace-local `YYYY-MM-DD`, datetime as UTC `Z`), absent bindings omitted, `ctx = "shared"` or `HMAC(digest_key_ws_v, "bound|"+JCS(attrs))`, and key-order independence all match fixed vectors

**Given** a bound attribute is missing or invalid
**When** the resolver is asked for a bound key
**Then** it produces no key, no fetch happens, and the reason is `access.context_missing`

**Given** the `scheduler` role ticks every `dispatch_tick`
**When** a target is due
**Then** the dispatcher (role `system`, `FOR UPDATE SKIP LOCKED`) increments `dispatch_seq`, advances `next_due_at` and enqueues `FetchJob(workspace_id, sync_group_id, dispatch_seq)` on `fetch-scheduled`; the job re-enters tenant context and re-reads IDs under RLS (mismatch is a hard failure and a security event)

**Given** a run succeeds
**When** it commits
**Then** one transaction writes `raw_bodies` (bytea, content-addressed per target), an immutable `raw_observations` row, `current_payload_id`, `payload_seq`, and `ingestion.payload.changed`, guarded by `applied_seq < :dispatch_seq`

**Given** a late or duplicate run with a lower `dispatch_seq`
**When** it tries to commit
**Then** it is recorded `superseded` and changes nothing (race tests)

**Given** the lost-job case
**When** the next tick runs
**Then** the target is re-derived from `next_due_at` and dispatched again

**Given** a sync run is recorded
**When** I inspect `sync_runs`
**Then** it stores a sanitised URL template and parameter names only, status, HTTP status, latency, bytes, error code and request ID (no query strings, no secrets)

**Given** the Data Source detail
**When** a target has succeeded
**Then** the Admin sees "Last success {time}" for the Endpoint

**Given** a pasted sample
**When** anything is stored
**Then** it never enters the raw tier

### Story 2.15: Use conditional requests and skip unchanged data

As an Admin,
I want unchanged responses detected cheaply,
So that APIs are not hammered and nothing is recomputed when data has not changed.

**Requirements:** FR-13, NFR-3, NFR-1, AR-12, AR-40, AD-8, UX-DR-282

**Acceptance Criteria:**

**Given** a stored ETag
**When** the next scheduled fetch runs
**Then** it sends `If-None-Match`; a 304 sets `last_success_at` and `last_checked_at`, keeps the current payload and emits no `ingestion.payload.changed`

**Given** no ETag but a stored Last-Modified
**When** the next fetch runs
**Then** it sends `If-Modified-Since` with the same 304 outcome

**Given** neither header
**When** the body arrives
**Then** the sha256 of the lossless-canonical body (whitespace and key order normalised, number lexemes as received) is compared with `content_hash`; equal means same outcome as 304, different stores a new payload

**Given** a 304
**When** shown to the Admin
**Then** it counts as a success for health, "checked at" updates, and data-as-of is unchanged

**Given** the Endpoint revision or `data_source_revision` changes
**When** the next fetch runs
**Then** conditional state (`etag`, `last_modified`, `content_hash`) is reset and a full fetch happens

**Given** a 304 arrives with no stored payload
**When** handled
**Then** it is treated as an error, the conditional state is cleared, and a full fetch runs next

**Given** a body that differs only by whitespace
**When** hashed
**Then** it is detected as unchanged

### Story 2.16: Choose how much raw history a Data Source keeps

As an Admin with `data_sources.manage`,
I want to choose between latest-only and a short window of raw history,
So that I keep only the data I need.

**Requirements:** NFR-5, AR-13, AR-5, AR-57, AD-9, UX-DR-207, UX-DR-26, UX-DR-282

**Acceptance Criteria:**

**Given** the Data Source form
**When** I open Retention
**Then** I can choose `latest` (default) or `window(N days)` with N validated against a `pending_input` maximum, and saving is audited as `connector.data_source.updated`

**Given** retention is `latest`
**When** a new payload becomes current
**Then** the superseded payload is swept by `maintenance` after the grace period (a `pending_input` setting), and the current payload is never removed

**Given** retention is `window(N)`
**When** the sweep runs
**Then** observations and bodies older than N days are deleted except the current payload

**Given** the roles
**When** tests run
**Then** only `maintenance` can DELETE; the `app` role cannot (INSERT/SELECT only on raw_observations; never UPDATE)

**Given** a cold target past `cold_purge_after`
**When** the purge runs
**Then** its payloads are deleted, and targets with a current payload that is the only copy are only purged when cold

**Given** a changed retention setting
**When** the next sweep runs
**Then** the new rule applies without touching `current_payload_id`

### Story 2.17: Retry, rate-limit and break the circuit on failing sources

As an Admin,
I want failing or throttling APIs handled gracefully,
So that outages never turn into wrong numbers or a flood of calls.

**Requirements:** FR-13, NFR-1, NFR-3, NFR-13, AR-45, AR-43, AR-57, AR-31, AD-26

**Acceptance Criteria:**

**Given** a network error, timeout, 5xx or 408 on a GET
**When** a scheduled fetch fails
**Then** it retries with exponential backoff and full jitter up to `max_attempts` (base, cap, attempts are `pending_input`), all within the interval

**Given** a 429 or 503 with `Retry-After`
**When** received
**Then** the delay is honoured up to a cap and the per-Data-Source token bucket is drained

**Given** other 4xx
**When** received
**Then** no retry, and an Admin-visible configuration error is recorded on the run

**Given** a non-JSON, over-limit or too-deep response
**When** received
**Then** no retry and the last-known-good payload is kept

**Given** a POST read-only Endpoint
**When** the outcome is ambiguous (timeout after send)
**Then** it is not retried automatically

**Given** consecutive failures reach the circuit-breaker count (`pending_input`)
**When** the breaker opens
**Then** further calls to that Data Source are skipped until the cool-down, one half-open probe then decides whether to close it, and `sync_runs` records the skips

**Given** rate limits
**When** many targets are due
**Then** a token bucket per Data Source, a concurrency cap and per-workspace fairness caps apply, and one Workspace cannot starve another

**Given** a run is superseded or fails
**When** it ends
**Then** failures are visible in `sync_runs` with an error code and request ID, and metrics `dashflow.connector.*` are emitted

### Story 2.18: See Data Source health

As an Admin,
I want each Data Source's health and last success shown everywhere I choose or manage it,
So that I notice a broken API before users do.

**Requirements:** FR-12, NFR-13, NFR-1, AR-46, AR-36, AR-57, UX-DR-114, UX-DR-260 (Your data sources part), UX-DR-115, UX-DR-261, UX-DR-39, UX-DR-43, UX-DR-282, UX-DR-276, UX-DR-273

**Acceptance Criteria:**

**Given** I save a Data Source
**When** the save commits
**Then** a probe of the base URL (or an optional health path) runs and the list shows "Checking…" until the first result

**Given** rolling `sync_runs` and breaker state
**When** health is computed
**Then** the status is `healthy`, `degraded` or `unreachable` using thresholds from `pending_input` settings, a 304 counts as success, and a source with no Endpoints reflects its probe

**Given** the Data sources list
**When** it renders
**Then** each row shows `health-row` style dot AND word (Healthy/Degraded/Unreachable) plus "Last success 10:42"; a dot never appears without its word and is `aria-hidden`; assistive tech reads the full status

**Given** the Admin overview page
**When** it loads
**Then** a "Platform health" panel shows "Your data sources" with dot, word and last success per source, each row opening the form; the platform services part (Dashboard API etc.) is out of scope here

**Given** a source changes state
**When** the transition is recorded
**Then** `ingestion.source_health.changed` is emitted (IDs/enums only) and audited so Admin notification delivery can consume it; in-app delivery is out of scope here

**Given** the source recovers
**When** a success is recorded
**Then** state returns to Healthy and "Last success" updates

**Given** a source with no data yet
**When** health is read
**Then** it shows "Checking…", never Healthy

**Given** I lack `data_sources.manage`
**When** I request health
**Then** the Admin-only panel is not rendered and the API returns 403

### Story 2.19: Refresh only what is being watched, within budgets

As an Admin and platform operator,
I want fetching to follow demand and stay within per-workspace budgets,
So that idle data costs nothing and a Workspace cannot overload a source.

**Requirements:** FR-13, NFR-1, NFR-3, NFR-13, AR-11, AR-44, AR-57, AD-7, UX-DR-26, UX-DR-282

**Acceptance Criteria:**

**Given** `Ingestion\Contracts\Subscribe`
**When** a caller registers or touches a subscription (sync target, Block version, role primary|comparison, refresh interval, compute ctx, resolved period)
**Then** a `sync_subscriptions` row is upserted, the interval is copied at subscribe time, touches are throttled, and `hot_until = last_access_at + hot_window` (a `pending_input` setting)

**Given** several hot subscriptions on one target
**When** the dispatcher schedules it
**Then** the effective interval is the minimum, and a target with no hot subscription gets only the health probe (one representative target per Endpoint revision)

**Given** the workspace budgets (max hot keys, fetch rate per Data Source, new cold keys per membership per hour)
**When** a budget is exceeded
**Then** the effective interval widens, the item is marked budget-limited, nothing is queued without limit, and a metric is emitted

**Given** Live (~30 s) is requested
**When** the Data Source is not `live_capable`, the Endpoint is POST, or the budget is exhausted
**Then** Live is refused with `msg:live-not-supported` and the select option is disabled with that reason as description

**Given** a Data Source loses `live_capable` or the Workspace removes an interval
**When** the change is saved
**Then** published versions keep their value, the scheduler clamps to the nearest allowed interval, and the Data Source detail shows an impact list of affected subscriptions with an event emitted for Admin notification

**Given** a cold per-user target
**When** `cold_purge_after` passes
**Then** it and its payloads are purged

**Given** a request for a key with a missing bound attribute
**When** `Subscribe` is called
**Then** no target is created and the reason `access.context_missing` is returned

**Given** an operator
**When** they view metrics
**Then** `dashflow.ingestion.hot_targets`, `.cold_targets` and `.budget_limited` exist and carry `workspace_id` and `request_id`

### Story 2.20: Fetch comparison data together as a sync group

As an Admin,
I want a primary and its prior-period request fetched together,
So that a comparison never mixes data from different moments.

**Requirements:** FR-13, NFR-1, AR-33, AR-11, AR-36, AD-26

**Acceptance Criteria:**

**Given** a comparison subscription links a primary target to its comparison target
**When** the dispatcher picks them
**Then** one `FetchJob(workspace_id, sync_group_id, dispatch_seq)` fetches both under one fence

**Given** both fetches succeed
**When** the run commits
**Then** one `sync_generations` row is written and `ingestion.payload.changed` carries the generation

**Given** the comparison fetch fails
**When** the run commits
**Then** the generation is written with the comparison side marked failed, the primary payload is still stored, and the comparison is reported `unavailable` to consumers

**Given** compute reads a generation
**When** it is incomplete
**Then** only complete generations are used (race test: primary new, comparison old is never served)

**Given** a late run with a lower `dispatch_seq`
**When** it commits
**Then** it is `superseded`

**Given** the maintenance sweep
**When** a target's `payload_seq` is ahead of consumers
**Then** the event is re-emitted

**Given** the Data Source detail
**When** I view sync runs
**Then** a group run appears as one run with both sides' outcomes

## Epic 3: Build and Publish Your First Block

Admins can build a KPI Block in the five-step wizard (Configure Block, Data Source, Map Data, Preview, Save/Publish): obtain a sample (fetch or paste), Auto-map with the six field roles, Review, Override, Preview, validate against the real API and publish v1.0. This is a thin slice: only the `kpi-card` Block Type is built end to end (plus the Block Type contract v1 and registry), so the wizard, mapper, Query Plan engine, lossless decoding, preview endpoint, validation Operation and publish gate are proven early. Richer transforms, calculated fields, comparisons, text templates and the other 10 Block Types are Epic 5; Dashboards, Results, notifications and the Block lifecycle lists/impact/restore are later epics.

### Story 3.1: Register the Block Type contract v1 and the kpi-card package

As a developer extending Dashflow,
I want a versioned Block Type package contract and a registry with `kpi-card` as the first package,
So that the server, the browser and the wizard all read one definition of a Block Type's slots and limits.

**Requirements:** FR-32 (kpi-card slots only), NFR-12, AR-28, AD-21, AR-3, AR-39, AR-54

**Acceptance Criteria:**

**Given** the repository contains `block-types/kpi-card/v1/`
**When** the build runs
**Then** the directory holds `schema.json`, `Shaper.php` and `Renderer.vue`
**And** `schema.json` declares the common Slots Title, Subtitle, Icon and Footer action plus KPI label*, Headline value*, comparison value or change %, comparison label, secondary note and status badge, each with a value type (number, text, date/time, boolean), a cardinality (single) and which Field Roles it accepts
**And** `schema.json` declares default and minimum/maximum size limits and that the default width is 3 grid columns and default height Small
**And** a single generated TypeScript type and a PHP value object are produced from `schema.json` (a CI check fails when either drifts from it).

**Given** the Block Type registry is loaded in a Workspace
**When** an Admin with `blocks.edit` calls `GET /api/v1/block-types`
**Then** the response lists `kpi-card` with its key, contract version `1`, slots, size limits and default aggregations
**And** the other ten MVP keys are not listed until their packages exist
**And** a User-area session receives 403 and the denial is audited.

**Given** a Block Version pins contract `v1` of `kpi-card`
**When** a developer tries to delete or rename `block-types/kpi-card/v1/`
**Then** a CI check fails stating that a contract directory is never deleted while a version pins it
**And** the registry resolves a pinned `(key, contractVersion)` pair, never "latest".

**Given** a package declares an unknown slot value type or a slot without a cardinality
**When** the registry boots
**Then** boot fails with a readable error naming the package and slot
**And** no Block Type is partially registered.

**Given** the Pest architecture tests run
**When** `Shaper.php` calls I/O (database, HTTP, filesystem, clock) or `json_decode`
**Then** the architecture test fails (Shapers are pure; payload bodies are parsed only by the lossless decoder, AR-40).

**Given** the lint rules run on `Renderer.vue`
**When** it uses `v-html`, `innerHTML` or a formatter string
**Then** lint fails (AD-21).

### Story 3.2: See the KPI card in every state, rendered safely and exactly

As an Admin building a block,
I want the KPI card face to render Normal, Loading, Empty, Stale, Unavailable and Error states with exact decimal numbers,
So that what I preview is what users will later see.

**Requirements:** FR-29 (formatting descriptors), FR-30 (Unavailable never zero), NFR-7, NFR-11, AR-40, AD-33, AD-21, UX-DR-53, UX-DR-54, UX-DR-146, AR-54

**Acceptance Criteria:**

**Given** the `kpi-card` Shaper receives engine output with a Headline value `284680.10` held as a decimal string, a KPI label and a comparison value
**When** it shapes the render payload
**Then** the payload carries the measure as a decimal string, a typed value and a format descriptor (number, decimal places, thousands separator, percent, currency code and symbol, compact, date pattern, relative time, duration, units suffix)
**And** it contains no pre-formatted, locale-dependent string.

**Given** a render payload with a format descriptor for currency
**When** the Renderer formats it with `Intl` using user locale, then the Workspace default, then `en`
**Then** `284680.10` shows as "$284,680.10" for `en-US` and the value is not passed through a JavaScript float (a 20-digit value keeps every digit).

**Given** the kpi-card is a 3-column Small Block with a delta
**When** the comparison is positive, negative or flat
**Then** the delta line always carries an arrow glyph (up-right or down-right) that is `aria-hidden`, or "No change" when flat
**And** when Direction is "Lower is better" the visible delta adds "· better" or "· worse" in the delta colour
**And** hidden text states direction, value, label and judgement (e.g. "Up 14.2% from last year, favourable")
**And** colour is never the only signal.

**Given** the Headline value slot is `unavailable` because its field is missing
**When** the Block renders for a User
**Then** it shows the warning icon and `msg:unavailable-user` and never "0", "NaN" or "—" as the headline
**And** the Admin variant shows `msg:unavailable-admin` ("Unavailable: total missing").

**Given** the Block is in Loading, Stale, Empty or Error state
**When** it renders
**Then** Loading shows skeleton bars and, after 5 seconds, a "Still loading…" caption with the shimmer stopped; Stale shows muted values, a clock icon and "Stale: last data 10:42" in warning; Empty shows a centred muted caption; Error shows an error icon, a message and a Retry link
**And** every state is reachable as a Vitest fixture.

**Given** a slot value contains `<img src=x onerror=alert(1)>` or `"><script>`
**When** the XSS fixture runs through every kpi-card slot (label, secondary note, comparison label, status badge)
**Then** the text is shown literally and nothing executes.

**Given** a long label or 200% text zoom
**When** the Block renders
**Then** content scrolls inside the Block body, a focusable region labelled with the Block title, and nothing is clipped.

### Story 3.3: Start, save and discard a Draft block

As an Admin with the `blocks.edit` permission,
I want to create a Draft block that stores my progress safely and appears under Draft blocks,
So that I can leave and resume building a block without losing work or overwriting a colleague.

**Requirements:** FR-19, FR-38 (Draft blocks list part), AR-17, AD-13, AR-4, AR-14, AR-25, AR-39, AR-42, AR-36, UX-DR-255

**Acceptance Criteria:**

**Given** an Admin with `blocks.edit`
**When** they `POST /api/v1/blocks/drafts` with a name and Block Type `kpi-card`
**Then** a Block and one Draft row (`block_versions.state = 'draft'`) are created in one transaction with UUIDv7 keys, `workspace_id`, `revision = 1`, `lock_epoch` and a prospective version number "1.0" fixed at creation
**And** the tables have `ENABLE`+`FORCE ROW LEVEL SECURITY` and a no-context query returns zero rows
**And** an audit event in the AD-29 grammar (e.g. `blocks.draft.created`) is written in the same transaction.

**Given** a Block already has a Draft
**When** a second Draft is created for that Block
**Then** the partial unique index `WHERE state='draft'` rejects it and the API returns 409 `blocks.draft_exists` with the current Draft ID.

**Given** a Draft at `revision` 4 and `lock_epoch` 1
**When** a save arrives with `expected_revision` 3
**Then** the API returns 409 with the current state and nothing is written
**And** a save with `expected_revision` 4 and a stale `lock_epoch` returns 423 `platform.edit_lock_lost`
**And** a successful save bumps `revision` and writes no audit event per autosave.

**Given** a Draft with only a name and Block Type
**When** it is saved
**Then** the lenient draft schema accepts it (missing required slots, no Endpoint)
**And** the strict validation schema is not applied until validation (Story 3.18).

**Given** a save payload contains a secret-valued field (a token or password)
**When** it is submitted
**Then** the API rejects it with 422 and nothing is stored (AR-26: Draft payloads reject secret-valued fields).

**Given** an Admin opens Admin > Draft blocks
**When** the list loads
**Then** it shows name, category, type, version, owner, last updated and a status badge "Draft", in an accessible `data-table` with search and a count
**And** each row opens the wizard at the step stored in `draft_ui_state`
**And** the list shows only Drafts (a Published list is Story 3.19 and Epic 6).

**Given** an Admin discards a Draft
**When** they confirm
**Then** the Draft, its `draft_samples` and `draft_ui_state` are deleted by the `maintenance`-role path, an audit event is written and a later save gets 404
**And** an Admin without `blocks.edit` opening the page sees `msg:perm-denied`, the page is not rendered and the denial is audited.

### Story 3.4: Configure Block (step 1) with a live preview

As an Admin,
I want the Create block wizard shell and Step 1 with display and behaviour options and a live preview,
So that I can name and shape the block and see its size on Desktop, Tablet and Mobile before I connect data.

**Requirements:** FR-14, FR-17, FR-18 (live preview pane), NFR-9, NFR-7, UX-DR-47, UX-DR-48, UX-DR-49, UX-DR-88, UX-DR-89, UX-DR-170, UX-DR-171, UX-DR-172, UX-DR-255, UX-DR-87, UX-DR-117, UX-DR-153

**Acceptance Criteria:**

**Given** an Admin opens Create block
**When** the wizard renders
**Then** the header reads "Block management / Create block" with the title, a "• Draft" badge, Save draft, Preview and Publish block
**And** a `nav` "Create block progress" holds an ordered list of five steps Configure Block, Data Source, Map Data, Preview, Save/Publish, the current one with `aria-current="step"`, later steps as plain text "(not started)"
**And** a completed step collapses to a one-line summary card with a green check and "Edit", and buttons are named "{n}. {label}, completed" or ", not completed"
**And** the Admin can jump back to any completed step but forward only to the first incomplete one.

**Given** Step 1 is open
**When** the Admin leaves Block name or Description empty and activates Continue
**Then** the fields show an inline error, focus moves to the first, and the Draft keeps the other values
**And** Category, Block type (only registry keys), an icon (defaulting from the Category, with "Change" and "Use category icon") can be set.

**Given** the Display & behavior section
**When** the Admin sets default width in columns, default height (Small, Medium 360 px, Large), toggles Show block header, Show subtitle, Allow resize, Allow minimize, Allow refresh and Show footer action, minimum and maximum size, and period behaviour (Follow dashboard default, Own selector, Not date-filtered)
**Then** values outside the Block Type's size limits are rejected with the allowed range
**And** "Own selector" is stored but no Block Period Selector is rendered here (it appears with dashboards in Epic 4)
**And** turning on Show footer action adds the Footer action Slot as Optional-empty (its target is configured in Epic 5).

**Given** any Step 1 change
**When** the Admin edits a field
**Then** the Live preview pane ("Live preview", "Changes appear here automatically.") re-renders at once with the `kpi-card` skeleton before data exists
**And** Desktop, Tablet and Mobile segmented toggles change the canvas width and the size label pill reads e.g. "3 columns × Small" or "8 columns × Medium (360px)"
**And** `msg:version-safe` ("Publishing creates version 1.0...") appears under the preview naming the version the publish will create.

**Given** an Admin without `blocks.publish`
**When** they view any wizard step
**Then** Publish block is shown disabled with `msg:perm-publish` as inline text (`aria-disabled`, focusable) and Save draft still works.

**Given** the viewport is under 1440px
**When** the wizard renders
**Then** the sidebar shows as the icon rail and slot rows wrap, with the Live preview pane sticky on the right in steps 1 to 3.

**Given** the wizard is open on step 1 or step 2 on a 1280 px wide window
**When** the layout renders
**Then** the left column holds form section cards (1 px border, radius lg, padding 16, 28 px accent-soft icon tile, title and caption subtitle) and the right column holds the sticky preview at about 45% of the content width, with the sidebar collapsed to the icon rail below 1440 px (UX-DR-87, UX-DR-153)
**And** the Admin can choose the Block icon from a 320 px popover with search and a six-column grid of 28 px tiles, whose active, selected and focus states follow the design tokens, and the default icon follows the category (UX-DR-117, FR-14)

**Given** the Workspace has no Block categories yet
**When** the Admin opens step 1 for the first time
**Then** an expand-only migration has created `block_categories` (workspace-scoped, RLS, `id`, `name`, `archived` default false) and a default "General" category exists for the Workspace, so the Category field is never empty
**And** category ordering, renaming, archiving and the Admin management screen are added in Epic 6 on this same table

### Story 3.5: Autosave continuously and recover from expiry, offline and save failure

As an Admin building a block,
I want my work saved as I type and restored after sign-in, offline or a failed save,
So that I never lose a draft.

**Requirements:** FR-19, FR-2 (wizard draft restore), UX-DR-170, UX-DR-248, UX-DR-249 (wizard flush hook only; the dialog is FR-2/Epic 1), UX-DR-251, UX-DR-253, UX-DR-255, AR-17, AR-27, AR-39

**Acceptance Criteria:**

**Given** an Admin edits any wizard field
**When** the debounce elapses (a `pending_input` setting)
**Then** the client sends one compare-and-set save with `expected_revision` and `lock_epoch` through `/api/v1` with `X-Background: 1` so the autosave does not extend the idle session
**And** the current step, acknowledged warnings and slot statuses are stored in `draft_ui_state`, excluded from version diff.

**Given** the session-expiry warning (`msg:session-warning`) appears
**When** it opens, and again just before expiry
**Then** the client flushes pending changes
**And** unsaved secret fields are never autosaved.

**Given** a save returns 401 because the session expired
**When** the Admin signs in again
**Then** the pending delta kept in `sessionStorage` is replayed with CAS, the wizard reopens at the step in `draft_ui_state`, and `msg:session-expired` ("...Your draft was saved, and we've restored it.") shows only if the flush was acknowledged
**And** if the replay hits a revision conflict the Admin sees both versions and chooses, nothing is silently overwritten.

**Given** a save returns 409 `blocks.draft_published`
**When** the autosave sees it
**Then** the wizard stops saving, shows that the Draft was published, and offers the Published blocks list.

**Given** the browser goes offline
**When** the Admin continues editing
**Then** banner `msg:offline-editing` shows, editing continues, and Save draft, Fetch, Test connection and Publish are `aria-disabled` with that reason inline until reconnect, then pending changes save in order.

**Given** a save fails for another reason
**When** the failure returns
**Then** `msg:save-failed` shows inline beside Save draft with Retry, every change stays, and focus moves to the message.

**Given** the Admin leaves with unsaved changes
**When** they press Back (←) or navigate away
**Then** `msg:unsaved-changes` offers Save draft, Discard changes and Keep editing, focus on Keep editing in generic forms
**And** Back with no changes returns to the opening page directly.

### Story 3.6: Soft-lock a Draft and take over editing

As an Admin,
I want a Draft to be locked softly while a colleague edits it, with a safe take-over,
So that nobody's changes are silently overwritten.

**Requirements:** FR-19, UX-DR-250, AD-13, AR-19 (Block Drafts part), AD-13 edit locks

**Acceptance Criteria:**

**Given** Admin A has a Draft open
**When** Admin B opens the same Draft
**Then** B sees a read-only view, banner `msg:draft-locked` ("Maya Patel is editing this draft (since 10:42)...") with "Take over editing" and Close
**And** every B write attempt returns 423 `platform.edit_lock_lost` and changes nothing.

**Given** A's tab is idle or closed
**When** about 15 minutes pass without activity (the heartbeat tracks user activity) or the tab sends the release request (works with `sendBeacon`, token in body)
**Then** the lock `edit_lock:{ws}:block_draft:{id}` is released and B can open the Draft for editing.

**Given** B chooses "Take over editing"
**When** the server asks A's client to flush (`platform.edit_lock.flush_requested`, over the membership channel with a polling fallback via the heartbeat response)
**Then** the server waits for A's acknowledgement or a timeout, increments `lock_epoch` in PostgreSQL, B loads the post-flush revision, and A sees `msg:draft-taken-over` ("Alex Morgan took over editing at 10:58. Your changes were saved.") only if A's flush was acknowledged
**And** A's later writes with the old epoch receive 423 `platform.edit_lock_lost`.

**Given** A is offline during take-over
**When** the timeout elapses
**Then** B proceeds, A's unsaved edits are not applied and A sees the lost-lock notice on reconnect with no claim that changes were saved.

**Given** the lock holder and the taker have the same Admin identity on two tabs
**When** the second tab opens
**Then** it is treated as another editor and shown the same banner.

**Given** a lock take-over
**When** it completes
**Then** an audit event is recorded and the lock key, queue payload and channel name are prefixed with the workspace ID.

### Story 3.7: Choose the Data Source, Endpoint, parameters and refresh (step 2)

As an Admin,
I want to select a Data Source and Endpoint, bind parameters and set the refresh interval and default date range,
So that the block knows which request to make.

**Requirements:** FR-15, FR-11 (wizard side), FR-8 (shared-data badge, bindings), FR-17 (default date range), UX-DR-134, UX-DR-173, UX-DR-174, UX-DR-176 (no-Data-Source state), AR-10

**Acceptance Criteria:**

**Given** Step 2 opens and Data Sources exist
**When** the Admin opens the Data Source select
**Then** each option shows a health dot and the word Healthy, Degraded or Unreachable
**And** the Endpoint list shows method prefix plus path (e.g. `GET /api/v2/finance/revenue`)
**And** an Admin with `data_sources.manage` also sees "Edit data source".

**Given** the Admin selects an Endpoint with POST
**When** the form updates
**Then** the `msg:post-readonly` checkbox is required, and Fetch and Continue stay `aria-disabled` with that reason until it is ticked
**And** GET is the default.

**Given** the Endpoint has parameters
**When** the `parameters-table` renders
**Then** each row shows Name (mono), Binding (Fixed value, Date Range from/to, Block Period Selector start/end only when period behaviour is Own selector, or User context for user ID, email, group or a System-settings attribute) and Value
**And** bound rows show the resolved value in mono muted text ("from = 2026-01-01")
**And** User context rows carry the "user context" chip and cannot be changed by users
**And** an empty path parameter blocks Fetch and names the parameter.

**Given** no binding is a user-context binding
**When** the step renders
**Then** a "shared data" badge shows.

**Given** the Admin opens Refresh Interval
**When** the list renders
**Then** it lists the System-settings intervals (Live ~30 s, 1, 5, 15 or 60 minutes, daily)
**And** Live is disabled with `msg:live-not-supported` as its description when the Data Source's Live flag is off
**And** the Default date range (today, this week, this month, current year, custom) is selectable.

**Given** the Workspace has no Data Sources
**When** Step 2 opens
**Then** `msg:datasource-none` replaces the select; with `data_sources.manage` it offers "+ Register data source" in a new tab, without it the ask-an-admin text shows; Fetch and Continue are disabled with the reason; Paste sample JSON stays available (Story 3.8) but Publish will need a real Data Source (Story 3.18).

**Given** the Admin changes Data Source, Endpoint or parameters after a sample exists
**When** the change affects the request
**Then** the sample is marked out of date, the pinned `endpoint_revision_id` and `data_source_revision` are recorded in the Draft, and Continue requires a new or re-confirmed sample.

### Story 3.8: Paste sample JSON and inspect it with exact numbers

As an Admin,
I want to paste a sample response and see its structure, paths, types and examples,
So that I can start mapping before I can reach the real API.

**Requirements:** FR-21 (paste part), FR-22 (path display), AR-40, AD-33, AD-12, AR-16, AR-26, AR-46, C5, UX-DR-41, UX-DR-175, UX-DR-184, UX-DR-188, NFR-4, AR-54

**Acceptance Criteria:**

**Given** the Sample Response segmented control
**When** the Admin selects "Paste sample JSON"
**Then** a `json-textarea` shows and validation runs on paste and on blur, announced politely.

**Given** valid JSON of 412 bytes with 3 records at `data.monthly[]`
**When** it is validated server-side by the platform's lossless JSON decoder
**Then** `msg:json-valid` shows ("✓ Valid JSON · 3 records found at data.monthly[] · Pasted 09:14 · 412 bytes · 4 scalars, 1 array")
**And** number lexemes are kept as `DecimalLiteral` (`12345678901234567890.10` and `1.0` keep every digit and the trailing zero)
**And** `json_decode` is not used in Ingestion, RawStore, Mapping or Results (architecture test).

**Given** invalid JSON with an extra comma on line 4
**When** validated
**Then** `msg:json-invalid` shows with "Go to line 4" which moves the caret to that line and column, and Continue is disabled.

**Given** non-JSON text or input above the byte or depth limit (`pending_input` settings)
**When** pasted
**Then** it is rejected stating the limit, and nothing is stored.

**Given** a valid paste
**When** it is accepted
**Then** it is stored only in `draft_samples` encrypted with the workspace `data` key, `origin = pasted`, Draft-scoped, with a hard maximum TTL from a `pending_input` setting and a "contains personal data" acknowledgement, and never in the raw tier or a version
**And** the step 2 summary shows "Pasted 09:14" and the badge "Sample data · used for mapping and preview only" (`msg:sample-wizard`) is shown.

**Given** the sample is accepted
**When** the Admin opens the JSON tree (and "View JSON" later in Map Data)
**Then** it is a collapsible tree, with a table view for arrays, and each node shows its display path (`data.monthly[].revenue`), inferred type and example value
**And** stored paths are the RFC 9535 subset (`$.data.monthly[*].revenue`, member names and `[*]` only) and a conformance suite compares the evaluator with `symfony/json-path`.

**Given** a pasted sample
**When** the Admin tries to publish with it
**Then** it is never accepted as a production source (enforced in Story 3.18)
**And** the Draft is autosaved with the sample restorable; the sample is deleted on publish or discard.

### Story 3.9: Fetch a sample from the API

As an Admin,
I want to fetch a sample response from the configured Endpoint with the current parameters,
So that I map real data and see clear errors when the call fails.

**Requirements:** FR-21 (fetch part), FR-10 (errors), FR-13 (never truncated), AR-35, AD-28, AR-8, AR-45, AR-46, C5, UX-DR-135, UX-DR-175, UX-DR-176, UX-DR-184

**Acceptance Criteria:**

**Given** Step 2 has an Endpoint and all parameters
**When** the Admin chooses "Fetch from API" (default)
**Then** `POST /api/v1/operations` creates a `sample_fetch` Operation with `subject_revision` captured at enqueue and the request runs on `worker-connector` through `EgressGuard` and `FetchTransport`
**And** the result body reaches the Draft only as an encrypted Valkey blob with TTL handed to the Blocks `OperationHandler`, which writes `draft_samples` (`origin = fetched`)
**And** `msg:fetch-ok` shows ("✓ 200 · 312 ms · 3 records found at data.monthly[]") and the JSON tree (Story 3.8) appears.

**Given** a fetch returns while the Draft revision has moved
**When** the handler runs
**Then** the result is stored against its `sample_revision` and the Admin sees the sample belongs to earlier parameters.

**Given** the fetch fails
**When** the failure is one of host not allowlisted, blocked address, response too large, not JSON or generic failure
**Then** the `fetch-error-card` shows with `msg:host-not-allowlisted`, `msg:blocked-address`, `msg:response-too-large`, `msg:not-json` or `msg:fetch-failed` respectively, Retry, "Paste sample JSON instead", a "▸ Technical details" disclosure (status, request ID, host, reason) and "Copy request ID"
**And** focus moves to the card title
**And** a too-large response or too many pages is never truncated and no sample is stored.

**Given** an Admin triggers fetches faster than the per-membership limit (`pending_input`)
**When** the limit is hit
**Then** the API returns 429 with `retry_after` in the error envelope and the button shows the wait.

**Given** Operation results are visible only to the initiator
**When** another Admin queries the Operation
**Then** the API returns 404.

**Given** the browser is offline
**When** Fetch is activated
**Then** it is disabled with `msg:offline-editing` as its reason.

**Given** a fetch succeeds
**When** the Admin re-fetches with different parameters
**Then** the new `draft_samples` row replaces the old and `sample_revision` increments (the preview cache key changes, Story 3.10)
**And** the new sample is simply the current sample for mapping; comparing an existing mapping against it is covered in Story 3.13 (`msg:api-changed`).

**Given** the Operation expires or the platform timeout ceiling (`pending_input`) is hit
**When** the Admin returns
**Then** the Operation shows as expired with Retry, no partial sample is stored.

### Story 3.10: Render the sample through the Query Plan engine (live preview endpoint)

As an Admin,
I want the live preview to compute from my sample with the same engine that will run in production,
So that the preview cannot disagree with the published block.

**Requirements:** FR-18 (live preview with real sample data), FR-30 (Unavailable per slot, never 0), AR-15, AD-11, AD-12, AD-23, AR-30, AR-40, AR-27, AR-45, AR-55, C6, NFR-13, UX-DR-137 (sample-note), UX-DR-182 (preview column)

**Acceptance Criteria:**

**Given** a Draft with a sample and a Query Plan (`config.query_plan`, `"v": 1`) of extract then shape
**When** the client calls `POST /api/v1/blocks/drafts/{id}/preview` (debounced, `X-Background: 1`)
**Then** the server runs `QueryPlanEngine` behind the `QueryExecutor` port (`InProcessExecutor`) over the decrypted sample parsed by the lossless decoder and returns a render payload from the Block Type's Shaper
**And** the sample is cached per `(draft, sample_revision)` so repeated previews do not re-parse it.

**Given** the plan stages
**When** the engine runs
**Then** stage order is fixed extract, filter, row calc, group, aggregate, aggregate calc, sort, top-N, shape (only extract and shape are implemented here; others are Epic 5)
**And** arithmetic uses `BcMath\Number` at one internal scale (a `pending_input` setting) with rounding only at presentation
**And** the plan version `v` is stored and the engine runs every published `v` forever.

**Given** a mapped field is missing from the sample or has the wrong type
**When** the engine runs
**Then** that slot is `unavailable` with the reason (the Admin variant "Unavailable: total missing") and never `0`, and the other slots still render.

**Given** a run exceeds a guard (extracted rows, evaluations, CPU or wall time; limits are `pending_input` settings)
**When** it aborts
**Then** the API returns an error `mapping.guard_exceeded` and no partial result is returned.

**Given** the first-sprint spike for preview latency (C6)
**When** it is run against a representative sample on the CI hardware
**Then** the measured p95 and the decision (server preview confirmed, or fallback to a TypeScript evaluator checked by a shared conformance suite) are recorded and the target is a `pending_input` setting with no invented number
**And** the preview endpoint emits a duration metric `dashflow.mapping.preview_duration`.

**Given** the preview request is made for a Draft the Admin does not own and has not got open
**When** the lock is held by another Admin
**Then** a read-only viewer may request previews but cannot change the plan.

**Given** more than the per-membership preview limit (`pending_input`)
**When** requests arrive
**Then** 429 with `retry_after` is returned and the pane shows the last good preview.

**Given** a valid preview
**When** the pane renders in steps 1 to 3
**Then** it shows the real sample values with a "Sample data" label, and below the Block `msg:sample-note` ("Sample data only. Published blocks call GET /api/v2/finance/revenue live...").

### Story 3.11: Review field roles and choose the record path

As an Admin,
I want to see what each field means and choose which array holds the rows or which scalar to use,
So that auto-map starts from correct roles.

**Requirements:** FR-16, FR-22, FR-23 (role assignment), UX-DR-65, UX-DR-66, UX-DR-123, UX-DR-125, UX-DR-126, UX-DR-127, UX-DR-128, UX-DR-129, UX-DR-180, UX-DR-181, UX-DR-182, UX-DR-185, UX-DR-186, UX-DR-187, UX-DR-188, UX-DR-200, UX-DR-201, UX-DR-206, AR-16, UX-DR-38

**Acceptance Criteria:**

**Given** the Admin continues from step 2 with a sample
**When** Map Data opens
**Then** the left column shows the step 1 and 2 summaries, a sticky status bar, "1 · Roles" ("What does each field mean?"), "2 · Review block slots" and a collapsible "3 · Transforms" placeholder (empty until Epic 5)
**And** the sticky Live preview (384px) stays visible while the left column scrolls.

**Given** a sample whose fields are inferred
**When** the Roles section renders
**Then** each scalar shows a `scalar-card` (mono key, type chip, example, role chip with qualifier "Value · main", "Value · baseline", "Label · unit" or "Time · as-of") in rows of at most 4 (one per row under 640px)
**And** rows show a `roles-table` of at most 5 sample rows with a role chip above each column, a real table with `th scope`, in a focusable region "Sample rows, scrolls sideways" when it overflows
**And** the six roles are Label, Value, Dimension, Measure, Time and Filter/Category, and a role chip never shows only a colour.

**Given** a role chip
**When** the Admin opens it
**Then** it is a menu button named "Role for {field}: {role}" with `menuitemradio` items and a one-line description on focus
**And** after a change the T1 note is announced politely and focus returns to the chip
**And** a flagged chip adds ", possible wrong role".

**Given** the Dashflow heuristics suspect `revenue` is a Measure labelled Filter
**When** the `suggestion-row` shows "✦ revenue looks like a Measure — use as series?"
**Then** "Set as Measure" applies it and moves focus to the affected role chip, "Keep as Filter" dismisses it.

**Given** the response has more than one array or nesting deeper than 2 levels
**When** Map Data opens
**Then** "Rows from (record path)" shows a `radiogroup` of candidate arrays with row count and column count, "Suggested" on the one with most rows, and "looks like pagination, not data" on `meta.links[]`-like arrays
**And** the chosen record path is stored in the Draft for Auto-map (Story 3.12) to use
**And** the status bar reads "◐ Auto-mapped 6 of 9 · 3 low confidence — please review".

**Given** a deeply nested scalar
**When** the Admin opens the field tree
**Then** it is searchable and collapsible with type and example, a breadcrumb `nav` "Field path", "Use" per leaf and the footer "Selected: data.summary.kpis.total.amount · 284680 → $284,680"
**And** it is an APG tree (`role=tree`, `treeitem`, `aria-level`, `aria-expanded`, `aria-setsize`, `aria-posinset`) with arrow, Home/End and type-ahead keys, Enter on a leaf = Use, incompatible leaves `aria-disabled` with a reason.

**Given** no Low-confidence slots and no open suggestions, or the Admin chooses "Looks right"
**When** roles are accepted
**Then** the table collapses to the `roles-summary-line` ("Roles: total Value · previous_total Value ... — Edit roles") and "Roles accepted. Roles table collapsed." is announced if focus was in the table
**And** it re-expands on "Edit roles" or when a role is flagged, without moving focus.

**Given** the Admin changes a role
**When** the change is saved
**Then** the field's role is updated in the Draft's role map (the single source of truth) with an inline Undo, and Confirmed or Overridden slots are never changed; re-filling unconfirmed slots from the new role is delivered and tested in Story 3.12.

**Given** the viewport is under 640px
**When** Map Data opens
**Then** it is one column, the preview becomes a collapsible "Preview" bar and `msg:map-small-screen` shows once, never after Dismiss, never blocking.

**Given** the suggestion catalog (Datasets)
**When** the Admin picks a record path
**Then** the Dataset `(workspace, endpoint, record_path)` and its typed fields are upserted as a suggestion for new Drafts only, and the per-Draft role map stays authoritative.

**Given** a field in the response tree has an inferred type
**When** the tree or the role review lists it
**Then** a small type chip shows number (Measure blue), text (Label grey), date (Time violet), array (Filter orange) or object, using the role-family colours, and the type is also stated in text so colour is never the only signal (UX-DR-38)

### Story 3.12: Auto-map the KPI slots and review the checklist

As an Admin,
I want Dashflow to fill the KPI card's slots with a confidence for each, and a checklist I can confirm,
So that I only spend time on the slots that need a human.

**Requirements:** FR-16, FR-23, FR-32 (kpi-card slots), UX-DR-55, UX-DR-56, UX-DR-57, UX-DR-58, UX-DR-59, UX-DR-60, UX-DR-61, UX-DR-63, UX-DR-64, UX-DR-123, UX-DR-124, UX-DR-181, UX-DR-183, UX-DR-189, UX-DR-190, UX-DR-191, UX-DR-205, UX-DR-255, AR-15

**Acceptance Criteria:**

**Given** a sample with `total`, `previous_total`, `month`, `revenue`, `region`, `status`
**When** Auto-map runs
**Then** the Headline value takes the single Value not named `previous_*`, the other Value is recorded as "Value · baseline" and shown to the Admin but is not mapped to a slot in this epic (computing a comparison from it is Epic 5)
**And** every filled slot has a confidence High (unique match by role and type), Medium (heuristic) or Low (ambiguous) with a reason caption for Medium and Low (e.g. "2 Values; picked the one not named previous_*")
**And** the mapping is written into `config.query_plan` and the preview updates.

**Given** the slot checklist
**When** it renders
**Then** slots are grouped under label-caps headers HEADER, KPI and META, required slots show a red `*`, and each row is a `group` labelled by name and status ("Headline value, required, auto-mapped, high confidence")
**And** Auto-mapped High, Medium, Low, Confirmed, Overridden, Optional-empty, Required-missing and Type-mismatch each render per the state matrix with the confidence word and meter (`aria-hidden` meter and dot; badge reads "Confidence: Medium")
**And** Low-confidence rows are pinned to the top, with pinning and sorting computed only at step load and on "↻ Re-run auto-map".

**Given** an Auto-mapped High slot
**When** the Admin does nothing
**Then** it counts as Confirmed
**And** "Confirm" on a Medium or Low slot marks it Confirmed with "Confirmed by you" visible (not hover-only), and Confirm is never bound to Enter on the row.

**Given** the radiogroup filter "All (n) · Needs review (n) · Missing (n)"
**When** the Admin confirms a row under "Needs review"
**Then** the row stays while focus is in the list and "Headline value confirmed. 1 slot still needs review." is announced politely.

**Given** the status bar
**When** counts change
**Then** it reads "Auto-mapped 6 of 9 · 2 confirmed · 1 required missing" with the aria-hidden `status-meter`, announced politely only when the required-missing or needs-review count changes
**And** the "Sample data" badge appears once here.

**Given** a required slot is missing, a Low-confidence slot is unconfirmed or a type mismatch exists
**When** the Admin activates "Continue to Preview →"
**Then** the button is `aria-disabled` with the reason inline (`msg:required-slot-missing`: "Map Chart series to continue. Optional slots can stay empty." style, listing several), focus moves to the first blocking row, and several blockers add an error summary of links focused on activation
**And** a Medium optional slot does not block but stays in Needs review.

**Given** an Admin re-runs auto-map
**When** it finishes
**Then** Confirmed and Overridden slots are never changed and "Re-run auto-map" never discards an override.

**Given** a Required-missing slot with a known candidate
**When** the row renders
**Then** it shows the suggestion ("Use revenue as headline") with 3px error left rule, error-soft background and the caption in secondary text.

**Given** the Admin changes the Block type in Step 1 after mapping
**When** it applies
**Then** slots with the same name and type are kept and the rest are flagged unmapped, nothing is silently dropped (FR-23; only `kpi-card` exists now, so this is covered by a fixture contract with a second test-only schema).

### Story 3.13: Override a slot, pick a field and fix mismatches

As an Admin,
I want to change which field feeds a slot, undo it, and be told when a type does not fit,
So that I stay in control of the mapping.

**Requirements:** FR-23, FR-16 (override updates role, Undo), FR-30 (mapping validation), UX-DR-60, UX-DR-61, UX-DR-63, UX-DR-180, UX-DR-190, UX-DR-192, UX-DR-193, UX-DR-203, UX-DR-204, UX-DR-205, UX-DR-255, UX-DR-142

**Acceptance Criteria:**

**Given** a slot row
**When** the Admin activates "Change field ⌄"
**Then** a search combobox opens with compatible fields first and "n fields match" announced politely
**And** incompatible fields are `aria-disabled` with the reason in `aria-describedby` ("Text field can't be a numeric headline. Use it as the currency format below.")
**And** the picker also offers "Static text" (e.g. "Total revenue"); Text Template and Calculated Field entries are not offered until Epic 5
**And** Esc closes it and returns focus to the opener.

**Given** the Admin picks `revenue` for the Headline value and it was labelled Filter/Category
**When** the override applies
**Then** the row shows "Overridden" with the note "revenue is now a Measure (was Filter/Category). Undo" and the field's role updates
**And** Undo restores the slot and the role, and other unconfirmed slots fed by the old role re-fill.

**Given** a text field is chosen for a numeric slot
**When** the choice is attempted
**Then** it is rejected, the slot shows `slot-pill-error` "Type mismatch" with the reason ("data.currency is text ("USD"). Headline value needs a number."), compatible fields, and Continue is blocked.

**Given** a field such as the string "2026-01" feeding a Time slot
**When** "Treat as …" is offered and chosen (e.g. as a month)
**Then** the effective type is stored in the Draft's field overrides and the preview updates.

**Given** the Admin re-fetches the sample (or the Draft opens against a changed sample) and a mapped path is no longer present
**When** the shape is compared
**Then** `msg:api-changed` shows ("API response changed: field total no longer present → Headline: Unavailable"), affected slots become Required-missing and Continue is blocked with the reason
**And** a matching field is suggested with `msg:api-match-found` and is never applied silently.

**Given** a role change or Block type change causes a mismatch
**When** the slot renders
**Then** the mismatch reason, compatible fields and the Treat as option appear without discarding the field the Admin chose.

**Given** an Admin without `blocks.edit`
**When** they open the picker
**Then** it is not rendered (read-only view of the mapping only).

**Given** the Admin opens the field picker for a Slot
**When** the 320 px popover appears
**Then** it has a search box at the top, a tree with indent guides, type chips and mono example values, disabled fields in muted text with the reason in a caption, and the picked leaf shown as the active listbox option (UX-DR-142)
**And** it is fully operable by keyboard, with focus returning to the Slot row on close

### Story 3.14: Change a slot from the preview with hotspots

As an Admin,
I want to click a region of the live preview to change that slot's field,
So that I can map in the place where I see the result.

**Requirements:** FR-16 (preview click shortcut), FR-23, FR-18, UX-DR-136, UX-DR-137, UX-DR-199, UX-DR-182, UX-DR-184, NFR-7, UX-DR-74

**Acceptance Criteria:**

**Given** a mapped KPI card in the Map Data preview
**When** it renders
**Then** each mapped region has a faint dashed outline, hover shows an accent outline and the tooltip "Click to change field · Headline value ← total", and keyboard focus shows the standard focus ring (never the accent outline) with the same tooltip
**And** each is a button named "{Slot}: {field}, {rendered value}. Change field" (e.g. "Headline value: total, $284,680. Change field").

**Given** a hotspot is activated by click, Enter or Space
**When** it fires
**Then** the page scrolls to the slot row, expands it (`aria-expanded`), opens the field picker with focus in the search, and Esc returns focus to the hotspot.

**Given** the Headline value slot is required and unmapped
**When** the preview renders
**Then** the hatched `missing-slot-placeholder` button named "Headline value, required, not mapped. Choose field" shows the text "Headline value required · No Value mapped · click to choose" and opens the same picker.

**Given** a slot without a region (Data-as-of, status badge)
**When** the Admin wants to change it
**Then** it is reached from the checklist only.

**Given** the preview Block's chrome (refresh, minimize, ⋯)
**When** the Admin activates them
**Then** they are inert.

**Given** the preview region
**When** a keyboard user tabs in
**Then** the region is named "Live preview, sample data" and is preceded by a skip link "Skip to slot checklist".

**Given** the preview shows the Block
**When** it renders under the Block
**Then** the `source-legend` "WHERE EACH PART COMES FROM" lists rows of role chip, field (mono), "→" and the rendered result (e.g. "Value total → $284,680"), followed by `msg:sample-note`.

**Given** the live preview shows hotspot regions
**When** a region is idle, hovered with a pointer, or focused by keyboard
**Then** idle shows a 1 px dashed default border, hover shows a 1.5 px solid accent border on accent-wash for pointer users only, and focus shows the standard focus ring, never the low-contrast accent outline as the focus indicator (UX-DR-74)

### Story 3.15: Set presentation rules for the KPI card slots

As an Admin,
I want to format the headline and set direction, thresholds, labels and empty display,
So that the block reads correctly and highlights what matters.

**Requirements:** FR-29, FR-30 (empty/null display), UX-DR-62, UX-DR-133, UX-DR-194, UX-DR-54, NFR-11, AD-33

**Acceptance Criteria:**

**Given** a mapped slot
**When** the Admin expands its row (one at a time; the editor shows Format, Emphasis, Direction in three columns, one column under 640px)
**Then** Format offers number, integer, decimal places, thousands separator, percent, currency (code and symbol), compact (1.2k, 3.4M), date and time pattern, relative time, duration and units suffix
**And** a live example shows ("284680 → $284,680") computed with the same format descriptor path as the Renderer.

**Given** Emphasis
**When** the Admin chooses headline, primary, secondary or badge, size and weight
**Then** only one slot per Block can be the headline ("one headline per block") and a second is refused with the reason.

**Given** Direction
**When** the Admin picks "↑ Higher is better" or "↓ Lower is better"
**Then** the note "Colors the comparison: up = green" shows, the arrow reflects the actual direction of change while the colour shows good or bad, and Lower is better adds "· better" or "· worse" and hidden judgement text.

**Given** the Admin adds threshold bands
**When** they use "+ Add band"
**Then** each `threshold-band-row` has an order numeral, a condition (≥, ≤, between), a colour select (Success, Warning, Error, Info, Neutral shown with swatch and name), an icon select, a label and a remove button, stacking under 640px
**And** the first matching band wins, top to bottom
**And** a band without an icon or label is rejected (colour is never the only signal) and the accent colour is not offered.

**Given** Label settings
**When** the Admin sets text, prefix and suffix (e.g. "vs last month")
**Then** they render in the KPI card and a null value shows the configured empty display (e.g. "—") for non-headline slots; a null headline shows Unavailable, never the empty display or 0.

**Given** an invalid format (negative decimal places, unknown currency code)
**When** it is entered
**Then** an inline error shows, Continue to Preview is blocked and the preview keeps its last valid state.

**Given** a locale
**When** the Admin changes the Workspace locale in the test fixture
**Then** the preview formats with that locale through `Intl` and the stored presentation holds no locale-formatted strings.

### Story 3.16: Preview on every device and state (step 4)

As an Admin,
I want to see the block full-size on Desktop, Tablet and Mobile, in a sample dashboard grid and in each state,
So that I can check it before I validate.

**Requirements:** FR-18, FR-30, NFR-9, UX-DR-177, UX-DR-184, UX-DR-170, UX-DR-171, UX-DR-146, UX-DR-137

**Acceptance Criteria:**

**Given** the Admin continues from Map Data
**When** Step 4 opens
**Then** the Block shows full size with Desktop, Tablet and Mobile toggles and also inside a sample dashboard grid (12 columns, 14px gap) at the configured size
**And** the "Sample data" label shows whenever the data is a sample, fetched or pasted.

**Given** the state switcher
**When** the Admin selects Normal, Loading, Empty, Stale, Unavailable or Minimized
**Then** the Block renders that state from the same Shaper and Renderer as production
**And** Unavailable shows `msg:unavailable-user`, not "$0".

**Given** validation warnings exist (e.g. an optional Low-confidence slot cleared, a null in the sample for an optional slot)
**When** the step lists them
**Then** each has an "Acknowledge" checkbox and Continue stays `aria-disabled` with the reason until every unresolved warning is resolved or acknowledged
**And** acknowledgements are stored in `draft_ui_state` and cleared when the underlying warning changes.

**Given** the Admin goes back and changes a mapping
**When** they return to Step 4
**Then** the preview reflects the change and any acknowledgement for a changed warning is reset.

**Given** the Mobile toggle
**When** selected
**Then** the Block renders at mobile width without horizontal page scroll and its content scrolls within the Block if it overflows.

### Story 3.17: Fetch and preview as a user

As an Admin with the `data.preview_as_user` permission,
I want to fetch and preview the block as a chosen user,
So that I can check per-user data without exposing it.

**Requirements:** FR-18 (preview as), FR-21 (fetch as user), FR-8, AR-37, AD-30, AR-35, AD-28, AR-10, AR-25, UX-DR-175, NFR-4

**Acceptance Criteria:**

**Given** the Endpoint has user-context bindings and the Admin has `data.preview_as_user`
**When** they choose "Fetch as user" and pick a user (or a member of a group)
**Then** a `fetch_as_user` Operation resolves the user's bound values server-side and the Admin sees the sample for that user in the tree
**And** the user's attribute values are never sent to the browser.

**Given** the Admin lacks `data.preview_as_user`
**When** they open Step 2 or Step 4
**Then** "Fetch as user" and "Preview as" are disabled with the reason, and an API attempt returns 403 and an audited security event.

**Given** a fetch as user succeeds
**When** the result is returned
**Then** the response exists only as the Operation's encrypted Valkey blob with a short TTL, visible only to the initiator
**And** it is never written to `draft_samples` or the shared Draft; only the value-free shape (paths and types) is kept
**And** an audit event is written for each use and the target-user notification is emitted as an event for the notifications epic (Epic 8) to deliver.

**Given** a user whose bound attribute is missing
**When** the fetch is attempted
**Then** no request is made, the item is `unavailable` with reason `access.context_missing` and the Admin sees why.

**Given** the Admin previews as a user
**When** Step 4 renders
**Then** the preview is computed from the ephemeral blob and labelled with the chosen user's name and "Sample data"
**And** after the Operation expires the preview reverts to the shared sample and says so.

**Given** the Admin edits their own attributes or permissions
**When** they attempt it (out of scope here)
**Then** they cannot (AD-30), and previewing as themselves is allowed only through this same audited path.

### Story 3.18: Validate the block against the real API (step 5)

As an Admin,
I want the mapping checked against the live Endpoint before I can publish,
So that a sample, even a pasted one, is never the only evidence the block works.

**Requirements:** FR-20 (validation gate), FR-30 (mapping validation), AR-17, AR-35, AR-46, AD-13, AD-28, AD-11, C5, UX-DR-178, UX-DR-170, UX-DR-89, UX-DR-251, UX-DR-255

**Acceptance Criteria:**

**Given** Step 5 with a real Data Source, Endpoint and mapped Draft at `revision` 12
**When** the Admin chooses "Validate against the live API"
**Then** a `publish_validation` Operation is created with `subject_revision = 12`, fetches the real response through the connector and runs the plan with `QueryPlanEngine` against `config.fields`
**And** the summary shows the version to create ("1.0"), the validation result and `msg:version-safe`.

**Given** the live response matches every mapped path and type
**When** the report is stored
**Then** a `ValidationReport` bound to revision 12 with status `passed` is saved and an audit event "validated" is recorded
**And** an autosave to revision 13 marks the report stale and disables Publish again.

**Given** the live response lacks a mapped path or has a wrong type
**When** validated
**Then** Publish stays disabled, the report lists the mismatching paths, and Save draft remains available.

**Given** the live API cannot be reached
**When** validation runs
**Then** `msg:live-shape-unreachable` shows ("We couldn't reach the API to check this block. Save a draft and try again.") and Publish is blocked.

**Given** the only sample is a pasted one, or no real Data Source is set
**When** the Admin opens Step 5
**Then** Publish is disabled with a reason stating a real Endpoint must validate, and a pasted sample is never accepted as proof.

**Given** an Endpoint with `requires_user_context`
**When** validation runs
**Then** a negative check fetches without the attribute and must be refused by the platform (no fetch, `access.context_missing`), and an Endpoint that could be published unbound fails validation.

**Given** the Draft has strict-schema errors (a required slot unmapped, bad presentation, plan fails type check)
**When** validation starts
**Then** it fails fast with the list before any API call is made.

**Given** a report whose subject revision moved while the Operation ran
**When** the result is handled
**Then** it is stored as `stale` and the Admin is told to validate again.

**Given** an Admin triggers validation faster than the per-membership limit (`pending_input`)
**When** the limit is hit
**Then** 429 with `retry_after` is returned.

### Story 3.19: Publish block v1.0

As an Admin with the `blocks.publish` permission,
I want to publish the validated block as version 1.0 to the Block Library,
So that users can later add it to their dashboards.

**Requirements:** FR-20, FR-19, FR-38 (Published list, minimal), AR-17, AR-18, AD-13, AR-36, AR-25, AR-7, AR-3 (RegisterAccessSubject), C5, UX-DR-170, UX-DR-178 (first publish), UX-DR-255

**Acceptance Criteria:**

**Given** a passed `ValidationReport` bound to the current revision and an Admin with `blocks.publish`
**When** they activate "Publish block"
**Then** `POST /api/v1/blocks/drafts/{id}/publish` runs Tx1 `UPDATE ... SET state='prepared' WHERE state='draft' AND revision = report.revision`, emits `blocks.version.prepared`, and freezes `config` (`schema_version`, `type_key`, `contract_version`, `fields` snapshot with the roles, `query_plan`, `presentation`, `chrome`, sizes, period behaviour, refresh interval) as the immutable version
**And** Tx2 (`ActivateVersion`) sets `state='published'`, switches `current_version_id`, writes the audit event and emits `blocks.version.published`
**And** Results precompute of hot keys is not performed in this epic: Epic 4 subscribes to `blocks.version.prepared`; here activation follows immediately.

**Given** an Admin without `blocks.publish`
**When** they view or activate Publish
**Then** it is disabled with `msg:perm-publish`, a direct API call returns 403 and an audited denial, and Save draft still works.

**Given** the report is stale or missing
**When** Publish is attempted
**Then** the API returns 409 and nothing changes.

**Given** an autosave arrives after Tx1
**When** it reaches the server
**Then** it gets 409 `blocks.draft_published` and the immutable version is untouched.

**Given** a version left `prepared` beyond `precompute_timeout` (a `pending_input` setting)
**When** the maintenance job runs
**Then** it calls `ActivateVersion` and the version becomes `published`
**And** a database trigger rejects every UPDATE except draft content edits, draft to prepared and prepared to published.

**Given** the block is published
**When** the transaction commits
**Then** the Block is registered as an Access subject in the same transaction with the default access "All users" (selected groups and access changes are Epic 6), no grant keyed by version exists, and the Draft's `draft_samples` are deleted
**And** the pinned Endpoint revision is recorded on the version.

**Given** publish completes
**When** the wizard responds
**Then** `msg:publish-success` ("Revenue overview v1.0 is live in the Block Library under Finance.") shows as a toast, and Admin > Published blocks opens with the new row highlighted and focused, listing name, category, type, version, owner, last updated and status "Published"
**And** a second Draft for the same Block can later be created for v1.1 (publish impact, restore and unpublish are Epic 6).

**Given** an Admin double-clicks Publish or two tabs publish
**When** both requests arrive
**Then** exactly one succeeds and the other receives 409 without a duplicate version or duplicate audit event.

**Given** v1.0 is published for the first time
**When** the transaction commits
**Then** an expand-only migration has created `access_subjects` (workspace-scoped, RLS, `subject_type`, `subject_id`, `visible`, `mode` default `all`) and the `Access\Contracts\RegisterAccessSubject` contract, and the Block's row is written by it in the same transaction
**And** `access_grants` and the group-restricted mode are created in Epic 6 on top of this table

## Epic 4: Live Personal Dashboards

Users can see published Blocks on personal dashboards with live, trustworthy data, and add, remove, move and resize them without drag-and-drop being required. Results are computed off the read path, so dashboards never wait on source APIs, and every state (loading, empty, error, Stale, Unavailable, paused, access removed, unpublished) is shown honestly.

### Story 4.1: Resolve every Block's period in one place

As an Admin previewing a Block and as a User viewing it,
I want the period for a Block to be resolved by a single shared rule,
So that the preview, validation and the dashboard always show the same date window.

**Requirements:** FR-35, AR-34, AD-27, UX-DR-165

**Acceptance Criteria:**

**Given** a Block with period behaviour Follow dashboard, a dashboard Date Range and a Block Default date range
**When** `Platform\Period\PeriodResolver` resolves the item
**Then** it returns `ResolvedPeriod{token, start, end, comparison?}` from the dashboard Date Range, half-open, in workspace-local dates
**And** with no dashboard Date Range it uses the Block's Default date range.

**Given** a Block with period behaviour Own selector and a stored Block Period Selector choice
**When** the resolver runs
**Then** the user's choice wins over the dashboard Date Range (precedence 1, then 2, then 3 of FR-35)
**And** with no stored choice the Block's Default date range is the initial value.

**Given** a Block with period behaviour Not date-filtered
**When** the resolver runs with any selection or Date Range
**Then** it returns no period (all inputs ignored).

**Given** a selection naming a preset the Block has not enabled, or a client-supplied absolute date
**When** the resolver runs
**Then** the input is rejected with `422` and the error envelope, never silently accepted (only Block-enabled presets are accepted; client dates are never accepted).

**Given** a workspace timezone different from the user's timezone
**When** the resolver computes "This month" near a month boundary
**Then** the window uses the workspace timezone, and the user timezone is used only for display
**And** a conformance test table of presets, timezones and boundaries passes in CI.

**Given** the Epic 3 wizard preview and validation
**When** they need a period
**Then** they call the resolver with the Block default and no second implementation of precedence exists (architecture test).

### Story 4.2: Compute Block Results when payload changes

As a User,
I want each Block's display data computed ahead of time from the latest source data,
So that dashboards open fast and never wait on a source API.

**Requirements:** FR-60, FR-61, NFR-1, AR-32, AR-33, AR-40, AD-25, AD-26, AD-17, FR-30

**Acceptance Criteria:**

**Given** a published kpi-card Block Version with a current payload
**When** `ingestion.payload.changed` is consumed by the `worker-compute` role
**Then** `Results` writes a versioned `BlockResult` under key `res:{ws}:{block_version}:{primary_fk}:{compute_ctx}` in the Valkey `cache` store with a TTL
**And** the DTO carries `generation_id`, `payload_seq`, `data_as_of`, `last_success_at`, `state`, `stale`/`refreshing` flags, `slot_states`, a locale-free user-free `render_payload` and `result_etag = sha256(key, generation_id, v)`.

**Given** `compute_ctx = sha256(resolved period, comparison window, workspace tz, compute-affecting settings revision, engine version, shaper contract version)`
**When** any one of those inputs changes
**Then** a different key is used and the old result is not served for the new context.

**Given** two compute jobs for the same key finish out of order
**When** the later-started job (lower `payload_seq`) writes last
**Then** the Lua compare-and-set on `payload_seq` rejects it and the higher `payload_seq` result remains
**And** race tests for concurrent writers, duplicate delivery and Valkey `cache` flush pass.

**Given** a mapped field is missing or mistyped in the payload
**When** compute runs
**Then** that slot is `unavailable` in `slot_states` and no 0, NaN or blank is emitted
**And** the result `state` follows the precedence access_removed > unpublished > error > pending > unavailable > empty > ok, with `partial` derived and `loading` never stored.

**Given** a payload whose numbers carry more precision than a float holds
**When** compute and shaping run
**Then** measures are emitted as decimal strings from the lossless tree with format descriptors, and `json_decode` is not used in Results (architecture test).

**Given** `sync_targets.payload_seq` is ahead of the stored result (a lost event)
**When** the maintenance sweep runs
**Then** it re-enqueues compute for that target and the result converges without manual action.

**Given** the job payload on the queue
**When** it is inspected
**Then** it carries IDs only and is HMAC-signed and verified before unserialize (AR-24)
**And** the Valkey `cache` and `queue` stores are separate connections with `cache` entries all carrying TTLs.

**Given** a changed payload no longer contains a mapped path, or a mapped value changes type, compared with the Block Version's field snapshot (`config.fields`)
**When** the compute job runs the drift check
**Then** the affected Slots are stored as `unavailable` in `slot_states` with a reason code and the rest of the Block still renders (never zero, FR-30)
**And** a `results.mapping.slot_failed` outbox event is emitted with the Block, version, Slot and a consecutive-failure count, which Epic 8 turns into incidents and notifications

### Story 4.3: Judge freshness and keep viewed data hot

As a User,
I want a Block to be marked Stale only when its source has genuinely not been checked in time,
So that I can trust the values and the "data as of" time I see.

**Requirements:** FR-60, FR-61, FR-62, NFR-1, AR-11, AR-22, AD-15, C12

**Acceptance Criteria:**

**Given** a Block Version with Refresh Interval I and `last_success_at` older than 2 x I
**When** `Results\Contracts\Freshness` evaluates it (the only place the rule exists)
**Then** the result is `stale` and returns `stale_at`, and within 2 x I it is not stale
**And** all timestamps come from the database clock.

**Given** a successful check that found unchanged data (304 Not Modified or equal hash)
**When** Freshness evaluates it
**Then** the Block stays fresh because `last_success_at` advanced, while `data_as_of` still shows the real age of the data.

**Given** a Block with a mapped Data-as-of slot
**When** `data_as_of` is computed
**Then** it is the mapped slot's value, otherwise the payload `first_observed_at`.

**Given** a comparison Block fetched in one sync group
**When** Freshness evaluates it
**Then** `last_success_at` is the older of the two and a result is never built by mixing generations (only complete `sync_generations` are used).

**Given** a User opens a Block (a result read for a viewing Block Version)
**When** Results calls `Ingestion\Contracts\Subscribe`
**Then** a `sync_subscriptions` row is registered or touched (throttled) with the Block Version's refresh interval copied, `compute_ctx`, `resolved_period`, `last_access_at` and `hot_until = last_access_at + hot_window`, where `hot_window` comes from a `pending_input` setting
**And** an unviewed target stays cold and gets only a health probe.

**Given** a Workspace budget (max hot keys, fetch rate per Data Source, new cold keys per membership per hour; all `pending_input`)
**When** subscribing would exceed it
**Then** the subscription is refused for hot refresh, the Block shows its last result with Stale marking on schedule, and the refusal is recorded without failing the read.

**Given** the source API is down
**When** results are read
**Then** no code on the read path calls the source API (test with the egress client stubbed to fail).

### Story 4.4: Open my Overview and see my Blocks' frames

As a User,
I want my Overview dashboard to open instantly with my Blocks laid out,
So that I always land somewhere useful, on any device.

**Requirements:** FR-49, FR-56, FR-36, NFR-2, AR-20, AR-27, AD-14, AD-20, UX-DR-50, UX-DR-149, UX-DR-236, UX-DR-237, UX-DR-148, UX-DR-86

**Acceptance Criteria:**

**Given** a Workspace member opens the User area for the first time
**When** the Overview is requested
**Then** an Overview dashboard (`is_overview`) is created for that membership and Workspace with `revision` 0, and it is empty in this epic (Template seeding is out of scope for this story)
**And** `dashboards`, `dashboard_items` (unique `(dashboard_id, block_id)`) are created by this story's expand-only migration with `workspace_id` first and RLS.

**Given** the Inertia page for the dashboard
**When** it loads
**Then** its props are only the shell and `{dashboard_id, revision}`, and layout and items come from `GET /api/v1/dashboards/{id}` with clamped geometry, `state`, `refresh_interval`, `is_live`, a User-safe Block DTO and `next_free_slot` hints.

**Given** a dashboard with items
**When** it renders
**Then** the shell appears at once and each Block renders as a `block-card` `section` labelled by its h2 title (surface-card, 1px border-default, radius lg), on a 12-column grid with 14px gap and the width and height presets of UX-DR-149
**And** presets set grid height and not a clipping box, so overflowing content scrolls inside a focusable region labelled with the Block title.

**Given** an item whose state is `access_removed` or `unpublished`
**When** the dashboard renders
**Then** the Block shows `msg:block-access-removed` or `msg:block-unpublished` with a Remove action and no data (rendered from the item state contract; granting and unpublishing UIs are other epics).

**Given** a dashboard with no items
**When** it renders
**Then** it shows `msg:dashboard-empty` with a "+ Add block" action.

**Given** the layout request fails
**When** the page renders
**Then** the shell renders with `msg:dashboard-load-failed` and Retry, no partial or default layout is shown, and nothing is saved.

**Given** a user in Workspace A requests a dashboard owned by another membership or Workspace B
**When** the request is made
**Then** the response is `404` (not `403`) and nothing is disclosed.

**Given** a dashboard saved on one device
**When** the same user opens it on another device
**Then** the same layout is restored (FR-56 server persistence)
**And** the desktop page header shows the title, the dashboard switcher and Date Range slots and "✎ Edit layout" (their behaviour arrives in later stories).

### Story 4.5: See live Block values without waiting on the source

As a User,
I want my Blocks to show current values and honest states,
So that I can trust what I read at a glance.

**Requirements:** FR-36, FR-37, FR-61, FR-62, FR-30, NFR-1, NFR-7, AR-22, AR-27, AR-28, AR-40, AD-15, AD-20, AD-33, UX-DR-53, UX-DR-54, UX-DR-233, UX-DR-234, UX-DR-235, UX-DR-240, UX-DR-239

**Acceptance Criteria:**

**Given** a dashboard with N items
**When** the client calls `GET /api/v1/dashboards/{id}/results?range=<preset>`
**Then** hits return immediately with `data_as_of`, `last_success_at`, `stale_at`, `result_etag` and misses return `pending`
**And** the call never contacts a source API and never blocks on compute.

**Given** a miss with a current payload
**When** the results endpoint runs
**Then** it enqueues compute, and a miss with no current payload enqueues an interactive sync
**And** a per-item endpoint may compute inline under a single-flight lock so concurrent requests produce one compute.

**Given** a user whose access was removed between layout load and results read
**When** results are serialized
**Then** access is re-checked per item and that item returns `access_removed` with no data.

**Given** a cold load
**When** results are `pending`
**Then** each Block shows its real header and its own skeleton, and later refreshes update in place (a skeleton only when no prior data exists)
**And** the client caches results in memory by `result_etag`.

**Given** a kpi-card Block
**When** it renders
**Then** the value, delta with ↗/↘, icon tile and "vs last month" label follow UX-DR-53, formatted client-side with `Intl` (user locale, then workspace default, then `en`)
**And** the delta carries hidden text stating direction and judgement ("Up 14.2% from last year, favourable"), flat reads "No change", and Lower is better adds "· better"/"· worse".

**Given** a Block's result is `empty`
**When** it renders
**Then** it shows `msg:block-empty` centred; a result in `error` shows `msg:block-error` with a Retry link that re-requests the Block, and Admins get Technical details in About this block.

**Given** a mapped field is missing
**When** the Block renders
**Then** the slot shows `msg:unavailable-user` with ⚠ and never 0, NaN or blank, About this block and Admins show `msg:unavailable-admin`, and the rest of the Block renders
**And** a table or list cell with a missing value shows "—" with a "Partial data" header badge.

**Given** a result with `stale` true
**When** it renders
**Then** last values are text-muted with a clock icon and `msg:stale` ("Stale: last data 10:42", or "yesterday 22:10" or "3 Oct 22:10" when not today), colour is never the only signal
**And** it recovers automatically on the next fresh result, and Stale/Unavailable are announced once, aggregated and debounced about 2 s as `msg:stale-aggregate`.

**Given** an API value containing `<img src=x onerror=alert(1)>` in every text slot (XSS fixture)
**When** the Block renders
**Then** it appears as literal text, no HTML is interpreted, and lint bans `v-html`, `innerHTML` and formatter strings in renderers.

**Given** a Block that is refreshed while a control inside it has focus
**When** values update
**Then** elements are updated in place with stable keys and focus is not lost.

### Story 4.6: Use Block chrome: refresh, minimize, About this block, footer

As a User,
I want each Block's header controls to work,
So that I can refresh it, collapse it and see where its data comes from.

**Requirements:** FR-34, FR-55, FR-56, FR-60, FR-62, FR-37, NFR-7, AR-20, UX-DR-51, UX-DR-231, UX-DR-232, UX-DR-236, UX-DR-240, UX-DR-98

**Acceptance Criteria:**

**Given** a Block configured with header, subtitle, refresh, minimize and footer
**When** it renders
**Then** it shows icon tile, title, subtitle and controls named "Refresh Revenue overview", "Minimize Revenue overview" and "More actions for Revenue overview" with 28px hit areas
**And** controls the Admin disabled (Allow refresh, Allow minimize, Show footer action) are absent.

**Given** a User presses Refresh
**When** the request is sent
**Then** only this Block is refreshed, a spinner shows in the icon, values update in place and focus stays on the control
**And** it works while updates are paused.

**Given** a User refreshes repeatedly within the manual-refresh window (value from a `pending_input` setting)
**When** the limit is hit
**Then** the server returns `429`, the control is `aria-disabled` and described by `msg:refresh-limited` for the rest of the window, and no source call is made.

**Given** a User presses Minimize
**When** `SetMinimized` is applied with `expected_revision`
**Then** the Block shows its header only, the button becomes Expand, the state persists per Block Instance on the server and survives reload
**And** a stale `expected_revision` returns `409` and the client reloads the layout and retries once.

**Given** the ⋯ menu
**When** it opens
**Then** it offers Refresh, About this block, Expanded view (only if configured), View as table (every chart Block, with accessible text alternative) and "Remove from dashboard"
**And** for a Mandatory item Remove is disabled with `msg:locked`.

**Given** About this block
**When** it opens
**Then** it shows description, Data Source, Refresh Interval, Data-as-of Time with full date and zone ("Data as of 5 Oct 2026 10:40 BST"), version and period behaviour in words, and for a Stale Block the Data-as-of Time is also shown inline
**And** Admins additionally see technical details and `msg:unavailable-admin`; Users never see technical details.

**Given** a footer action that is an external URL template `https://erp.example.com/approvals?user={user.email}`
**When** it renders
**Then** `{user.*}` tokens are resolved in the browser from the session profile and percent-encoded per position, scheme and host stay literal, the link opens in a new tab with ↗
**And** a `javascript:` or other non-http(s) URL is never rendered as a link, and an in-app route is accepted.

**Given** a footer action configured as an expanded view
**When** the User opens it
**Then** the Block shows full-size with a paginated table of all its rows, with `msg:expanded-empty` or `msg:expanded-error` states.

**Given** a Block has a footer action configured
**When** the Block renders
**Then** the footer link is shown centred in accent-ink, caption weight 600 (for example "View all approvals →"), and its target follows FR-34 (UX-DR-98)

### Story 4.7: Browse the Add-blocks Panel

As a User,
I want to open a panel that lists the Blocks available to me,
So that I can find what to put on my dashboard.

**Requirements:** FR-52, NFR-7, NFR-9, AR-27, UX-DR-67, UX-DR-68, UX-DR-69, UX-DR-152, UX-DR-213, UX-DR-214, UX-DR-215, UX-DR-217, UX-DR-221, UX-DR-222, UX-DR-84, UX-DR-42

**Acceptance Criteria:**

**Given** a User on a dashboard
**When** they choose "+ Add block"
**Then** a right-side drawer titled "Add blocks — Build a dashboard that works for you" opens (392px, non-modal `complementary` "Add blocks"), the dashboard stays visible and usable, and focus moves to search
**And** a "Back to dashboard" skip link goes to the first Block, or "+ Add block" when empty.

**Given** published Blocks in the Workspace
**When** the panel lists them
**Then** every Workspace member sees every published Block (group access is a later epic) as a `list` of `listitem`s, each with a 64x46 aria-hidden schematic thumbnail generated from the version's field snapshot and presentation, name, one-line description, category tag and "New" where applicable
**And** the count reads "8 available blocks" and "2 of 8 blocks" when filtered, announced politely and debounced about 500 ms.

**Given** the search box (placeholder "Search blocks…", accessible name "Search blocks")
**When** the User types
**Then** names, descriptions and tags match with highlights, category chips show exactly the categories that have at least one available Block plus All, and Filter offers type and Data Source
**And** when no Block matches `msg:drawer-empty-search` appears with Clear search.

**Given** a Block already on the dashboard
**When** the list renders
**Then** it shows "✓ Added" as persistent status text (not a control) plus a separate "× Remove" button, and never reorders
**And** a Mandatory item appears in a separate "On your dashboard" group with a lock and `msg:locked` ("required by your admin and cannot be removed"), is excluded from the count, is still searchable and has no Remove.

**Given** no Block is published
**When** the panel opens
**Then** it shows `msg:drawer-none`; while loading it shows row skeletons with thumbnail placeholders; on load failure it shows `msg:drawer-load-failed` with Retry and search and chips disabled with that reason.

**Given** the footer
**When** it renders
**Then** it shows `msg:drawer-footer` and "Done", which closes the panel
**And** there are no drag grips, no multi-add and no "Request a block" button anywhere in the panel.

**Given** the viewport is 1024px or wider
**When** the panel opens
**Then** it pushes the dashboard narrower and collapses the sidebar to the icon rail, and closing restores the sidebar and the unchanged layout
**And** below 1024px it is a modal `overlay-sheet` with focus trapped, background inert, and a 44px Close.

**Given** a keyboard User
**When** they tab through a row
**Then** each row has a disclosure button (name, `aria-expanded`, `aria-controls`, description as `aria-describedby`) and action buttons with visible focus rings, and "+ Add" is named "Add {Block} to dashboard".

**Given** a Block was published recently enough to count as new under the `pending_input` "New" rule
**When** it appears in the Add-blocks Panel
**Then** a "New" badge (accent background, on-accent text) is shown beside its category tag (UX-DR-42)

### Story 4.8: Preview a Block with generated sample values

As a User,
I want to preview a Block inside the panel before adding it,
So that I know what it looks like without waking up any data source.

**Requirements:** FR-52, C14, NFR-7, UX-DR-216, UX-DR-221, UX-DR-215

**Acceptance Criteria:**

**Given** a row in the panel
**When** the User clicks the row body or presses Enter or Space on the disclosure button
**Then** an inline accordion preview opens (one open at a time, scroll position kept) showing the full description, the real Block renderer at about 340px, and the meta "Data source: HR analytics API · Refreshes hourly · v1.0 · Default size 4 columns × Medium"
**And** the region is named "Preview of {Block} with sample data".

**Given** a preview is open
**When** values are generated
**Then** they are synthesized client- or server-side from the Block Version's field snapshot and presentation only, `msg:sample-drawer` is always shown under the preview, and the Block is inert
**And** a test with the egress client and Ingestion stubbed to fail shows no source fetch, no stored values and no cold-target creation or `sync_subscriptions` row from browsing previews.

**Given** a Block whose slots need a type the snapshot cannot sample (for example a missing format)
**When** the preview renders
**Then** it falls back to a labelled placeholder value, never real data and never 0.

**Given** an open preview
**When** the User presses "+ Add to dashboard"
**Then** the preview shows the same "+ Add" control as the list row, rendered aria-disabled with the reason "Adding is not enabled yet" until Story 4.9 wires the AddItem command; Story 4.9 then collapses the row to "✓ Added".

**Given** an open preview
**When** the User presses Esc
**Then** the preview collapses; a second Esc closes the drawer
**And** "Close preview" does the same as the first Esc.

**Given** a generated sample for the same Block Version
**When** previewed twice
**Then** the values are identical (deterministic from the snapshot) so screenshots and tests are stable.

### Story 4.9: Add and remove Blocks with automatic placement

As a User,
I want "+ Add" to put a Block at the first free spot without rearranging mine,
So that building a dashboard is fast and predictable.

**Requirements:** FR-53, FR-56, FR-59, FR-37, AR-20, AR-21, AD-14, UX-DR-215, UX-DR-218, UX-DR-219, UX-DR-220, UX-DR-228, UX-DR-239, UX-DR-68, UX-DR-72, UX-DR-73

**Acceptance Criteria:**

**Given** the `Platform\Layout` kernel (12 columns)
**When** placement, clamp and collision are exercised
**Then** the kernel is a pure shared module (Dashboards, Templates and `next_free_slot`) with `fill` mode for user adds, deterministic server-side clamp that never pushes neighbours, and a conformance suite in CI.

**Given** a dashboard at `revision` R
**When** the User presses "+ Add" and the client sends `AddItem{block_id, expected_revision: R}`
**Then** the server places it at the first suitable free position at the Block's default size on the saved full-width layout, bumps `revision`, emits `dashboards.dashboard.changed` and returns `201` with the item
**And** existing Blocks never move or resize.

**Given** the same `AddItem` is delivered twice
**When** the second request arrives
**Then** it is idempotent (no duplicate row) and the same Block can never be added twice to one dashboard (`unique (dashboard_id, block_id)`).

**Given** the add succeeds
**When** the UI updates
**Then** the Block appears at once with the `just-added-outline` and "Just added" for about 3 s (persisting about 2 s after the drawer closes), no auto-scroll, focus stays on the row
**And** `msg:toast-add` appears top right of the dashboard area, never over the drawer, offering "Show me" and "Undo".

**Given** there is no free space in view
**When** the Block is added
**Then** it goes below, no scroll occurs, and `msg:toast-add-below` with "Show me ↓" and an edge pill (`msg:edge-pill`) are shown until scrolled to or Show me
**And** in single-column layouts the Block is added at the end.

**Given** the toast
**When** the User chooses "Show me"
**Then** the view scrolls, the outline pulses (static outline under reduced motion) and focus moves to the Block title; on mobile the sheet closes first.

**Given** the toast has an action
**When** time passes
**Then** it stays at least 10 s (FR-59), pauses on hover, and never auto-dismisses while focus is in the toast or the drawer.

**Given** a User presses "× Remove" in the panel or "Remove from dashboard" in ⋯
**When** `RemoveItem{block_id, expected_revision}` is sent
**Then** the Block is removed at once with `msg:toast-remove`, no confirmation dialog, `revision` bumped and the event emitted
**And** from the drawer focus stays on the row (now "+ Add"); from ⋯ focus goes to the next Block title, else the previous, else "+ Add block", announced politely.

**Given** a Mandatory item
**When** `RemoveItem` is sent
**Then** the server rejects it with `403` and the UI shows the reason `msg:locked`.

**Given** the server rejects an optimistic add or remove (stale `expected_revision` `409`, or the Block lost access)
**When** the response arrives
**Then** the change rolls back in place with `msg:toast-rollback` (`role="alert"`, not auto-dismissed) and focus stays on the element
**And** an add for an inaccessible or unpublished Block returns `404`/`422` and nothing is placed.

**Given** the user hovers or focuses "+ Add" or "+ Add to dashboard" with a pointer
**When** the hint appears
**Then** the free-space hint (accent-wash fill, 2 px dashed accent-border, copy `msg:free-space-hint`) shows where the next Block will go and is never shown on touch (UX-DR-72)
**And** when a newly added Block lands out of the visible viewport, an edge pill "↓ 1 new block below" (`msg:edge-pill`) appears at the edge of the dashboard viewport (UX-DR-73)

### Story 4.10: Undo adds and removes

As a User,
I want to undo my last add or remove at any time,
So that a wrong click is never costly.

**Requirements:** FR-53, FR-59, AR-20, AD-14, UX-DR-219, UX-DR-220, UX-DR-227

**Acceptance Criteria:**

**Given** a Block just added
**When** the User chooses "Undo" in the toast
**Then** the client sends `RemoveItem` and the Block disappears and focus returns sensibly.

**Given** a Block just removed
**When** the User chooses "Undo" in the toast
**Then** the client sends `RestoreItem` with the item's remembered geometry, minimized state and period selection, the server places it using the Layout kernel (original spot if free, otherwise first free) and bumps `revision`
**And** focus returns to the restored Block's title.

**Given** the toast has gone
**When** the User presses Ctrl+Z (⌘Z) anywhere on the dashboard outside text fields
**Then** the last add or remove is reversed with no time limit, repeated presses walk back through the client history, which lasts while the dashboard is open
**And** it works with single-key shortcuts turned off.

**Given** the User presses Ctrl+Z while a text field has focus
**When** the key is handled
**Then** the dashboard undo is ignored and the field's own undo runs.

**Given** the removed Block's source Block was unpublished or the user lost access meanwhile
**When** `RestoreItem` is sent
**Then** the server returns an error and the client shows `msg:toast-rollback`, with the history entry marked unavailable.

**Given** another device changed the dashboard meanwhile
**When** `RestoreItem` carries a stale `expected_revision`
**Then** the server returns `409`, the client refreshes the layout and retries once, and a second failure shows `msg:toast-rollback`.

### Story 4.11: Move and resize Blocks in Edit Layout Mode

As a User,
I want to drag and resize my Blocks and save the layout,
So that my dashboard fits the way I work.

**Requirements:** FR-54, FR-56, FR-17, NFR-7, AR-20, AR-21, AR-28, AD-14, UX-DR-52, UX-DR-223, UX-DR-225, UX-DR-226, UX-DR-227, UX-DR-228, UX-DR-230, UX-DR-119

**Acceptance Criteria:**

**Given** a User on the dashboard
**When** they choose "✎ Edit layout"
**Then** it becomes "Done" beside Undo and Redo (`edit-layout-toolbar`), every movable Block gets a move handle (whole header) and the hint "Drag to move · corner to resize · M to move with the keyboard"
**And** drag and resize are available only in this mode, and no Block can be dragged from the Add-blocks Panel.

**Given** a Block with Allow resize set
**When** Edit Layout Mode is on
**Then** a 24x24 `resize-handle` shows at bottom-right and resize snaps to the width presets (3, 4, 6, 8, 12) and height presets within the Block's minimum and maximum allowed sizes
**And** a Block without Allow resize shows no handle.

**Given** a drag or resize overlaps other Blocks
**When** the move resolves
**Then** overlapped Blocks are pushed down and nothing compacts upward; Mandatory Blocks can move and resize but not be removed.

**Given** the User presses "Done"
**When** `MoveResizeItems{items[], expected_revision}` is sent
**Then** the server validates and clamps geometry with the Layout kernel, bumps `revision`, emits `dashboards.dashboard.changed`, and focus returns to "✎ Edit layout"
**And** the command never creates or deletes items (a payload naming an unknown or extra item returns `422`).

**Given** the save fails
**When** Done is pressed
**Then** Edit Layout Mode stays open with `msg:layout-save-failed` and the local arrangement is kept.

**Given** Edit Layout Mode
**When** the User presses Undo or Ctrl+Shift+Z (⌘⇧Z) Redo
**Then** each step of moves and resizes is fully reversed or re-applied from client history.

**Given** the saved layout `revision` has advanced on another device
**When** Done is pressed with a stale `expected_revision`
**Then** the server returns `409` and the UI offers to reload with `msg:layout-save-failed` rather than overwriting.

**Given** the shared grid library (grid-layout-plus 1.1, or the gridstack 14 fallback per the spike)
**When** blocks update
**Then** keys are stable and updates are in place, and narrow-width reflow never writes positions back.

**Given** Edit Layout Mode is on
**When** the toolbar renders
**Then** it shows Undo and Redo secondary icon buttons with text labels, then a primary "Done" button, and the hint "Drag to move · corner to resize · M to move with the keyboard" in secondary text (UX-DR-119)

### Story 4.12: Move, resize, reorder and shortcut without drag-and-drop

As a keyboard or screen-reader User,
I want to move and resize Blocks and add or remove them without dragging,
So that I can do everything the pointer can.

**Requirements:** FR-59, FR-54, FR-58, NFR-7, AR-20, AR-21, AD-14, UX-DR-224, UX-DR-225, UX-DR-226, UX-DR-239, UX-DR-156, UX-DR-265

**Acceptance Criteria:**

**Given** Edit Layout Mode and a focused "Move {Block}" button (described "Press M or Space to pick up")
**When** the User presses M or Space
**Then** the Block is picked up, arrows move it, Shift+arrows resize it within allowed sizes, Enter drops it and Esc cancels the move and restores the Block.

**Given** a move
**When** it lands
**Then** it is announced politely against neighbours ("Revenue vs Expense, row 3, column 7, right of Revenue overview; Project status moved down").

**Given** the ⋯ menu on a Block
**When** the User chooses Move up, Move down, Make wider or Make narrower
**Then** the Block moves or resizes by one step, with the same Layout kernel rules, in or outside Edit Layout Mode followed by a save with `expected_revision`
**And** Make wider is disabled at the maximum allowed size with the reason.

**Given** Edit Layout Mode with nothing picked up and no menu open
**When** the User presses Esc once and then Esc again
**Then** the first Esc only cancels any move, the second exits the mode and saves; one press never does both.

**Given** a mobile or single-column layout
**When** the User reorders
**Then** reorder only is available (drag or Move up and Move down, no free resize) and `SetCompactOrder{order[], expected_revision}` stores `compact_order` while saved desktop positions never change
**And** new items are appended to `compact_order` when one exists.

**Given** the "Keyboard shortcuts" setting in Profile & settings is off
**When** the User presses `/`, A or M
**Then** nothing is triggered, while the menu and button routes still work; with it on (default) they work
**And** the setting is stored per user and WCAG 2.1.4 is met.

**Given** a screen reader
**When** the User adds, removes or moves Blocks
**Then** the changes are announced in a polite live region and the keyboard-only path completes add, remove, move and resize end to end (test).

**Given** a user who does not use a pointer
**When** they edit the layout
**Then** every drag action has a keyboard path: move with M or Space plus arrow keys or the ⋯ menu, resize with Shift plus arrow keys or Make wider/narrower, and add without dragging (UX-DR-265, FR-59)

### Story 4.13: Receive live updates through Reverb with a polling fallback

As a User,
I want my dashboard to update itself when new data arrives and recover when my connection drops,
So that I do not have to reload the page to see fresh values.

**Requirements:** FR-60, FR-61, NFR-1, AR-23, AR-24, AR-27, AD-16, AD-17, AD-20, UX-DR-234

**Acceptance Criteria:**

**Given** `dashboards.dashboard.changed` events
**When** Results consumes them
**Then** its own `block_viewers` read model (membership, block) is updated so fan-out recipients are known without querying Dashboards tables.

**Given** a new result is written for a Block
**When** the outbox relays `results.result.updated{block_id, result_etag}`
**Then** it is broadcast on `workspace.{ws}.block.{block}` for shared-data Blocks and on `workspace.{ws}.membership.{m}` for bound-data Blocks
**And** the event carries only IDs and `result_etag`, never values.

**Given** a client subscribed to its channels
**When** an event arrives with a `result_etag` it does not hold
**Then** it refetches only the changed items over `/api/v1` and updates them in place.

**Given** a User who lost Block access or membership
**When** their socket is next authorized or an access change occurs
**Then** channel auth re-checks the database, the socket is forced to re-authorize and the subscription is refused with `403`.

**Given** Reverb is unreachable or the Valkey `queue` store is down
**When** the client cannot connect
**Then** it falls back to polling `/api/v1` with the same semantics (etag compare, background header `X-Background: 1` so polling never extends the idle session)
**And** the polling interval comes from a `pending_input` setting.

**Given** the connection is lost
**When** it persists
**Then** a banner `msg:reconnecting` is announced once, Blocks keep last values and go Stale on schedule, and on return `msg:back-online` is announced once and Blocks refresh in place
**And** no offline editing is offered (layout commands are disabled with the reason while offline).

**Given** a Block's results arrive at different times
**When** the Block renders
**Then** it shows one result generation only and never mixes results fetched at different times.

### Story 4.14: Pause live updates and see the oldest Data-as-of

As a User,
I want to pause automatic refreshing and see how fresh my dashboard is,
So that numbers do not change under me while I am reading or presenting.

**Requirements:** FR-60, FR-62, NFR-7, UX-DR-120, UX-DR-238, UX-DR-234

**Acceptance Criteria:**

**Given** a dashboard where any Block refreshes automatically
**When** it renders
**Then** the header shows the `live-updates-toggle` ("Pause live updates", `aria-pressed`) next to Date Range and the oldest Data-as-of Time of the Live Blocks
**And** when no Block is Live the toggle is not shown.

**Given** the User presses "Pause live updates"
**When** pause is on
**Then** no automatic refresh or push-triggered refetch is applied, the header shows `msg:paused` ("Paused · data as of 10:42" with the oldest Data-as-of Time), the button reads "Resume live updates" and values do not change
**And** refresh button, Date Range and Block Period Selector changes still fetch.

**Given** the User presses "Resume live updates"
**When** it applies
**Then** paused Blocks refresh immediately and the header returns to the freshness line
**And** pause lasts until resumed or the dashboard is left; reopening starts live.

**Given** updates arrive
**When** the freshness line changes
**Then** routine refreshes are never announced and values update with no animated tickers or chart transitions.

**Given** the shortest interval Live (about 30 s)
**When** a Block offers it
**Then** it is offered only if the Admin marked the Data Source as supporting it and the Block's `is_live` is derived from that.

**Given** the dashboard has Live Blocks with differing Data-as-of Times
**When** the header is computed
**Then** it shows the oldest one (from the results response header field) and Stale Blocks count into it.

### Story 4.15: Set a dashboard Date Range and Block period selectors

As a User,
I want to choose a Date Range for my dashboard and a period for individual Blocks,
So that I see the time window I care about, remembered on every device.

**Requirements:** FR-35, FR-51, FR-55, FR-56, NFR-7, AR-34, AR-20, AD-14, AD-27, UX-DR-84, UX-DR-116, UX-DR-164, UX-DR-165

**Acceptance Criteria:**

**Given** the Date Range button in the header
**When** the User opens it
**Then** a popover lists Today, This week, This month, Current year and Custom with a two-month `date-range-calendar` (one month below 640px) and Apply, using the APG date grid keys (arrows day/week, Page Up/Down month, Home/End week ends)
**And** Enter sets start then end and announces "May 1 to May 31 selected".

**Given** Custom with only one date chosen
**When** the User views Apply
**Then** it is disabled with the reason until both dates are set; Esc returns focus to the button.

**Given** the User applies a range
**When** `SetDateRange{range, expected_revision}` is sent
**Then** it is saved per dashboard on the server and restored on any device, `revision` is bumped, and only Follow-dashboard Blocks refetch with the resolved period (via PeriodResolver)
**And** affected Blocks show last values with a header spinner (skeleton only without prior data), nothing moves, and focus returns to the button.

**Given** an Own-selector Block
**When** it renders
**Then** it shows a compact period dropdown (top right of the body) with only the Block-enabled presets, and choosing one sends `SetPeriodSelection{block_id, selection, expected_revision}`
**And** the choice persists across reload and the dashboard Date Range change never resets it.

**Given** a Not-date-filtered Block
**When** the range or any period changes
**Then** it shows no selector and ignores all of them.

**Given** a Block whose period differs from the dashboard Date Range
**When** it renders
**Then** the subtitle states it ("Financial performance · This year") and About this block describes the behaviour in words.

**Given** a request names a preset the Block has not enabled or a raw client date
**When** the command is validated
**Then** it returns `422` and nothing is stored.

### Story 4.16: Manage my dashboards and switch between them

As a User,
I want to create, rename, duplicate, delete and switch between my dashboards,
So that I can keep different views for different jobs.

**Requirements:** FR-49, FR-51, FR-56, AR-20, AD-14, NFR-7, UX-DR-163, UX-DR-166, UX-DR-84

**Acceptance Criteria:**

**Given** the dashboard switcher in the header
**When** it opens
**Then** the popover lists Overview first, then My dashboards in order with a check on the current one, plus "+ New dashboard" and "Manage dashboards"
**And** choosing one loads it in place and closes the Add-blocks Panel.

**Given** My dashboards in the User area
**When** it renders
**Then** a `data-table` lists the user's dashboards with Rename, Duplicate and Delete in the row or dashboard ⋯, and loading, empty and failure states follow the generic list pattern
**And** it shows only the user's own dashboards in the active Workspace.

**Given** the User creates a blank dashboard
**When** `CreateDashboard{name}` is sent
**Then** an empty dashboard is created and opened with `msg:dashboard-empty`; an empty or over-long name returns `422` with a field error.

**Given** the User duplicates a dashboard
**When** `DuplicateDashboard` runs
**Then** a new dashboard is created with the same items, positions, sizes, minimized states and Date Range, an independent revision, and Mandatory flags preserved
**And** items the user can no longer use are omitted and a notice names how many.

**Given** the User renames a dashboard
**When** `RenameDashboard` runs
**Then** the new name is saved and appears in the switcher.

**Given** the User deletes a non-Overview dashboard
**When** they confirm the destructive dialog
**Then** `DeleteDashboard` removes it and the user lands on Overview
**And** Overview's Delete is disabled with `msg:overview-delete` (and `DeleteDashboard` for Overview returns `422` if called directly).

**Given** "Reset to template" and "Create from Template"
**When** they would appear
**Then** they are out of scope for this story (the Templates gallery and reset are a later epic); this story adds no Template entry points.

**Given** a user in another Workspace
**When** they request these dashboards
**Then** nothing is disclosed (`404`).

### Story 4.17: Use the dashboard on any screen size

As a User on a tablet or phone,
I want the dashboard to adapt to my screen,
So that I can read and rearrange it anywhere.

**Requirements:** FR-58, NFR-7, NFR-8, NFR-9, UX-DR-147, UX-DR-148, UX-DR-150, UX-DR-151, UX-DR-152, UX-DR-154, UX-DR-155, UX-DR-156, UX-DR-157

**Acceptance Criteria:**

**Given** the viewport width
**When** the app shell renders
**Then** it matches the breakpoints of UX-DR-147: 1440px and wider full 232px sidebar; 1280-1439px sidebar; 1024-1279px 64px icon rail with ☰ opening the full sidebar as a sheet; 768-1023px icon rail plus ☰ sheet; 640-767px top bar with ☰ and "+ Add", sidebar as a sheet; below 640px the same in one column
**And** the desktop top bar is 64px with search, notifications, settings gear and the area primary action.

**Given** the content area is about 720-1099px wide
**When** the dashboard renders
**Then** the grid keeps 12 columns, 3-column Blocks stay 4-up and wider Blocks render at 6 columns in the same reading order, display only, saved positions never change.

**Given** the content area is below about 720px
**When** the dashboard renders
**Then** Blocks render one column in the user's order, KPI cards grow to fit, and no position is written back.

**Given** the Add-blocks Panel is open at a viewport of 1024-1231px
**When** content is under 720px
**Then** the dashboard shows single-column in user order, still pushed, the just-added highlight is visible in that column
**And** below 1024px the panel is a full-height `overlay-sheet`, with Done and 44px Close.

**Given** a phone
**When** the dashboard header renders
**Then** the dashboard switcher, Date Range and Pause live updates are compact controls in a header row, all targets are 44px, "× Remove" is always shown beside "✓ Added", hover affordances have tap equivalents and no free-space hint is shown
**And** the panel can stay open in Edit Layout Mode and still places in the first free slot.

**Given** 320 CSS px (400% zoom at 1280px)
**When** the dashboard is used
**Then** there is no page horizontal scroll; only a wide Table Block scrolls sideways inside a focusable region labelled for it (reflow 1.4.10 test at 320px)
**And** 200% on 1440px behaves as the tablet row.

**Given** at most one sub-bar and the top bar are sticky
**When** the viewport is under 480 CSS px tall
**Then** only the top bar is sticky, and each sticky layer sets scroll-padding equal to its height so a focused element is never hidden beneath it.

**Given** the latest two versions of Chrome, Edge, Firefox and Safari on desktop and mobile
**When** the cross-browser smoke suite runs
**Then** dashboard open, add, remove, edit layout and Date Range pass.

## Epic 5: Rich Blocks and Governed Calculations

Admins can shape data with governed, declarative transforms, calculated fields, text templates and comparisons, and publish all 11 Block Types with accessible faces. Developers can add a Block Type as a package with no change to the wizard, mapper or dashboards.

### Story 5.1: Filter, sort and top-N transforms in the Map Data step

As an Admin,
I want to add filter, sort and top-N steps to a Block's data,
So that the Block shows only the rows I care about, in the order I choose.

**Requirements:** FR-24, AR-15, AD-11, UX-DR-131, UX-DR-198, UX-DR-205

**Acceptance Criteria:**

**Given** an Admin is on Map Data with a Sample Response of 36 rows and the collapsible "3 · Transforms" section open
**When** the Admin chooses "+ Add step" and picks Filter, then sets "Filter field" = status, "Filter operator" = is, "Filter value" = Approved
**Then** a `transform-row` with stage label FILTER shows "Keep rows where status is Approved" and the row counts "36 → N rows"
**And** the live preview re-renders at once from the new plan and the step is stored in `block_versions.config.query_plan` (no script, no free-form code field exists)

**Given** a Block with a Sort step, a Filter step and a Top-N step added in any order
**When** the Admin views the section and when the plan runs
**Then** the rows are displayed and executed in the fixed order filter, sort, top-N (never by insertion order) and "+ Add step" places a new step at its stage position with no drag reorder control
**And** the server `QueryPlanEngine` (one engine for preview, validation and runtime) returns the same rows as the preview for the same plan

**Given** a Sort step "Sort by revenue descending" followed by Top-N "Keep the top 5 rows"
**When** the plan runs on rows with revenue 10, 50, 30, 70, 20, 60, 40
**Then** the result rows are 70, 60, 50, 40, 30 in that order
**And** the sort is stable for equal values (ties keep source order)

**Given** a Filter step names a field that is not in the response (e.g. "status isn't in the response")
**When** the Admin edits the row
**Then** the row shows the error state (error-soft, 3px error left rule, caption message) and "Continue to Preview" is `aria-disabled` with the reason inline (msg:required-slot-missing or the first blocker) and not only in a tooltip
**And** activating the disabled button moves focus to the first blocking row

**Given** a Filter step on a numeric field with a non-numeric value, or a Sort on a field of unsortable type
**When** the Admin enters the value
**Then** inline validation names the problem and the step blocks Continue and Publish validation
**And** a missing or mistyped field at run time makes the dependent Slot `unavailable`, never 0

**Given** each control of a transform row
**When** a screen-reader user tabs through it
**Then** each control has an accessible name ("Filter field", "Filter operator", "Filter value", "Sort field", "Top-N count") and a one-line description via `aria-describedby` on focus and hover
**And** the remove button is named "Remove step: Keep rows where status is Approved" and removing a step focuses the next step or "+ Add step"

**Given** the viewport is below 640px
**When** the Transforms section renders
**Then** each `transform-row` stacks as stage label, wrapping sentence, then counts and remove

**Given** an API payload exceeds the engine guard limits (rows or size, values from `pending_input` settings)
**When** the plan runs
**Then** the engine aborts with a guard error and never truncates the rows silently

### Story 5.2: Group and aggregate transforms and "Aggregate rows by..." flattening

As an Admin,
I want to group rows and aggregate measures, including when a nested array is flattened,
So that charts and tables show summaries such as revenue by region.

**Requirements:** FR-24, FR-32, AR-15, AR-40, AD-11, AD-33, UX-DR-130, UX-DR-131, UX-DR-198, UX-DR-202, UX-DR-205

**Acceptance Criteria:**

**Given** the Admin adds Group "Group by region" and Aggregate "SUM of revenue", then Sort descending and Top-N 5
**When** the plan runs on the Sample Response
**Then** the rows are grouped by region, revenue is summed per region, sorted descending and cut to the top 5, in the FR-24 order
**And** the Aggregate row offers exactly SUM, COUNT, AVG, MIN and MAX, and the row counts show "36 → 12 rows"

**Given** an Aggregate step on a text field
**When** the Admin selects SUM
**Then** the row shows the error "Can't SUM a text field" and blocks Continue

**Given** the Admin flattens a nested array (`data.regions[].stores[]`) for a per-row chart Slot
**When** the flattening is applied
**Then** parent fields become columns (e.g. `region.name`) and an `aggregate-control` appears above the sample table with the default "Sum sales.amount by sales.month" and the count "36 rows → 12 rows"
**And** the group-by fields and aggregation are editable there and write the equivalent Transform step, which is also editable in the Transforms section (both stay in sync)

**Given** a flattened array is used by a per-row chart Slot and no aggregation is set
**When** the Admin tries to continue
**Then** Continue is blocked (UX-DR-205 condition 5) until an aggregation is set

**Given** numeric per-row chart Slots (FR-32) declare a default aggregation
**When** rows are grouped and the Admin has not chosen one
**Then** the Slot's declared default aggregation is applied and shown in the Slot row

**Given** measures with more than 15 significant digits (e.g. 12345678901234567.89)
**When** they are summed in the Aggregate step
**Then** the result is exact (BcMath at the one internal scale from `pending_input`) and is sent to the browser as a decimal string with no float rounding (AD-33)

**Given** a Group step where grouping produces more groups than the guard limit
**When** the plan runs
**Then** the engine aborts with a guard error rather than truncating

### Story 5.3: Calculated fields and ratio-safe aggregation

As an Admin,
I want to define calculated fields in a restricted expression language and mark ratios,
So that derived numbers such as margin or completion rate are correct at row level and after grouping.

**Requirements:** FR-24, FR-25, FR-28, FR-30, AR-15, AR-16, AD-11, AD-12, AD-33, UX-DR-131, UX-DR-193, UX-DR-198, UX-DR-205

**Acceptance Criteria:**

**Given** the Admin adds a Calculated field step "[margin] = (revenue - cost) / revenue"
**When** the expression is typed
**Then** it is validated inline against the grammar shared with Text Templates and URL templates, stored as AST plus source text (AD-12), and the live preview shows the computed column
**And** the supported grammar is arithmetic (`+ - * /`), comparisons, `IF`, safe division, `ROUND`, `ABS`, `PCT_CHANGE(current, previous)` and date parts; any other function name is rejected

**Given** a row-level calculated field is placed before Group and an aggregate-level one after Aggregate
**When** the plan runs
**Then** row-level fields are evaluated at the "row calc" stage (before group) and aggregate-level fields at the "aggregate calc" stage (after aggregate), each at its fixed position in the FR-24 order

**Given** an expression with a syntax error or unknown field (e.g. `ROUND(revenue,`)
**When** the Admin edits it
**Then** the row shows an error with the position of the problem and blocks Continue and Publish validation
**And** a payload cannot cause script execution: no eval, no user functions, no loops

**Given** a division where the divisor is 0 or null
**When** the expression runs
**Then** the safe division returns null (shown per the Slot's empty display, e.g. "—") and never 0 or an error that breaks the Block
**And** `PCT_CHANGE(current, previous)` with previous = 0 or null also returns null

**Given** `PCT_CHANGE(284680, 249195)` with lossless decimal inputs
**When** evaluated
**Then** the result is 14.2% when presented with 1 decimal (value 14.2403..., rounding per presentation only)

**Given** the Admin marks a Calculated field as a Ratio and declares numerator `done` and denominator `total`
**When** rows 600/1,000 and 450/500 are aggregated by group
**Then** the aggregated ratio is 70.0% (sums numerators and denominators separately), not the 75.0% from averaging 60% and 90%
**And** the aggregate-control note reads "ratio: sums numerator and denominator"

**Given** a Ratio field is declared without both numerator and denominator, or with a non-numeric one
**When** the Admin saves the step
**Then** validation fails with an inline message and the step blocks Continue

**Given** the Field picker on any numeric Slot
**When** the Admin opens it
**Then** "Calculated Field" is offered beside Static text and Text Template, with example `PCT_CHANGE(total, previous_total)`, and choosing it opens the expression editor with focus in its input; Esc returns focus to the opener

**Given** the required field of an expression is missing from a later fetch
**When** the Block runs
**Then** the dependent Slot is `unavailable` ("Unavailable: <field> missing"), never zero

### Story 5.4: Text templates, tokens and footer action target

As an Admin,
I want any text Slot to take a Text Template with formatted tokens,
So that titles, labels, captions and links can mix literal text with live values.

**Requirements:** FR-26, FR-32, AR-16, AR-40, AD-12, AD-33, NFR-11, UX-DR-193, UX-DR-196

**Acceptance Criteria:**

**Given** the Admin opens the Field picker on a text Slot (Title, Subtitle, label, list subtitle, caption)
**When** the Admin chooses "Text Template"
**Then** an editor with a token helper opens, accepts literal text mixed with tokens `{field}`, `{field|currency}`, `{ROW_COUNT}`, `{period.start|MMM d}`, `{period.end|MMM d, yyyy}` and `{user.name}`, and previews the result live

**Given** the templates "{ROW_COUNT} requests need your attention", "{category} · {days} days", "{done}/{total} tasks" and "{period.start|MMM d} – {period.end|MMM d, yyyy}"
**When** rendered with sample data and period 2026-05-01 to 2026-05-31
**Then** they render as "12 requests need your attention" style output, and "May 1 – May 31, 2026" respectively, using the viewer's locale for dates and numbers (NFR-11, `Intl`)

**Given** a template refers to an unknown field or an unknown format name
**When** the Admin edits it
**Then** inline validation names the token and the template blocks Continue

**Given** a token resolves to null or a missing field at run time
**When** the Block renders
**Then** the empty display of the Slot is used for that token (e.g. "—") and never "0" or "undefined"

**Given** a template containing `{user.name}`
**When** the Block is computed and cached
**Then** the server result contains the token unresolved (`render_payload` is user-free) and the browser resolves `{user.name}` from the session profile
**And** a different user viewing the same cached result sees their own name

**Given** the Footer action Slot is enabled ("Show footer action") with Target "External link" and URL template `https://erp.example.com/approvals?user={user.email}`
**When** it renders for user `a&b@x.com`
**Then** the token is percent-encoded in position (`a%26b%40x.com`) with the scheme and host staying fixed literals, and the link shows the ↗ cue

**Given** a footer URL template whose scheme is not http(s) (e.g. `javascript:alert(1)`) or whose host is built from a token
**When** the Admin enters it
**Then** msg:url-invalid is shown and Continue is blocked

**Given** the Footer action Target "Expanded view"
**When** the Admin picks it
**Then** the label (a Text Template, e.g. "View all approvals") is kept and the link opens the Expanded view (built in Story 5.9); until then the preview shows it inert with a note
**And** the footer renders as the centred `block-footer-link` pattern

**Given** a token value contains `<img src=x onerror=alert(1)>`
**When** the template renders
**Then** it appears as literal escaped text and nothing executes

### Story 5.5: Comparisons from a mapped previous value, with comparison modes

As an Admin,
I want a KPI's comparison to come from a previous value in the same response and to choose how it is expressed,
So that users see "↗ 14.2% from last year" or an absolute or point change.

**Requirements:** FR-27, FR-28, FR-29, AR-15, NFR-11, UX-DR-132, UX-DR-195, UX-DR-246

**Acceptance Criteria:**

**Given** the Admin expands the comparison Slot of a KPI card
**When** the `comparison-editor` opens
**Then** a Source segmented control offers "Mapped previous value" and "Prior-period request", a field picker (e.g. `previous_total`) for the former, and a Mode choice of % change (`PCT_CHANGE`), absolute delta or percentage-point delta (for ratios)
**And** the example caption shows the live result (e.g. "↗ 14.2% from last year")

**Given** headline 284680, previous 249195 and mode % change
**When** the KPI renders
**Then** it shows "↗ 14.2%" with the comparison label from its Text Template ("from last year", "vs last month")
**And** the hidden text reads direction, value, label and judgement ("Up 14.2% from last year, favourable") and the arrow glyph is `aria-hidden`

**Given** mode absolute delta with current 90 and previous 100
**When** rendered
**Then** it shows "↘ -10" (sign and arrow) with the value formatted per the Slot's presentation rules

**Given** a ratio headline of 72% and previous 70% with mode percentage-point delta
**When** rendered
**Then** it shows "↗ 2.0 pp" and not "2.9%"

**Given** the mode percentage-point delta is chosen for a non-ratio field
**When** the Admin selects it
**Then** the option is disabled with a reason, and the mode cannot be saved

**Given** current equals previous
**When** rendered
**Then** the delta reads "No change from last year" and shows a flat indicator, neutral colour and no arrow glyph implying direction

**Given** the previous value is missing, null or not numeric, or previous is 0 for % change
**When** the Block renders
**Then** the comparison Slot shows "Unavailable: <field> missing" or "—" per its empty display, never 0 or infinity
**And** the headline value still renders

**Given** the previous-value field disappears after a re-fetch on a published Block
**When** the mapping fails
**Then** the comparison Slot is paused and msg:api-changed names it ("Comparison (% change) is paused too") and the dependent Slots of the headline are not blanked

### Story 5.6: Prior-period comparison requests through sync groups

As an Admin,
I want a comparison that calls the same Endpoint for the prior period,
So that a comparison works even when the API returns no previous value.

**Requirements:** FR-27, AR-11, AR-32, AR-33, AD-25, AD-26, NFR-1, UX-DR-195

**Acceptance Criteria:**

**Given** the comparison Source is "Prior-period request"
**When** the Admin chooses a prior window
**Then** the options are previous period of equal length, same period last year, or custom offset (number plus unit), computed from the active period

**Given** the Admin works with a pasted sample
**When** the Preview renders the comparison
**Then** it states "Checked against the live API before publish" and the preview does not fabricate a comparison value
**And** the step 5 live check runs both the primary and the comparison request

**Given** a published Block with a prior-period comparison
**When** a sync run is dispatched
**Then** the primary target and comparison target form one sync group fetched in one run that writes one `sync_generations` row (AD-26)
**And** compute uses only complete generations so primary and comparison never come from different generations

**Given** the primary fetch succeeds but the comparison fetch fails
**When** the Block result is computed
**Then** the primary values render, the comparison Slot is `unavailable` and `last_success_at` is the older of the two requests (so Stale uses the older, per 2 x interval)

**Given** two sync runs for the same group race (a late run finishes after a newer dispatch)
**When** the late run tries to commit
**Then** the guard `applied_seq < dispatch_seq` rejects it and it is recorded `superseded`, and a race test in CI proves the newer payload is never overwritten

**Given** the user changes the dashboard Date Range or the Block period
**When** the Block recomputes
**Then** `compute_ctx` includes the resolved period and the comparison window, so the cached result for another window is never served

**Given** the Date Range is a custom range with a prior-period offset that falls before the data the API holds
**When** the API returns empty or an error for the comparison request
**Then** the comparison Slot shows its empty display or `unavailable` and the Block is not blocked

**Given** the dashboard is viewed
**When** Results are served
**Then** no source API is called on the read path (hits immediately, misses `pending`)

### Story 5.7: Colour, threshold and direction rules

As an Admin,
I want to set direction, threshold bands and colour rules on Slots,
So that values show whether they are good or bad without relying on colour alone.

**Requirements:** FR-29, FR-32, NFR-7, UX-DR-194, UX-DR-246, UX-DR-273, AR-15

**Acceptance Criteria:**

**Given** the Admin expands a comparison Slot and sets Direction "Higher is better"
**When** the value rises
**Then** the arrow is ↗ and the colour is green (success); when it falls the arrow is ↘ and the colour is red (error)
**And** the arrow shows the actual change direction while the colour shows whether it is good

**Given** Direction "Lower is better" and a value that falls 5%
**When** rendered
**Then** the arrow is ↘, the colour is green and the visible text adds "· better" (and "· worse" for a rise), with hidden text stating direction and judgement

**Given** the Thresholds editor, the Admin clicks "+ Add band"
**When** they set condition ≥ / ≤ / between, a colour from the fixed status set (success, warning, error, info, neutral), and an icon or label
**Then** the band is added in order; first match from top to bottom wins; accent colour is not offered
**And** a band with colour but neither icon nor label is rejected with an inline message (colour is never the only signal)

**Given** overlapping or gapped bands and a value that matches none
**When** rendered
**Then** the value renders with neutral styling and the word and icon requirement is satisfied by the neutral state, never an error

**Given** a band rule on a text Slot using value matches ("On track", "At risk")
**When** rendered
**Then** the band's soft fill, text colour, leading icon and status word render

**Given** a value is exactly at a band boundary (e.g. ≥ 80 and 80)
**When** evaluated with decimal arithmetic
**Then** the ≥ band matches (no float comparison error)

**Given** colour-blind and high-contrast checks of every band colour on its soft fill
**When** the contrast test runs in CI
**Then** each pairing meets 4.5:1 for text and 3:1 for non-text

**Given** the Preview
**When** the Admin changes the Direction or a band
**Then** the live preview re-renders at once and the Slot row shows the "Colors the comparison: up = green" note

### Story 5.8: Shared chart wrapper and the Line/Area Block Type

As an Admin and a user,
I want line and area charts that are accessible and safe,
So that trends can be published and read by everyone, including keyboard and screen-reader users.

**Requirements:** FR-32, FR-33, AR-28, AD-21, NFR-7, NFR-11, UX-DR-101, UX-DR-102, UX-DR-103, UX-DR-104, UX-DR-105, UX-DR-106, UX-DR-107, UX-DR-108, UX-DR-109, UX-DR-145, UX-DR-146, UX-DR-197, UX-DR-199, UX-DR-241, UX-DR-242, UX-DR-244

**Acceptance Criteria:**

**Given** the package `block-types/line-area/v1/` with `schema.json`, `Shaper.php` and `Renderer.vue`
**When** the Admin chooses Line/Area in step 1
**Then** the Slots are common Title, Subtitle, Icon, Footer action plus X* (date or Dimension), series* (1-n Measures), series labels, highlighted point and target line, with the Block-Type settings from UX-DR-197 (per-series label Text Template, direct label on/off, optional target line value and label)
**And** the wizard and mapper render these from the schema with no Line/Area-specific code

**Given** the shared `useChart` composable wraps ECharts
**When** any chart renders a tooltip or label
**Then** text is built with `richText` and `encodeHTML` and never from formatter strings, and the lint rule bans `v-html`, `innerHTML` and formatter strings in Block Type packages

**Given** a one-series line chart
**When** rendered
**Then** it draws a 2px `chart-1-stroke` line with monotone curve and no markers at rest; Area adds the gradient fill for series 1 only; gridlines are horizontal only; Y ticks are compact ("$40k")

**Given** two or more series (maximum 6)
**When** rendered
**Then** each series has a distinct dash style (solid, dash, dot, dash-dot, long-dash, dotted) and marker (● ■ ▲ ◆ ✕ ○) at every point when points <= 24 else at the line end, plus a direct label
**And** the legend is a list naming marker and dash ("Revenue, solid line, circle markers"), not toggles
**And** a seventh series is folded by top-N/"Other" rules and never plotted

**Given** a chart plot
**When** a screen reader reaches it
**Then** it is `role="img"` with a generated `aria-label` (e.g. "Line chart, Revenue, Jan to Dec 2026. Lowest $10.2k in Jan, highest $40.1k in Dec, trend up. Target $35k"), regenerated on change and never announced

**Given** keyboard focus is on the plot
**When** the user presses ← → ↑ ↓ Home End and Esc
**Then** the plot is one tab stop; ← → step points (Home/End to the ends), ↑ ↓ switch series, the point shows `chart-point-focus` and the tooltip and is announced politely ("Revenue, March, $21.4k"), Esc leaves point mode with focus staying on the plot
**And** the tooltip never hides the focused point

**Given** a highlighted-point or target-line Slot is mapped
**When** rendered
**Then** the point uses `chart-point-highlight` (10px, 2px series stroke) and the target line is drawn with its label

**Given** the loading, empty, stale, unavailable, error and partial-data states
**When** the Block renders each
**Then** each follows the Block state faces (skeleton bars, msg:block-empty, "Stale: last data 10:42", msg:unavailable-user with no zero, Retry link, partial-data badge) and none draws a zero line

**Given** the Preview step on Line/Area
**When** the Admin hovers or focuses a plot region
**Then** the preview hotspot reads "{Slot}: {field}, {rendered value}. Change field" and opens the picker

**Given** the data values include a number lexeme beyond double precision
**When** the chart renders
**Then** the shaper passes decimal strings and formatting uses `Intl`; the chart axis may use floats only for position, and the tooltip shows the exact formatted decimal

### Story 5.9: View as table, Expanded view and the paginated rowset endpoint

As a user,
I want to see a chart's data as a table and open a Block's full rows in an Expanded view,
So that I can read exact values and browse all the rows behind the summary.

**Requirements:** FR-26, FR-32, AR-32, AD-25, NFR-7, UX-DR-118, UX-DR-243, UX-DR-247

**Acceptance Criteria:**

**Given** any chart Block (Line/Area now, others as they are added) regardless of its Expanded view setting
**When** the user opens ⋯ and chooses "View as table"
**Then** an `expanded-view` panel opens with only an accessible table (th scope, units in headers, the chart's formats) built from the displayed render payload
**And** focus moves to the panel title and Esc or Close returns focus to ⋯

**Given** the View as table data is loading, empty or failed
**When** the panel opens
**Then** it shows skeleton rows, msg:expanded-empty or msg:expanded-error with Retry respectively

**Given** a Block configured with the footer action "Expanded view"
**When** the user activates it
**Then** a full-size Block renders with a paginated data-table of the rows before top-N, at 90% of the viewport up to 1200px, and full screen below 768px
**And** focus goes to the panel title; Esc or Close returns to the invoker; the header shows icon tile, title and Data-as-of Time

**Given** `GET /api/v1/dashboards/{d}/items/{i}/rows?page=2&page_size=25`
**When** the user with access requests it
**Then** it returns rows from the `rowset_ref` of the current Block Result (rows before top-N), server-paginated, with `total` for "26–50 of 48"-style text
**And** responses carry only mapped fields, measures as decimal strings, and the API never calls a source API

**Given** a user who has lost access to the Block or whose Block is unpublished
**When** the rows endpoint is called
**Then** it returns 403 (or the unpublished envelope) with the error envelope and no rows, and access is re-evaluated per request

**Given** `rowset_ref` has expired from the cache
**When** the rows endpoint is called
**Then** it returns a retryable error shown as msg:expanded-error, and recomputes through Results without calling the source

**Given** a user with the table open
**When** a new Block Result arrives
**Then** the table values replace in place and the page and sort are kept (the last page is shown and announced if the page no longer exists)

**Given** the rows page contains hostile strings
**When** the table renders
**Then** each cell is escaped text

### Story 5.10: KPI & Chart Block Type and the normative revenue example

As an Admin,
I want a KPI & Chart Block that combines a headline, a comparison and a chart,
So that the "Revenue overview" mockup can be built and verified end to end.

**Requirements:** FR-25, FR-27, FR-29, FR-31, FR-32, AR-28, AD-21, AD-33, UX-DR-54, UX-DR-145, UX-DR-197, UX-DR-246

**Acceptance Criteria:**

**Given** `block-types/kpi-chart/v1/` is registered
**When** the Admin picks KPI & Chart
**Then** the Slots are the KPI card Slots (Headline value*, KPI label*, comparison, comparison label, secondary note, status badge) plus Chart X* and Chart series* (1-n), and the common Slots Title, Subtitle, Icon and Footer action

**Given** the FR-31 acceptance fixture: endpoint `GET /api/v2/finance/revenue` returns `{data:{total:284680, previous_total:249195, currency:"USD", monthly:[{month:"2026-01",revenue:10200},{month:"2026-02",revenue:12650}]}}`
**When** the Block is mapped as: Title static "Revenue overview", Subtitle static "Financial performance · Current year" (header); KPI label static "Total revenue" (secondary); Headline `data.total` (headline bold large, currency USD, 0 decimals); Comparison `PCT_CHANGE(data.total, data.previous_total)` (percent, 1 decimal, Direction higher is better); Chart X `data.monthly[].month` (date "MMM"); series 1 `data.monthly[].revenue` (currency compact, line, area fill)
**Then** the rendered Block shows "Total revenue", "$284,680", "↗ 14.2% from last year" in green, and the chart X labels Jan, Feb with compact currency values
**And** the same mapping computed server-side with the Query Plan engine and in the Preview yields identical render payloads (golden test)

**Given** the FR-31 acceptance fixture as an automated test
**When** it runs in CI against the engine, the shaper and the renderer
**Then** it asserts the exact strings above, the colour token `success`, the arrow glyph ↗, the hidden judgement text "Up 14.2% from last year, favourable", and exactly one headline-styled element in the Block

**Given** the same fixture with `previous_total` removed
**When** the Block renders
**Then** the comparison Slot shows "Unavailable: data.previous_total missing", the headline and chart still render, and no value is shown as zero

**Given** the same fixture with `data.monthly` empty
**When** the Block renders
**Then** the headline renders and the chart region shows msg:block-empty text, with no 0 points drawn

**Given** the chart region of the Block
**When** a user navigates it by keyboard
**Then** it behaves per Story 5.8 and "View as table" is in ⋯ (Story 5.9)

**Given** the Block is shown at its default width and at tablet and mobile widths
**When** the Preview toggles are used
**Then** the layout reflows to a single column on mobile without hiding the headline, comparison or label

### Story 5.11: Bar and Pie/Donut Block Types

As an Admin,
I want bar charts and pie or donut charts,
So that categories and shares can be compared at a glance.

**Requirements:** FR-24, FR-32, AR-28, AD-21, NFR-7, UX-DR-102, UX-DR-109, UX-DR-110, UX-DR-111, UX-DR-145, UX-DR-197, UX-DR-241, UX-DR-242, UX-DR-244

**Acceptance Criteria:**

**Given** the Bar package with Slots Category*, values* (1-n), stack/series key and settings Layout (Grouped or Stacked), orientation (vertical by default), value labels and sort (data order or by value)
**When** the Admin maps and previews a grouped chart
**Then** bars use series fill with chart-1 + 1px `chart-1-stroke` edge, radius xs on the top corners, groupGap 30%, barGap 2px, baseline at zero
**And** series 3-6 use fill patterns (diagonal, dots, cross-hatch, vertical lines) so series are never identified by colour alone

**Given** Stacked layout
**When** rendered
**Then** segments stack bottom-up in palette order with a 1px surface-card divider and the total above the stack in caption text-secondary tabular

**Given** negative values or all-zero values
**When** the chart renders
**Then** bars extend below the zero baseline and an all-zero chart still shows its category labels and the legend (no division by zero)

**Given** the Pie/Donut package with Category*, value*, centre label/value and settings (shape pie or donut, centre value Value / slice total / none with label)
**When** more than 6 categories exist
**Then** only the top 5 slices plus one "Other" slice (maximum 6) render, largest first clockwise from 12 o'clock, with the 2px surface-card divider and donut thickness 28% of radius

**Given** slice labels collide
**When** rendered
**Then** the legend lists name, % and value, and patterns apply from series 3 on

**Given** category order by "by value" sort and the Admin-set Top-N step
**When** the plan runs
**Then** the visual order follows the settings and "Other" aggregates the folded rows using the ratio-safe rule when the value is a ratio

**Given** bars and slices are focused by keyboard
**When** the user presses ← → (and ↑ ↓ for series)
**Then** each step is announced ("Revenue, March, $21.4k" for bars; name, % and value for slices) and Esc leaves point mode
**And** the plot `aria-label` names the categories and the largest value (bars) or the largest slices with % (pies)

**Given** both Block Types
**When** the user opens ⋯
**Then** "View as table" is present (Story 5.9)

### Story 5.12: Table Block Type with sort, pagination and stable row keys

As a user,
I want a table Block I can sort and page through,
So that I can scan structured records inside a dashboard.

**Requirements:** FR-29, FR-30, FR-32, AR-28, AD-21, NFR-7, UX-DR-100, UX-DR-145, UX-DR-146, UX-DR-197, UX-DR-245, UX-DR-44

**Acceptance Criteria:**

**Given** the Table package with Columns* (each Field, header, format, alignment, width, sortable), row highlight rule and pagination size
**When** the Admin configures it
**Then** the column editor offers those per-column settings and the pagination size, with the row highlight rule using threshold bands (Story 5.7)

**Given** a rendered Table Block
**When** assistive technology reads it
**Then** it is a real `table` with a visually hidden caption equal to the Block title, `th scope="col"`, units in headers and numeric cells right-aligned tabular

**Given** a sortable column header
**When** the user activates its button repeatedly
**Then** the order cycles ascending, descending, data order, `aria-sort` is updated, "Sorted by Amount, descending" is announced, focus stays, and the sort is session-only (not saved)
**And** only columns marked sortable show the sort indicator

**Given** 48 rows and pagination size 10
**When** rendered
**Then** "1–10 of 48" shows with Previous/Next, Previous `aria-disabled` on page 1 and Next on the last page, focus stays on the pressed button and the new range is announced

**Given** the user is on page 3
**When** they change the sort, the Date Range or the Block period
**Then** the table resets to page 1

**Given** a refresh delivers new rows
**When** values update
**Then** rows update in place using stable row keys (a mapped key column, else a hash of the key fields; never array index) so focus, sort and page survive
**And** if the current page no longer exists, the last page is shown and announced

**Given** a row matches a highlight band
**When** rendered
**Then** the first cell shows the band icon and label ("At risk") in band text colour, and the row's accessible text includes the label

**Given** a cell's mapped value is missing
**When** rendered
**Then** it shows "—" in that cell and the Block shows the partial-data badge; a missing column is `unavailable`, not zero

**Given** a narrow Block
**When** the table overflows
**Then** it scrolls sideways in a focusable region labelled with the Block title, the first column is pinned and the header row stays visible

**Given** two rows with identical values
**When** sorted and refreshed
**Then** their relative order is preserved (stable sort) and keys stay unique

**Given** a table or list Block has rows with a missing mapped value
**When** it renders
**Then** the missing cells show "—" and the Block header shows the "Partial data" badge (warning-soft background, warning text) with a text label so colour is not the only signal (UX-DR-44, FR-30)

### Story 5.13: List and Progress list Block Types

As an Admin,
I want list and progress-list Blocks,
So that people can see ranked items and task or goal completion.

**Requirements:** FR-26, FR-28, FR-29, FR-32, AR-28, AD-21, NFR-7, UX-DR-92, UX-DR-93, UX-DR-94, UX-DR-145, UX-DR-197

**Acceptance Criteria:**

**Given** the List package with Item title*, item subtitle, avatar/initials or icon, right value, right meta and status
**When** rows are mapped with subtitle template "{category} · {days} days"
**Then** each `list-row` shows title, subtitle, right value (tabular) and right meta, with a 1px surface-muted divider and the footer `block-footer-link`

**Given** the avatar Slot maps a name
**When** rendered
**Then** `avatar-initials` shows initials in one of five tints chosen deterministically from the name (the same person gets the same tint in every Block)

**Given** the Progress list package with Item title*, progress* (value/total or %), caption and colour rule
**When** a row has done 12, total 16 and caption template "{done}/{total} tasks"
**Then** it shows "12/16 tasks" and "75%" (value divided by total, computed in decimal) above a 6px track
**And** the percent text is the value, the bar is `aria-hidden`, and assistive technology reads "Website redesign, 12 of 16 tasks, 75%"

**Given** a colour rule (threshold bands) on progress
**When** a row falls in the "At risk" band
**Then** the band icon and label sit beside the percent and the fill uses the band colour

**Given** progress is mapped as a ratio field (numerator/denominator)
**When** rows are grouped (Story 5.2/5.3)
**Then** the percent is computed from summed numerators and denominators

**Given** total is 0 or missing, or progress exceeds 100%
**When** rendered
**Then** total 0 shows "—" and the partial-data badge, and values above 100% clamp the bar to 100% while the text shows the true percent

**Given** no rows
**When** rendered
**Then** msg:list-empty shows centred, and when a search or filter leaves none msg:list-no-match applies where such a control exists

**Given** a row key
**When** rows refresh
**Then** list items keep stable keys and screen-reader focus is not lost

### Story 5.14: Activity feed, Calendar/Agenda and Text/Status Block Types

As an Admin,
I want activity feed, calendar agenda and text/status Blocks,
So that recent events, upcoming items and simple statuses can be shown.

**Requirements:** FR-26, FR-29, FR-32, AR-28, AD-21, NFR-7, NFR-11, UX-DR-95, UX-DR-96, UX-DR-97, UX-DR-99, UX-DR-145, UX-DR-197

**Acceptance Criteria:**

**Given** the Activity feed package with Actor*, action text*, object, timestamp* (relative) and avatar
**When** rendered with a timestamp 8 minutes old
**Then** each `activity-row` shows actor (600), action (secondary) and "8 min ago" beneath, with relative time using the viewer's locale and refreshed without reload

**Given** a timestamp in the future or an unparsable timestamp
**When** rendered
**Then** a future timestamp shows the formatted date, and an unparsable one shows "—" with the partial-data badge

**Given** the Calendar/Agenda package with Date*, start time*, title*, subtitle/location, duration, colour/category and the week-strip setting
**When** the week strip is on
**Then** `calendar-week-strip` renders as a list with today highlighted by the 28px accent circle and a dot, `aria-current="date"`, and its accessible name "Wednesday 22 May, today"

**Given** agenda rows with categories
**When** rendered
**Then** each `agenda-row` shows time, a 3px category rule from the chart palette, title and subtitle, and assistive technology reads "09:30, Product sync, Meeting room 2A, 45 min"
**And** category is also conveyed by the text or icon, not colour alone

**Given** events in the workspace time zone and a viewer in another zone
**When** rendered
**Then** times use the resolved workspace/user time zone rules from the Period resolver and are never shifted on date-only values

**Given** the Text/Status package with settings mode Text (Text Template) or Status (mapped field with threshold bands or value matches)
**When** the mode is Status and value is "On track"
**Then** a pill with band fill, text colour, leading icon and the status word ("✓ On track") renders

**Given** the Text/Status value matches no band
**When** rendered
**Then** it shows neutral text, and the word and icon are always present

**Given** long text in Text mode
**When** rendered
**Then** it shows up to 3 lines then an ellipsis, with the full text in "About this block"

**Given** each of the three Block Types in loading, empty, stale, unavailable, error and partial states
**When** rendered
**Then** each state matches the shared state faces and none shows zero

### Story 5.15: Change Block Type while keeping same-name Slots

As an Admin,
I want to change a Block's type without losing the mapping that still applies,
So that I do not have to remap everything when I switch from, say, Bar to Line.

**Requirements:** FR-23, FR-30, FR-32, AR-28, AD-21, UX-DR-180, UX-DR-203, UX-DR-204, UX-DR-199

**Acceptance Criteria:**

**Given** a Draft with mapped Slots on a Bar Block
**When** the Admin changes the Block Type to Line/Area in step 1
**Then** Slots re-fill from unchanged roles without asking, Slots with the same name and type are kept with their mapping, presentation rules and Confirmed status, and Slots with no counterpart are flagged unmapped

**Given** the new Block Type has a required Slot with no counterpart (e.g. X)
**When** the change completes
**Then** it appears as Required-missing with a suggestion, "Continue to Preview" is `aria-disabled` with msg:required-slot-missing, and a status-bar update is announced politely

**Given** a kept Slot has a type that the new Block Type does not accept (e.g. text field in a numeric Slot)
**When** the change completes
**Then** the Slot shows Type-mismatch with the reason ("data.currency is text. Headline value needs a number."), compatible fields, and "Treat as ..." when parsing fixes it

**Given** Overridden or Confirmed Slots
**When** the type changes
**Then** auto-map never alters them

**Given** common Slots (Title, Subtitle, Icon, Footer action)
**When** the type changes
**Then** they are always kept

**Given** Transforms and Calculated fields exist
**When** the type changes
**Then** they are kept; steps that reference fields still present stay valid and others show their row errors

**Given** a published Block is being edited and the Admin changes the type
**When** they save
**Then** the change creates a new Draft version only and the published version is unchanged until publish (msg:version-safe is shown)

**Given** the Admin changes the type and then presses Undo or reverts it
**When** the type is switched back
**Then** the previously kept mapping is restored from the Draft revision history of this session

**Given** the type change by an Admin without a draft lock
**When** another Admin holds the draft lock
**Then** the change is refused with msg:draft-locked (the read-only view)

### Story 5.16: XSS fixtures through every slot of every Block Type

As a security-minded operator,
I want every slot of every Block Type proven to render API content as inert text,
So that hostile API data can never run script in a user's browser.

**Requirements:** FR-26, FR-32, AR-28, AD-21, NFR-4, NFR-7, UX-DR-246, UX-DR-273

**Acceptance Criteria:**

**Given** a fixture payload where every string value is `"><img src=x onerror=window.__xss=1>` and `<script>window.__xss=1</script>`
**When** an automated test maps every Slot of each of the 11 Block Types (kpi-card, kpi-chart, line-area, bar, pie-donut, table, list, progress-list, activity-feed, calendar-agenda, text-status) and renders each in the Block and in the Expanded view and "View as table"
**Then** `window.__xss` stays undefined, the strings appear as literal text, and the DOM contains no element created from payload content

**Given** the chart Block Types render tooltips, axis labels, legends, direct labels and centre labels
**When** the hostile fixture is used
**Then** the tooltip and labels show literal text through `richText`/`encodeHTML` and no formatter string is evaluated

**Given** Text Templates and URL templates with hostile tokens (`{field}` values containing `javascript:` or quotes)
**When** rendered
**Then** text is escaped and the URL tokens are percent-encoded per position with fixed scheme and host

**Given** the lint rules run in CI
**When** a Block Type package introduces `v-html`, `innerHTML` or a formatter string
**Then** CI fails

**Given** the hostile fixture reaches the `rowset_ref` rows endpoint and the Preview and wizard field samples
**When** rendered in the Expanded view, the Preview and the hotspot tooltips
**Then** it is also inert

**Given** a slot value that is a very long string (1 MB) or contains RTL and control characters
**When** rendered
**Then** it is truncated or wrapped by CSS only, never executed, and does not break the layout

**Given** a new Slot is added to any Block Type schema without an XSS fixture entry
**When** the fixture-coverage test runs
**Then** it fails, naming the missing slot

### Story 5.17: Add a Block Type with only a package and versioned contracts

As a developer,
I want to add a Block Type by adding a package,
So that new Block Types need no change to the wizard, mapper or dashboards.

**Requirements:** FR-32, FR-33, NFR-12, AR-28, AD-21, AD-25, UX-DR-145

**Acceptance Criteria:**

**Given** a new package `block-types/example-gauge/v1/` containing `schema.json` (slot schema, configuration schema, size limits), `Shaper.php` and `Renderer.vue`
**When** the application is built and no other file is changed
**Then** the Block Type appears in step 1's Block Type choice, the mapper shows its Slots, the Preview and Add-blocks Panel render it and a dashboard displays it
**And** a CI "extensibility proof" test asserts the diff contains only files under that package

**Given** the package schema is invalid or lacks a required state renderer (loading, empty, stale, unavailable, error)
**When** the build or the schema validation test runs
**Then** it fails naming the missing member, and `schema.json` is the single source validated by opis/json-schema for PHP and typed for TS

**Given** a Block published with Block Type contract `v1`
**When** a contract `v2` is added
**Then** the `v1` directory is never deleted while any Block Version pins it, and Blocks keep rendering with `v1`

**Given** an Admin edits a Block pinned to `v1` while `v2` exists
**When** they choose to upgrade the contract
**Then** the upgrade creates a Draft (never mutates the published version) and the pinned `shaper contract version` is part of `compute_ctx` so cached results of `v1` are not served for `v2`

**Given** a Block Version whose contract directory is missing
**When** a deploy check runs
**Then** the CI check fails the release

**Given** all 11 first-party packages
**When** the schema conformance test runs
**Then** each declares the common Slots Title, Subtitle, Icon and Footer action, each Slot's value type (number, text, date/time, boolean or Text Template) and cardinality (single or per row), and numeric per-row chart Slots declare a default aggregation

**Given** a package attempts to reach domain logic or import core internals
**When** the architecture test runs
**Then** it fails (no domain logic in the core, NFR-12) and Block Types are first-party and loaded at build time only

## Epic 6: Governed Publishing and Access

Admins can manage Block versions safely (edit a published Block into a new Draft, diff and impact confirmation, two-phase publish, restore, version history, unpublish and archive, propagation to users without moving layouts), organise Blocks into categories, manage all Blocks from one list, and control which user groups can use each Block.

### Story 6.1: Edit a published Block into a new Draft

As an Admin,
I want to edit a published Block without disturbing the live version,
So that I can prepare changes safely while users keep the current version.

**Requirements:** FR-38, FR-40, FR-41, AR-17, AR-19, AD-13, C17, UX-DR-40, UX-DR-45, UX-DR-37, UX-DR-250, UX-DR-282

**Acceptance Criteria:**

**Given** a Block with a Published version v1.0 and no Draft
**When** an Admin holding the create/edit permission chooses Edit
**Then** a new Draft is created with the content of v1.0, its prospective version number fixed at creation as the next minor (v1.1), and the wizard opens at step 1 with the "• Draft" badge and the neutral "v1.1" version tag
**And** v1.0 stays Published and keeps serving every Block Instance unchanged
**And** an Audit Event `blocks.draft.created` is written in the same transaction.

**Given** a Block that already has a Draft
**When** any Admin chooses Edit, or a second create-Draft request arrives concurrently
**Then** the existing Draft is opened and no second Draft is created (the partial unique index `WHERE state='draft'` rejects the duplicate)
**And** the API answers 409 with the current Draft state for the losing request.

**Given** a Draft is open for editing by Admin A
**When** Admin B opens it
**Then** B sees the read-only view with `msg:draft-locked` and "Take over editing" / Close
**And** Take over increments `lock_epoch`, saves A's valid non-secret fields, and A's next write returns 423 `platform.edit_lock_lost` with `msg:draft-taken-over`
**And** the lock lapses after the `edit_lock_ttl` pending_input setting without inventing a value.

**Given** a Draft write carries a stale `revision`
**When** it is submitted
**Then** the API answers 409 with the current state and nothing is overwritten
**And** each successful write bumps `revision`, which invalidates any existing ValidationReport.

**Given** the Block's Block Type has a newer contract version available
**When** the Admin edits
**Then** the Draft proposes the next major version (v2.0) only for a contract upgrade, and every ordinary edit increments the minor (C17).

**Given** an Admin without the create/edit permission
**When** they call the edit endpoint
**Then** the API answers 403 and the denial is recorded as a security event.

**Given** an Archived Block
**When** Edit is requested
**Then** the action is refused with 409 `blocks.block_archived` because Archived is read-only.

### Story 6.2: Know who is affected: the block_usage impact read model

As an Admin,
I want to see how many users have a Block on a dashboard and which Templates include it,
So that I can judge the impact of a change before I confirm it.

**Requirements:** FR-39, AR-3, AR-36, AD-29, UX-DR-282

**Acceptance Criteria:**

**Given** the Blocks module consumes `dashboards.dashboard.changed` outbox events
**When** a user adds or removes a Block, or a dashboard is deleted
**Then** the `block_usage` read model (new table with `workspace_id` first, RLS forced) records distinct memberships per Block and is updated idempotently via `outbox_consumptions`
**And** events older than the subject's last applied `subject_seq` are dropped.

**Given** the same user has the Block on three dashboards
**When** `BlockImpact` is requested
**Then** the user is counted once (distinct memberships, not instances).

**Given** Templates are not yet available in this Workspace (Epic 7 not delivered when this story ships)
**When** `BlockImpact` is requested
**Then** the Template list is empty and the count is 0
**And** the read model accepts template events when they exist without a schema change (Template side is out of scope for this story).

**Given** a user's dashboard-item counts change in a rolled-back transaction
**When** the consumer reruns
**Then** `block_usage` converges to the dashboards' state (rebuildable from events, verified by a test).

**Given** a request from an Admin of Workspace B for Workspace A's Block
**When** `BlockImpact` is called
**Then** zero rows are returned (RLS) and no count leaks.

**Given** `BlockImpact`
**When** it is called
**Then** it never joins another module's tables (architecture test passes: only Blocks-owned `block_usage`).

### Story 6.3: Diff a new version against the current one

As an Admin,
I want a clear before-and-after diff of what I changed,
So that I know exactly what users will get.

**Requirements:** FR-39, FR-41, AD-13, UX-DR-76, UX-DR-282

**Acceptance Criteria:**

**Given** a Draft v1.1 of a Block whose current version is v1.0
**When** `BlockVersionDiff` runs
**Then** it returns changes grouped by exactly four sections: Mapping, Presentation, Chrome, Endpoint, each as before -> after pairs
**And** `draft_ui_state` (step, acknowledged warnings, slot statuses and confidence) is excluded from the diff.

**Given** two Drafts with identical content but different `draft_ui_state`
**When** diffed against the same version
**Then** the diffs are equal.

**Given** a Draft with no content change from the current version
**When** the diff runs
**Then** the diff result has every section empty, which Story 6.4's publish dialog renders as "No changes" for each section.

**Given** a publish succeeds
**When** the new version row is frozen
**Then** a generated `change_summary` (section names with counts of changed items) is stored on the version and cannot change afterwards.

**Given** the diff rows are rendered in the dialog component
**When** displayed
**Then** before text is text-muted with strikethrough, after text is text-primary, and the text carries "was"/"now" for screen readers (no colour-only signal).

**Given** a first publish (no earlier version)
**When** the diff is requested
**Then** it is not computed and only the validation summary applies (FR-39).

### Story 6.4: Publish a new version with diff, impact and two-phase activation

As an Admin with the publish permission,
I want to confirm the diff and impact and then publish a new version that goes live atomically,
So that users never see a half-published Block.

**Requirements:** FR-20, FR-39, FR-40, FR-42, AR-17, AR-18, AD-13, AD-25, AD-2, UX-DR-170, UX-DR-178, UX-DR-179, UX-DR-89, UX-DR-76, UX-DR-255, UX-DR-272, UX-DR-282, AR-57

**Acceptance Criteria:**

**Given** a valid Draft v1.1 of a published Block and a ValidationReport bound to the current `revision`
**When** an Admin with `blocks.publish` activates Publish block
**Then** the dialog "Publish Revenue overview v1.1?" opens with focus on the title, Cancel first in Tab order, the What changed diff (Story 6.3), `msg:publish-impact` filled from `BlockImpact` (e.g. "214 users have this block and 2 templates include it. Their layouts won't move.") and buttons "Publish v1.1" and Cancel
**And** Esc cancels back to the wizard step.

**Given** the dialog is confirmed
**When** Tx1 runs
**Then** `UPDATE ... SET state='prepared' WHERE state='draft' AND revision = report.revision` freezes the row and emits `blocks.version.prepared`
**And** a stale revision updates zero rows and the API answers 409 with no state change.

**Given** the version is `prepared`
**When** Results receives `blocks.version.prepared`
**Then** it precomputes the hot keys up to the `precompute_timeout` pending_input setting and then calls `Blocks\Contracts\ActivateVersion`
**And** Tx2 sets `published`, switches `current_version_id`, writes the Audit Event `blocks.version.published` with before/after and emits `blocks.version.published` in the same transaction.

**Given** a reader requests the dashboard while the version is `prepared`
**When** the response is served
**Then** every item still shows the previous Published version (never an empty state)
**And** the database trigger rejects any UPDATE other than draft edits, draft->prepared and prepared->published.

**Given** the publish succeeds
**When** the Admin returns to the list
**Then** `msg:publish-success` is shown and Published blocks opens with the new row highlighted and focused
**And** the previous version is shown as "Superseded by v1.1", a derived state never stored.

**Given** a first publish of a Draft v1.0
**When** the Admin publishes
**Then** only the validation summary is shown (no diff or impact dialog), the same two-phase path runs, and the Block registers its access subject via `RegisterAccessSubject` in the same transaction with mode All users by default.

**Given** an Admin without `blocks.publish`
**When** they view the wizard step 5 or the dialog trigger
**Then** Publish is `aria-disabled="true"`, focusable, described by the inline `msg:perm-publish`, and activation focuses the reason without opening the dialog
**And** a direct API call answers 403 and writes a security event.

**Given** the Admin pressed Publish in a second tab after the Draft was taken over or edited
**When** Tx1 runs
**Then** the request is rejected with 409 (revision) or 423 `platform.edit_lock_lost` and nothing is published.

### Story 6.5: Activate stuck prepared versions by maintenance

As an operator,
I want versions stuck in `prepared` to activate automatically,
So that a slow or failed precompute never blocks a publish forever.

**Requirements:** FR-39, AR-18, AR-43, AD-13, AD-25, AR-57

**Acceptance Criteria:**

**Given** a version has been `prepared` longer than `precompute_timeout` (pending_input)
**When** the `maintenance` queue job runs
**Then** it calls `ActivateVersion` and the version becomes `published` with the same audit and outbox events as the normal path
**And** the job is idempotent when run twice or concurrently with Results' own activation (the second call is a no-op).

**Given** a version is activated before its hot keys were computed
**When** a dashboard loads
**Then** uncached items return `pending` from `/api/v1/dashboards/{id}/results` and a compute is enqueued, with no call to a source API on the read path.

**Given** Results is down when a version becomes `prepared`
**When** the timeout elapses
**Then** the version still activates and a metric `dashflow.blocks.activation_timeout` increments.

**Given** a version is `prepared` but its Draft owner has meanwhile saved another Draft
**When** the maintenance job activates
**Then** the new Draft is unaffected (only one Draft exists after publish is created from a new Draft of the next minor).

**Given** a restart of workers mid-publish
**When** they come back
**Then** no version stays `prepared` after one more maintenance cycle (tested).

### Story 6.6: Propagate a new version without moving layouts

As a User,
I want a new Block version to appear on my dashboard without my layout changing,
So that updates never rearrange my work.

**Requirements:** FR-42, AR-20, AR-21, AD-14, AD-25, UX-DR-229, UX-DR-257, UX-DR-282

**Acceptance Criteria:**

**Given** v1.1 is published and a user has the Block on a dashboard
**When** the Block next refreshes
**Then** it renders v1.1, keeping position and minimized state, with no layout command issued by the system
**And** the dashboard `revision` is unchanged.

**Given** v1.1's size limits exclude the user's current size
**When** the dashboard is read
**Then** the geometry is clamped server-side by the `Platform\Layout` kernel to the nearest allowed size, deterministically and without pushing neighbours.

**Given** a clamp would collide and cannot fit without moving neighbours
**When** the Admin opens the publish dialog (Story 6.4)
**Then** the dialog lists the affected count under "Sizes that cannot fit" and the old size is kept for those instances after publish
**And** the report is computed before confirmation, not after.

**Given** a version publish
**When** Tx2 commits
**Then** the event `blocks.version.published` triggers the notification event `msg:notify-version` ("Revenue overview was updated to v1.1") for each affected membership, once per user per version (`notifications(source_event_id)` unique)
**And** the notification UI itself is out of scope (Epic 8).

**Given** a Block pinned `Mandatory` by a Template (Epic 7 not delivered)
**When** this story ships
**Then** no mandatory-append behaviour is implemented here; only version propagation.

**Given** a layout conformance fixture of 20 dashboards
**When** v1.1 is published
**Then** positions before and after are identical for every item except clamped sizes (test).

### Story 6.7: Browse version history with read-only preview

As an Admin,
I want to see every version of a Block and preview any of them,
So that I can understand what changed and what users saw.

**Requirements:** FR-41, FR-44, UX-DR-208, UX-DR-115, UX-DR-45, UX-DR-37, UX-DR-146, UX-DR-263, UX-DR-282

**Acceptance Criteria:**

**Given** a Block with versions v1.0, v1.1 (Published) and a Draft v1.2
**When** an Admin chooses Versions from the row menu
**Then** a `data-table` lists newest first: version, Lifecycle State (Draft/Published/Unpublished/Archived), published by, date, change summary
**And** the current user-served version is tagged "Current", older Published rows show "Superseded by v1.1" caption in text-muted, and the word "Live" is not used.

**Given** a published version row
**When** the Admin activates View
**Then** a read-only preview opens with the state switcher and a per-step summary, rendered from the immutable version config
**And** no edit control is present and no write request is made.

**Given** the version has no stored payload for the preview
**When** the preview renders
**Then** a skeleton face is shown (never fabricated values) with the `msg:sample-note` explanation.

**Given** an attempt to modify a published version via API
**When** the request is made
**Then** it is refused (409) and the DB trigger blocks any UPDATE other than the allowed transitions
**And** a version viewed twice always returns identical content.

**Given** the history list is loading, failed, or empty
**When** displayed
**Then** the generic states apply: 5 skeleton rows; "We couldn't load versions. Try again." with Retry; `msg:list-empty`
**And** the table is a real `table`, results count announced politely, "More actions for v1.1" row menus.

**Given** a user without the Admin role
**When** they request the history
**Then** the API answers 403 and the page is not rendered (`msg:perm-denied`, audited).

### Story 6.8: Restore an old version as a new Draft

As an Admin with the publish permission,
I want to restore an old version into a new Draft without losing my current Draft silently,
So that I can roll back content safely and deliberately.

**Requirements:** FR-40, FR-41, AR-19, AD-13, AD-5, UX-DR-208, UX-DR-271, UX-DR-272, UX-DR-255, UX-DR-282

**Acceptance Criteria:**

**Given** a Block with no Draft and a past version v1.1
**When** an Admin with `blocks.publish` chooses "Restore as draft" on v1.1
**Then** a new Draft (next minor, e.g. v1.3) is created from v1.1 with `msg:restore-done` ("Draft v1.3 created from v1.1. Publish it to make it live.") and the wizard opens at step 1
**And** the current Published version stays live until the Draft is published normally, and an Audit Event `blocks.version.restored` is written.

**Given** a Draft already exists
**When** Restore is chosen
**Then** an `alertdialog` shows `msg:restore-replace` ("Replace your current draft with v1.1?") with focus on Cancel and buttons "Replace draft" / Cancel, and nothing changes until Replace draft
**And** the request carries `expected_draft_revision` and `replace_draft=true`; a mismatch answers 409 and the existing Draft is untouched.

**Given** the Admin cancels the dialog or presses Esc
**When** focus returns
**Then** it returns to the Restore invoker and the Draft is unchanged.

**Given** a restore
**When** it commits
**Then** `revision` is bumped (invalidating any ValidationReport) and the restored Draft needs a new validation before publish.

**Given** access grants exist for the Block
**When** restore runs
**Then** grants are neither read nor written (test: restore does not re-grant, AD-5).

**Given** an Admin without `blocks.publish`
**When** viewing Versions
**Then** Restore is `aria-disabled` with `msg:perm-publish` inline, and the API answers 403 with a security event.

**Given** another Admin holds the Draft lock
**When** Restore with replace is attempted
**Then** the API answers 423 `platform.edit_lock_lost` unless the lock is taken over first.

### Story 6.9: Unpublish and archive Blocks

As an Admin with the publish permission,
I want to unpublish or archive a Block with a clear impact warning,
So that I can retire Blocks without surprising users.

**Requirements:** FR-38, FR-40, AR-18, AR-32, UX-DR-209, UX-DR-271, UX-DR-272, UX-DR-255, UX-DR-146, UX-DR-257, UX-DR-282

**Acceptance Criteria:**

**Given** a Published Block with 214 users
**When** an Admin with `blocks.publish` chooses Unpublish
**Then** an `alertdialog` "Unpublish Revenue overview?" shows `msg:unpublish-impact` with the count from `BlockImpact`, focus on Cancel, and a destructive button "Unpublish Revenue overview"
**And** on confirm the state becomes Unpublished, the Block leaves the Add-blocks Panel and search, and the Audit Event `blocks.block.unpublished` is written.

**Given** a Block is Unpublished
**When** a user's dashboard is read
**Then** each Instance has state `unpublished` (precedence below `access_removed`) and shows `msg:block-unpublished` ("This block is no longer available. You can remove it.") with Remove, and no data
**And** Remove works as for any item; position of other items is unchanged.

**Given** an Unpublished Block
**When** Publish is requested again
**Then** the latest version can be published again via the Draft path and Instances recover on next refresh (lifecycle: Published -> Unpublished -> Published).

**Given** a Published or Unpublished Block
**When** the Admin chooses Archive
**Then** the dialog "Archive Revenue overview? It becomes read-only." shows the impact count while the Block is published
**And** on confirm the state is Archived, read-only, edit and restore are refused with 409, an audit `blocks.block.archived` is written, and it disappears from Published lists and the Add-blocks Panel.

**Given** the lifecycle table
**When** transitions are tested
**Then** exactly these are allowed: Draft -Publish-> Published; Published -Edit-> new Draft (Published stays live); Published -Unpublish-> Unpublished; Published/Unpublished -Archive-> Archived; every other transition answers 409.

**Given** the event `blocks.block.unpublished` is emitted
**When** consumed
**Then** the "was unpublished" notification event is produced for affected users (UI is Epic 8).

**Given** an Admin without `blocks.publish`
**When** viewing the row menu
**Then** Unpublish and Archive are `aria-disabled` with `msg:perm-publish`, and API calls answer 403 with a security event.

**Given** confirmation completes
**When** the dialog closes
**Then** focus returns to the invoker's row, or to the list if the row moved.

### Story 6.10: Manage Block categories and tags

As an Admin,
I want to create, rename, order and archive categories and tag Blocks,
So that users can find Blocks through meaningful chips and search.

**Requirements:** FR-43, FR-40, UX-DR-261, UX-DR-115, UX-DR-263, UX-DR-31, UX-DR-37, UX-DR-214, UX-DR-282

**Acceptance Criteria:**

**Given** Block categories exist (minimal catalogue from the wizard category select)
**When** an Admin opens Block categories
**Then** a `data-table` lists name, order, Block count, status with create, rename, reorder (keyboard-operable move up/down) and archive actions, using the generic list states
**And** every change is audited (`blocks.category.created|renamed|reordered|archived`).

**Given** a category name already used in the Workspace
**When** an Admin creates or renames to it
**Then** the API answers 422 with an inline field error and no row is created.

**Given** a category with Published Blocks is archived
**When** confirmed
**Then** it can no longer be chosen for new Blocks, existing Blocks keep it, and the Admin sees the count of affected Blocks in the confirmation
**And** no category with zero visible Blocks produces a chip.

**Given** each Block has exactly one primary category and optional tags
**When** a Block is saved
**Then** a missing or archived primary category fails validation and tags are stored normalised (trimmed, case-insensitive unique).

**Given** the Add-blocks Panel (Epic 4)
**When** it loads for a user
**Then** its chips are exactly the primary categories that have at least one Block available to that user, in the Admin's order, excluding Blocks in "On your dashboard"
**And** tags are searchable with highlights but create no chips, and the chip row is single-select with one tab stop and arrow navigation.

**Given** the order is changed
**When** a user next opens the Panel
**Then** the chips follow the new order without a deploy.

**Given** a non-Admin
**When** they call the category APIs
**Then** the API answers 403 and a security event is recorded.

### Story 6.11: Block management, Draft blocks and Published blocks lists

As an Admin,
I want one place to find and act on every Block,
So that I can manage the whole library without hunting.

**Requirements:** FR-38, FR-44, FR-40, UX-DR-115, UX-DR-261, UX-DR-263, UX-DR-255, UX-DR-272, UX-DR-271, UX-DR-40, UX-DR-45, UX-DR-282

**Acceptance Criteria:**

**Given** Blocks in various states
**When** an Admin opens Block management
**Then** all non-deleted Blocks are listed with search and filters by category, type, status and owner, and a count caption announced politely
**And** the row menu offers Edit, Duplicate, Unpublish, Archive and Versions.

**Given** Draft blocks and Published blocks pages
**When** they load
**Then** each shows name, category, type, version, owner, last updated and status badge, and is filterable by category, type and owner
**And** sortable headers are buttons with `aria-sort`.

**Given** the Admin chooses Duplicate on a Block
**When** confirmed
**Then** a new Block is created as a Draft v0.1-equivalent first Draft (named "Copy of ..."), owned by the actor, with no grants copied and a fresh access subject on publish
**And** an audit `blocks.block.duplicated` is written and the source is unchanged.

**Given** search finds nothing
**When** results render
**Then** `msg:list-no-match` with Clear search and "0 of n" is announced politely
**And** an empty library shows `msg:list-empty` with "Create block".

**Given** a save or action succeeds or fails
**When** the list updates
**Then** the affected row is highlighted and focused on success, and failure rolls back with `msg:toast-rollback`.

**Given** an Admin without `blocks.publish`
**When** viewing rows
**Then** Unpublish and Archive are disabled with `msg:perm-publish`, while Edit and Duplicate remain available
**And** an Admin page opened without the Admin permission shows `msg:perm-denied`, is not rendered, and is audited.

**Given** a User-area session
**When** it requests the management API
**Then** it answers 403 (area=Admin and permission required).

### Story 6.12: Restrict a Block to selected user groups

As an Admin with the access.manage permission,
I want to limit a Block to selected groups with a clear impact warning,
So that only the right people can use it.

**Requirements:** FR-7, FR-40, AR-6, AR-7, AD-4, AD-5, AD-2, UX-DR-178, UX-DR-271, UX-DR-272, UX-DR-282

**Acceptance Criteria:**

**Given** a Block (subject registered with mode All users by default)
**When** an Admin with `access.manage` chooses Selected groups and picks groups
**Then** the `msg:access-impact` dialog ("38 users will lose this block.") opens with focus on Cancel and Confirm / Cancel
**And** the count comes from `AccessImpact`: active memberships allowed now and not allowed after the change.

**Given** the dialog is confirmed
**When** the change commits
**Then** `access_subjects.mode` and `access_grants` (keyed by Block identity, not version) are written in one transaction with the Audit Event `access.grant.changed` (before/after) and the outbox event
**And** no Block Version is created and the current version is unchanged.

**Given** Selected groups with none chosen
**When** Publish or Save access is attempted
**Then** `msg:group-required` blocks it in the wizard step 5 and the access API answers 422.

**Given** an Admin holding `blocks.publish` but not `access.manage`
**When** they try to change access
**Then** the control is `aria-disabled` with an inline reason and the API answers 403 with a security event
**And** an Admin with `access.manage` but not `blocks.publish` can change access but cannot publish.

**Given** access is changed in a new Draft's step 5 before first publish
**When** the Block is first published
**Then** the grants are applied with the registration in the same transaction.

**Given** publish, restore, archive or unpublish occur
**When** they commit
**Then** no grants are read or written (tests for each).

**Given** a Template includes the Block
**When** this story ships
**Then** Template access is out of scope (Epic 7 reuses the same subject mechanism).

**Given** the change is effective
**When** the next request arrives
**Then** the decision is made on that request with no cached decision beyond the request.

### Story 6.13: Enforce access in every list with "no longer available to you"

As a User,
I want to see only Blocks I may use, and a clear placeholder when I lose access,
So that I never see data I should not and know what happened.

**Requirements:** FR-7, AR-6, AR-7, AR-23, AR-32, AD-4, UX-DR-235, UX-DR-214, UX-DR-221, UX-DR-146, UX-DR-282

**Acceptance Criteria:**

**Given** a Block restricted to the group Finance
**When** a user outside Finance opens the Add-blocks Panel, global search source, Templates source or category chips
**Then** the Block is absent everywhere because every list filters by `WHERE id IN (AccessEvaluator::visibleSubjectIds(type, membership, area))`
**And** a property test shows the PHP `canUse()` path and the SQL path agree for random group/grant combinations.

**Given** a user whose group loses access to a Block already on their dashboard
**When** the dashboard is read or its channel re-authorizes
**Then** the item state is `access_removed` with no data (result precedence above `unpublished`), shown as `msg:block-access-removed` ("This block is no longer available to you.") with Remove
**And** Remove still succeeds (ownership only) and the layout of other items is unchanged.

**Given** access is later restored
**When** the user refreshes
**Then** the Block shows data again at its previous position.

**Given** the user calls `/api/v1/dashboards/{id}/results` for a Block they cannot use
**When** serialized
**Then** access is re-evaluated per item and the response contains the state only, with no `render_payload`
**And** a Reverb private block channel subscribe is refused and a membership change forces re-authorization.

**Given** an Admin in the Admin area
**When** they open Block management
**Then** Admin lists use the same evaluator fragment with area=Admin and show all Blocks.

**Given** a cross-Workspace attempt
**When** a user requests another Workspace's Block by ID
**Then** the response is 404 with no hint of existence.

**Given** the drawer count, empty and none-available states
**When** access hides all Blocks
**Then** `msg:drawer-none` is shown and the count announces "0 available blocks".

## Epic 7: Templates and Onboarding

Admins can publish Dashboard Templates with Mandatory Blocks and choose the default Template that seeds every new user's Overview. Users can start dashboards from Templates, duplicate them and reset them, and Admins can see how many dashboards each Template produced.

### Story 7.1: Build a Template draft in the Template editor

As an Admin with the `templates.manage` permission,
I want to arrange published Blocks on a grid in a Template editor and save the result as a draft,
So that I can prepare a starting layout for users without making it available yet.

**Requirements:** FR-45, AR-3, AR-4, AR-17, AR-21, AR-42, UX-DR-210, UX-DR-230, UX-DR-149, UX-DR-115, UX-DR-263, UX-DR-261, UX-DR-159, UX-DR-161

**Acceptance Criteria:**

**Given** an Admin in the Admin area holds `templates.manage`
**When** they open "Dashboard templates" from the sidebar
**Then** a `data-table` lists the Workspace's Templates (name, state, version, owner, last updated) with the generic list states of UX-DR-263 (5 skeleton rows on cold load, `msg:list-empty` with the primary action "Create template" when none, `msg:list-no-match` with Clear search)
**And** the page only lists Templates of the active Workspace (RLS, `workspace_id` on `templates`)

**Given** the Admin selects "Create template"
**When** the Template editor opens
**Then** it shows Name*, Description and a grid canvas that is always in Edit Layout Mode using the shared `Platform\Layout` kernel (12 columns, same grid library as Edit Layout Mode and the wizard preview)
**And** Save draft is available and a Template Draft is created (at most one Draft per Template, compare-and-set on `revision`)

**Given** the Template editor is open
**When** the Admin chooses "+ Add block"
**Then** the list contains only published Blocks and "Add {Block}" places the Block at the first free grid position at its default size, computed by the same Layout kernel `next_free_slot` used for users
**And** existing Blocks never move or resize, and a Block cannot be added twice

**Given** a Block is on the Template canvas
**When** the Admin drags, resizes, or uses the keyboard (M or Space, arrows, Shift+arrows, Enter, Esc) or the menu items Move up, Move down, Make wider, Make narrower
**Then** size snaps to the Block's allowed presets and min/max, overlapped Blocks are pushed down, nothing compacts upward, and the Block can be removed from the canvas through its ⋯ menu
**And** narrow-screen reflow is display-only and never writes positions

**Given** the Name field is empty
**When** the Admin selects Save draft
**Then** the save is rejected with the inline `msg:field-error` and an error summary focused on activation, and no revision is written

**Given** a valid Draft
**When** the Admin selects Save draft
**Then** `msg:saved` is shown with focus staying on Save draft, and an Audit Event is written in the same transaction
**And** an Admin without `templates.manage` receives 403 on every Template endpoint and does not see "Dashboard templates" actions enabled (aria-disabled with reason)

**Given** the save request fails
**When** the Admin retries
**Then** `msg:save-failed` is shown in form wording, entered values are kept and focus moves to the message

**Given** a Template Draft with Blocks
**When** it is saved twice with a stale `revision`
**Then** the second write returns 409 with the current state and nothing is overwritten

**Given** the Template editor is opened for the first time
**When** the first Draft is saved
**Then** an expand-only migration in this story has created `templates` and `template_versions` (workspace-scoped, RLS forced), following the same Draft pattern as Blocks (one Draft per Template, compare-and-set on `revision` and `lock_epoch`, immutable once published)
**And** `dashboard_pending_mandatory` is created later, in Story 7.8, because that is the first story that needs it

### Story 7.2: Mark Blocks as Mandatory in the Template editor

As an Admin,
I want to mark some Blocks on a Template as Mandatory,
So that users cannot remove the Blocks my organisation requires.

**Requirements:** FR-45, UX-DR-210, UX-DR-34, UX-DR-46, UX-DR-254, UX-DR-271

**Acceptance Criteria:**

**Given** a Block is on the Template canvas
**When** the Admin opens its ⋯ menu
**Then** a `menuitemcheckbox` "Mandatory" with the visible word On or Off is offered, rendered with the `switch` (`role="switch"`, `aria-checked`, Space toggles)
**And** the setting is saved with the Draft

**Given** Mandatory is switched On
**When** the canvas re-renders
**Then** the Block shows the lock badge `msg:locked` ("Required by your admin") with text always present, announced "required by your admin and cannot be removed"
**And** the Block can still be moved and resized in the Template editor

**Given** a Mandatory Block in the Draft
**When** the Admin removes it from the canvas
**Then** it is removed from the Draft (Mandatory only constrains users, not the Template author)
**And** the removal is not blocked

**Given** the Admin switches Mandatory On for a Block already on the Template
**When** the Draft is saved
**Then** no dashboard is changed (Templates never write dashboards; propagation happens only on publish, Story 7.12)

### Story 7.3: Protect the Template editor with an edit lock

As an Admin,
I want only one Admin to edit a Template at a time with a safe take-over,
So that nobody's changes are silently overwritten.

**Requirements:** FR-45, AR-19, AR-42, UX-DR-250, UX-DR-249

**Acceptance Criteria:**

**Given** Admin A has the Template editor open
**When** Admin B opens the same Template
**Then** B sees a read-only view with the `msg:draft-locked` banner and buttons "Take over editing" and Close
**And** the lock is `edit_lock:{ws}:template:{id}` via `Platform\EditLock`, with a heartbeat on user activity

**Given** B selects "Take over editing"
**When** the server sends `platform.edit_lock.flush_requested` to A and waits for acknowledgement or timeout
**Then** the Template's `lock_epoch` is incremented in PostgreSQL, B loads the post-flush revision and A is notified with `msg:draft-taken-over`
**And** A's valid non-secret fields are saved if A is online, otherwise discarded with a notice

**Given** A still holds a stale `lock_epoch`
**When** A saves
**Then** the write is rejected with `423 platform.edit_lock_lost` and A's view becomes read-only with current state

**Given** the holder is inactive for the lock timeout (about 15 minutes) or closes the tab
**When** the lock expires or the release endpoint is called (including via `sendBeacon`)
**Then** the lock is released and another Admin can edit without take-over

**Given** the session is about to expire
**When** 2 minutes remain
**Then** the session warning (UX-DR-249) appears in the Template editor, and after expiry unsaved fields are not restored

### Story 7.4: Control Template access with `access.manage`

As an Admin holding `access.manage`,
I want to limit a Template to all users or selected groups,
So that only the right users see it in the gallery.

**Requirements:** FR-45, FR-7, AR-6, AR-7, AR-3, AR-36, UX-DR-210, UX-DR-271

**Acceptance Criteria:**

**Given** a Template is created
**When** the transaction commits
**Then** it is registered as an Access subject through `Access\Contracts\RegisterAccessSubject` in the same transaction with default mode "all users"
**And** Template grants are keyed by Template identity, never by version

**Given** the Template editor's Access section (same control as wizard step 5)
**When** an Admin without `access.manage` views it
**Then** it is read-only (aria-disabled with reason), and a direct API write returns 403 even if the Admin holds `templates.manage` or `blocks.publish`

**Given** an Admin with `access.manage` selects "Selected groups" and chooses none
**When** they save
**Then** `msg:group-required` is shown and nothing is written

**Given** an access change would remove the Template from users
**When** the Admin confirms
**Then** an alertdialog shows `msg:access-impact` (for example "38 users will lose this block" with the Template wording), initial focus on Cancel, Esc cancels
**And** on Confirm the change is effective on the next request, creates no Template version, writes an Audit Event and emits `access.template.changed`

**Given** a Template is published, unpublished, restored or archived
**When** the operation completes
**Then** grants are neither read nor written (test: restore does not re-grant)

**Given** a user outside the granted groups
**When** they request the Template by ID
**Then** the same `AccessEvaluator` fragment used for Blocks yields 404, and the Template is never listed in the gallery, the Add-blocks Panel or search

### Story 7.5: Publish a Template for the first time

As an Admin holding both `templates.manage` and `blocks.publish`,
I want to publish a validated Template draft,
So that users can start dashboards from it.

**Requirements:** FR-45, FR-40, AR-17, AR-18, AR-35, AR-36, UX-DR-210, UX-DR-272

**Acceptance Criteria:**

**Given** a Template Draft with a valid name and only published, accessible-to-the-workspace Blocks
**When** the Admin selects "Publish template"
**Then** a `template_validation` Operation runs (Templates via Blocks contracts) and a validation summary is shown; a first publish shows only the validation summary and no impact dialog
**And** on success the Template becomes Published as v1.0, `current_version_id` is set, `templates.version.published` is emitted and an Audit Event is written

**Given** the Draft contains a Block that was unpublished or archived since it was added
**When** validation runs
**Then** publishing is blocked with the offending Block named, aria-disabled with the reason inline, and the Draft is unchanged

**Given** the Draft `revision` moved after validation
**When** publish is attempted
**Then** the stale report is rejected (`revision` CAS) and the Admin must re-validate

**Given** an Admin holds `templates.manage` but not `blocks.publish`
**When** they view or call Publish
**Then** the control is aria-disabled with the reason and the API returns 403; saving drafts still works

**Given** a published version
**When** anything tries to UPDATE it
**Then** the database trigger rejects it (versions are immutable; only draft edits, draft to prepared and prepared to published are allowed)

**Given** the Template is published
**When** a user's gallery loads
**Then** the Template is visible only per its access mode (Story 7.4)

### Story 7.6: Publish updates with impact, view versions, restore, unpublish and archive

As an Admin,
I want to see impact before publishing a new Template version and to manage versions and lifecycle,
So that I understand the consequences and can recover older layouts.

**Requirements:** FR-45, FR-40, FR-47, AR-17, AR-18, AR-19, UX-DR-179, UX-DR-208, UX-DR-209, UX-DR-271, UX-DR-21

**Acceptance Criteria:**

**Given** a published Template and a Draft with changes
**When** the Admin selects "Publish template"
**Then** the dialog titled "Publish {Template} v1.1?" shows what changed, the Template impact line "{n} dashboards were created from this template. Existing dashboards don't change, except that newly mandatory blocks are added at the end of their layouts." where n = `COUNT(dashboards WHERE template_id)`, and buttons "Publish v1.1" and Cancel
**And** focus opens on the title, Cancel is first in Tab order, Esc cancels, and the dialog never opens without the publish permission

**Given** the Admin confirms
**When** the new version is published
**Then** existing dashboards are not rearranged, `templates.version.published` is emitted with `mandatory_added` listing only Blocks newly Mandatory in this version, and an Audit Event is written

**Given** "Versions" in the row menu of Dashboard templates
**When** it is opened
**Then** a `data-table` lists versions newest first (version, Lifecycle State Draft/Published/Unpublished/Archived, published by, date, change summary), the current version tagged "Current" and older Published rows "Superseded by v1.1"; the word "Live" is not used
**And** View opens a read-only preview

**Given** Restore as draft on v1.1 with no existing Draft
**When** the Admin confirms
**Then** a new Draft is created from that version, `msg:restore-done` is shown, and the editor opens
**And** the action needs `blocks.publish` and writes an Audit Event

**Given** a Draft already exists
**When** Restore is chosen
**Then** `msg:restore-replace` appears with Cancel focused and "Replace draft"; the request requires `expected_draft_revision` and `replace_draft=true`, and bumps `revision`; a Draft is never discarded silently

**Given** Unpublish or Archive on a Template
**When** the Admin opens the confirmation
**Then** an alertdialog with the destructive button repeating the object name ("Unpublish {Template}", "Archive {Template}") and Cancel with initial focus is shown, the action is audited, and the Template leaves the gallery
**And** existing dashboards created from it are not changed or deleted

### Story 7.7: Browse the Templates gallery with preview

As a user,
I want to see the Templates I may use with a preview,
So that I can choose a starting dashboard.

**Requirements:** FR-50, FR-7, AR-6, UX-DR-167, UX-DR-159, UX-DR-263

**Acceptance Criteria:**

**Given** a user opens "Templates" in the User area
**When** the page loads
**Then** the gallery lists only Published Templates returned by the `AccessEvaluator` fragment for this membership and area, each with name, description and "Preview" and "Use template" actions

**Given** the user opens a Template preview
**When** the preview renders
**Then** it shows the Template's current published layout with generated sample values (never the user's or organisation's figures) and the label `msg:sample-drawer`
**And** no source API is called and no Block Result is computed for the preview

**Given** a Template includes a Block the user cannot access
**When** the preview opens
**Then** that Block is omitted and `msg:template-block-omitted` states the count (for example "1 block in this template isn't available to you.")

**Given** no Template is available to the user
**When** the gallery loads
**Then** `msg:templates-none` is shown instead of an empty page

**Given** a Template limited to another group, or Unpublished or Archived
**When** the user opens the gallery, searches, or requests it directly
**Then** it is never listed and a direct request returns 404

**Given** the gallery fails to load
**When** the error occurs
**Then** "We couldn't load templates. Try again." with Retry is shown

### Story 7.8: Create a dashboard from a Template

As a user,
I want "Use template" to create a new dashboard with the Template's layout,
So that I start from a good arrangement.

**Requirements:** FR-50, FR-45, FR-49, AR-20, AR-21, UX-DR-167, UX-DR-163, UX-DR-254

**Acceptance Criteria:**

**Given** a user selects "Use template" on a Template they can use
**When** they confirm the name (default "{Template} – {user first name}", for example "Finance weekly – Jamie")
**Then** `CreateFromTemplate` creates a dashboard owned by the user with `template_id` and `template_version_id` set to the current published version, items copied with their position, size and `mandatory` flag, and `revision` 1
**And** it opens with the Template layout and Mandatory Blocks locked (`msg:locked`), and appears in My dashboards and the dashboard switcher

**Given** the Template contains a Block the user cannot access (`canUse` false)
**When** the dashboard is created
**Then** the Block is omitted (remaining items keep their template geometry), and `msg:template-block-omitted` states the count
**And** nothing is recorded as an error, and omitted Mandatory Blocks are recorded in `dashboard_pending_mandatory` (table created in this story; Story 7.13 re-applies them when access is granted)

**Given** the Template is unpublished or archived between gallery load and creation
**When** the user confirms
**Then** the request fails with a clear error, no dashboard is created, and the gallery refreshes

**Given** the user's name is empty or already used by another of their dashboards
**When** they confirm
**Then** an empty name shows `msg:field-error`; a duplicate name is allowed only if the dashboards list rules from Epic 4 permit it, otherwise the same inline validation applies

**Given** the user holds two dashboards
**When** they switch between them
**Then** both layouts remain intact

**Given** `CreateFromTemplate` is retried with the same idempotency key
**When** the second request arrives
**Then** exactly one dashboard exists

**Given** a Mandatory Block cannot be added because the user lacks access to it
**When** the dashboard is created from the Template
**Then** an expand-only migration in this story has created `dashboard_pending_mandatory` (workspace-scoped, RLS, dashboard, block, template version) and a row is stored for each skipped Mandatory Block, which is counted in the omission message

### Story 7.9: See how many dashboards came from each Template

As an Admin,
I want to see adoption counts per Template,
So that I know which Templates users actually use.

**Requirements:** FR-48, AR-6, UX-DR-258, UX-DR-115

**Acceptance Criteria:**

**Given** dashboards created by `CreateFromTemplate`, by default-Template seeding (Story 7.11) or by Duplicate (Story 7.15)
**When** an Admin opens "Dashboard templates"
**Then** each row shows "Dashboards created" = count of the Workspace's dashboards with that `template_id`
**And** deleting a dashboard reduces the count

**Given** the Admin overview in Epic 8 needs "Template adoption"
**When** it asks the Dashboards read contract
**Then** it receives the share of dashboards with a non-null `template_id` over all dashboards in the Workspace; the reporting window and definition come from a `pending_input` setting, not hard-coded numbers

**Given** an Admin without access to Dashboard templates
**When** they call the adoption endpoint
**Then** the response is 403

**Given** counts are read from another Workspace's context
**When** RLS applies
**Then** zero rows from other Workspaces are counted

### Story 7.10: Keep Mandatory Blocks on the dashboard, including through Undo and Reset

As an Admin,
I want Mandatory Blocks to be impossible to remove by any path,
So that required Blocks always remain visible to users.

**Requirements:** FR-45, FR-47, FR-57, AR-20, UX-DR-254, UX-DR-217, UX-DR-220, UX-DR-225, UX-DR-231, UX-DR-215, UX-DR-272

**Acceptance Criteria:**

**Given** a dashboard item with `mandatory = true`
**When** a user calls `RemoveItem` (UI or API)
**Then** the command is rejected (422 with the dashboards error code), the dashboard `revision` is unchanged and no event is emitted

**Given** the Add-blocks Panel
**When** a Mandatory Block is listed
**Then** it appears in the "On your dashboard" group with the lock badge, announced "required by your admin and cannot be removed", excluded from the count but still searchable, with no "× Remove"

**Given** the Block Chrome ⋯ menu
**When** the Block is Mandatory
**Then** "Remove from dashboard" is disabled (aria-disabled, reason `msg:locked`)
**And** the Block can still be moved and resized (including Move up, Move down, Make wider, Make narrower)

**Given** a user removed a Block, then an Admin later made it Mandatory and it was re-added by `ApplyMandatory`
**When** the user triggers Undo of the earlier removal or Add (Ctrl+Z / the toast Undo)
**Then** the Undo does not remove the now-Mandatory item and the toast reports the reason

**Given** a user adds a Block, the Block later becomes Mandatory on their dashboard, and the user presses Undo on the original add
**When** the command runs
**Then** it is rejected by the same rule

**Given** `RestoreItem` for a Mandatory Block
**When** the item is absent
**Then** it is restored (Mandatory items are always present); when present, the command is idempotent

### Story 7.11: Seed every new user's Overview from the default Template

As an Admin,
I want to choose the Template that seeds new users' Overview,
So that new users land on a useful dashboard.

**Requirements:** FR-46, FR-45, FR-49, AR-20, AR-36, UX-DR-262, UX-DR-166

**Acceptance Criteria:**

**Given** an Admin with `settings.manage` and a Published Template visible to all users
**When** they choose "Set as default for new users" from the Template's row menu
**Then** `workspace_settings.default_template_id` is stored with a bumped settings `revision` and an Audit Event; the System settings screen presentation of this setting is delivered in Epic 8

**Given** a Template limited to selected groups, or not Published
**When** the Admin tries to set it as default
**Then** the request is rejected with a field error and nothing changes

**Given** a default Template is set
**When** a new membership is activated and the user's Overview is created
**Then** it is created through `CreateFromTemplate` semantics with `is_overview = true`, `template_id` and `template_version_id` set, Mandatory items flagged, and the Overview counts toward adoption
**And** any omitted inaccessible Block follows Story 7.8

**Given** no default Template is set, or it was unpublished or archived later
**When** a new user's Overview is created
**Then** the Overview is blank (the Epic 4 behaviour) and no error is shown

**Given** the default Template changes
**When** existing users' Overviews exist
**Then** they are not changed

**Given** a seeded Overview
**When** the user opens My dashboards
**Then** the dashboard stores its Template provenance (`template_id`, `template_version_id`), which is what lets Story 7.14 offer "Reset to template", and Delete stays disabled with `msg:overview-delete`

### Story 7.12: Add newly Mandatory Blocks to existing dashboards

As a user,
I want Blocks my admin newly marks Mandatory to appear on my dashboards from that Template,
So that I always have the required Blocks without my layout being rearranged.

**Requirements:** FR-47, AR-20, AR-21, AR-36, UX-DR-229, UX-DR-71, UX-DR-277, UX-DR-254

**Acceptance Criteria:**

**Given** a Template publish that makes Block X newly Mandatory
**When** Dashboards consumes `templates.version.published{mandatory_added}` as system actor
**Then** `ApplyMandatory` runs per dashboard with `template_id` = this Template, `SELECT ... FOR UPDATE`, `expected_revision` check, revision bump and `dashboards.dashboard.changed`
**And** consumption is recorded in `outbox_consumptions` and events older than the dashboard's last applied `subject_seq` are dropped

**Given** the dashboard does not contain X
**When** `ApplyMandatory` runs
**Then** X is added in `append` mode at the end of the layout (below the lowest item, at its default size via the Layout kernel), existing items keep position and size, and the item has `mandatory = true`, `mandatory_unseen = true`; if `compact_order` exists the item is appended to it

**Given** the dashboard already contains X
**When** `ApplyMandatory` runs
**Then** the item is flagged `mandatory = true` in place without moving or resizing, and `mandatory_unseen` stays false

**Given** a user opens a dashboard with an unseen Mandatory item
**When** the dashboard loads
**Then** the Block shows the just-added outline and "Just added" pill (about 3 s; static under reduced motion) and the user sees `msg:mandatory-added` naming the Block and dashboard; the in-app notification bell entry is out of scope here (Epic 8)
**And** `AcknowledgeMandatory` clears `mandatory_unseen` (once, idempotent) so the message does not repeat

**Given** the same event is delivered twice
**When** it is processed again
**Then** no duplicate item or second message is produced

**Given** a published update with no newly Mandatory Blocks
**When** it is published
**Then** `mandatory_added` is empty, no dashboard is touched and layouts do not move

**Given** a dashboard no longer matches the template (the user deleted it)
**When** the event is processed
**Then** it is not touched

### Story 7.13: Hold Mandatory Blocks until access is granted

As an Admin,
I want a Mandatory Block that a user cannot access to be held and added later,
So that access rules are never bypassed and the user gets the Block once allowed.

**Requirements:** FR-47, FR-7, AR-6, AR-21, AR-36, UX-DR-167, UX-DR-254

**Acceptance Criteria:**

**Given** `ApplyMandatory` for a user whose `canUse` on Block X is false
**When** it runs
**Then** X is not added, a row is recorded in `dashboard_pending_mandatory` (dashboard, block, template), and nothing is shown to the user for X

**Given** the same situation at `CreateFromTemplate` or seeding
**When** the dashboard is created
**Then** the omission is counted in `msg:template-block-omitted` and the Mandatory Block is recorded as pending

**Given** an `access.*.changed` event grants the user access to X
**When** Dashboards consumes it
**Then** pending rows for affected memberships are re-evaluated with the `AccessEvaluator`; for each now accessible Block `ApplyMandatory` appends it (append mode), flags `mandatory_unseen`, and the user sees `msg:mandatory-added` on next load
**And** the pending row is removed

**Given** access remains denied after an `access.*.changed` event
**When** it is processed
**Then** the pending row is kept

**Given** the Template later stops marking X Mandatory, or the dashboard is deleted
**When** the next publish or deletion happens
**Then** the pending row is removed and X is never added

**Given** the Block X is unpublished or archived while pending
**When** access is granted
**Then** nothing is added

### Story 7.14: Reset a dashboard to its Template

As a user,
I want to reset a Template-created dashboard to the Template's current layout,
So that I can get back to a clean starting point.

**Requirements:** FR-57, FR-47, AR-20, AR-21, UX-DR-166, UX-DR-271, UX-DR-21, UX-DR-272

**Acceptance Criteria:**

**Given** My dashboards lists a dashboard with `template_id` (including a seeded Overview)
**When** the user opens its row menu or the dashboard ⋯
**Then** "Reset to template" is offered; on blank dashboards and dashboards without a Template it is not offered

**Given** the user selects Reset to template
**When** the alertdialog opens
**Then** it shows `msg:reset-template` ("Reset Finance weekly – Jamie to the template's current layout? Your blocks, positions and sizes on this dashboard will be replaced. This can't be undone."), a destructive button "Reset Finance weekly – Jamie" and Cancel with initial focus; Esc cancels and focus returns to the invoker

**Given** the user confirms
**When** `ResetToTemplate` runs
**Then** the dashboard's items are replaced by the Template's current published version (its blocks, positions, sizes and Mandatory flags), `template_version_id` is updated, `compact_order` is cleared, `revision` is bumped and `dashboards.dashboard.changed` is emitted
**And** no Undo is offered and no toast Undo is shown

**Given** the Template includes a Block the user cannot access
**When** the reset runs
**Then** that Block is omitted with `msg:template-block-omitted`, and omitted Mandatory Blocks are recorded in `dashboard_pending_mandatory`

**Given** the user previously added extra Blocks
**When** the reset runs
**Then** the extra Blocks are removed (they were not Mandatory) and Mandatory Blocks the user could previously have moved are placed per the Template, and none is ever removed

**Given** the Template is now unpublished or archived
**When** the user selects Reset
**Then** the action is disabled with the reason (aria-disabled), or fails with a clear error and the dashboard is unchanged

**Given** a stale `expected_revision`
**When** the command runs
**Then** it returns 409 with the current state and nothing is replaced

### Story 7.15: Duplicate a dashboard with its Template provenance

As a user,
I want to duplicate one of my dashboards,
So that I can experiment without losing my current layout.

**Requirements:** FR-49, FR-57, FR-48, AR-20, AR-6, UX-DR-166, UX-DR-163

**Acceptance Criteria:**

**Given** My dashboards lists a dashboard (including Overview)
**When** the user selects Duplicate
**Then** `DuplicateDashboard` creates a new non-Overview dashboard owned by the same membership with a name the user confirms (prefilled default, editable) and appears in My dashboards and the dashboard switcher
**And** `is_overview` is always false for the copy, and the original is unchanged

**Given** the source has items with positions, sizes, minimized state, period selections and Date Range
**When** the copy is created
**Then** layout, minimized state, `period_selection` and `date_range` are copied and `compact_order` is copied as-is

**Given** the source was created from a Template
**When** the copy is created
**Then** `template_id` and `template_version_id` are copied (provenance), so "Reset to template" is available on the copy and the copy counts toward adoption (Story 7.9)
**And** `mandatory` flags are preserved on copied items so Mandatory Blocks cannot be removed by duplicating

**Given** the source has a Block the user can no longer access
**When** the copy is created
**Then** that item is omitted from the copy (access-removed items are not copied) and a message states the omitted count with the same pattern as `msg:template-block-omitted`

**Given** the source has unseen Mandatory items
**When** the copy is created
**Then** `mandatory_unseen` is reset to false on the copy

**Given** a blank dashboard without a Template
**When** it is duplicated
**Then** the copy has no `template_id` and no Reset action

**Given** another user's dashboard ID
**When** Duplicate is called
**Then** the response is 404

## Epic 8: Find, Be Notified and Operate

Users can search across what they are allowed to see and receive in-app notifications; Admins can see Workspace activity and platform health, configure Workspace settings and retention, and search and export the audit log. Everything is permission-filtered through the AccessEvaluator and Workspace-scoped.

### Story 8.1: Fan out in-app notifications for Users

As a User,
I want to be told when a new Block I can use appears, when a Block on my dashboard changes version or is unpublished, when I was previewed as, and when a Mandatory Block is added,
So that I notice changes to my dashboards without hunting for them.

**Requirements:** FR-64, AR-36, AR-23, AR-43, AR-27, UX-DR-257, UX-DR-282

**Acceptance Criteria:**

**Given** the Notifications module consumes outbox events emitted by earlier epics (`blocks.version.published`, Block unpublished, `access.*` changes, preview-as use, Mandatory Block appended)
**When** migrations run
**Then** `notifications` (workspace-level row, `source_event_id` unique, `type`, `subject`, `created_at`) and `notification_reads` (per membership) exist with non-null `workspace_id`, ENABLE and FORCE RLS, expand-only
**And** a query with no Workspace context returns zero rows (AD-3 test)

**Given** an Admin publishes the first version of a Block
**When** the `notifications` queue processes the event
**Then** one workspace-level "new Block available" row is written and members for whom `AccessEvaluator::canUse` holds at fan-out time can read it
**And** members who cannot use the Block never see it, in list, count or API response

**Given** an Admin publishes a new version of a Block that is on a User's dashboard
**When** the event is consumed
**Then** each owner of a dashboard containing it gets a per-member notification rendered with `msg:notify-version`
**And** owners of dashboards without that Block get none

**Given** a Block on a User's dashboard is unpublished
**When** the event is consumed
**Then** the dashboard owners get a "Block unpublished" notification linking to the dashboard (the Unpublished face is shown by Epic 6 behaviour)

**Given** an Admin with `data.preview_as_user` previews as a target User (AD-30)
**When** the preview-as use is recorded
**Then** the target User gets a "Previewed as you" notification naming the Admin, in addition to the audit event

**Given** a Template update makes a Block Mandatory and appends it to a User's layout
**When** the mandatory flag is set (`mandatory_unseen`)
**Then** the owner also gets a notification row linking to the dashboard and the existing `msg:mandatory-added` banner behaviour is unchanged

**Given** the same outbox event is delivered twice
**When** the consumer runs again
**Then** no duplicate notification is created (`notifications(source_event_id)` unique and `outbox_consumptions`)
**And** a `notification.created` event carrying only IDs is pushed on `workspace.{ws}.membership.{m}` and clients refetch over `/api/v1` (polling fallback keeps the same semantics)

**Given** a notification exists for a removed membership
**When** `access.membership.removed` is consumed
**Then** that member's notifications and read state are deleted

### Story 8.2: Use the notification bell and mark notifications read

As a User or Admin,
I want a bell with an unread count and a list of my notifications,
So that I can see what changed and clear it in one action.

**Requirements:** FR-64, UX-DR-257, UX-DR-140, UX-DR-83, UX-DR-10, UX-DR-263, UX-DR-270, UX-DR-282, AR-27

**Acceptance Criteria:**

**Given** a member with 3 unread notifications
**When** the top bar renders
**Then** the bell shows the number 3 in an error-filled pill (a number, not only a dot) with an accessible name that includes the count
**And** the count updates without reload when `notification.created` arrives

**Given** the member opens the bell
**When** `GET /api/v1/notifications` returns
**Then** a 360px popover lists items newest first, each with a 28px icon tile, one sentence, relative time and a link
**And** unread items show a 6px accent dot AND bold text
**And** only notifications addressed to this member in the active Workspace are returned (no other Workspace, no other member)

**Given** unread items
**When** the member activates "Mark all as read"
**Then** `notification_reads` rows are written for this member, the count becomes 0 and focus stays on the control
**And** a failure rolls back with `msg:toast-rollback` (role=alert, not auto-dismissed)

**Given** the member activates one notification link
**When** it opens
**Then** that item is marked read and the target (dashboard, Block in Add-blocks Panel, Data source, Block) opens only if still permitted; otherwise the generic `msg:perm-denied` page applies

**Given** no notifications
**When** the popover opens
**Then** `msg:notifications-empty` is shown centred in text-muted

**Given** keyboard-only use
**When** the member tabs to the bell
**Then** it opens with Enter/Space, Esc closes and returns focus to the bell, and the toast region "Notifications" stays distinct from the popover (landmarks)
**And** the list load failure shows the generic "We couldn't load {items}. Try again." with Retry

**Given** the member switches Workspace
**When** the bell refreshes
**Then** it shows only the new Workspace's notifications

### Story 8.3: Alert Admins on publications, source health and repeated mapping failures

As an Admin,
I want in-app alerts for other Admins' publications, Data Source health changes and repeated mapping failures with match suggestions,
So that I can react before users lose data.

**Requirements:** FR-64, AD-1, AR-46, AR-2, UX-DR-257, UX-DR-204, UX-DR-282, NFR-13, AR-25

**Acceptance Criteria:**

**Given** Admin A publishes a Block
**When** the event is consumed
**Then** every other Admin in the Workspace gets a per-member notification; Admin A does not
**And** Users do not receive this type

**Given** a Data Source health state changes (`healthy`, `degraded`, `unreachable` per the existing health calculation)
**When** the change event is consumed
**Then** Admins holding `data_sources.manage` get a notification showing the dot-and-word state and a link opening the Data source form
**And** Admins without `data_sources.manage` get none

**Given** a published Block version's drift check marks a Slot `unavailable` on repeated payloads
**When** the repeat threshold (a `pending_input` setting) is reached
**Then** a `mapping_health_incidents` row (workspace-scoped, RLS, expand-only migration) is opened once per Block version and Slot, with match suggestions computed while old and new payloads both existed
**And** the Block owner and Admins with `blocks.edit` are notified with `msg:notify-mapping-failed`

**Given** an incident has a match suggestion
**When** an Admin opens the notification link
**Then** the Block shows `msg:api-match-found` with the suggested field as a proposal that is never applied silently
**And** accepting it creates a new Draft version on the normal publish path (UX-DR-204)

**Given** the Slot recovers on a later payload
**When** the next drift check passes
**Then** the incident is marked resolved and no further notifications are sent for it

**Given** an incident persists
**When** further failing payloads arrive
**Then** no duplicate notification is created for the same incident

**Given** a User who is not an Admin
**When** they request `/api/v1/mapping-health-incidents`
**Then** the response is 403 with the error envelope and no incident detail

### Story 8.4: Search dashboards, Blocks and Templates with permission filtering

As a User,
I want to search my dashboards, Blocks and Templates,
So that I can jump to what I need without browsing.

**Requirements:** FR-63, AR-6, AR-27, AR-36, UX-DR-256, UX-DR-167, AD-4

**Acceptance Criteria:**

**Given** the Search module is deployed
**When** migrations run
**Then** `search_documents` (workspace_id, group, subject_type, subject_id, `tsvector` column, trigram index via `pg_trgm`, owner membership where relevant) exists with RLS and an expand-only migration

**Given** subject events (Block published/renamed/unpublished, Template published, dashboard created/renamed/deleted)
**When** the outbox consumer processes them
**Then** `search_documents` rows are upserted or deleted idempotently, ignoring events older than the subject's last applied `subject_seq`
**And** Block names, descriptions and tags are indexed; tags create no category chips

**Given** the endpoints `GET /api/v1/search?group=dashboards|blocks|templates&q=`
**When** a User queries
**Then** each group is answered by its own request and returns independently
**And** Blocks and Templates are filtered only with `WHERE id IN (AccessEvaluator::visibleSubjectIds(...))`; Dashboards only the member's own

**Given** a Block or Template limited to a group the User is not in
**When** the User searches its exact name
**Then** it is absent from results (never listed in search, drawer or gallery)

**Given** a typo such as "revnue overview"
**When** the query runs
**Then** trigram similarity still returns "Revenue overview"; matches rank by text rank then similarity
**And** an empty or one-character `q` returns 422 with the error envelope

**Given** a Block's access is changed (`access.manage`) so the User loses it
**When** the User searches again
**Then** it no longer appears on the next request (no cached visibility beyond the request)

**Given** a Block result is selected
**When** the client resolves its target
**Then** the target is "open Add-blocks Panel with that row focused and expanded" and a Template result opens the Templates gallery preview
**And** the PHP and SQL visibility paths agree in the existing property test, extended to the Search subjects

**Given** a membership is removed
**When** `access.membership.removed` is consumed
**Then** that member's dashboard search documents are deleted

### Story 8.5: Search data sources, users and settings as an Admin

As an Admin,
I want search to include Data Sources, Users and Settings pages according to my permissions,
So that I can reach any Admin surface quickly without seeing what I may not manage.

**Requirements:** FR-63, AR-6, UX-DR-256, UX-DR-255, AD-4

**Acceptance Criteria:**

**Given** an Admin in the Admin area holding `data_sources.manage`
**When** they request `GET /api/v1/search?group=data_sources&q=`
**Then** Data Sources of the active Workspace matching by name or host return, each linking to its form

**Given** an Admin without `data_sources.manage`
**When** they request the `data_sources` group
**Then** the response is 403 with the error envelope and the group is not offered in the palette

**Given** an Admin holding `users.manage`
**When** they query the `users` group
**Then** members of the active Workspace match by name or email and link to User configuration
**And** members of other Workspaces never appear, and a plain User gets 403

**Given** the `settings` group
**When** an Admin queries "retention"
**Then** only settings entries whose page the Admin may open (`settings.manage` for System settings, `audit.view` for Audit log, `users.manage` for User configuration) are returned
**And** an Admin with none of these gets an empty group, not the entries

**Given** a User (not in the Admin area) or an Admin in the User area
**When** they search
**Then** only the Dashboards, Blocks and Templates groups exist

**Given** a User is deactivated
**When** the next search runs
**Then** they no longer appear in `users` results

**Given** denied group requests
**When** they occur repeatedly
**Then** each denial is recorded via `Audit::recordSecurityEvent` (denied access)

### Story 8.6: Use the global search palette

As a User or Admin,
I want a ⌘K palette that shows grouped results as each group loads,
So that I can find and open things from anywhere.

**Requirements:** FR-63, UX-DR-256, UX-DR-139, UX-DR-269, UX-DR-263, UX-DR-282, UX-DR-270, NFR-7

**Acceptance Criteria:**

**Given** any page with the top bar
**When** the user presses ⌘K (macOS) or Ctrl+K, or activates the top-bar "Search anything" control
**Then** a 560px combobox popover opens with the input focused
**And** the shortcut works even when the single-key shortcuts setting is Off (modifier shortcuts remain)

**Given** the user types a query
**When** the group requests (one per group) are in flight
**Then** each loaded group is shown immediately under a label-caps header while still-loading groups show two skeleton rows
**And** "Searching…" is announced once after about 1 s, not repeatedly

**Given** results are displayed
**When** the user presses Arrow Down/Up
**Then** the active option has `listbox-option-active` and is referenced by `aria-activedescendant`
**And** Enter opens the target (dashboard, Add-blocks Panel with the Block row focused and expanded, Templates preview, data source form, user, settings page) and Esc closes returning focus to the opener

**Given** a User
**When** the palette lists groups
**Then** only Dashboards, Blocks, Templates are shown; an Admin in the Admin area also sees Data Sources, Users, Settings per their permissions

**Given** no group returns a match
**When** the loading finishes
**Then** `msg:search-empty` is shown

**Given** one group request fails
**When** the others succeed
**Then** the successful groups remain and the failed group shows an inline retry row; the palette is not blocked

**Given** a result is activated while the user is in the Create-block wizard with unsaved changes
**When** navigation would leave it
**Then** `msg:unsaved-changes` is shown first

**Given** mobile width below 640px
**When** the palette opens
**Then** it fits the viewport with no horizontal scroll and targets of at least 44px

### Story 8.7: Search the audit log

As an Admin with `audit.view`,
I want to search the Workspace audit log by actor, object, action and time and inspect before/after,
So that I can answer who changed what, and when.

**Requirements:** FR-68, AR-25, UX-DR-261, UX-DR-115, UX-DR-262, UX-DR-263, UX-DR-255, UX-DR-159, AD-18, AD-29

**Acceptance Criteria:**

**Given** an Admin holding `audit.view`
**When** they open Admin > Audit log
**Then** a `data-table` lists Audit Events newest first with time, actor, action, object, outcome and a "Details" disclosure
**And** the table is a real `table`, sortable headers are buttons with `aria-sort`, and the count is announced politely

**Given** the filters actor, object (type and name), action (from the closed `AuditAction` enum, AD-29 grammar) and a time range
**When** the Admin applies them
**Then** `GET /api/v1/audit-events` returns only matching events of the active Workspace, paginated
**And** an action value outside the enum returns 422 with the error envelope

**Given** an event row
**When** the Admin expands it
**Then** it shows before and after state from the module's allowlist `AuditSerializer`, with attribute values, header values and samples shown only as hashes, never as raw values or secrets
**And** the request ID is shown with a "Copy request ID" control (Admin area only)

**Given** no events match
**When** results load
**Then** `msg:list-no-match` with Clear search and "0 of n" announced politely; with no events at all `msg:list-empty`

**Given** an Admin without `audit.view`
**When** they request the page or API
**Then** the page shows `msg:perm-denied` and is not rendered, the API returns 403, and the denial is audited
**And** the Audit log navigation item is not shown to them

**Given** a "View all" request with a Block-events filter
**When** the page loads with `?object_type=block`
**Then** the filter is preselected and applied

**Given** the app database role
**When** the Audit log feature is used
**Then** no update or delete endpoint exists and the `app` role still has INSERT and SELECT only on `audit_events`

**Given** an operator or system action recorded in `operator_audit`
**When** mirrored into the Workspace log
**Then** it appears with an "Operator" actor label and cannot be edited

### Story 8.8: Export the audit log as a CSV

As an Admin with `audit.view`,
I want to export filtered Audit Events to a safe CSV after re-entering my password,
So that I can share evidence with auditors without injection risk.

**Requirements:** FR-68, AR-25, AR-35, AR-38, UX-DR-261, UX-DR-263, UX-DR-255, AD-18, AD-28

**Acceptance Criteria:**

**Given** the Audit log with filters applied
**When** the Admin activates "Export CSV"
**Then** a `password.confirm` dialog opens (alertdialog semantics, focus per UX-DR-271 for sensitive actions)
**And** with a wrong password no Operation is created, an inline error is shown and the failure is audited

**Given** a correct password re-confirmation
**When** the export starts
**Then** an `audit_export` Operation is created (kind per AD-28) for the requester membership with the current filters and `subject_revision`
**And** an `audit.export.requested` Audit Event is written in the same transaction

**Given** the Operation completes
**When** the requester polls `/api/v1/operations/{id}`
**Then** a download is offered only to the initiator; another Admin gets 404/403
**And** the result body lives as an encrypted Valkey blob with TTL, never stored in PostgreSQL; after expiry the Operation reports expired

**Given** any cell whose value begins with `=`, `+`, `-`, `@`, tab or carriage return
**When** the CSV is written
**Then** it is neutralized (prefixed so spreadsheets do not evaluate it) and the injection test fixtures pass
**And** the CSV contains the same hashed-only values as the UI, with request ID and before/after columns

**Given** repeated export requests
**When** the per-membership or per-workspace rate limit is exceeded
**Then** the API returns 429 with `retry_after` and the UI shows the reason inline

**Given** an Admin without `audit.view`
**When** they call the export endpoint
**Then** the response is 403 and the attempt is audited

**Given** the export exceeds a size cap (`pending_input` setting)
**When** the Operation runs
**Then** it fails with a clear message to narrow the time range, and nothing is truncated silently

### Story 8.9: See Admin overview metrics and recent block activity

As an Admin,
I want an Admin overview with usage metrics and the latest Block activity,
So that I can see how the Workspace is used and what changed.

**Requirements:** FR-65, FR-48, UX-DR-258, UX-DR-259, UX-DR-112, UX-DR-113, UX-DR-86, UX-DR-263, AR-57, AD-18

**Acceptance Criteria:**

**Given** an Admin in the Admin area
**When** they open Admin overview
**Then** four `admin-metric-card`s show Published blocks (change this month), Active dashboards (change this week), Template adoption (% of dashboards created from a Template, from the Epic 7 counts) and Active users (count and share active this week)
**And** changes begin with ↗/↘ and a sign, success-text good, error bad, text-muted flat; a share that is not a change is plain text-muted with no arrow

**Given** the metric windows and the definition of "active"
**When** values are computed
**Then** the windows come from `pending_input` settings (Overview metric windows) with no invented defaults, using the PRD labels (this month, this week) until configured
**And** Active dashboards uses `dashboards.last_viewed_at` and Active users uses `workspace_memberships.last_active_at`; background calls (`X-Background: 1`) never update `last_active_at`

**Given** zero dashboards or zero users
**When** percentages are computed
**Then** division by zero renders a dash with an accessible "No data" name, not 0%

**Given** "Recent block activity"
**When** the panel loads
**Then** it shows the five latest publish or draft actions from Blocks' own activity read model (not from the audit log), newest first, each an `activity-admin-row` with category icon tile, Block name, "Published by …" or "Draft by …" and time (relative within a day, "Yesterday", then date)
**And** a row opens Block management for that Block

**Given** the Blocks activity read model
**When** an Admin publishes or saves a Draft
**Then** the activity row appears after the event is consumed
**And** an archived or deleted Block does not break the panel

**Given** the Admin has `audit.view`
**When** they activate "View all →"
**Then** the Audit log opens filtered to Block events; without `audit.view` the link opens Block management instead and is not offered as Audit log

**Given** the "+ Create block" primary action
**When** an Admin without `blocks.edit` views the page
**Then** the button is `aria-disabled` with an inline reason (`msg:perm-denied` pattern); a plain User requesting the page gets 403

**Given** the page fails to load a panel
**When** one query errors
**Then** that card or panel shows the generic load-failure row with Retry while the others render

### Story 8.10: Monitor platform and data source health

As an Admin,
I want a Platform health panel showing the four platform services and my data sources,
So that I know whether problems are on the platform or on a source API.

**Requirements:** FR-66, NFR-6, NFR-13, AR-53, AR-31, AR-2, AR-46, AR-57, UX-DR-260, UX-DR-114, AD-24, AD-1

**Acceptance Criteria:**

**Given** the Health module
**When** migrations run
**Then** `service_health_samples` exists as a global table (no `workspace_id`, in the CI global-table allowlist) with service, status, latency and `sampled_at`, expand-only

**Given** the `maintenance` job
**When** it runs at the health-sampling interval (`pending_input`)
**Then** it samples Dashboard API (`web`), Data refresh service (`scheduler` + `worker-connector` + `worker-compute`), Authentication (`web` auth path) and Notification service (`realtime` + `notifications`) via the role health endpoints `/health/live` and `/health/ready`
**And** each sample carries `request_id`, and metrics `dashflow.health.*` are emitted without secrets (scrubbing processor)

**Given** samples exist
**When** an Admin opens the Platform health panel
**Then** each service shows an 8px dot, the word Operational/Degraded/Outage and uptime % over the uptime window (`pending_input`), with accessible text such as "Dashboard API, Operational, 99.99% uptime"
**And** a dot is never shown without its word; an overall badge reads Operational, Degraded or Outage

**Given** "Your data sources"
**When** the panel renders
**Then** each source of the active Workspace shows dot, Healthy/Degraded/Unreachable and "Last success 10:42" and opens its form; sources of other Workspaces never appear
**And** a newly saved source shows "Checking…" until its first probe

**Given** the overall state becomes Degraded or Outage
**When** the sampling job records it
**Then** all Admins in every affected Workspace get a notification (reusing Story 8.3's channel) once per transition, not on every sample

**Given** no samples exist yet or the sampler is stale
**When** the panel loads
**Then** it shows an "unknown" word and does not claim Operational

**Given** a plain User
**When** they request the health API
**Then** the response is 403; service samples never include Workspace data

### Story 8.11: Configure Workspace system settings

As an Admin with `settings.manage`,
I want one System settings page for defaults and limits,
So that the Workspace behaves consistently and I can see the rules Blocks run under.

**Requirements:** FR-67, FR-10, FR-46, FR-8, NFR-11, UX-DR-262, UX-DR-263, UX-DR-253, UX-DR-255, AR-57, AR-25, UX-DR-282

**Acceptance Criteria:**

**Given** an Admin with `settings.manage`
**When** they open System settings
**Then** sections show: Host allowlist (read-only list with a link to its management from Epic 2 and the `require_https` state), Allowed refresh intervals, Fetch limits, Locale, time zone and currency, Template for new users, User attributes available for user-context binding (view with link to User configuration where managed), and Help links
**And** skeleton fields load first with Save disabled until loaded; load failure shows "We couldn't load these settings. Try again." with Retry, never stale values

**Given** the allowed refresh intervals and fetch limits
**When** the Admin saves
**Then** values are validated against the pending_input platform ceilings (no invented numbers), saved with `revision` CAS, and `msg:saved` is shown with focus staying on Save
**And** a concurrent change returns 409 with current state and no overwrite

**Given** an interval is removed that published Blocks use
**When** the Admin saves
**Then** the impact list of affected Blocks is shown first; confirmed removal clamps to the nearest allowed interval and notifies Admins (AR-44), and the Block wizard step 2 lists only the saved set

**Given** locale, time zone and currency defaults
**When** saved
**Then** they become the Workspace defaults for formatting (user locale first, then these, then `en`) and a settings change updates compute context so affected Block Results recompute
**And** invalid IANA zones, locales or ISO 4217 codes return 422 with inline field errors and an error summary

**Given** the "Template for new users"
**When** the Admin picks one
**Then** only published Templates are offered; the choice applies to users created after the change; existing dashboards are untouched (FR-47)

**Given** an Admin without `settings.manage`
**When** they open or call the page
**Then** `msg:perm-denied`, 403 on the API, and the attempt is audited

**Given** any successful save
**When** the transaction commits
**Then** an Audit Event with before/after (allowlisted fields only) and a `workspace.settings.changed` outbox event are written together
**And** leaving with unsaved changes shows `msg:unsaved-changes` with Keep editing focused; the theme default setting is not rendered (light only, UX-DR-286)

**Given** help links
**When** the Admin adds a label and an http(s) URL
**Then** non-http(s) URLs are rejected with `msg:url-invalid`; saved links are stored per Workspace for Story 8.14

### Story 8.12: Set retention with an operator floor and delayed reductions

As an Admin with `settings.manage`,
I want to set how long audit events and platform-held operational records are kept,
So that we meet our policy without ever dropping below the operator's minimum.

**Requirements:** FR-67, FR-68, NFR-13, AR-25, AR-57, AD-18, C15, UX-DR-262, UX-DR-263, UX-DR-255

**Acceptance Criteria:**

**Given** System settings > Retention
**When** the Admin opens it
**Then** two groups are shown separately per C15: "Audit events" (immutable evidence) and "Operational records" (`sync_runs`, `operations`, notifications, data-access logs)
**And** copy states that the external log and telemetry sink retention is a deployment control outside this setting

**Given** the operator floor and defaults (`pending_input`, no invented values)
**When** the Admin enters a retention period below the floor
**Then** the API returns 422 `settings.retention_below_floor` with the floor value and the form shows it inline; nothing is saved
**And** an increase is effective immediately

**Given** the Admin reduces a period at or above the floor
**When** they confirm (alertdialog stating which records will become eligible for deletion and when)
**Then** the new value is stored as pending with an effective time after the reduction delay (`pending_input`), shown in the UI as "takes effect {time}"
**And** an Audit Event `retention changed` (before/after) is written, a security alert is raised for retention reductions, and the Admin can cancel the pending reduction before it takes effect (audited)

**Given** `password.confirm` guards sensitive changes
**When** the Admin submits a retention reduction
**Then** password re-confirmation is required

**Given** the `maintenance` role runs retention sweeps
**When** the effective period has elapsed for records
**Then** it row-deletes expired `audit_events`, `sync_runs`, `operations` and notifications per Workspace using the `maintenance` DB role, never newer than the effective period and never below the operator floor
**And** the `app` role still cannot delete audit rows, and a sweep writes a summary event (counts only, no content)

**Given** a reduction is pending
**When** a sweep runs before the effective time
**Then** it deletes only under the old (longer) period

**Given** a Workspace without a setting
**When** a sweep runs
**Then** the deployment default applies and nothing below the floor is removed

**Given** an Admin without `settings.manage`
**When** they call the retention endpoints
**Then** 403 and the attempt is audited

### Story 8.13: Offer an optional regulated data-access log

As an operator or Admin of a regulated Workspace,
I want an optional mode that records who viewed which Block,
So that regulated customers can review read access without burdening everyone else.

**Requirements:** FR-67, FR-68, NFR-13, AR-25, AD-18, C15, UX-DR-262, UX-DR-263

**Acceptance Criteria:**

**Given** the Workspace setting "Data-access log" (off by default, available only where the deployment enables regulated mode)
**When** it is off
**Then** no read-access rows are written and the System settings control states it is unavailable or off with a reason

**Given** the mode is on
**When** a membership views a Block
**Then** a `data_access_logs` row aggregated daily per membership and Block (workspace_id, membership, block, day, count) is upserted from the existing view events, with no data values stored
**And** writes happen asynchronously on a queue and never delay dashboard rendering

**Given** the log exists
**When** it is retained
**Then** its retention uses the "Operational records" period from Story 8.12 (C15) and the maintenance sweep covers it

**Given** an Admin with `audit.view`
**When** they filter the Audit log by "Data access"
**Then** aggregated rows can be searched by member, Block and day; plain Admins without `audit.view` see 403

**Given** the mode is toggled
**When** the Admin saves with password re-confirmation
**Then** an Audit Event is written and the tamper-evident hash-chain seam stays a documented extension point; no hash chain is built in this story

### Story 8.14: Manage profile, shortcuts and find help

As a User or Admin,
I want a Profile & settings page and a Help & support page,
So that I can manage my account and know who to contact.

**Requirements:** FR-4, FR-67, UX-DR-169, UX-DR-34, UX-DR-269, UX-DR-286, UX-DR-263, UX-DR-274, UX-DR-211, AR-38, UX-DR-282

**Acceptance Criteria:**

**Given** a signed-in member
**When** they open the settings gear
**Then** a User in the User area lands on Profile & settings and an Admin in the Admin area on System settings; Appearance is not rendered anywhere

**Given** Profile & settings
**When** the member edits name, avatar, locale and time zone
**Then** values save with `msg:saved`, validation errors are inline with an error summary, and form fields carry `autocomplete` attributes (`name`, `email`)
**And** an avatar upload accepts only image types within a size limit (`pending_input`), strips metadata and is served without script execution
**And** locale and time zone override the Workspace defaults for that member

**Given** the password section
**When** the member changes their password
**Then** the current password and `password.confirm` are required, a wrong current password returns an inline error, new and confirm use `new-password`, and other sessions' rotation follows AD-31
**And** email change is shown only if the deployment enables self-service (otherwise operator-only text)

**Given** the Keyboard shortcuts `switch` (default On, "On"/"Off" word visible)
**When** the member turns it Off
**Then** single-key shortcuts (/, A, M) stop firing and modifier shortcuts (⌘K/Ctrl+K, Ctrl+Z) plus arrows, Enter, Space, Esc and Tab keep working
**And** the preference persists server-side and applies on next load; a theme control is not rendered (UX-DR-286)

**Given** Help & support
**When** any member opens it
**Then** the Admin-configured help links from System settings show with "Contact your workspace administrator"; with no links only the contact line shows
**And** links open with `rel="noopener noreferrer"`, and no domain-specific product copy appears

**Given** a save failure or offline state
**When** the member saves
**Then** `msg:save-failed` keeps values with focus on the message, and offline shows `msg:offline-editing` with Save disabled and an inline reason

## Epic 9: Production-Ready Deployment

Operators can deploy Dashflow with Docker Compose or Helm, scale it, back it up, recover it, rotate keys, upgrade without downtime and load-test it, meeting the non-functional requirements. This epic hardens and productises what Epic 1 delivered (starter kit, CI baseline, Compose dev environment); it does not redo them.

### Story 9.1: Build and publish a signed multi-stage image

As an operator,
I want one signed, reproducible OCI image that runs all five process roles,
So that every deployment model promotes the same verified artifact.

**Requirements:** AR-47, AR-2, AR-50, NFR-14, AD-1, AD-22

**Acceptance Criteria:**

**Given** a tagged commit on the release branch
**When** the CI image stage runs
**Then** a multi-stage build runs Composer, Vite on Node 22, and a PHP 8.5 runtime containing `bcmath`, `pgsql`, `redis` and `uv`, with nginx (plus php-fpm for the web role)
**And** the final stage contains no Composer, Node, build tools or dev dependencies.

**Given** the built image
**When** it is started with each role command (`web`, `realtime`, `scheduler`, `worker-connector`, `worker-compute`)
**Then** each role boots from the same image digest with behaviour selected only by command and environment
**And** starting with an unknown role value exits non-zero with a clear message.

**Given** the built image
**When** `php -m` is inspected in a CI check
**Then** a missing `bcmath` or `uv` extension fails the stage.

**Given** a pushed image
**When** the signing step completes
**Then** the image is signed with cosign and the signature verifies against the published public key or keyless identity
**And** an unsigned or tampered image fails the verification step.

**Given** the image runs as configured
**When** its runtime user and filesystem are inspected
**Then** it runs as a non-root user and works with a read-only root filesystem, writing only to declared tmpfs or volume paths.

### Story 9.2: Run a production-style single-host stack with Docker Compose

As an operator,
I want a Compose file that brings up every role with its dependencies,
So that a customer's IT can self-host Dashflow on one machine.

**Requirements:** AR-48, AR-52, AR-50, NFR-14, NFR-3, AD-17, AD-22

**Acceptance Criteria:**

**Given** a host with Docker and a filled `.env` from the documented template
**When** `docker compose up -d` runs
**Then** `web`, `realtime`, `scheduler`, `worker-connector`, `worker-compute`, PostgreSQL 18 and two Valkey instances (queue and cache) start from the same app image digest
**And** all services report healthy through their `/health/live` and `/health/ready` checks.

**Given** the stack starts on a fresh database
**When** the `migrate` one-shot service runs
**Then** application roles start only after it exits successfully (`depends_on: service_completed_successfully`)
**And** a failing migration leaves the application roles not started and exits non-zero.

**Given** the two Valkey instances
**When** their configuration is inspected
**Then** the queue instance uses `noeviction` and the cache instance uses an LRU policy with TTLs
**And** neither is published on a host port, and each role connects with its own ACL user.

**Given** the `pgbouncer` Compose profile is enabled
**When** the application connects through it in transaction mode (version 1.21 or later, `max_prepared_statements` greater than 0)
**Then** the app works with no other change
**And** with the profile disabled the app connects to PostgreSQL directly.

**Given** the key-to-role mapping (AR-50)
**When** the Compose secrets are inspected
**Then** `web` mounts `data` and `digest`, `worker-connector` mounts `cred`, `token` and `data`, `worker-compute` mounts `data`, and `realtime` and `scheduler` mount no key
**And** `scheduler` runs as exactly one replica.

**Given** a missing required environment variable
**When** the stack starts
**Then** the affected service fails fast naming the variable, without printing any secret value.

### Story 9.3: Deploy to Kubernetes with a hardened Helm chart

As an operator,
I want a Helm chart that deploys each role as a scalable, disruption-safe workload,
So that Dashflow scales horizontally and survives node maintenance.

**Requirements:** AR-49, AR-50, NFR-3, NFR-6, NFR-14, AD-1, AD-22

**Acceptance Criteria:**

**Given** the chart is installed with default values
**When** the rendered manifests are inspected
**Then** there is one workload per role (`web`, `realtime`, `scheduler`, `worker-connector`, `worker-compute`) using the same image reference
**And** `scheduler` is fixed at one active instance protected by the scheduler mutex.

**Given** HPA is enabled for a role
**When** load exceeds that role's configured target
**Then** the HorizontalPodAutoscaler for that role scales it between its configured min and max, independently of other roles
**And** the scaling targets come from values, with no numbers invented in the chart defaults beyond clearly marked `pending_input` placeholders.

**Given** a node drain
**When** pods of a role are evicted
**Then** its PodDisruptionBudget keeps at least the configured minimum available
**And** the `scheduler` PDB does not block a drain permanently.

**Given** any role pod
**When** its security context is inspected
**Then** `automountServiceAccountToken` is false, the seccomp profile is `RuntimeDefault`, the root filesystem is read-only, it runs as non-root, and all capabilities are dropped
**And** `realtime` has a raised file-descriptor limit.

**Given** liveness and readiness probes
**When** a role is unready (database or Valkey unreachable)
**Then** `/health/ready` fails and traffic is withheld while `/health/live` still passes.

**Given** `helm lint` and `helm template` with `kubeconform` run in CI
**When** a template violates the schema or the hardening checks above
**Then** the CI stage fails.

### Story 9.4: Isolate the network and secrets in the Helm chart

As an operator,
I want default-deny networking and one Secret per key purpose,
So that a compromised web pod cannot reach source APIs or foreign keys.

**Requirements:** AR-49, AR-50, AR-52, NFR-4, NFR-14, AD-6, AD-17

**Acceptance Criteria:**

**Given** the chart is installed
**When** NetworkPolicies are listed
**Then** a default-deny policy covers ingress and egress for the namespace
**And** explicit allow rules exist only for ingress to `web` and `realtime`, and for each role to PostgreSQL (or PgBouncer), the matching Valkey instance, SMTP and the OTLP collector.

**Given** the `web` pod
**When** it attempts to open a connection to an arbitrary external host
**Then** the connection is blocked by NetworkPolicy
**And** only `worker-connector` has egress to external addresses, via EgressGuard or the optional enforcing proxy.

**Given** separate Kubernetes Secrets for `cred`, `token`, `data`, `digest`, `APP_KEY`, database passwords, Reverb and Valkey
**When** each workload spec is inspected
**Then** each role references only the Secrets its purpose requires per the AR-50 mapping
**And** a render test fails if `realtime` or `scheduler` references any key Secret, or `web` references `cred` or `token`.

**Given** Valkey is deployed by the chart or an external one is configured
**When** a client connects without TLS or with the default user
**Then** the connection is refused
**And** `queue` and `cache` are separate instances or databases with their own ACL users, `noeviction` and LRU+TTL respectively.

**Given** the optional PgBouncer is enabled
**When** it is rendered
**Then** it uses transaction mode with `max_prepared_statements` greater than 0 and version 1.21 or later.

**Given** the migrator
**When** an install or upgrade runs
**Then** it runs as a pre-install/pre-upgrade hook before workloads roll, and a failed hook aborts the release.

### Story 9.5: Upgrade without downtime and prove N-1 compatibility

As an operator,
I want rolling upgrades that never break a mixed-version fleet,
So that customers upgrade without maintenance windows.

**Requirements:** AR-51, AR-56, NFR-3, NFR-6, NFR-14, AD-32

**Acceptance Criteria:**

**Given** release N is built from a branch with new migrations
**When** the CI compatibility stage runs
**Then** it applies N's migrations, then starts release N-1 code against that schema and runs the N-1 smoke suite
**And** a contracting (drop/rename/not-null-without-default) migration in the same release as its expand step fails the stage (AD-32).

**Given** a mixed fleet of N-1 and N processes
**When** job payloads (versioned), outbox events, `FetchRequest` and `/api/v1` calls cross between them
**Then** each side accepts the other's contracts using the recorded fixtures
**And** a payload or event without a version field fails the contract test.

**Given** stored JSON fixtures from release N-1 (each with `schema_version`)
**When** the upcaster test runs under release N
**Then** every fixture reads correctly through the read-time upcaster, and published rows are not rewritten.

**Given** a rolling upgrade in staging with a continuous request probe
**When** `web`, `realtime` and workers are rolled
**Then** the probe records no failed request attributable to the rollout
**And** workers drain in-flight jobs before termination.

**Given** a failed rollout
**When** the operator redeploys N-1
**Then** the system returns to working state without restoring a backup, because migrations were expand-only.

**Given** the upgrade runbook for customer-hosted installs
**When** an operator follows the N-1 to N path
**Then** each step (backup, migrate, roll, verify, rollback) has a command and a verification check
**And** the same path is exercised in a staging job.

### Story 9.6: Back up and restore PostgreSQL and the keyring, with a restore drill

As an operator,
I want point-in-time backups and a tested restore procedure,
So that data and secrets are recoverable within targets set by the customer.

**Requirements:** AR-56, NFR-6, NFR-5, AD-34, AR-41

**Acceptance Criteria:**

**Given** the backup runbook
**When** PITR is configured for PostgreSQL (WAL archiving plus base backups)
**Then** a restore to a chosen timestamp is documented with exact commands for Compose and Kubernetes
**And** RPO and RTO appear only as `pending_input` settings, with no invented values.

**Given** the SecretVault keyring or KMS keys
**When** the backup procedure runs
**Then** the keyring is backed up to a location separate from the database backup
**And** the runbook states that losing it makes every secret unreadable and that Valkey is never backed up.

**Given** the staging restore drill
**When** a database is restored from a base backup plus WAL to a target time, with the keyring restored separately
**Then** a verification command confirms RLS is enabled with the expected policies, the application roles exist with the expected grants, and every `current_payload_id` pointer resolves to a stored payload
**And** a failing check exits non-zero with the failing object named.

**Given** a restored database without the matching keyring
**When** a stored credential is read
**Then** the operation fails with a clear error and no secret value is logged.

**Given** both Valkey instances are wiped
**When** the stack restarts
**Then** queues and cached results are rebuilt from PostgreSQL state and the reconciliation sweep
**And** dashboards show last-known-good data (stale where applicable) until fresh fetches complete, with no data loss in PostgreSQL.

### Story 9.7: Rotate APP_KEY, database passwords, Reverb and Valkey secrets

As an operator,
I want rotation runbooks and commands for platform secrets,
So that keys can be changed on schedule or after exposure without downtime.

**Requirements:** AR-50, NFR-6, NFR-4, AD-34, AD-19

**Acceptance Criteria:**

**Given** a new `APP_KEY` generated and the old one placed in `APP_PREVIOUS_KEYS`
**When** the rollout completes
**Then** existing sessions and encrypted values encrypted with the old key still decrypt
**And** new writes use the new key.

**Given** the old `APP_KEY` is removed from `APP_PREVIOUS_KEYS` before data is re-encrypted
**When** the verification command `dashflow:keys:verify` runs
**Then** it reports the count of values still needing the old key and exits non-zero without printing any value.

**Given** a database role password rotation
**When** the runbook is followed (set new password, update Secret, roll roles, revoke old)
**Then** no role loses connectivity longer than the rolling restart
**And** connections through PgBouncer pick up the new credential after its reload.

**Given** a Reverb app secret or Valkey ACL password rotation
**When** the runbook is followed (dual-valid period, roll consumers, remove old)
**Then** realtime connections reconnect with the new secret and queue jobs signed or queued before the change are still processed
**And** a client using the revoked secret is rejected after the cutover.

**Given** any rotation command
**When** it runs
**Then** an audit entry is written to `operator_audit` (mirrored) recording the purpose and actor, never the key material.

### Story 9.8: Re-wrap workspace DEKs and rotate digest keys with pre-warm

As an operator,
I want to rotate key-encryption keys and digest keys safely,
So that keys can change without breaking decryption or invalidating result caches.

**Requirements:** AR-50, NFR-5, NFR-6, AD-34, AD-19

**Acceptance Criteria:**

**Given** a new KEK version in the SecretVault (local keyring or KMS)
**When** `dashflow:keys:rewrap-deks` runs
**Then** every workspace DEK is re-wrapped under the new KEK without re-encrypting payload data
**And** the command is resumable and idempotent; re-running reports zero remaining.

**Given** a re-wrap interrupted mid-run
**When** the application serves requests
**Then** DEKs wrapped under either KEK version still decrypt
**And** no workspace is left with an unreadable DEK.

**Given** a new digest key version is introduced
**When** the pre-warm command (`dashflow:keys:digest-prewarm`) runs
**Then** Fetch Keys and result digests are computed under the new version for hot keys before it becomes active
**And** after activation, dashboards do not show a thundering-herd of upstream fetches (verified by the fetch-count metric staying within the per-Data-Source rate limit).

**Given** the old digest key version
**When** it has zero remaining references
**Then** the command reports so and only then allows retirement; retiring a version still in use is refused with a non-zero exit.

**Given** role separation
**When** the commands run
**Then** they run only in roles holding the needed key purpose (`web` or the operator job with `data`+`digest`), and fail fast elsewhere.

### Story 9.9: Operator commands for workspace, first admin and private-range grant

As an operator,
I want audited commands to create a workspace, invite its first Admin and grant private-range access,
So that a customer can be onboarded without direct database edits.

**Requirements:** FR-10, NFR-14, NFR-4, AD-6, AD-22

**Acceptance Criteria:**

**Given** a valid name and slug
**When** `dashflow:workspace:create` runs
**Then** the Workspace is created with default settings and a record is written to `operator_audit`
**And** a duplicate slug exits non-zero with no partial data.

**Given** an existing Workspace and an email address
**When** `dashflow:workspace:invite-admin` runs
**Then** a single-use invitation with the Admin role is created and emailed, and the link follows the standard invitation expiry
**And** an unknown Workspace, malformed email or already-active member exits non-zero with a clear message.

**Given** an operator grants a private CIDR for a Workspace with `dashflow:egress:grant`
**When** the command runs
**Then** an entry is stored with Workspace, CIDR, reason and operator, and an `operator_audit` record is written
**And** the next Test connection or fetch to that range from that Workspace passes EgressGuard while other Workspaces remain blocked.

**Given** a CIDR overlapping loopback, link-local, metadata or the deployment's own CIDRs
**When** a grant is attempted
**Then** it is refused with a non-zero exit, because no grant can lift those deny-list entries
**And** the refusal is audited.

**Given** `dashflow:egress:revoke`
**When** it runs for an existing grant
**Then** fetches to that range are blocked again and the revocation is audited.

**Given** a user without shell access to the platform
**When** they try the same actions in the UI or API
**Then** no such endpoint exists (404).

### Story 9.10: Gate releases on supply-chain checks

As an operator,
I want dependency, SBOM, signing and provenance gates in CI,
So that only vetted, traceable artifacts reach production.

**Requirements:** AR-47, AR-51, NFR-14, AD-22

**Acceptance Criteria:**

**Given** a merge request
**When** `composer audit` or `npm audit` reports a vulnerability at or above the configured severity
**Then** the pipeline fails
**And** an allow-listed advisory requires an expiry date and reason in the repository.

**Given** the dependency and license audit
**When** a dependency carries a disallowed license (for example BSL for bundled components)
**Then** the pipeline fails naming the package.

**Given** a release build
**When** the SBOM stage runs
**Then** an SBOM (CycloneDX or SPDX) for the image is generated and published with the release
**And** it lists the PHP, Node and OS packages in the image.

**Given** a release image
**When** the provenance stage runs
**Then** a SLSA provenance attestation is attached and verifiable with cosign alongside the signature
**And** base images and CI images are referenced by pinned digest; a tag-only reference fails a lint check.

**Given** the production deploy job
**When** it deploys an image
**Then** it verifies signature and attestation first and refuses an unverified image
**And** staging and production receive the same digest (promotion, not rebuild).

### Story 9.11: Run the security regression suite in CI

As a security engineer,
I want tenant-isolation, SSRF and XSS regression tests as blocking CI jobs,
So that the core security guarantees cannot regress unnoticed.

**Requirements:** AR-54, AR-52, NFR-4, AD-3, AD-6, AD-17

**Acceptance Criteria:**

**Given** a database fronted by PgBouncer in transaction mode
**When** requests for two Workspaces alternate on the same pooled connections across every tenant table
**Then** no row from the other Workspace is ever returned
**And** a query without tenant context returns no rows and an access attempt fails closed with `access.context_missing`.

**Given** the SSRF fixture set
**When** each URL form is submitted as base URL, OAuth token URL, pagination next URL and redirect target
**Then** every one is blocked and audited as `connector.ssrf_blocked`
**And** the fixtures include decimal, octal and hex IPv4 forms, IPv4-mapped and compatible IPv6, NAT64, 6to4, Teredo, ULA, `[::1]`, link-local, cloud-metadata, CGNAT, unspecified, multicast and DNS names resolving to those addresses, plus a rebinding case.

**Given** a private range with an operator grant
**When** the same fixtures targeting the deployment's own CIDRs run
**Then** they stay blocked.

**Given** the XSS fixture set per Block slot (title, text, labels, links, table cells, chart text)
**When** payloads are rendered
**Then** no script executes and no unsafe attribute or URL scheme is emitted
**And** CSV formula-injection fixtures are neutralised on export.

**Given** secret non-disclosure checks
**When** secrets are set and used
**Then** they never appear in API responses, logs, audit records or browser payloads.

**Given** any of these jobs fails
**Then** the pipeline is blocked and the failing fixture is named in the report.

### Story 9.12: Gate releases on cross-browser and accessibility tests

As a product owner,
I want the end-to-end suite to run on all supported browsers and pass axe,
So that NFR-7, NFR-8 and NFR-9 are enforced at every release.

**Requirements:** NFR-7, NFR-8, NFR-9, AR-51, UX-DR-264

**Acceptance Criteria:**

**Given** the Playwright suite
**When** it runs in CI
**Then** it executes on Chromium, Firefox and WebKit plus mobile emulation, with browserslist set to the latest two versions of Chrome, Edge, Firefox and Safari
**And** a failure in any browser fails the pipeline.

**Given** every primary screen (sign-in, dashboards, Block builder, Data Sources, Admin, notifications)
**When** axe runs at WCAG 2.1 AA in the light theme
**Then** zero violations of serious or critical impact are reported
**And** an exemption requires a tracked issue and expiry.

**Given** desktop, tablet and mobile viewports
**When** the core dashboard flow runs
**Then** it completes with no horizontal page scroll at the smallest supported width.

**Given** the keyboard-only layout editing flow
**When** the test runs
**Then** a Block can be moved and resized with the keyboard alone
**And** colour is not the only signal for state in the asserted screens.

### Story 9.13: Load-test the platform against sizing scenarios

As an operator,
I want a load-test harness with scenarios driven by the sizing parameters,
So that capacity and latency claims are measured against the customer's numbers.

**Requirements:** AR-55, NFR-1, NFR-2, NFR-3, AR-57

**Acceptance Criteria:**

**Given** the harness parameter file
**When** it is loaded
**Then** Workspaces, users, concurrent users, Blocks, dashboards, refresh intervals and API calls per minute are read from named `pending_input` sizing values
**And** a missing value fails the run with a message naming it, never substituting a default.

**Given** the dashboard results fan-in scenario
**When** N concurrent users open dashboards with M Blocks
**Then** first-render and result-fetch latency percentiles are reported against the NFR-2 targets, shown as `pending_input` until set
**And** the dashboards are served from stored results without waiting on source APIs.

**Given** the sync dispatch scenario with hot and cold keys
**When** it runs against a mock source API
**Then** upstream call counts, queue depth and wait time, and the per-Data-Source rate limit and per-workspace budgets are observed and reported
**And** requests over budget are throttled, not dropped silently.

**Given** the Reverb fan-out scenario
**When** a result change is published to many subscribed sockets
**Then** delivery lag percentiles and connection counts are reported
**And** the realtime role file-descriptor limit is verified.

**Given** a run completes
**When** the report is produced
**Then** it records the image digest, resource configuration, scenario parameters and a pass/fail against targets that are set
**And** unset targets are reported as "no target (pending_input)", not as pass.

### Story 9.14: Ship observability dashboards, alerts and health endpoints

As an operator,
I want dashboards, alerts and health endpoints for every role,
So that failures, abuse and drift are visible and traceable.

**Requirements:** NFR-13, NFR-6, AR-53, AR-45, AD-24

**Acceptance Criteria:**

**Given** the OTel collector configuration shipped with Compose and Helm
**When** the stack runs
**Then** traces, metrics and JSON logs reach the OTLP endpoint with `request_id` or `trace_id` and `workspace_id`
**And** a request's trace ID can be followed through its job, outbox event and Reverb message.

**Given** the scrubbing-processor test in CI
**When** spans and logs contain query strings, fragments, secret-bearing headers or non-allowlisted headers
**Then** they are stripped or dropped before export
**And** a removed scrubber fails the pipeline.

**Given** the shipped dashboards
**When** loaded in the customer's Grafana (or equivalent)
**Then** panels exist for queue depth and wait, compute duration and guard aborts, cache hit ratio, sockets, stale ratio, unavailable-slot incidents, sign-in failures, SSRF blocks, authorization denials, retention reductions and newly added `http` sources
**And** metric names follow `dashflow.<module>.<measure>`.

**Given** repeated `connector.ssrf_blocked` events from one Workspace
**When** the count exceeds the configured threshold (`pending_input`)
**Then** an alert fires with the Workspace and source named
**And** alert thresholds are settings, with none invented.

**Given** a Workspace reduces retention or adds a Data Source using plain `http`
**When** the audit event is emitted
**Then** a metric increments and the corresponding alert rule can fire.

**Given** each of the five roles
**When** `/health/live` and `/health/ready` are called
**Then** live reports process health only and ready reflects dependency reachability
**And** neither response exposes secrets or version details to unauthenticated callers beyond status.

### Story 9.15: Document deployment models and classification-driven defaults

As an operator,
I want deployment guides per hosting model and clear defaults per data classification,
So that a customer's IT can choose and configure the right setup.

**Requirements:** NFR-14, NFR-5, NFR-6, AR-57, FR-10, AD-6, AD-22

**Acceptance Criteria:**

**Given** the documentation set
**When** an operator opens it
**Then** there are guides for SaaS multi-tenant, dedicated and customer-hosted models, each listing topology, key placement, egress path, backups and upgrade path
**And** each guide uses the same image and migrations (AD-22).

**Given** the agent seam section
**When** read
**Then** it describes `FetchTransport` (`direct` now, `agent` later) and `FetchRequest` carrying `secret_refs` as the future connection point
**And** it states plainly that no agent is built in this release and private APIs are reached through the operator grant or customer-hosted deployment.

**Given** a Data Source with `data_classification=regulated`
**When** it is saved
**Then** the documented defaults apply: app-level raw payload encryption on, short `cold_purge_after`, `require_https` on, impersonation off, optional data-access log available
**And** the documentation lists these defaults and where each can be overridden by an Admin or the operator.

**Given** the `pending_input` settings list (AR-57)
**When** the documentation is generated from the settings registry
**Then** every setting and its owner appears with "pending" where no value exists
**And** a CI check fails if a registry setting is missing from the docs.

**Given** a runbook index
**When** it is checked in CI
**Then** each runbook from this epic (upgrade, backup, restore, rotation, operator commands, load test) exists and its internal links resolve.
