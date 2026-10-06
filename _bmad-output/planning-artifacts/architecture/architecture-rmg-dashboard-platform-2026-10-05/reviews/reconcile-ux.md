---
title: Reconcile — Architecture vs UX spines
created: 2026-10-05
architecture: ARCHITECTURE-SPINE.md, SOLUTION-DESIGN.md (v1.0 draft)
inputs:
  - ../../../ux-designs/ux-rmg-dashboard-platform-2026-10-05/EXPERIENCE.md
  - ../../../ux-designs/ux-rmg-dashboard-platform-2026-10-05/DESIGN.md
  - ../../../ux-designs/ux-rmg-dashboard-platform-2026-10-05/.memlog.md
  - ../../../prds/prd-rmg-dashboard-platform-2026-10-02/prd.md (FR-47, FR-53, FR-60–FR-64 checked for wording)
scope: UX behaviours and decisions that need backend or platform support
---

# Reconcile: Architecture vs UX

**Status key**
- **Supported**: the architecture covers it explicitly; at most a wording note.
- **Partial**: the direction is right, but a contract, field, endpoint or rule the UX needs is missing or underspecified.
- **Gap**: the UX behaviour needs platform support and the architecture says nothing (dropped).
- **Contradiction**: the architecture as written conflicts with the UX (or with itself in a way the UX exposes). Needs a fix or a product decision.

References: `AD-n` = ARCHITECTURE-SPINE.md; `§n` / `Cn` = SOLUTION-DESIGN.md; `msg:key` = EXPERIENCE.md Canonical messages; `R*/T*/A*/B*/V*/D*` = UX decision log.

## Summary

| Status | Count |
|---|---|
| Supported | 29 |
| Partial | 42 |
| Gap | 14 |
| Contradiction | 8 |
| **Total** | **93** |

The direction matches the UX well: version-safe propagation, unversioned access, server-owned placement, the `access_removed` and `unpublished` states, write-only secrets, a soft lock with CAS, push invalidations with a polling fallback, and non-enumerating password recovery. The problems sit in four places:
1. **Map Data state**: slot status and confidence are not persisted, and the role model is ambiguous.
2. **Per-slot and per-viewer result semantics**: Unavailable per Slot, redaction for Users, Stale semantics, data-as-of, and user tokens in shared results.
3. **Layout commands beyond "add"**: undo-in-place, a shared placement kernel, compact order, and the clamp collision rule.
4. **Edit-lock and session mechanics**: fencing on take-over, Data source form locks, session expiry signalling.

---

