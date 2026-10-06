<?php

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Contracts\MembershipPermissions;
use App\Modules\Access\Contracts\Permission;
use App\Platform\Tenancy\WorkspaceTransaction;

/** Reads `membership_permissions` inside the Workspace's transaction (the request's own when it is already open). */
final class EloquentMembershipPermissions implements MembershipPermissions
{
    public function __construct(private readonly WorkspaceTransaction $transactions) {}

    public function forUser(int $userId, string $workspaceId): array
    {
        /** @var list<string> $held */
        $held = $this->transactions->run($workspaceId, fn (): array => MembershipPermission::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('membership_id', WorkspaceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->select('id'))
            ->pluck('permission')
            ->all());

        $permissions = [];

        foreach (array_unique($held) as $value) {
            $permission = Permission::tryFrom($value);

            if ($permission !== null) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }
}
