---
title: 'Adversarial review: Architecture Spine, Dashflow'
target: ../ARCHITECTURE-SPINE.md
companion: ../SOLUTION-DESIGN.md
lens: 'Two units one level down, each obeying every AD to the letter, still build incompatibly'
date: 2026-10-05
reviewer: adversary lens (subagent)
---

# Adversarial review: Dashflow Architecture Spine

## Verdict

The spine is strong on *what must be true* (tenancy, immutability, one engine, read path never waits). It is weak on *who owns the seams*. The module dependency diagram (AD-2) does not match the call graph that SOLUTION-DESIGN describes. Several cross-cutting artifacts have no owner and no schema: the Block Result, the Fetch Key canonical form, the resolved period, the subscription set, the outbox envelope, the layout command set and the audit action vocabulary. Two compliant teams would ship incompatible code at about a dozen seams. Ten of these need a new or tightened AD before epics are cut. They are not wording issues.

Method: for each seam, I name two epics/units (taken from SOLUTION-DESIGN §23), show how each can follow every AD literally and still diverge, cite the text that allows the divergence, and propose exact Rule text. Severity: **S1** = incompatible builds or wrong numbers or a security leak; **S2** = rework or drift likely; **S3** = friction.

---

## Summary table

| # | Seam | Units in conflict | Sev | Fix |
|---|---|---|---|---|
| H1 | AD-2 diagram vs the real call graph (results read path, compute, Access → Blocks cycle) | User dashboards vs Connector & sync vs Lifecycle & access | S1 | New AD: `Results` module + corrected diagram |
| H2 | Block Result: owner, key shape, envelope | Dashboards vs Blocks (pre-compute) vs Ingestion (PayloadChanged) | S1 | New AD: Block Result contract |
| H3 | Operation result write-back (sample, ValidationReport) into Blocks tables | Wizard & mapper vs Connector & sync | S1 | New AD: Operations contract |
| H4 | Fetch Key canonical form and owner; endpoint revision storage | Connector & sync vs User dashboards vs Wizard | S1 | Tighten AD-7 |
| H5 | Period resolution (FR-35 precedence, time zone, relative windows, comparison prior period) | User dashboards vs Connector & sync vs Mapper | S1 | New AD: PeriodResolver |
| H6 | Subscription set (which Block Versions read a sync target) and `result.updated` fan-out | Connector & sync vs Notifications/realtime vs Dashboards | S1 | New AD: subscriptions + fan-out |
| H7 | Layout commands, placement algorithm, mandatory propagation writes `dashboard_items` | User dashboards vs Templates | S1 | Tighten AD-14 |
| H8 | Access predicate in PHP vs SQL; Access needs Blocks lifecycle | Lifecycle & access vs Search vs Dashboards (Add-blocks Panel) | S1 | Tighten AD-4 |
| H9 | Query Plan vs `slot_mapping` dual storage; plan versioning; Block Type `contractVersion` evolution | Mapper vs Block Types vs Lifecycle | S1 | Tighten AD-11, AD-21 |
| H10 | Comparison pairing: "sync generation" is undefined | Connector & sync vs Mapper/compute | S1 | Tighten AD-15 |
| H11 | Block state enum: producers, precedence, block-level vs slot-level, `partial` has no producer | Block Types vs Dashboards vs compute | S2 | New convention / AD |
| H12 | Outbox ownership, event naming and envelope; domain events vs broadcast events | Every module vs Notifications/realtime | S1 | New AD: Outbox contract |
| H13 | Audit: "its own DB transaction" ambiguity; action naming; who audits denials and reads | Platform foundation vs every module | S2 | Tighten AD-18 |
| H14 | Restore semantics: "one-step restore" vs validation gate; immutability trigger vs `superseded` | Lifecycle & access vs Wizard | S1 | Tighten AD-13 |
| H15 | Cross-workspace system jobs vs RLS (dispatcher, retention, health sampling) | Connector & sync vs Platform foundation | S1 | Tighten AD-3 |
| H16 | Dataset reuse is mutable shared state across Blocks | Wizard & mapper (Block A) vs Wizard & mapper (Block B) vs Lifecycle | S2 | New rule in AD-9/AD-11 |
| H17 | `user_attributes` key definitions owned twice; membership removal cascades across modules | Lifecycle & access vs Settings vs Connector & sync | S2 | Tighten AD-4 |
| H18 | Item state produced on two surfaces (Inertia props and results API) | User dashboards (page) vs User dashboards (API) / Lifecycle | S2 | Tighten AD-20 |
| H19 | Stale threshold: which interval | User dashboards vs Health (SM-6 metric) | S2 | Convention |
| H20 | Template layout shape vs dashboard layout shape | Templates vs User dashboards | S2 | Shared Layout DTO |
| H21 | Error code namespace ownership | All | S3 | Convention |

---

## H1: The AD-2 dependency diagram is not the system's call graph (S1)

**Units:** *User dashboards* (Dashboards module) vs *Connector & sync* (Ingestion, RawStore) vs *Lifecycle & access* (Access).

**What the spine says.** AD-2: "talks to other modules only through their Contracts **and follows the dependency diagram above**." The diagram has `Dashboards → Blocks` only. `Blocks → Mapping, Datasets`. `App → Access, Audit, Tenancy`. `Access → Tenancy`.

