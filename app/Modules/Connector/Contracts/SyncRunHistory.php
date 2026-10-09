<?php

namespace App\Modules\Connector\Contracts;

/**
 * Reads the run history of a Data Source (Story 2.18), for Ingestion's health evaluation, in the caller's Workspace transaction. Only the
 * final `scheduled_fetch` runs count: `succeeded` (a 304 or an unchanged body included) and `failed`. A `retrying`, `skipped` or
 * `superseded` run and a `health_probe` run never do.
 */
interface SyncRunHistory
{
    /** Final runs that started within the last `$windowSeconds`. */
    public function counts(string $workspaceId, string $dataSourceId, int $windowSeconds): RunCounts;

    /** Failed runs since the last succeeded one (all of them when none ever succeeded). */
    public function trailingFailures(string $workspaceId, string $dataSourceId): int;

    /** Whether the Data Source has any final run at all. */
    public function hasFinalRun(string $workspaceId, string $dataSourceId): bool;
}
