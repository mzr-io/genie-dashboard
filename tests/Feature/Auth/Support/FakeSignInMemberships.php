<?php

namespace Tests\Feature\Auth\Support;

use App\Modules\Identity\Contracts\SignInMembership;
use App\Modules\Identity\Contracts\SignInMemberships;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Stands in for the Access adapter in the SQLite Feature suite, which has no row-level security or
 * SECURITY DEFINER function. The real lookup is exercised by the Database suite.
 */
final class FakeSignInMemberships implements SignInMemberships
{
    /** @var array<int, list<SignInMembership>> */
    private array $byUser = [];

    /** @var list<string> */
    public array $marked = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(SignInMemberships::class, $fake);

        return $fake;
    }

    public function give(int $userId, string $role = 'user', string $workspaceName = 'Acme', ?string $lastActiveAt = null, bool $usable = true): SignInMembership
    {
        $membership = new SignInMembership(
            membershipId: (string) Str::uuid7(),
            workspaceId: (string) Str::uuid7(),
            workspaceName: $workspaceName,
            role: $role,
            usable: $usable,
            lastActiveAt: $lastActiveAt === null ? null : CarbonImmutable::parse($lastActiveAt),
        );

        $this->byUser[$userId][] = $membership;

        return $membership;
    }

    public function forUser(int $userId): array
    {
        $all = $this->byUser[$userId] ?? [];
        usort($all, fn (SignInMembership $a, SignInMembership $b): int => strcmp($a->workspaceName, $b->workspaceName));

        return $all;
    }

    public function markActive(string $workspaceId, string $membershipId): bool
    {
        $this->marked[] = $membershipId;

        return true;
    }
}
