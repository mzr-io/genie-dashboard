<?php

namespace App\Modules\Access\Contracts;

/** The Admin who edits a member: their user and active membership in the session's Workspace. */
final readonly class MemberEditor
{
    public function __construct(
        public int $userId,
        public string $membershipId,
        public string $workspaceId,
    ) {}
}
