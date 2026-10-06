<?php

namespace App\Platform\Tenancy;

/**
 * The Workspace the current request or job runs in. Only WorkspaceTransaction writes it,
 * and only while its database transaction (and so the database context) is open.
 */
final class WorkspaceContext
{
    private ?string $workspaceId = null;

    public function workspaceId(): ?string
    {
        return $this->workspaceId;
    }

    /**
     * @internal for WorkspaceTransaction
     */
    public function swap(?string $workspaceId): ?string
    {
        $previous = $this->workspaceId;
        $this->workspaceId = $workspaceId;

        return $previous;
    }
}