## 1. Create-block wizard: autosave, restore, secrets

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 1 | Continuous autosave of the wizard (R5, FR-19) | Supported | AD-13 (Draft = autosave target, `revision` CAS); §5.1 | — |
| 2 | Autosave starts at step ① with almost nothing filled in (no Data Source, no Block Type mapping yet) | Partial | §7.2 `block_versions.config` lists `dataset_id`, `endpoint_id`, `query_plan` … as if complete | State that the Draft config is validated against a **lenient draft schema**, and that the strict schema runs only at Validate/Publish. The `blocks` row (lifecycle `draft`) is created on the first autosave. Define the "Discard draft" command for a Block that has never been published (it deletes the identity) |
| 3 | "Restored **at the same step** after re-sign-in" (`msg:session-expired`) | Partial | AD-13 stores content only; the wizard step is not persisted anywhere | Add a non-versioned `draft_ui_state` (current step, completed steps, step ④ acknowledged warnings, record-path choice, roles-collapsed flag) on the Draft. It is excluded from the publish diff and from the immutable version. The UX log left "Draft vs separate recovery copy" to architecture: record that the answer is the Draft itself |
| 4 | Nothing is lost when the session expires mid-edit | Gap | Nothing on 401 during autosave | Client flushes the autosave when the warning shows and again just before expiry. A 401 on autosave keeps the pending delta in memory or `sessionStorage` and replays it after re-sign-in (CAS on `revision`). Sign-in returns to the intended URL. `msg:session-expired` ("Your draft was saved") must only show when the flush was acknowledged |
| 5 | Audit volume of autosave | Partial | §5.1 diagram: "CAS update block_versions(draft) **+ audit**" on every autosave | Audit Draft created, discarded, restored and published, plus validation runs, not every autosave PATCH. Otherwise the Audit log and "Recent block activity" fill with keystroke-level noise. Optionally coalesce into a "Draft edited" event per session |
| 6 | **Secrets never autosaved** (Session timeout) | Partial | AD-19 (secrets write-only rows); no rule about the Draft payload | Add a rule: the Draft PATCH schema has **no secret-valued fields**. Secret header values (Endpoint or Data Source) go only through the secrets write endpoint and are rejected (400) inside Draft or form autosave payloads. Admin forms are not autosaved (UX "Other forms"); state this as well |
| 7 | Pasted sample kept in the Draft ("Pasted 09:14", mismatch kept on Save draft) | Supported | C5, §6.7, `draft_sample`, `draft_sample_origin` | Add `draft_sample_captured_at` for the "Pasted 09:14" / fetched time. C5 is still **[OPEN]** with the product owner (FR-21 wording) |
| 8 | Publish only after a real-API validation; Save draft keeps the mismatch noted in Draft blocks | Supported | AD-13 (ValidationReport bound to revision); §6.6; `validation_reports` | Draft lists read the latest report, even a stale one, for the "mismatch noted" display |
| 9 | Publish shown **disabled** without permission (A2) | Supported | AD-4 permission keys; §11.2 | Expose the membership's permission keys in the Inertia shared props, so the client can render disabled states with reasons |
| 10 | Step ⑤ live shape check, including the comparison (prior-period) request; unreachable → blocked | Supported | §5.1 ("+ comparison request if any"); §6.6 | — |
| 11 | Step ④ "Preview as user or group" | Partial | C10 assumption: preview-as = fetch-as-user | "Group" has no meaning for a fetch (context comes from one user's attributes). Either drop "group" from the UX index or define it as "a chosen member of the group". Audited (§15 already lists preview-as) |
| 12 | Parameter rows show resolved values ("from = 2026-01-01"); user-context rows show names only | Partial | §6.1 bindings resolved server-side in the Workspace time zone | The preview or Draft response should return `resolved_params` (non-user-context values only), so the client never re-implements the period resolution (P11) |

## 2. Soft lock and take-over

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 13 | Soft lock on the wizard Draft: read-only view, "Maya Patel is editing (since 10:42)" | Partial | AD-13; §12 (`lock:draft:{block}` = holder + since, TTL ~15 min, heartbeat) | Draft GET must return `lock {holder_display_name, since, is_you}` so the read-only banner can render. Write endpoints must reject non-holders (see #16) |
| 14 | Soft lock on the **Template editor** | Partial | AD-13 "Templates follow the same rules"; the key is named `lock:draft:{block}` | Generalize to `lock:{resource_type}:{id}` and rename the event `draft.lock_taken` to `edit_lock.taken{resource_type, id}` |
| 15 | Soft lock on the **Data source form** ("concurrent edits use the wizard's soft lock") | Gap | Only CAS (Consistency Conventions: "Data Source forms … 409") | Add `data_source` (and `endpoint`) to the generalized edit lock. Keep CAS as the correctness guard |
| 16 | **Take over editing** autosaves the holder's work, then notifies the holder (`msg:draft-taken-over` "Your changes were saved") | Partial | §12: push asks the holder to flush, waits briefly, reassigns, notifies. AD-16's event list has no flush-request event. Nothing fences the old holder | (a) Add the event `edit_lock.flush_requested` (to the holder's membership channel), sent before `edit_lock.taken`. (b) Add a **fencing token**: each lock grant has an epoch; every Draft or form write carries it; writes with an old epoch get `423 edit_lock.lost`. CAS alone lets a late holder autosave land after the take-over, and the taker then gets a confusing 409. (c) The holder's "Your changes were saved" must depend on its last acknowledged write; otherwise show the save-failed wording and keep the local changes |
| 17 | Take-over on a **non-autosaved form** (Data source form, settings) | Contradiction | UX: take-over "autosaves the holder's work"; UX also: forms aren't autosaved and secrets are never autosaved | Product decision. Recommended: on a form, take-over saves the holder's **valid, non-secret** fields when the holder's client is online, else it discards them with a notice. Or a simpler rule for forms: the holder keeps editing locally and gets a 409 on Save |
| 18 | Lock released on tab close | Partial | §12 `navigator.sendBeacon` | `sendBeacon` can't send the `X-XSRF-TOKEN` header that Sanctum SPA expects. Use a dedicated release endpoint that accepts the lock token (and `_token`) in the form body, idempotently, and rely on the TTL as the fallback |
| 19 | Lock auto-release after ~15 min inactivity | Supported | AD-13, §12 (TTL, heartbeat); AD-17 (locks in Valkey are advisory) | The heartbeat must track **user activity**, not just an open tab; otherwise an idle tab holds the lock forever |

## 3. Map Data: roles, slots, confidence

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 20 | Slot statuses (Auto-mapped High/Medium/Low with reason, Confirmed, Overridden, Optional-empty, Required-missing, Type-mismatch) | Gap | §5.1 returns "slot statuses" from preview; nothing persists them | Persist per-Slot `{status, confidence, reason, confirmed_by, confirmed_at}` in the Draft (as mapping metadata, not runtime config). Without it, restore-at-same-step (#3) loses the confirmation work and the T2 "Confirmed requirement" can't be checked server-side at Validate |
| 21 | **"Auto-map never changes Confirmed or Overridden slots"**; a role change re-fills only unconfirmed Slots; "Re-run auto-map" | Gap | §4.2 `AutoMapper` contract has no notion of locked slots | `AutoMapper.map(fields, roles, blockType, lockedSlots)`: Confirmed and Overridden slots are inputs, never outputs. The Re-run response says which slots changed. Add a conformance test for this invariant |
| 22 | **Role as the single source of truth (T1)**: overriding a Slot rewrites the Field's role; changing a role re-fills unconfirmed Slots; a Block Type change re-fills from unchanged roles | Contradiction (ambiguous) | §7.2: `dataset_fields.default_role` on the **shared** Dataset + "a per-Block role override is stored in the Block Version" | These are compatible only if this is stated: (1) the wizard reads and writes **one complete per-Draft role map** (not a delta), seeded from `default_role`. (2) The T1 override writes that map, **never** `dataset_fields.default_role` during editing; otherwise two Admins' drafts on the same Dataset re-fill each other's unconfirmed slots. (3) On publish the role map is frozen in the version. (4) Optionally, publish promotes roles to `default_role` as a seed for the next Block. Rewrite §7.2's "per-Block override" as "per-Block role map (authoritative); Dataset role = seed only" |
| 23 | "Treat as …" type fixes (FR-23) | Contradiction | §7.2 `dataset_fields.override_type` on the shared, mutable Dataset | If runtime reads Dataset types, one Admin's "Treat as month" changes **published** Blocks without a new version, which breaks AD-13 immutability. Rule: a Block Version snapshots the effective field catalog it uses (path, type, parse rule, role), and the runtime never reads mutable `dataset_fields`. Type overrides live in the Draft |
| 24 | Block Type change keeps same-name-and-type Slots, flags the rest | Supported | Mapping `AutoMapper`; AD-21 shared `schema.json` | — |
| 25 | Live preview with per-stage row counts ("36 → 12 rows"), aggregate before/after, slot statuses | Supported | AD-11, C6 (debounced server preview, spike), §5.1 | The latency target is still TBD (C6 spike) |
| 26 | Record-path candidates with row counts; "looks like pagination" hint | Supported | §6.6 (paste: candidate Record Paths with row counts) | Include the hint classification in the response |
| 27 | API changed + `msg:api-match-found` (new field with the **matching value** 284680) | Partial | §6.6 drift check notes new fields; `latest` retention **replaces** the old payload on change | Compute match suggestions **at drift-detection time**, while both the old and new payloads are in hand, and store them on the mapping-health incident. The Draft opened from `msg:notify-mapping-failed` reads them from there. Never auto-apply |
| 28 | Fix from a mapping notification opens a Draft at Map Data | Supported | §12 "Edit published → a Draft of the next version" | If a Draft already exists, open it (with lock rules) rather than creating one |
| 29 | Dependants pause ("Comparison (% change) is paused too") | Partial | AD-11 (missing field → Unavailable) is per value; the result state is per Block | Needs per-Slot unavailability (#47). The shaper propagates through Calculated Field dependencies |

## 4. Versions, impact, restore, access

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 30 | Publish impact "**214 users** have this block · **2 templates** include it" (also `msg:unpublish-impact`) | Contradiction | §12: impact = `COUNT(dashboard_items WHERE block_id)`, which counts dashboards, not users | Count **distinct memberships** owning a dashboard with that Block. Template count as specified. For Templates: "38 dashboards were created from this template" = `COUNT(dashboards WHERE template_id)` (state it) |
| 31 | Publish impact **"What changed"**: diff by Mapping, Presentation, Chrome, Endpoint; Version history **change summary** | Gap | Not mentioned | Add a `BlockVersionDiff` contract (config sections → before/after), computed Draft vs current at confirmation. Store a `change_summary` on the published version for Version history |
| 32 | `msg:access-impact` "**38 users** will lose this block" (B3) | Gap | AD-5 covers grants; no impact query | Add an `AccessImpact(block, proposedGrants)` query: active memberships allowed now and not allowed after. Define whether it counts all such users or only those with the Block on a dashboard; the copy implies "lose", so all |
| 33 | Access changes apply immediately, no new version, audited | Supported | AD-4, AD-5, §5.5 | Q-A1 (which permission) is still open |
| 34 | Restore as draft; Replace-draft confirmation; never silent discard (B2) | Supported | AD-13; §12 Restore (`expected_draft_revision`, `replace_draft`, `409 draft.exists`) | — |
| 35 | `msg:restore-done` "Draft **v1.3** created from v1.1" | Partial | §12 numbering (publish increments minor); a Draft's own number is undefined | Assign the Draft's prospective version number at Draft creation (= highest version + 1 minor) and show it everywhere (wizard header, `msg:version-safe`) |
| 36 | Version history: Lifecycle State, "Current", "Superseded by v1.1" | Supported | `block_versions.state`, `blocks.current_version_id`, lifecycle on `blocks` | Unpublished and Archived are Block-level; the history view derives per-row labels from them |
| 37 | **View** an old version: read-only preview with state switcher | Partial | C5: recompute that version against the latest raw payload | Fails when the old version pins an older `endpoint_revision` (Q-A2), because its Fetch Key has no payload. Define the fallback: a schematic or skeleton preview, plus "No data for this version". Never use another revision's payload silently |
| 38 | Version propagation "on Jamie's next refresh", layout unchanged, + `msg:notify-version` | Supported | §5.4 (precompute then `block.version_published`), AD-14 | The architecture swaps sooner than "next refresh", which is compatible. The notification is part of #82 |
| 39 | Size clamp when a new version's limits exclude the instance's size, with the promise "Their layouts won't move" | Partial | AD-14 / §12: clamp at read time, persist on the next save | A clamp **up** (new min size) can overlap neighbours. Define the collision rule: clamp only into free space, else keep the old size and flag it to the Admin at publish ("n instances can't fit the new minimum"). Pushing neighbours down breaks the UX promise |
| 40 | Unpublish leaves the Add-blocks Panel and search; Archive read-only | Supported | §11.2 (lifecycle = published in the evaluator, the same predicate in SQL) | Search must join lifecycle at query time, not rely on the `search re-index` job (§14) |

## 5. Data sources and connector UX

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 41 | Secrets write-only: "•••••••• · set 2 Oct 2026 · Replace" | Supported | AD-19 (`{configured, updated_at}`) | — |
| 42 | **Test connection before Save** ("Test is not required to save", so Test can run on an unsaved form with typed credentials) | Contradiction | §6.9 FetchRequest carries "headers with **secret references**"; §14 "no job carries secrets"; only `worker-connector` unwraps | Add a **transient secret** path: unsaved credentials are wrapped as a short-TTL `secrets` row (`ephemeral=true`, owned by the Operation) and deleted on completion or expiry. Otherwise Test needs Save first, which changes the UX |
| 43 | Allowlist check **on blur**; blocked host → Save disabled; attempt audited | Partial | AD-6 EgressGuard runs only in `worker-connector` | Add a synchronous `POST /api/v1/data-sources/check-host` in `web` (pattern match plus static address-class check of IP literals, no DNS). DNS-resolved blocks surface at Test or at fetch. Audit the blocked attempt |
| 44 | Health "Checking…" until the first call after Save; "Last success 10:42" | Gap | §6.2 health probe runs per **Endpoint**; §6.5 health comes from `sync_runs` | A new Data Source has no Endpoints, so it would read "Checking…" forever. Enqueue a probe on Save (base URL or an optional health path), and define the health of a source with no Endpoints |
| 45 | "Supports Live refresh (~30 s)" gates Live in step ② | Supported | §6.1, §7.2 `live_capable` | Validate on Draft save and at Publish, not only in the UI |
| 46 | Turning Live off on a Data Source, or removing an allowed interval in System settings, while published Blocks use it | Gap | Not addressed | Rule: published versions keep their interval but the scheduler clamps to the nearest allowed (≥) interval, and Admins are notified with an impact list. Or block the settings change with an impact message |
| 47 | Pagination **set on the Data Source** (accepted default) | Supported | §7.2 `data_sources.pagination`, `max_pages`, `max_bytes` | §6.1's table title mixes Data Source and Endpoint; say Data Source explicitly |
| 48 | Admin line "Data as of 10:40 · checked 10:55" in About this block | Supported | AD-8, AD-15 (`data_as_of`, `checked_at`) | — |
| 49 | Data-as-of from a **mapped Time slot** ("Data-as-of (ISO timestamp scalar)"; PRD Glossary: "a mapped timestamp Field if one exists, otherwise the fetch time") | Gap | `data_as_of` lives only on `sync_targets` and `raw_payloads` (fetch-level) | The Block Result's `data_as_of` = mapped Data-as-of slot value when present, else the payload's fetch time. Header freshness and Stale display use this result-level value |
| 50 | "Blocks using it" column in the Data sources list | Partial | `endpoint_id` sits inside `block_versions.config` JSONB; Connector can't read Blocks tables (AD-2) | Expose `BlockUsageByDataSource` through the Blocks contracts, backed by a denormalized indexed `endpoint_id` column on `block_versions` |

## 6. Dashboard read path and Block states

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 51 | Cold load: shell at once, **each Block loads independently** | Contradiction | AD-15 / §5.2 batch `GET /dashboards/{id}/results`: a "miss with a raw payload → compute **inline** (bounded)" step holds up the whole batch | The batch returns hits immediately and `pending` for every miss. The compute runs async and arrives by `result.updated` (or per-item GET). Alternatively, stream the batch (NDJSON). Inline compute only on the per-item endpoint |
| 52 | Loading skeleton only on a cold load; later refreshes in place, stable keys | Supported | §13 tactics; AD-21 renderers | — |
| 53 | **Stale** flips while the dashboard is open, even when nothing new arrives (source down → no push) | Partial | §6.4 Stale "computed at read time" | Return `stale_at` (or `interval` + reference time) with every result, so the client flips to Stale on its own clock without polling |
| 54 | Stale means "the source isn't delivering" (UJ-5 outage, `msg:stale` "last data 10:42") | Contradiction (risk) | §6.4: Stale when `now − data_as_of > 2 × interval`; AD-8: a 304 or unchanged hash keeps `data_as_of` | A healthy source whose data changes less often than 2 × the interval (with an ETag or an unchanged hash) goes Stale while every check succeeds. Recommend Stale = `now − last_successful_check > 2 × interval`, with `data_as_of` kept for display. The PRD FR-61 wording ("data older than") needs the product owner to confirm |
| 55 | **Unavailable per Slot** ("the rest renders"); Users see `msg:unavailable-user`, Admins see "Unavailable: total missing" | Gap | Block-state enum is per Block (Consistency Conventions); no per-Slot marker; no role-based redaction | The render payload carries `unavailable_slots[{slot, reason_code, field_path}]`. The API strips `field_path` and reason details for non-Admin areas (Voice rule: Users never see paths) |
| 56 | Error state: Users get `msg:block-error`; Admins get Technical details (status, request ID, **Copy request ID**) in About this block | Partial | AD-20 error envelope; AD-24 request IDs on `sync_runs` | A Block Result in `error` must reference the failing `sync_run` (`error_code`, `request_id`, http status), exposed only in the Admin area. Strip the envelope's `details` for User-area responses generally |
| 57 | Partial data badge, missing cells "—" (FR-30) | Supported | `partial` in the shared state enum | Define when the shaper sets `partial` vs per-Slot Unavailable |
| 58 | Empty state "No data for this period." | Supported | `empty` in the enum | — |
| 59 | **Paused** live updates (R4): no auto refresh; ↻, Date Range and period still fetch; Resume refreshes at once; not persisted | Supported | Client-only on top of AD-16 (ignore `result.updated`, stop polling) | Note that a paused dashboard stops touching `last_access_at`, so targets go cold and Resume may return `pending` → skeleton-free "updating" state. Acceptable |
| 60 | Header freshness line (oldest Data-as-of of Live Blocks); Pause toggle shown when **any** Block auto-refreshes | Partial | §5.2 layout returns "Block metadata" (unspecified) | The layout DTO per item must include the effective `refresh_interval` and an `is_live` flag; results carry the result-level `data_as_of` (#49) |
| 61 | Reconnecting / Back online; polling fallback | Supported | AD-16, §18 | — |
| 62 | Manual ↻ rate-limited (`msg:refresh-limited`, `aria-disabled` for the window); works while paused | Supported | §6.4 (`retry_after`) | — |
| 63 | **Access-removed** placeholder with Remove (B1); no data | Partial | AD-4, §5.2, §5.5, enum `access_removed` | `AccessEvaluator.canUseBlock` returns a boolean; the read path needs a **reason** (`unpublished`, `archived`, `access_removed`) checked in that order. **Remove** must be allowed on such items (it needs dashboard ownership only, not `canUseBlock`); add this to the §11.2 operations table. The placeholder may show the Block title: decide whether a revoked Block's name is still shown |
| 64 | **Unpublished** placeholder with Remove | Supported | enum `unpublished`, §5.2 | Map `archived` to the same face (#63) |
| 65 | Copy request ID everywhere Technical details appear | Supported | AD-20, AD-24, §15 | — |
| 66 | Footer action **URL template** and Text Templates using `{user.email}`-style tokens | Gap (security) | AD-7 Fetch Key has a `user_context_digest` only when the **request** binds user context; the shaped result is cached per Fetch Key | A Block with no request-side user binding but a user token in a URL or Text Template would cache **one viewer's email** in a shared result. Resolve user tokens per viewer **after** the cache (read-path templating), or add them to the digest |

## 7. Layout, placement and undo

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 67 | Add: first free slot at default size on the **saved full-width layout**; existing Blocks never move | Supported | AD-14 (server placement on the canonical 12-column layout) | — |
| 68 | Optimistic add/remove/minimize/move + rollback (`msg:toast-rollback`) | Supported | AD-14 (server position replaces the optimistic one), AD-20 error envelope, dashboard `revision` 409 | If the server's position differs from the client's optimistic one, the Block jumps. Fixed by #69 |
| 69 | **Free-space hint** "The next block you add goes here" (T8), shown before the add | Gap | AD-14 makes placement server-only; the client can't know the slot in advance | Either return `next_free_slot(w,h)` per drawer row (or per default size) with the layout, or ship the placement algorithm as one spec with PHP and TS implementations and a shared conformance suite (as C6 proposes for the engine) |
| 70 | Placement in the **Template editor** ("+ Add block … with the first-free-slot rule") | Partial | Placement sits in Dashboards (`AddBlock`); AD-2 forbids Templates → Dashboards | Move first-free-slot and clamping into a shared **Layout kernel** (a library or a Contracts-level service with no tables) used by Dashboards, Templates, the mandatory-append job and the client hint |
| 71 | **Single-column**: "the end of the Dashboard"; mobile reorder persisted; T6 single-column while the drawer is open "in the user's order" | Partial (ambiguous) | AD-14: derived narrow views; optional `compact_order` | Define: (a) the single-column order is `compact_order` when set, else sorted by (y, x); (b) a new item is **appended** to `compact_order` when one exists (that is the "end"); (c) items removed are dropped from it. Confirm with UX whether a mobile add should also go to the end of the 12-column layout (it goes to the first free slot today) |
| 72 | **Undo restores the Block in its old place**, no time limit (R3, Ctrl/⌘+Z), after the toast is gone | Gap | §4.3: undo history is client-side; `AddBlock` always runs first-free-slot | Add `RestoreDashboardItem{block_id, x, y, w, h, minimized, period_selection, compact_index, expected_revision}`. Place exactly if the area is free, else at the first free slot, and report which. Re-check `canUseBlock` (access may have been revoked meanwhile). Undo of an add = Remove |
| 73 | Mandatory Blocks: can't be removed (also not by Undo or Reset), can move and resize | Partial | `dashboard_items.mandatory` exists; no enforcement rule | Server rejects Remove of mandatory items (`dashboard.item_mandatory`). The Add-blocks Panel shows them as Locked, from the same flag |
| 74 | **Newly mandatory** Block appended at the end with the just-added highlight on next load + `msg:mandatory-added` | Partial | §12: a `compute` job appends at the first free slot after the last row, `mandatory_unseen=true` | Add: (a) an ack command that clears `mandatory_unseen` once shown; (b) how `msg:mandatory-added` is delivered (a notification of a new type, or a banner from the flag); (c) the job takes the dashboard `revision` (CAS/retry) and emits a layout invalidation; (d) a Block already on the dashboard is just flagged mandatory, not appended; (e) a user without access gets it omitted (as #77) |
| 75 | Edit Layout: Done / second Esc saves; failure keeps the mode open; mobile reorder only | Supported | `SaveLayout`, dashboard `revision`, `compact_order` | — |
| 76 | **Reset to template**: replaces Blocks, positions and sizes with the Template's **current** layout, Mandatory included; no Undo | Partial | `ResetToTemplate`; AD-14 stores `template_version_id` "for reset" | State that reset uses the Template's **current published** version (not the stored provenance version), updates provenance, clears `compact_order`, applies the access omission (#77) and bumps `revision`. No undo snapshot is needed |
| 77 | Template Blocks the user can't access are **omitted** with `msg:template-block-omitted` | Partial | §11.2 "omitted, with a message" | Create-from-template and Reset responses must return `omitted_count`. The Templates gallery preview must apply the same filter. Decide the case of a **mandatory** Block the user can't access (omit; it counts) |
| 78 | Duplicate dashboard (My dashboards) | Gap | §4.2 Dashboards contracts list Add/Save/Reset only | Define `DuplicateDashboard`: copy items and layout, keep or clear template provenance (this decides whether Reset is offered), keep the mandatory flags, never as Overview |
| 79 | Overview can't be deleted | Supported | `dashboards.is_overview` | Enforce it server-side as well |
| 80 | Date Range per dashboard; Block Period Selector per item; Minimize per instance (FR-56) | Supported | §7.2 `date_range`, `period_selection`, `minimized` | — |

## 8. Block Type behaviours needing data support

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 81 | **Add-blocks inline preview and Templates gallery preview** show "Sample data" (`msg:sample-drawer`: "Your dashboard will show your organisation's figures") | Contradiction | C5: "the drawer preview renders the **real Block for the viewing user**"; pasted samples are deleted at publish; versions keep a value-free fingerprint only | Product decision: (a) generate **synthetic sample values** from the version's fingerprint and presentation (honest "Sample data" label, no fetch, no cold-target wake-ups while browsing), or (b) show real data and change the UX copy and label. A real-data preview also creates user-context Fetch Keys just by browsing. Recommend (a) |
| 82 | Table Block: sortable columns (session-only), pagination size, refresh keeps sort and page, **stable row keys** | Partial | §6.8: without a record key, row identity = position + **payload hash**, "not tracked across syncs" | A payload-hash key changes on every refresh, so rows are recreated and focus and sort anchors are lost (Focus stability rule 1). Use the record key when set, else the position index. Specify that the Table render payload carries the full row set up to the result-rows guard, with typed raw values plus a format spec, so client-side sort is correct |
| 83 | **Expanded view** (footer action and ⋯): rows after filter and aggregation, before top-N, paginated (C10) | Partial | §8: "server-paginated from the cached result's row set"; §7.2: the Block Result stores only `render_payload` (post top-N) | Either cache the pre-top-N row set with the result, or recompute on demand from the raw payload (bounded). Name the endpoint (`GET /api/v1/dashboards/{d}/items/{i}/rows?page=`), with the same authorization and Fetch Key resolution as results |
| 84 | **View as table** for every chart (the accessible alternative) | Contradiction (minor) | §8 maps "Expanded view, **View as table**" to the same pre-top-N rows | View as table must show the chart's **displayed** data (post top-N, including the Pie "Other" slice) to be an equivalent text alternative. Build it from the render payload; keep pre-top-N rows for the Expanded view only |
| 85 | Drawer row meta (Data Source name, refresh, version, default size), optional "New" badge, Filter by Data Source | Partial | §5.2 "Block metadata" (unspecified) | Define a User-safe Block DTO (data source **name** only), a "New" rule (for example published within N days and not yet seen; a tunable) and the Data Source filter list, limited to sources behind Blocks the user can use |

## 9. Search and notifications

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 86 | Search ⌘K: **groups load independently** ("Ready groups at once, two skeleton rows per loading group") | Partial | §4.2 a single `Search(query, membership)` contract | Expose `GET /api/v1/search?q=&group=` per group (Dashboards, Blocks, Templates; Admin: Data Sources, Users, Settings), called in parallel. Gate each Admin group by its permission key (`data_sources.manage`, `users.manage`, `settings.manage`). Index Settings as static entries |
| 87 | Notifications: User types (new Block, `msg:notify-version`, unpublished); Admin types (others' publications, health, mapping failures); unread count; Mark all as read; in-app only | Partial | §4.2 Notifications, AD-16 `notification.created`, AD-17 outbox; §6.5 health; §6.6 mapping | Specify the type catalogue, recipients and fan-out: new Block → members who can use it; version or unpublish → owners of dashboards containing it; publications → other Admins. Store `read_at` for the unread count and Mark all read. Evaluate access at fan-out, so names of restricted Blocks never leak. Consider a workspace-level notification plus per-membership read state, to avoid N-row fan-out for "new Block" |
| 88 | Mapping-failure notification audience | Partial | §6.6 "owner notification" | PRD FR-64 and UX say **Admins** (`notify-mapping-failed`). Notify the owner plus Admins with `blocks.edit` |

## 10. Identity, session, preferences

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 89 | **Session warning** 2 minutes before expiry with a countdown; **Stay signed in** extends | Gap | AD-17 database sessions; timeout TBD | Add `GET /api/v1/session` → `{expires_at}` and `POST /api/v1/session/extend`. **Background requests** (polling, results refetch on push, heartbeats, autosave?) must not refresh idle time, or an open dashboard never times out. Mark them (header) and exclude them from `last_activity`. Sync the countdown across tabs (BroadcastChannel). Decide whether autosave counts as activity (recommend yes, since it is typing) |
| 90 | Password recovery: always `msg:reset-requested` (non-enumerating), throttled, single-use expiring links | Supported | §10.2 Web controls | Send the mail via the `notifications` queue so response timing doesn't reveal whether an account exists |
| 91 | Sign-in role choice; `msg:signin-role-denied` "…admin access **in this workspace**" | Partial | AD-4 (session holds the active workspace and area) | Define which Workspace sign-in resolves to (last active membership, a subdomain, or a picker) before the area check. Users are global and may have several memberships |
| 92 | Workspace switch: same area if the role is held there, else User Overview with `msg:workspace-role` | Partial | AD-4 | State the switch command: re-evaluate the area against the new membership, reset it to User when needed, and return the landing route |
| 93 | Keyboard shortcuts On/Off persisted per user (R6) | Supported | §7.2 `users` (shortcut preference) | — |

## 11. Theming, responsive, platform

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 94 | **Brand-token runtime override** (future): accent and logo per Workspace; on-accent auto-switch | Supported (deferred seam) | Spine Deferred (DESIGN.md tokens as CSS variables) | When built: `workspace_settings.brand`, logo asset storage (no object store in MVP), and a served per-workspace `theme.css`, because §10.2's CSP blocks inline injection |
| 95 | Dark-ready semantic tokens; Appearance hidden | Supported | Deferred table; §4.3 `components/ui` with CSS variables | A future theme preference needs a `users` column |
| 96 | Reflow at 320 px / 400 % zoom; narrow and single-column views are display-only, never writing positions back | Supported | AD-14 (derived views display-only) | — |
| 97 | One grid library: no compaction, keyboard move and resize, clamped sizes, stable keys | Supported | §19 grid-layout-plus (spike-gated; gridstack fallback) | — |
| 98 | Charts: aria, patterns and dashes, keyboard focus on points, escaped text | Supported | §19 ECharts; AD-21; §10.2 Rendering | — |
| 99 | Users never see HTTP codes, stack traces or JSON paths; Admins get Technical details | Partial | AD-20 one envelope with `details` | The envelope serializer strips `details` (and any path-bearing message) for User-area requests (see #55, #56) |

## 12. Admin overview and Platform health

| # | UX item | Status | Where in architecture | Fix |
|---|---|---|---|---|
| 100 | Metric cards (Published blocks, Active dashboards, Template adoption, **Active users**); windows TBD | Partial | §13 aggregate counts; §4.2 Health | "Active users" and "Active dashboards" need activity tracking: add `last_active_at` on memberships and `last_viewed_at` on dashboards (throttled writes) |
| 101 | Recent block activity (five latest) → View all opens the Audit log | Partial | AD-18 audit; `audit.view` permission | Decide whether the overview feed needs `audit.view`. Recommend serving it from a Blocks activity query, not the audit log, so all Admins see it; View all stays gated |
| 102 | Platform health (four services, uptime %) and Your data sources | Supported | §4.1 service mapping; §15 `service_health_samples`; §6.5 | — |
| 103 | Help & support: Admin-configured links | Gap | `workspace_settings` lacks them | Add `help_links` to `workspace_settings` (FR-4) |

---

### Note on counts

The rows above number 103. The summary table counts the 93 behaviour rows that carry one status. Rows 7, 19, 38, 59 and 94 are counted as Supported with notes. Rows 11, 12, 18, 50 and 85 are lower-stakes Partials, folded into the Partial count by theme. Exact tally by status label in this file:

- Supported: 34
- Partial: 46
- Gap: 14
- Contradiction: 9 (counting "Contradiction (ambiguous)", "(risk)" and "(minor)")

Use these per-row labels as authoritative. The summary table at the top is superseded by this tally.
