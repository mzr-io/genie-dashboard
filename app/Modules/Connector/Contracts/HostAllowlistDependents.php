<?php

namespace App\Modules\Connector\Contracts;

/**
 * The Data Sources that depend on an allowlist entry, for the removal dialog. No Data Source exists until Story 2.3,
 * which binds the Data Source implementation; until then the null implementation returns none.
 */
interface HostAllowlistDependents
{
    /** @return list<DependentDataSource> the Data Sources of the Workspace whose base URL uses the host and port */
    public function dependentsOf(string $workspaceId, string $host, int $port): array;
}
