---
title: 'Verification: v2 spine (AD-1..AD-34) and SOLUTION-DESIGN v1.1 against the review gate'
checked: [ARCHITECTURE-SPINE.md v2, SOLUTION-DESIGN.md v1.1]
against: [reconcile-prd.md, reconcile-ux.md, reconcile-request.md, review-adversary.md, review-data-integrity.md, review-security.md, review-rubric.md, review-tech-currency.md]
date: 2026-10-05
scope: 'Critical/High/S1 findings; reconcile-ux Gap + Contradiction rows; reconcile-prd missing + contradicts rows; reconcile-request top-10 fixes; tech-currency items tied to the requested old-wording check'
---

# Verify v2

Status: **closed** = v2 resolves it; **partial** = mostly resolved, a named piece is still missing; **open** = not addressed; **routed** = sent to SD §0 / §22 as a product-owner or UX-owner decision (counts as handled).

SD = SOLUTION-DESIGN.md v1.1. Spine = ARCHITECTURE-SPINE.md v2.

## Counts

| Status | Count |
|---|---|
| closed | 68 |
| partial | 11 |
| open | 0 |
| routed | 11 |
| **Total** | **90** |

## Findings

| Source finding id | Status | Where | Remaining fix |
|---|---|---|---|
| ADV-H1 Results module + dependency graph | partial | Spine diagram, AD-2, AD-25 | Results exists and the diagram was regenerated, but the new graph has cycles and missing edges (see New contradictions N1, N2, N4, N5, N6, N7, N12) |
| ADV-H2 Block Result owner/key/envelope | closed | AD-25; SD §13 | — |
| ADV-H3 Operations write-back | closed | AD-28; SD §5.1 | (Edges for Connector/Templates/Audit callers broken; see N4) |
| ADV-H4 Fetch Key canonical form, endpoint revisions | closed | AD-7; SD §6.2, §7.2 `endpoint_revisions` | — |
| ADV-H5 Period resolution owner | closed | AD-27 | (Placement of the owner creates cycles; see N2) |
| ADV-H6 Subscription set and fan-out | partial | AD-25 `result_subscriptions`, AD-16 block channels; SD §4.2 `ViewersOf` | Ingestion reading `EffectiveInterval` inverts the edge (N1); `ViewersOf` lives in Results but the viewer data is `dashboard_items` (Dashboards) — state that Results keeps its own viewer read model fed by `dashboards.dashboard.changed`; subscription row lacks `compute_ctx` (N3) |
| ADV-H7 Layout commands, placement, propagation | closed | AD-14; SD §12 | (Closed command set is incomplete; see N8) |
| ADV-H8 One access predicate | closed | AD-4, AD-2 `RegisterAccessSubject`; SD §11.2 | — |
| ADV-H9 Query Plan vs slot_mapping; contract evolution | closed | AD-11, AD-21, AD-32; SD §7.2 | — |
| ADV-H10 Sync generation undefined | closed | AD-26; SD §6.2 | — |
| ADV-H12 Outbox ownership and grammar | closed | AD-29; SD §4.2 kernel, §14 | (Two event names break the grammar; see N11) |
| ADV-H14 Restore semantics vs trigger | closed | AD-13; SD §12 | — |
| ADV-H15 Cross-workspace jobs vs RLS | closed | AD-3 `system` role; SD §10.1, §14 | — |
| DI-1 Stale from `data_as_of` | routed | AD-15, C12 | Mechanics closed. Still to state: Stale/"refreshing" for a woken cold target with a sync in flight, and freshness of a primary+comparison result (older of the two `last_success_at`) |
| DI-2 Result key ignores period/tz/engine | closed | AD-25 `compute_ctx`, AD-33 locale-free payload | (Worker cannot rebuild `compute_ctx`; see N3) |
| DI-3 Decimal precision / hash via floats | closed | AD-33, AD-8, AD-12 | Optional: name the rounding mode (half-up vs banker's) alongside the `pending_input` scale |
| DI-4 Compute write ordering | closed | AD-26 Lua CAS; SD §14 `ShouldBeUniqueUntilProcessing` | — |
| DI-5 Dedupe lock expiry | closed | AD-26 `dispatch_seq`/`applied_seq` | — |
| DI-6 `raw_payloads` unique key on partitioned table | closed | AD-9 `raw_bodies` + `raw_observations`, `current_payload_id`; SD §7.2 | — |
| DI-7 Retention deletes last-known-good | closed | AD-9; SD §7.3 | — |
| DI-8 Comparison generation | closed | AD-26 sync groups; SD §6.2 | — |
| DI-9 Layout write protocol | closed | AD-14; SD §18 | — |
| DI-10 Publish/PayloadChanged ordering | partial | AD-13 two-phase, AD-26 outbox + sweep | SD §21 says "switch on timeout with the `updating` state", but AD-13 has no timeout rule and AD-25 has no `updating` state. Add the timeout rule to AD-13 and `updating` to AD-25, or delete the §21 wording (N9) |
| SEC-S1 Missing attribute fails open | closed | AD-7, AD-30; SD §6.2, §6.6 negative check | — |
| SEC-S2 Admin reads any user's scoped data | closed | AD-30, AD-31; SD §6.7, §11.2 | Optional dual control in regulated mode not adopted; add to §10.3 if wanted |
| SEC-S3 RLS mechanics | closed | AD-3; SD §10.1 | — |
| SEC-S4 EgressGuard coverage | closed | AD-6; SD §6.1, §6.4, §10.2 | — |
| SEC-S5 Config cache bypasses RLS | closed | AD-3; SD §13 `cfg:{ws}:…` | — |
| SEC-S6 Secrets/PII in telemetry | closed | AD-24, AD-17, AD-6; SD §6.1, §14 | — |
| SEC-S7 ECharts tooltip XSS | closed | AD-21; SD §10.2 | — |
| SEC-S8 No MFA; privilege escalation | routed | AD-31 (grant rules closed), C13 (MFA, step-up) | — |
| SEC-S9 Denial-of-wallet | closed | AD-7, AD-28; SD §6.1, §6.2, §6.4 | — |
| SEC-S15 Valkey as code-execution boundary | partial | AD-17 (TLS, ACL, NetworkPolicy, IDs-only jobs) | Add signed job payloads (HMAC checked before `unserialize`) or `allowed_classes` unserialize to AD-17; IDs-only payloads do not stop gadget-chain injection by a Valkey writer |
| SEC-S18 Key purposes; plaintext attributes | closed | AD-19; SD §4.1 keys per role, §7.2 encrypted attributes | — |
| SEC-S21 Retention and erasure | partial | AD-9 `cold_purge_after`; SD §7.3, §10.2 | Add erasure of a **global** user (all memberships, `users` row, sessions) and of OTel/log data to §7.3 |
| RUB-F1 AD-3 contradicts system processes | closed | AD-3 `system` role, `SECURITY DEFINER` lookup, fail-closed policy | — |
| RUB-F2 Dependency graph leaves flows unowned | partial | Spine diagram, AD-2 (inverted-need rule, table test) | Add Notifications, Search, Health, Settings, Operator and the Http edges to the diagram; fix N1, N2, N4–N7 |
| RUB-F3 Zero-downtime deploys | closed | AD-32; SD §16.2 | — |
| RUB-F4 Persisted JSON evolution | closed | AD-32, AD-21, AD-11 | — |
| REQ-1 (T3) Subscription table | closed | AD-25 `result_subscriptions` | — |
| REQ-2 (T4) Endpoint revisions | closed | AD-7, AD-9; SD §7.2 | — |
| REQ-3 (T1) `raw_payloads` partitioning | closed | AD-9; SD §7.2 | — |
| REQ-4 (S1, S2) Generation and `data_as_of` | closed | AD-26, AD-15 | — |
| REQ-5 (T5) Dataset snapshot | closed | AD-11 `config.fields`; SD §7.2 | — |
| REQ-6 (G8–G11) Invented numbers | partial | SD §6.4 (`max_attempts` TBD), §15 (sampling TBD), §17 (load-test wording) | "TLS 1.2+" (SD §10.2) still unlabelled; the dispatcher period is now a hard 5 s (AD-1, SD §14) and is not in the §22 tunables list. Label `[PROPOSED]` or make both tunables |
| REQ-7 (G6, G7) Labels | partial | Spine tags all ADs; ADOPTED removed | SD §4.2–§4.4, §6.3–§6.5, §13, §14, §17, §18 still carry no status label; add `[ADR AD-n]` / `[PROPOSED]` per section |
| REQ-8 Access impact, FR-39 count, Mandatory vs revoked, Undo-remove | closed | SD §5.5 `AccessImpact`, §12 Impact, C18, AD-14 `RestoreItem` | — |
| REQ-9 (T6) Valkey topology/eviction | closed | AD-17; SD §16.2 | — |
| REQ-10 (G4, G5) Docker/Helm/Inertia/Sanctum/PgBouncer; MVP principle | closed | SD §19, P14 | — |
| PRD FR-4 Help & support links (missing) | closed | SD §7.2 `workspace_settings.help_links` | — |
| PRD FR-43 Chips rule (missing) | partial | SD §11.2 ("category chips use the fragment") | State the rule: chips = distinct categories of published Blocks in `visibleSubjectIds`; decide whether "On your dashboard" Blocks count; index tags in `search_documents` |
| PRD FR-62 Header oldest Data-as-of (missing) | closed | SD §5.2 step 4 | — |
| PRD NFR-8 Browsers (missing) | closed | SD §16.2 Playwright + browserslist | — |
| PRD FR-7/FR-6 Who may change access (contradicts) | routed | Q-A1, C17; SD §11.2 | — |
| PRD FR-21 Pasted sample stored (contradicts) | routed | C5 | — |
| PRD FR-67 Log retention (contradicts) | routed | C15 | — |
| PRD NFR-4 Plain-http egress (contradicts) | routed | C16 | — |
| PRD NFR-5 No historical copies (contradicts) | routed | C1 | — |
| PRD §7 No history in MVP (contradicts) | routed | C1 | — |
| UX-4 Nothing lost on session expiry mid-edit (Gap) | partial | SD §4.3 session dialog; AD-20 background requests | Add: flush autosave on warning and before expiry; on 401 keep the delta client-side and replay after re-sign-in with CAS; show "Your draft was saved" only on an acknowledged flush |
| UX-15 Data source form lock (Gap) | closed | AD-13 generalized lock | (Ownership of the lock module; see N6) |
| UX-17 Take-over on non-autosaved forms (Contradiction) | routed | SD §12, §22 UX-1 | — |
| UX-20 Slot statuses persisted (Gap) | closed | SD §7.2 `draft_ui_state` | — |
| UX-21 AutoMapper respects locked slots (Gap) | closed | SD §4.2, §5.1 | — |
| UX-22 Role as single source of truth (Contradiction) | closed | SD §7.2 per-Draft role map; AD-11 | — |
| UX-23 "Treat as" on shared Dataset (Contradiction) | closed | AD-11 snapshot; SD §8 | — |
| UX-30 Impact counts users (Contradiction) | closed | SD §12 Impact | — |
| UX-31 Version diff / change summary (Gap) | closed | SD §4.2 `BlockVersionDiff`, §7.2 `change_summary` | — |
| UX-32 Access-change impact (Gap) | closed | SD §4.2, §5.5 `AccessImpact` | — |
| UX-42 Test connection before Save (Contradiction) | closed | AD-19 transient secret | — |
| UX-44 Health of a new Data Source (Gap) | closed | SD §6.5 | — |
| UX-46 Live/interval withdrawn (Gap) | closed | SD §6.1 | — |
| UX-49 Data-as-of from mapped slot (Gap) | closed | AD-15 | — |
| UX-51 Batch blocked by inline compute (Contradiction) | closed | AD-15 | — |
| UX-54 Stale semantics (Contradiction) | routed | C12 | — |
| UX-55 Unavailable per slot + redaction (Gap) | closed | AD-25 `slot_states`, AD-20 `details` stripped | — |
| UX-66 User tokens in shared results (Gap) | closed | AD-33 | — |
| UX-69 Free-space hint (Gap) | closed | SD §5.2 `next_free_slot`, §4.2 `NextFreeSlot` | — |
| UX-72 Undo restores in place (Gap) | closed | AD-14 `RestoreItem` | — |
| UX-78 Duplicate dashboard (Gap) | closed | AD-14 `DuplicateDashboard` | — |
| UX-81 Sample data in drawer preview (Contradiction) | routed | C14 | — |
| UX-84 View as table = displayed data (Contradiction) | closed | AD-21, C10 | — |
| UX-89 Session warning (Gap) | closed | AD-20; SD §4.2 Identity session status/extend, §4.3 | — |
| UX-103 Help links (Gap) | closed | SD §7.2 | — |
| TC-3b Sanctum not in kit; Inertia 3 has no axios | closed | Spine Stack; AD-20 typed `fetch` client | — |
| TC-5 "Horizon unique jobs" misattributed | closed | SD §14 | — |
| TC-9 Pest not in kit | closed | Spine Stack; SD §19 | — |
| TC-14 Fortify/Wayfinder/Teams; `password_resets` | partial | AD-31; SD §19 (`password_reset_tokens`, Teams off) | Wayfinder is not mentioned; record keep/remove in Stack and SD §19 |
| TC-15 `schedule:work` in production | closed | AD-1; SD §4.1 | — |
| TC-16 Horizon not compatible with Cluster | closed | AD-17; SD §19 | — |

## Old wording from v1

None remains. `slot_mapping` appears only in "there is no separate `slot_mapping`" (AD-11). `superseded` appears only as a `sync_runs` status and for swept payloads, not as a stored version state. `draft.lock_taken`, `raw_payloads`, `COUNT(dashboard_items`, `password_resets`, "Horizon unique", `schedule:work` and `ADOPTED` are absent from both documents. The §20 ADR table matches every spine tag (AD-1..AD-34).

## New contradictions introduced by v2

| # | Contradiction | Where | Fix |
|---|---|---|---|
| N1 | Ingestion reads `Results\Contracts\EffectiveInterval`, but the diagram allows only Results → Ingestion; the reverse call is a cycle | AD-25, AD-7, spine diagram | Ingestion keeps its own effective-interval read model fed by a `results.subscription.changed` event, or move `result_subscriptions` into Ingestion |
| N2 | `PeriodResolver` is in `Dashboards\Contracts`, but Results (`compute_ctx`), Blocks (preview/validation) and Ingestion (pre-warm) call it; none has an edge to Dashboards, and Results/Ingestion → Dashboards would be cycles | AD-27, AD-25, AD-13 preview, SD §4.2 | Move `PeriodResolver` into the kernel (`Platform\Period`) or a leaf module that every caller may reach |
| N3 | `result_subscriptions(sync_target, block_version, role, last_access_at)` has no resolved period or `compute_ctx`, so a worker compute on `ingestion.payload.changed` can't rebuild the AD-25 key for platform-side-filtered periods | AD-25, SD §5.3 | Add `compute_ctx` (and the resolved period) to the subscription row; key subscriptions by `(sync_target, block_version, compute_ctx)` |
| N4 | Operations are owned by Ingestion, but `connection_test` comes from Connector (Ingestion → Connector exists, so Connector → Ingestion is a cycle), `template_validation` from Templates (no Templates → Ingestion edge) and `audit_export` from the Audit kernel (kernel → module) | AD-28, spine diagram, SD §4.2 | Move the Operations registry into the kernel, or add Templates → Ingestion and route connection tests through Ingestion; move `audit_export` to a kernel or Audit-owned job |
| N5 | Dashboards needs Templates (ResetToTemplate, create-from-template, DuplicateDashboard provenance) and Blocks (Add-blocks Panel, User-safe Block DTO, `refresh_interval`, `is_live`), but the diagram has neither edge | AD-14, SD §5.2, spine diagram | Add `Dashboards → Templates` and `Dashboards → Blocks` (both acyclic) |
| N6 | Edit locks are a Blocks contract (SD §4.2), but Data Source forms (Connector) use them; Connector → Blocks would be a cycle. The error code `blocks.edit_lock_lost` is also used for Template and Data Source locks, against the AD-29 owning-module rule | AD-13, AD-29, SD §4.2, §12 | Move edit locks to the kernel (`Platform\EditLock`) and rename the code `platform.edit_lock_lost` |
| N7 | Two-phase publish: Blocks must trigger Results precompute and wait (no Blocks → Results edge; Results → Blocks exists), and SD §5.1 has worker-compute switching `blocks.current_version_id`, a Blocks table. `BlockImpact` counts dashboard memberships (Dashboards table) with no stated read model | AD-13, AD-2, SD §5.1, §12 | State the choreography: Blocks emits `blocks.version.staged`; Results precomputes, then calls `Blocks\Contracts\ActivateVersion` (tx2). Name the `dashboard_items` read model that Blocks keeps for impact |
| N8 | AD-14 says layout mutations are "only these commands", but the list omits `SetDateRange` (FR-51), create/rename/delete dashboard (FR-49), create-from-template (FR-50) and the mandatory acknowledgement command that SD §12 uses | AD-14, SD §7.2, §12 | Add `CreateDashboard`, `CreateFromTemplate`, `RenameDashboard`, `DeleteDashboard` (rejects Overview), `SetDateRange`, `AckMandatory` |
| N9 | The AD-25 stored `state` enum omits `unavailable`, but AD-7 and SD §18 return item-level `unavailable` and the AD-25 precedence lists it. SD §21 relies on an `updating` state that exists nowhere | AD-25, AD-7, SD §18, §21 | Add `unavailable` to the enum. Either add `updating` plus a precompute-timeout rule to AD-13/AD-25, or remove it from §21 |
| N10 | AD-13 says publish phase 1 "inserts the immutable version", but the Draft is itself a `block_versions` row that the trigger lets you UPDATE while `state='draft'`. Between tx1 and tx2 the new published version ≠ `current_version_id`, so the derived rule calls it "superseded" | AD-13, SD §5.1, §7.2 | Say tx1 flips the Draft row to `published` (not yet current). Derive "superseded" as published, not current and `published_at` earlier than the current version's |
| N11 | Event names break the AD-29 `{module}.{noun}.{past_verb}` grammar: `edit_lock.flush_requested` (no module) and `operations.operation.completed` (Ingestion owns operations) | SD §12, §5.1 | Rename to `platform.edit_lock.flush_requested` (or the lock owner's module) and `ingestion.operation.completed` |
| N12 | The module diagram omits Notifications, Search, Health, Settings and Operator, and Http → Identity/Access/Search/Notifications/Settings. The capability map cites a "Realtime" module that doesn't exist in the seed or SD §4.2. Tenancy is a kernel in the spine (`Platform/…Tenancy`, "kernel: Tenancy") but a module with tables in SD §4.2 and a node in the diagram. AD-10 and AD-23 appear in no capability-map row | Spine diagram, Structural Seed, Capability map; SD §4.2 | Add the missing modules and edges; replace "Realtime" with Notifications + `realtime` role; pick kernel or module for Tenancy; map AD-10 and AD-23 |
| N13 | SD §23 says epics "follow module ownership, so no two epics share tables", but Blocks tables are split across epics 6 and 7, Access across 1 and 7, and `PeriodResolver` (Dashboards) is in epic 4 while Dashboards is epic 9. AD-10 and AD-17 are cited by no epic | SD §23 | Merge epics 6+7 (or split by table), put grants in one epic, move `PeriodResolver` with its module (or into the kernel per N2), add AD-10/AD-17 to epics 1/2 |
| N14 | SD §12 says "Major is reserved for a contract upgrade (C17)", but C17 doesn't list the major-version rule. The SD §11.1 permission diagram omits `access.manage`, which §7.2 lists | SD §0 C17, §11.1, §12 | Add the major-version rule to C17; add `access.manage (Q-A1)` to the §11.1 diagram |
