# Epic 3 Context: Build and Publish Your First Block

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Admins build a KPI Block in a five-step wizard (Configure Block, Data Source, Map Data, Preview, Save/Publish). They get a sample by fetching it or pasting it, Auto-map it with the six field roles, Review, Override, Preview, validate against the real API and publish v1.0. This is a thin slice: only the `kpi-card` Block Type is built end to end, with the Block Type contract v1 and registry. That proves the wizard, mapper, Query Plan engine, lossless decoding, preview endpoint, validation Operation and publish gate early. Richer transforms, calculated fields, comparisons, text templates and the other ten Block Types belong to Epic 5. Dashboards, Results, notifications and Block lifecycle lists, impact and restore come in later epics.

## Stories

- Story 3.1: Register the Block Type contract v1 and the kpi-card package
- Story 3.2: See the KPI card in every state, rendered safely and exactly
- Story 3.3: Start, save and discard a Draft block
- Story 3.4: Configure Block (step 1) with a live preview
- Story 3.5: Autosave continuously and recover from expiry, offline and save failure
- Story 3.6: Soft-lock a Draft and take over editing
- Story 3.7: Choose the Data Source, Endpoint, parameters and refresh (step 2)
- Story 3.8: Paste sample JSON and inspect it with exact numbers
- Story 3.9: Fetch a sample from the API
- Story 3.10: Render the sample through the Query Plan engine (live preview endpoint)
- Story 3.11: Review field roles and choose the record path
- Story 3.12: Auto-map the KPI slots and review the checklist
- Story 3.13: Override a slot, pick a field and fix mismatches
- Story 3.14: Change a slot from the preview with hotspots
- Story 3.15: Set presentation rules for the KPI card slots
- Story 3.16: Preview on every device and state (step 4)
- Story 3.17: Fetch and preview as a user
- Story 3.18: Validate the block against the real API (step 5)
- Story 3.19: Publish block v1.0

## Requirements & Constraints

- The wizard is Admin-only (`blocks.edit` to build, `blocks.publish` to publish). A User-area session gets 403, and the denial is audited.
- Each Slot declares a value type (number, text, date/time, boolean), a cardinality and the Field Roles it accepts. `kpi-card` slots are Title, Subtitle, Icon, Footer action, KPI label, Headline value, comparison value or change %, comparison label, secondary note and status badge. The default size is 3 columns wide, Small height.
- A sample comes from a fetch or a paste. Paste is for inspection only. Sample bodies are never truncated: an over-limit sample fails with an error.
- Auto-map fills each slot with a confidence. Review is a checklist the Admin confirms. Override changes the slot's field and role, with Undo and a type-mismatch warning.
- A missing or mistyped field makes that slot Unavailable, never zero. Empty and null display is configurable.
- The card renders Normal, Loading, Empty, Stale, Unavailable and Error states with exact decimals. Formatting (number, date, direction, thresholds) is described by descriptors and applied in the browser.
- Preview shows Desktop, Tablet and Mobile, a sample grid and each state, with a live preview pane throughout.
- Step 5 validation against the real API gates publishing. A Draft autosaves continuously and recovers after session expiry, offline use or a failed save.
- Preview-as and fetch-as-user need the separate `data.preview_as_user` permission.
- Time-based tunables stay env-driven and `pending_input`.

## Technical Decisions

