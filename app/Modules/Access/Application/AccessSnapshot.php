<?php

namespace App\Modules\Access\Application;

/** A membership's role, status, revision and permissions as read under the lock (Story 1.22). */
final readonly class AccessSnapshot
{
    /** @param  list<string>  $permissions  catalogue values, sorted */
    public function __construct(
        public string $role,
        public string $status,
        public int $revision,
        public array $permissions,
    ) {}
}
