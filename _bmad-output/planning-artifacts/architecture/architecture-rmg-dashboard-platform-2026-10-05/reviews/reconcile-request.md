---
title: "Reconciliation: architecture vs product-owner architecture request (2026-10-05)"
reviewed: [ARCHITECTURE-SPINE.md, SOLUTION-DESIGN.md (v1.0)]
against: [inputs/architecture-request-2026-10-05.md, ../../briefs/brief-rmg-dashboard-platform-2026-10-02/brief.md (v4.1), PRD v3.2.1 (spot-checked for sourcing)]
date: 2026-10-05
---

# Reconciliation against the architecture request

**Verdict.** Coverage is strong: all 23 output sections exist with the requested titles, every ingestion item, storage item, security item, deployment distinction, sizing parameter and concern has a home, and the BI evaluation reaches a clear recommendation. What remains: some labelling and sourcing discipline problems, a few invented defaults, 7 technical defects in the data model or mechanics, and about 18 places where a story writer would still have to make an architecture decision.

**Gap counts**

| Category | Count |
|---|---|
| Coverage gaps against explicit asks (G) | 13 |
| Technical defects or under-modelled mechanics (T) | 7 |
| Open architecture decisions a story writer would face (S) | 18 |
| **Total** | **38** |

Severity: High = blocks stories or is a correctness error. Med = will cause rework. Low = polish.

---

## 1. Data source / ingestion (17 asks)

| Ask | Where | Status |
|---|---|---|
| API authentication | SD §6.1 | Covered. Adds `none`, which is not in the FR-9 list; the addition is unflagged (see G13) |
| Headers and parameters | §6.1 | Covered |
| Refresh intervals | §6.1, AD-7 | Covered |
| ETag / If-None-Match | AD-8, §6.3 | Covered |
| Last-Modified fallback | AD-8, §6.3 | Covered |
| Content-hash fallback | AD-8, §6.3, C8 | Covered. Pagination interaction undefined (S8) |
| API failure handling | §6.4, §18 | Covered |
| Rate limiting | §6.4 | Covered. Who configures it (Admin form, operator or platform setting) is undefined (S15) |
| Retries / backoff | §6.4 | Covered. **`max_attempts (default 3)` is invented** (G8) |
| Timeout handling | §6.1 | Covered (defaults TBD) |
| Source health | §6.5 | Covered |
| Schema / response validation | §6.6 | Covered |
| Sample JSON vs production response | §6.7, C5 | Covered |
| Raw API data preservation | AD-9, C1 | Covered, pending C1 |
| External IDs vs internal IDs | AD-10, §6.8 | Covered |
| Mapping config separate from raw data | AD-9, §7.2 | Covered |
| Historical / snapshot handling | C1, §23 | Covered (deferred, plus opt-in window) |
| "Do not assume every API supports ETag" | AD-8 | Honoured |

## 2. Data storage (15 asks)

All 15 have a row in SD §7.2: tenants, users/groups/roles, dashboards, blocks, block versions, access policies, data sources, sync metadata, raw payloads, mapping configs, datasets/fields, dimensions/measures, governed KPI definitions and audit. The PostgreSQL evaluation is in §7.1, and SQLite is excluded in production.

The storage rows have these weaknesses:
- **Templates.** `templates` and `template_versions` have no column-level model: there is no layout schema, no mandatory flags, and the Template-to-Block join is unnamed. See S10.
- **Supporting tables are unmodelled.** `operations`, `validation_reports`, `outbox_events` and `notifications` have no columns.
- **Several required structures are missing** (T1–T7): the sync-target-to-Block-Version subscription link, endpoint revision history and per-workspace DEK storage.

## 3. BI evaluation (criteria scored?)

The request lists 22 discrete terms; the caller counts them as 21 when "grouping, aggregation" is read as one. SD §9.2 has 22 rows: 21 from the request plus an extra "Deployment-agnostic" row. The arithmetic was re-checked: Build 92, Embed 64, Hybrid 85, all correct.

