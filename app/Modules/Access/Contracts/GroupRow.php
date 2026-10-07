<?php

namespace App\Modules\Access\Contracts;

/** One group of the Workspace with its members. */
final readonly class GroupRow
{
    /**
     * @param  list<GroupMemberRow>  $members  ordered by name, then email
     * @param  string  $createdAt  ISO 8601, UTC
     * @param  string  $updatedAt  ISO 8601, UTC
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $memberCount,
        public array $members,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