- **Block Type package:** `block-types/{key}/v{contractVersion}/` holds `schema.json`, a pure `Shaper.php` and `Renderer.vue`. `schema.json` is the single source for the generated TypeScript type and PHP value object, and a CI check fails on drift. A contract directory is never deleted or renamed while a version pins it. The registry resolves a pinned `(key, contractVersion)` and never "latest", and boot fails readably on an invalid slot. `GET /api/v1/block-types` lists only packages that exist.
- **Pure shapers:** Pest architecture tests fail if a Shaper does I/O (database, HTTP, filesystem, clock) or calls `json_decode`.
- **Renderers:** they implement every state. A lint rule bans `v-html`, `innerHTML` and formatter strings. An XSS fixture runs through every slot.
- **Lossless numbers:** payloads are parsed only by the platform's lossless JSON decoder (number lexemes kept as `DecimalLiteral`). `json_decode` is banned in Ingestion, RawStore, Mapping and Results. Arithmetic uses `BcMath\Number`. Measures go to the browser as decimal strings and are formatted with `Intl`.
- **One Query Plan, one engine:** `block_versions.config.query_plan` (versioned `v`) is the only store for mapping, transforms and slots. Fixed stage order: extract, filter, row calc, group, aggregate, aggregate calc, sort, top-N, shape. `config.fields` snapshots the field catalog (paths, effective types, roles, source time zone). A single server-side `QueryPlanEngine` behind the `QueryExecutor` port runs preview, validation and runtime. Guards abort and never truncate.
- **Paths:** stored paths are a strict RFC 9535 subset (member names and `[*]` only). The dotted UI form is display only.
- **Draft and publish lifecycle:** a Block has at most one Draft (partial unique index). Autosave writes are compare-and-set on `revision` and `lock_epoch`, with a lenient draft schema. Strict validation applies at validation time. Publish needs `blocks.publish` and a passing `ValidationReport` bound to the current revision. It runs in two phases: Draft to `prepared` (immutable), then Results precompute, then `published`. A trigger enforces immutability, and `blocks.version.prepared` and `blocks.version.published` are emitted. Samples live in `draft_samples`, never on version rows. Draft and form payloads reject secret-valued fields.
- **Edit lock:** the Epic 1 `Platform\EditLock` kernel serves Draft locks. Take-over increments `lock_epoch` after a flush request, and stale writes get `423 platform.edit_lock_lost`.
- **Operations (AD-28):** sample fetch, preview and validation are interactive Operations. Fetch-as-user and preview-as responses are ephemeral, visible only to the initiator, never written to the shared Draft (only the value-free shape is kept), audited, and the target user is notified. Per-user values are never put in shared results.
- **Conventions:** error codes are prefixed with the owning module (`blocks.draft_exists`). Stored JSON carries `schema_version`, and drafts are upcast on save. All browser traffic goes through `/api/v1` with same-origin Sanctum auth. Modules involved are Blocks, Mapping and Ingestion, with Access registration of the Block as an access subject at publish.

## UX & Interaction Patterns

- The five-step wizard shell has a persistent live preview pane. Step 1 holds display and behaviour options.
- Autosave status is visible, with recovery flows for offline use, expiry and failure. The wizard exposes a flush hook for the session-expiry dialog (Epic 1).
- Soft-lock banners offer a safe take-over to a colleague who is editing.
- Mapping shows field roles, confidence, a confirmable checklist, a field picker, Undo, type-mismatch messages, and click-to-change hotspots on the preview.
- Sample-note and preview-column cues mark values as sample data. Empty states cover "no Data Source" and no sample yet.

## Cross-Story Dependencies

- 3.1 (contract and registry) precedes everything. 3.2 (card states) underpins every preview story (3.4, 3.10, 3.14, 3.16).
- 3.3 (Draft) precedes 3.4 to 3.19. 3.5 (autosave) and 3.6 (soft lock) layer on the Draft. 3.18 and 3.19 rely on the compare-and-set `revision`.
- 3.7 (Data Source and Endpoint choice) precedes 3.8 and 3.9 (sample). Both samples feed 3.10 (Query Plan engine, preview), which in turn feeds 3.11 to 3.15 (field roles, Auto-map, Override, hotspots, presentation).
- 3.17 (preview as user) depends on 3.9 and 3.10. 3.16 follows mapping.
- 3.18 (validation) gates 3.19 (publish v1.0).
- Depends on Epic 1 (RLS, audit, outbox, Access and permissions, Operations, EditLock, shared UI and messages) and Epic 2 (Data Sources, Endpoints, Sample Responses, user attributes and context binding, `worker-connector` fetching).
- Epic 4 (dashboards) renders published versions. Epic 5 extends the Query Plan, transforms and the other Block Types on the same contract and engine.