| Criterion | Scored | Rationale | Note |
|---|---|---|---|
| Dynamic KPI creation | Yes | Yes | |
| Datasets | Yes | Yes | |
| Dimensions / measures | Yes | Yes | |
| Filtering | Yes | Yes | |
| Grouping | **Merged** | Yes | Grouping and aggregation share one row (G1) |
| Aggregation | **Merged** | Yes | Same row as grouping (G1) |
| Drill-down | Yes | Thin ("MVP non-goal") | Rationale doesn't explain why Embed scores 5 |
| Correct roll-ups | Yes | Yes | |
| Trend / history | Yes | Yes | |
| Visualization flexibility | Yes | Yes | |
| Dashboard customization | Yes | Yes | |
| Near-real-time | Yes | Yes | |
| Governance | Yes | Yes | |
| Tenant isolation | Yes | Yes | |
| Row / field-level security | Yes | Partial | Field-level security is not discussed at all; only row scope (via the API) is |
| Performance | Yes | Yes | |
| Scalability | Yes | Yes | |
| UX consistency | Yes | Yes | |
| Implementation complexity | Yes | Yes | |
| Licensing / cost | Yes | Yes | |
| Vendor lock-in | Yes | **No** ("—") | G2 |
| Extensibility | Yes | Yes | |

The recommendation is clear (AD-23: Build), with a counter-argument and conditions that would reverse it. The "do not turn MVP into ad-hoc BI" ask is honoured.

Problems:
- **G3.** The text says "weighted by the PRD's priorities, Build leads by a wider margin", but no weights are shown.
- Field-level security has no mechanism. For example: can a Block hide a field from some groups? The implicit answer is that each Block is the field-level boundary. That should be stated.

## 4. Security / governance (14 asks)

| Ask | Where | Status |
|---|---|---|
| Multi-tenant isolation | AD-3, §10.1 | Covered |
| RBAC | AD-4, §11 | Covered. The permission-key enum is unlabelled; it should be [PROPOSED] |
| Group-based block access | AD-5, §11 | Covered |
| Access changes immediate | AD-4, §11.2 | Covered. **The FR-7 impact confirmation ("38 users will lose this block") is not designed** (S12) |
| Audit logging | AD-18, §15 | Covered |
| Encryption in transit | §10.2 | Covered. "TLS 1.2+" is unlabelled (G11) |
| Encryption at rest | §10.2 | Covered. App-level payload encryption is OPEN (Q-A3) |
| Credential / secret management | AD-19 | Covered. DEK storage is unmodelled (T7) |
| Least privilege | §10.2 | Covered |
| Admin vs end-user permissions | AD-4, §11 | Covered. User↔Admin area switching within a session is undefined (S17) |
| Publish permission | §10.2, §11 | Covered |
| Versioning | AD-13, §12 | Covered |
| Data retention | §7.3 | Covered |
| Compliance readiness, no invented regimes, TBDs listed | §10.3 | Covered |

## 5. Deployment distinctions (3 asks plus "don't lock")

| Ask | Where | Status |
|---|---|---|
| Where the platform is deployed | §16.1 Q1 | Covered (SaaS, customer-hosted, dedicated) |
| Where APIs and data reside | §16.1 Q2 | Covered |
| How private-network APIs are reached | §16.1 Q3, §6.9 | Covered (direct allowlist, peering/VPN, tunnel, future agent) |
| Not locked | §16.1 | Honoured [CONFIRMED NFR-14] |

## 6. Sizing parameters (12 asks)

All 12 are in the §17 table: tenants, users, concurrent users, dashboards/user, blocks/dashboard, API sources, refresh frequency, response size, ingestion volume, requests/s, query complexity and availability. Each has an "architectural implication". The fan-out insight, that user-context bindings multiply Fetch Keys, is valuable.

**G10:** two of the implications state unsourced capacity claims: "Shared schema holds well into thousands" and "Negligible for PG at any plausible count". Reword them as [ASSUMED] with a validation step.

