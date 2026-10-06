---
title: "Reconcile: Architecture vs PRD v3.2.1 + Addendum v3"
date: 2026-10-05
checked: ARCHITECTURE-SPINE.md (AD-1..AD-24), SOLUTION-DESIGN.md (§0-§23)
against: prd.md v3.2.1 (FR-1..FR-68, NFR-1..14, §7, §9), addendum.md v3 (§A-§L)
---

# Reconcile: Architecture vs PRD

Status key: **covered** = an AD or SD section realizes it; **partial** = the structure supports it but a stated behaviour, rule or data element is missing; **missing** = no trace; **contradicts** = the architecture changes PRD semantics (flagged **[unlisted]** when SOLUTION-DESIGN §0 does not list it).

SD = SOLUTION-DESIGN.md. Spine = ARCHITECTURE-SPINE.md.

## 1. Functional requirements

| Item | Status | Where | Suggested fix |
|---|---|---|---|
| FR-1 Sign in with role choice | covered | AD-4 (session active area); SD §4.3 Sign-in, §10.2 "Admin vs user" | — |
| FR-2 Credentials, recovery, throttling | covered | SD §10.2 Web (throttling, time-limited single-use reset), §4.2 Identity, §22 (Remember-me TBD) | — |
| FR-2 Session-timeout warning (2 min, Stay signed in) | partial | SD §4.3 lists "session-timeout dialog" only | Specify: server-known expiry (`GET /api/v1/session` returning `expires_at`), a "Stay signed in" extend call, and **which background traffic does NOT extend the idle session** (results polling, Reverb auth, soft-lock heartbeats, autosave). Otherwise polling/heartbeats keep sessions alive forever and the idle timeout (FR-4) never fires |
| FR-2 Wizard draft autosave + restore after sign-in | partial | AD-13 (Draft = autosave target, CAS); SD §5.1 | Persist wizard position (current step, last-focused slot) on the Draft so "restored after sign-in" lands at the same step; state what happens to a CAS 409 during re-login restore; ensure Draft-sample TTL (SD §7.3) is longer than the session so restore doesn't lose the pasted sample |
| FR-3 Workspace membership, switcher, isolation, provisioning, per-membership roles | covered | AD-3, AD-4; SD §4.2 Operator, §10.1 | Add: on workspace switch, client drops Reverb channels and Pinia caches for the old workspace (one line in AD-16) |
| FR-4 Sign out, profile menu, Appearance hidden, idle timeout TBD | covered | SD §4.3, §22 | — |
| FR-4 Profile & settings (name, avatar, password, locale, tz) | covered | SD §7.2 `users` columns | — |
| FR-4 Help & support links configured by Workspace Admin | missing | — | Add `help_links` to `workspace_settings` (SD §7.2) and to the FR-67 settings list |
| FR-5 Role enforcement, denied attempts audited | covered | AD-4, AD-18; SD §15 "denied access" | — |
| FR-6 Invite, deactivate, assign role/permissions/groups | covered | SD §4.2 Identity/Access, §7.2 memberships, permissions | State that deactivation is a membership `status` checked by AccessEvaluator per request (implied) |
| FR-7 Block/Template access by group, immediate, audited, placeholder + Remove | covered | AD-4, AD-5; SD §5.5, §11.3 | — |
| FR-7 Access-change impact confirmation ("38 users will lose this block") | partial | SD §12 has impact only for publish | Add an `AccessChangeImpact` query (distinct memberships with an instance who lose access under the new grant set) to Access/Blocks contracts |
| FR-7 / FR-6 Who may change access | contradicts [unlisted] | SD §11.3 "Change access: `blocks.publish`"; §22 Q-A1 | PRD does not tie access to publish. Move Q-A1 into §0 as a conflict, or define an `access.manage` permission |
| FR-8 User-context binding, API filters, user can't change, "shared data" badge | covered | AD-7 (digest in Fetch Key); SD §6.1, §11.3 | — |
| FR-9 Data Source registration, encrypted write-only credentials | covered | AD-19; SD §6.1, §7.2 | SD adds auth type `none` (not in PRD list) — harmless extension, note it |
| FR-10 SSRF: allowlist, blocked ranges, operator private grants, redirects, audit | covered | AD-6, AD-22; SD §10.2 SSRF | — |
| FR-11 Endpoint, bindings, GET default, read-only POST, never modifies | covered | AD-6; SD §6.1 | — |
| FR-12 Test endpoint (status, latency, sample); source health + last success | covered | AD-6 Operations; SD §6.5 | — |
| FR-13 Pagination, rate limits, shared fetch, no truncation, conditional requests, JSON only | covered | AD-7, AD-8; SD §6.1-§6.4; C8 | — |
| FR-14 Configure Block (name, description, category, type, icon default from category) | covered | SD §7.2 `block_versions.config` (chrome) | Note icon-defaults-from-category rule in Block config defaults |
| FR-15 Data Source step (interval, default range, fetch/paste) | covered | SD §6.1, §6.7 | — |
| FR-16 Auto-map with confidence; confirm/override; override updates Field Role with Undo; click-preview; Continue gated on confirmation | partial | SD §4.2 `AutoMapper`; §6.6 Map Data | Model per-slot `{source: auto\|manual, confidence, confirmed}` in the Draft Slot Mapping, and make the Continue gate a server-side validation rule (`mapping.unconfirmed_slot`) so it survives autosave/restore |
| FR-17 Display & behaviour, min/max sizes, period behaviour | covered | SD §7.2 config (chrome, sizes, period behaviour); AD-21 size limits | — |
| FR-18 Live preview, state previews, full-size grid, preview-as | covered | SD §0 C6, C10; §5.1 | — |
| FR-18 Validation warnings must be resolved **or acknowledged** before Publish | partial | AD-13 ValidationReport | Add `acknowledged_warnings[]` on the ValidationReport bound to the Draft revision; Publish rejects unacknowledged warnings |
| FR-19 Save draft, Draft blocks list | covered | AD-13 | — |
| FR-19 Soft lock: read-only view, Take over (save holder first, notify), 15 min, tab close | covered | AD-13; SD §12 (sendBeacon, push flush) | Soft-lock heartbeat must not extend the auth session (see FR-2) |
| FR-20 Publish gated on real-API validation; permission-disabled button | covered | AD-13, AD-6; SD §6.6, §6.7 | — |
| FR-21 Fetch or paste sample; pasted never stored/production | contradicts (listed C5) | SD §0 C5 stores pasted sample in the Draft | Awaiting PO; fine as listed |
| FR-21 JSON tree / table view, path+type+example, re-fetch, fetch as user | covered | SD §6.6, §11.3 | — |
| FR-22 Record path, candidate arrays with counts, "Aggregate rows by…" default | covered | SD §6.6, §8 "Correct roll-ups" | — |
| FR-23 Field picker, static text, calc field, type override, no drag | covered | SD §7.2 `dataset_fields` override type | — |
| FR-23 Changing Block type keeps same-name/type slots, flags rest unmapped | partial | SD §12 only says "major reserved for a Block-Type change" | Add a `RemapOnTypeChange` rule in Mapping (keep name+type matches, mark others `unmapped`, never drop) and decide whether a type change on a published Block is allowed (if yes, the major-version rule is new semantics — list it) |
| FR-24 Transforms order, declarative, server-side | covered | AD-11; SD §8 | — |
| FR-25 Calculated field function set | covered | AD-12; SD §8 | — |
| FR-26 Text Templates (tokens incl. `{user.name}`, `{ROW_COUNT}`, period tokens with format) | partial | AD-12 (grammar only) | **Decide where tokens resolve.** Block Results are shared per Fetch Key (AD-7/AD-9) and formatting is "only in renderers via Intl" (spine Conventions). `{user.name}` resolved in the shared Shaper output would leak one viewer's name to others. Rule: Shaper emits an unresolved template + data tokens; renderer resolves `{user.*}` and formats per viewer locale. Add to AD-12/AD-21 |
| FR-27 Comparisons: mapped previous or comparison request; modes %, absolute, pp | covered | AD-7, AD-15 (one generation); SD §6.2 | Name the three prior-period resolutions (equal length, same period last year, custom offset) in the server period resolver; list `percentage_point` mode in config |
| FR-28 Ratio-safe aggregation | covered | AD-11; SD §8 | Use the FR-28 600/1000 + 450/500 = 70.0% example as a conformance test |
| FR-29 Presentation rules, Direction-coloured arrows, colour never only signal | covered | AD-21, SD §7.2 presentation | — |
| FR-30 Validation, Unavailable not zero, partial-data badge, owner notified | covered | AD-11, spine block states (`partial`); SD §6.6 | — |
| FR-31 Normative mapping example | covered | SD §21 conformance suite | Make FR-31 a golden conformance fixture explicitly |
| FR-32 Block Types and slot schema | covered | AD-21; SD §4.2 (11 keys) | — |
| FR-33 Extensible Block Types | covered | AD-2, AD-21 | — |
| FR-34 Chrome, ⋯ menu, About this block, View as table | covered | AD-21; SD §8 | Define a user-facing `BlockAbout` DTO (description, data source **name**, interval, data_as_of, version, owner per §7) so endpoint secrets/params never leak |
| FR-34 Footer action: URL template with field tokens, **http(s) only**; expanded view | partial | AD-12 (URL template grammar), SD §10.2 Rendering, §8 Expanded view | Specify: token values are **URL-encoded per component**, scheme/host validated **after** substitution (http/https only), `{user.email}` resolved per viewer (same issue as FR-26), and the template's static part validated at save time |
| FR-35 Block Period Selector and precedence | covered | SD §5.2 step 2, §6.1 | — |
| FR-36 Block states | covered | spine Conventions block-state enum; AD-21 | — |
| FR-37 Safe rendering, http(s)/in-app links | covered | AD-21; SD §10.2 | — |
| FR-38 Draft and Published lists with filters | covered | AD-20 (Inertia admin CRUD); SD §4.3 | — |
| FR-39 Publish impact: diff + users + Templates | partial | SD §12 "`COUNT(dashboard_items WHERE block_id)`" | **Bug:** this counts dashboards, not users. Use `COUNT(DISTINCT dashboards.membership_id)`. Also define impact for **Template** publishes (FR-39 says "Block or Template") and the version-diff source (config JSON diff by section: mapping, presentation, chrome, endpoint) |
| FR-40 Publish permissions, audited | covered | AD-13, AD-18; SD §10.2 | — |
| FR-41 Immutable versions, Restore → Draft, Replace draft prompt | covered | AD-13; SD §12 | — |
| FR-42 Version-safe propagation, size clamp | covered | AD-14; SD §5.4, §12 | — |
| FR-43 Categories: create, rename, **order**, archive; tags | partial | SD §7.2 `block_categories`, `blocks.tags` | Add `sort_order`, `archived_at` to categories; define what archiving a category does to its Blocks |
| FR-43 **Chips rule**: chips = exactly the categories with ≥1 Block available to the user; tags searchable, no chips | missing | — | Add to Dashboards/Search: chip list computed with the same AccessEvaluator SQL predicate (SD §11.2) over published, accessible, not-on-dashboard? (decide whether "On your dashboard" Blocks count); tags indexed in `search_documents` |
| FR-44 Block management actions (edit, duplicate, unpublish, archive, versions) | partial | SD §4.2 Blocks contracts lack Duplicate | Add `DuplicateBlock` (new identity, Draft from current version, grants **not** copied unless chosen, audited) |
| FR-45 Dashboard Templates, Mandatory, same lifecycle | covered | AD-13 ("Templates follow the same rules"), SD §12 | — |
| FR-46 Default Template seeds every new user's Overview | partial | SD §7.2 `workspace_settings.default template`, `dashboards.is_overview` | Specify the seeding command: on membership activation create Overview from the default Template's current version (skip inaccessible Blocks); behaviour when no default is set (blank Overview); whether changing the default re-seeds anyone (no) |
| FR-47 Template updates; newly mandatory appended | covered | AD-14; SD §12 | Decide what happens when the appended mandatory Block is not accessible to the user |
| FR-48 Template adoption counts | partial | SD §7.2 `dashboards.template_id` (data exists) | Add `TemplateAdoption` query in Templates/Health (count dashboards per template, including deleted? decide) |
| FR-49 Overview + My dashboards: blank, from Template, **duplicate**; rename/delete; Overview undeletable | partial | SD §7.2 `is_overview`, §4.2 Dashboards | Add `DuplicateDashboard`, `DeleteDashboard` (reject when `is_overview`), partial unique index one Overview per membership |
| FR-50 Templates gallery, Use template | covered | SD §11.2 (inaccessible Blocks omitted) | — |
| FR-51 Dashboard switcher, Date Range per dashboard | covered | SD §7.2 `dashboards.date_range` | — |
| FR-52 Add-blocks Panel (search, chips, filter, count, thumbnails, inline preview, "New", "On your dashboard") | partial | SD §4.3, §11.2, C10 | Define "New" (e.g. first published within N days, tunable) and the thumbnail source (static per Block Type vs rendered); count excludes "On your dashboard" |
| FR-53 Auto-placement, never rearrange, Show me/Undo, no drag-in, immediate remove, mandatory unremovable, no duplicates | covered | AD-14; SD §7.2 unique `(dashboard_id, block_id)` | State that RemoveBlock rejects `mandatory=true` server-side |
| FR-54 Edit layout, save on leave, undo while editing | covered | AD-14 SaveLayout; SD §12 dashboard `revision` | — |
| FR-55 User block controls; users cannot change mapping | covered | AD-20; SD §6.4 manual refresh | — |
| FR-56 Server persistence of layouts, minimized, period, Date Range | covered | SD §7.2 dashboards/dashboard_items | — |
| FR-57 Reset to Template's current layout | covered | AD-14 provenance; SD §4.2 `ResetToTemplate` | Clarify: resets to Template's **current** version (not stored `template_version_id`), removes user-added Blocks?, confirm dialog; update provenance after reset |
| FR-58 Responsive: desktop/tablet/mobile, mobile reorder, panel sheet | covered | AD-14 (`compact_order`) | — |
| FR-59 Keyboard alternatives ("Move up", "Make wider") | covered | SD §19 Grid (keyboard layer ours) | — |
| FR-59 Keyboard-shortcuts toggle | covered | SD §7.2 `users` shortcut preference | — |
| FR-59 Action toasts ≥10 s; **Ctrl/⌘+Z undoes last add/remove, no time limit** | partial | SD §4.3 "undo history for the dashboard visit, client-side only" | (a) "for the dashboard visit" is a time limit the PRD doesn't set — state it as the interpretation or list it; (b) undo-of-remove must restore the **original position, size, minimized and period selection** — the current `AddBlock` re-runs first-free placement and the deleted `dashboard_item` loses state. Add `RestoreRemovedItem` (re-insert snapshot at original slot if free) or soft-delete items |
| FR-60 Refresh on interval, Live gated by source `live_capable`, manual refresh rate-limited | covered | AD-7; SD §6.1, §6.4 | — |
| FR-60 **Pause live updates** ("Paused · data as of <time>"; user-initiated refreshes still run) | partial | SD §23 epic 8 mentions "Pause" only | Specify: client-side flag per dashboard that ignores `result.updated` and stops polling; user actions still call results API; whether paused viewers still touch `last_access_at` (keep hot or let cold); whether pause is persisted (FR-56 lists no pause → session-only) |
| FR-61 Stale at 2× interval; Reconnecting banner; no mixing | covered | AD-15; SD §6.4, §18 | See FR-62 data_as_of note — stale is computed from `data_as_of`, so an API that legitimately returns 304 or carries a mapped "report date" goes permanently Stale. Confirm with PO whether Stale is based on `data_as_of` or `last_success_at`; list in §0 |
| FR-62 Data-as-of in About + inline when stale | covered | AD-8, AD-15 | — |
| FR-62 Data-as-of = **mapped timestamp Field** if one exists, else fetch time | partial | SD §7.2 stores `data_as_of` on `sync_targets` / `raw_payloads` (fetch-level) | The mapped timestamp is Block-Version-specific (two Blocks on one Fetch Key may map different timestamp fields). Compute `data_as_of` in the Shaper/result, falling back to the sync's fetch time; add a "Data-as-of field" slot/config |
| FR-62 **Dashboard header shows oldest Data-as-of of the Live Blocks** | missing | — | Batch results response returns `dashboard.oldest_data_as_of` over items with interval = Live (or compute client-side from per-item `data_as_of`); state it in AD-15 / SD §5.2 |
| FR-63 Global search scope (dashboards, Blocks, Templates; Admin: data sources, users, settings) within workspace + permissions | partial | SD §4.2 Search `Search(query, membership)`; §19 PG FTS; §11.2 same predicate | Enumerate indexed document types; users index must be per-membership (global `users` table is outside RLS); "settings" = static nav entries filtered by permission; Admin-only types gated by area=Admin + permission |
| FR-64 In-app notifications (user: new Blocks, version updated, unpublished; admin: others' publications, health, mapping failures); email deferred | partial | AD-16, AD-17; SD §6.5, §6.6, §14 | Add a notification-type → audience table (e.g. `block.version_published` → memberships with an instance; "new Block available" → members passing AccessEvaluator; others' publications → admins except actor), and fan-out cost rule for large workspaces |
| FR-65 Admin overview metrics (published blocks Δ month, active dashboards Δ week, template adoption %, active users + weekly share, recent activity) | partial | SD §13 "aggregate counts", §22 metric windows TBD | Data for "active" is missing: add `memberships.last_active_at` and `dashboards.last_viewed_at` (throttled touch, like `last_access_at`); recent activity = audit query; deltas from `published_at`/audit; keep windows TBD |
| FR-66 Platform health (4 services + workspace sources, Operational badge) | covered | AD-1, AD-24; SD §4.1, §6.5, §15 | — |
| FR-67 Settings: allowlist, retention, intervals, limits, locale/tz/currency, theme default, default Template, context attributes | partial | SD §7.2 `workspace_settings`, `host_allowlist_entries` | Theme default absent (fine for MVP, add placeholder); clarify that Workspace Admins edit **public** allowlist entries and only the operator grants private ranges (`host_allowlist_entries.granted_by_operator` is ambiguous) |
| FR-67 **Log** retention configurable per Workspace | contradicts [unlisted] | SD §7.3 "Application logs: Deployment setting (outside the DB)" | Either add workspace-scoped retention for workspace-attributable logs (sync_runs, operations, notifications already are) and state that stdout/OTLP logs are governed by the deployment's log store, or list as a conflict in §0 and amend FR-67/NFR-13 |
| FR-68 Audit events, before/after, immutable, search + export, retention | covered | AD-18; SD §15, C7 | — |

