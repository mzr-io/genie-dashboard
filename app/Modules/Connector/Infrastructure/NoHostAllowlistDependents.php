<?php

namespace App\Modules\Connector\Infrastructure;

use App\Modules\Connector\Contracts\HostAllowlistDependents;

/** No Data Source exists yet (Story 2.3 binds the real implementation), so nothing depends on an entry. */
final class NoHostAllowlistDependents implements HostAllowlistDependents
{
    public function dependentsOf(string $workspaceId, string $host, int $port): array
    {
        return [];
    }
}