## 7. Conceptual-flow evaluation

The flow is evaluated in SD §1 and accepted with three refinements. Each stage maps to a module (§4.4).

| Asked | Where used | Status |
|---|---|---|
| Redis / cache | §13 (Block Results, locks, rate buckets, OAuth tokens, queues, Reverb fan-out); AD-17 | Covered. **Eviction policy and topology are undefined** (T6) |
| Background workers / queues | §14 (six queues, two worker roles, dispatcher) | Covered |
| Materialized / aggregated data | Block Results (Valkey, recomputable); admin-overview matview "if needed" | Covered. The pre-top-N row set for the expanded view has no defined home (S6) |

## 8. The 22 concerns

All 22 are addressed. Mapping: 1 ingestion §6 · 2 raw vs transformed AD-9 · 3 semantic model §7.2/§8/C2 · 4 mapping engine AD-11/12 · 5 query engine §8 · 6 KPI/metric C2/§8 · 7 dashboard/block config AD-14/§7.2 · 8 versioning §12 · 9 access evaluation §11 · 10 multi-tenancy §10.1 · 11 secrets AD-19 · 12 caching §13 · 13 background jobs §14 · 14 failure/retry §6.4/§18 · 15 observability §15 · 16 audit AD-18/§15 · 17 security §10.2 · 18 performance §13 · 19 scalability §17 · 20 deployment §16 · 21 BI §9 · 22 future connectors §6.9.

Thin areas:
- **Concern 4.** The AutoMapper (auto-map step) has no specified algorithm (S16).
- **Concern 6.** "Metric engine" is satisfied only by C2's redefinition. That is acceptable, but the design depends on C2 being accepted.

## 9. The 23 output sections

All 23 are present, in order, with matching titles (SD §1–§23). Conflicts are in §0.

## 10. Principles

| Request principle | Realized | Status |
|---|---|---|
| Core domain-agnostic | P1, AD-2 | Covered. A grep found no RMG entities in the architecture |
| No RMG-specific entities/logic | AD-2 | Covered |
| Separate config from source data | P2 | Covered |
| Preserve raw data where practical | P3 | Covered |
| Mapping changeable without destroying source data | P4 | Covered. Undercut by shared mutable `dataset_fields` (T5) |
| Access evaluated at runtime | P5 | Covered |
| Content versioning separate from access policy | P6 | Covered |
| API availability decoupled from requests | P7 | Covered |
| Prefer async sync | P8 | Covered |
| Horizontal scaling | P9 | Covered |
| Avoid unnecessary microservices; boundaries from responsibilities/scaling/security | P10 | Covered |
| **MVP implementable, no over-engineering** | — | **Missing (G5)**. No principle and no explicit MVP-minimization pass. The MVP carries several items that could be trimmed or explicitly phased: a Helm chart plus Compose, three SecretVault drivers, an IdentityProvider port, an optional hash chain, dual tenancy enforcement, OTel across five roles, and a standby scheduler. Each should be justified as MVP, or marked "seam only, driver later" |

## 11. Technology validation

| Tech | Validated? | Notes |
|---|---|---|
| Laravel | Yes (§19, with gaps listed: SSRF, KMS, CPU) | Good |
| Vue 3 | Yes (§19, C4) | Good |
| PostgreSQL | Yes (§7.1 dedicated evaluation) | Good |
| **Docker** | **Not explicitly** | §19 has no row. Packaging is described in §16.2 and in the spine Stack ("OCI images; Docker Compose; Helm"), but there is no validation or justification (G4) |

Components added beyond the four:
- **Justified in §19:** Valkey, Horizon, Reverb, ECharts, grid-layout-plus, symfony/json-path, opis/json-schema, bcmath, OTel, SecretVault/OpenBao, PG FTS, php-fpm/nginx.
- **Added without justification:**
  - Inertia 3, which drives the dual surface in AD-20;
  - Sanctum;
  - Kubernetes/Helm;
  - **PgBouncer**: "compatible", but whether it is used is undecided.

