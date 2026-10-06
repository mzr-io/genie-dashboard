---
id: SPEC-rmg-dashboard-platform
companions:
  - ../../planning-artifacts/prds/prd-rmg-dashboard-platform-2026-10-02/prd.md
  - ../../planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/DESIGN.md
  - ../../planning-artifacts/ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md
  - ../../planning-artifacts/architecture/architecture-rmg-dashboard-platform-2026-10-05/ARCHITECTURE-SPINE.md
  - ../../planning-artifacts/architecture/architecture-rmg-dashboard-platform-2026-10-05/SOLUTION-DESIGN.md
  - traceability.md
sources:
  - ../../planning-artifacts/prds/prd-rmg-dashboard-platform-2026-10-02/addendum.md
  - ../../planning-artifacts/briefs/brief-rmg-dashboard-platform-2026-10-02/brief.md
---

> **Canonical contract.** This SPEC and the files in `companions:` are the complete, preservation-validated contract for what to build, test, and validate. Source documents listed in frontmatter are for traceability — consult them only if you need narrative rationale or prose color this contract intentionally omits.

# Dashflow: Dynamic Dashboard Platform

## Why

**A pain to solve and a platform to realize.** Organizations in any domain already expose data through REST APIs but lack a fast, safe way to turn those APIs into dashboards each person can shape. Today every new dashboard is a development ticket. Admins and end users of any business domain (RMG, finance, healthcare, retail and others) are affected. Dashflow lets an Admin connect an API, map its response to a block, validate it against the real API and publish it. Users then assemble personal dashboards from the published blocks. It is a dashboard-centric platform with governed BI capabilities, not an ad-hoc BI tool.

## Capabilities

Requirement detail is the PRD's FR/NFR text (adopted companion). `traceability.md` maps each capability to its FRs, architecture decisions and epics.

- **CAP-1**
  - **intent:** A person can sign in as User or Admin, belong to several isolated Workspaces, and switch between them with per-Workspace roles.
  - **success:** A person who is Admin in Workspace A and User in B sees only each Workspace's own objects, and an Admin-role request without Admin membership is refused.
- **CAP-2**
  - **intent:** Admins can control who may use each Block and Template by user group, with changes taking effect immediately and being audited.
  - **success:** After a grant is removed, the affected user's next request returns the "no longer available" placeholder with Remove and no data, no new version is created, and an audit event exists.
- **CAP-3**
  - **intent:** Admins can bind Endpoint parameters or headers to the viewer's attributes so the source API returns only that user's data.
  - **success:** A user with a missing bound attribute gets no fetch and an Unavailable item, and two users with different attribute values never share a result.
- **CAP-4**
  - **intent:** Admins can register REST/JSON Data Sources and Endpoints that the platform calls safely server-side, with health, limits and conditional requests.
  - **success:** Calls to loopback, link-local, metadata or non-allowlisted hosts are blocked and audited; an API without `ETag` or `Last-Modified` is still change-detected by content hash; credentials are never returned after saving.
- **CAP-5**
  - **intent:** Admins can build a Block in a five-step wizard (Configure, Data Source, Map Data, Preview, Save/Publish) with autosave and safe concurrent editing.
  - **success:** Publish is enabled only after validation against the real API passes for the current Draft revision and the Admin holds the publish permission; a second editor sees the soft lock and Take over.
- **CAP-6**
  - **intent:** Admins can map an API response to Block slots with Auto-map, Review, Override and Preview, using the six field roles, governed transforms and presentation rules.
  - **success:** The FR-31 example renders "$284,680" and "↗ 14.2% from last year"; a renamed API field shows "Unavailable", never zero; ratios aggregate as ratio of sums (600/1,000 and 450/500 give 70.0%).
- **CAP-7**
  - **intent:** The platform provides the 11 MVP Block Types, and developers can add a Block Type without changing the wizard, mapper or dashboards.
  - **success:** A new Block Type package passes the shared slot-schema and renderer conformance suites with no change outside its package.
- **CAP-8**
  - **intent:** Every Block shows its chrome, controls and all states (loading, empty, error, Stale, Unavailable, minimized) and renders API values as escaped text.
  - **success:** Hostile strings in every slot never produce injected elements, and every chart offers a "View as table" equal to its displayed data.
