<?php

namespace App\Modules\Ingestion\Contracts;

/**
 * The compute-facing read of sync generations (Story 2.20; AD-26). Read-only, in the caller's Workspace transaction. Only a complete generation is
 * ever returned, so a primary payload that is newer than its comparison payload (or the reverse) is never served as a pair.
 */
interface SyncGenerations
{
    /** The newest generation whose two sides both succeeded in the same run, or null while there is none. */
    public function latestComplete(string $workspaceId, string $syncGroupId): ?SyncGeneration;

    /** Whether the group's newest generation has its comparison side failed: the comparison is `unavailable` until a later run completes it. */
    public function comparisonUnavailable(string $workspaceId, string $syncGroupId): bool;
}