**What SOLUTION-DESIGN requires.**
- §5.2 / AD-15: the Dashboards results endpoint resolves the Fetch Key, reads the latest raw payload (RawStore), runs the engine on a miss (Mapping), enqueues an interactive sync (Ingestion) and touches `sync_targets.last_access_at` (Ingestion). None of these edges exists from Dashboards.
- §11.2: `AccessEvaluator.canUseBlock` checks `block.lifecycle = published` and the Block's grants. `blocks.lifecycle` is a Blocks table; Access can't read it. If Access depends on Blocks, that is a cycle, because Blocks (as an App module) depends on Access.
- §4.2: Dashboards exposes `ResetToTemplate`, and §12 has Templates appending items to dashboards. Neither `Dashboards → Templates` nor `Templates → Dashboards` is in the diagram.
- §5.1: Blocks enqueues Operations (fetch sample, validation). There is no `Blocks → Ingestion` or `Blocks → Connector` edge (only through Datasets → Connector).
- §4.4: ComputeJob runs the engine, then a Block Type Shaper, a drift check (Datasets) and notifications. No module is named as its owner.

**Divergence.** The Dashboards team, following AD-2 literally, can't build AD-15, so it builds a `Blocks::resultsFor()` contract and pushes the read pipeline into Blocks. The Connector & sync team builds the same pipeline in Ingestion for `PayloadChanged`. Result: two compute orchestrators, two cache writers and two Fetch Key resolvers. The Pest architecture test either fails the build or gets loosened ad hoc, epic by epic.

**Proposed AD-25 — Results module and the authoritative dependency graph**

> **Rule:**
> - A `Results` module owns the Block Result pipeline: result cache keys, `ComputeBlockResult`, `ResultsFor(items, membership, period)`, the compute job, pre-compute on publish and the subscription set (AD-27). It is the only writer of Block Results.
> - The dependency graph is the table in `tests/Architecture/dependencies.php`, and the diagram is generated from it. Allowed edges added to the spine: `Dashboards → Results, Templates (Contracts only)`; `Results → Ingestion, RawStore, Mapping, Datasets, Blocks (read contracts), BlockTypes`; `Blocks → Ingestion (Operations)`; `Access → Blocks/Templates` is **forbidden**. Instead, Blocks and Templates publish their lifecycle and access mode into Access through `Access\Contracts\RegisterAccessSubject(subject_type, subject_id, visible: bool, mode)`, which Access stores in its own `access_subjects` table, inside the same transaction as the lifecycle change.
> - Adding an edge requires a spine change.

---

## H2: Block Result has no owner, no single key shape and no envelope schema (S1)

**Units:** *User dashboards* (reads results, computes inline on a miss) vs *Lifecycle* (pre-compute on publish, §5.4) vs *Connector & sync* (PayloadChanged triggers compute).

**Contradictions in the text.**
- AD-9: Block Results are "keyed by `(block_version_id, fetch_key, payload_hashes)`".
- §7.2: the Valkey key is `res:{ws}:{block_version}:{fetch_key}`, with `payload_hashes` **in the value**.
- §4.3: the browser keys results by `(dashboard_item, result_etag)`. `result_etag` is defined nowhere.
- §8 (Expanded view): rows are "server-paginated from the cached result's row set (before top-N)". The cached value in §7.2 is `render_payload`, which is the Shaper's output after top-N. One team caches the shaped payload only; the other needs pre-top-N rows.
- A comparison Block reads **two** Fetch Keys (§6.2). A cache key with one `fetch_key` doesn't say which one.
- The period filter applied platform-side (§8 "Period filtering") means a result depends on the resolved period, not just on the Fetch Key. If the API ignores a period parameter, two periods can share a Fetch Key but need different results.

**Divergence.** Dashboards caches under `res:{ws}:{bv}:{fk}` and treats a hit as current. Compute writes `res:{ws}:{bv}:{fk}:{hashes}` to stay faithful to AD-9. Every read misses and recomputes inline, so AD-15's "bounded" compute becomes the hot path. Or one comparison Block gets two cache entries keyed by each Fetch Key, and they race.

**Proposed AD-26 — Block Result contract**

> **Rule:**
> - `result_key = res:{workspace_id}:{block_version_id}:{primary_fetch_key}:{period_digest}`. `comparison_fetch_key` and the payload hashes go in the value, never in the key.
> - The value is the versioned `BlockResult` DTO in `Results\Contracts`: `{v, block_version_id, primary_fetch_key, comparison_fetch_key?, payload_hashes{primary, comparison?}, generation_id, data_as_of, checked_at, state, slot_states{}, render_payload, rowset_ref?}`. `rowset_ref` points to the pre-top-N row set used by Expanded view and View as table, stored under the same key with suffix `:rows`.
> - `result_etag = sha256(result_key ‖ payload_hashes ‖ v)`. It is the HTTP ETag and the client cache key.
> - Only `Results` writes these keys. A read is a hit only when the value's `payload_hashes` equal the targets' current `content_hash`. Otherwise it is a miss.

---

## H3: Operation results are written into another module's tables (S1)

**Units:** *Wizard & mapper* (Blocks owns `block_versions.draft_sample` and `validation_reports`) vs *Connector & sync* (Ingestion owns `operations`; worker-connector executes).

