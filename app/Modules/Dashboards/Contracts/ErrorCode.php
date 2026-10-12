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
}
