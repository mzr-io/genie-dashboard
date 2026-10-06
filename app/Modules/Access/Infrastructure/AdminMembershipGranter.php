<?php

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Contracts\Permission;
use App\Modules\Identity\Contracts\InvitedMembershipGranter;
use App\Modules\Identity\Contracts\MembershipGrant;
use App\Modules\Identity\Contracts\MembershipNotGrantable;
use App\Platform\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Creates the Admin membership with every Admin permission, inside the caller's Workspace transaction. */
final class AdminMembershipGranter implements InvitedMembershipGranter
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function grantAdmin(int $userId, string $workspaceId): MembershipGrant
    {
        if ($this->context->workspaceId() !== strtolower($workspaceId) || DB::transactionLevel() < 1) {
            throw new LogicException('An Admin membership is granted inside the Workspace transaction of that Workspace.');
        }

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->first();

        if ($membership === null) {
            $outcome = MembershipGrant::CREATED;
            $membership = WorkspaceMembership::query()->create([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'role' => 'admin',
                'status' => 'active',
            ]);
        } elseif ($membership->status !== 'active') {
            throw new MembershipNotGrantable;
        } elseif ($membership->role === 'admin') {
            $outcome = MembershipGrant::UNCHANGED;
        } else {
            $outcome = MembershipGrant::PROMOTED;
            $membership->forceFill(['role' => 'admin'])->save();
        }

        $now = now();

        DB::table('membership_permissions')->insertOrIgnore(array_map(fn (string $permission): array => [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $workspaceId,
            'membership_id' => $membership->id,
            'permission' => $permission,
            'created_at' => $now,
            'updated_at' => $now,
        ], Permission::values()));

        return new MembershipGrant($membership->id, $outcome);
    }
}
