<?php

namespace App\Modules\Access\Contracts;

/** A member's role, permissions and revision (Story 1.22), as they are after a change or as they stand now. */
final readonly class AccessChange
{
    /**
     * @param  string  $role  `user` or `admin`
     * @param  list<string>  $permissions  catalogue values, sorted
     * @param  bool  $changed  false for a no-op (same role and set): nothing was written and the revision is unchanged
     */
    public function __construct(
        public string $membershipId,
        public string $role,
        public array $permissions,
        public int $revision,
        public bool $changed = true,
    ) {}
}