## 2. Non-functional requirements

| Item | Status | Where | Suggested fix |
|---|---|---|---|
| NFR-1 Freshness ≈30 s where supported; target TBD | covered | AD-7, AD-15; SD §6.1, §22 | Note: demand-driven scheduling means a cold Block's first view shows `pending`/last-known-good before fresh data — say so for SM-6 |
| NFR-2 Performance TBD; validated from customer | covered | SD §13 (load-test harness), §17 | — |
| NFR-3 Scale TBD; horizontal scaling | covered | AD-1, AD-17; SD §17 | — |
| NFR-4 Security baseline | covered | AD-3, AD-6, AD-18, AD-19; SD §10.2 | — |
| NFR-4 Encryption **in transit (TLS)** | contradicts [unlisted] | SD §10.2 "http only by explicit Data Source flag on allowlisted private hosts" | Either remove plain-HTTP egress or list it in §0 as a PRD amendment (operator-only, audited) |
| NFR-5 No historical copies; short-lived caches only | contradicts (listed C1, C5) | AD-9; SD §0 C1, C5 | Awaiting PO |
| NFR-6 Availability TBD; health visible | covered | SD §16.2, §18 | — |
| NFR-7 WCAG 2.1 AA; keyboard layout; colour never only signal | partial | SD §9.2 scoring, §16.2 axe in CI, §19 keyboard layer | No AD binds accessibility. Extend AD-21: every renderer must provide View-as-table, aria labels, non-colour signal, focus stability on in-place update; CI axe gate on renderers |
| NFR-8 Browsers: latest two Chrome, Edge, Firefox, Safari, desktop + mobile | missing | — | Add Vite build target / browserslist and Playwright projects (Chromium, Firefox, WebKit, mobile Safari/Chrome); include touch-drag of the grid lib on iOS Safari in the grid spike acceptance |
| NFR-9 Devices | covered | AD-14 derived views | — |
| NFR-10 Light only; semantic tokens; brand override later; system colours fixed | covered | spine Deferred; SD §4.3 `components/ui`, §23 | Add: ECharts theme reads the same CSS-variable tokens (else charts break dark-readiness) |
| NFR-11 Locale-aware formatting; Workspace defaults | partial | spine Conventions (Intl in renderers); SD §7.2 locale/tz/currency | Resolve server-shaped text (Text Templates, comparison labels) vs Intl-only formatting (see FR-26); state precedence user locale/tz > Workspace default |
| NFR-12 Extensibility, no domain logic | covered | AD-2, AD-21; SD §6.9 SourceAdapter | — |
| NFR-13 Observability; log retention configurable (FR-67) | partial | AD-24; SD §15 | See FR-67 log-retention contradiction |
| NFR-14 Deployment-agnostic; future agent | covered | AD-6, AD-22; SD §16 | — |