**The text.**
- §5.1: `C->>P: operation result (payload kept with Draft, not raw store)` and `C->>P: ValidationReport bound to draft revision`. The worker-connector writes rows that belong to Blocks.
- AD-2: a module writes only its own tables. Connector can't depend on Blocks (the dependency runs the other way).
- C5: a pasted or fetched sample is "never stored outside the Draft". If the fetched body sits in `operations.result` until Blocks copies it, it is stored outside the Draft, in a table with no TTL rule (§7.3 lists no retention for `operations`).
- AD-16 has `operation.completed`, but no rule says who turns an operation result into a ValidationReport, or whether the report is bound to the revision **at enqueue time** or **at completion time**.

**Divergence.** The Connector team stores the response body in `operations.result_json` (AD-6 says it "returns a FetchResponse", so it must land somewhere). The Wizard team reads the body over `/api/v1/operations/{id}` and PATCHes it into the Draft. The sample now exists in three places (operations row, HTTP response, Draft), which violates C5. Or the Wizard team binds the report to the revision current at completion, so an edit made during validation slips through, which violates AD-13 "bound to the current Draft revision".

**Proposed AD-28 — Operations contract**

> **Rule:**
> - `Ingestion` owns `operations` (id, workspace_id, kind, requested_by_membership, subject_type, subject_id, subject_revision, status, error, result_ref, expires_at). It never stores a response body in Postgres.
> - A body produced for a Draft is handed to the requesting module through a completion handler that module registers (`OperationHandler` in the requester's Contracts, invoked in `worker-compute` on the `operation.completed` outbox event). The handler writes the requester's own tables. Ingestion passes the body only as a short-lived encrypted Valkey blob (`op:{ws}:{id}:body`, TTL = operation TTL). The blob is deleted after the handler commits.
> - A ValidationReport binds to `subject_revision` captured **at enqueue**. If the Draft's revision has moved by the time the handler runs, the handler stores the report as `stale`.
> - Operation kinds and their owners are a closed list in the spine: `connection_test` (Connector), `sample_fetch` / `fetch_as_user` / `preview_as` / `publish_validation` (Blocks), `template_validation` (Templates).

---

## H4: Fetch Key canonical form has no owner, and pinned endpoint revisions have nowhere to live (S1)

**Units:** *Connector & sync* (computes keys for scheduled syncs, stores resolved params on `sync_target`) vs *User dashboards* (`FetchKeyResolver` in `web`, §4.4) vs *Wizard* (publish validation and fetch-as-user need the same key so the payload can be reused).

**Gaps.**
- AD-7: `sha256(workspace_id, endpoint_id, endpoint_revision, canonical resolved params, user_context_digest)`. There is no separator, no encoding, no type rules ("2026-10-01" vs a date object; `10` vs `"10"`; arrays sorted or kept in order; null vs absent), and no owning module.
- §6.1 says canonical params use "ISO dates **in the Workspace time zone**". The Time convention says "UTC in storage and API". One team serialises `2026-10-01` (a local date); the other serialises `2026-09-30T22:00:00Z`.
- §6.2: `user_context_digest` is an "HMAC of the bound attribute values". The key (per workspace? APP_KEY? the SecretVault?) and the attribute ordering aren't specified. Under AD-19, `web` "cannot unwrap" secrets, so an HMAC key held in the vault can't be used on the read path.
- Q-A2 / §12: published versions **pin** `endpoint_revision`. But `endpoints` is one row with a `revision` counter; there is no `endpoint_revisions` table. The worker can't rebuild the request for a pinned old revision. The Connector team overwrites the row; the Lifecycle team expects old Block Versions to keep working.

**Divergence.** Dashboards computes `sha256(json_encode([...]))`. Ingestion computes `sha256(implode('|', ...))`. Their keys never match, so every dashboard read becomes `pending` + interactive sync, and the duplicate-fetch protection (FR-13) is lost.

**Tightened AD-7 Rule (replace first bullet, add two)**

> - `fetch_key = "fk1:" + hex(sha256(JCS(FetchKeyInput)))`, where `FetchKeyInput = {v:1, workspace_id, endpoint_id, endpoint_revision, params: {name: TypedValue}, ctx: user_context_digest|null}`, JCS is RFC 8785, `TypedValue = {t: "string"|"number"|"bool"|"date"|"datetime", v}`, dates are `YYYY-MM-DD` workspace-local calendar dates, datetimes are UTC `Z`, and absent and null bindings are omitted. Only `Ingestion\Contracts\FetchKeyResolver::resolve(BlockVersionRef, MembershipRef, ResolvedPeriod)` computes it. Every other module calls it. A golden-vector test suite lives with the contract.
> - `user_context_digest = HMAC-SHA256(workspace_fk_salt, JCS({attr_key: value}))`. `workspace_fk_salt` is a non-secret-tier per-workspace value readable by `web`, not a vault secret.
> - Endpoint edits create rows in `endpoint_revisions` (immutable, owned by Connector). `endpoints.current_revision` points to the latest. Block Versions pin a revision ID, and the worker builds requests from that pinned revision.

---

## H5: Period resolution has no owner, and relative periods rotate Fetch Keys (S1)

**Units:** *User dashboards* (owns `dashboards.date_range` and `dashboard_items.period_selection`) vs *Lifecycle/Blocks* (owns "period behaviour" in `block_versions.config`) vs *Connector & sync* (binds `date_range.from/to` and `block_period.start/end` into params).

**Gaps.**
- §5.2 step 2 says "resolve the period (FR-35 precedence)", but no module, contract or output type is named. The precedence inputs live in three modules.
- Time zone: the convention table says "period windows resolved server-side in the Workspace time zone". `users.tz` exists (§7.2). A team building "Today" for a user in another time zone may use the user's.
- **Rolling windows.** A sync target stores *resolved* params (§7.2). For "Last 30 days", the resolved absolute dates change at local midnight. The Fetch Key changes, the old target keeps getting refreshed until it goes cold, and the new key starts cold, so every dashboard shows `pending` at 00:00 local time. Unless the key holds the token (`last_30d`), and then the worker resolves the dates at fetch time. Each team can pick either approach, and both follow AD-7.
- The comparison prior period (FR-27b) is derived from the primary period. Who derives it (calendar month vs 30 days, week-aligned) is unstated.
- The wizard preview and publish validation need a period too. Which one: the default, or the Admin's chosen one?

**Proposed AD-29 — Period resolution**

> **Rule:**
> - `Dashboards\Contracts\PeriodResolver::resolve(item|null, dashboard|null, BlockVersionRef, now): ResolvedPeriod` is the only implementation of FR-35 precedence (item override > dashboard range > Block default, unless the Block Version's period behaviour is `fixed`). Preview and validation call it with `item=null, dashboard=null`.
> - `ResolvedPeriod = {token, start_date, end_date, tz, comparison?: {start_date, end_date}}`. Dates are workspace-local calendar dates. The time zone is always the Workspace's; the user's time zone is used only for display.
> - The Fetch Key uses `start_date` and `end_date`, not the token. Ingestion pre-warms the next window: within `prewarm_lead` before local midnight it creates the next day's sync target for every hot rolling target, so a rollover never shows `pending`.
> - The comparison period rule (shift by the window length, or calendar-aligned for `month`/`quarter`/`year` tokens) is defined only inside `PeriodResolver`.

---

## H6: The subscription set doesn't exist, so the effective interval and `result.updated` fan-out can't be computed (S1)

**Units:** *Connector & sync* (needs "the minimum Refresh Interval of its subscribed Block Versions while hot", AD-7) vs *Notifications/realtime* (must deliver `result.updated` on **per-membership** channels, AD-16) vs *User dashboards* (knows which membership views which item).

**Gaps.**
- No table records which Block Versions subscribe to a sync target. `last_access_at` is per target, not per (target, version), so "the minimum interval among the **hot subscribed** Block Versions" (§6.1) can't be computed.
- §5.3: "for each subscribed Block Version" is the compute job's loop, and it has no data source.
- AD-16 channels are per membership only. A shared Fetch Key (no user context) used by 500 members needs 500 broadcasts. Someone must compute *which* memberships. Only Dashboards knows (`dashboard_items`), but the outbox relay belongs to Notifications/Realtime.
- `result.updated{fetch_key, block_version}`: to match an event to items, the client must know each item's Fetch Key. The results API doesn't say it returns one. A Fetch Key with a user-context digest is a per-user value, and exposing it is a privacy question.

**Divergence.** Ingestion keeps the interval on `sync_targets.effective_interval` and sets it at creation, from the first Block Version that created it. A later Live Block doesn't lower it, so it is never Live. Realtime broadcasts `result.updated` on `workspace.{id}.admin` only, because it can't compute the recipients, and user clients fall back to polling everywhere.

**Proposed AD-27 — Subscriptions and fan-out**

> **Rule:**
> - `Results` owns `result_subscriptions(workspace_id, sync_target_id, block_version_id, role primary|comparison, last_access_at)`, upserted (throttled) by the read path. `effective_interval(target) = min(refresh_interval of block_versions with a hot subscription)`. Ingestion reads it only through `Results\Contracts\EffectiveInterval`.
> - `result.updated` carries `{event_id, block_id, block_version_id, result_etag}`, never a Fetch Key. `Results` resolves recipients as the memberships with a hot `dashboard_items` row for that block, **through `Dashboards\Contracts\ViewersOf(block_id)`**. For Blocks without user context it publishes once on `workspace.{id}.block.{block_id}`, a private channel authorized by `AccessEvaluator.canUseBlock`. Clients refetch any item whose `block_id` matches and whose `result_etag` differs.

(This adds a channel family to AD-16. That is intentional: the fan-out cost otherwise grows with Blocks × viewers.)

---

## H7: Layout commands, placement and mandatory propagation (S1)

**Units:** *User dashboards* (owns `dashboards`, `dashboard_items`, AD-14 add-block and placement) vs *Templates* (FR-47 mandatory propagation).

**Gaps.**
- §12: "A newly mandatory Block is appended to existing derived dashboards **by a compute job**, at the first free slot **after the last row**". AD-14: Add-block runs "**first-free-slot** placement on the saved layout". These are two algorithms: fill the first gap, or append after the last row. Each team implements its own.
- Under AD-2, the Templates team can't write `dashboard_items`. If it calls `Dashboards\AddBlock`, that is a dependency the diagram doesn't have, and AddBlock checks `canUseBlock` for the *acting* user (§11.2), and a system job has no user.
- `SaveLayout` semantics aren't defined. If it replaces the full item set (a natural client implementation), a layout save from a tab opened before propagation **deletes** the newly added mandatory item. `dashboards.revision` exists (§7.2), but no rule says that AddBlock, propagation, clamp persistence or `compact_order` edits bump it.
- AD-16 has no `dashboard.changed` event, so an open dashboard never learns that propagation added an item.
- Clamping "is persisted on the next save" (AD-14). If the client sends clamped sizes, the client is clamping. If the server persists them, which command? Size limits come from the Block Type schema, which Dashboards must read (another missing edge).
- `compact_order` for a newly placed item (start, end, or derived) is unspecified.
- A mandatory Block that the user can't access: is it skipped forever, or added when access is granted later?

**Tightened AD-14 Rule (replace bullets 3–5)**

> - Layout mutations are exactly these commands in `Dashboards\Contracts`: `AddItem`, `RemoveItem`, `MoveResizeItems(positions[])`, `SetMinimized`, `SetCompactOrder`, `ResetToTemplate` and `ApplyMandatory(template_version_id)`. Each takes `expected_revision`, bumps `dashboards.revision`, and emits `dashboard.changed{dashboard_id, revision}` to the owner's channel. `MoveResizeItems` never creates or deletes items. Unknown or missing item IDs are a `409`. Mandatory items reject `RemoveItem`.
> - Placement is the single pure function `Dashboards\Domain\Placement::place(layout, w, h, mode)`. `mode=fill` (user Add-block) scans row-major from (0,0) for the first gap. `mode=append` (mandatory propagation) places at x=0 below the lowest occupied row. A new item is appended to the end of `compact_order` when one exists.
> - Templates never touch dashboards. On publish it emits `template.version_published{template_id, version_id, mandatory_added[]}`. Dashboards consumes it (outbox handler) and runs `ApplyMandatory` per derived dashboard as a system actor. A Block the owner can't use is skipped and recorded in `dashboard_pending_mandatory`, which is re-applied on `access.changed`.
> - Clamping is computed by the server on read, and `MoveResizeItems` persists the clamped value. Clients never clamp authoritatively.

---

## H8: Access evaluated in PHP and in SQL will drift (S1)

**Units:** *Lifecycle & access* (builds `AccessEvaluator.canUseBlock` in PHP) vs *Search* (FR-63, filters `search_documents`) vs *User dashboards* (Add-blocks Panel list) vs *Templates* (gallery, "Template Blocks the user can't access are omitted").

**Gaps.**
- §11.2: "Visibility lists ... apply the **same predicate in SQL**." The predicate is then implemented twice by two teams. AD-4 requires "**one** AccessEvaluator contract" but allows any number of SQL re-implementations.
- Search owns `search_documents`. To filter by access it must join `block_access_grants` and `group_members` (Access tables), which violates AD-2. Or it denormalises grants into the search documents, which breaks AD-4's "from current DB state". Either way it diverges.
- The predicate depends on `blocks.lifecycle` (Blocks) and on the session **area** (Admin vs User). An Admin in the User area: should they see `access_mode=groups` Blocks they're not in? Unspecified.
- For a Template: if the Template is accessible but some of its Blocks aren't, is the Template visible? Is `canUseTemplate` affected by the accessibility of its Blocks?
- Under RLS, a SQL predicate that a module writes against another module's tables also crosses ownership boundaries.

**Tightened AD-4 Rule (replace bullet 3)**

> - `Access\Contracts\AccessEvaluator` is the only definition of visibility. It exposes `canUse(subject, membership, area): bool` and `visibleSubjectIds(subject_type, membership, area): QueryFragment`, an SQL subquery over Access-owned tables only (`access_subjects`, grants, `group_members`; see AD-25). `canUse` is implemented **as** `EXISTS(visibleSubjectIds ∩ {id})`, so there is exactly one predicate. List queries in other modules may only `WHERE id IN (<fragment>)`. A property-based test runs both paths on random grant graphs.
> - Admin area: Admins see every subject for management, but `canUse` for viewing data ignores the role. Template visibility does not depend on its Blocks; inaccessible Blocks are filtered at render time.

---

## H9: Query Plan vs Slot Mapping, plan versioning and Block Type contract evolution (S1)

**Units:** *Mapper* (Mapping module: Query Plan, engine, AutoMapper) vs *Block Types* (`schema.json` slots, Shaper) vs *Lifecycle* (immutable published versions that live for years).

**Gaps.**
- AD-11: "Slot Mapping, Transforms and Calculated Fields are stored **only** as a Query Plan". §7.2 `block_versions.config` holds `query_plan` **and** `slot_mapping` **and** `presentation` as siblings. One team derives slots from the plan's `shape` stage; the other reads `slot_mapping`. They disagree as soon as one is edited without the other.
- There is no Query Plan schema owner and no `v` field. Published versions are immutable (a trigger rejects updates), so a plan written with engine v1 must run on engine vN forever. If Mapping evolves the schema (a renamed op, new stage semantics), the immutable rows can't be migrated.
- AD-21: Block Versions pin `contractVersion` and renderers register by `{key, contractVersion}`. But a package holds **one** `schema.json` and **one** Shaper. When `bar` moves from contract 1 to 2, the package is overwritten, and v1 Block Versions now run Shaper v2 against renderer v1, or they have no renderer. §12 says major version is "reserved for a Block-Type change", but no rule says whether old contracts stay runnable or whether an upgrade creates a Draft.
- `schema.json` (Block Types) defines slots and default aggregation per Measure (§7.2). The plan's aggregate stage (Mapping) also encodes aggregation. That gives two places to set the default.

**Tightened AD-11 Rule (add)**

> - `config.query_plan` is the single source of truth for slots. `config.slot_mapping` is removed. The `shape` stage carries `{slot_key: output_column}`. The Query Plan JSON Schema lives in `Mapping/Contracts/query-plan.v{N}.schema.json`, and every plan carries `"v": N`. The engine runs every `v` ever published (no in-place migration). A new `v` requires a conformance suite case per stage, with plans of the old `v` replayed against golden outputs.

**Tightened AD-21 Rule (add)**

> - A Block Type package keeps every published contract side by side: `block-types/{key}/v{contractVersion}/{schema.json, Shaper.php, Renderer.vue}`. A contract version is never deleted while any `block_versions` row pins it (CI check against a migration-time inventory). Moving a Block to a new contract is an Admin action that creates a Draft through `UpgradeBlockTypeContract`. It is never automatic. Defaults (aggregation, size limits) come only from `schema.json`; AutoMapper copies them into the plan, and the engine never reads `schema.json`.

---

## H10: Comparison pairing: "sync generation" is undefined (S1)

**Units:** *Connector & sync* (the primary and comparison targets sync independently, each with its own dedupe lock and `next_due_at`) vs *compute* (must "never mix generations", AD-15, FR-61).

**Gaps.** AD-15 and P13 rely on a "sync generation", which has no field, table or definition. §6.2 says "a result that needs both records **both payload hashes**". Any two hashes satisfy that, including a fresh primary and a three-day-old comparison. The two targets can be on different intervals (the comparison's prior-period data changes rarely, so with hot-window scheduling it may go cold and be probed only). Which pairing is "mixed" is left for each team to decide.

**Divergence.** Ingestion team: generation = "latest payload of each target". Compute team: generation = "payloads fetched in the same dispatcher tick" and refuses to compute, so the comparison Slot is permanently `Unavailable`.

**Tightened AD-15 Rule (replace bullet 4)**

> - A comparison Block's primary sync target lists its comparison target in `sync_targets.linked_target_id`. Ingestion fetches both in **one** sync run, which stamps a `generation_id` (UUIDv7) on both `raw_payloads`. If either fetch fails, neither payload is stored as a new generation. A `304` on one side carries the prior payload forward under the new `generation_id`. Compute pairs payloads only by equal `generation_id`. The comparison target never schedules on its own.

---

## H11: Block state enum: producers, precedence, granularity (S2)

**Units:** *Block Types* (renderers implement every state, AD-21) vs *User dashboards* (read path) vs *compute* (stores `state` in the result).

**Gaps.**
- `loading` is client-only. `stale` is computed at read time (§6.4). `access_removed` / `unpublished` come from the read path or the page props. `ok/empty/unavailable/error` come from compute. `partial` has **no producer**: AD-11 forbids truncation, FR-13 forbids partial payloads, and guards abort.
- States can combine: a Stale result whose comparison slot is Unavailable. An enum value can't express that, and there is no precedence rule.
- `unavailable` is a slot-level concept (AD-11 "a missing field produces Unavailable"), while the enum is block-level.

**Proposed convention (Block states row, replace)**

> `BlockResultState = {state: pending|ok|empty|unavailable|error|access_removed|unpublished, stale: bool, slot_states: {slot_key: ok|unavailable}}`. Producers: compute sets `ok|empty|unavailable|error` and `slot_states`. `Results` read path sets `pending`, and `stale` from AD-7 data. Dashboards sets `access_removed|unpublished` (and then nothing else is returned). `loading` is client-only and never on the wire. `partial` means "≥1 slot unavailable, ≥1 ok". It is derived by the renderer from `slot_states` and never stored. Precedence: access_removed > unpublished > error > pending > unavailable > empty > ok, with `stale` orthogonal.

---

## H12: Outbox ownership, event naming and envelope (S1)

**Units:** *every module that writes outbox rows* (Access on `access.changed`, Blocks on publish, Ingestion on PayloadChanged?) vs *Notifications/realtime* (owns `outbox`, §4.2).

**Gaps.**
- §4.2: Notifications **owns** `outbox`. AD-4, AD-17 and the Mutations convention require every module to write outbox rows in its own transaction. Under AD-2 they can't write a Notifications table, and Notifications isn't upstream of them in the diagram.
- Naming: PHP events are `NounVerbedEvent` (convention). Wire events are `result.updated`, `block.version_published`, `access.changed`, `draft.lock_taken` (AD-16). `PayloadChangedEvent` (§4.2) is it an outbox event, a direct job dispatch, or a Laravel event? The payload of `access.changed` is `{block_id}` in §5.5, but templates also have grants. The schemas have no version.
- Delivery: at-least-once with consumers "idempotent by event ID" (§14), but nothing owns the consumer-dedupe table.
- Ordering: `access.changed` then `result.updated` for the same Block could arrive reordered.

**Proposed AD-30 — Outbox contract**

> **Rule:**
> - The outbox is a platform kernel concern: `Platform\Outbox\Contracts\Outbox::emit(EventEnvelope)`, which joins the caller's transaction. The table `outbox_events` is owned by the kernel, not by Notifications. Notifications and Realtime are consumers.
> - `EventEnvelope = {event_id (UUIDv7), type, v, workspace_id, occurred_at, actor_membership_id?, request_id, subject{type,id}, data{}}`. `data` contains IDs and enums only.
> - `type` is `{module}.{noun}.{past_verb}` in snake_case (`blocks.version.published`, `access.grant.changed`, `ingestion.payload.changed`, `results.result.updated`, `dashboards.dashboard.changed`, `operations.operation.completed`). Each type's JSON Schema lives in the emitting module's `Contracts/Events/`. PHP class names mirror it (`BlockVersionPublishedEvent`). The AD-16 broadcast names are the same strings.
> - Consumers record `(consumer, event_id)` in `outbox_consumptions`. Ordering is guaranteed only per `subject`.

---

## H13: Audit transaction and vocabulary (S2)

**Units:** *Platform foundation* (builds `Audit::record`) vs *every module* (calls it).

**Gaps.**
- AD-18: "writes its `audit_event` in **its own** DB transaction". This can be read as "in a separate transaction", which contradicts the Mutations convention (one transaction for domain change + audit + outbox) and AD-4 bullet 4. One team opens a nested transaction or a second connection, so the audit commits even when the change rolls back.
- No action vocabulary exists. One team writes `block.publish`, another `BlockPublished`, another `blocks.published`. Search by action (FR-68) then needs a list per module.
- Denied access, failed sign-in and blocked SSRF (§15) happen in read paths or failure paths, where there is no domain transaction. Who writes those rows, and are they lost when the request rolls back?
- Before/after: who supplies the serializer allowlist for each object type?

**Tightened AD-18 Rule (replace bullet 1, add)**

> - Every audited command writes its `audit_event` **inside the same transaction as the change** (no nested or second transaction). Denials, sign-in outcomes and egress blocks are written by `Audit::recordSecurityEvent` in a dedicated autonomous transaction that commits even if the request fails.
> - `action` uses the outbox `type` grammar (`blocks.version.published`, `access.grant.changed`, `identity.sign_in.failed`). The full list is an enum in `Audit\Contracts\AuditAction`; adding one is a code change. Each owning module registers an `AuditSerializer` (allowlist) for its object types. Audit refuses to write an object type with no registered serializer.

---

## H14: Restore semantics and the immutability trigger (S1)

**Units:** *Lifecycle & access* (FR-41 restore; §21 promises "one-step restore") vs *Wizard & mapper* (the AD-13 validation gate).

**Gaps.**
- AD-13: Restore creates a Draft from vN, and publish needs a passing ValidationReport. §21 says "one-step restore". One team re-points `current_version_id` to v1.1, which is instant. The other creates a Draft and forces revalidation, which takes several steps.
- If restore re-points, then v1.1's `state` moves from `superseded` to `published`. That is an UPDATE on a published version, which the trigger (§7.2) rejects. And `block_version_id` in the result cache keys returns to an old ID, so stale cache entries may serve.
- The version number after restore (v1.3 as a copy, or v1.1 again) affects the impact display and the audit.

**Tightened AD-13 Rule (add)**

> - Restore always creates a Draft copied from vN (config only; never the grants). Publishing that Draft creates a **new** version number and needs a fresh ValidationReport. "One-step" is the UX: Restore-and-publish runs validation and then publishes in one Operation, and fails closed. `current_version_id` only ever moves forward to a newly created version. `block_versions.state` and `published_*` are the only columns the trigger allows to change, and only `published → superseded`.

---

## H15: Cross-workspace system jobs vs RLS (S1)

**Units:** *Connector & sync* (the dispatcher's `SELECT ... FROM sync_targets WHERE next_due_at <= now()` across all workspaces, §14) vs *Platform foundation* (AD-3: the app role has no `BYPASSRLS`; "cross-workspace operations exist only in operator commands").

**Divergence.** Under RLS, the dispatcher sees zero rows unless `app.workspace_id` is set. Team A loops over every workspace (N queries per tick, which defeats SKIP LOCKED batching). Team B runs the dispatcher as the operator role, which breaks AD-3. Retention purge, health sampling, partition management and the outbox relay hit the same issue.

**Tightened AD-3 Rule (add)**

> - A `system` DB role with a narrowly granted, column-limited `SELECT`/`UPDATE` on `sync_targets(id, workspace_id, next_due_at, last_access_at)` and `outbox_events` (relay columns), with RLS policies `TO system USING (true)`, is used only by the dispatcher and the outbox relay. Every job these two enqueue carries `workspace_id` and runs its work under the `app` role with `SET LOCAL`. Maintenance jobs iterate workspaces explicitly. No other code path uses `system`.

---

## H16: A reused Dataset is mutable shared state (S2)

**Units:** *Wizard & mapper* editing Block A vs the same epic editing Block B (and *Lifecycle* at publish).

**Gaps.** §7.2: a Dataset is "reused when the same Endpoint and Record Path are chosen again", and it holds `override type` and `default role`, which are editable. Publish writes "dataset fingerprint" inside the Blocks transaction (§5.1), which is a cross-module write. If Admin 1 overrides a field type while Admin 2 is drafting Block B on the same Dataset, B's preview changes underneath it. The drift check compares against "the Dataset shape fingerprint of each subscribed Block Version" (§6.6), but the Dataset's fingerprint is overwritten on every publish. A race on find-or-create also creates duplicate Datasets unless `(endpoint_revision, record_path)` is unique.

**Proposed rule (add to AD-11)**

> - A Block Version snapshots the field catalog it uses (paths, effective types, roles) and its shape fingerprint into `config.fields`, and never reads mutable Dataset state at runtime. `datasets` is a suggestion catalog only. It is unique on `(workspace_id, endpoint_id, record_path)`, written only by `Datasets\Contracts\UpsertDataset` (idempotent upsert), and edits to it affect only new Drafts.

---

## H17: `user_attributes` definitions and membership-removal cascade (S2)

**Units:** *Lifecycle & access* (Access owns `user_attributes(membership, key, value)`) vs *Settings* (`workspace_settings.context attributes`, §7.2) vs *Connector & sync* (Endpoint bindings `user_context.<attr>`).

**Gaps.** The attribute *key catalog* is in Settings, and the *values* are in Access. A team renaming a key in Settings orphans the values and silently breaks bindings. A binding to an undefined key resolves to null, so the Fetch Key has no context and becomes shared data, which leaks per-user data. §7.3: removing a membership "deletes `user_attributes` and the user-context `sync_targets` and their payloads". That is three modules' tables written by whichever team builds removal.

**Tightened AD-4 Rule (add)**

> - Access owns both the attribute key catalog (`user_attribute_keys`) and the values. Keys are immutable identifiers with mutable labels. A binding to an unknown key, or a membership missing a bound value, fails closed (`access.context_missing`, block state `error`), never as unbound. Membership removal emits `access.membership.removed`; Ingestion and RawStore delete their own rows in response.

---

## H18: Item state produced on two surfaces (S2)

**Units:** *User dashboards* (Inertia page, §5.2 step 1, returns items as `access_removed` / `unpublished`) vs *User dashboards / results API* (AD-15, also returns `access_removed`).

**Gap.** AD-20 says layout commands go through `/api/v1`, but the page props carry the layout and per-item states. The two sides use different code, so they can disagree after an `access.changed`: the page says the item is fine, and results says `access_removed`.

**Tightened AD-20 Rule (add)**

> - Inertia dashboard props carry only `{dashboard_id, revision}` and the shell. The layout and items (with `access_removed`/`unpublished`) come only from `GET /api/v1/dashboards/{id}`, and data only from `/results`. Both use `AccessEvaluator.canUse`.

---

## H19: Stale threshold: which interval (S2)

**Units:** *User dashboards* (read path computes Stale) vs *Health* (Stale-Block ratio metric, SM-6).

**Gap.** "Stale when `now − data_as_of > 2 × interval`". The interval could be the Block Version's own refresh interval or the target's effective (minimum) interval. A 1-hour Block that shares a target with a Live Block reads Stale after 60 s under one reading and after 2 h under the other.

**Rule (Consistency Conventions)**

> Stale uses the **viewing Block Version's** configured refresh interval, computed only by `Results\Contracts\Freshness::isStale(result, block_version)`. Health and metrics call the same function.

---

## H20: Template layout shape vs dashboard layout shape (S2)

**Units:** *Templates* (the Templates editor stores a layout in `template_versions`) vs *User dashboards* (12-column canonical layout, `compact_order`, clamp).

**Gap.** Templates can't depend on Dashboards, so each team defines its own `{x,y,w,h}` DTO, grid width and size-limit validation. "Create dashboard from Template" then needs a translation layer, and placement rules (H7) differ.

**Rule (add to AD-14)**

> A `Layout` value object (12 columns, `{block_id, x, y, w, h, minimized}`, validation against Block Type size limits) lives in a shared kernel `Platform\Layout`, which Templates and Dashboards both use, along with `Placement`.

---

## H21: Error code namespaces (S3)

**Gap.** Codes are "dotted", but the examples mix module prefixes (`connector.`, `mapping.`) with non-module ones (`validation.stale`, `draft.exists`). Two teams pick `blocks.draft_exists` and `draft.exists` for the same condition.

**Rule (Errors convention)**

> The first segment is the owning module's snake_case name (`blocks.validation_stale`, `blocks.draft_exists`). Each module declares its codes in `Contracts/ErrorCode.php`. The frontend maps codes from a generated TypeScript enum.

---

## Notes on what is solid

- AD-5 (access is not content), AD-8 (change-detection cascade), AD-19 (secrets) and AD-6 (EgressGuard) are tight enough that I could not find two compliant, incompatible builds.
- AD-13's compare-and-set plus the soft lock are well specified for the single-entity case. The holes are where it touches restore (H14) and operations (H3).
- AD-11's "one engine" holds up well. The gaps are around its data (H9, H16), not its execution.

## Recommended order of closure

1. H1 + H2 + H6 together (Results module, result contract, subscriptions). Most other fixes reference them.
2. H4 + H5 (Fetch Key and period). Without them, no epic can be tested end to end.
3. H12 + H13 (outbox and audit grammar). The foundation epic ships these first.
4. H3, H7, H8, H9, H10, H14, H15.
5. The S2/S3 items as convention-table edits.
