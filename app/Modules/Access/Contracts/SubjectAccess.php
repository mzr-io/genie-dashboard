<?php

namespace App\Modules\Access\Contracts;

/**
 * Whether a member may see Access subjects (Story 4.4; FR-62 re-checked on every read). A subject with no `access_subjects` row, or one that is not
 * visible, is not seen. Mode `all` is everyone in the Workspace; any other mode (groups, Epic 6) is refused until its evaluation exists, so a
 * restriction can only ever hide a Block, never reveal one. Read-only, in the caller's Workspace transaction.
 */
interface SubjectAccess
{
    /**
     * @param  list<string>  $subjectIds
     * @return array<string, bool> by subject id (lower case)
     */
    public function canView(string $workspaceId, string $membershipId, string $subjectType, array $subjectIds): array;
}
