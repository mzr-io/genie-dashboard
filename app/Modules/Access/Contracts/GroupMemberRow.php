<?php

namespace App\Modules\Access\Contracts;

/** A member of a group, as the Groups view lists it. */
final readonly class GroupMemberRow
{
    /**
     * @param  string  $status  `active` or `deactivated` (a deactivated member keeps their groups)
     */
    public function __construct(
        public string $membershipId,
        public string $name,
        public string $email,
        public string $status,
    ) {}
}
