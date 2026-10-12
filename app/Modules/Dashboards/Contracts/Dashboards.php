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
     * `RestoreItem` (AD-14, Story 4.10): puts a removed Block back with its remembered geometry and minimized state. The same checks as
     * {@see self::addItem()}; the item goes to its remembered spot when that is free (clamped to the Block's limits) and otherwise to the first
     * free position at that size, and no other item moves.
     *
     * @param  array{col: int, row: int, width: int, height: string}|null  $geometry
     *
     * @throws DashboardNotFound
     * @throws BlockNotAvailable
     * @throws RevisionConflict
     */
    public function restoreItem(string $workspaceId, string $membershipId, string $dashboardId, string $blockId, ?array $geometry, bool $minimized, int $expectedRevision): ItemChange;

    /**
     * `MoveResizeItems` (AD-14, Story 4.11): `items` names existing items with the geometry they should have. The dashboard is held `FOR UPDATE`, the
     * revision checked, each geometry clamped to its Block's own limits (an item whose Block does not allow resize keeps its size), the layout settled
     * by the Layout kernel (moved items keep their places, what they overlap is pushed down, nothing compacts upward), the revision bumped once and
     * `dashboards.dashboard.changed` emitted. It never creates or deletes an item.
     *
     * @param  list<array{item_id: string, col: int, row: int, width: int, height: string}>  $items
     * @return DashboardView the saved layout
     *
     * @throws DashboardNotFound
     * @throws InvalidLayout an item that is not on the dashboard, or named twice
     * @throws RevisionConflict
     */
    public function moveResizeItems(string $workspaceId, string $membershipId, string $dashboardId, array $items, int $expectedRevision): DashboardView;

    /**
     * `SetCompactOrder` (AD-14, Story 4.12): the order the Blocks stack in on a phone. `order` names exactly the dashboard's items, each once. It is
     * stored apart from the saved desktop positions, which never change; the revision is checked and bumped and `dashboards.dashboard.changed`
     * emitted.
     *
     * @param  list<string>  $order
     * @return DashboardView the saved layout
     *
     * @throws DashboardNotFound
     * @throws InvalidLayout the order is not exactly the dashboard's items
     * @throws RevisionConflict
     */
    public function setCompactOrder(string $workspaceId, string $membershipId, string $dashboardId, array $order, int $expectedRevision): DashboardView;

    /**
     * `SetDateRange` (AD-14, Story 4.15): the dashboard Date Range, remembered per dashboard on the server. `range` is a preset token, `custom`
     * (with the inclusive local dates `from` and `to`) or null to go back to each Block's own default. Only Follow-dashboard Blocks use it. A token
     * that is not a catalogue preset, a date with a preset, or a custom range that is incomplete, not a real date or reversed is an
     * {@see InvalidPeriodInput} and nothing is stored.
     *
     * @return DashboardView the dashboard after the command
     *
     * @throws DashboardNotFound
     * @throws InvalidPeriodInput
     * @throws RevisionConflict
     */
    public function setDateRange(string $workspaceId, string $membershipId, string $dashboardId, ?string $range, ?string $from, ?string $to, int $expectedRevision): DashboardView;

    /**
     * `SetPeriodSelection` (AD-14, Story 4.15): the member's own choice for one Own-selector Block, by Block (null clears it). A Block that is not an
     * Own-selector one, or a token that is not a catalogue preset, is an {@see InvalidPeriodInput}. The dashboard Date Range never resets it.
     *
     * @return DashboardView the dashboard after the command
     *
     * @throws DashboardNotFound
     * @throws ItemNotFound the Block is not on the dashboard
     * @throws InvalidPeriodInput
     * @throws RevisionConflict
     */
    public function setPeriodSelection(string $workspaceId, string $membershipId, string $dashboardId, string $blockId, ?string $selection, int $expectedRevision): DashboardView;

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
     * @param  list<string>|null  $items  only these item ids (a client refetching what changed); null for every item
     * @return list<array<string, mixed>>
     *
     * @throws DashboardNotFound
     * @throws InvalidPeriodInput a range the Blocks do not offer (422)
     */
    public function results(string $workspaceId, string $membershipId, string $dashboardId, ?string $range, ?array $items = null): array;
}
