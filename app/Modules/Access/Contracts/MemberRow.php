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
     * @param  list<string>  $groups  always empty until Story 1.23
     * @param  string|null  $lastActiveAt  ISO 8601, UTC
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
    ) {}
}
