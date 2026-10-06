---
name: Dashflow Experience
status: final
created: 2026-10-05
updated: 2026-10-05
sources:
  - ../../briefs/brief-rmg-dashboard-platform-2026-10-02/brief.md
  - ../../prds/prd-rmg-dashboard-platform-2026-10-02/prd.md
  - ../../prds/prd-rmg-dashboard-platform-2026-10-02/addendum.md
---

# Dashflow: Experience Spine

**Tags (2026-10-05).**
- `[UX DECISION — aligned in PRD v3.2]`: a product-owner UX decision that differed from PRD v3.1. **PRD v3.2 now matches it.**
- `[UX DECISION — PRD alignment needed]`: a UX refinement that differs from the PRD wording. **The PRD needs updating to match it** before stories quote the PRD.
- `[DECISION …]`: a product-owner decision in the decision log ([.memlog.md](.memlog.md)), for example A2, T6 or R3.
- `[PROPOSED]`, `[ASSUMPTION]` and `[ASSUMPTION — accepted default]`: **accepted working defaults** (decision log, 2026-10-05), adjustable later without a product-level decision.
- `[OPEN — product decision]`: the UX treatment depends on a decision not yet made.
- `msg:key`: a row of **Canonical messages** (under Voice and Tone), the only copy of that string.

**Reading this file.** Terms follow the PRD Glossary (§3) verbatim. "Per FR-n" means PRD v3.2 holds the rule and this file adds only UX behaviour. `{group.token}` is a DESIGN.md frontmatter token; a backticked name such as `drawer-row` is a DESIGN.md component. States live only in State Patterns; keyboard and focus rules live only in Interaction Primitives.

**The spines (this file and [DESIGN.md](DESIGN.md)) win over every mockup in `imports/`, over the two hybrid sketches in `mockups/` ([mockups/mapdata-hybrid.html](mockups/mapdata-hybrid.html), [mockups/addblocks-hybrid.html](mockups/addblocks-hybrid.html)) and over the rejected alternatives (.working/) when they conflict.**

## Foundation

- **Form factor.** Responsive web for desktop, tablet and mobile (NFR-8, NFR-9); no native app. Desktop and laptop are the primary Admin surfaces; Users read and personalize on every size.
- **Two areas, one shell.** The **User area** ("Personal workspace") and **Admin area** ("System management") share the sidebar, top bar and breadcrumb shell, and differ in navigation and primary action.
- **Top-bar settings gear** (kept from the mockups) opens **Profile & settings** in the User area and **System settings** in the Admin area. [ASSUMPTION — accepted default]
- **Domain-agnostic content rule.** Product UI copy (navigation, labels, buttons, helper text, empty states, errors, notifications, help) contains **no RMG or other domain terms**. Domain terms appear **only in user-configured content**: Data Source, Block, Block Category and Dashboard Template names, descriptions, Text Templates and mapped API data. Names such as "Revenue overview", "Finance data warehouse" and "Pending approvals" are illustrative configured content (brief v4 status note; PRD §4.15, NFR-12).
- **Branding and theming.** Tokens and override guardrails: DESIGN.md → Brand & Style, Colors. Behaviour: the MVP ships Dashflow defaults; a future Workspace override swaps only brand tokens and the logo, at runtime; system tokens stay fixed, so statuses mean the same everywhere (V2). **Dark theme is deferred** (V3) [UX DECISION — aligned in PRD v3.2: NFR-10, FR-4]: tokens are dark-ready; the Appearance control and the mockups' theme toggle stay hidden until it exists; **dark-theme contrast is unchecked while deferred** [DECISION 2026-10-05, gaps] and is checked when the theme is built.
- **UI system.** [PROPOSED] Token-driven components on **accessible headless primitives** (shadcn/ui on Radix style), **one grid-layout library** (GridStack-class, shared by Edit Layout Mode, Dashboard Template editing and the wizard preview; addendum §F) and **one charting library** (for example ECharts; addendum §I). **The final choice belongs to architecture and implementation.** Required behaviour:
  - **Theming**: DESIGN.md tokens map to the library's CSS variables; no component-level hex; per-Workspace brand override at runtime.
  - **Non-modal side panel**: a right drawer that **pushes** content, a labelled landmark with skip links (F6 as an enhancement); a modal sheet below about 1024px.
  - **Grid**: first-free-slot placement on the saved 12-column layout; no compaction or rearranging on add; keyboard move and resize, clamped to allowed sizes; display-only narrow reflow that never writes positions back; **in-place updates with stable keys**.
  - **Charts**: the 6-colour palette in fixed order with the series-1 stroke token; legends, direct labels, dash/marker variants; tabular numerals; escaped text only (FR-37); reduced motion; **keyboard focus on data points**; generated summary; data-table alternative.
  - **Toasts**: Undo; minimum on-screen time with pause on hover and focus; polite live region (assertive for errors); placement override (top right of the dashboard area).
  - **Command palette** (⌘K), **popover menus** and **accordions** with full keyboard support.

## Information Architecture

```
Auth
└─ Sign in (role choice: User | Admin) ─ Forgot password ─ Reset password
User area ("Personal workspace")                      Admin area ("System management")
├─ Overview (default Dashboard)                       ├─ Admin overview
├─ My dashboards (list → Dashboard)                   ├─ Block management (→ Version history)
├─ Templates (gallery → Use template)                 ├─ Create block (5-step wizard)
├─ Profile & settings                                 ├─ Draft blocks
└─ Help & support                                     ├─ Published blocks
                                                      ├─ Block categories
                                                      ├─ Dashboard templates (→ Template editor)
                                                      ├─ Data sources (→ Data source form)  [PRD v3 addition]
                                                      ├─ User configuration
                                                      ├─ System settings
                                                      └─ Audit log               [PRD v3 addition]
Cross-cutting: Search ⌘K · Notifications (bell) · Settings gear · Workspace switcher · Sign out (Appearance hidden in the MVP)
Overlays on a Dashboard: Add-blocks Panel (drawer) · Edit Layout Mode · About this block · Expanded view · View as table · Dashboard switcher · Date Range picker
```

| Surface | Reached from | Purpose | Journey |
|---|---|---|---|
| Sign in | App URL, sign-out | Role choice and credentials ([imports/01-sign-in.png](imports/01-sign-in.png)) | Flows 1, 2 |
| Forgot / Reset password | "Forgot password?", emailed link | FR-2 | — |
| Overview | User landing, sidebar | Default Dashboard ([imports/02-user-overview-add-blocks.png](imports/02-user-overview-add-blocks.png)) | Flows 2, 4 |
| My dashboards | Sidebar, dashboard switcher | List, create (blank, from Template, duplicate), rename, delete (not Overview), reset to template | Flow 5 |
| Templates | Sidebar | Allowed Dashboard Templates with preview; "Use template" | Flow 5 |
| Profile & settings | Sidebar, user menu, gear (User area) | Per FR-4, plus **Keyboard shortcuts** (On / Off, default On) [DECISION R6]; theme setting hidden while only light exists [ASSUMPTION] | — (FR-4) |
| Help & support | Sidebar footer | Admin-configured links, "Contact your workspace administrator" | — (FR-4) |
| Admin overview | Admin landing | Metrics, recent block activity, platform health ([imports/03-admin-overview.png](imports/03-admin-overview.png)) | Flows 1, 4 |
| Block management | Sidebar | All Blocks: search, filter, edit, duplicate, unpublish, archive, versions | Flows 3, 4 |
| Version history | "Versions" in Block management or Dashboard templates | Immutable versions; Restore as draft (FR-41) | — |
| Create block wizard | Sidebar, "Create block", edit from a list | 5-step wizard ([imports/04-create-block-basic-data.png](imports/04-create-block-basic-data.png), [imports/05-create-block-display-behavior.png](imports/05-create-block-display-behavior.png), [mockups/mapdata-hybrid.html](mockups/mapdata-hybrid.html)) | Flows 1, 3, 4 |
| Draft / Published blocks | Sidebar | Lifecycle lists: status, version, owner, last updated | Flows 1, 3 |
| Block categories | Sidebar | Create, rename, order, archive; drive the drawer's chips | Flow 2 |
| Dashboard templates | Sidebar | List; Template editor (FR-45) | Flow 5 |
| Data sources | Sidebar | List with health; Data source form (FR-9 to FR-13) | Flows 0, 1, 4 |
| User configuration | Sidebar | Invite, deactivate; role, permissions, groups | — (FR-6) |
| System settings | Sidebar, gear (Admin area) | Allowlist, retention, refresh intervals, locale, Template for new users | — (FR-67) |
| Audit log | Sidebar | Search and export Audit Events | Flow 3 |
| Search ⌘K | Top bar, ⌘K / Ctrl+K | Dashboards, Blocks, Templates; Admins also Data Sources, users, settings | Flow 2 |
| Notifications | Top bar bell | Versions, new and unpublished Blocks; Admins also health and mapping failures | Flows 3, 4 |
| Workspace switcher | Sidebar top card | Change the active Workspace | — (FR-3) |

**Settings and admin-management screens (Profile & settings, Help & support, User configuration, System settings, Block categories, Audit log, Workspace switcher) get generic states only, with no journeys** [DECISION 2026-10-05, gaps]: State Patterns → *Generic states*; lists use `data-table`. Data sources (Flow 0), Version history and the Template editor have patterns because the brief and PRD name them as core Admin jobs.

## Voice and Tone

Microcopy rules (V6). The tone is **warm and professional everywhere**.
- **Errors** are calm, specific and actionable, never blame the user, and say what happened, what still works and what to do next.
- **Admins** get technical detail (HTTP status, field path, request ID) in an expandable **"Technical details"** section. **Users never see** stack traces, HTTP codes or JSON paths.
- Sentence case, plain verbs, numbers before nouns ("3 rows", "2 of 9 blocks"); no exclamation marks or emoji.
- Glossary terms in Admin copy (Slot, Record Path); "block" and "dashboard" in User copy, never "Slot Mapping".
- **No domain terms in product copy** (Foundation); examples naming finance data, approvals or tasks are *configured* names.

### Canonical messages

The **single source of product copy**. Example values ("Revenue overview", "10:42", "214") come from the real Block, time or count; screen-reader forms are given under the visible text.

