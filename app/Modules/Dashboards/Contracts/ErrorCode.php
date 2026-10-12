<?php

namespace App\Modules\Dashboards\Contracts;

/**
 * Error codes owned by Dashboards: `dashboards.{snake_case}`. A generated TypeScript enum (`resources/js/types/error-codes.ts`) mirrors every
 * module's codes.
 */
enum ErrorCode: string
{
    /** A layout command carried a stale `expected_revision` (Story 4.6): HTTP 409, with the current revision. */
    case RevisionConflict = 'dashboards.revision_conflict';
    /** A manual refresh came inside the manual-refresh window (Story 4.6): HTTP 429 with `Retry-After`; nothing was requested. */
    case RefreshLimited = 'dashboards.refresh_limited';
    /** A Block cannot be added (Story 4.9): it has no published version (HTTP 422). A Block the member cannot see is a 404. */
    case BlockUnavailable = 'dashboards.block_unavailable';
    /** `RemoveItem` of a Mandatory item (Story 4.9): HTTP 403, with `msg:locked`. */
    case ItemLocked = 'dashboards.item_locked';
    /** A layout command named an item that is not on the dashboard, or one twice (Story 4.11): HTTP 422, nothing changed. */
    case InvalidLayout = 'dashboards.invalid_layout';
    /** A Date Range or a Block period selection is not one the dashboard or Block offers, or carries a date it may not (Story 4.15): HTTP 422, nothing stored. */
    case InvalidPeriod = 'dashboards.invalid_period';
}
