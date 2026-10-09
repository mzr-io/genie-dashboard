<?php

namespace App\Modules\Ingestion\Contracts;

/** The health of Data Sources, for the Admin screens (Story 2.18). Read-only, in the caller's Workspace. */
interface SourceHealths
{
    /**
     * @param  list<string>  $dataSourceIds
     * @return array<string, SourceHealth> by Data Source id, every given id present; one with no health row yet is `checking`
     */
    public function forDataSources(string $workspaceId, array $dataSourceIds): array;
}
