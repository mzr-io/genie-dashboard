---
reviewed: ARCHITECTURE-SPINE.md (draft, 2026-10-05)
companion_checked: SOLUTION-DESIGN.md
driving_spec: prd-rmg-dashboard-platform-2026-10-02/prd.md (v3.2.1)
altitude: initiative
lens: good-spine rubric
date: 2026-10-05
---

# Spine Review: Good-Spine Rubric

## Verdict

The spine is strong on the data-path and governance invariants. Most ADs have enforceable rules, and its own content covers the PRD well. It is **not yet build-ready for independent teams**, though, for three reasons. AD-3's RLS rule contradicts the system processes the spine itself requires. The module dependency graph leaves several real cross-module flows unassigned. And the operational envelope (zero-downtime deploys, migrations, evolving JSONB/contract schemas, backups, upgrades, secret rotation, i18n) is missing from the spine: it is decided only in SOLUTION-DESIGN or not at all.

Scorecard:

| Criterion | Result |
| --- | --- |
| Fixes the real divergence points, misses none | Partial: 4 significant misses (F1, F2, F3, F5) |
| Every Rule is enforceable and prevents its divergence | Mostly. AD-3 is self-contradictory; AD-2's table rule is not enforceable by the stated tool; AD-18's retention rule conflicts with FR-67 |
| Nothing under Deferred lets units diverge | One leak: the agent seam (F7) |
| Named tech is verified-current | Yes, backed by research/tech-verification-2026-10-05.md. Minor gaps (F9) |
| Covers the driving spec's capabilities | Yes at map level. NFR-6 and NFR-7/8 are not mapped; the FR-26/FR-29 formatting location is undecided (F5) |
| Every dimension the altitude owns is decided, deferred or open | **No.** The operations envelope is silent (F3, F4); i18n is silent (F5) |
| Terse (decisions, not rationale) | Mostly. A few rationale lines and process rules (F10) |
| Mermaid diagrams valid | **Yes.** All 4 diagrams render with mermaid-cli 12.1.0. Semantic gaps in the module and ER diagrams (F2, F11) |

## Findings

### F1. CRITICAL: AD-3 RLS rule contradicts the spine's own system processes

AD-3 says cross-workspace operations exist **only** in operator commands, which run under a separate role. Several non-operator processes in the spine are cross-workspace by design:

- the sync dispatcher (AD-7, AD-17) scans `sync_targets WHERE next_due_at <= now()` across all workspaces (SD §14);
- the outbox relay (AD-17) drains `outbox_events` for every workspace;
- maintenance retention purges and partition drops (AD-18), health sampling (FR-66), and search re-indexing;
- sign-in and the workspace switcher (FR-1, FR-3). These must read a global user's `workspace_memberships` across workspaces *before* an active workspace exists, and SD §4.2 puts memberships in Access, which makes it a tenant table under RLS;
- Reverb channel auth, which happens before a workspace-scoped transaction.

Under the rule as written, each team will invent its own bypass: BYPASSRLS, iterating per workspace, or a SECURITY DEFINER function. That is exactly the divergence AD-3 exists to prevent. The rule also does not say how a policy behaves when `app.workspace_id` is unset (fail closed?), and it does not say who opens the per-request transaction. Laravel does not wrap reads in a transaction by default.

**Fix.** Amend AD-3:

1. Add a named `system` DB role for scheduler, dispatcher, outbox relay and maintenance. It is limited to a named list of tables and columns: IDs, `next_due_at`, outbox rows. Any domain work it hands off runs in a per-workspace job that sets `app.workspace_id`.
2. Add one `SECURITY DEFINER` function, `memberships_for_user(user_id)`, owned by Access, for the switcher and sign-in.
3. Policies use `current_setting('app.workspace_id', true)` and return no rows when it is unset.
4. A single `WorkspaceTransaction` middleware and job middleware opens the transaction and runs `SET LOCAL`. An architecture test forbids raw connections outside it.

### F2. HIGH: The module dependency graph leaves real cross-module flows unowned (AD-2)

The diagram is the rule, but it omits modules and edges that the spine's own flows require:

