<?php

namespace App\Modules\Blocks\Contracts;

/** The read of a Block version's frozen configuration for compute (Story 4.2). Read-only, in the caller's Workspace transaction. */
interface PublishedConfigs
{
    /** The version, whatever its state (a caller checks `state`), or null when it does not exist in the Workspace. */
    public function find(string $workspaceId, string $versionId): ?PublishedConfig;
}