## 3. PRD §7 Data Governance, §9 Non-goals

| Item | Status | Where | Suggested fix |
|---|---|---|---|
| §7 Blocks/Templates versioned, permission-controlled, audited | covered | AD-13, AD-18 | — |
| §7 Owner, data source, endpoint, Refresh Interval visible to users in "About this block" | partial | SD §7.2 `blocks.owner` | Define the `BlockAbout` DTO; decide whether the endpoint **path** is shown (resolved params can carry user-context values; show path template only) |
| §7 Source APIs are system of record; never written | covered | AD-6 | — |
| §7 No history in MVP | contradicts (listed C1) | AD-9 | Awaiting PO |
| §9 Ad-hoc BI | covered | AD-23 | — |
| §9 Domain logic in core | covered | AD-2 | AD-2 bans domain vocabulary in **seeds**; PRD §10 allows an optional demo sample Workspace — state it ships as an operator-imported fixture, not a core seed |
| §9 User scripts / JS / HTML | covered | AD-12, AD-21 | — |
| §9 Writing to source APIs | covered | AD-6 | — |
| §9 Non-API sources | covered | SD §6.9, §23 | — |
| §9 Offline / PGlite | covered | SD §19 Rejected | — |
| §9 Physical table per Block | covered | SD §7.2 Rejected | — |
| §9 Drill-down, multi-Endpoint Blocks | covered | SD C3, spine Deferred | — |
| §9 Native app, SSO, email notifications, exports, scheduled reports, threshold alerts, kiosk, public links, snapshots, export/import | covered | spine Deferred; SD §23; C7 (audit export kept per PRD) | — |

