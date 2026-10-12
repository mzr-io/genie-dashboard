# Epic 4 Context: Live Personal Dashboards

<!-- Compiled from planning artifacts. Edit freely. Regenerate with compile-epic-context if planning docs change. -->

## Goal

Users see published Blocks on personal dashboards with live, trustworthy data, and can add, remove, move and resize them without drag-and-drop being required. Results are computed off the read path so dashboards never wait on source APIs, and every state (loading, empty, error, Stale, Unavailable, paused, access removed, unpublished) is shown honestly.

## Stories

- Story 4.1: Resolve every Block's period in one place
- Story 4.2: Compute Block Results when payload changes
- Story 4.3: Judge freshness and keep viewed data hot
- Story 4.4: Open my Overview and see my Blocks' frames
- Story 4.5: See live Block values without waiting on the source
- Story 4.6: Use Block chrome (refresh, minimize, About this Block, footer)
- Story 4.7: Browse the Add Blocks panel
- Story 4.8: Preview a Block with generated sample values
- Story 4.9: Add and remove Blocks with automatic placement
- Story 4.10: Undo adds and removes
- Story 4.11: Move and resize Blocks in Edit Layout Mode
- Story 4.12: Move, resize, reorder and shortcut without drag-and-drop
- Story 4.13: Receive live updates through Reverb with a polling fallback
- Story 4.14: Pause live updates and see the oldest Data-as-of
- Story 4.15: Set a dashboard Date Range and Block period selectors
- Story 4.16: Manage my dashboards and switch between them
- Story 4.17: Use the dashboard on any screen size

## Requirements & Constraints

- A Block's period comes from exactly one rule with precedence: the user's stored Block Period Selector choice, then the dashboard Date Range, then the Block's Default date range. Blocks that are not date-filtered ignore all period input.
- Only presets the Block has enabled are accepted. Client-supplied absolute dates are never accepted; both are rejected with 422 and the error envelope.
- Windows are half-open and computed in the workspace timezone. The user timezone is used for display only.
- A missing or mistyped mapped field makes that slot Unavailable, never 0, NaN or blank.
- Dashboards must never wait on a source API: no read-path code calls the source.
- Stale means the source was genuinely not checked within 2 x Refresh Interval. Unchanged data keeps a Block fresh while Data-as-of shows the real age.
- Result state precedence: access_removed > unpublished > error > pending > unavailable > empty > ok. `partial` is derived and `loading` is never stored.

## Technical Decisions

- `Platform\Period\PeriodResolver` returns `ResolvedPeriod{token, start, end, comparison?}`. The Epic 3 preview and validation must call it with the Block default, and an architecture test forbids a second precedence implementation.
- Results are versioned `BlockResult` DTOs in the Valkey `cache` store, keyed `res:{ws}:{block_version}:{primary_fk}:{compute_ctx}`, always with TTLs. `compute_ctx` hashes resolved period, comparison window, workspace tz, settings revision, engine version and shaper contract version. Writes are Lua compare-and-set on `payload_seq`.
- Compute runs on the `worker-compute` role when `ingestion.payload.changed` is consumed. Job payloads carry IDs only and are HMAC-signed. A maintenance sweep re-enqueues lost events.
- Measures are decimal strings from the lossless tree; `json_decode` is banned in Results. A `results.mapping.slot_failed` outbox event is emitted on drift.
- `Results\Contracts\Freshness` is the only place the staleness rule exists. Timestamps come from the DB clock. Viewing a Block registers or touches a `sync_subscriptions` row through `Ingestion\Contracts\Subscribe`, subject to workspace budgets.
- `dashboards` and `dashboard_items` (unique `(dashboard_id, block_id)`) are created by expand-only migrations with `workspace_id` first and RLS. The Overview (`is_overview`) is created lazily per membership with `revision` 0.
- Conventions from earlier epics hold: module-prefixed error codes, `/api/v1` with Sanctum, Inertia pages, outbox events, audit.

## UX & Interaction Patterns

- The Overview opens instantly with Block frames; values fill in from precomputed Results. Edit Layout Mode supports drag-and-drop but must also offer keyboard and non-drag move, resize, reorder and shortcuts.
- Add Blocks panel with generated sample-value previews; add and remove have Undo.
- Live updates arrive via Reverb with a polling fallback, and can be paused with the oldest Data-as-of shown.

## Cross-Story Dependencies

- 4.1 (period) underpins 4.2 (`compute_ctx`) and 4.15 (selectors).
- 4.2 precedes 4.3, 4.5 and 4.13. 4.4 (dashboards tables) precedes 4.5 to 4.17.
- 4.9 precedes 4.10 and 4.11, and 4.11 precedes 4.12.
- Depends on Epic 3 (published block versions, Query Plan engine, preview and validation) and Epic 2 (ingestion, sync targets, payload sequencing).