| Key | Context | Do | Don't |
|---|---|---|---|
| `field-error` | Field error | "Enter an email address, like name@company.com." | "Invalid input." |
| `signin-role-denied` | Sign-in role denied | "You don't have admin access in this workspace. Sign in as User instead?" | "403 Forbidden" |
| `signin-failed` | Sign-in failed | "That email and password don't match. Try again, or reset your password." | "Login failed." |
| `throttled` | Throttled | "Too many attempts. Wait a minute, then try again." | "Account locked due to suspicious activity." |
| `fetch-failed` | API fetch failed (Admin) | "We couldn't reach Finance data warehouse. Check the endpoint or try again. ▸ Technical details" | "Error 502 Bad Gateway at /api/v2/…" |
| `json-invalid` | Invalid JSON pasted | "This isn't valid JSON. Line 4 has an extra comma. Go to line 4" | "Parse error." |
| `json-valid` | Valid JSON pasted | "✓ Valid JSON · 3 records found at data.monthly[] · Pasted 09:14 · 412 bytes · 4 scalars, 1 array" | "OK" |
| `test-ok` | Test connection succeeded | "✓ Connected · 200 · 184 ms" | "OK" |
| `notify-mapping-failed` | Admin notification, repeated mapping failures | "Revenue overview can't read total. Fix mapping →" | "Mapping error 422" |
| `notify-version` | User notification, new version | "Revenue overview was updated to v1.1" | "Block changed." |
| `fetch-ok` | Fetch succeeded | "✓ 200 · 312 ms · 3 records found at data.monthly[]" | "Success!" |
| `required-slot-missing` | Required Slot missing: the inline reason next to a disabled Continue | "Map Chart series to continue. Optional slots can stay empty." With several: "Map 2 required slots to continue: Chart series, Chart X. Optional slots can stay empty." | "Validation failed." |
| `post-readonly` | POST endpoint chosen | Checkbox "This POST is a read-only query", with "Dashflow never sends requests that change data. Mark this only if the endpoint just reads." | A silent POST |
| `url-invalid` | Footer URL template not http(s) | "Use a link that starts with https:// or http://." | "Invalid URL." |
| `group-required` | Selected groups, none chosen | "Choose at least one group, or make it available to all users." | "Required." |
| `access-impact` | Access change confirmation (B3) | "**38 users** will lose this block." with Confirm and Cancel | "Are you sure?" |
| `api-changed` | API changed banner (wizard) | "API response changed: field total no longer present → Headline: Unavailable" | "Schema error." |
| `api-match-found` | Matching field suggestion | "Dashflow found a new number field total_revenue that looks like the same data (matching value 284680). [Use total_revenue]" | Applying it silently |
| `live-shape-unreachable` | Live shape check can't reach the API | "We couldn't reach the API to check this block. Save a draft and try again." | "Validation error." |
| `drawer-empty-search` | Empty search (drawer) | "No blocks match 'payroll'. Ask your admin to publish one." | "No results." |
| `drawer-none` | No Blocks published (drawer) | "No blocks are available yet. Your admin hasn't published any." | "Empty." |
| `drawer-load-failed` | Add-blocks list fails to load | "We couldn't load the blocks. Try again." with Retry | A blank panel |
| `dashboard-empty` | Empty dashboard | "This dashboard is empty. Add blocks to start." with "+ Add block" | "Nothing here!" |
| `dashboard-load-failed` | Dashboard layout fails to load | "We couldn't load this dashboard. Your layout is safe. Try again." with Retry | A blank page |
| `block-empty` | Empty Block | "No data for this period." | "0" or a blank card |
| `block-error` | Block failed to load | "We couldn't load this block. Try again" with a Retry link | A stack trace |
| `sample-wizard` | Sample-data label (wizard) | "Sample data · used for mapping and preview only" | "Test", "Demo", "Fake data" |
| `sample-drawer` | Sample-data label (drawer preview) | "Sample data. Your dashboard will show your organisation's figures." | "Test", "Demo", "Fake data" |
| `sample-note` | Under the Map Data preview | "Sample data only. Published blocks call GET /api/v2/finance/revenue live. Before you publish, Dashflow checks that the live response matches this sample's shape." | None |
| `toast-add` | Toast (add) | Visible: "Revenue vs Expense added · Show me · Undo". Announced: "Revenue vs Expense added next to Recent activity. Undo with Ctrl+Z." (⌘Z on macOS) | "Block successfully added to your dashboard!" |
| `toast-add-below` | Toast (add, off-screen) | Visible: "Revenue vs Expense added below · Show me ↓ · Undo". Announced as `toast-add`, plus "It's below the visible area." | "Added." |
| `toast-remove` | Toast (remove) | Visible: "Recent activity removed · Undo". Announced: "Recent activity removed. Undo with Ctrl+Z." | "Deleted." |
| `toast-rollback` | Optimistic action rejected | "We couldn't add Revenue vs Expense. Your dashboard is unchanged. Try again." (error toast) | "Error." |
| `edge-pill` | New Block out of view | "↓ 1 new block below" | None |
| `free-space-hint` | Free-space hint | "The next block you add goes here." | None |
| `drawer-footer` | Drawer footer | "New blocks go to the first free space." | None |
| `unavailable-user` | Unavailable, on the Block face (Users) | "Unavailable — this value can't be shown right now." [UX DECISION — PRD alignment needed: PRD UJ-1's edge case shows "Unavailable: total missing" on the headline. The spine keeps field paths away from Users (Voice rule) and shows the field only to Admins and in About this block] | "$0", "NaN", "—" for a headline |
| `unavailable-admin` | Unavailable (Admins, About this block) | "Unavailable: total missing" | "Error" |
| `stale` | Stale | "Stale: last data 10:42". When the time is not today: "Stale: last data yesterday 22:10" or "Stale: last data 3 Oct 22:10". [ASSUMPTION — accepted default: the date rule] | "Data may be inaccurate." |
| `stale-aggregate` | Announcement when Blocks go Stale | "3 blocks are stale. Last data 10:42." On recovery: "Blocks are up to date." | One announcement per Block |
| `paused` | Live updates paused (R4) | "Paused · data as of 10:42" | "Stopped" |
| `reconnecting` | Reconnecting | "Reconnecting… Your dashboard will refresh when you're back online." | "Network error." |
| `back-online` | Reconnected | "Back online. Refreshing your dashboard." | None |
| `refresh-limited` | Manual refresh rate-limited | "Refreshed a moment ago" | A silently ignored click |
| `block-unpublished` | Unpublished Block | "This block is no longer available. You can remove it." | "Block not found." |
| `block-access-removed` | Access removed (B1) | "This block is no longer available to you." with Remove | "403" |
| `locked` | Locked Block | "Required by your admin" | "You can't do that." |
| `perm-publish` | No publish permission (A2) | "You don't have permission to publish this block." | A hidden button |
| `perm-denied` | Admin page without permission | "You don't have permission to view this. Ask a workspace admin." | "403 Forbidden" |
| `publish-impact` | Publish impact | "214 users have this block and 2 templates include it. Their layouts won't move." | "This will affect users. Continue?" |
| `publish-success` | Publish succeeded (Admin) | "Revenue overview v1.0 is live in the Block Library under Finance." | "Published!" |
| `version-safe` | Version-safe publishing note | "Publishing creates version 1.0. Future edits create a new version, so existing user layouts remain stable." | None |
| `host-not-allowlisted` | Host not allowlisted (Admin) | "This host isn't on your workspace allowlist. Add it in System settings, or ask a platform operator for a private-network address. ▸ Technical details" | "Request blocked (SSRF)." |
| `blocked-address` | Blocked address (Admin) | "Dashflow can't call loopback, link-local or cloud-metadata addresses. Use the API's public or allowlisted host." | "Forbidden host." |
| `response-too-large` | Response too large (Admin) | "Response too large: 14.2 MB is over this data source's 10 MB limit. Narrow the request with parameters, or raise the limit on the data source. Nothing was shown, so no totals are wrong." | "Truncated." |
| `not-json` | Not JSON (Admin) | "This endpoint returned HTML, not JSON. Dashflow supports REST APIs that return JSON." | "Unexpected token <." |
| `live-not-supported` | Live not supported (Admin) | "Live isn't available: Finance data warehouse isn't marked as supporting Live refresh." | A silently missing option |
| `datasource-none` | Step ② with no Data Sources | "No data sources are registered yet." With the manage-data-sources permission: "+ Register data source" (opens the Data source form in a new tab). Without it: "Ask an admin who manages data sources to register one." | An empty select |
| `reset-requested` | Password reset requested | "Check your email. If an account exists for that address, we've sent a link to reset your password." | "No account found for that email." |
| `reset-expired` | Reset link expired | "This reset link has expired or was already used. Request a new one." | "Invalid token." |
| `password-changed` | Password changed | "Your password was changed. Sign in with your new password." | "Done." |
| `restore-done` | Restore | "Draft v1.3 created from v1.1. Publish it to make it live." | "Rolled back." |
| `restore-replace` | Restore while a Draft exists (B2) | "Replace your current draft with v1.1?" with Replace draft and Cancel | Silently discarding the Draft |
| `unpublish-impact` | Unpublish impact | "214 users have this block. They'll see 'This block is no longer available.' It leaves the Add-blocks Panel and search." | "Are you sure?" |
| `reset-template` | Reset to template | "Reset Finance weekly – Jamie to the template's current layout? Your blocks, positions and sizes on this dashboard will be replaced. This can't be undone." | "Reset dashboard?" |
| `overview-delete` | Overview delete | "Overview is your default dashboard and can't be deleted." | A hidden or silently failing Delete |
| `mandatory-added` | New mandatory block (User) | "Your admin added Employee attendance to Finance weekly – Jamie. It's at the end of your dashboard." | "Layout changed." |
| `template-block-omitted` | Template Block the User can't access | "1 block in this template isn't available to you." [ASSUMPTION] | Silently dropping it |
| `templates-none` | No Templates available | "No templates are available to you yet." | "Empty." |
| `search-empty` | Search ⌘K, no matches | "No matches in Genie Inc. Try a block or dashboard name." | "No results." |
| `notifications-empty` | Notifications panel empty | "No notifications yet. Updates to your blocks and dashboards appear here." | "Nothing here!" |
| `expanded-empty` | Expanded view / View as table, no rows | "No rows for this period." | A blank table |
| `expanded-error` | Expanded view / View as table fails | "We couldn't load the rows. Try again." with Retry | A spinner forever |
| `workspace-role` | Workspace switch lands on Overview | "You're a User in Acme Ltd." | None |
| `layout-save-failed` | Edit Layout save failed | "We couldn't save your layout. Try again." | "Save failed." |
| `save-failed` | Save draft or form save failed | "We couldn't save your draft. Your changes are still here. Try again." (forms: "We couldn't save your changes. They're still here. Try again.") | "Save failed." |
| `offline-editing` | Offline in the wizard or a form | "You're offline. Your changes are still here. Save draft and Publish come back when you reconnect." | "Network error." |
| `session-warning` | Session about to expire (R5) | "You'll be signed out in 2:00 for security." with **Stay signed in** and Sign out | A silent sign-out |
| `session-expired` | Signed in again after expiry (R5) | "You were signed out to protect your workspace. Your draft was saved, and we've restored it." | "Session expired." |
| `draft-locked` | Another Admin is editing the same Draft | "Maya Patel is editing this draft (since 10:42). You can view it, or take over editing." Buttons: **Take over editing** / Close [DECISION: soft lock] | Silently overwriting either version |
| `draft-taken-over` | Shown to the Admin whose lock was taken | "Alex Morgan took over editing at 10:58. Your changes were saved." | "You were kicked out." |
| `map-small-screen` | Map Data below 640px (dismissible banner) | "Map Data works best on a larger screen. Everything still works here." with Dismiss | "Use a desktop." or a blocking screen |
| `list-empty` | Generic list, no items | "No {items} yet. {Primary action} to start." (for example "No draft blocks yet. Create a block to start.") | "No data." |
| `list-no-match` | Generic list search, no match | "No {items} match '{query}'." with Clear search | "No results." |
| `saved` | Generic form saved | "Changes saved." (polite announcement and a toast without actions) | "Success!" |
| `unsaved-changes` | Leaving with unsaved changes | "You have unsaved changes." with Save (or Save draft), Discard changes and Keep editing | Losing the changes |
| `wizard-subtitles` | Wizard section subtitles | "Give your block a clear identity." · "Connect the block to a trusted data source." · "Set the default dimensions and controls." | "Section 1" |
| `page-subtitles` | Page subtitles | "Here's what's happening across your workspace today." (Overview) · "Monitor dashboard adoption, publishing activity and platform health." (Admin overview) | None |
| `signin-subtitle` | Sign-in subtitle | "Choose your workspace role and enter your credentials." | "Log in." |

## Component Patterns

Behaviour only; visuals are in DESIGN.md → Components, and states in State Patterns.

### Create-block wizard: 5 steps

[UX DECISION — aligned in PRD v3.2: D1] Map Data is a first-class step (PRD v3.2 §4.4): **① Configure Block → ② Data Source → ③ Map Data → ④ Preview → ⑤ Save/Publish**.

**Shell.**
- Header: "Block management / Create block", title, "• Draft" badge, **Save draft** (every step; FR-19), **Preview**, **Publish block**.
- **Autosave** [DECISION R5]: continuous; restored at the same step after re-sign-in (`msg:session-expired`). Draft vs separate recovery copy is for architecture.
- **Publish block**: enabled only on step ⑤ after successful validation against the **real configured API** (A1). Without the publish permission: shown **disabled** with `msg:perm-publish`; drafts still allowed [DECISION A2].
- **Stepper**: a completed step collapses to a summary card with ✓ and **Edit**; jump back to any completed step, forward only to the first incomplete one. A `nav` "Create block progress" with an ordered list; done steps and the first incomplete step are buttons "{n}. {label}, completed" (or ", not completed"); current `aria-current="step"`; later steps plain text "(not started)".
- **Back (←)** returns to the opening page (Block management, a lifecycle list, Admin overview); with unsaved changes it first shows `msg:unsaved-changes` (**Save draft**, **Discard changes**, **Keep editing**). [ASSUMPTION — accepted default: the three buttons]
- **Form section cards** have a title and subtitle (`msg:wizard-subtitles`): ① "Basic information", "Display & behavior"; ② "Data configuration".
- **Live preview pane** (steps ① to ③; PRD §4.4): "Live preview — Changes appear here automatically.", sticky right. `msg:version-safe` under it, **repeated in step ⑤**, naming the version this publish would create (1.1 when editing v1.0).
- Below 1440px the sidebar collapses to the icon rail and slot rows wrap [DECISION R7].

**① Configure Block.** Fields per FR-14; **Display & behavior** on the same step, per FR-17. **Show footer action** enables the Footer action Slot, configured in Map Data (a URL template can use field tokens).
- **Icon picker** (`icon-picker`): current icon in a `{spacing.target-chrome}` tile with "Change"; **defaults from the Category** until the Admin picks one. "Change" opens a searchable popover grid of the generic platform set (charts, people, calendar, documents, status…; never domain-specific); "Use category icon" reverts. [ASSUMPTION — accepted default: the revert action and the set's content] One tab stop; arrows move, Enter picks, Esc closes to "Change"; tiles named "Bar chart icon".
- The preview updates on every change, with Desktop/Tablet/Mobile toggles and a size label ("8 columns × Medium (360px)"); a Block Type skeleton before data exists.

**② Data Source.** Fields per FR-15; the select shows health dot and word; Endpoint = method prefix + path.
- **POST** (GET is default) reveals the required `msg:post-readonly` checkbox; Fetch and Continue stay disabled until it is ticked (FR-11).
- **Parameters table** (`parameters-table`; FR-11, FR-8): a row per query/path parameter or header, with Name, **Binding**, Value. Bindings: **Fixed value** (text input); **Date Range · from / to** (Follow-dashboard Blocks); **Block Period Selector · start / end** (only for Own selector; FR-17, FR-35); **User context** (user ID, email, group, or a System settings attribute, FR-67; "user context" chip; never user-changeable, FR-8). Bound rows show the resolved value in mono text-muted ("from = 2026-01-01"). Empty path parameters block Fetch, naming the parameter.
- **Refresh Interval** lists the System settings intervals (FR-67). **Live is disabled** with `msg:live-not-supported` when the Data Source's Live flag is off (FR-60), the reason being the option's description. Data Source managers also get "Edit data source".
- **Sample Response**, a segmented control:
  - **Fetch from API** (default): server-side with current parameters; "Fetch as user" when user-context bindings exist (FR-21); result `msg:fetch-ok`.
  - **Paste sample JSON** [UX DECISION — aligned in PRD v3.2: D2, FR-21]: `json-textarea`; validates on paste and blur, announced politely: `msg:json-valid`, or `msg:json-invalid` with **Go to line 4** (caret to line and column; Continue disabled). Non-JSON or oversized input is rejected with the limit stated.
- Pasted data is sample data only (FR-21); the Sample badge rule (T5) applies from here. Secrets are never shown; no user-context binding → "shared data" badge (FR-8).
- **Continue** needs an Endpoint **and** a Sample Response.
- **Fetch errors** (FR-10, FR-13): the inline `fetch-error-card` (Retry, "Paste sample JSON instead", Technical details) with `msg:host-not-allowlisted`, `msg:blocked-address`, `msg:response-too-large`, `msg:not-json` or `msg:fetch-failed`; focus to its title.

**④ Preview**, per FR-18, plus a **state switcher** (Normal, Loading, Empty, Stale, Unavailable, Minimized), an "Acknowledge" checkbox per unresolved warning before Continue, and the "Sample data" label whenever data is a sample.

**⑤ Save/Publish** (FR-20, FR-39).
- Summary: version to create ("1.0"), validation result, repeated `msg:version-safe`.
- **Access** (FR-7): `radio` **All users** / **Selected groups** (searchable multi-select of User configuration groups); none chosen blocks Publish with `msg:group-required`. Default All users. [ASSUMPTION — accepted default: the default value] Audited (FR-68). **Access changes create no new version** [DECISION B3]: they apply immediately after the `msg:access-impact` dialog (Confirm / Cancel; focus on Cancel); versions are for content changes.
- **Live shape check** (from [mockups/mapdata-hybrid.html](mockups/mapdata-hybrid.html)): the live Endpoint's shape must match the sample's mapped paths; a mismatch lists the paths and blocks Publish (Save draft remains). [ASSUMPTION] Unreachable API: blocked with `msg:live-shape-unreachable`.
- First publish: validation summary, then **Publish block**; a new version: *Publish impact confirmation*.
- Success: `msg:publish-success` toast; Published blocks opens with the new row highlighted and focused. ("Block Library" is the Glossary's Admin term; Users see the Add-blocks Panel.)

### Map Data (step ③)

The product's most important pattern (FR-16, FR-21 to FR-32): sketch **C** (semantic roles) → **A** (slot checklist) → **B** (preview hotspots). Reference: [mockups/mapdata-hybrid.html](mockups/mapdata-hybrid.html); rejected alternatives (.working/): [.working/mapdata-A-three-pane.html](.working/mapdata-A-three-pane.html), [.working/mapdata-B-preview-first.html](.working/mapdata-B-preview-first.html), [.working/mapdata-C-semantic-table.html](.working/mapdata-C-semantic-table.html). Visuals: DESIGN.md → *Map Data parts*.

#### Data Mapping Model

Each Field gets one **role**; auto-map fills the Block Type's Slots (FR-32) from roles by type and cardinality. The six roles refine PRD §3's Dimension and Measure as in the last column. [UX DECISION — aligned in PRD v3.2]

| Role | Meaning | Typical Slots | Cardinality | PRD role |
|---|---|---|---|---|
| Label | Names or describes | KPI label, Title/Subtitle tokens, unit/currency source | single | Dimension |
| Value | One headline number | Headline value; Comparison baseline (`previous_*`); Pie centre value | single | Measure |
| Dimension | Groups or splits rows | Category axis, series split, list item title, table text columns | per row | Dimension |
| Measure | Number to plot or sum | Chart series, bar values, pie value, table numeric columns, progress | per row | Measure |
| Time | When it happened | Chart X; Data-as-of (ISO timestamp scalar); Calendar date | per row / single | Dimension |
| Filter/Category | Lets users narrow | Not plotted; filter Transforms and group-by. [ASSUMPTION: no user-facing filter control in the MVP; sketch C's "becomes a user filter" is not in the PRD] | per row | Dimension |

- **One source of truth**: overriding a Slot updates the Field's role (T1); changing a role re-fills unconfirmed Slots; changing the Block Type re-fills Slots from the unchanged roles without asking, keeping same-name-and-type Slots and flagging the rest unmapped (FR-23).
- **Confidence**: **High** = unique match by role and type; **Medium** = heuristic (name patterns, generated label); **Low** = ambiguous (several candidates, deep nesting, repetition across a flattened array).

#### Layout and loop

- **Left column**: step ① and ② summaries; sticky status bar; **1 · Roles** ("What does each field mean?"); **2 · Review block slots**; collapsible **3 · Transforms** [ASSUMPTION — accepted default: placement]. **Right**: sticky live preview (`{spacing.preview-pane-width}`), visible while the left scrolls (T4).
- **Below `{spacing.reflow-stack-below}`** (including 400% zoom): one column; the preview becomes a collapsible "Preview" bar; slot rows, expanded editor, scalar cards, transform and threshold rows stack; only the sample-rows table and JSON view scroll sideways, in labelled focusable regions. `msg:map-small-screen` shows once, never after Dismiss, never blocking.
- **Loop: Auto-map → Review → Override → Preview.** Auto-map infers types, proposes roles and fills every Slot with a confidence; with two Values, the one not named `previous_*` is the headline, the other the comparison baseline (shown to the Admin). Review: the checklist groups Slots (HEADER, KPI, CHART, META) with status and confidence. Override: "Change field ⌄" or a preview click opens a field picker. Every change re-renders the preview at once.
- **Sticky status bar** (`mapdata-status-bar`; not sticky under 480px viewport height): `status-meter` (one segment per Slot, `aria-hidden`); text such as "Auto-mapped 6 of 9 · 2 confirmed · 1 required missing", announced politely **only when the required-missing or needs-review count changes**; subtitle "Fields → slots of your KPI & Chart block"; the Sample data badge (T5); "↻ Re-run auto-map"; **Continue to Preview →**.

#### 1 · Roles

- **Single values** (scalars outside the Record Path): `scalar-card` with key (mono), type chip, example, and a role chip with qualifier (Value · main, Value · baseline, Label · unit, Time · as-of).
- **Rows**: `roles-table` of up to 5 sample rows, a role chip above each column; a real `table` with `th` scope, in a focusable region "Sample rows, scrolls sideways" when it overflows.
- **Role chip**: menu button "Role for {field}: {role}" (`aria-haspopup="menu"`); flagged adds ", possible wrong role" (its "!" `aria-hidden`); items are `menuitemradio` with `aria-checked` and a one-line description on focus. After a change the T1 note is announced politely and focus returns to the chip. **A role change re-fills unconfirmed slots; auto-map never changes Confirmed or Overridden slots.** [ASSUMPTION]
- **Suggestions** (`suggestion-row`): "✦ revenue looks like a Measure — use as series? [Set as Measure] [Keep as Filter]"; focus then moves to the affected role chip.
- **Collapse (T4)**: once accepted (no Low-confidence slots and no open suggestions, or "Looks right"), the table collapses to `roles-summary-line` ("Roles: total Value · previous_total Value · month Time · revenue Measure · region Dimension · status Filter/Category — Edit roles"); it re-expands on Edit or when a role is flagged, without moving focus. Timing follows Focus stability; if the collapse removes the focused control, "Roles accepted. Roles table collapsed." is announced.
- **"View JSON"** opens the raw JSON tree (FR-21); "Replace sample / Fetch from API" reopens the step ② controls inline.

#### 2 · Slot checklist

- **Filters** (`radiogroup`): All (9) · Needs review (n) · Missing (n). Needs review = Low confidence, Medium-confidence required, Type-mismatch, Required-missing. A row confirmed under a filter stays while focus is in the list, announced ("Headline value confirmed. 1 slot still needs review.").
- **Low-confidence slots pinned to the top**; pinning and sorting computed **at step load and on "Re-run auto-map" only**.
- **Statuses** (visuals: DESIGN.md → slot row): **Auto-mapped · High** (auto-accepted, counts as Confirmed; T2); **· Medium** (with reason: "2 Values; picked the one not named previous_*"); **· Low** ("Low confidence — please review", with reason: "3 numbers named 'amount' at different depths"); **Confirmed** (the row's **Confirm** ✓ button; caption "Confirmed by you"); **Overridden** (another field, static text, Text Template or Calculated Field); **Optional-empty** (does not block); **Required-missing** (red-tinted, with a suggestion when one exists: "Use revenue as series"); **Type-mismatch** (below).
- **Confirmed requirement (T2)**: before Continue, every **required** and every **Low-confidence** Slot is Confirmed, Overridden or (Low optional only) cleared. Medium-confidence *optional* Slots don't block but stay in Needs review. [ASSUMPTION: the Medium optional rule]
- **Row semantics**: rows form a list; each is a `group` labelled by name and status ("Headline value, required, auto-mapped, high confidence"); Confirm, Change field and Expand presentation (`aria-expanded`) are in Tab order; Confirm is never bound to Enter on the row; status dot and meter `aria-hidden`; the badge reads "Confidence: Medium".
- **Override updates the role (T1)**: the row notes "revenue is now a Measure (was Filter/Category). Undo"; Undo restores slot and role; other unconfirmed Slots fed by the old role re-fill.
- **Field picker**: search combobox (`listbox-option-active`), compatible fields first, "n fields match" announced; incompatible fields `aria-disabled` with a reason in `aria-describedby` ("Text field can't be a numeric headline. Use it as the currency format below."); also **Static text**, **Text Template** (FR-26, token helper), **Calculated Field** (FR-25, e.g. `PCT_CHANGE(total, previous_total)`). Esc returns focus to the opener.
- **Expand a row** (▴; one at a time) for Presentation Rules per FR-29, plus:
  - **Format**: live example ("284680 → $284,680"). **Emphasis**: "one headline per block".
  - **Direction**: ↑ Higher / ↓ Lower is better, note "Colors the comparison: up = green"; Lower is better adds visible "· better" / "· worse" after the % (DESIGN.md → KPI card); every delta has hidden judgement text ("Up 14.2% from last year, favourable").
  - **Thresholds** (`threshold-band-row`): "+ Add band" adds an ordered condition (≥, ≤, between) with **colour**, **icon** and **label**; colours only from the fixed status set (success, warning, error, info, neutral), never the accent; icon or label required alongside colour; first match top to bottom wins. [ASSUMPTION — accepted default: first match wins]
- **Comparison editor** (`comparison-editor`; KPI card, KPI & Chart): options per FR-27. **Source** is a segmented control, **Mapped previous value** (field picker, e.g. `previous_total`) or **Prior-period request**; a custom offset is a number and a unit. Hidden text joins direction, value, label and judgement ("No change from last year" when flat). A pasted sample can't preview a prior-period request: the preview says "Checked against the live API before publish" and the step ⑤ check runs both requests. [ASSUMPTION — accepted default]
- **Footer action target** (FR-34; META Slot, present when Show footer action is on): **Label** is a Text Template ("View all approvals"); **Target** is **Expanded view** or **External link** with a **URL template** using FR-26 tokens (URL-template syntax, not design tokens), e.g. `https://erp.example.com/approvals?user={user.email}`; non-http(s) shows `msg:url-invalid` (FR-37); the preview shows ↗.
- **Block-Type settings** (FR-32), in the Slot's expanded row or a final "Block settings" row:

| Block Type | UX settings |
|---|---|
| Table | Per column: header, format, alignment, width, **sortable**; per Block: **pagination size**, **row highlight rule** (a threshold condition that tints the row and adds its icon or label) |
| List | Avatar from a mapped field's initials, or an icon; right value; right meta |
| Progress list | Value ÷ total or %; **colour rule** = threshold bands on the % |
| Calendar / Agenda | Week strip on/off with today highlighted; event colour from a mapped category |
| Line / Area | Per series: label (Text Template) and direct label on/off; optional target line value and label |
| Bar | **Layout** (Grouped / Stacked), **orientation** (vertical default), **stack/series key** (a Dimension that splits series, or one series per mapped value field), value labels on/off, sort (data order or by value). [ASSUMPTION — accepted default: orientation and sort options] |
| Pie / Donut | **Shape**; **centre value** (Donut only: a mapped Value, the slice total, or none) with a Text Template label ("Total"); beyond the top 5, slices group as "Other" (max 6). [ASSUMPTION — accepted default: top 5 + Other] |
| Text / Status | **Mode** Text (Text Template) or Status (mapped field + **colour rule**: threshold bands or value matches, each with colour, icon, label); no match → neutral text. [ASSUMPTION — accepted default: value matches for text statuses] |

#### 3 · Transforms

Per FR-24, FR-25, FR-28: **declarative rows**, never scripts, always displayed and run in the FR-24 order. "+ Add step" inserts a stage at its place (no drag reordering); stage labels sit left of their rows. Rows read as sentences (`transform-row`; each control named, e.g. "Filter field", "Filter operator", "Filter value"):
- Filter "Keep rows where [status] [is] [Approved]"; Calculated field "[margin] = [expression]" (FR-25 functions, inline validation, "Ratio" option asking numerator and denominator, FR-28); Group "Group by [region]"; Aggregate "[SUM] of [revenue]" (SUM, COUNT, AVG, MIN, MAX); Sort "Sort by [revenue] [descending]"; Top-N "Keep the top [5] rows".
- Functions and stages have one-line descriptions on focus and hover (`aria-describedby`).
- Each row shows rows in and out on the Sample Response ("36 → 12 rows"); the preview updates.
- Row errors (missing field "status isn't in the response", "Can't SUM a text field", invalid expression with position) block Continue. × is named "Remove step: Keep rows where status is Approved".
- "Aggregate rows by…" (T3) writes its group and aggregate rows here; edit in either place.

#### Preview, hotspots and nested data

- **Sticky preview**: Desktop/Tablet/Mobile toggle, size label, "Sample data" label; a region "Live preview, sample data" preceded by the skip link "Skip to slot checklist". Below the Block: `source-legend` "Where each part comes from" (a list: role chip, field, result, e.g. "Value total → $284,680"), then `msg:sample-note`.
- **Hotspots** (B): mapped regions have a faint dashed outline (`preview-hotspot`). Hover outlines in accent with the tooltip "Click to change field · Headline value ← total"; keyboard focus shows the standard focus ring (never the 1.48:1 accent outline) and the same tooltip. Each is a `button` "{Slot}: {field}, {rendered value}. Change field" ("Headline value: total, $284,680. Change field"). Click, Enter or Space scrolls to the Slot, expands it and opens its picker with focus in search; Esc returns to the hotspot. A missing required Slot is the hatched `missing-slot-placeholder`, a button "Chart series, required, not mapped. Choose field" showing "Chart series required · No Measure mapped · click to choose". The preview Block's chrome (↻, –, ⋯) is inert. Slots with no region (Data-as-of, series 2 to n) are reached from the checklist.
- **Record path** (FR-22): with more than one array or nesting deeper than 2 levels, Roles opens with **"Rows from (record path)"** (`record-path-list`), a `radiogroup` of arrays with row count, columns and hint; choosing one re-runs auto-map. Most rows = "Suggested"; `meta.links[]`-like arrays = "looks like pagination, not data". The status bar reads "◐ Auto-mapped 6 of 9 · 3 low confidence — please review" with "This response has 3 candidate arrays and numbers nested 3–4 levels deep. Dashflow filled what it could. Confirm the record path, then check the flagged slots."
- **Field tree** (replaces the flat picker for deep responses): searchable, collapsible, with type and example; breadcrumb `nav` "Field path" (`data › summary › kpis › total`); "Use" per leaf; footer "Selected: data.summary.kpis.total.amount · 284680 → $284,680". **APG tree**: `role=tree`, `treeitem` with `aria-level`, `aria-expanded`, `aria-setsize`, `aria-posinset`; → ← expand and collapse, ↑ ↓ move, Home/End, type-ahead; **Enter on a leaf = Use** (the "Use" pill is `aria-hidden`); names include type and example ("amount, number, 284680"); incompatible leaves `aria-disabled` with reason; active `listbox-option-active`, picked `listbox-option-selected`.
- **"Aggregate rows by…" (T3)**: flattening a nested array (`data.regions[].stores[]`) makes parent fields columns (`region.name`) and shows `aggregate-control` above the sample table. **Default: sum the Measures, grouped by the Time field**, with **row counts before and after** ("36 rows → 12 rows (sum of sales.amount by sales.month)"). Group-by field(s) and aggregation (SUM, COUNT, AVG, MIN, MAX) are editable and write the equivalent Transform. Ratio fields aggregate ratio-safely (FR-28), labelled "ratio: sums numerator and denominator".

#### Mismatches, API changes and Continue

- **Type-mismatch**: the picker prevents it by choice; a role change, Block Type change or re-fetch can still cause it. The Slot shows the reason ("data.currency is text ("USD"). Headline value needs a number.") and compatible fields, plus **"Treat as …"** when parsing fixes it (e.g. "2026-01" as a month; FR-23). It blocks Continue.
- **API changed**: on "Re-fetch sample", or when a published Block's scheduled check or run-time mapping fails (FR-30) [ASSUMPTION: schedule set in architecture]. `msg:api-changed` banner; affected Slots become Required-missing (Unavailable for a published version) and dependants pause ("Comparison (% change) is paused too"); `msg:api-match-found` is never applied silently. Fixing a published Block creates a new Draft version on the normal publish path.
- **Sample badge rule (T5)**: the badge appears **once, in the status bar**, and the **preview carries "Sample data"**, whenever on-screen data is a Sample Response, pasted or fetched; pasted samples add "Pasted 09:14" to the step ② summary.
- **Continue to Preview is enabled** only when: (1) every required Slot is mapped; (2) every required and Low-confidence Slot is Confirmed or Overridden; (3) no Type-mismatch; (4) Calculated Field, Text Template and Transform rows validate and any footer URL template is http(s); (5) a flattened array used by a per-row chart Slot has its aggregation set (the default counts). Until then it follows *Disabled controls*, with the reason inline (`msg:required-slot-missing` or the first other blocker), not only in a tooltip; activating it focuses the first blocking row. Several blockers add an **error summary** of links at the top, focused on activation.

### Add-blocks Panel (drawer)

[UX DECISION: D3, the hybrid list with thumbnails; rendered reference [mockups/addblocks-hybrid.html](mockups/addblocks-hybrid.html). The rejected gallery and its variants are rejected alternatives (.working/): [.working/addblocks-variants.html](.working/addblocks-variants.html).] Contents per FR-52; placement per FR-53.

- **Opening**: "+ Add block" (top bar or a search result) opens "Add blocks — Build a dashboard that works for you" on the right; **the dashboard stays visible and usable** and is never navigated away from; focus goes to search. Landmarks per Interaction Primitives; the header's "Back to dashboard" skip link goes to the first Block (or "+ Add block" when empty).
- **Push vs overlay (T6)**: from about 1024px the drawer (`{spacing.drawer-width}`) **pushes** the dashboard narrower and the sidebar collapses to the icon rail (`{spacing.icon-rail-width}`); from 1024 to about 1231px the remaining width is under 720px, so the dashboard is **single-column** in the user's order (still pushed, nothing covered, the just-added highlight in that column). Below about 1024px it is an `overlay-sheet`: modal, focus trapped, background inert, a `{spacing.drawer-dim-strip-mobile}` dimmed strip that closes it on tap, a `{spacing.target-touch}` **Close** in the header. Closing restores the sidebar and full width; positions are unchanged.
- **Content**: sticky search (placeholder "Search blocks…", name "Search blocks"), category chips (FR-43), Filter (type, Data Source), count ("8 available blocks"; "2 of 8 blocks" when filtered); the list, then **"On your dashboard"** (T10); footer `msg:drawer-footer` and **Done**. Search covers names, descriptions and tags with highlights; chips filter results. [ASSUMPTION: the sketch's "Request a block" button is out of MVP; there is no request workflow in the PRD]
- **Row** (`drawer-row`): `drawer-thumbnail` (`aria-hidden`), name, description, category tag, optional "New". **+ Add** ("Add {Block} to dashboard") adds at once, no confirmation. **✓ Added** is **persistent status text**, not a control, with a separate **× Remove** ("Remove {Block} from dashboard") shown on hover, focus-within and touch and always in the accessibility tree. **Locked** for Mandatory Blocks. No Block twice (FR-53). ARIA: `list` of `listitem`s, each with a **disclosure button** (the name; `aria-expanded`, `aria-controls` to the preview; description as `aria-describedby`) plus action buttons. Row-body click or Enter/Space on the disclosure opens the inline preview.
- **Inline preview** (accordion, one open, scroll kept): full description, the real Block at about 340px with sample data, `msg:sample-drawer`, meta "Data source: HR analytics API · Refreshes hourly · v1.0 · Default size 4 columns × Medium". A region "Preview of {Block} with sample data"; the Block is inert. **+ Add to dashboard** (row collapses to ✓ Added) and **Close preview**. Esc collapses it; a second Esc closes the drawer.
- **"On your dashboard" (T10)**: Mandatory Blocks with lock and `msg:locked`, announced "required by your admin and cannot be removed"; **excluded from the count, still searchable**. Added non-mandatory Blocks stay in the main list as ✓ Added, so it never jumps. [ASSUMPTION] (PRD FR-52 and T10 also say "system Blocks", undefined in the Glossary; this spec treats the group as Mandatory Blocks only, and system Blocks would need a Glossary entry.)
- **Placement (D4, T7)**: **first free grid position at default size**, computed on the **saved full-width layout**, not the narrow view; existing Blocks never move or resize; no auto-scroll. `just-added-outline` and "Just added" for about 3 seconds, **persisting briefly after the drawer closes** and the layout reflows. [ASSUMPTION: about 2 seconds after close] **Focus stays on the row**. Single-column layouts: the end of the Dashboard. Out of view: no scroll; `msg:toast-add-below` and `msg:edge-pill` until scrolled to or Show me. **Free-space hint (T8)**: `msg:free-space-hint` **only on hover or focus of "+ Add"** / "+ Add to dashboard".
- **Toast (T9; timing per R3)**: `msg:toast-add`, **top right of the dashboard area**, never over the drawer; timing and Ctrl+Z per *Toasts with Undo* [DECISION R3, replacing T9's ~6 s]. **Show me** scrolls, pulses the outline and focuses the Block title; **Undo** removes. On mobile it sits inside the sheet, never over the focused element, and Show me closes the sheet first.
- **Remove**: × Remove or the ⋯ "Remove from dashboard" removes the Block Instance at once with `msg:toast-remove`, same timing and undo, **no confirmation** [DECISION A6]. Focus: from the drawer, stays on the row (now **+ Add**); from ⋯, per Focus stability; Undo returns it to the Block title. Mandatory Blocks show the reason, no Remove.
- **Out of MVP**: **multi-add** [DECISION A5; PRD v3.2 FR-52] (fast sequential adds instead: ↓ then A, or Tab to the next + Add when shortcuts are off); **dragging from the drawer onto the grid**, with the mockup footer "Turn on Edit layout to drag blocks" [DECISION A4; UX DECISION — aligned in PRD v3.2: FR-53]. Users reposition in Edit Layout Mode.

### Edit Layout Mode

Per FR-54, FR-58, FR-59.
- "✎ Edit layout" toggles the mode; it becomes **Done** beside Undo and Redo (`edit-layout-toolbar`). Leaving returns focus to "✎ Edit layout".
- Movable Blocks get a move handle (the whole header), a `resize-handle` if Allow resize is set, and the hint "Drag to move · corner to resize · M to move with the keyboard".
- **Keyboard move (APG grab)**: a focusable **Move {Block}** button, described "Press M or Space to pick up"; keys in the Keyboard model. [ASSUMPTION — accepted default: Esc cancels rather than drops] Moves are announced against neighbours ("Revenue vs Expense, row 3, column 7, right of Revenue overview; Project status moved down").
- Resize snaps to column and height presets within min and max (FR-17). Dragging pushes overlapped Blocks down; nothing compacts upward. [ASSUMPTION] Mandatory Blocks move and resize but can't be removed. [ASSUMPTION] ⋯ adds Move up, Move down, Make wider, Make narrower.
- **Done**, or **a second Esc** with nothing picked up and no menu open, saves to the server (FR-56); a failure keeps the mode open with `msg:layout-save-failed`.
- Mobile: reorder only (drag or Move up/down), no free resize (FR-58). The Add-blocks Panel can stay open, still placing in the first free slot. [ASSUMPTION]

### Block Chrome controls

Per FR-34, FR-55.
- Each Block is a `section` labelled by its heading title (h2 under the page h1); controls include the name ("Refresh Revenue overview", "Minimize Revenue overview", "More actions for Revenue overview").
- **Refresh ↻** (if allowed): this Block only; spinner in the icon; values update in place; focus stays. Rate-limited: `msg:refresh-limited` and `aria-disabled` with that reason for the rest of the window. Works while paused.
- **Minimize –** (if allowed): header only, persisted per Block Instance; becomes **Expand**.
- **⋯**: Refresh; **About this block** (description, Data Source, Refresh Interval, Data-as-of Time with full date and zone, e.g. "Data as of 5 Oct 2026 10:40 BST", version, period behaviour); Expanded view (if configured); **View as table** (every chart Block); Remove from dashboard (disabled with `msg:locked` when Mandatory).
- **Footer action**: in-app expanded view (full-size Block, paginated table) or an external http(s) link in a new tab with ↗ (FR-34).

### Live updates and pause (FR-60 to FR-62; R4)

- **Pause live updates** [DECISION R4]: whenever any Block refreshes automatically (Live or a timed interval), the header shows `live-updates-toggle` (`aria-pressed`, "Pause live updates").
- **Paused**: no automatic refresh; `msg:paused` with the oldest Data-as-of Time; the button reads "Resume live updates". ↻, Date Range and Block Period Selector changes still fetch. [ASSUMPTION — accepted default] **Resume** refreshes paused Blocks at once.
- Pause lasts until resumed or the dashboard is left; reopening starts live, so nobody returns to quietly old data. [ASSUMPTION — accepted default: not persisted across visits]
- No animated value changes (no tickers, no chart transitions); routine refreshes are never announced.

### Chart Block (Line / Area, Bar, Pie / Donut, KPI & Chart)

Visuals: DESIGN.md → Chart marks, Block Type faces.
- **Summary**: the plot is `role="img"` with a generated `aria-label` (type, series, X range, lowest and highest, trend, highlighted point, target line): "Line chart, Revenue, Jan to Dec 2026. Lowest $10.2k in Jan, highest $40.1k in Dec, trend up." ("Target $35k"). Bars name categories and the largest value; Pies the largest slices with %. Regenerated on change, never announced.
- **View as table**: always in the ⋯ menu, whatever the Expanded view setting; opens `expanded-view` with the Block's data as an accessible table (`th` scope, units in headers, chart formats). Focus to the panel title; Esc or Close returns to ⋯.
- **Keyboard**: one tab stop; ← → step points (Home/End to ends), ↑ ↓ switch series; the point shows `chart-point-focus` and the hover tooltip and is announced politely ("Revenue, March, $21.4k"). Bars and slices step the same way. Esc leaves point mode, keeping focus on the plot. Tooltips never hide the focused point.
- **Legend**: a list naming marker and dash ("Revenue, solid line, circle markers"); not toggles in the MVP. [ASSUMPTION — accepted default]
- **Two or more series**: direct labels or dash and marker variants [DECISION R2]. Series-1 lines use `{colors.chart-1-stroke}` [DECISION R1].

### Table Block (Table / Data grid)

Visuals: DESIGN.md → Block Type faces; settings: Map Data → Block-Type settings.
- A real `table`: visually hidden caption = Block title; `th scope="col"`; units in headers.
- **Sorting**: only **sortable** columns; a `button` in the `th`, which carries `aria-sort`; ascending → descending → data order; announced ("Sorted by Amount, descending"); focus stays. Session-only, not saved to the layout. [ASSUMPTION — accepted default: session-only]
- **Pagination**: the Block's **pagination size** (FR-32); "Previous" / "Next" around "1–10 of 48", `aria-disabled` at the ends; focus stays and the new range is announced; resets to page 1 on sort, Date Range or Block period change.
- **Row highlight**: the band icon and label in the first cell and in the row's accessible text ("At risk"); tint is never the only cue.
- Tab moves through headers and pagination; an overflowing table's scroll container is a focusable region labelled with the Block title.
- Refresh replaces values in place, keeping sort and page (or showing and announcing the last page if the page is gone).

### Period selector precedence

Per FR-35; Not-date-filtered Blocks ignore periods. Only Own-selector Blocks show a period dropdown. A Block whose period differs from the dashboard Date Range states it in the subtitle ("Financial performance · This year"). [ASSUMPTION] Changing the Date Range never resets a Block Period Selector choice. About this block states the behaviour in words.

### Publish impact confirmation

Per FR-39, for a **new version** of a published Block or Dashboard Template (a first publish shows only the validation summary).
- **Title** "Publish Revenue overview v1.1?"; **What changed**: diff by Mapping, Presentation, Chrome and Endpoint, before → after.
- **Impact**: "**214 users** have this block on a dashboard · **2 templates** include it." (templates linked). For a Template: "**38 dashboards** were created from this template. Existing dashboards don't change, except that newly mandatory blocks are added at the end of their layouts." (FR-47, FR-48)
- **Promise**: "Their layouts won't move. Blocks keep their position and size; sizes outside the new limits are adjusted to the nearest allowed size."
- **Publish v1.1** (primary) and Cancel; focus opens on the title, Cancel first in Tab order; Esc cancels to Publish block. Audited. Without the permission the dialog never opens (`msg:perm-publish`) [DECISION A2].

### Data source form (FR-9 to FR-13)

- **List** (`data-table`): name, host, auth type, **health** (dot + Healthy / Degraded / Unreachable), last successful call, Blocks using it; primary "+ Register data source".
- **Form** (section cards; also edits). Fields per FR-9, plus:
  - **Connection**: Name\* ("Finance data warehouse"), Base URL\*; allowlist check on blur (FR-10); blocked host → `msg:host-not-allowlisted` inline, Save disabled.
  - **Authentication**: API key → header name and key; Bearer → token; OAuth2 → token URL, client ID, client secret, scope; Basic → username and password. **Secrets write-only** (`•••••••• · set 2 Oct 2026 · Replace`; no reveal).
  - **Default headers**: key/value rows, "+ Add header"; values can be marked secret. [ASSUMPTION — accepted default]
  - **Limits and pagination**: Timeout, max response size, max pages; Pagination style (None, page, offset, cursor, link header) followed to the limits (FR-13). [ASSUMPTION — accepted default: pagination is set on the Data Source]
  - **Refresh**: "Supports Live refresh (~30 s)", off by default, helper "Only turn this on if the API can handle a call every 30 seconds per block."; gates Live in step ② (FR-60).
  - **Test connection** (FR-12): server-side, with auth; `msg:test-ok` or an error card with Technical details (status, request ID, blocked-host or non-JSON reason). Optional before Save. [ASSUMPTION — accepted default: Test is not required to save]
  - **Save** returns to the list with the row highlighted, health "Checking…" until the first call; audited (FR-68).
- **Health** (FR-12) shows in the list, step ② select, Platform health and Admin notifications: a dot **and** a word, plus "Last success 10:42". A 304 is a success and updates "checked at" (FR-13); Admins see "Data as of 10:40 · checked 10:55" in About this block.

### Version history and restore (FR-41)

- "Versions" in a Block's or Template's row menu (Block management, Published blocks, Dashboard templates) opens a `data-table`, newest first: version ("v1.1"), **Lifecycle State** (Draft, Published, Unpublished, Archived), published by, date, change summary. The version users get is tagged **"Current"**; older Published rows show "Superseded by v1.1" in caption text-muted. "Live" is not used, to avoid the Live Refresh Interval.
- **View** is read-only: preview (with state switcher) and per-step settings summary.
- **Restore as draft** creates a **new Draft** (`msg:restore-done`) and opens the wizard at step ①; the current Published version stays until that Draft is published normally. **If a Draft exists** [DECISION B2]: `msg:restore-replace` first (destructive; Cancel focused); a Draft is never discarded silently.
- Restore needs the publish permission (FR-40) and is an Audit Event ("Alex Morgan restored Revenue overview v1.1 as draft v1.3", FR-68).

### Unpublish and archive (FR-44, §4.8)

Destructive confirmations, publish permission only.
- **Unpublish**: "Unpublish Revenue overview?" with `msg:unpublish-impact`; **Unpublish Revenue overview** / Cancel.
- **Archive**: "Archive Revenue overview? It becomes read-only." with the impact count while published; **Archive Revenue overview** / Cancel.
- Audited; users see the Unpublished face and the "was unpublished" notification (FR-64).

### Template editor (FR-45 to FR-47)

- Name\*, Description, **Access** (as step ⑤; FR-7). The canvas is the grid in Edit Layout Mode, always on; "+ Add block" lists published Blocks with the first-free-slot rule.
- **Mandatory** is a `switch` in each Block's ⋯ (`menuitemcheckbox` with On / Off); Mandatory Blocks show the `msg:locked` badge as users see it.
- Save draft and **Publish template** follow the Block lifecycle (validation summary, impact dialog with the Template line, Version history). The default for new users is set in System settings (FR-46).
- **Newly mandatory Blocks** are appended per FR-47, the one exception to "layouts never move" (existing Blocks keep position and size); on next load they show the just-added outline and the user gets `msg:mandatory-added`.

### Dashboard switcher and Date Range (FR-51)

- **Switcher** ("Grid dashboard ›"): popover with Overview first, then My dashboards in order, ✓ on the current one, "+ New dashboard" and "Manage dashboards". Choosing loads in place and closes the Add-blocks Panel. [ASSUMPTION — accepted default: the two footer links]
- **Date Range** ("May 1 – May 31 ›"): presets (Today, This week, This month, Current year) and **Custom** with the two-month `date-range-calendar` and Apply. [ASSUMPTION — accepted default: the preset list (from FR-15's default date ranges) and the two-month calendar]
  - **APG date grid**: each month a `grid`; arrows by day and week, Page Up/Down by month, Home/End to week ends; Enter sets start then end, announced ("May 1 to May 31 selected"); Apply disabled with the reason until both are set; Esc returns focus to the button.
  - Applies to Follow-dashboard Blocks (FR-35); remembered per dashboard on the server (FR-56).
  - A change refreshes affected Blocks **in place** (last values with a header spinner; a skeleton only without prior data); nothing moves; focus returns to the button.
- Mobile: both become compact selects in a header row.

### My dashboards actions (FR-49, FR-57)

Rename, Duplicate, **Reset to template**, Delete (row or the dashboard's ⋯).
- **Reset to template** (only on Template-created dashboards, including a seeded Overview, FR-46): destructive confirmation with `msg:reset-template`; **Reset Finance weekly – Jamie** replaces Blocks, positions and sizes with the Template's **current** layout, Mandatory Blocks included; no Undo.
- **Delete**: destructive confirmation; on **Overview**, **disabled** with `msg:overview-delete` (FR-49).

### Admin overview and Platform health (FR-65, FR-66)

- **Metric cards**: Published blocks (change this month), Active dashboards (change this week), Template adoption (% of dashboards from a Template), Active users (count and share active this week). **Changes show ↗/↘ with a sign**, coloured good or bad; shares are plain. **Windows and definitions (e.g. "active") are TBD** [DECISION 2026-10-05, gaps; PRD §12.1]; nothing here fixes one.
- **Recent block activity**: five latest publish/edit actions, newest first: category icon tile, Block name, "Published by …" / "Draft by …", time (relative within a day, "Yesterday", then the date). Rows open Block management; **View all →** opens the Audit log filtered to Block events. [ASSUMPTION — accepted default: the View all target and row count]
- **Platform health**: **Platform services** (Dashboard API, Data refresh service, Authentication, Notification service: dot, word, uptime %, its window part of the TBD) and **Your data sources** (dot, Healthy / Degraded / Unreachable, "Last success 10:42"; opens the form). The overall badge (Operational / Degraded / Outage, with the word) sums up the services.
- Primary action: **Create block**.

### Password recovery (FR-2)

- **Forgot password?**: one email field and **Send reset link**; always `msg:reset-requested`, so accounts can't be discovered; throttled like sign-in.
- **Reset password**: new and confirm, then Sign in with `msg:password-changed`; expired or used links show `msg:reset-expired` with **Request a new link**.
- Remember me maximum: TBD (PRD §12.1).
- **Autocomplete** (1.3.5): email `username`, password `current-password`, new and confirm `new-password`; profile `name` and `email`. Paste into passwords is allowed.
- **Show password**: a button with `aria-pressed`; keeps caret and value.

### Session timeout (R5)

Idle timeout TBD (PRD §12.1, FR-4).
- **Warning** [DECISION R5]: **2 minutes before** expiry, `msg:session-warning` with a countdown, **Stay signed in** (primary, initial focus) and Sign out; announced at 2:00, 1:00 and 0:30. [ASSUMPTION — accepted default: the announcement points] Stay signed in extends and restores focus.
- **Wizard**: autosave loses nothing; it reopens at the same step with `msg:session-expired`.
- **Other forms** (Data source form, Template editor, settings): same warning; after expiry, sign-in returns to the page but unsaved fields are not restored. [ASSUMPTION — accepted default]
- Unsaved secrets are never autosaved.

### Profile & settings: keyboard shortcuts (R6)

**Keyboard shortcuts: On / Off** [DECISION R6]: a `switch`, **On by default**, per user. Off disables single-key shortcuts (`/`, `A`, `M`) only; modifier shortcuts (⌘K / Ctrl+K, Ctrl+Z) and widget keys (arrows, Enter, Space, Esc, Tab) still work, and everything stays reachable by Tab and buttons (Move {Block} still takes Space). The setting lists the shortcuts. [ASSUMPTION — accepted default]

### Workspace switcher, Search ⌘K and Notifications

- **Workspace switcher**: the sidebar card opens a popover of Workspaces (name, label, the user's role), searchable above 7 [ASSUMPTION: the threshold]. Switching changes the whole app: same area if the user holds that role there, otherwise User **Overview** with `msg:workspace-role` [ASSUMPTION]; unsaved wizard work prompts Save draft. Search, notifications and lists are Workspace-scoped.
- **Search ⌘K**: a combobox palette (top bar or ⌘K / Ctrl+K; active result `listbox-option-active` via `aria-activedescendant`), grouped Dashboards, Blocks, Templates, and for Admins Data Sources, Users, Settings; only permitted items (FR-63). A Block result opens the Add-blocks Panel with that row focused and expanded.
- **Notifications**: the bell shows an unread count (a number, not only a dot); the popover lists items newest first (icon, sentence, relative time, link) with "Mark all as read". User types: new Block; `msg:notify-version`; Block unpublished. Admin types: other Admins' publications; Data Source health; repeated mapping failures (`msg:notify-mapping-failed`). In-app only; email deferred (FR-64).

### Small components (behaviour)

| Component | Behaviour |
|---|---|
| Buttons | One primary per view; a disabled primary shows its reason inline; async actions show an inline spinner and keep their width |
| Inputs and selects | Validate on blur and submit, not per keystroke (JSON validates on paste). Submit focuses the first invalid field. `aria-invalid="true"`, `aria-describedby` to a message starting with an error icon and hidden "Error:". Two or more errors: an **error summary** of links at the top, focused on submit. Unlabelled search fields get an accessible name (`aria-label="Search blocks"`); placeholders never carry instructions |
| Required marker | Red `*` is `aria-hidden`; the field has `required` (or `aria-required`); "* Required" once per form |
| Radio groups, segmented controls | `radiogroup` with arrow keys (Access, record-path list, sign-in role cards, filters; Desktop / Tablet / Mobile, Fetch / Paste, Source), or buttons with `aria-pressed` |
| Switches | `role="switch"`, `aria-checked`, visible On / Off word; Space toggles |
| Tooltips (1.4.13) | Every tooltip (hotspot, icon rail, disabled reason, chart point, role and transform descriptions) shows on focus and hover, Esc dismisses it without moving focus, it stays while hovered, and it never holds the only copy of information |
| Textarea | Grows to the viewport; never clips |
| Secret input | "•••••••• · set 2 Oct 2026 · Replace", read as "Secret set on 2 Oct 2026"; no reveal; Replace ("Replace token") clears and requires a new value (FR-9); screen-reader text never includes resolved user-context values |
| Chips | Category chips: single-select, one tab stop, arrows between |
| Badges | Never interactive; Sample data, Draft, New, Locked, Partial data and version always include text |
| Role chips | Menu buttons; changes apply at once with the T1 Undo note |
| Sign-in role cards | `radiogroup` with visible radios; the choice sets "Sign in as User / Admin" |
| Data tables (admin lists) | Real `table`; sortable headers are buttons with `aria-sort`; row menu "More actions for {row}"; results count announced politely (debounced) |
| Status pieces | Dots, the status-bar meter and progress bars are `aria-hidden`; the word or number is the value ("Dashboard API, Operational, 99.99% uptime"); the Sample badge's "S" glyph is `aria-hidden` |
| Progress list, calendar | Progress rows are list items ("Website redesign, 12 of 16 tasks, 78%"). The week strip is a `list`; today has `aria-current="date"` ("Wednesday 22 May, today"); agenda items read "09:30, Product sync, Meeting room 2A, 45 min" |
| Technical details | Disclosure with `aria-expanded`; "Copy request ID" button |

## State Patterns

The single home for states; copy is cited from Canonical messages.

| Surface | State | Treatment |
|---|---|---|
| Block | Loading | Block Type skeleton **only on a cold load or without prior data**; real header. Later refreshes update in place |
| Block | Empty | `msg:block-empty`, body-sm text-muted, centred |
| Block | Error | `msg:block-error`; Admins get Technical details in About this block |
| Block | Stale | Last values in text-muted, inline `msg:stale` in warning with a clock icon; recovers automatically (FR-61) |
| Block | Unavailable | The Slot shows `msg:unavailable-user` with ⚠, **never 0**; About this block and Admins show `msg:unavailable-admin`; the rest renders |
| Block | Partial data | Missing cells "—", header badge "Partial data" (FR-30) |
| Block | Minimized | Header only; Expand replaces Minimize |
| Block | Paused (R4) | Values unchanged, no automatic refresh, header `msg:paused` |
| Block | Unpublished | `msg:block-unpublished` with Remove |
| Block | Access removed [DECISION B1] | The user's group lost access: `msg:block-access-removed` with **Remove**, as Unpublished; no data shown |
| Dashboard | Empty | `msg:dashboard-empty` with + Add block |
| Dashboard | Cold load | Shell at once; each Block loads independently with its skeleton |
| Dashboard | Layout fails to load | Shell renders; `msg:dashboard-load-failed` with Retry; no partial or default layout; nothing saved |
| Dashboard | Offline / reconnecting | Banner `msg:reconnecting`, announced once; Blocks keep last values, then go Stale on schedule; on reconnect `msg:back-online` once and Blocks refresh in place. No offline editing (non-goal) |
| Dashboard header | Live freshness / paused | Oldest Data-as-of Time of Live Blocks (FR-62), never announced on routine refreshes; when paused, "Resume live updates" and `msg:paused` replace it (R4) |
| Expanded view / View as table | Loading / empty / error | Panel and header at once with skeleton rows; `msg:expanded-empty`; `msg:expanded-error` with Retry, dashboard unaffected |
| Add-blocks Panel | Empty search · none published | `msg:drawer-empty-search` with Clear search · `msg:drawer-none` |
| Add-blocks Panel | No space in view | `msg:toast-add-below` with Show me ↓ and the edge pill; no auto-scroll |
| Add-blocks Panel | Loading / fails to load | Row skeletons with thumbnail placeholders · `msg:drawer-load-failed` with Retry; search and chips disabled with that reason |
| Add-blocks Panel | Inline preview | `msg:sample-drawer` under the preview, always |
| Notifications | Empty | `msg:notifications-empty` |
| Search ⌘K | Loading or slow · no matches | Ready groups at once, two skeleton rows per loading group, "Searching…" announced once after about 1 second [ASSUMPTION — accepted default] · `msg:search-empty` |
| Wizard · any | Save failure | `msg:save-failed` inline by Save draft, with Retry; every change stays |
| Wizard · any | Offline | Banner `msg:offline-editing`; editing continues; Save draft, Fetch, Test and Publish disabled with that reason until reconnect |
| Wizard · any | Session expiring / expired | `msg:session-warning` 2 minutes before; after re-sign-in, the same step with autosaved work and `msg:session-expired` (R5) |
| Wizard · any | Draft open by another Admin | **Soft lock** [DECISION]: read-only view, `msg:draft-locked` banner, **Take over editing** (autosaves the holder's work, then notifies them with `msg:draft-taken-over`). Releases after ~15 min inactivity or tab close [ASSUMPTION — accepted default timeout]. Nobody's changes are silently overwritten (the B2 principle) |
| Wizard · any | No publish permission | Publish disabled with `msg:perm-publish` |
| Wizard · Data Source | No Data Sources | `msg:datasource-none` replaces the select; Fetch and Continue disabled with that reason; Paste sample JSON stays, so mapping can start, but Publish needs a real Data Source (A1) |
| Wizard · Data Source | Fetch failing · invalid JSON | `fetch-error-card` with Retry and Technical details, offering Paste sample JSON · `msg:json-invalid` with line, column and Go to line; Continue disabled |
| Wizard · Data Source | Too large / too many pages | `msg:response-too-large` (or the page-limit equivalent); never truncated (FR-13) |
| Wizard · Data Source | Live not supported | Live (~30 s) disabled with `msg:live-not-supported` (FR-60) |
| Wizard · Map Data | Sample data · complex nested | Badge in the status bar plus preview label (T5) · record-path picker, Low-confidence pinned (load and Re-run only), field tree |
| Wizard · Map Data | API changed · transform error | `msg:api-changed`, Slots flagged, `msg:api-match-found` · error on the row; Continue disabled with the reason inline |
| Wizard · Map Data | Below `{spacing.reflow-stack-below}` | `msg:map-small-screen`, dismissible, never blocking; rows stack |
| Wizard · Save/Publish | Shape mismatch · API unreachable | Paths listed · `msg:live-shape-unreachable`; either way Publish blocked, Save draft available |
| Data sources | Unhealthy | Dot plus word (Healthy / Degraded / Unreachable) and last success time |
| Data source form | Test failing | Error card with the plain reason (blocked host, timeout, auth rejected, not JSON) and Technical details; Save allowed unless the host is blocked |
| Data source form | Host blocked | `msg:host-not-allowlisted` on Base URL; Save disabled; audited (FR-10) |
| Data source form, Template editor | Save failure, offline, session expiry, edited elsewhere | As the Wizard rows, with the form wording of `msg:save-failed`; expiry per *Session timeout* → Other forms; concurrent edits use the wizard's soft lock |
| Admin overview | Platform degraded · Data Source unhealthy | Badge "Degraded" (warning) or "Outage" (error), always with text · its health row shows dot, word and last success; an Admin notification is sent (FR-64) |
| Admin overview | Metric windows | TBD [DECISION 2026-10-05, gaps]; PRD labels until defined |
| Version history | Viewing a version | Read-only; Restore as draft disabled with the reason without the publish permission |
| Templates (User) | None available | `msg:templates-none`. Templates and Blocks limited to other groups are never listed in the gallery, the Add-blocks Panel or search (FR-7) |
| My dashboards | Overview | Delete disabled with `msg:overview-delete` |
| Password recovery · Sign in | Expired link · throttled, wrong role, failed | `msg:reset-expired` · `msg:throttled`, `msg:signin-role-denied`, `msg:signin-failed` |

### Generic states for admin and settings screens

Every Admin list and form, plus Profile & settings, Help & support, User configuration, System settings, Block categories, Audit log, Dashboard templates and My dashboards, unless a row above is more specific. `{items}` is the page's plural noun ("draft blocks", "data sources", "audit events").

| State | Lists (`data-table`) | Forms and settings |
|---|---|---|
| Cold load | Toolbar and header at once; 5 skeleton rows | Skeleton fields; Save disabled until loaded |
| Load failure | Full-width row "We couldn't load {items}. Try again." with Retry | "We couldn't load these settings. Try again." with Retry; never stale values |
| Empty | `msg:list-empty` with the primary action (none if the user can't create) | — |
| No results | `msg:list-no-match` with Clear search; count "0 of n", announced politely | — |
| Save success | Row highlighted (accent-soft, leading bar) and focused | `msg:saved`; focus stays on Save |
| Save failure | Row actions roll back with `msg:toast-rollback` (an alert, not auto-dismissed) | `msg:save-failed` (form wording) by Save; values kept; focus to the message |
| Validation errors | — | Inline errors and the error summary |
| Unsaved changes | — | `msg:unsaved-changes`: Save, Discard changes, Keep editing (focus on Keep editing) |
| Offline | Read-only; row actions disabled with `msg:offline-editing` | `msg:offline-editing` banner; Save disabled |
| Permission denied | `msg:perm-denied`; page not rendered; audited (FR-5) | Same |
| Action not permitted | `aria-disabled` with its reason | Same |

## Interaction Primitives

- **Drag and resize** only in Edit Layout Mode, each with a keyboard or menu equivalent; **no drag from the Add-blocks Panel**; mapping uses role assignment, field pickers and hotspots, never drag. [DECISION A3]
- **Keyboard model.**
  - `⌘K` / `Ctrl+K`: global search.
  - `Ctrl+Z` / `⌘Z` on a dashboard: undo the last add or remove, **no time limit**, toast or not [DECISION R3]; not a single-key shortcut, so it works with shortcuts off; ignored in text fields. [ASSUMPTION — accepted default: the undo history lasts while the dashboard stays open] In Edit Layout Mode it is the mode's Undo, with `Ctrl+Shift+Z` / `⌘⇧Z` as Redo.
  - **Single-key shortcuts** fire **only while their component has focus and focus is not in a text field**, and can be turned off (Profile & settings → **Keyboard shortcuts**, default On) [DECISION R6]: `/` focuses the drawer's search (not page-global); `A` adds the focused drawer row; `M` picks up the focused Move button.
  - Edit Layout Mode: `M` or `Space` picks up, arrows move, `Shift` + arrows resize, `Enter` drops, `Esc` cancels the move and restores the Block; moves are announced against neighbours.
  - `↑` `↓`: optional accelerators between drawer rows and slot rows; every control is also in Tab order.
  - `Enter` / `Space` activate the focused button; Enter never has a hidden second meaning on a row.
  - `F6` / `Shift+F6`: an **enhancement** cycling dashboard, drawer and toast region, never the only route.
  - `Esc` closes the innermost layer: picker or popover, then inline preview, then drawer. **In Edit Layout Mode, the first Esc ends (cancels) a move; a second Esc, or Done, exits and saves**; one press never does both.
- **Landmarks and skip links.** `banner` (top bar), `navigation` (sidebar or icon rail), `main` (labelled with the page or dashboard name); the wide drawer is a non-modal `complementary` "Add blocks"; the mobile sheet is a modal dialog with focus trapped; the toast stack is a `region` "Notifications". Skip links (`skip-link`, visible on focus): "Skip to content" first on every page; "Back to dashboard" in the drawer header; "Go to notifications" from the drawer and dashboard headers while a toast shows; "Skip to slot checklist" before the Map Data preview.
- **Focus stability.** Changing content never takes focus with it.
  1. **Update in place**: Live, interval, ↻, Date Range and Block period refreshes keep **stable keys** for rows, points and cells; skeletons only on cold load or without prior data; a focused element is never replaced or re-created.
  2. **If the focused item disappears, focus the nearest survivor and announce it** briefly and politely ("Recent activity removed. Focus on Project status."): a Block removed from its ⋯ → next Block title, else previous, else "+ Add block"; a slot row leaving a filter → next row, else previous, else the filter; a control removed by the roles collapse → "Edit roles"; a removed transform row → next, else previous, else "+ Add step"; an Admin list row → next row, else the list's search.
  3. **Never auto-collapse, re-sort or re-pin while focus is inside**: the roles table (T4) collapses after focus leaves, or at once on "Looks right"; pinning and slot order only at step load and Re-run; a row confirmed under Needs review or Missing stays, marked Confirmed, until focus leaves the list or the filter changes; Table Blocks keep sort and page; rollbacks restore elements in place.
- **Toasts with Undo** for add and remove (the T1 note offers Undo inline); each Undo fully reverses. **Timing** [DECISION R3]: an action toast stays **at least 10 seconds**, pauses on hover and **never auto-dismisses while focus is in the drawer or the toast**; it is announced politely, naming the Undo route ("Undo with Ctrl+Z"). **Error and rollback toasts** are `role="alert"` and **never auto-dismiss**.
- **Disabled controls.** Blocked primary or row actions (Publish without permission, Continue to Preview, Fetch, Restore, Unpublish, Archive, Delete on Overview, rate-limited Refresh) use `aria-disabled="true"`, not `disabled`, **stay focusable**, and point `aria-describedby` at the inline reason. Activating one does nothing except, where a step has blockers, focus the first and announce the reason. Reason text is never dimmed (DESIGN.md → Buttons → Disabled). Disabled select options (Live) carry the reason as their description.
- **Destructive confirmations** (unpublish, archive, delete dashboard, reset to template, replace draft on restore, access change): `role="alertdialog"`, `aria-labelledby` on the title, `aria-describedby` on the impact; **initial focus on Cancel**; Esc cancels; the destructive button repeats the object ("Reset Finance weekly – Jamie"); focus returns to the invoker, or its row if the invoker is gone. Other dialogs (publish impact, session warning, unsaved changes) focus the title or the safe primary action named in their pattern.
- **Inline expansion over modals.** Drawer previews, slot editors, "Technical details" and the roles table expand in place, one at a time. Dialogs only for publish impact, destructive confirmations, the session warning and unsaved-changes prompts; at most one deep.
- **Optimistic UI.** Add, Remove, minimize and layout moves apply at once and roll back with `msg:toast-rollback` (an alert) on rejection, restoring the element in place and keeping focus on it.

## Accessibility Floor

**WCAG 2.1 AA** (NFR-7). Text and non-text contrast (1.4.11) is in DESIGN.md → Colors, light theme only; dark-theme contrast is unchecked while deferred [DECISION 2026-10-05, gaps]. Widget semantics live in each pattern (role chips, slot rows, field tree, hotspots, stepper, status pieces, switches, masked secrets) and in Small components. Rules with no other home:

- **Drag alternatives** (FR-59): move with M or Space + arrows or the menu; resize with Shift + arrows or Make wider / narrower; add without dragging.
- **Focus order.** Drawer: "Back to dashboard", Close, Search, chips (one stop), Filter, each row's disclosure then actions, Done. Wizard: "Skip to content", stepper, step content in reading order, status bar actions, Continue.
- **Focus return.** Closing the drawer returns to "+ Add block" (or the added Block after Show me); closing any popover, picker or dialog returns to its invoker. A refresh, collapse, filter or removal never drops focus to the body.
- **Focus visible and not obscured.** The `focus-ring` (`{colors.focus-ring}`) on every interactive element; active options in listboxes, menus, trees and palettes use `listbox-option-active`; hotspots use the standard ring. Sticky layers set `scroll-padding` so focus is never hidden.
- **Charts, tables, deltas, timing, shortcuts**: *Chart Block* [DECISION R1, R2], *Table Block*, Map Data → Direction, *Toasts with Undo* [DECISION R3], *Live updates and pause* [DECISION R4], *Session timeout* [DECISION R5], Keyboard model [DECISION R6].
- **Never colour alone**: deltas carry ↗/↘ and, for Lower-is-better, a word; statuses icons and words; role chips their names; confidence a word and a meter; selected states a ≥ 3:1 bar, border, ✓ or radio; chart series markers, dashes or direct labels (V5, R2).
- **Names and forms** (1.3.5, 3.3.1, 4.1.2): buttons carry full names ("Remove Calendar from dashboard"); labels sit above fields; error, `autocomplete`, disabled-action and dialog rules are in Small components, *Password recovery* and Interaction Primitives.
- **Reflow and zoom** (1.4.10, 1.4.4, 1.4.12): at 320 CSS px (400%) one column with no page scroll sideways; **only** the sample-rows table, the JSON view and wide Table Blocks scroll sideways, each in a labelled focusable region; `msg:map-small-screen` never blocks; control heights are minimums and Block content scrolls inside its Block, so 200% text and text-spacing overrides lose nothing; under 480px viewport height only the top bar is sticky.
- **Live regions.** **Polite**: the drawer count (debounced about 500 ms); add, remove and move; action toasts with the Undo route; the Map Data status bar on required-missing or needs-review count changes; JSON validation; focus recovery; sort and page changes. **Once** (`role="status"`): reconnecting, and `msg:back-online`. **Stale and Unavailable are aggregated per dashboard and debounced** (about 2 seconds) into one message such as `msg:stale-aggregate`; recovery once. **Assertive** (`role="alert"`): error and rollback toasts, not auto-dismissed. **Never announced**: routine refreshes, the freshness line, KPI values, chart data and summaries.
- **Reduced motion** (`prefers-reduced-motion`): a static outline instead of the Show me pulse; instant reflow, drawer push and slide, and sidebar collapse; no chart draw-in or value transitions; toasts appear without sliding; accordions (drawer preview, slot editor) open without animation; the "Just added" halo is a static outline for 3 seconds; skeletons don't shimmer.
- **Targets**: at least `{spacing.target-min}`; `{spacing.target-chrome}` for Block Chrome icons, chips, row actions, toast actions and the toast close; `{spacing.target-touch}` on touch layouts.

## Key Flows

### Flow 0: Alex registers a Data Source (brief §1 Admin job; FR-9 to FR-13)

Alex Morgan, Admin with the manage-data-sources permission, Genie Inc. Starts the brief's success measure "time from registering an API to a published block".

1. **Data sources** → **+ Register data source**: "Finance data warehouse" and the base URL, which passes the allowlist check on blur. **Bearer token**, default header `Accept: application/json`, default limits, "Supports Live refresh" off.
2. **Test connection**: `msg:test-ok`.
3. **Climax:** **Save**. The token reads "•••••••• · set 5 Oct 2026 · Replace"; the row shows **Healthy** and "Last success 09:02".
4. **Create block**: in step ② the source is in the select with its health, and **Fetch from API** returns the first Sample Response (Flow 1).

**Failure.** Host not allowlisted: `msg:host-not-allowlisted`, Save disabled, attempt audited.

### Flow 1: Alex (Admin) builds the "Revenue overview" block (UJ-1)

Alex Morgan, Admin with publish permission, Genie Inc. The finance API is unreachable from Alex's laptop today, but Alex has a sample response from the API team.

1. **Admin** role card → "Sign in as Admin →" → **Admin overview** → **Create block**.
2. **①**: "Revenue overview", description, Category **Finance**, **KPI & Chart**, 8 columns, Medium; header, subtitle, resize, minimize and refresh on. Preview skeleton at "8 columns × Medium (360px)".
3. **②**: "Finance data warehouse", `GET /api/v2/finance/revenue`, every 15 minutes, Current year; **Paste sample JSON** → `msg:json-valid`.
4. **③** opens auto-mapped: "Auto-mapped 6 of 9 · 1 required missing"; Continue disabled with `msg:required-slot-missing`.
5. `revenue` has a flagged **Filter/Category** chip and "✦ revenue looks like a Measure — use as series?". **Set as Measure** fills Chart series and draws the line; focus moves to the chip.
6. "Needs review (2)": **KPI label** (Medium, "check wording") and **Comparison** (Medium); the High Headline value is accepted. Alex sets Currency USD, 0 decimals, Headline emphasis; confirms KPI label; clicks the "↗ 14.2%" region, checks `PCT_CHANGE(total, previous_total)` with higher-is-better, confirms.
7. Moving on collapses the roles table; "9 of 9 · all required confirmed"; **Continue to Preview →** turns yellow.
8. **④**: all three devices look right; the Unavailable state shows `msg:unavailable-user`, not $0.
9. **⑤**: "✓ Live response matches the sample".
10. **Climax:** **Publish block** → `msg:publish-success`; Published blocks opens with the row highlighted. The Block matches the preview built from a pasted sample, without a single developer ticket.

**Failure.** The live response has `data.total_revenue`, not `data.total`: "total — not in live response" blocks Publish; **Save draft** lists it under Draft blocks with the mismatch noted.

### Flow 2: Jamie (User) personalizes the Overview (UJ-3)

Jamie Davis, User, desktop at 1440px.

1. Jamie signs in as User → **Overview** → **+ Add block**. The drawer pushes in, the sidebar becomes the icon rail, nothing is hidden; focus in search; "8 available blocks".
2. **Charts** chip, then the "Revenue vs Expense" row body: a real chart, "Sample data", "Data source: Finance data warehouse · Refreshes every 15 min · v1.0 · Default size 6 columns × Medium".
3. Hovering **+ Add to dashboard** shows the free-space hint next to Recent activity.
4. **Climax:** the Block lands in that gap with the yellow "Just added" outline; **no other Block moves**; `msg:toast-add` stays while Jamie works in the drawer; the row reads "✓ Added" with Remove.
5. **× Remove** on "Recent activity" (`msg:toast-remove`), then **Done**: the sidebar returns and the outline lingers briefly.
6. **Edit layout**: Jamie drags the Block beside "Revenue overview", resizes it, **Done**. Tomorrow on mobile, the same Blocks appear in the same order.

**Failures.** No free slot in view: `msg:toast-add-below` and the edge pill. "payroll": `msg:drawer-empty-search`. "Employee attendance" is in "On your dashboard" with `msg:locked`, no Remove. A wrong removal after the toast has gone: Ctrl+Z restores it in place (R3).

### Flow 3: Alex publishes an update (UJ-2) · A new block version (UJ-6)

Alex Morgan, Admin with publish permission, desktop. Jamie Davis already has "Revenue overview" on his Overview.

1. **Block management** → Edit "Revenue overview": a v1.1 Draft opens; v1.0 stays live.
2. Alex changes the chart colour rule, adds a target line (an optional Slot) and clicks **Publish block**: "Publish Revenue overview v1.1? · Changed: Presentation (series colour), Chart (target line added) · **214 users** have this block · **2 templates** include it · Their layouts won't move."
3. **Climax:** **Publish v1.1**. On Jamie's next refresh the target line appears **in exactly the same position and size**, and his bell shows `msg:notify-version`. The block is under the Finance chip and in search; the publish is in the Audit log.

**Failure.** No publish permission: Publish disabled with `msg:perm-publish`; Alex saves the draft.

### Flow 4: An API outage (UJ-5) · UJ-1 edge case: the API renames `total`

Jamie Davis, User, on his Overview at a desktop; Alex Morgan, Admin with publish permission, receives the alert.

1. `total` becomes `total_revenue`: Jamie's headline shows `msg:unavailable-user` [UX DECISION — PRD alignment needed; see the message row]; the chart still renders.
2. The "Pending approvals" source goes down: last values greyed with `msg:stale`, the oldest Data-as-of Time in the header, `msg:stale-aggregate` announced once.
3. Alex's `msg:notify-mapping-failed` opens a Draft at **Map Data** with `msg:api-changed` and `msg:api-match-found`. Alex accepts, confirms the Headline value and the paused Comparison, and publishes v1.2.
4. **Climax:** Jamie's headline returns to **$284,680** on the next refresh, layout unchanged; the approvals Block clears Stale by itself.

**Failure.** No match: Alex picks a field in the field tree, or leaves v1.1 live (that Slot Unavailable) and saves the fix as a draft.

### Flow 5: Jamie creates a second dashboard from a template (UJ-4)

Jamie Davis, User, desktop.

1. **Templates** → preview "Finance weekly" → **Use template**, "Finance weekly – Jamie", Create.
2. **Climax:** it opens with the template layout and locked Mandatory Blocks, appears in **My dashboards** and the switcher; switching back to Overview keeps both layouts intact.

**Failure.** A template Block Jamie can't access is omitted with `msg:template-block-omitted`.

## Responsive & Platform

| Width | Shell | Dashboard | Add-blocks Panel | Wizard |
|---|---|---|---|---|
| ≥ 1440px | Sidebar (`{spacing.sidebar-width}`) | 12 columns | Push (`{spacing.drawer-width}`); sidebar → icon rail; narrow layout | Two columns; sidebar kept; sticky preview right; slot rows on one line |
| 1280–1439px | Sidebar | 12 columns | Push; icon rail; narrow layout (~824px left for Blocks at 1280px) | **Icon rail** [DECISION R7]; two columns; sticky preview (`{spacing.preview-pane-width}`); slot rows on one line (left column ~752px at 1280px) or wrapped |
| 1024–1279px | **Icon rail**; ☰ opens the full sidebar as a sheet | 12 columns, narrow as needed | Push with icon rail; **below ~1232px the dashboard is single-column while the drawer is open** | Icon rail [R7]; two columns; preview `{spacing.preview-pane-width}`; **slot rows wrapped** (`slot-row-wrapped`, below `{spacing.slot-row-wrap-below}`) |
| 768–1023px (tablet) | Icon rail; ☰ sheet | Narrow layout (wide Blocks at 6 columns) | **Overlay sheet**, modal | Single column; collapsible "Preview" bar pinned at the bottom [ASSUMPTION]; slot rows wrapped |
| 640–767px | Top bar with ☰ and "+ Add"; sidebar as a sheet | Single column, user's order | Overlay sheet, `{spacing.drawer-dim-strip-mobile}` dim strip, `drawer-thumbnail-mobile`, toast inside | Single column; Preview bar; slot rows wrapped |
| < 640px (mobile, and desktop at 400% zoom) | As above | Single column | As above | Usable; `slot-row-stacked`; only the sample table and JSON view scroll sideways, in labelled regions; dismissible `msg:map-small-screen`, never blocking |

- **Mobile dashboards**: reorder only (drag or Move up/down), no free resize (FR-58); Date Range, dashboard switcher and Pause live updates become compact header controls.
- **Touch**: hover affordances get tap equivalents; × Remove always shows beside ✓ Added; no free-space hint; scrolling chips bring the focused chip into view, never under the edge fade.
- **Zoom**: 200% on 1440px (720 CSS px) uses the tablet row; 400% on 1280px (320 CSS px) the < 640px row.
- **Browsers**: latest two versions of Chrome, Edge, Firefox and Safari, desktop and mobile (NFR-8).

## Inspiration & Anti-patterns

From the 2026-10-05 exploration rounds only; no external products were named. Accepted hybrids: [mockups/mapdata-hybrid.html](mockups/mapdata-hybrid.html), [mockups/addblocks-hybrid.html](mockups/addblocks-hybrid.html); their CSS colours predate the contrast fixes, so DESIGN.md tokens win.

- **Kept from the mockups**: yellow accent, dark sign-in hero, light shell, right-side Add-blocks Panel, live preview beside the wizard, the version-safe note (steps ① to ③, repeated in ⑤), form section cards with subtitles, top-bar settings gear.
- **Kept from the rejected alternatives (.working/)**: sketch C as a whole ([.working/mapdata-C-semantic-table.html](.working/mapdata-C-semantic-table.html)): roles on real sample rows and Block-Type-independent roles, the main path. Sketch A ([.working/mapdata-A-three-pane.html](.working/mapdata-A-three-pane.html)): the slot checklist as the review surface and type-mismatch messaging. Sketch B ([.working/mapdata-B-preview-first.html](.working/mapdata-B-preview-first.html)): hotspots as a shortcut, "why not" reasons, the re-fetch-needed state. Add-blocks V1 ([.working/addblocks-variants.html](.working/addblocks-variants.html)): dense searchable list, thumbnails, inline accordion preview, Remove on hover or focus (now beside a persistent ✓ Added).
- **Rejected**: any one of A, B or C alone (A crowds and its tree grows long on nested data; B can't reach Slots without a region; C guesses badly without a clean array); drag-to-slot as the main mapping action; the V2 gallery as primary browser (half the Blocks per screen, a quick-look popover over the dashboard), with multi-add and end-of-dashboard placement (the first free slot wins, D4); a scrim or modal panel on wide screens (D3); drawer-to-grid dragging; a bottom-left toast (T9) and a ~6 s toast as the only Undo (R3); an overlay below 1280px (icon rail instead, T6); a permanent free-space hint (T8).

## Security-conscious UX

PRD baseline only; no regulatory workflows beyond the PRD. Index:

| Concern | Rule | Where |
|---|---|---|
| Masked secrets | Write-only after saving (FR-9); "fetch as user" shows bound attribute names, not values [ASSUMPTION: the value-hiding detail] | *Data source form*; Small components |
| Sample data | Always labelled, mapping and preview only; published Blocks call the live Endpoint after a shape check | Map Data; step ⑤ |
| Permission-gated actions | Publish shown disabled with `msg:perm-publish` [DECISION A2; FR-20]; Unpublish, Restore and Archive likewise (FR-40); denials explain themselves and are audited | Wizard Shell; *Version history*; *Unpublish and archive* |
| Data scope | "shared data" badge without user-context binding (FR-8); "Preview as" | Steps ② and ④ |
| Outbound safety | Allowlist, blocked-address and size errors (FR-10, FR-13) | Step ②; *Data source form* |
| Escaped rendering | API text renders as text (FR-37); external links show ↗ and open in a new tab | *Block Chrome controls* |
| Honest numbers | Unavailable never 0; Stale shows its time; paused updates say so (R4) | State Patterns |
| Session | 2-minute warning, autosave and restore (R5) | *Session timeout* |
| Audit | Publish, unpublish, restore, archive and access changes logged with before and after values | Audit log; step ⑤ |