## 12. Labelling (CONFIRMED / ASSUMED / PROPOSED / OPEN / ADR)

- **G6, vocabulary drift.** The spine uses **[ADOPTED]**, which is not in the requested set. SD §20 also uses ADOPTED, while SD's own legend defines CONFIRMED. Pick one; the request asks for CONFIRMED.
- **G7, coverage.** Many decisions carry no label:
  - §4 (all of it);
  - most of §6.2–§6.5;
  - §13, §14, §17 and §18 (none);
  - the §7.2 permission enum;
  - the §6.4 retry policy;
  - the §10.2 TLS floor.

  Spine ADs carry no inline status; status appears only in SD §20. [ASSUMED] is used once (C10), although the documents rely on several assumptions: "per-Block payloads are bounded" (§19), "PG scales vertically first", tenant-count capacity, and "first-free-slot after the last row" being acceptable UX.
- C3 is labelled [CONFIRMED] even though it is a conflict resolution. The PRD non-goal is confirmed, but scoring drill-down in the BI evaluation is the architect's proposal.

## 13. Invented numbers ("don't guess TBDs")

| # | Value | Location | Sourced? | Fix |
|---|---|---|---|---|
| G8 | `max_attempts (default 3)` | SD §6.4 | **No.** It also contradicts §22, which lists "max attempts" as a TBD tunable | Make it TBD, or label it [PROPOSED] with the rationale |
| G9 | Health sampling "every minute" | SD §15 | No | Name it as a tunable (`health_sample_interval`, TBD) |
| G10 | "holds well into thousands" (tenants); "negligible at any plausible count" | SD §17 | No | Rephrase as [ASSUMED] with a load-test validation |
| G11a | "TLS 1.2+" | SD §10.2 | No (industry baseline, unlabelled) | Label it [PROPOSED] |
| G11b | Hot window "about 2 × the effective interval with a floor" | SD §6.2 | Partly. It is labelled TBD but suggests a value | Keep it as [PROPOSED] or drop the suggestion |
| G11c | Dispatcher "every few seconds" | SD §14 | Vague, and not a named tunable | Add it to the tunables list |
| — | OAuth "expires_in − skew" | SD §6.1 | Skew is unnamed | Add it to the tunables |

Values checked and found **sourced** (no action):
- Live ~30 s (FR-60, NFR-1);
- Stale at 2× the interval (FR-61);
- soft lock about 15 min (FR-19);
- session warning 2 min (PRD R5);
- toast ≥ 10 s (FR-59);
- SUM/COUNT/AVG/MIN/MAX and the function list (FR-24, FR-25);
- 11 Block Types (FR-32);
- versions 1.0/1.1 (mockup).

The BI scores are analysis, not decisions. They are acceptable as long as G3 is fixed.

## 14. Conflicts call-out

SD §0 lists C1–C11, which satisfies the ask. Defects:
- **G12, ambiguous source naming.** "Brief" is used for both the **architecture request** (C2, C3, C8; C1 and C4 say "architecture brief") and the **product brief v4** (C7, C11). "Brief §88" in C11 is a line number, not a section; the text is in brief §7 Scope. Rename every reference to "Architecture request (2026-10-05)" or "Product brief v4 §n".
- **G13, conflicts not raised:**
  1. **`none` auth type.** It is added beyond the FR-9 list (API key, bearer, OAuth2 CC, basic) without being flagged.
  2. **UX mockups not cited.** They are a named source of truth in the request but are missing from both documents' `sources`.
  3. **FR-7 access-change impact confirmation.** The PRD and UX require it, and the architecture has no mechanism for it. It is designed only for publish.
  4. **FR-39 "number of users" vs the implementation.** SD §12 computes `COUNT(dashboard_items WHERE block_id)`, which counts dashboards, not distinct users. A user with the Block on two dashboards is double-counted.
  5. **Mandatory vs access-removed.** A Mandatory Block (cannot be removed) whose access is revoked for the user (must show a placeholder with **Remove**) is an unresolved PRD/UX interaction.
  6. **NFR-5 "short-lived caches only" vs durable data.** The architecture durably stores encrypted user-context values on `sync_targets` and OAuth tokens. These are small, but should be folded into the C1 amendment wording.