- **Block Results have no owning module.** AD-15 needs Dashboards to resolve a Fetch Key, touch `sync_target.last_access_at` and enqueue a sync, which means Ingestion. It also needs the latest raw payload (RawStore) and the engine (Mapping). The graph allows only `Dashboards → Blocks`. It is also undecided whether a cold miss computes inline in `web` or on `compute`.
- **Inverted needs.**
  - FR-39 impact counts: `BlockImpact` in Blocks counts `dashboard_items`, which Dashboards owns, but the graph has `Dashboards → Blocks`.
  - AD-7: Ingestion must know the minimum Refresh Interval of subscribed Block Versions, which Blocks owns, but the graph has `Blocks → … → Connector` and `Ingestion → Connector`.
  - Mandatory propagation (FR-47): Templates writes to Dashboards.
  - Search, Admin overview (FR-65) and Health read across many modules.
- **Missing modules.** Identity, Realtime, Notifications, Search, Health, Settings and Operator are in the Structural Seed but absent from the dependency graph.
- **Enforcement gap.** "Reads and writes only its own tables" cannot be enforced by Pest arch tests, which check namespaces, not SQL.

**Fix.**

- Complete the graph with every module.
- Name the owner of `block_results` and the compute job. Recommended: a `Results` module, or Ingestion.
- State a rule: an upward or inverted need is met only by (a) a domain event consumed by the lower module, or (b) a read model or projection the needing module owns. Examples: Blocks subscribes to `DashboardItemAdded` to keep an impact counter; Ingestion keeps `sync_target_subscriptions` from `BlockVersionPublished`; Search keeps `search_documents` from events.
- Enforce table ownership with an Eloquent-model-namespace arch rule plus a test that fails on cross-module `DB::table()`. Optionally add per-module schema grants.

### F3. HIGH: Zero-downtime deploys and migration strategy are missing from the spine

AD-22 says only "same image, same migrations". Nothing says how a release rolls out while `web`, `worker-*` and `realtime` run mixed versions, and that is where independently built features collide:

- migrations that break running pods: renames and NOT NULL without a default;
- queued job payloads serialized by version N and consumed by N+1 (or N-1 during rollback);
- `FetchRequest/Response`, outbox event and AD-16 push payload compatibility;
- an `/api/v1` response shape change while an old SPA bundle is still open (Inertia asset versioning reloads pages, but not live `/api/v1` clients);
- Horizon terminate and drain, and Reverb reconnect storms.

SD §16.2 mentions only a one-shot `migrate` job.

**Fix.** Add AD-25, "Release compatibility":

- Migrations are expand/contract only. Destructive steps go in a later release, and CI checks this with a migration linter.
- Every release must interoperate with N-1 across DB schema, queue payloads, outbox and push events, `FetchRequest/Response`, and `/api/v1` (additive changes only inside v1).
- Queue jobs carry IDs plus a `v` field. Handlers accept the current version and the previous one.
- The migrate job runs before the rollout. Rollback means redeploying N-1 with no down-migration.
- Workers drain through `horizon:terminate`. Clients reconnect with jittered backoff.

### F4. HIGH: Persisted JSON contracts have no evolution or upcasting rule (AD-11, AD-21)

`block_versions.config` JSONB holds the Query Plan, slot mapping, presentation, chrome and the Block Type `contractVersion`. Published rows are **immutable** (an SD trigger rejects updates), so they can never be migrated in place. AD-21 says renderers register by `{key, contractVersion}` and AD-11 stores a Query Plan, but the spine never says:

- whether the Query Plan, Presentation and Text Template ASTs carry their own `schema_version`;
- what happens when Block Type `kpi-card` moves from contract 1 to 2. Do old shapers and renderers stay forever? Is there a read-time upcaster? Do Drafts get migrated?
- when an old contract version may be retired.

Each Block Type team will choose differently. Some will rewrite JSONB in a migration and violate immutability; others will branch with `if`, which conflicts with the Feature-seams convention. Drafts, Templates' layouts, `FetchRequest` and the dashboard layout JSON have the same problem.

**Fix.** Add the rule to AD-21, or as a new AD:

- Every persisted JSON document carries `schema_version`.
- The owning module provides a chain of pure upcasters (`vN → vN+1`), applied at read time. Immutable rows are never rewritten.
- Drafts are upcast on their next save.
- A shaper or renderer for contract N is removed only after an operator command reports zero Draft or Published versions referencing it.
- Contract changes are additive within a major contract version, and CI runs every stored-fixture config through the upcaster chain.

### F5. MEDIUM: Formatting location and i18n are undecided, and the conventions contradict AD-11 and AD-12

The Conventions table says formatting happens "only in renderers via `Intl`". But:

- FR-26 Text Templates (`{field|currency}`, `{period.start|MMM d}`) and FR-34 URL templates use the AD-12 platform grammar;
- AD-11 and AD-12 say the browser never re-implements the engine or grammar.

