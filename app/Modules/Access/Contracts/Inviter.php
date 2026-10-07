<?php

namespace App\Modules\Access\Contracts;

/** The Admin who invites: their user and active membership in the session's Workspace. */
final readonly class Inviter
{
    public function __construct(
        public int $userId,
        public string $membershipId,
        public string $workspaceId,
        public string $workspaceName,
    ) {}
}
