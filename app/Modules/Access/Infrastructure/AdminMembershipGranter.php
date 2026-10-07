<?php

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Contracts\Permission;
use App\Modules\Identity\Contracts\InvitedMembershipGranter;
use App\Modules\Identity\Contracts\InviterGone;
use App\Modules\Identity\Contracts\MembershipGrant;
use App\Modules\Identity\Contracts\MembershipNotGrantable;
use App\Platform\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Creates the invited membership with the invited role and permissions, inside the caller's Workspace transaction.
 *
 * A `user` invitation creates a `user` membership with no permissions. An `admin` invitation creates an `admin`
 * membership with exactly the invited permissions, capped at what the inviting membership holds now (a permission
 * the inviter has lost since is dropped). An inviter who is no longer an active Admin, or a `created_by` that is neither a
 * membership ID nor an `operator:` value, refuses the whole invitation. Only the operator's first-Admin invitation is not capped.
 */
final class AdminMembershipGranter implements InvitedMembershipGranter
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function grant(int $userId, string $workspaceId, string $role, array $permissions, ?string $invitedBy): MembershipGrant
    {
        if ($this->context->workspaceId() !== strtolower($workspaceId) || DB::transactionLevel() < 1) {
            throw new LogicException('A membership is granted inside the Workspace transaction of that Workspace.');
        }

        if (! in_array($role, ['user', 'admin'], true)) {
            throw new LogicException('An invitation grants the role user or admin.');
        }

        // Authority first: an inviter who is gone (or a forged `created_by`) means nothing is granted at all.
        $held = $this->inviterHolds($workspaceId, $invitedBy);

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->first();

        if ($membership === null) {
            $outcome = MembershipGrant::CREATED;
            $membership = WorkspaceMembership::query()->create([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'role' => $role,
                'status' => 'active',
            ]);
        } elseif ($membership->status !== 'active') {
            throw new MembershipNotGrantable;
        } elseif ($membership->role === 'admin' || $role === 'user') {
            // An invitation never demotes: an Admin invited as a User stays an Admin, and a User stays one.
            $outcome = MembershipGrant::UNCHANGED;
        } else {
            $outcome = MembershipGrant::PROMOTED;
            $membership->forceFill(['role' => 'admin'])->save();
        }

        if ($role === 'admin') {
            $this->grantPermissions($workspaceId, $membership->id, $this->capped($permissions, $held));
        }

        return new MembershipGrant($membership->id, $outcome);
    }

    /**
     * The permissions the inviting membership holds now; null for the operator's invitation (`operator:` prefix), which is not capped.
     *
     * @return list<string>|null
     *
     * @throws InviterGone when `created_by` is not an `operator:` value or the ID of an active Admin membership of the Workspace
     */
    private function inviterHolds(string $workspaceId, ?string $invitedBy): ?array
    {
        if ($invitedBy !== null && str_starts_with($invitedBy, 'operator:')) {
            return null;
        }

        if ($invitedBy === null || ! Str::isUuid($invitedBy)) {
            throw new InviterGone;
        }

        $inviter = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('id', strtolower($invitedBy))
            ->where('status', 'active')
            ->where('role', 'admin')
            ->first();

        if ($inviter === null) {
            throw new InviterGone;
        }

        /** @var list<string> $held */
        $held = MembershipPermission::query()
            ->where('workspace_id', $workspaceId)
            ->where('membership_id', $inviter->id)
            ->pluck('permission')
            ->all();

        return $held;
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>|null  $held
     * @return list<string> the catalogue permissions among them that the inviter still holds (all of them when not capped)
     */
    private function capped(array $permissions, ?array $held): array
    {
        $permissions = array_values(array_intersect(array_unique($permissions), Permission::values()));

        return $held === null ? $permissions : array_values(array_intersect($permissions, $held));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function grantPermissions(string $workspaceId, string $membershipId, array $permissions): void
    {
        $now = now();

        DB::table('membership_permissions')->insertOrIgnore(array_map(fn (string $permission): array => [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspaceId,
            'membership_id' => $membershipId,
            'permission' => $permission,
            'created_at' => $now,
            'updated_at' => $now,
        ], $permissions));
    }
}
