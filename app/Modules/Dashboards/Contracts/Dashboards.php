<?php

namespace App\Modules\Dashboards\Contracts;

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