So either the server shapes and formats strings, which breaks the convention and needs a locale on the server, or the client parses the template grammar, which breaks AD-11 and AD-12. PHP `Intl` (ICU version) and browser `Intl` can also produce different output.

The locale source is also unspecified. FR-4 has a per-user locale and time zone, and FR-67 has Workspace defaults. Period resolution is pinned to the Workspace time zone, but display formatting is not pinned.

UI-string i18n is entirely silent: whether the MVP is English-only, and how strings are externalized (Laravel `lang/` plus a Vue i18n library). That makes it a whole dimension left undecided.

**Fix.** Add one convention or AD:

- The shaper emits typed values plus format descriptors, plus resolved Text Template *segments*: literal parts and typed tokens. The renderer formats every token with browser `Intl`, using the locale resolved as user locale, then Workspace default, then `en`.
- Server-side formatting is used only for exports and email.
- UI copy is externalized from day one via `vue-i18n` and Laravel `lang/`. The MVP ships `en` only, and translation and RTL are Deferred.

### F6. MEDIUM: AD-18 audit retention cannot meet FR-67 and FR-68 per-Workspace retention

AD-18 drops **monthly partitions** that all workspaces share, while FR-67 and FR-68 require retention configurable **per Workspace**. Dropping a partition can only honor the longest retention. Shorter retentions need row deletes, which neither the app role (INSERT/SELECT only) nor the rule allows.

The phrase "writes its `audit_event` in its own DB transaction" also reads as a *separate* transaction, while the Mutations convention says the *same* transaction.

**Fix.** Choose one:

- (a) a per-workspace row DELETE by the `maintenance` role, with partition drop as an optimization once every workspace's horizon has passed; or
- (b) LIST partitioning by workspace, then RANGE by month.

Reword AD-18 to "in the same transaction as the change it records". Denied-access and sign-in events, which have no domain change, use a standalone transaction.

### F7. MEDIUM: The Deferred agent seam can leak divergence, because FetchRequest does not say whether it carries secret values

AD-6 freezes a versioned `FetchRequest/Response` contract now so the agent can come later. The Structural Seed shows the agent pulling from `web`. AD-19 lets only `worker-connector` unwrap secrets.

If the MVP `direct` transport puts decrypted credentials into `FetchRequest`, which is the natural shortcut, the agent path later needs either `web` to hold plaintext or a contract break. The OAuth2 token cache and the redirect and pagination loop raise the same "who owns it" question.

**Fix.** In AD-6, state that:

- `FetchRequest` carries `secret_ref` plus a `credential_scheme`, never values;
- the transport resolves `secret_ref` at the egress point (worker-connector today; agent-side sealed delivery or an agent-held vault later);
- pagination, redirect re-checks and EgressGuard run inside the transport.

### F8. MEDIUM: The rest of the operational envelope is silent in the spine

Only SD covers these, which leaves teams free to diverge:

- **Backups and restore:** PG PITR is in SD §16.2. The `local` SecretVault keyring must be backed up **separately from and in addition to** the DB, because losing it makes every secret unreadable. A restore drill belongs in the definition of done.
- **Secrets rotation:** KEK and DEK re-wrap is stated. Not stated: `APP_KEY` rotation (`APP_PREVIOUS_KEYS`), DB role passwords, Reverb app secret, and OAuth client secrets for Data Sources, including who triggers each and whether it is audited.
- **Upgrades:** customer-hosted installs make version skew real. The spine needs a supported upgrade path (N-1 to N only, or skip-version), plus PHP, Laravel 14 (expected Q1 2027) and PG major-upgrade policy.
- **Environments and promotion:** these are only in SD.
- **Availability:** NFR-6 RPO and RTO are not in the Capability Map or Deferred.

**Fix.** Add an "Operations envelope" AD, or Deferred rows each with a seam:

- backup scope: PG plus keyring, and Valkey excluded;
- restore drill;
- rotation matrix: which secret, which mechanism, whether it needs zero downtime;
- upgrade compatibility window: N-1 to N, forward-only migrations;
- environment promotion: one image promoted through `ci`, `staging` and `prod`;
- NFR-6 in Deferred, with its seam (stateless roles, PG HA via provider or Patroni-class tooling).

### F9. LOW: Stack and tooling gaps that can cause small divergences

