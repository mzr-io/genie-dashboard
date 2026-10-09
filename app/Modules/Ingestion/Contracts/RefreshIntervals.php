<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * The refresh intervals the Workspace's System settings offer (the `sync.refresh_intervals` pending-input tunable), for the Block wizard's
 * Refresh Interval select (Story 3.7). Read-only. No number is invented: an unset or malformed list is null and nothing is offered.
 */
interface RefreshIntervals
{
    /**
     * Every entry in whole seconds, ascending and without repeats; null when the setting is unset or any entry is not a positive whole number.
     *
     * @return list<int>|null
     */
    public function refreshIntervals(): ?array;
}
