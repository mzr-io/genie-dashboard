<?php

namespace App\Modules\Connector\Contracts;

/**
 * The Endpoints of a Data Source (Story 2.9), in the caller's Workspace transaction. Creating writes the Endpoint, its
 * revision 1 and the pointer in one transaction with `connector.endpoint.created`; every later save writes a new immutable
 * revision, moves the pointer and audits `connector.endpoint.revised` in one transaction. An older revision is never changed
 * and nothing is deleted. A save never changes the Data Source's own `revision`. Nothing here sends a request.
 */
interface Endpoints
{
    /** @throws DataSourceNotFound */
    public function list(string $workspaceId, string $dataSourceId, ?string $search): EndpointPage;

    /**
     * @throws DataSourceNotFound
     * @throws EndpointNotFound
     */
    public function find(string $workspaceId, string $dataSourceId, string $id): Endpoint;

    /**
     * @throws DataSourceNotFound
     */
    public function create(DataSourceActor $actor, string $dataSourceId, EndpointInput $input): Endpoint;

    /**
     * @throws DataSourceNotFound
     * @throws EndpointNotFound
     * @throws EndpointRevisionConflict when `$revision` is not the Endpoint's current one
     */
    public function revise(DataSourceActor $actor, string $dataSourceId, string $id, EndpointInput $input, int $revision): Endpoint;
}
