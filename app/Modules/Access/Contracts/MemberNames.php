<?php

namespace App\Modules\Access\Contracts;

/** Resolves membership IDs to the members' display names, for pages that show who did something (never stored as text). */
interface MemberNames
{
    /**
     * @param  list<string>  $membershipIds
     * @return array<string, string> membership ID (lower case) => display name; a membership of another Workspace is absent
     */
    public function names(string $workspaceId, array $membershipIds): array;
}
