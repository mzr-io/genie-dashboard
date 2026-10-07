<?php

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Identity\Contracts\SignInMembership;
use App\Modules\Identity\Contracts\SignInMemberships;
use App\Platform\Tenancy\WorkspaceTransaction;
use Carbon\CarbonImmutable;

/** Implements Identity's sign-in port: reads through the Access SECURITY DEFINER lookup, stamps inside a Workspace transaction. */
final class SignInMembershipsAdapter implements SignInMemberships
{
    public function __construct(
        private readonly MembershipLookup $lookup,
        private readonly WorkspaceTransaction $transactions,
    ) {}

    public function forUser(int $userId): array
    {
        return array_map(fn ($m): SignInMembership => new SignInMembership(
            membershipId: $m->membershipId,
            workspaceId: $m->workspaceId,
            workspaceName: $m->workspaceName,
            role: $m->role,
            usable: $m->status === 'active' && $m->workspaceStatus === 'active',
            lastActiveAt: $m->lastActiveAt === null ? null : CarbonImmutable::parse($m->lastActiveAt),
        ), $this->lookup->forUser($userId));
    }

    public function markActive(string $workspaceId, string $membershipId): bool
    {
        return $this->transactions->run($workspaceId, fn (): bool => WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->whereKey($membershipId)
            ->update(['last_active_at' => now()]) > 0);
    }
}
