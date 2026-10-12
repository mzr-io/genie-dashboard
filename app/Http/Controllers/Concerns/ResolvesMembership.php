<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Modules\Access\Contracts\MembershipLookup;
use App\Platform\Tenancy\WorkspaceTransaction;
use Illuminate\Http\Request;

/** The signed-in person's active membership in the session's Workspace, or a 404 (nothing about another Workspace is disclosed). */
trait ResolvesMembership
{
    /** @return array{0: string, 1: string} [workspace id, membership id] */
    private function membership(Request $request, MembershipLookup $memberships): array
    {
        return $this->activeMembership($request, $memberships) ?? abort(404);
    }

    /** @return array{0: string, 1: string}|null [workspace id, membership id], or null when the person has no active membership in the session's Workspace */
    private function activeMembership(Request $request, MembershipLookup $memberships): ?array
    {
        $user = $request->user();
        $workspaceId = $request->session()->get(WorkspaceTransaction::SESSION_KEY);

        if (! $user instanceof User || ! is_string($workspaceId)) {
            return null;
        }

        foreach ($memberships->forUser($user->id) as $membership) {
            if ($membership->workspaceId === strtolower($workspaceId) && $membership->status === 'active') {
                return [$membership->workspaceId, $membership->membershipId];
            }
        }

        return null;
    }
}
