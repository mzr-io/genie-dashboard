<?php

namespace App\Modules\Connector\Contracts;

/**
 * The Workspace's Data Sources (Story 2.3), in the caller's Workspace transaction. A change checks the Base URL's
 * scheme, host and port against the host allowlist ({@see HostAllowlist::isAllowed}) and the `require_https` rule,
 * writes `connector.data_source.created` or `.updated` in the same transaction, and an update compares the caller's
 * `revision` under a row lock and bumps it. Nothing here calls, resolves or connects to any host.
 */
interface DataSources
{
    public function list(string $workspaceId, DataSourceQuery $query): DataSourcePage;

    /** @throws DataSourceNotFound */
    public function find(string $workspaceId, string $id): DataSource;

    /**
     * Whether the Base URL may be used: the allowlist and `require_https` only, so no DNS lookup and no request.
     *
     * @throws InvalidDataSource on `base_url`
     */
    public function checkUrl(string $workspaceId, DataSourceUrl $url): void;

    /** @throws InvalidDataSource */
    public function register(DataSourceActor $actor, DataSourceInput $input): DataSource;

    /**
     * @throws DataSourceNotFound
     * @throws DataSourceRevisionConflict
     * @throws InvalidDataSource
     */
    public function update(DataSourceActor $actor, string $id, DataSourceInput $input, int $revision): DataSource;

    /** The platform ceilings the limits are checked against. */
    public function ceilings(): DataSourceCeilings;
}
