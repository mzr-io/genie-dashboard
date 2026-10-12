<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * The user-bound rows of an Endpoint, as references to ask the member's own data for (Story 4.5). Results cannot call Connector, so Ingestion, which
 * can, tells it what {@see Subscribe} will want values for. Read-only, in the caller's Workspace transaction.
 */
interface EndpointUserBindings
{
    /**
     * @return array<string, array{binding: string, key: string|null}> reference (the parameter name, or `header:{name}`) => binding kind and attribute key; empty for shared data or an Endpoint that is not found
     */
    public function forEndpoint(string $workspaceId, string $dataSourceId, string $endpointId): array;
}
