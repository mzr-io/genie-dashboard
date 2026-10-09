<?php

namespace App\Platform\EditLock;

/**
 * The per-resource epoch a module keeps in PostgreSQL for one lockable resource type (for example `data_source`). The
 * kernel is generic over the type and calls no module: the module registers its implementation in
 * {@see EditLockResources}. Both calls run in the caller's Workspace transaction.
 */
interface LockEpochs
{
    /** The resource's current epoch, or null when it does not exist in the Workspace (as the caller sees it). */
    public function current(string $workspaceId, string $id): ?int;

    /**
     * Raises the epoch by one and returns the new value; the epoch never decreases. Null when the resource does not exist.
     */
    public function increment(string $workspaceId, string $id): ?int;
}
