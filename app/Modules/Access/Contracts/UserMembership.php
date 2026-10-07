<?php

namespace App\Modules\Access\Contracts;

/** One membership of a user, with the Workspace it belongs to. */
final readonly class UserMembership
{
    public function __construct(
        public string $membershipId,
        public string $workspaceId,
        public string $workspaceName,
        public ?string $workspaceLabel,
        public string $workspaceStatus,
        public string $role,
        public string $status,
        public ?string $lastActiveAt,
    ) {}
}
