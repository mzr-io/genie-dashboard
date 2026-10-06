---
title: 'Review: data integrity and concurrency lens'
reviewed:
  - ../ARCHITECTURE-SPINE.md
  - ../SOLUTION-DESIGN.md
date: 2026-10-05
lens: data-integrity / concurrency / ordering / precision
verdict: 'Sound direction (immutable raw tier, CAS drafts, materialized reads), but the sync-to-result pipeline has no ordering or fencing model, and several keys and definitions (Stale, result key, generation, raw uniqueness) are wrong or undefined in ways that will show wrong numbers. P13 ("never show a wrong number") is not yet met.'
---

# Review: data integrity and concurrency lens

**Verdict.** The overall shape is right: an immutable raw tier, recomputable derived results, CAS on drafts, and a server-owned layout. But the pipeline from fetch to result has **no monotonic ordering or fencing model**. Several core definitions are wrong or missing: when a result is Stale, what the result cache key contains, what a "sync generation" is, and how raw payloads are unique. As written, the design can show numbers that are wrong, out of date, or from the wrong period, without any error. That breaks P13 ("never show a wrong number"). None of this is hard to fix, but the fixes need to land in the spine before stories are written.

Severity scale: **Critical** = wrong numbers shown silently, or data loss on a normal path. **High** = wrong or lost state under realistic concurrency or failure. **Medium** = a narrower race, a gap in evolution, or an operational hazard. **Low** = hardening.

---

## Critical

### DI-1 — Stale is computed from `data_as_of`, so data that is healthy but unchanged shows as Stale
- **Where:** SD §6.4 ("Stale when `now − data_as_of > 2 × interval`"), AD-8 (a 304 or unchanged hash keeps `data_as_of`), FR-61.
- **Problem:** AD-8 deliberately leaves `data_as_of` alone when a 304 comes back or the hash is unchanged. Take a source that changes rarely, such as monthly figures. It is checked successfully every 5 minutes, yet `data_as_of` stays weeks old, so the Block goes **Stale for good** while sync is healthy. The opposite case is also wrong. A target that went cold and was then reopened shows Stale at once, even though an interactive refresh is already queued.
- **Fix:**
  - Define two separate clocks:
    - `data_as_of` = when the platform **first observed** this content, or the source `Last-Modified` when present. It is shown to the user.
    - `last_success_at` = the last successful check, 200 or 304.
  - Stale = `now − last_success_at > 2 × effective_interval(fetch_key)`, where the interval is the Fetch Key's effective interval and not the viewer Block's own.
  - Add a `refreshing` state for a cold target that was woken and has a sync in flight. Do not show Stale for it.
  - For a primary-plus-comparison result, freshness = the older of the two `last_success_at` values.
  - Write every timestamp with the DB clock (`now()`), not the worker's clock. This matters because Stale compares timestamps written by different roles.

### DI-2 — The result key leaves out the resolved period and other inputs that only affect compute, so a different period's numbers can be served
- **Where:** SD §7.2 (Valkey key `res:{ws}:{block_version}:{fetch_key}`), §6.2 (Fetch Key = request params only), §8 ("Period filtering … **and**, when a Time field is mapped and the API returns a wider range, a platform-side filter on the Time field"), AD-9 (Block Results keyed by `(block_version_id, fetch_key, payload_hashes)`).
- **Problem:** Suppose an Endpoint has **no** period parameter binding, and the period is applied only by the platform-side Time filter. Then "This month" and "Last month" resolve to the same request params, which give the same `fetch_key` and the **same result key**. Whichever period computed last overwrites the other, and viewers get the wrong period's totals. The same thing happens with other inputs the engine reads but the request does not:
  - the Workspace time zone (date parts and day grouping, FR-25);
  - the comparison window when it is filtered platform-side;
  - per-item `period_selection` without push-down;
  - Workspace settings that change compute (currency);
  - the engine and shaper code version.
- **Fix:**
  - Key results as `res:{ws}:{block_version}:{fetch_key(s)}:{compute_ctx}`, where `compute_ctx = sha256(resolved_period_window, comparison_window, workspace_tz, compute-affecting settings revision, engine_version, shaper_version)`.
  - Add an architecture test that the render payload is **locale-free**: no pre-formatted numbers or dates. Text Templates (FR-26) must either be rendered on the client or add `locale` to `compute_ctx`.
  - If a Text Template or label is formatted on the server, the viewer's locale and time zone must be in the key.

