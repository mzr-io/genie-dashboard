<?php

namespace App\Modules\Ingestion\Contracts;

/** The scheduled-fetch status of Endpoints, for the Admin screens (Story 2.14). Read-only, in the caller's Workspace transaction. */
interface SyncStatuses
{
    /**
     * @param  list<string>  $endpointIds
     * @return array<string, SyncStatus> by Endpoint id; an Endpoint without a current target is `waiting` (its target is registered when the outbox delivers)
     */
    public function forEndpoints(string $workspaceId, array $endpointIds): array;
}
