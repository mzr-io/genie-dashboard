<?php

namespace App\Modules\Access\Contracts;

/**
 * One row of the Workspace's user list: a member, or a pending invitation (status `invited`, empty name, no
 * last activity). Only these fields exist, so nothing else can reach a response.
 */
final readonly class MemberRow
{
    public const MEMBER = 'member';

    public const INVITATION = 'invitation';

    /**
     * @param  string  $id  the row ID (membership ID, or invitation ID for an invitation); used for ordering only
     * @param  string  $kind  `member` or `invitation`
     * @param  string  $role  `user` or `admin`
     * @param  string  $status  `active`, `invited` or `deactivated`
     * @param  list<array{id: string, name: string}>  $groups  the member's groups ordered by name (Story 1.23); always empty for an invitation row
     * @param  string|null  $lastActiveAt  ISO 8601, UTC
     * @param  list<string>  $permissions  catalogue values a member holds (sorted); always empty for an invitation row
     * @param  int|null  $revision  the member's revision (Story 1.22); null for an invitation row
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $name,
        public string $email,
        public string $role,
        public string $status,
        public array $groups,
        public ?string $lastActiveAt,
        public array $permissions = [],
        public ?int $revision = null,
    ) {}
}