### DI-3 — Decimal precision is lost before `BcMath\Number` ever sees the value, and the content hash can hide real changes
- **Where:** AD-11 (decimal arithmetic), SD §6.3 ("canonical JSON uses … normalized numbers"), conventions ("API values as JSON numbers or decimal strings"), AD-12 (`symfony/json-path` evaluates over decoded PHP arrays).
- **Problem:**
  1. `json_decode` turns every non-integer number into a PHP `float`, and integers above `PHP_INT_MAX` into floats as well (`JSON_BIGINT_AS_STRING` covers only big integers, not decimals). A value like `12345678901234567.89`, or any decimal with more than about 15 to 17 significant digits, is rounded **before** bcmath runs. `BcMath\Number` accepts `string|int`, so code will write `new Number((string)$float)`, which produces the float's shortest repr and not the source digits. That makes "exact sums" an illusion.
  2. Doing "normalized numbers" in the content hash through floats means that two payloads differing only past the float precision **hash the same**. AD-8 then reports "unchanged", and the real change is never stored or computed. That is silent data loss.
  3. PostgreSQL `jsonb` stores numbers as exact `numeric`, but reading the row back through PDO and `json_decode` loses precision again.
  4. Results sent to the browser as JSON numbers are parsed by `JSON.parse` into IEEE doubles, which loses precision a third time.
- **Fix:**
  - Use a **number-lexeme-preserving JSON decoder** on every server path (sync hashing, raw read, engine, preview). It returns numeric tokens as strings or as a `DecimalLiteral` value object. Never use plain `json_decode` for payload bodies. Enforce this with an arch test that bans `json_decode` in Ingestion, RawStore and Mapping.
  - The path evaluator must work on that tree. If `symfony/json-path` needs native arrays, wrap the numbers or use our own subset evaluator (the subset is small: members and `[*]`).
  - Canonical hashing normalizes only whitespace and key order. Number **lexemes** are hashed as received, or normalized by a lossless decimal canonicalizer (strip `+`, lower-case `e`, remove trailing zeros through string maths), never through a float.
  - Specify a single scale and rounding policy: an internal scale (for example 20), banker's rounding or half-up, applied **only** at presentation, and division scale for ratios and AVG.
  - The API sends measures as **decimal strings**. Renderers format them with `Intl.NumberFormat.prototype.format(string)`, which takes exact decimal strings in current engines. ECharts gets numbers only for plotting geometry, never for displayed labels.

---

## High

### DI-4 — Compute writes have no order: an older payload can overwrite a newer result, and "unique" jobs can drop the newest change
- **Where:** SD §5.3, §14 (`compute` "Unique per (version, Fetch Key)"), §5.2 step 3 (inline compute on a miss in `web`).
- **Problem:**
  - With Laravel `ShouldBeUnique`, a `PayloadChanged(B)` that arrives while the job for A is queued or running is **discarded**. The result stays on A until the next change, which for slow-changing data may never come.
  - With `ShouldBeUniqueUntilProcessing`, the jobs for A and B can run at the same time. If A finishes last, it overwrites B.
  - The inline compute in `web` on a miss races with worker compute in the same way.
  - Nothing compares generations when writing to Valkey.
- **Fix:**
  - Give each `sync_target` a monotonic `payload_seq`, bumped in PG in the same transaction that changes the current payload pointer (see DI-6).
  - Compute jobs carry `(block_version_id, fetch_key)` only. They read the **current** pointer when they start, and write to Valkey through a Lua CAS: write only if `incoming.seq > stored.seq`. The same applies to the inline compute.
  - After writing, re-read the pointer. If it moved, re-enqueue. This gives "latest wins" with debounce semantics.
  - Use `ShouldBeUniqueUntilProcessing` together with the CAS, never `ShouldBeUnique` on its own.

