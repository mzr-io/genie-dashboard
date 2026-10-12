<?php

namespace App\Modules\Results\Contracts;

use App\Platform\Tenancy\TenantKey;

/** The cache key of a Block Result: `{ws}:cache:res:{block_version}:{primary_fk}:{compute_ctx}` (Story 4.2). Every part is an ID, a digest or a fetch key. */
final class ResultKey
{
    public static function of(string $workspaceId, string $blockVersionId, string $primaryFetchKey, string $computeContext): string
    {
        return TenantKey::cache($workspaceId, 'res:'.strtolower($blockVersionId).':'.$primaryFetchKey.':'.$computeContext);
    }
}
