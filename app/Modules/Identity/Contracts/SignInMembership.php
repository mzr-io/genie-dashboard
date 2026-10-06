<?php

namespace App\Modules\Identity\Contracts;

use Carbon\CarbonImmutable;

/** One membership of the person signing in, as far as sign-in needs to know it. */
final readonly class SignInMembership
{
    public function __construct(
        public string $membershipId,
        public string $workspaceId,
        public string $workspaceName,
        public string $role,
        public bool $usable,
        public ?CarbonImmutable $lastActiveAt,
    ) {}

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * The Workspace a sign-in lands in: the usable membership with the latest `last_active_at`, else the
     * first by Workspace name. Memberships arrive ordered by name, which a stable sort keeps for ties.
     *
     * @param  list<SignInMembership>  $memberships
     */
    public static function active(array $memberships): ?self
    {
        $usable = array_values(array_filter($memberships, fn (self $m): bool => $m->usable));

        if ($usable === []) {
            return null;
        }

        $best = $usable[0];

        foreach ($usable as $candidate) {
            if ($candidate->lastActiveAt !== null && ($best->lastActiveAt === null || $candidate->lastActiveAt->greaterThan($best->lastActiveAt))) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