- **CAP-9**
  - **intent:** Admins can publish immutable versions with an impact view, restore old versions without silently overwriting a Draft, and manage categories and Blocks.
  - **success:** Publishing v1.1 shows user and Template impact, changes no user's layout, and Restore with an existing Draft asks "Replace your current draft?".
- **CAP-10**
  - **intent:** Admins can publish Dashboard Templates with Mandatory Blocks and choose the template that seeds new users.
  - **success:** Users cannot remove a Mandatory Block; a newly mandatory Block is appended to existing derived dashboards without moving other items.
- **CAP-11**
  - **intent:** Users can add, remove, move and resize Blocks on personal dashboards, with automatic placement, persistence and keyboard-accessible editing.
  - **success:** Add places the Block at the first free slot without moving others; remove offers Undo and Ctrl/⌘+Z restores the old position; layouts persist across devices.
- **CAP-12**
  - **intent:** Users see data that refreshes per Block without ever depending on a live source API call, with clear freshness.
  - **success:** With the source API down, a dashboard still opens, shows last values greyed with "Stale: last data <time>", and recovers automatically; Stale means no successful check within 2× the interval.
- **CAP-13**
  - **intent:** Users can search their dashboards, Blocks and Templates (Admins also sources, users and settings) and receive in-app notifications.
  - **success:** Search results and notifications never expose objects the user cannot access.
- **CAP-14**
  - **intent:** Admins can see Workspace activity and platform and data-source health.
  - **success:** The overview shows the four metric cards, recent activity and the four platform services with source health.
- **CAP-15**
  - **intent:** Admins can configure Workspace settings and retention, and search and export an immutable audit log.
  - **success:** Every FR-68 event is recorded with before/after state in the same transaction as the change; the CSV export neutralizes formula-leading cells.
- **CAP-16**
  - **intent:** Operators can deploy the same image as SaaS, customer-hosted or dedicated, and scale it horizontally.
  - **success:** The same image and migrations run in each model with environment-only configuration, and web and worker roles scale independently.

## Constraints

- Architecture decisions AD-1 to AD-34 bind all work: a modular monolith run as five process roles, Laravel 13 / PHP 8.5, Vue 3.5, PostgreSQL 18, Valkey, Reverb, and a governed BI layer built in-house with no embedded BI engine.
- Workspaces are isolated in the application and by PostgreSQL row-level security that fails closed; no domain logic or vocabulary enters the core.
- Source APIs are REST/JSON only, are never written to, and are never called on the dashboard request path.
- No capacity, latency, availability, retention or timeout number may be invented; each is a `pending_input` setting.
- Accepted MVP risks: no MFA, and plain `http` to source APIs allowed (badged, audited, `require_https` switch).
- Retention: the latest source response is kept as a last-known-good copy, longer retention is opt-in per Data Source, and a pasted sample lives only inside its Draft.
- Access changes need the `access.manage` permission; add-block previews use generated sample values; app-level payload encryption applies only to sources classified regulated.
- Compliance regimes are not assumed; they are confirmed per customer.

## Non-goals

- General-purpose or ad-hoc BI, drill-down between Blocks, and Blocks joining several Endpoints.
- Domain-specific logic in the core, user scripts, and writing to source APIs.
- Non-API sources (databases, files, streams) and offline operation.
- SSO, MFA, email notifications, scheduled reports, KPI alerts, TV/kiosk mode, public links or embedding, and a native mobile app.
- Platform-side history and trends, export/import between Workspaces, a maker-checker approval workflow, and a dark theme.

## Success signal

- An Admin publishes a new Block over an existing REST API with no code change, and users see it in the Add-blocks Panel; every displayed number traces to a published, versioned Block definition. Numeric targets for SM-1 to SM-6 are TBD (PRD §11).

## Assumptions

- PRD v3.3 FR/NFR IDs are the stable requirement IDs; CAP-n groups them (`traceability.md`).
- The PRD addendum v3 and brief v4 are fully absorbed (their options were resolved by the architecture run).

## Open Questions

- Should MVP packaging ship Docker Compose plus Helm (current spine and epic 11) or Compose first with Helm later?
- What are the first customer's API inventory, sizing, availability (RPO/RTO), compliance regimes, residency, retention defaults and deployment model (PRD §12)?
- What are the session idle timeout, the Remember-me duration, and the targets for SM-1 to SM-6?
