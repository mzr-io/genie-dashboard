<?php

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Contracts\MembershipLookup;
use App\Modules\Access\Contracts\UserMembership;
use Illuminate\Support\Facades\DB;

/** Reads memberships only through the Access-owned SECURITY DEFINER function. */
final class SecurityDefinerMembershipLookup implements MembershipLookup
{
    public function forUser(int $userId): array
    {
        /** @var list<object{membership_id: string, workspace_id: string, workspace_name: string, workspace_label: string|null, workspace_status: string, role: string, status: string, last_active_at: string|null}> $rows */
        $rows = DB::select('select * from access_user_memberships(?)', [$userId]);

        return array_map(fn ($row): UserMembership => new UserMembership(
            membershipId: $row->membership_id,
            workspaceId: $row->workspace_id,
            workspaceName: $row->workspace_name,
            workspaceLabel: $row->workspace_label,
            workspaceStatus: $row->workspace_status,
            role: $row->role,
            status: $row->status,
            lastActiveAt: $row->last_active_at,
        ), $rows);
    }
}
