<?php

namespace App\Modules\Dashboards\Contracts;

use App\Modules\Results\Contracts\RefreshOutcome;
use App\Platform\Period\InvalidPeriodInput;

/**
 * A member's own dashboards (Story 4.4). Every call runs in the caller's Workspace transaction and is scoped to one member: another member's
 * dashboard and another Workspace's are the same {@see DashboardNotFound}.
 */
interface Dashboards
{
    /** The member's Overview, created on first use (empty, `revision` 0). Idempotent under concurrent first opens. */
    public function overview(string $workspaceId, string $membershipId): DashboardRef;

    /** @throws DashboardNotFound */
    public function find(string $workspaceId, string $membershipId, string $dashboardId): DashboardView;

    /**
     * `AddItem` (AD-14, Story 4.9): idempotent per Block. A Block already on the dashboard is returned as it is, unchanged, whatever
     * `expected_revision` says. Otherwise the dashboard is held `FOR UPDATE`, the revision checked, the Block placed at the first free position
     * of the Layout kernel at its default size (no other item moves), the revision bumped and `dashboards.dashboard.changed` emitted.
     *
     * @throws DashboardNotFound
     * @throws BlockNotAvailable
     * @throws RevisionConflict
     */
    public function addItem(string $workspaceId, string $membershipId, string $dashboardId, string $blockId, int $expectedRevision): ItemChange;

    /**
     * `RemoveItem` (AD-14, Story 4.9): by Block. A Block that is not on the dashboard is a no-op (`changed` false). A Mandatory item is refused.
     *
     * @throws DashboardNotFound
     * @throws ItemLocked
     * @throws RevisionConflict
     */
    public function removeItem(string $workspaceId, string $membershipId, string $dashboardId, string $blockId, int $expectedRevision): ItemChange;

    /**
     * `SetMinimized` (AD-14): holds the dashboard `FOR UPDATE`, checks `expected_revision`, sets the item's flag, bumps the revision and emits
     * `dashboards.dashboard.changed`. Setting the value it already has still bumps nothing and succeeds (idempotent).
     *
     * @return int the dashboard's revision after the command
     *
     * @throws DashboardNotFound
     * @throws ItemNotFound
     * @throws RevisionConflict
     */
    public function setMinimized(string $workspaceId, string $membershipId, string $dashboardId, string $itemId, bool $minimized, int $expectedRevision): int;

    /**
     * A manual refresh of one item's Block (Story 4.6).
     *
     * @throws DashboardNotFound
     * @throws ItemNotFound
     */
    public function refresh(string $workspaceId, string $membershipId, string $dashboardId, string $itemId): RefreshOutcome;

    /**
     * The current result of every item that can be shown (Story 4.5), read from what is stored: it never contacts a source and never waits for a
     * compute. Each entry is `{item_id, block_id, state, stale, refreshing, data_as_of, last_success_at, stale_at, result_etag, slot_states,
     * render_payload}` with `state` one of `ok`, `empty`, `unavailable`, `error`, `pending`, `unpublished`, `access_removed`; a state that is not
     * `ok`, `empty`, `unavailable` or `error` carries no data. Access is re-checked per item here, not taken from an earlier layout read.
     *
     * @param  string|null  $range  a preset token for the dashboard Date Range; never a date
     * @return list<array<string, mixed>>
     *
     * @throws DashboardNotFound
     * @throws InvalidPeriodInput a range the Blocks do not offer (422)
     */
    public function results(string $workspaceId, string $membershipId, string $dashboardId, ?string $range): array;
}