- **AD-21:** `schema.json` is read by TypeScript, but no TS validator or codegen is named (Ajv 2020-12, or `json-schema-to-typescript`).
- **AD-2:** enforcement depends on Pest, but Pest and Larastan versions are not pinned. Node LTS is not pinned either.
- **`symfony/json-path` 8.1:** the component was introduced as *experimental* in Symfony 7.3. Confirm whether it is still outside Symfony's BC promise in 8.1. If it is, pin the exact minor and wrap it behind the AD-12 path port.
- **UUIDv7 source:** the Conventions say `uuidv7()` in PG18, while Laravel `HasUuids` generates v7 in PHP. Pick one; app-side generation is recommended so that IDs exist before insert for outbox and audit.
- **Stack entry "shadcn-vue on reka-ui: current at scaffold":** pin `reka-ui` 2.x.
- **Concurrency convention:** dashboards and layouts are not listed, although SD §12 gives them a `revision` and AD-14's add-block command races with a layout save from another tab.

### F10. LOW: Terseness, rationale and status tags

- The header says ADs are tagged [ADOPTED] or [PROPOSED], but **no AD carries a tag**. PRD-confirmed rules, such as publish permission (FR-40) and REST/JSON only, would therefore read as unconfirmed. Tag each AD.
- **AD-23** is mostly positioning and strategy. "Ad-hoc exploration needs a `bmad-correct-course` scope change" is process, not an architectural invariant. Keep the invariant ("no embedded BI engine; any executor implements `QueryExecutor` over the same Query Plan") and move the rest to SD.
- **AD-9** "Any result can be recomputed… so a remap or restore never refetches" and **AD-22** "No proprietary managed service is required" are consequences or rationale. They are fine in Prevents, but they are not rules.
- **AD-9** is still pending C1, and its rule should say what to build if C1 is rejected. SD's default is `latest` only.
- **AD-15** introduces "sync generation" without defining it. A primary and a comparison Fetch Key (FR-27) are two `sync_targets`. Define a generation as one dispatcher-issued `sync_group_id` that covers both, so the Ingestion and Results teams implement the same thing.
- The **Structural Seed** is appropriately a seed, not a set of rules. Good.

### F11. LOW: Diagram semantics (syntax is valid)

All 4 mermaid blocks parse and render with `@mermaid-js/mermaid-cli` (mermaid 12.1.0): both pipeline and dependency flowcharts, the deployment flowchart and the erDiagram. The quoted subgraph titles with parentheses, `[(...)]` cylinders, `-. label .->` links, `&` inside labels and the quoted ER relationship labels such as `"access (unversioned)"` are all fine.

Semantic gaps:

- **erDiagram:**
  - BLOCK, TEMPLATE and DASHBOARD have no link to WORKSPACE.
  - TEMPLATE_ACCESS_GRANT, BLOCK_RESULT, OPERATION, OUTBOX_EVENT and VALIDATION_REPORT are missing.
  - There is no SYNC_TARGET to BLOCK_VERSION subscription edge, which AD-7 needs.
  - The DASHBOARD_ITEM to TEMPLATE_VERSION provenance link from AD-14 is missing.
- **Dependency flowchart:** see F2.

## Coverage check against the PRD

Everything in FR-1 to FR-68 is mapped in the Capability Map. Gaps:

- **FR-26, FR-29, FR-34 formatting and templating:** the execution location is undecided (F5).
- **FR-3 switcher across workspaces under RLS:** undecided (F1).
- **FR-39 impact counts:** cross-module (F2).
- **FR-63 search:** cross-module indexing (F2).
- **FR-67 and FR-68 per-workspace retention:** conflicts with AD-18 (F6).
- **NFR-6:** not mapped (F8).
- **NFR-7 and NFR-8:** not mapped. That is acceptable at this altitude if a row points to DESIGN.md and EXPERIENCE.md plus the Playwright and axe gate in SD.
- **NFR-11:** partial (F5).

## What is good (keep)

- AD-6, AD-7 and AD-8 together make a precise, testable connector contract: the EgressGuard order, the Fetch Key formula and the change-detection cascade.
- AD-11 has one engine, a fixed stage order, decimal aggregation and the "Unavailable, never 0" rule. These are enforceable and directly prevent preview/runtime drift.
- AD-13 handles lifecycle correctness well: CAS on `revision`, the soft lock as advisory UX only, and validation bound to the Draft revision.
- AD-16 sends IDs only over push and refetches over `/api/v1`, a clean way to avoid an authorization bypass.
- AD-17 makes PG the record and the Redis-protocol store disposable, with the dispatcher re-deriving due work.
- The Deferred table gives every item a named seam. Only one leaks (F7).
