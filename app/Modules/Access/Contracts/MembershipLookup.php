<?php

namespace App\Modules\Access\Contracts;

/** The one cross-Workspace lookup: every membership of a user (sign-in, Workspace switcher). */
interface MembershipLookup
{
    /**
     * @return list<UserMembership>
     */
    public function forUser(int $userId): array;
}