## 15. Technical defects (T)

| # | Sev | Defect |
|---|---|---|
| T1 | High | `raw_payloads` is "partitioned by month" with "Unique `(sync_target_id, content_hash)`". PostgreSQL requires every unique constraint on a partitioned table to include the partition key, so this constraint cannot be created as written. In `latest` mode (the default), monthly partition drops also do not implement retention; the superseded row must be deleted. Decide: either no partitioning for `latest`, or a unique key that includes `fetched_at` plus an app-level dedupe |
| T2 | Med | The index `sync_targets(next_due_at) WHERE hot` and the dispatcher's `AND hot` assume a column. Hotness is time-relative (`now − last_access_at < hot_window`), and a partial-index predicate cannot use `now()`. Either store `hot_until` and query `hot_until > now()`, or maintain a flag with a sweeper |
| T3 | **High** | **No subscription model** links `sync_targets` to Block Versions. Several mechanisms depend on one: the effective interval ("min interval among hot subscribed Block Versions"), compute fan-out ("for each subscribed Block Version"), pre-compute on publish ("hot Fetch Keys" of a version), and drift checks. Add a `sync_target_subscriptions(sync_target_id, block_version_id, refresh_interval, last_access_at)` table, or equivalent, plus its lifecycle (created on the read path, pruned when cold) |
| T4 | High | Pinning `endpoint_revision` per Block Version (§12, the Q-A2 default) needs **historical endpoint definitions**, but `endpoints` has only a `revision` counter and `block_versions.config` stores `endpoint_id`, not the revision. Add `endpoint_revisions` (immutable) and pin `endpoint_revision_id` in the config |
| T5 | Med | `dataset_fields` (shared across Blocks) holds `override type` and `default_role`. Editing them changes the inputs of **immutable published** Block Versions that reuse the Dataset, which breaks AD-9 reproducibility and P4. Either snapshot field types into `block_versions.config`, or make Dataset field edits draft-only and copy-on-publish |
| T6 | Med | Valkey holds both **evictable** results (TTL'd, potentially large) and **non-evictable** state (queues, locks, rate buckets, Reverb). Neither the topology (one instance or split instances) nor the `maxmemory-policy` is defined. Under memory pressure, `allkeys-lru` drops queued jobs and locks, and `noeviction` fails result writes. Decide on split logical stores, or `volatile-*` with TTL on results only |
| T7 | Low | The per-workspace DEK (AD-19) has no storage table: no `workspace_keys` with wrapped DEK, key version and rotation timestamp |

## 16. "Detailed enough to derive stories without redesign": open architecture decisions (S)

A story writer would still have to decide each of these:

| # | Area | Decision still needed |
|---|---|---|
| S1 | Sync generations (AD-15, FR-61) | "A result is built from payloads of one sync generation" is the rule, but primary and comparison are **separate Fetch Keys synced independently**. What a generation is and how pairing is enforced are undefined: a joint sync job, a generation counter, or a tolerance window? |
| S2 | `data_as_of` semantics | Is it the fetch time of the first observation of the current hash, the source `Last-Modified`/`Date` header, or a mapped Time field? FR-60 requires "real data age" |
| S3 | Endpoint edit semantics | Q-A2 is open, and no storage exists for either option (T4) |
| S4 | Dataset identity and mutability | When is a Dataset reused or forked, and who may edit shared field types (T5)? |
| S5 | Raw payload deletion in `latest` mode | Mechanics and partitioning (T1) |
| S6 | Expanded view and View-as-table | Where the pre-top-N row set lives (a second cached artefact or recompute per page), how its size is bounded, and pagination keys |
| S7 | Valkey topology and eviction | See T6. Result TTL value, persistence on or off |
| S8 | Pagination × conditional requests | Which page's ETag is stored? Is the content hash taken over the concatenated pages? Does a 304 on page 1 short-circuit? |
| S9 | Mandatory Blocks enforcement | A server-side reject of remove on `mandatory`; the interaction with access revocation (G13.5); and **Undo of a remove**, which needs a restore-at-original-position command because AddBlock uses first-free-slot. The Dashboards contracts list no `RemoveBlock` or `RestoreBlockInstance` |
| S10 | Template data model | `template_versions` layout schema, mandatory flags, the Template↔Block join table, whether a Template pins Block identity or Block Version, and how Reset (FR-57) treats revoked Blocks |
| S11 | Operations model | The `operations` table and its states; result retention; and the timeout surfaced to the wizard. Also whether a **fetch-as-user sample stored in a shared Draft** (other Admins can open the Draft) leaks that user's data to other Admins |
| S12 | Access-change impact count | The FR-7 "N users will lose this block" query, which must account for group overlap and `access_mode=all`. Also fix the FR-39 distinct-user count |
| S13 | Search indexing | `search_documents` is owned by Search but indexes other modules' entities, so the update path (outbox events?) is unspecified. Per-type permission predicates are needed for Admin-only types (users, data sources, settings) |
| S14 | Notification fan-out | For "new Blocks available" and "Block updated", recipients are not computed anywhere. Are they computed with AccessEvaluator at emit time? Is a revocation between emit and read honoured? Also dedupe and retention |
| S15 | Rate-limit and breaker configuration ownership | Are they Admin-editable on the Data Source (FR-9 doesn't list them), operator-only, or platform defaults? Also the per-workspace fairness cap values and where they are set |
| S16 | AutoMapper | The rules for inferring Field Roles and auto-assigning Slots (name heuristics, type heuristics, cardinality). Without them, the "Auto-map" story is a design task |
| S17 | Area switching | Can a session switch User↔Admin without re-auth? What happens to the soft lock and channels on a switch? |
| S18 | Pause live updates (FR-60) | Does a paused client still touch `last_access_at` and keep targets hot? Does push still arrive and get ignored? Also: is "compute inline (bounded)" on a read miss bounded by the guards or by a stricter web-role budget? |

Lower-priority items, noted but not counted:
- the `/api/v1` route catalogue is not enumerated;
- PgBouncer adoption is undecided;
- the tablet column count is a UX decision.

## 17. Top fixes (ordered)

1. Add the sync-target ↔ Block Version subscription table and its lifecycle (T3).
2. Add immutable `endpoint_revisions` and pin `endpoint_revision_id` in `block_versions.config`, or resolve Q-A2 the other way (T4/S3).
3. Fix the `raw_payloads` partitioning and unique-key design, and define deletion in `latest` mode (T1).
4. Define a sync "generation" for primary-plus-comparison results, and `data_as_of` semantics (S1, S2).
5. Snapshot Dataset field types and roles into each Block Version, or make Dataset edits copy-on-publish (T5).
6. Remove or label the invented `max_attempts=3` and "every minute", and reword the §17 capacity claims as [ASSUMED] (G8–G10).
7. Unify the labels: replace ADOPTED with CONFIRMED and label the unlabelled decisions in §4, §6, §13, §14, §17 and §18 (G6, G7).
8. Design the access-change impact count, fix the FR-39 distinct-user count, and resolve Mandatory vs revoked access plus Undo-remove (S9, S12, G13).
9. Decide Valkey topology and eviction policy (split queue/lock store from the result cache) (T6).
10. Add a Docker/OCI + Helm + Inertia + Sanctum + PgBouncer validation to §19, and add the "MVP-implementable / no over-engineering" principle with an explicit phasing of seams versus drivers (G4, G5).