## 4. Addendum §A-§L

| Item | Status | Where | Suggested fix |
|---|---|---|---|
| §A Configuration model, JSONB versioned config validated by slot schema, relational keyed by workspace, instances by reference, rejections | covered | AD-3, AD-9, AD-14, AD-21; SD §7.2; C2 adds Dataset | — |
| §B Transform engine pipeline, confined paths, AST, ratio, guards (incl. CPU), aggregation-location ADR | covered | AD-11, AD-12; SD §8 | — |
| §C Connector: server-side, SSRF incl. post-redirect DNS, egress path, auth types, pagination, change detection, rate limits/429 | covered | AD-6, AD-8; SD §6 | — |
| §C Cache key includes **Refresh Interval window**; cache lifetime configurable **per Data Source** (shorter for sensitive data) | partial | AD-7 Fetch Key excludes interval; SD §13 Block Results TTL ≥ hot window | Add a per-Data-Source `result_ttl` / sensitivity cap on Valkey Block Results and raw `latest` retention; note interval is handled by scheduling, not the key |
| §D Refresh mechanism; reconnection and **missed updates** | partial | AD-16; SD §18 Reverb outage | State: on socket reconnect (or polling resume) the client refetches the whole batch results endpoint with `If-None-Match`, since invalidations sent while disconnected are lost |
| §E History deferred | covered (with C1) | AD-9; SD §23 | — |
| §F One shared grid library across Template editor, wizard preview, user dashboards; no GrapesJS | covered | SD §19 Grid, Rejected | — |
| §G Block Type contract (metadata, config schema, data shape, size limits, renderer, state handlers) | covered | AD-21 | — |
| §H Browser keeps last result in memory; no offline | covered | SD §4.3 | — |
| §I Candidate stack | covered (React→Vue listed C4) | SD §0 C4, §19 | — |
| §I Candidate modules | covered | SD §4.2 (Lifecycle merged into Blocks) | — |
| §J Build vs embed scored, counter-argument, change conditions | covered | AD-23; SD §9 | — |
| §K Domain examples as Blocks/Templates; export/import post-MVP | covered | AD-2; SD §23 | — |
| §L Traceability | covered | spine Capability map | — |

