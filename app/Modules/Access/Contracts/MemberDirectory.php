<?php

namespace App\Modules\Access\Contracts;

/** The members and pending invitations of one Workspace (User configuration, Story 1.20). */
interface MemberDirectory
{
    /** @throws InvalidMemberCursor */
    public function page(string $workspaceId, MemberQuery $query): MemberPage;

    /** A member of the Workspace by membership ID; null when it does not exist there (another Workspace's is invisible). */
    public function find(string $workspaceId, string $membershipId): ?MemberRow;
}
