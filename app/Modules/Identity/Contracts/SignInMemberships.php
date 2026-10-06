<?php

namespace App\Modules\Identity\Contracts;

/**
 * The port through which sign-in reads a person's memberships and records the Workspace they used.
 * Identity sits below Access and cannot call it, so Identity declares the port and Access implements it.
 */
interface SignInMemberships
{
    /**
     * Every membership of the user across Workspaces, ordered by Workspace name.
     *
     * @return list<SignInMembership>
     */
    public function forUser(int $userId): array;

    /** Stamps `last_active_at` on the membership, inside that Workspace's own transaction. */
    public function markActive(string $workspaceId, string $membershipId): void;
}