## 5. Semantic changes not listed in SOLUTION-DESIGN §0

1. **Access changes require `blocks.publish`** (SD §11.3; only §22 Q-A1). PRD FR-6/FR-7 don't say so.
2. **Plain-HTTP egress** to private hosts (SD §10.2) vs NFR-4 TLS in transit.
3. **Log retention is a deployment setting**, not a Workspace setting (SD §7.3) vs FR-67/NFR-13.
4. **Endpoint revisions pinned per Block Version** (SD §12; only §22 Q-A2): editing an Endpoint no longer affects published Blocks until republished — PRD FR-39 treats endpoint as part of the version diff, so compatible, but users of the Endpoint get no propagation; list it.
5. **Major version reserved for Block-Type change** (SD §12) — new numbering rule; PRD only shows 1.0 → 1.1.
6. **Undo history limited to the dashboard visit** (SD §4.3) vs FR-59 "no time limit".
7. **Demand-driven (hot/cold) scheduling** (AD-7): PRD FR-60 says each Block re-fetches on its interval; cold targets stop syncing. Semantically benign for viewers, but mapping-failure alerts (FR-30) and health for un-viewed Blocks now depend on the probe interval — worth one §0 line.
8. **Stale/data_as_of semantics** (SD §6.4, §7.2) — fetch-level `data_as_of` instead of mapped-timestamp per Block; Stale from `data_as_of` makes unchanged-but-healthy sources Stale.

## 6. Counts (table rows)

- covered: 90
- partial: 28
- missing: 4 (FR-4 help links, FR-43 chips rule, FR-62 header oldest data-as-of, NFR-8 browsers)
- contradicts: 6 rows. 3 are listed in §0 (FR-21 = C5, NFR-5 = C1, §7 history = C1). 3 are not (FR-7 access permission, FR-67 log retention, NFR-4 plain-HTTP egress). §5 items 4-8 are further unlisted semantic shifts recorded inside partial rows.