### DI-5 — If the dedupe lock expires during a slow fetch, two runs proceed and the older response can win
- **Where:** AD-7 ("One in-flight sync per Fetch Key"), SD §6.2 (Valkey lock `sync:{fetch_key}` "lasts for the run"), §6.4 (retries with backoff inside the interval), §14 (dispatcher `FOR UPDATE SKIP LOCKED`, which holds only for the dispatcher's own transaction).
- **Problem:** A run's worst case is `max_pages × total_timeout × max_attempts + backoff + Retry-After`, and it is unbounded relative to any fixed lock TTL. When the lock expires, a second run starts, from the next dispatcher tick or from a viewer's interactive cold-miss sync, which goes **around** the dispatcher. Run 1 started first but finishes last. It then stores an older body as "latest" and overwrites `etag`, `last_modified` and `content_hash` with older values. The next conditional request then gets a `200` again, or worse, a `304` against stale state. A Valkey lock is not a correctness primitive, and a Valkey failover can also drop it.
- **Fix:**
  - Fence in PG. The dispatcher, or the interactive enqueue, does `UPDATE sync_targets SET dispatch_seq = dispatch_seq + 1 … RETURNING dispatch_seq`, and the job carries that sequence number.
  - The worker commits the payload, the conditional state and the pointer in **one transaction** guarded by `WHERE id = ? AND applied_seq < :dispatch_seq`, then sets `applied_seq = :dispatch_seq`. A late or older run commits nothing and records `sync_runs.status = superseded`.
  - Keep the Valkey lock only as an optimization. Give it a heartbeat that extends the TTL while the run is active.

### DI-6 — The `raw_payloads` unique key `(sync_target_id, content_hash)` cannot exist on a monthly-partitioned table, and "latest" is ambiguous after A→B→A
- **Where:** SD §7.2 ("partitioned by month … Unique `(sync_target_id, content_hash)`"), AD-9, §13 ("replaced on change").
- **Problems:**
  1. PostgreSQL requires a unique constraint on a partitioned table to **include the partition key**. `(sync_target_id, content_hash)` is therefore rejected. Adding `fetched_at` makes it legal but defeats the deduplication.
  2. Take content that goes A, then B, then A. The change detection in AD-8 compares only against the **last** hash, so it sees the second A as a change. Inserting A then either conflicts, or with `ON CONFLICT DO NOTHING` it leaves the old A row in place, with an old `fetched_at` and `data_as_of`. If "latest" means `ORDER BY fetched_at DESC`, it returns **B**, and the dashboard shows superseded data while reporting a successful sync.
  3. The A row's `data_as_of` is immutable, so it cannot be corrected (see DI-11).
- **Fix:**
  - Make `sync_targets.current_payload_id` (plus `payload_seq`, from DI-4) the **only** definition of "latest". Never derive it from timestamps.
  - Choose one of these options:
    - (a) No content deduplication across generations. `raw_payloads` keyed by `(id, fetched_at)`, with an `observation_seq`, and A stored again on its return. This is simple and keeps `data_as_of` correct.
    - (b) Content-addressed bodies in an unpartitioned `raw_bodies(workspace_id, sync_target_id, content_hash)` table, plus a partitioned `raw_observations(sync_target_id, seq, body_id, observed_at, data_as_of)` table. Retention drops observation partitions and garbage-collects bodies that are no longer referenced.
  - Option (b) works best with windowed retention and with the future history feature.

### DI-7 — Retention can delete the last-known-good copy, and `latest` mode contradicts "drop partitions, not rows"
- **Where:** SD §7.3 ("Partitions older than the window are dropped, not deleted row by row"), AD-9 `latest` / `window(N)`, §13 ("replaced on change"), §7.3 (membership removal deletes payloads).
- **Problems:**
  - Under `window(N)`, a target whose content has not changed for more than N days has its **only** payload in an old partition. Dropping that partition deletes the last-known-good copy. The Block falls to `pending` or `error` after a restart or Valkey flush, which is exactly what C1 says the durable copy is for.
  - `latest` mode means deleting the previous row on each change. That is a row-by-row delete, which contradicts the partition-drop policy.
  - It also races with in-flight compute jobs, old-version previews and ComputeJobs still queued for the previous hash. Those reads fail.
  - Membership removal (GDPR) needs DELETE on rows the immutability rule says are immutable.
- **Fix:**
  - Retention never removes the row referenced by `current_payload_id`, or by the current generation (DI-8).
  - Under `latest`, delete the superseded payloads in a delayed sweep (`superseded_at < now() − grace`, with a grace period longer than the compute job timeout), not inline.
  - Under `window`, use DI-6(b) so the current body survives when its observation partition is dropped.
  - Grant DELETE on the raw tables only to the `maintenance` role. Document that "immutable" means "never UPDATE".

### DI-8 — "One sync generation" for a primary-plus-comparison pair is not defined
- **Where:** AD-15 last bullet, SD §6.2 (the comparison is a **separate** Fetch Key, so it has its own `sync_target`, `next_due_at`, lock and, possibly, a different hot state and interval), P13.
- **Problem:** Two independent targets sync at different times. They have no shared generation identifier. "Never mixed across generations" therefore has no definition that can be implemented. Recording "both payload hashes" in the result describes what was mixed; it does not prevent the mixing. The comparison target can go cold while the primary stays hot, or the reverse. And each `PayloadChanged` triggers a compute with "the latest of the other", which is mixing by definition.
- **Fix:**
  - Model a **sync group**: a primary target and its comparison targets are fetched by **one** FetchJob under one fence (DI-5).
  - Each successful group run writes a `sync_generations(group_id, seq, primary_payload_id, comparison_payload_id, completed_at)` row atomically. Compute reads only complete generations.
  - Give the comparison target the primary's hot state and interval. If you want an optimization, a prior-period comparison can be refreshed less often. In that case, define the rule explicitly: a generation may reuse the previous comparison payload when it is younger than X. That is a deliberate, documented tolerance, not an accident.
  - If the comparison fetch fails, the generation still completes, with the comparison marked `Unavailable` (FR-27b). The primary does not wait.

### DI-9 — Dashboard layout has one `revision` column but no write protocol for its four concurrent writers
- **Where:** AD-14, SD §7.2 (`dashboards.revision`), §12 (mandatory propagation by a `compute` job "at the first free slot after the last row"; clamp "persisted on the next save"), FR-56–FR-58.
- **Writers:** a user `SaveLayout` from tab 1 and tab 2, the `AddBlock` server placement, the mandatory-propagation job, `ResetToTemplate`, and the persisting of the clamp on read.
- **Races:**
  - Two tabs, or two quick Add actions, compute first-free-slot against the same saved layout, and the two Blocks **overlap**.
  - The propagation job appends a mandatory item. Meanwhile a tab holding revision n saves a full snapshot. If the save replaces the item set, the mandatory item is **deleted**. If it does not, the items overlap.
  - A clamp that **grows** an item (a new minimum size) collides with its neighbours. Read-time clamping and the saved layout then disagree.
  - The client-side undo history replays positions captured before the propagation.
- **Fix:**
  - Every layout mutation runs `SELECT … FROM dashboards WHERE id = ? FOR UPDATE`. It checks `expected_revision` for client commands, applies the change, bumps `revision`, and returns the full canonical layout.
  - `SaveLayout` is a **delta**: moves and resizes of the listed item IDs. It never deletes an item it does not mention.
  - On a 409, the client re-fetches, rebases its delta and retries automatically when there is no conflict.
  - Propagation is idempotent: `INSERT … ON CONFLICT (dashboard_id, block_id) DO NOTHING`, under the same row lock, bumping `revision`. It is processed in batches with SKIP LOCKED so it does not hold many dashboard locks at once.
  - The read-time clamp runs the same deterministic collision resolution as placement, so the rendered layout and the next persisted layout are identical.
  - A second `AddBlock` for the same Block returns the existing item (200) instead of failing.

### DI-10 — Ordering at commit time: the publish event fires before the results exist, and `PayloadChanged` is not transactional
- **Where:** SD §5.1 (the publish transaction includes `outbox(block.version_published)`), §5.4 ("compute builds results … **before** emitting `block.version_published`"), §5.3 (`PayloadChanged` dispatched straight to the queue), AD-17 ("dispatcher re-derives due work", which covers fetches only).
- **Problems:**
  1. The diagram in §5.1 and the text in §5.4 contradict each other. If the outbox event is written in the publish transaction, the relay can push `block.version_published` before precompute finishes. Clients then hit a miss, causing N inline computes and the empty state the design said would not happen.
  2. `current_version_id` switches at commit, so every reader misses until precompute is done, whichever event fires.
  3. If `PayloadChanged` is dispatched inside the FetchJob transaction without `afterCommit`, compute may read the previous pointer. If it is dispatched after commit and the worker crashes, or Valkey loses the job, the change is **stranded**: the payload is new, the result is old, and nothing re-derives it, because the dispatcher only re-derives fetches.
- **Fix:**
  - Publish in **two phases**:
    - Transaction 1 inserts the immutable version, with `blocks.current_version_id` unchanged.
    - Precompute results for the new `version_id` over the hot Fetch Keys. Results are keyed by version, so this is safe.
    - Transaction 2 switches `current_version_id` and writes the outbox `block.version_published`.
    - Until transaction 2 commits, readers keep the previous version. If precompute times out, switch anyway; the read path serves the previous version's result with `state=updating` until the new one lands.
  - Make `PayloadChanged` an **outbox event** in the same transaction as the pointer update.
  - Add a reconciliation sweep in `maintenance`: for hot targets where `payload_seq` is greater than the result's stored `seq`, enqueue a compute.
  - Send `result.updated` only after the Valkey CAS write succeeds (DI-4).

---

## Medium

### DI-11 — The immutability trigger conflicts with writes the design itself needs
- **Where:** SD §7.2 (block_versions "Immutable once published (a trigger rejects updates)", the state `superseded`, `draft_sample` "deleted when the Draft is published", `published_by/at`, `shape_fingerprint` set at publish), the raw tier "immutable".
- **Problem:** Publishing is an UPDATE of the draft row (draft to published). Superseding is an UPDATE of a published row. Clearing the sample on publish is an UPDATE. GDPR erasure and retention are DELETEs. A naive trigger blocks all of them. A permissive one ("allow state changes") lets later code smuggle in other edits.
- **Fix:**
  - **Remove the stored `superseded` state.** Derive it: a version is current when it equals `blocks.current_version_id`.
  - The trigger: when `OLD.state = 'draft'`, allow anything. Otherwise reject every UPDATE.
  - The publish transaction first nulls `draft_sample` and sets `published_*` and `shape_fingerprint`, then flips the state, all in the same statement.
  - Better still, move `draft_sample` into a separate `draft_samples` table so that published rows never held it.
  - DELETE only by the maintenance role.

### DI-12 — Publish versus a concurrent autosave, and taking over the lock without fencing
- **Where:** AD-13, SD §5.1, §12 (Take over "waits briefly, then reassigns").
- **Problems:**
  - The publish handler must re-check `validation_report.revision = draft.revision` **inside** the transaction that flips the state (`UPDATE … WHERE id = ? AND state = 'draft' AND revision = :report_revision`). Otherwise an autosave that commits between the check and the flip publishes content that was never validated.
  - After publish, an in-flight autosave from the other tab must get `409 draft.published`, not a new draft created silently.
  - Two tabs each "Edit published" at the same moment: the uniqueness must be a partial unique index on `block_versions(block_id) WHERE state = 'draft'`, plus `blocks.draft_version_id` set under a lock on the `blocks` row. The spine names the index but not its predicate.
  - Take-over:
    - The holder's flush can land **after** the taker has loaded revision n. The taker's first save then gets a 409, and the taker can lose typing.
    - The advisory lock is lost, but the old holder's tab is still at the current revision. It can keep saving successfully, and every save forces the taker into a 409.
- **Fix:**
  - Add a `lock_epoch` column on the draft, in PG. Take-over increments it.
  - Saves must present the current epoch along with `revision`, so the old holder gets `409 draft.taken_over`.
  - The taker loads the draft **after** the flush is acknowledged or times out. The server returns the post-flush revision.
  - Restore with `replace_draft` must bump `revision` and invalidate the ValidationReport in the same transaction.

### DI-13 — The pinned `endpoint_revision` has nothing to pin to, and mutable shared config leaks into published versions
- **Where:** SD §12 (Endpoint "published versions pin `endpoint_revision`"), §7.2 (`endpoints.revision` is a counter on a mutable row; `datasets` and `dataset_fields` are shared and mutable, with type overrides and default roles; `data_sources` with base URL and headers are not in the Fetch Key).
- **Problems:**
  - A worker that rebuilds a request for revision 3 after the row is at revision 4 has no stored copy of revision 3. Pinning needs an immutable `endpoint_revisions` snapshot table.
  - Changing the base URL, a header or the auth on a Data Source keeps the same Fetch Key. That sends the old host's `ETag` or `If-Modified-Since` to the new host, and compares the hash against another source's body.
  - A wizard edit to `dataset_fields.override_type` or `default_role` changes how **published** versions behave at runtime. That breaks reproducibility (AD-9) and immutability.
  - Each revision bump splits Fetch Keys, doubling source load until every Block is republished, and it needs no-op edits (rename, description) to **not** bump the revision.
- **Fix:**
  - Store `endpoint_revisions` (immutable, holding the request-affecting fields only) and add a `data_source_revision` for the request-affecting Data Source fields. Both go into the Fetch Key.
  - Reset the conditional state when either changes.
  - Snapshot the resolved field catalog (types and roles used) into `block_versions.config` at publish.
  - Show the split in source load in Admin impact.

### DI-14 — Schema evolution of immutable JSONB config (contractVersion and Query Plan version)
- **Where:** AD-21 (renderer by `{key, contractVersion}`, versions pin `contractVersion`), §12 (major is "reserved for a Block-Type change"), the `config` JSONB.
- **Problem:** A bump to `contractVersion` cannot migrate stored published configs, because they are immutable. Either every old Shaper and Renderer lives forever, or old versions break. The Query Plan JSON and the expression AST have no version field. Cached results produced by an older Shaper survive a deploy (DI-2).
- **Fix:**
  - Store `config.schema_version` and `query_plan.plan_version`.
  - Ship **read-time upcasters**, pure and chained `vN → vN+1`, covered by golden-file tests over a corpus of real stored configs. Never rewrite rows.
  - Keep at most K old renderer contracts, with an operator report of versions per contract.
  - A breaking change that cannot be upcast needs an Admin re-publish, which creates a new major version through the normal validation.
  - Put `engine_version` and `shaper_version` in the result key.

### DI-15 — The user-context digest is shared across users, so per-user deletion is ill-defined
- **Where:** SD §6.2 (digest = HMAC of the **attribute values**), §11 ("per-user results are never shared"), §7.3 ("Membership removal deletes … the user-context `sync_targets` and their payloads").
- **Problem:** Two users with the same values (for example `region=EU`) get the same Fetch Key, and so they **share** the target, the payloads and the results. That is efficient and correct only if the source API scopes by those values and not by the caller's identity. But "never shared" is false, and deleting user A's "user-context targets" deletes B's last-known-good copy. Attributes can also change mid-flight: a compute started for the old digest can finish and push `result.updated` to a user whose scope has since narrowed. They get IDs only, but a refetch must recompute the key, which it does.
- **Fix:**
  - State the sharing semantics explicitly.
  - Reference-count targets by the distinct digests of active memberships. Delete a target only when its count reaches zero, and delete the user-context values that only that user had.
  - Key the HMAC per workspace, with a rotation plan.
  - If any source scopes by caller identity (per-user tokens), include `membership_id` in the digest for that Endpoint, as a flag.

### DI-16 — Period rollover, DST and the time zone of source timestamps
- **Where:** conventions (period resolved in the Workspace time zone), §6.1 (params canonicalized as ISO dates in the Workspace time zone).
- **Problems:**
  - At midnight, or at the turn of the month, in the Workspace time zone, every relative-period Fetch Key changes at once. Every dashboard misses, gets `pending`, and a thundering herd of syncs hits the sources.
  - DST days are 23 or 25 hours long, so "Today" and "Last 24 h" differ.
  - A Workspace time-zone change silently re-keys everything. With DI-2 unfixed, it serves results computed in the old zone.
  - Source timestamps without an offset have no declared time zone for the platform-side Time filter and for date-part grouping.
  - ISO dates without an offset in the canonical params are ambiguous.
- **Fix:**
  - Pre-warm the next period's keys for hot targets before the boundary, or serve the previous period with `state=updating` until the new key lands.
  - Canonicalize instants with an explicit offset and the zone ID.
  - Add `source_timezone` per Time field on the Dataset, defaulting to UTC with a warning.
  - Bound periods half-open, `[start, end)`, in local time and convert them to UTC instants.
  - Put the Workspace time zone in `compute_ctx` (DI-2).

### DI-17 — Valkey eviction policy mixes data that can be evicted with data that must never be
- **Where:** AD-17 (results, locks, rate limits, queues and Reverb in one store), SD §13 (results TTL ≥ hot window).
- **Problem:** Under memory pressure, `allkeys-lru` evicts queue jobs, locks and OAuth tokens. `noeviction` makes result writes fail with OOM and stops the queues.
- **Fix:**
  - Use separate logical instances, or at least a policy split: queues, locks and limits go on `noeviction`; results go on `allkeys-lru` or `volatile-lru`, with a TTL on every result key.
  - Make this a deployment requirement in the Helm and Compose defaults.

### DI-18 — The inline compute on a miss has no single-flight, and the "hot" index cannot be built
- **Where:** SD §5.2 step 3, §13 (index `sync_targets(next_due_at) WHERE hot`).
- **Problems:**
  - A cold dashboard opened by many viewers runs the same inline compute N times. Fetch Key dedupe protects only the fetch.
  - "hot" is `now() − last_access_at < hot_window`. A partial index cannot use `now()`, which is not immutable.
- **Fix:**
  - Use a per-result single-flight lock in Valkey. Losers poll briefly, or return `pending` and receive `result.updated`.
  - Store `hot_until = last_access_at + hot_window` and index `(next_due_at) WHERE hot_until IS NOT NULL`, comparing `hot_until > now()` in the query. Alternatively, keep a boolean that a sweep maintains.

---

## Low

### DI-19 — Outbox duplicates and ordering
- **Where:** SD §14 ("At-least-once; consumers idempotent by event ID").
- **Problem:** No mechanism is named for this. With several relay workers, events for the same aggregate can arrive out of order. One example is a health transition `degraded → healthy` delivered as `healthy → degraded`, which leaves the wrong final notification.
- **Fix:**
  - Use a unique `notifications(source_event_id)` constraint.
  - Have the relay claim events with SKIP LOCKED and mark them `sent_at`.
  - Give each aggregate a `seq`. Consumers drop events older than the last one they applied.
  - Broadcast invalidations are idempotent by nature, which is fine.

### DI-20 — Access revoked while a result is in flight, and open channels
- **Where:** AD-4, SD §11 ("re-authorize on reconnect").
- **Problem:**
  - A batch request authorizes at its start and then computes inline, which can take seconds. It can return data after the revoke has committed. The window is one request long, and it is acceptable if documented.
  - A revoked user stays subscribed until reconnect and keeps receiving `result.updated` IDs, which leak timing and identifiers only.
- **Fix:**
  - Re-run `AccessEvaluator` just before serializing each item, which is cheap.
  - On `access.changed`, ask Reverb to unsubscribe the affected channels or force them to re-authorize.

### DI-21 — Version numbering and unpublish or archive during compute
- **Problem:**
  - The next `minor` must be computed under the `blocks` row lock, with `UNIQUE(block_id, major, minor)`.
  - A compute for a version that was just unpublished or superseded keeps writing results. The read path checks the lifecycle first, so this is harmless, but it wastes work and sends spurious `result.updated` events.
- **Fix:**
  - Lock the `blocks` row and use the unique constraint.
  - Compute checks `current_version_id = job.version` (or the version is still referenced) before writing and broadcasting.
  - Expire result keys for old versions by TTL. Do not scan for them.

### DI-22 — The draft TTL can expire mid-edit, and the preview cache is keyed per Draft rather than per revision
- **Fix:**
  - The TTL counts from the last autosave, and the heartbeat extends it.
  - Key the preview sample cache by `(draft_id, sample_revision)`, so an earlier preview cannot serve an old sample after a re-paste.

---

## Required spine edits (summary)

1. **AD-7 / AD-8:** a PG fencing sequence (`dispatch_seq` / `applied_seq`). One transaction for payload, conditional state and pointer. Data Source and Endpoint revisions in the Fetch Key. Valkey locks are an optimization only.
2. **AD-9:** `current_payload_id` and `payload_seq` define "latest". Pick raw uniqueness option (a) or (b) (DI-6). Retention never removes the current payload. "Immutable" means no UPDATE, and DELETE is maintenance-only.
3. **AD-11:** a decoder that preserves number lexemes, a canonical hash that never uses floats, a stated scale and rounding policy, and decimal strings to the client.
4. **AD-13:** the publish CAS inside the transaction, `lock_epoch` fencing, a derived `superseded`, and the trigger rule.
5. **AD-14:** the layout write protocol (row lock, revision, delta saves, idempotent propagation, deterministic clamp).
6. **AD-15:** the Stale definition based on `last_success_at`, sync groups and `sync_generations`, the result key with `compute_ctx`, and single-flight on a miss.
7. **AD-16 / AD-17:** `PayloadChanged` through the outbox, two-phase publish, a reconciliation sweep, Lua CAS on result writes, and per-aggregate sequences on outbox events.
8. **AD-21:** `schema_version` and `plan_version`, read-time upcasters, and the shaper and engine version in the result key.
